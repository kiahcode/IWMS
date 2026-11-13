<?php
require_once "database.php";
require_login();
$action = $_REQUEST["action"] ?? "list";
function delivery_target_qty($it)
{
  $aq = (int)($it["approved_quantity"] ?? 0);
  return $aq > 0 ? $aq : (int)$it["quantity"];
}
function sync_delivery_status($pdo, $deliveryId, $notify = true)
{
  $q = $pdo->prepare("SELECT d.*,r.request_number,r.requested_by,r.requester_team,r.destination,r.recipient_gmail FROM deliveries d JOIN requests r ON r.id=d.request_id WHERE d.id=? FOR UPDATE");
  $q->execute([$deliveryId]);
  $d = $q->fetch();
  if (!$d) return null;
  return $d;
}
function delivery_rows($pdo)
{
  $sql = "SELECT d.*,r.request_number,r.status request_status,r.recipient_name,r.recipient_gmail,r.destination,r.requester_team,r.po_number,COALESCE(NULLIF(u.full_name,''),NULLIF(r.requester_team,''),NULLIF(r.recipient_name,''),'—') requester FROM deliveries d JOIN requests r ON r.id=d.request_id LEFT JOIN users u ON u.id=r.requested_by WHERE (r.status IN ('Approved','Partially Approved') OR (LOWER(COALESCE(r.requester_team,''))='inventory' AND r.status='Pending')) AND COALESCE(r.remarks,'') NOT LIKE '%DEMO SAMPLE%' AND COALESCE(d.remarks,'') NOT LIKE '%DEMO SAMPLE%' AND LOWER(COALESCE(r.remarks,'')) NOT LIKE '%sample%' AND LOWER(COALESCE(d.remarks,'')) NOT LIKE '%sample%' AND COALESCE(r.request_number,'') NOT LIKE 'REQ-DEMO-%'";
  $p = [];
  if (!can_control_session($pdo)) {
    $sql .= " AND r.requested_by=?";
    $p[] = $_SESSION["user_id"];
  }
  $sql .= " ORDER BY d.updated_at DESC";
  $s = $pdo->prepare($sql);
  $s->execute($p);
  $rows = $s->fetchAll();
  foreach ($rows as &$row) {
    $q = $pdo->prepare("SELECT ri.id,ri.item_name,ri.quantity,ri.approved_quantity,ri.rejected_quantity,ri.delivered_quantity,ri.accepted_quantity,ri.inspection_accepted_quantity,ri.inspection_rejected_quantity,ri.inspection_rejection_reason,ri.unit,ri.size,i.item_code,i.item_type,i.size item_size,i.supplier_name,w.warehouse_name,w.location FROM request_items ri LEFT JOIN items i ON i.id=ri.item_id LEFT JOIN warehouses w ON w.id=ri.warehouse_id WHERE ri.request_id=? ORDER BY ri.id");
    $q->execute([$row["request_id"]]);
    $row["items"] = $q->fetchAll();
    $isInventory = strtolower(trim((string)($row["requester_team"] ?? ""))) === "inventory";
    $row["is_inventory_request"] = $isInventory ? 1 : 0;
    $row["total_items"] = count($row["items"]);
    $row["complete_items"] = 0;
    $row["has_partial"] = false;
    $row["all_delivered"] = true;
    $row["remaining_balance_total"] = 0;
    $row["qty_total"] = 0;
    foreach ($row["items"] as &$it) {
      $target = $isInventory ? (int)$it["quantity"] : (int)$it["approved_quantity"];
      $accepted = (int)($it["delivered_quantity"] ?? 0) > 0 ? (int)($it["accepted_quantity"] ?? $it["inspection_accepted_quantity"] ?? 0) : (int)($it["approved_quantity"] ?? 0);
      $remaining = max(0, (int)$it["quantity"] - $accepted);
      $it["remaining_balance"] = $remaining;
      $it["delivery_target"] = $target;
      $row["remaining_balance_total"] += $remaining;
      $row["qty_total"] += $target;
      $del = (int)$it["delivered_quantity"];
      if ($del > 0 && $del < $target) $row["has_partial"] = true;
      if (($isInventory ? $accepted : $del) < $target) $row["all_delivered"] = false;
      if (($isInventory ? $accepted : $del) >= $target) $row["complete_items"]++;
    }
    unset($it);
    if ($isInventory) {
      $allAccepted = true;
      $anyDelivered = false;
      foreach ($row["items"] as $it) {
        $acc = (int)($it["accepted_quantity"] ?? 0);
        if ((int)($it["delivered_quantity"] ?? 0) > 0) $anyDelivered = true;
        if ($acc < (int)$it["quantity"]) $allAccepted = false;
      }
      if ($allAccepted) $row["status"] = "Delivered";
      elseif ($anyDelivered) $row["status"] = "Partially Delivered";
      else $row["status"] = "To Ship";
    } else {
      $any = false;
      $all = true;
      foreach ($row["items"] as $it) {
        $target = $isInventory ? (int)$it["quantity"] : (int)$it["approved_quantity"];
        $del = (int)$it["delivered_quantity"];
        if ($del > 0) $any = true;
        if ($del < $target) $all = false;
      }
      if ($row["remaining_balance_total"] > 0 || ($any && !$all)) $row["status"] = "Partially Delivered";
      elseif ($all && $any) $row["status"] = "Out for Delivery";
      else $row["status"] = "To Ship";
    }
    $dr = $pdo->prepare("SELECT id,dr_number FROM delivery_receipts WHERE delivery_id=?");
    $dr->execute([$row["id"]]);
    $receipt = $dr->fetch();
    if (!$receipt) {
      $num = "DR-" . date("YmdHis") . "-" . $row["id"];
      try {
        $pdo->prepare("INSERT INTO delivery_receipts(dr_number,delivery_id,request_id,receipt_date) VALUES(?,?,?,CURDATE())")->execute([$num, $row["id"], $row["request_id"]]);
        $receipt = ["id" => $pdo->lastInsertId(), "dr_number" => $num];
      } catch (Exception $e) {
        $dr->execute([$row["id"]]);
        $receipt = $dr->fetch();
      }
    }
    $row["dr_id"] = $receipt["id"] ?? null;
    $row["dr_number"] = $receipt["dr_number"] ?? "";
    $rb = $pdo->prepare("SELECT received_by FROM delivery_receipts WHERE delivery_id=?");
    $rb->execute([$row["id"]]);
    $row["received_by"] = (string)$rb->fetchColumn();
    if (!$isInventory && $row["received_by"] !== "") $row["status"] = $row["remaining_balance_total"] > 0 ? "Partially Delivered" : "Delivered";
  }
  return $rows;
}

