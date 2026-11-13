<?php
require_once "_bootstrap.php";
api_method(["GET", "POST"]);
$team = api_auth();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $requestNumber = trim($_GET["request_number"] ?? "");
    $sql = "SELECT r.*, d.status delivery_status, d.expected_days, d.expected_date
            FROM requests r
            LEFT JOIN deliveries d ON d.request_id = r.id
            WHERE r.destination = ?";
    $params = [$team];

    if ($requestNumber !== "") {
        $sql .= " AND r.request_number = ?";
        $params[] = $requestNumber;
    }

    $sql .= " ORDER BY r.created_at DESC";
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll();

    foreach ($rows as &$row) {
        $items = $pdo->prepare(
            "SELECT ri.id request_item_id, ri.item_id, ri.item_name,
                    ri.size, ri.quantity requested_quantity,
                    ri.approved_quantity, ri.delivered_quantity,
                    ri.accepted_quantity, ri.unit, i.item_code
             FROM request_items ri
             LEFT JOIN items i ON i.id = ri.item_id
             WHERE ri.request_id = ?
             ORDER BY ri.id"
        );
        $items->execute([$row["id"]]);
        $row["items"] = $items->fetchAll();
    }
    unset($row);

    api_response(true, "Requests retrieved successfully.", $rows);
}

$data = api_input();
$destination = trim($data["destination"] ?? $team);
$recipient = trim($data["recipient_name"] ?? "Inventory");
$recipientEmail = trim($data["recipient_email"] ?? "");
$warehouseId = (int)($data["warehouse_id"] ?? 0);
$items = $data["items"] ?? [];

if (!api_valid_team($destination) || $destination !== $team) {
    api_response(false, "Destination must match the authenticated team.", null, 422);
}

if ($warehouseId <= 0 || !is_array($items) || count($items) === 0) {
    api_response(false, "Provide a valid warehouse_id and at least one item.", null, 422);
}

$warehouseQuery = $pdo->prepare("SELECT id FROM warehouses WHERE id = ? AND status = 'Active'");
$warehouseQuery->execute([$warehouseId]);
if (!$warehouseQuery->fetch()) {
    api_response(false, "The selected warehouse does not exist or is inactive.", null, 422);
}

$cleanItems = [];
$seen = [];

foreach ($items as $item) {
    $itemId = (int)($item["item_id"] ?? 0);
    $quantity = $item["quantity"] ?? null;

    if (
        $itemId <= 0 ||
        !is_numeric($quantity) ||
        (string)(int)$quantity !== (string)$quantity ||
        (int)$quantity <= 0 ||
        isset($seen[$itemId])
    ) {
        api_response(false, "Each item needs a unique item_id and a positive whole-number quantity.", null, 422);
    }

    $itemQuery = $pdo->prepare("SELECT id, item_name, size, unit, status FROM items WHERE id = ? LIMIT 1");
    $itemQuery->execute([$itemId]);
    $itemRecord = $itemQuery->fetch();

    if (!$itemRecord || $itemRecord["status"] !== "Active") {
        api_response(false, "An item was not found or is inactive: " . $itemId, null, 422);
    }

    $seen[$itemId] = true;
    $cleanItems[] = [
        "item_id" => $itemId,
        "item_name" => $itemRecord["item_name"],
        "size" => $itemRecord["size"] ?? "",
        "unit" => $itemRecord["unit"] ?? "pcs",
        "quantity" => (int)$quantity
    ];
}

$requestNumber = "REQ-" . date("YmdHis") . random_int(10, 99);
$expectedDate = trim($data["expected_delivery_date"] ?? "");
$remarks = trim($data["remarks"] ?? "");
$poNumber = $team === "Procurement" ? trim((string)($data["po_number"] ?? "")) : "";

$pdo->beginTransaction();

try {
    $insertRequest = $pdo->prepare(
        "INSERT INTO requests
            (request_number, requested_by, requester_team, request_date, status,
             remarks, recipient_name, recipient_gmail, destination, po_number, expected_receive_date)
         VALUES (?, NULL, ?, CURDATE(), 'Pending', ?, ?, ?, ?, ?, ?)"
    );
    $insertRequest->execute([
        $requestNumber,
        $team,
        $remarks,
        $recipient,
        $recipientEmail,
        $destination,
        $poNumber,
        $expectedDate !== "" ? $expectedDate : null
    ]);

    $requestId = (int)$pdo->lastInsertId();
    $insertItem = $pdo->prepare(
        "INSERT INTO request_items
            (request_id, item_id, item_name, size, warehouse_id, quantity, unit)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($cleanItems as $item) {
        $insertItem->execute([
            $requestId,
            $item["item_id"],
            $item["item_name"],
            $item["size"],
            $warehouseId,
            $item["quantity"],
            $item["unit"]
        ]);
    }

    $pdo->prepare(
        "INSERT INTO deliveries(request_id, status, expected_days, expected_date)
         VALUES (?, 'To Ship', ?, ?)"
    )->execute([
        $requestId,
        $data["expected_days"] ?? "2-3 days",
        $expectedDate !== "" ? $expectedDate : null
    ]);

    $requestInfo = [
        "requested_by" => 0,
        "recipient_gmail" => $recipientEmail,
        "destination" => $destination
    ];

    create_incoming_request_notifications($pdo,$requestId,$requestNumber,$destination,0,trim((string)($data["requester_name"]??$team)),true);
    $pdo->commit();

    api_response(true, "Request submitted successfully.", [
        "request_number" => $requestNumber,
        "destination" => $destination,
        "request_status" => "Pending",
        "po_number" => $poNumber,
        "items" => $cleanItems
    ], 201);
} catch (Exception $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    api_response(false, $error->getMessage(), null, 422);
}
?>
