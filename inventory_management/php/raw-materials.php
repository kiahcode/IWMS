<?php
require_once "database.php";
require_login();
$warehouse_id = (int)($_GET["warehouse_id"] ?? 0);
$sql = "SELECT i.*,c.category_name,COALESCE(SUM(inv.quantity),0) current_stock,GROUP_CONCAT(DISTINCT w.warehouse_name ORDER BY w.warehouse_name SEPARATOR ', ') warehouse_name
FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN inventory inv ON inv.item_id=i.id
LEFT JOIN warehouses w ON w.id=inv.warehouse_id WHERE i.item_type='Raw Material'";
$p = [];
if ($warehouse_id > 0) {
    $sql .= " AND inv.warehouse_id=?";
    $p[] = $warehouse_id;
}
$sql .= " GROUP BY i.id ORDER BY i.created_at DESC, i.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($p);
json_response(["success" => true, "items" => $stmt->fetchAll()]);
