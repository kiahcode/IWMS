<?php
require_once "_bootstrap.php";
api_method(["GET", "POST"]);
$team = api_auth();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $requestNumber = trim($_GET["request_number"] ?? "");
    if ($requestNumber === "") {
        api_response(false, "Provide request_number.", null, 422);
    }

    $request = api_find_request($pdo, $requestNumber);
    if (!$request || $request["destination"] !== $team) {
        api_response(false, "Request not found for this team.", null, 404);
    }

    $items = $pdo->prepare(
        "SELECT id request_item_id, item_id, item_name, quantity,
                approved_quantity, delivered_quantity, accepted_quantity, unit
         FROM request_items WHERE request_id = ? ORDER BY id"
    );
    $items->execute([$request["id"]]);

    api_response(true, "Approval details retrieved successfully.", [
        "request_number" => $request["request_number"],
        "destination" => $request["destination"],
        "request_status" => $request["status"],
        "po_number" => $request["po_number"] ?? "",
        "items" => $items->fetchAll()
    ]);
}

$data = api_input();
$requestNumber = trim($data["request_number"] ?? "");
$status = trim($data["status"] ?? "Approved");
$items = $data["items"] ?? [];
$poNumber = $team === "Procurement" ? trim((string)($data["po_number"] ?? "")) : "";

if ($requestNumber === "" || !in_array($status, ["Approved", "Partially Approved", "Disapproved"], true)) {
    api_response(false, "Provide request_number and a valid approval status.", null, 422);
}

$request = api_find_request($pdo, $requestNumber);
if (!$request || $request["destination"] !== $team) {
    api_response(false, "Request not found for this team.", null, 404);
}

if ($request["status"] !== "Pending") {
    api_response(false, "Only pending requests can be processed.", null, 409);
}

if ($status !== "Disapproved" && (!is_array($items) || count($items) === 0)) {
    api_response(false, "Provide approved quantities for all request items.", null, 422);
}

$pdo->beginTransaction();

try {
    $itemQuery = $pdo->prepare(
        "SELECT id, quantity FROM request_items WHERE request_id = ? FOR UPDATE"
    );
    $itemQuery->execute([$request["id"]]);
    $requestItems = $itemQuery->fetchAll();
    $approvedMap = [];

    foreach ($items as $item) {
        $itemId = (int)($item["request_item_id"] ?? 0);
        $quantity = $item["approved_quantity"] ?? null;

        if (!is_numeric($quantity) || (string)(int)$quantity !== (string)$quantity) {
            throw new Exception("Approved quantities must be whole numbers.");
        }

        $approvedMap[$itemId] = (int)$quantity;
    }

    $totalApproved = 0;
    $totalRejected = 0;
    $update = $pdo->prepare(
        "UPDATE request_items SET approved_quantity = ?, rejected_quantity = ?
         WHERE id = ? AND request_id = ?"
    );

    foreach ($requestItems as $item) {
        $itemId = (int)$item["id"];
        $requested = (int)$item["quantity"];
        $approved = $status === "Disapproved" ? 0 : ($approvedMap[$itemId] ?? -1);

        if ($approved < 0 || $approved > $requested) {
            throw new Exception("An approved quantity is missing or exceeds the requested quantity.");
        }

        $rejected = $requested - $approved;
        $update->execute([$approved, $rejected, $itemId, $request["id"]]);

        $totalApproved += $approved;
        $totalRejected += $rejected;
    }

    if (count($approvedMap) !== count($requestItems) && $status !== "Disapproved") {
        throw new Exception("Send an approved quantity for every request item.");
    }

    if ($status === "Disapproved" || $totalApproved === 0) {
        $finalStatus = "Disapproved";
    } elseif ($totalRejected > 0) {
        $finalStatus = "Partially Approved";
    } else {
        $finalStatus = "Approved";
    }

    $pdo->prepare(
        "UPDATE requests SET status = ?, approved_at = NOW(), po_number = CASE WHEN ? <> '' THEN ? ELSE po_number END
         WHERE id = ? AND status = 'Pending'"
    )->execute([$finalStatus, $poNumber, $poNumber, $request["id"]]);

    if ($finalStatus !== "Disapproved") {
        $pdo->prepare(
            "INSERT INTO deliveries(request_id, status, expected_days, expected_date)
             VALUES (?, 'To Ship', ?, ?)
             ON DUPLICATE KEY UPDATE expected_date = VALUES(expected_date)"
        )->execute([
            $request["id"],
            $request["expected_days"] ?? "2-3 days",
            $request["expected_receive_date"]
        ]);
    }

    notify_request_participants(
        $pdo,
        $request,
        $finalStatus,
        "Request " . $requestNumber . " was processed by " . $team . ". Approved: " . $totalApproved . "; rejected: " . $totalRejected . ". Destination: " . $request["destination"] . ".",
        "request",
        null
    );

    $pdo->commit();

    api_response(true, "Request approval processed successfully.", [
        "request_number" => $requestNumber,
        "destination" => $request["destination"],
        "request_status" => $finalStatus,
        "po_number" => $poNumber !== "" ? $poNumber : ($request["po_number"] ?? ""),
        "approved_quantity" => $totalApproved,
        "rejected_quantity" => $totalRejected
    ]);
} catch (Exception $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    api_response(false, $error->getMessage(), null, 422);
}
?>
