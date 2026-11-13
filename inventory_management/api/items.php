<?php
require_once "_bootstrap.php";
api_method(["GET"]);
$team = api_auth();

$query = $pdo->query(
    "SELECT i.id item_id, i.item_code, i.item_name, i.item_type,
            i.size, i.unit, i.status
     FROM items i
     WHERE i.status = 'Active'
     ORDER BY i.item_name, i.size"
);
$items = $query->fetchAll();

$warehouses = $pdo->query(
    "SELECT id warehouse_id, warehouse_name, location
     FROM warehouses WHERE status = 'Active' ORDER BY warehouse_name"
)->fetchAll();

api_response(true, "Items and active warehouses retrieved successfully.", [
    "team" => $team,
    "items" => $items,
    "warehouses" => $warehouses
]);
?>
