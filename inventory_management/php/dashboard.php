<?php
require_once "database.php";
require_login();

$stats = [];
$stats["totalItems"] = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE status='Active'")->fetchColumn();
$stats["rawMaterials"] = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE item_type='Raw Material' AND status='Active'")->fetchColumn();
$stats["finishedGoods"] = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE item_type='Finished Good' AND status='Active'")->fetchColumn();
$stats["totalStock"] = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory")->fetchColumn();
$stats["totalWarehouses"] = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status='Active'")->fetchColumn();
$stats["stockInToday"] = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM stock_in WHERE date=CURDATE()")->fetchColumn();
$stats["stockOutToday"] = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM stock_out WHERE date=CURDATE()")->fetchColumn();
$stats["lowStockItems"] = (int)$pdo->query("SELECT COUNT(*) FROM items i JOIN inventory inv ON inv.item_id=i.id WHERE inv.quantity<=i.reorder_level AND i.reorder_level>0 AND i.status='Active'")->fetchColumn();
$overview = $pdo->query("SELECT i.item_name,COALESCE(SUM(inv.quantity),0) total_stock FROM items i LEFT JOIN inventory inv ON inv.item_id=i.id WHERE i.status='Active' GROUP BY i.id ORDER BY total_stock DESC LIMIT 8")->fetchAll();
$low = $pdo->query("SELECT i.item_code,i.item_name,w.warehouse_name,inv.quantity, i.reorder_level,CASE WHEN inv.quantity<=i.reorder_level*.5 THEN 'Critical' ELSE 'Low Stock' END status FROM items i JOIN inventory inv ON inv.item_id=i.id JOIN warehouses w ON w.id=inv.warehouse_id WHERE inv.quantity<=i.reorder_level AND i.reorder_level>0 ORDER BY inv.quantity LIMIT 8")->fetchAll();
$mov = $pdo->query("SELECT sm.date,i.item_name,w.warehouse_name,sm.movement_type,sm.quantity FROM stock_movements sm JOIN items i ON i.id=sm.item_id JOIN warehouses w ON w.id=sm.warehouse_id ORDER BY sm.date DESC,sm.id DESC LIMIT 8")->fetchAll();
$transactions = [];
$a = $pdo->query("SELECT date,stock_in_number number,item_name,quantity,'Stock In' type FROM stock_in si JOIN items i ON i.id=si.item_id ORDER BY si.date DESC,si.id DESC LIMIT 5")->fetchAll();
$b = $pdo->query("SELECT date,stock_out_number number,item_name,quantity,'Stock Out' type FROM stock_out so JOIN items i ON i.id=so.item_id ORDER BY so.date DESC,so.id DESC LIMIT 5")->fetchAll();
$transactions = array_merge($a, $b);
usort($transactions, function ($x, $y) {
    return strcmp($y["date"], $x["date"]);
});
$transactions = array_slice($transactions, 0, 8);
json_response(["success" => true, "stats" => $stats, "overview" => $overview, "low_stock" => $low, "movements" => $mov, "transactions" => $transactions]);