if ($action === "list") {
  json_response(["success" => true, "rows" => delivery_rows($pdo)]);
}


if ($action === "update") {
  require_admin();
  $id = (int)post_value("id");
  $status = post_value("status");
  $allowed = ["To Ship", "Out for Delivery", "Partially Delivered", "Delivered"];
  if (!in_array($status, $allowed, true)) json_response(["success" => false, "message" => "Invalid delivery status."], 422);
  $pdo->beginTransaction();
  try {
    $d = sync_delivery_status($pdo, $id, false);
    if (!$d) throw new Exception("Delivery not found.");
    if ($status === "Delivered" && $d["status"] !== "Delivered") throw new Exception("Delivery status is automatic. Record the actual delivered quantities first.");
    if ($status === "Out for Delivery" && $d["status"] === "To Ship") {
      $pdo->prepare("UPDATE deliveries SET status='Out for Delivery',shipped_date=COALESCE(shipped_date,CURDATE()),out_for_delivery_date=CURDATE() WHERE id=?")->execute([$id]);
    }
    $pdo->commit();
    json_response(["success" => true, "message" => "Delivery status updated automatically from the recorded quantities."]);
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(["success" => false, "message" => $e->getMessage()], 422);
  }
}

if ($action === "record_delivery") {
  require_admin();
  $id = (int)post_value("id");
  $items = json_decode(post_value("items", "[]"), true);
  if (!is_array($items)) json_response(["success" => false, "message" => "Invalid delivery quantities."], 422);
  $pdo->beginTransaction();
  try {
    $q = $pdo->prepare("SELECT d.*,r.request_number,r.requested_by,r.requester_team,r.destination,r.recipient_gmail,r.po_number FROM deliveries d JOIN requests r ON r.id=d.request_id WHERE d.id=? FOR UPDATE");
    $q->execute([$id]);
    $d = $q->fetch();
    if (!$d) throw new Exception("Delivery not found.");
    $isInventoryRequest = strtolower(trim((string)($d["requester_team"] ?? ""))) === "inventory";
    $s = $pdo->prepare("SELECT id,approved_quantity,quantity,delivered_quantity FROM request_items WHERE request_id=? FOR UPDATE");
    $s->execute([$d["request_id"]]);
    $expected = [];
    foreach ($s->fetchAll() as $it) $expected[(int)$it["id"]] = $isInventoryRequest ? (int)$it["quantity"] : (int)$it["approved_quantity"];
    $update = $pdo->prepare("UPDATE request_items SET delivered_quantity=?,accepted_quantity=?,inspection_accepted_quantity=?,inspection_rejected_quantity=?,inspection_rejection_reason=? WHERE id=? AND request_id=?");
    $seen = [];
    $rejectedItems = [];
    $inspectionItems = [];
    $supplierNames = [];
    foreach ($items as $it) {
      $ri = (int)($it["request_item_id"] ?? 0);
      $raw = $it["delivered_quantity"] ?? null;
      $qty = (int)$raw;
      $acceptedRaw = $it["accepted_quantity"] ?? $qty;
      $rejectedRaw = $it["rejected_quantity"] ?? 0;
      $accepted = (int)$acceptedRaw;
      $rejected = (int)$rejectedRaw;
      $reason = trim((string)($it["rejection_reason"] ?? ""));
      if (!isset($expected[$ri]) || !is_numeric($raw) || ((string)$qty !== (string)$raw) || $qty < 0 || $qty > $expected[$ri] || $accepted < 0 || $rejected < 0 || $accepted + $rejected !== $qty || ((string)$accepted !== (string)$acceptedRaw) || ((string)$rejected !== (string)$rejectedRaw)) throw new Exception("Inspection quantities are invalid. Accepted + Rejected must equal Delivered, using whole numbers only.");
      if ($rejected > 0 && $reason === "") throw new Exception("Please provide a rejection reason for every rejected item.");
      $update->execute([$qty, $accepted, $accepted, $rejected, $reason, $ri, $d["request_id"]]);
      $seen[$ri] = true;
      $qitem = $pdo->prepare("SELECT ri.item_name,ri.quantity,ri.unit,i.supplier_name FROM request_items ri LEFT JOIN items i ON i.id=ri.item_id WHERE ri.id=?");
      $qitem->execute([$ri]);
      $detail = $qitem->fetch();
      $line = ["request_item_id" => $ri, "item_name" => $detail["item_name"], "ordered_quantity" => (int)$detail["quantity"], "delivered_quantity" => $qty, "accepted_quantity" => $accepted, "rejected_quantity" => $rejected, "unit" => $detail["unit"], "rejection_reason" => $reason];
      $inspectionItems[] = $line;
      if ($rejected > 0) {
        $rejectedItems[] = $line;
        if (!empty($detail["supplier_name"])) $supplierNames[] = $detail["supplier_name"];
      }
    }

    if (count($rejectedItems)) {
      $po = strtolower(trim((string)($d["destination"] ?? ""))) === "procurement" ? trim((string)($d["po_number"] ?? "")) : "";
      $supplier = trim((string)post_value("supplier_name"));
      $receivedDate = trim((string)post_value("received_date")) ?: date("Y-m-d");
      if ($supplier === "") $supplier = implode(", ", array_values(array_unique($supplierNames)));
      $find = $pdo->prepare("SELECT id FROM supplier_rejections WHERE delivery_id=?");
      $find->execute([$id]);
      $existing = (int)$find->fetchColumn();
      if ($existing) {
        $pdo->prepare("UPDATE supplier_rejections SET po_number=?,supplier_name=?,received_date=?,report_status='Pending',supplier_response=NULL,email_sent_at=NULL,supplier_response_at=NULL,created_by=? WHERE id=?")->execute([$po, $supplier, $receivedDate, $_SESSION["user_id"], $existing]);
        $rejectionId = $existing;
        $pdo->prepare("DELETE FROM supplier_rejection_items WHERE rejection_id=?")->execute([$rejectionId]);
      } else {
        $pdo->prepare("INSERT INTO supplier_rejections(request_id,delivery_id,po_number,supplier_name,received_date,created_by) VALUES(?,?,?,?,?,?)")->execute([$d["request_id"], $id, $po, $supplier, $receivedDate, $_SESSION["user_id"]]);
        $rejectionId = (int)$pdo->lastInsertId();
        $reportNumber = "RR-" . date("Y") . "-" . str_pad((string)$rejectionId, 3, "0", STR_PAD_LEFT);
        $pdo->prepare("UPDATE supplier_rejections SET report_number=? WHERE id=?")->execute([$reportNumber, $rejectionId]);
      }
      $insReject = $pdo->prepare("INSERT INTO supplier_rejection_items(rejection_id,request_item_id,item_name,ordered_quantity,delivered_quantity,accepted_quantity,rejected_quantity,unit,rejection_reason) VALUES(?,?,?,?,?,?,?,?,?)");
      foreach ($inspectionItems as $ri) $insReject->execute([$rejectionId, $ri["request_item_id"], $ri["item_name"], $ri["ordered_quantity"], $ri["delivered_quantity"], $ri["accepted_quantity"], $ri["rejected_quantity"], $ri["unit"], $ri["rejection_reason"]]);
    } else {
    }

    $isInventory = strtolower(trim((string)($d["requester_team"] ?? ""))) === "inventory";
    $s = $pdo->prepare("SELECT quantity,approved_quantity,delivered_quantity,inspection_accepted_quantity FROM request_items WHERE request_id=? FOR UPDATE");
    $s->execute([$d["request_id"]]);
    $allTargetsMet = true;
    $anyDelivered = false;
    $remainingTotal = 0;
    foreach ($s->fetchAll() as $it) {
      $target = $isInventory ? (int)$it["quantity"] : (int)$it["approved_quantity"];
      $del = (int)$it["delivered_quantity"];
      $accepted = (int)$it["inspection_accepted_quantity"];
      $balance = max(0, (int)$it["quantity"] - ($del > 0 ? $accepted : (int)$it["approved_quantity"]));
      $remainingTotal += $balance;
      if ($del > 0) $anyDelivered = true;
      if (($isInventory ? $accepted : $del) < $target) $allTargetsMet = false;
    }
    if ($isInventory) {
      $status = ($allTargetsMet && $remainingTotal === 0) ? "Delivered" : ($anyDelivered ? "Partially Delivered" : "To Ship");
    } else {
      $status = ($remainingTotal > 0 || !$allTargetsMet) ? "Partially Delivered" : "Out for Delivery";
    }
    $sets = "status=?,shipped_date=COALESCE(shipped_date,CURDATE()),out_for_delivery_date=COALESCE(out_for_delivery_date,CURDATE())";
    $params = [$status];
    if ($isInventory && $status === "Delivered") $sets .= " ,delivered_date=COALESCE(delivered_date,CURDATE()),received_date=COALESCE(received_date,CURDATE())";
    $params[] = $id;
    $pdo->prepare("UPDATE deliveries SET $sets WHERE id=?")->execute($params);
    if ($isInventory && $anyDelivered) {
      $u = $pdo->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
      $u->execute([$_SESSION["user_id"]]);
      $receiver = (string)$u->fetchColumn();
      $pdo->prepare("UPDATE delivery_receipts SET received_by=?,received_date=CURDATE() WHERE delivery_id=?")->execute([$receiver, $id]);
    }

    $title = $status === "Delivered" ? "Delivery Complete" : "Delivery Update";
    $msg = $status === "Delivered" ? "Delivery {$d["request_number"]} is complete." : "Delivery {$d["request_number"]} is {$status}.";
    create_notification_for_user($pdo, $title, $msg, "delivery", $_SESSION["user_id"], $d["requested_by"]);
    create_notification_for_email($pdo, $d["recipient_gmail"] ?? "", $title, $msg, "delivery", $_SESSION["user_id"]);
    create_notification_for_team($pdo, $d["destination"] ?? "", $title, $msg, "delivery", $_SESSION["user_id"]);
    create_notification_for_admins($pdo, $title, $msg, "delivery", $_SESSION["user_id"]);
    if ($status === "Partially Delivered") {
      create_notification_for_user($pdo, "Partial Delivery", "Request {$d["request_number"]} has missing delivered quantities.", "delivery", $_SESSION["user_id"], $d["requested_by"]);
    }
    $pdo->commit();
    json_response(["success" => true, "status" => $status, "message" => count($rejectedItems) ? "Delivery inspection saved. Rejected items were added to Rejected Items." : ($isInventory ? ($status === "Delivered" ? "Inventory received the complete request. Receiver details were saved automatically." : "Inventory receipt saved. Remaining Balance is reflected as Partially Delivered.") : ("Delivery recorded. Status is " . $status . "; the requesting team can confirm receipt when the approved quantities arrive."))]);
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(["success" => false, "message" => $e->getMessage()], 422);
  }
}

