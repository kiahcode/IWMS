<?php
require_once "_bootstrap.php";
api_method(["POST"]);
$team = api_auth();
$data = api_input();

$requestNumber = trim($data["request_number"] ?? "");
if ($requestNumber === "") {
    api_response(false, "Provide request_number.", null, 422);
}

$request = api_find_request($pdo, $requestNumber);
if (!$request) {
    api_response(false, "Request not found.", null, 404);
}

if (strcasecmp(trim((string)$request["requester_team"]), trim($team)) !== 0) {
    api_response(false, "Only the requesting team can confirm receipt.", null, 403);
}

if (strtolower(trim((string)$request["requester_team"])) === "inventory") {
    api_response(false, "Inventory must record actual accepted quantities in Delivery Progress.", null, 403);
}

if (($request["status"] ?? "") !== "Approved" && ($request["status"] ?? "") !== "Partially Approved") {
    api_response(false, "Only approved requests can be received.", null, 409);
}

$pdo->beginTransaction();

try {
    $query = $pdo->prepare(
        "SELECT id, quantity, approved_quantity, delivered_quantity, accepted_quantity
         FROM request_items WHERE request_id = ? FOR UPDATE"
    );
    $query->execute([$request["id"]]);
    $items = $query->fetchAll();

    if (!$items) {
        throw new Exception("The request has no items.");
    }

    if (strtolower(trim((string)$request["requester_team"])) === "inventory") {
        throw new Exception("Inventory must record actual accepted quantities manually.");
    }
    $deliveryStatusQuery = $pdo->prepare("SELECT status FROM deliveries WHERE request_id = ? FOR UPDATE");
    $deliveryStatusQuery->execute([$request["id"]]);
    if ($deliveryStatusQuery->fetchColumn() !== "Out for Delivery") {
        throw new Exception("Receipt can only be confirmed after the delivery is Out for Delivery.");
    }

    $receive = $pdo->prepare("UPDATE request_items SET delivered_quantity = ?, accepted_quantity = ? WHERE id = ? AND request_id = ?");
    foreach ($items as $item) {
        $target = api_target_quantity($item);
        $receive->execute([$target, $target, $item["id"], $request["id"]]);
    }

    $deliveryQuery = $pdo->prepare(
        "SELECT id FROM deliveries WHERE request_id = ? FOR UPDATE"
    );
    $deliveryQuery->execute([$request["id"]]);
    $deliveryId = (int)$deliveryQuery->fetchColumn();

    if ($deliveryId <= 0) {
        throw new Exception("Delivery record not found.");
    }

    $pdo->prepare(
        "UPDATE deliveries SET status = 'Delivered',
            delivered_date = COALESCE(delivered_date, CURDATE()),
            received_date = COALESCE(received_date, CURDATE())
         WHERE id = ?"
    )->execute([$deliveryId]);

    create_notification_for_admins(
        $pdo,
        "Delivery Accepted",
        "The " . $team . " team accepted request " . $requestNumber . ". Destination: " . $request["destination"] . ".",
        "delivery",
        null
    );

    $pdo->commit();

    api_response(true, "Receipt confirmed. Delivery status is now Delivered.", [
        "request_number" => $requestNumber,
        "destination" => $request["destination"],
        "delivery_status" => "Delivered"
    ]);
} catch (Exception $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    api_response(false, $error->getMessage(), null, 422);
}
?>
