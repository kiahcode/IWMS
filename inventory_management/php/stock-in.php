<?php
require_once "database.php";
require_login();

$action = $_REQUEST["action"] ?? "list";
function stockin_rows($pdo)
{
  $s = $pdo->query("SELECT si.*,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name,u.username FROM stock_in si JOIN items i ON i.id=si.item_id JOIN warehouses w ON w.id=si.warehouse_id LEFT JOIN users u ON u.id=si.user_id ORDER BY si.date DESC,si.id DESC");
  return $s->fetchAll();
}
if ($action === "list") json_response(["success" => true, "rows" => stockin_rows($pdo)]);
if ($action === "create" || $action === "update" || $action === "delete") {
  try {
    $pdo->beginTransaction();
    $id = (int)post_value("id");
    if ($action !== "create") {
      $s = $pdo->prepare("SELECT * FROM stock_in WHERE id=? FOR UPDATE");
      $s->execute([$id]);
      $old = $s->fetch();
      if (!$old) throw new Exception("Stock In record not found.");
      $s = $pdo->prepare("SELECT quantity FROM inventory WHERE item_id=? AND warehouse_id=? FOR UPDATE");
      $s->execute([$old["item_id"], $old["warehouse_id"]]);
      $inv = $s->fetch();
      $oldQty = (float)$old["quantity"];
      if ($inv) {
        $newQty = (float)$inv["quantity"] - $oldQty;
        if ($newQty < 0) throw new Exception("Cannot edit/delete because current stock is already lower than this transaction.");
        $s = $pdo->prepare("UPDATE inventory SET quantity=? WHERE item_id=? AND warehouse_id=?");
        $s->execute([$newQty, $old["item_id"], $old["warehouse_id"]]);
      }
      $pdo->prepare("DELETE FROM stock_movements WHERE movement_type='STOCK IN' AND item_id=? AND warehouse_id=? AND reference_number=? AND quantity=? AND date=? LIMIT 1")->execute([$old["item_id"], $old["warehouse_id"], $old["reference_number"] ?: $old["stock_in_number"], $old["quantity"], $old["date"]]);
      if ($action === "delete") {
        require_admin();
        $pdo->prepare("DELETE FROM stock_in WHERE id=?")->execute([$id]);
        $pdo->commit();
        json_response(["success" => true, "message" => "Stock In deleted successfully."]);
      }
    }
    $num = post_value("stock_in_number");
    $date = post_value("date");
    $item = (int)post_value("item_id");
    $wh = (int)post_value("warehouse_id");
    $qty = (float)post_value("quantity");
    $supplier = post_value("supplier");
    $unit = post_value("unit");
    $ref = post_value("reference_number") ?: $num;
    $remarks = post_value("remarks");
    if ($num === "" || $date === "" || $item <= 0 || $wh <= 0 || $qty <= 0) throw new Exception("Please complete the required fields and use a quantity greater than zero.");
    if ($unit === "") throw new Exception("Please specify the unit.");
    $t = $pdo->prepare("SELECT item_type FROM items WHERE id=?");
    $t->execute([$item]);
    $itemType = $t->fetchColumn();
    if (!$itemType) throw new Exception("Selected item was not found.");
    if ($itemType === "Raw Material") {
      if ($supplier === "") throw new Exception("Supplier is required for Raw Materials.");
    } else {
      $supplier = "";
    }
    if ($action === "update") {
      $s = $pdo->prepare("UPDATE stock_in SET stock_in_number=?,item_id=?,warehouse_id=?,quantity=?,unit=?,supplier=?,reference_number=?,remarks=?,date=? WHERE id=?");
      $s->execute([$num, $item, $wh, $qty, $unit, $supplier, $ref, $remarks, $date, $id]);
    } else {
      $s = $pdo->prepare("INSERT INTO stock_in(stock_in_number,item_id,warehouse_id,quantity,unit,supplier,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)");
      $s->execute([$num, $item, $wh, $qty, $unit, $supplier, $ref, $remarks, $date, $_SESSION["user_id"]]);
      $id = $pdo->lastInsertId();
    }
    $s = $pdo->prepare("INSERT INTO inventory(item_id,warehouse_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)");
    $s->execute([$item, $wh, $qty]);
    $s = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,unit,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?,?)");
    $s->execute([$item, $wh, "STOCK IN", $qty, $unit, $ref, $remarks, $date, $_SESSION["user_id"]]);
    create_notification_for_admins($pdo, "Item Received", "A new Stock In transaction was recorded and an item was received into inventory.", "stock-in", $_SESSION["user_id"]);
    $pdo->commit();
    json_response(["success" => true, "message" => $action === "create" ? "Stock In saved successfully." : "Stock In updated successfully."]);
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(["success" => false, "message" => $e->getMessage()], 422);
  }
}
json_response(["success" => false, "message" => "Invalid action."], 400);
