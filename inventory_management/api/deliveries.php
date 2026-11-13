<?php
require_once "_bootstrap.php";
api_method(["GET", "POST"]);
$team = api_auth();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $requestNumber = trim($_GET["request_number"] ?? "");
    $sql = "SELECT r.request_number, r.destination, r.requester_team,
                   r.status request_status, d.id delivery_id,
                   d.status delivery_status, d.expected_days, d.expected_date,
                   r.recipient_name, r.expected_receive_date
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
        $requestIdQuery = $pdo->prepare("SELECT id FROM requests WHERE request_number = ? LIMIT 1");
        $requestIdQuery->execute([$row["request_number"]]);
        $requestId = (int)$requestIdQuery->fetchColumn();

        $items = $pdo->prepare(
            "SELECT ri.id request_item_id, ri.item_id, ri.item_name,
                    ri.quantity requested_quantity, ri.approved_quantity,
                    ri.delivered_quantity, ri.accepted_quantity, ri.unit,
                    GREATEST(0, COALESCE(NULLIF(ri.approved_quantity, 0), ri.quantity) - ri.accepted_quantity) remaining_quantity
             FROM request_items ri
             JOIN requests r ON r.id = ri.request_id
             WHERE ri.request_id = ? AND r.destination = ?
             ORDER BY ri.id"
        );
        $items->execute([$requestId, $team]);
        $row["items"] = $items->fetchAll();
    }
    unset($row);

    api_response(true, "Delivery information retrieved successfully.", $rows);
}

$data = api_input();
$requestNumber = trim($data["request_number"] ?? "");
$items = $data["items"] ?? [];

if ($requestNumber === "" || !is_array($items) || count($items) === 0) {
    api_response(false, "Provide request_number and delivered item quantities.", null, 422);
}

$request = api_find_request($pdo, $requestNumber);
if (!$request || $request["destination"] !== $team) {
    api_response(false, "Request not found for this team.", null, 404);
}

if (strtolower(trim((string)$request["requester_team"])) !== "inventory") {
    api_response(false, "This endpoint is for deliveries of Inventory requests.", null, 403);
}

if (!in_array($request["status"], ["Approved", "Partially Approved"], true)) {
    api_response(false, "Only approved requests can receive delivery updates.", null, 409);
}

$pdo->beginTransaction();

try {
    $query = $pdo->prepare(
        "SELECT id, quantity, approved_quantity, delivered_quantity
         FROM request_items WHERE request_id = ? FOR UPDATE"
    );
    $query->execute([$request["id"]]);
    $limits = [];

    foreach ($query->fetchAll() as $item) {
        $limits[(int)$item["id"]] = api_target_quantity($item);
    }

    $update = $pdo->prepare(
        "UPDATE request_items SET delivered_quantity = ?
         WHERE id = ? AND request_id = ?"
    );
    $seen = [];

    foreach ($items as $item) {
        $itemId = (int)($item["request_item_id"] ?? 0);
        $quantity = $item["delivered_quantity"] ?? null;

        if (
            !isset($limits[$itemId]) ||
            !is_numeric($quantity) ||
            (string)(int)$quantity !== (string)$quantity ||
            (int)$quantity < 0 ||
            (int)$quantity > $limits[$itemId]
        ) {
            throw new Exception("Delivered quantity is invalid or exceeds the approved quantity.");
        }

        $update->execute([(int)$quantity, $itemId, $request["id"]]);
        $seen[$itemId] = true;
    }

    $status = api_save_delivery_status($pdo, (int)$request["id"]);

    create_notification_for_user(
        $pdo,
        "Delivery Update",
        "The " . $team . " team updated delivery " . $requestNumber . ". Current status: " . $status . ". Destination: " . $request["destination"] . ".",
        "delivery",
        null,
        (int)$request["requested_by"]
    );
    create_notification_for_admins(
        $pdo,
        "Delivery Update",
        "Delivery " . $requestNumber . " status: " . $status . ". Destination: " . $request["destination"] . ".",
        "delivery",
        null
    );

    $pdo->commit();

    api_response(true, "Delivered quantities saved and status updated.", [
        "request_number" => $requestNumber,
        "destination" => $request["destination"],
        "delivery_status" => $status
    ]);
} catch (Exception $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    api_response(false, $error->getMessage(), null, 422);
}
?>