if ($action === "accept") {
  require_login();
  $id = (int)post_value("id");
  $pdo->beginTransaction();
  try {
    $q = $pdo->prepare("SELECT d.*,r.request_number,r.requested_by,r.requester_team,r.destination,r.recipient_gmail FROM deliveries d JOIN requests r ON r.id=d.request_id WHERE d.id=? FOR UPDATE");
    $q->execute([$id]);
    $d = $q->fetch();
    if (!$d) throw new Exception("Delivery not found.");
    if ((int)($d["requested_by"] ?? 0) !== (int)$_SESSION["user_id"] && !can_control_session($pdo)) throw new Exception("Only the requesting department can confirm receipt.");
    if (strtolower(trim((string)$d["requester_team"])) === "inventory") throw new Exception("Inventory-originated requests are received and recorded by Inventory in the delivery form.");
    if (!in_array($d["status"], ["Out for Delivery", "Partially Delivered"], true)) throw new Exception("Delivery must be recorded before receipt can be confirmed.");
    $s = $pdo->prepare("SELECT quantity,approved_quantity,delivered_quantity,inspection_accepted_quantity FROM request_items WHERE request_id=? FOR UPDATE");
    $s->execute([$d["request_id"]]);
    $items = $s->fetchAll();
    $remaining = 0;
    $allReceived = true;
    foreach ($items as $it) {
      $target = (int)$it["approved_quantity"];
      $del = (int)$it["delivered_quantity"];
      if ($del < $target) $allReceived = false;
      $accepted = $del > 0 ? (int)$it["inspection_accepted_quantity"] : (int)$it["approved_quantity"];
      $remaining += max(0, (int)$it["quantity"] - $accepted);
    }
    if (!$allReceived) throw new Exception("The approved quantities must be recorded before the requesting team can confirm receipt.");
    $pdo->prepare("UPDATE request_items SET accepted_quantity=inspection_accepted_quantity WHERE request_id=?")->execute([$d["request_id"]]);
    $u = $pdo->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
    $u->execute([$_SESSION["user_id"]]);
    $receiver = (string)$u->fetchColumn();
    $newStatus = $remaining > 0 ? "Partially Delivered" : "Delivered";
    $pdo->prepare("UPDATE deliveries SET status=?,delivered_date=COALESCE(delivered_date,CURDATE()),received_date=CURDATE() WHERE id=?")->execute([$newStatus, $id]);
    $pdo->prepare("UPDATE delivery_receipts SET received_by=?,received_date=CURDATE() WHERE delivery_id=?")->execute([$receiver, $id]);
    create_notification_for_admins($pdo, "Delivery Accepted", "Delivery {$d["request_number"]} was confirmed by {$receiver}. Status: {$newStatus}.", "delivery", $_SESSION["user_id"]);
    create_notification_for_team($pdo, $d["destination"] ?? "", "Delivery Accepted", "Delivery {$d["request_number"]} was confirmed. Status: {$newStatus}.", "delivery", $_SESSION["user_id"]);
    $pdo->commit();
    json_response(["success" => true, "message" => "Receipt confirmed. Received By was automatically saved as {$receiver}. Status: {$newStatus}."]);
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(["success" => false, "message" => $e->getMessage()], 422);
  }
}

json_response(["success" => false, "message" => "Invalid action."], 400);
