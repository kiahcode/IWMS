<?php
require_once "database.php";
require_login();

$stmt = $pdo->prepare("SELECT i.*,c.category_name,COALESCE(SUM(inv.quantity),0) current_stock,
GROUP_CONCAT(DISTINCT w.warehouse_name ORDER BY w.warehouse_name SEPARATOR ', ') warehouse_name
FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN inventory inv ON inv.item_id=i.id
LEFT JOIN warehouses w ON w.id=inv.warehouse_id WHERE i.item_type='Finished Good' GROUP BY i.id ORDER BY i.item_name ASC, CAST(i.size AS UNSIGNED) ASC, i.id ASC");
$stmt->execute();
json_response(["success" => true, "items" => $stmt->fetchAll()]);
