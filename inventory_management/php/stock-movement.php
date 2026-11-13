<?php
require_once "database.php";
require_login();
$sql = "SELECT sm.id,sm.item_id,sm.warehouse_id,sm.date,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name,sm.movement_type,sm.quantity,sm.reference_number,u.username,sm.remarks FROM stock_movements sm JOIN items i ON i.id=sm.item_id JOIN warehouses w ON w.id=sm.warehouse_id LEFT JOIN users u ON u.id=sm.user_id WHERE 1=1";
$p = [];
if (!empty($_GET["item_id"])) {
    $sql .= " AND sm.item_id=?";
    $p[] = (int)$_GET["item_id"];
}
if (!empty($_GET["warehouse_id"])) {
    $sql .= " AND sm.warehouse_id=?";
    $p[] = (int)$_GET["warehouse_id"];
}
if (!empty($_GET["movement_type"])) {
    $sql .= " AND sm.movement_type=?";
    $p[] = $_GET["movement_type"];
}
if (!empty($_GET["date_from"])) {
    $sql .= " AND sm.date>=?";
    $p[] = $_GET["date_from"];
}
if (!empty($_GET["date_to"])) {
    $sql .= " AND sm.date<=?";
    $p[] = $_GET["date_to"];
}
$sql .= " ORDER BY sm.date DESC,sm.id DESC";
$s = $pdo->prepare($sql);
$s->execute($p);
json_response(["success" => true, "rows" => $s->fetchAll()]);
