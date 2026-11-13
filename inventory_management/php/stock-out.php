<?php
require_once "database.php";
require_login();

$action = $_REQUEST["action"] ?? "list";
function stockout_rows($pdo)
{
  $s = $pdo->query("SELECT so.*,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name,u.username,r.requester_team,r.destination request_destination FROM stock_out so JOIN items i ON i.id=so.item_id JOIN warehouses w ON w.id=so.warehouse_id LEFT JOIN users u ON u.id=so.user_id LEFT JOIN requests r ON r.request_number=so.reference_number ORDER BY so.date DESC,so.id DESC");
  $rows = $s->fetchAll();
  foreach ($rows as &$row) {
    if (trim((string)($row['destination'] ?? '')) === '') {
      $dest = trim((string)($row['requester_team'] ?? ''));
      if ($dest === '' || $dest === 'Inventory') $dest = trim((string)($row['request_destination'] ?? ''));
      if (in_array($dest, ['Production', 'Sales', 'Procurement'], true)) {
        $pdo->prepare("UPDATE stock_out SET destination=? WHERE id=?")->execute([$dest, $row['id']]);
        $row['destination'] = $dest;
      }
    }
  }
  return $rows;
}
if ($action === "list") json_response(["success" => true, "rows" => stockout_rows($pdo)]);
if (in_array($action, ["create", "update", "delete"])) {
  require_admin();
  try {
    $pdo->beginTransaction();
    $id = (int)post_value("id");
    if ($action !== "create") {
      $s = $pdo->prepare("SELECT * FROM stock_out WHERE id=? FOR UPDATE");
      $s->execute([$id]);
      $old = $s->fetch();
      if (!$old) throw new Exception("Stock Out record not found.");
      $s = $pdo->prepare("SELECT quantity FROM inventory WHERE item_id=? AND warehouse_id=? FOR UPDATE");
      $s->execute([$old["item_id"], $old["warehouse_id"]]);
      $inv = $s->fetch();
      $newQty = (float)$inv["quantity"] + (float)$old["quantity"];
      $s = $pdo->prepare("UPDATE inventory SET quantity=? WHERE item_id=? AND warehouse_id=?");
      $s->execute([$newQty, $old["item_id"], $old["warehouse_id"]]);
      $pdo->prepare("DELETE FROM stock_movements WHERE movement_type='STOCK OUT' AND item_id=? AND warehouse_id=? AND reference_number=? AND quantity=? AND date=? LIMIT 1")->execute([$old["item_id"], $old["warehouse_id"], $old["reference_number"] ?: $old["stock_out_number"], $old["quantity"], $old["date"]]);
      if ($action === "delete") {
        require_admin();
        $pdo->prepare("DELETE FROM stock_out WHERE id=?")->execute([$id]);
        $pdo->commit();
        json_response(["success" => true, "message" => "Stock Out deleted successfully."]);
      }
    }
    $num = post_value("stock_out_number");
    $date = post_value("date");
    $item = (int)post_value("item_id");
    $wh = (int)post_value("warehouse_id");
    $qty = (int)post_value("quantity");
    $purpose = post_value("purpose");
    $destination = post_value("destination", "");
    $ref = post_value("reference_number") ?: $num;
    $remarks = post_value("remarks");
    if ($num === "" || $date === "" || $item <= 0 || $wh <= 0 || $qty <= 0 || ((string)$qty !== trim((string)post_value("quantity"))) || !in_array($destination, ["Sales", "Production", "Procurement"], true)) throw new Exception("Please complete the required fields and use a valid destination.");
    $s = $pdo->prepare("SELECT quantity FROM inventory WHERE item_id=? AND warehouse_id=? FOR UPDATE");
    $s->execute([$item, $wh]);
    $inv = $s->fetch();
    $available = $inv ? (float)$inv["quantity"] : 0;
    if ($qty > $available) throw new Exception("Insufficient stock. Available stock: " . $available);
    if ($action === "update") {
      $s = $pdo->prepare("UPDATE stock_out SET stock_out_number=?,item_id=?,warehouse_id=?,quantity=?,purpose=?,destination=?,reference_number=?,remarks=?,date=? WHERE id=?");
      $s->execute([$num, $item, $wh, $qty, $purpose, $destination, $ref, $remarks, $date, $id]);
    } else {
      $s = $pdo->prepare("INSERT INTO stock_out(stock_out_number,item_id,warehouse_id,quantity,purpose,destination,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)");
      $s->execute([$num, $item, $wh, $qty, $purpose, $destination, $ref, $remarks, $date, $_SESSION["user_id"]]);
    }
    $pdo->prepare("UPDATE inventory SET quantity=quantity-? WHERE item_id=? AND warehouse_id=?")->execute([$qty, $item, $wh]);
    $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?)")->execute([$item, $wh, "STOCK OUT", $qty, $ref, $remarks, $date, $_SESSION["user_id"]]);
    create_notification($pdo, "Stock Out", "A new Stock Out transaction was recorded.", "stock-out", $_SESSION["user_id"]);
    $pdo->commit();
    json_response(["success" => true, "message" => $action === "create" ? "Stock Out saved successfully." : "Stock Out updated successfully."]);
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(["success" => false, "message" => $e->getMessage()], 422);
  }
}
json_response(["success" => false, "message" => "Invalid action."], 400);
