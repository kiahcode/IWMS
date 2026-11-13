<?php
require_once "database.php";
require_login();

$action = $_REQUEST["action"] ?? "list";
if ($action === "list") {
  $sql = "SELECT sa.*,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name,u.username,
   sa.current_quantity AS beginning_stock,
   COALESCE((SELECT SUM(si.quantity) FROM stock_in si WHERE si.item_id=sa.item_id AND si.warehouse_id=sa.warehouse_id AND si.date=sa.date),0) AS stock_in_quantity,
   COALESCE((SELECT SUM(so.quantity) FROM stock_out so WHERE so.item_id=sa.item_id AND so.warehouse_id=sa.warehouse_id AND so.date=sa.date),0) AS stock_out_quantity,
   (sa.current_quantity+
    COALESCE((SELECT SUM(si2.quantity) FROM stock_in si2 WHERE si2.item_id=sa.item_id AND si2.warehouse_id=sa.warehouse_id AND si2.date=sa.date),0)-
    COALESCE((SELECT SUM(so2.quantity) FROM stock_out so2 WHERE so2.item_id=sa.item_id AND so2.warehouse_id=sa.warehouse_id AND so2.date=sa.date),0)) AS total_balance
   FROM stock_adjustments sa JOIN items i ON i.id=sa.item_id JOIN warehouses w ON w.id=sa.warehouse_id LEFT JOIN users u ON u.id=sa.user_id
   ORDER BY sa.date DESC,sa.id DESC";
  json_response(["success" => true, "rows" => $pdo->query($sql)->fetchAll()]);
}
if (in_array($action, ["create", "update", "delete"])) {
  require_admin();
  try {
    $pdo->beginTransaction();
    $id = (int)post_value("id");
    if ($action !== "create") {
      $s = $pdo->prepare("SELECT * FROM stock_adjustments WHERE id=? FOR UPDATE");
      $s->execute([$id]);
      $old = $s->fetch();
      if (!$old) throw new Exception("Adjustment not found.");
      $pdo->prepare("UPDATE inventory SET quantity=quantity-? WHERE item_id=? AND warehouse_id=?")->execute([$old["difference"], $old["item_id"], $old["warehouse_id"]]);
      $pdo->prepare("DELETE FROM stock_movements WHERE movement_type='STOCK ADJUSTMENT' AND item_id=? AND warehouse_id=? AND reference_number=? AND quantity=? AND date=? LIMIT 1")->execute([$old["item_id"], $old["warehouse_id"], $old["adjustment_number"], (int)$old["difference"], $old["date"]]);
      if ($action === "delete") {
        require_admin();
        $pdo->prepare("DELETE FROM stock_adjustments WHERE id=?")->execute([$id]);
        $pdo->commit();
        json_response(["success" => true, "message" => "Adjustment deleted successfully."]);
      }
    }
    $num = post_value("adjustment_number");
    $date = post_value("date");
    $item = (int)post_value("item_id");
    $wh = (int)post_value("warehouse_id");
    $adjusted = (float)post_value("adjusted_quantity");
    $reason = post_value("reason");
    $remarks = post_value("remarks");
    if ($num === "" || $date === "" || $item <= 0 || $wh <= 0 || $adjusted < 0) throw new Exception("Please complete all required fields. Adjusted quantity cannot be negative.");
    $s = $pdo->prepare("SELECT quantity FROM inventory WHERE item_id=? AND warehouse_id=? FOR UPDATE");
    $s->execute([$item, $wh]);
    $inv = $s->fetch();
    $current = $inv ? (float)$inv["quantity"] : 0;
    $diff = $adjusted - $current;
    if ($action === "update") $pdo->prepare("UPDATE stock_adjustments SET adjustment_number=?,item_id=?,warehouse_id=?,current_quantity=?,adjusted_quantity=?,difference=?,reason=?,remarks=?,date=? WHERE id=?")->execute([$num, $item, $wh, $current, $adjusted, $diff, $reason, $remarks, $date, $id]);
    else $pdo->prepare("INSERT INTO stock_adjustments(adjustment_number,item_id,warehouse_id,current_quantity,adjusted_quantity,difference,reason,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$num, $item, $wh, $current, $adjusted, $diff, $reason, $remarks, $date, $_SESSION["user_id"]]);
    $pdo->prepare("INSERT INTO inventory(item_id,warehouse_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)")->execute([$item, $wh, $adjusted]);
    $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?)")->execute([$item, $wh, "STOCK ADJUSTMENT", $diff, $num, $remarks, $date, $_SESSION["user_id"]]);
    create_notification($pdo, "Stock Adjustment", "A new Stock Adjustment transaction was recorded.", "stock-adjustment", $_SESSION["user_id"]);
    $pdo->commit();
    json_response(["success" => true, "message" => $action === "create" ? "Adjustment saved successfully." : "Adjustment updated successfully."]);
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(["success" => false, "message" => $e->getMessage()], 422);
  }
}
json_response(["success" => false, "message" => "Invalid action."], 400);
