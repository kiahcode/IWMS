<?php
require_once "_bootstrap.php";
api_method(["GET"]);
$team = api_auth();

$requestNumber = trim($_GET["request_number"] ?? "");
if ($requestNumber === "") {
    api_response(false, "Provide request_number.", null, 422);
}

$deliveryQuery = $pdo->prepare(
    "SELECT d.id delivery_id, r.id request_id
     FROM deliveries d
     JOIN requests r ON r.id = d.request_id
     WHERE r.request_number = ? AND r.destination = ?
     LIMIT 1"
);
$deliveryQuery->execute([$requestNumber, $team]);
$deliveryInfo = $deliveryQuery->fetch();

if (!$deliveryInfo) {
    api_response(false, "Delivery receipt not found for this team.", null, 404);
}

$existingReceipt = $pdo->prepare("SELECT id FROM delivery_receipts WHERE delivery_id = ? LIMIT 1");
$existingReceipt->execute([$deliveryInfo["delivery_id"]]);

if (!$existingReceipt->fetch()) {
    $receiptNumber = "DR-" . date("YmdHis") . "-" . $deliveryInfo["delivery_id"];
    $pdo->prepare(
        "INSERT INTO delivery_receipts(dr_number, delivery_id, request_id, receipt_date)
         VALUES (?, ?, ?, CURDATE())"
    )->execute([$receiptNumber, $deliveryInfo["delivery_id"], $deliveryInfo["request_id"]]);
}

$query = $pdo->prepare(
    "SELECT dr.dr_number, dr.receipt_date, dr.received_by, dr.received_date,
            dr.remarks, r.request_number, r.destination, r.requester_team,
            r.recipient_name, d.status delivery_status
     FROM delivery_receipts dr
     JOIN requests r ON r.id = dr.request_id
     JOIN deliveries d ON d.id = dr.delivery_id
     WHERE r.request_number = ? AND r.destination = ?
     LIMIT 1"
);
$query->execute([$requestNumber, $team]);
$receipt = $query->fetch();

if (!$receipt) {
    api_response(false, "Delivery receipt not found for this team.", null, 404);
}

$items = $pdo->prepare(
    "SELECT ri.item_name, ri.size, ri.quantity requested_quantity,
            ri.approved_quantity, ri.delivered_quantity, ri.accepted_quantity,
            GREATEST(0, COALESCE(NULLIF(ri.approved_quantity, 0), ri.quantity) - ri.accepted_quantity) remaining_quantity,
            ri.unit, i.item_code, w.warehouse_name
     FROM request_items ri
     LEFT JOIN items i ON i.id = ri.item_id
     LEFT JOIN warehouses w ON w.id = ri.warehouse_id
     WHERE ri.request_id = (
        SELECT id FROM requests WHERE request_number = ? LIMIT 1
     )
     ORDER BY ri.id"
);
$items->execute([$requestNumber]);
$receipt["items"] = $items->fetchAll();

api_response(true, "Delivery receipt retrieved successfully.", $receipt);
?>
