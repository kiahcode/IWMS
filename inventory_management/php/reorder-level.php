<?php
require_once "database.php";
require_login();
$action = $_REQUEST["action"] ?? "list";
if ($action === "update") {
    require_admin();
    $id = (int)post_value("id");
    $level = (float)post_value("reorder_level");
    if ($id <= 0) json_response(["success" => false, "message" => "Invalid item."], 422);
    if ($level < 0) json_response(["success" => false, "message" => "Reorder level cannot be negative."], 422);
    $s = $pdo->prepare("UPDATE items SET reorder_level=? WHERE id=?");
    $s->execute([$level, $id]);
    create_notification($pdo, "Reorder Level", "A reorder level was updated.", "reorder", $_SESSION["user_id"]);
    json_response(["success" => true, "message" => "Reorder level updated successfully."]);
}
$sql = "SELECT i.id,i.item_code,i.item_name,i.item_type,i.size,i.reorder_level,i.created_at,w.id warehouse_id,w.warehouse_name,COALESCE(inv.quantity,0) quantity FROM items i CROSS JOIN warehouses w LEFT JOIN inventory inv ON inv.item_id=i.id AND inv.warehouse_id=w.id WHERE i.status='Active' ORDER BY i.created_at DESC,i.id DESC,w.warehouse_name";
$rows = $pdo->query($sql)->fetchAll();
foreach ($rows as &$r) {
    $r["status"] = ((float)$r["quantity"] <= (float)$r["reorder_level"]) ? "REORDER NEEDED" : "STOCK OK";
}
unset($r);
json_response(["success" => true, "rows" => $rows]);
