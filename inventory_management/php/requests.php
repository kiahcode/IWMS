<?php
require_once "database.php";
require_login();
$action = $_REQUEST["action"] ?? "list";

function request_target_qty($it)
{
    return (int)($it["approved_quantity"] ?? 0);
}
function request_expected_item_type($destination)
{
    return "Finished Good";
}
function request_valid_size($size)
{
    $size = trim((string)$size);
    return preg_match('/^\d+$/', $size) && (int)$size >= 20 && (int)$size <= 45;
}
function infer_requester_team($pdo, $userId)
{
    return infer_user_team($pdo, $userId);
}
function infer_destination($r)
{
    $d = trim((string)($r["destination"] ?? ""));
    if ($d !== "") return $d;
    $src = strtolower(trim((string)($r["recipient_gmail"] ?? "") . " " . (string)($r["recipient_name"] ?? "") . " " . (string)($r["recipient_team"] ?? "")));
    if (strpos($src, "procurement") !== false) return "Procurement";
    if (strpos($src, "sales") !== false) return "Sales";
    if (strpos($src, "production") !== false || strpos($src, "team 3") !== false) return "Production";
    return "";
}
function ensure_request_delivery($pdo, $requestId, $expectedDate, $expectedDays = "2-3 days")
{
    if (!$requestId) return;
    $s = $pdo->prepare("SELECT expected_days FROM deliveries WHERE request_id=?");
    $s->execute([$requestId]);
    $d = $s->fetch();
    $days = $d["expected_days"] ?? $expectedDays;
    $pdo->prepare("INSERT INTO deliveries(request_id,status,expected_days,expected_date) VALUES(?,'To Ship',?,?) ON DUPLICATE KEY UPDATE expected_days=VALUES(expected_days),expected_date=VALUES(expected_date)")->execute([$requestId, $days, $expectedDate]);
}

function resolve_request_inventory_item($pdo, $requestItem, $destination = "")
{
    $expectedType = "";
    $requestedName = trim((string)($requestItem["item_name"] ?? ""));
    $requestedSize = trim((string)($requestItem["size"] ?? ""));
    $requestedId = (int)($requestItem["item_id"] ?? 0);

    if ($requestedId > 0) {
        $sql = "SELECT id,item_name,item_code,item_type,size,unit FROM items WHERE id=? AND status='Active'";
        $params = [$requestedId];
        if ($expectedType !== "") {
            $sql .= " AND item_type=?";
            $params[] = $expectedType;
        }
        $q = $pdo->prepare($sql . " LIMIT 1");
        $q->execute($params);
        $record = $q->fetch();
        if ($record && strcasecmp(trim((string)$record["item_name"]), $requestedName) === 0) return $record;
    }

    if ($requestedName === "") return null;
    $base = "SELECT id,item_name,item_code,item_type,size,unit FROM items
             WHERE status='Active' AND LOWER(TRIM(item_name))=LOWER(TRIM(?))";
    $params = [$requestedName];
    if ($expectedType !== "") {
        $base .= " AND item_type=?";
        $params[] = $expectedType;
    }

    if ($requestedSize !== "") {
        $q = $pdo->prepare($base . " AND TRIM(COALESCE(size,''))=TRIM(?) ORDER BY id ASC LIMIT 1");
        $q->execute(array_merge($params, [$requestedSize]));
        $record = $q->fetch();
        if ($record) return $record;
    }


    $q = $pdo->prepare($base . " ORDER BY id ASC LIMIT 2");
    $q->execute($params);
    $matches = $q->fetchAll();
    return count($matches) === 1 ? $matches[0] : null;
}

if ($action === "list") {
    $sql = "SELECT r.*,COALESCE(NULLIF(u.full_name,''),NULLIF(r.requester_team,''),'—') requester,COALESCE(r.requester_team,'') requester_team,d.expected_days,d.status delivery_status,COUNT(ri.id) item_count FROM requests r LEFT JOIN users u ON u.id=r.requested_by LEFT JOIN deliveries d ON d.request_id=r.id LEFT JOIN request_items ri ON ri.request_id=r.id WHERE COALESCE(r.remarks,'') NOT LIKE '%DEMO SAMPLE%' AND COALESCE(r.remarks,'') NOT LIKE '%sample%' AND COALESCE(r.request_number,'') NOT LIKE 'REQ-DEMO-%' AND NOT EXISTS (SELECT 1 FROM deliveries dx WHERE dx.request_id=r.id AND COALESCE(dx.remarks,'') LIKE '%sample%')";
    $p = [];

    $sql .= " GROUP BY r.id ORDER BY r.created_at DESC";
    $s = $pdo->prepare($sql);
    $s->execute($p);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        if (trim((string)($r["requester_team"] ?? "")) === "") $r["requester_team"] = infer_user_team($pdo, $r["requested_by"] ?? 0);
        $r["destination"] = infer_destination($r);
        if (trim((string)($r["destination"] ?? "")) !== "") {
            $pdo->prepare("UPDATE requests SET destination=? WHERE id=? AND (destination IS NULL OR destination='')")->execute([$r["destination"], $r["id"]]);
        }
        $q = $pdo->prepare("SELECT ri.*,COALESCE(ri.item_id,i.id) item_id,i.item_code,i.item_type,i.size item_master_size,i.unit item_unit,w.warehouse_name,w.location,COALESCE(NULLIF(ri.size,''),i.size) size FROM request_items ri LEFT JOIN items i ON i.id=ri.item_id OR (ri.item_id IS NULL AND i.item_name=ri.item_name) LEFT JOIN warehouses w ON w.id=ri.warehouse_id WHERE ri.request_id=? ORDER BY ri.id");
        $q->execute([$r["id"]]);
        $r["items"] = $q->fetchAll();
    }
    json_response(["success" => true, "rows" => $rows]);
}

if ($action === "rejected_items") {
    $sql = "SELECT ri.id request_item_id,ri.request_id,ri.item_id,ri.item_name,ri.size,ri.quantity,ri.approved_quantity,ri.rejected_quantity,COALESCE(ri.rejection_reason,'') rejection_reason,ri.delivered_quantity,ri.accepted_quantity,ri.unit,ri.warehouse_id,w.warehouse_name,w.location,r.request_number,r.request_date,r.status request_status,r.destination,COALESCE(r.requester_team,'') requester_team,r.recipient_name,r.recipient_gmail,r.expected_receive_date,r.approved_at,u.full_name requester FROM request_items ri JOIN requests r ON r.id=ri.request_id LEFT JOIN users u ON u.id=r.requested_by LEFT JOIN warehouses w ON w.id=ri.warehouse_id WHERE ri.rejected_quantity>0";
    $p = [];
    $sql .= " ORDER BY r.created_at DESC,ri.id DESC";
    $s = $pdo->prepare($sql);
    $s->execute($p);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        if (trim((string)($r["requester_team"] ?? "")) === "") $r["requester_team"] = infer_user_team($pdo, $r["requested_by"] ?? 0);
        $r["destination"] = infer_destination($r);
        if (trim((string)($r["destination"] ?? "")) !== "") {
            $pdo->prepare("UPDATE requests SET destination=? WHERE id=? AND (destination IS NULL OR destination='')")->execute([$r["destination"], $r["request_id"]]);
        }
        $q = $pdo->prepare("SELECT item_code,item_type,size,brand,supplier_name,category_id,unit FROM items WHERE id=? LIMIT 1");
        $q->execute([(int)$r["item_id"]]);
        $i = $q->fetch();
        $r["item_code"] = $i["item_code"] ?? "";
        $r["item_type"] = $i["item_type"] ?? "";
        $r["brand"] = $i["brand"] ?? "";
        $r["supplier_name"] = $i["supplier_name"] ?? "";
        $r["category_id"] = $i["category_id"] ?? null;
        $r["item_size"] = $i["size"] ?? $r["size"] ?? "";
    }
    json_response(["success" => true, "rows" => $rows]);
}

if ($action === "approval_options") {
    require_admin();
    $id = (int)($_REQUEST["id"] ?? 0);
    $s = $pdo->prepare("SELECT r.id,r.request_number,r.requested_by,r.destination,r.recipient_name,r.recipient_gmail,r.expected_receive_date FROM requests r WHERE r.id=? AND r.status='Pending'");
    $s->execute([$id]);
    $req = $s->fetch();
    if (!$req) json_response(["success" => false, "message" => "Request is no longer pending or was not found."], 404);
    if ((int)($req["requested_by"] ?? 0) === (int)($_SESSION["user_id"] ?? 0)) json_response(["success" => false, "message" => "You cannot approve your own request."], 403);
    $s = $pdo->prepare("SELECT ri.id,ri.item_id,ri.item_name,ri.size,ri.quantity,ri.approved_quantity,ri.rejected_quantity,ri.unit,ri.warehouse_id FROM request_items ri WHERE ri.request_id=? ORDER BY ri.id");
    $s->execute([$id]);
    $items = $s->fetchAll();
    if (!$items) json_response(["success" => false, "message" => "This request has no items."], 422);
    foreach ($items as &$it) {
        $record = resolve_request_inventory_item($pdo, $it, $req["destination"] ?? "");
        $it["resolved_item_id"] = (int)($record["id"] ?? 0);
        $it["item_code"] = $record["item_code"] ?? "";
        $it["item_type"] = $record["item_type"] ?? "";
        if ($record) {
            $it["size"] = $record["size"] ?? $it["size"];
            $it["unit"] = $record["unit"] ?? $it["unit"];
        }
    }
    unset($it);
    $s = $pdo->query("SELECT id,warehouse_name,location FROM warehouses WHERE status='Active' ORDER BY id");
    $warehouses = $s->fetchAll();
    foreach ($warehouses as &$w) {
        $availableCount = 0;
        $stockItems = [];
        foreach ($items as $it) {
            $itemId = (int)$it["resolved_item_id"];
            $q = $pdo->prepare("SELECT COALESCE(quantity,0) FROM inventory WHERE warehouse_id=? AND item_id=? LIMIT 1");
            $q->execute([$w["id"], $itemId]);
            $stock = (float)$q->fetchColumn();
            if ($itemId > 0 && $stock > 0) $availableCount++;
            $stockItems[] = ["item_id" => $itemId, "item_name" => $it["item_name"], "quantity" => (int)$it["quantity"], "available_qty" => $stock, "unit" => $it["unit"] ?? "pcs"];
        }
        $w["available"] = $availableCount > 0 ? 1 : 0;
        $w["stock_summary"] = $availableCount . "/" . count($items) . " items in stock";
        $w["stock_items"] = $stockItems;
    }
    unset($w);
    json_response(["success" => true, "request" => $req, "items" => $items, "warehouses" => $warehouses]);
}

if ($action === "create" || $action === "update") {
    require_admin();
    $itemsRaw = post_value("items", "");
    $items = json_decode($itemsRaw, true);
    $wh = (int)post_value("warehouse_id");
    $recipient = trim(post_value("recipient_name"));
    $gmail = trim(post_value("recipient_gmail"));
    $destination = post_value("destination", "");
    $days = post_value("expected_days", "2-3 days");
    $receive = null;
    $remarks = post_value("remarks");
    if ($destination === "") {
        $u = $pdo->prepare("SELECT full_name,username,email FROM users WHERE id=? LIMIT 1");
        $u->execute([$_SESSION["user_id"]]);
        $usr = $u->fetch();
        $source = strtolower(($usr["full_name"] ?? "") . " " . ($usr["username"] ?? "") . " " . ($usr["email"] ?? ""));
        if (strpos($source, "production") !== false) $destination = "Production";
        elseif (strpos($source, "procurement") !== false) $destination = "Procurement";
        elseif (strpos($source, "sales") !== false) $destination = "Sales";
    }
    if (!is_array($items) || count($items) < 1 || $wh <= 0 || $recipient === "" || $gmail === "" || $receive === "" || !in_array($destination, ["Production", "Procurement", "Sales"], true)) json_response(["success" => false, "message" => "Please complete the request fields and add at least one item."], 422);
    $clean = [];
    $seen = [];
    foreach ($items as $it) {
        $itemId = (int)($it["item_id"] ?? 0);
        $qty = (int)($it["quantity"] ?? 0);
        if ($itemId <= 0 || $qty <= 0 || ((string)$qty !== (string)($it["quantity"] ?? ""))) json_response(["success" => false, "message" => "Each item must have a valid whole-number quantity."], 422);
        $t = $pdo->prepare("SELECT id,item_type FROM items WHERE id=? AND status='Active' LIMIT 1");
        $t->execute([$itemId]);
        $selectedItem = $t->fetch();
        if (!$selectedItem) json_response(["success" => false, "message" => "The selected item is not active or no longer exists in Item Master. Please refresh the item list and try again."], 422);
        $expectedType = request_expected_item_type($destination);
        if ($expectedType !== "" && strcasecmp(trim((string)$selectedItem["item_type"]), $expectedType) !== 0) json_response(["success" => false, "message" => "For {$destination} requests, please select {$expectedType} items only."], 422);
        if (isset($seen[$itemId])) json_response(["success" => false, "message" => "The same item cannot be added twice in one request."], 422);
        $seen[$itemId] = true;
        $size = trim((string)($it["size"] ?? ""));
        if (!request_valid_size($size)) json_response(["success" => false, "message" => "Please select a size (20 to 45) for each item."], 422);
        $clean[] = ["item_id" => $itemId, "quantity" => $qty, "size" => $size];
    }
    if ($action === "update") {
        require_admin();
        $id = (int)post_value("id");
        $pdo->beginTransaction();
        try {
            $chk = $pdo->prepare("SELECT status FROM requests WHERE id=? FOR UPDATE");
            $chk->execute([$id]);
            $cur = $chk->fetch();
            if (!$cur || $cur["status"] !== "Pending") throw new Exception("Only pending requests can be edited.");
            $pdo->prepare("UPDATE requests SET recipient_name=?,recipient_gmail=?,destination=?,requester_team=?,expected_receive_date=?,remarks=? WHERE id=?")->execute([$recipient, $gmail, $destination, infer_user_team($pdo, $_SESSION["user_id"]), $receive, $remarks, $id]);
            $pdo->prepare("DELETE FROM request_items WHERE request_id=?")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO request_items(request_id,item_id,item_name,size,warehouse_id,quantity,unit) SELECT ?,id,item_name,?,?,?,unit FROM items WHERE id=?");
            foreach ($clean as $it) $ins->execute([$id, $it["size"], $wh, $it["quantity"], $it["item_id"]]);
            $pdo->prepare("UPDATE deliveries SET expected_days=?,expected_date=? WHERE request_id=?")->execute([$days, $receive, $id]);
            $pdo->commit();
            json_response(["success" => true, "message" => "Request updated successfully."]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_response(["success" => false, "message" => $e->getMessage()], 422);
        }
    }
    $num = "REQ-" . date("YmdHis") . rand(10, 99);
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("INSERT INTO requests(request_number,requested_by,requester_team,request_date,status,remarks,recipient_name,recipient_gmail,destination,expected_receive_date) VALUES(?,?,?,CURDATE(),'Pending',?,?,?,?,?)");
        $s->execute([$num, $_SESSION["user_id"], infer_user_team($pdo, $_SESSION["user_id"]), $remarks, $recipient, $gmail, $destination, $receive]);
        $id = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO deliveries(request_id,status,expected_days,expected_date) VALUES(?,'To Ship',?,NULL)")->execute([$id, $days]);
        $ins = $pdo->prepare("INSERT INTO request_items(request_id,item_id,item_name,size,warehouse_id,quantity,unit) SELECT ?,id,item_name,?,?,?,unit FROM items WHERE id=?");
        foreach ($clean as $it) $ins->execute([$id, $it["size"], $wh, $it["quantity"], $it["item_id"]]);
        $req = ["requested_by" => (int)$_SESSION["user_id"], "recipient_gmail" => $gmail, "destination" => $destination];
        notify_request_participants($pdo, $req, "New Request", "Request {$num} with " . count($clean) . " item(s) was submitted for {$destination}.", "request", $_SESSION["user_id"]);
        $pdo->commit();
        json_response(["success" => true, "message" => "Request with " . count($clean) . " item(s) submitted successfully."]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(["success" => false, "message" => $e->getMessage()], 422);
    }
}

if ($action === "delete") {
    require_admin();
    $id = (int)post_value("id");
    $s = $pdo->prepare("SELECT status,requested_by FROM requests WHERE id=?");
    $s->execute([$id]);
    $req = $s->fetch();
    if (!$req || $req["status"] !== "Pending") json_response(["success" => false, "message" => "Only pending requests can be deleted."], 422);
    $pdo->prepare("DELETE FROM requests WHERE id=?")->execute([$id]);
    json_response(["success" => true, "message" => "Request deleted successfully."]);
}

if ($action === "disapprove") json_response(["success" => false, "message" => "Disapproval is disabled. Use the approval quantity to record the remaining balance."], 410);
if ($action === "approve") {
    require_admin();
    $id = (int)post_value("id");
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("SELECT r.*,u.full_name requester_name FROM requests r LEFT JOIN users u ON u.id=r.requested_by WHERE r.id=? AND r.status='Pending' FOR UPDATE");
        $s->execute([$id]);
        $req = $s->fetch();
        if (!$req) throw new Exception("Request is no longer pending.");
        $req["destination"] = infer_destination($req);
        if (trim((string)$req["destination"]) !== "") $pdo->prepare("UPDATE requests SET destination=? WHERE id=? AND (destination IS NULL OR destination='')")->execute([$req["destination"], $id]);
        if ((int)($req["requested_by"] ?? 0) === (int)($_SESSION["user_id"] ?? 0)) throw new Exception("You cannot approve or disapprove your own request. Wait for the destination team to process it.");
        if ($action === "approve") {
            $warehouseId = (int)post_value("warehouse_id");
            if ($warehouseId <= 0) throw new Exception("Please select a warehouse before approving.");
            $s = $pdo->prepare("SELECT ri.* FROM request_items ri WHERE ri.request_id=? ORDER BY ri.id");
            $s->execute([$id]);
            $items = $s->fetchAll();
            if (!$items) throw new Exception("This request has no items.");
            foreach ($items as &$it) {
                $record = resolve_request_inventory_item($pdo, $it, $req["destination"] ?? "");
                $itemId = (int)($record["id"] ?? 0);
                if ($itemId <= 0) throw new Exception("Item {$it["item_name"]} could not be matched to an active inventory item. Check Item Master name, size, and item type.");
                $it["resolved_item_id"] = $itemId;
                $pdo->prepare("UPDATE request_items SET item_id=? WHERE id=? AND request_id=?")->execute([$itemId, $it["id"], $id]);
            }
            unset($it);
            $s = $pdo->prepare("SELECT warehouse_name FROM warehouses WHERE id=? AND status='Active'");
            $s->execute([$warehouseId]);
            $wh = $s->fetch();
            if (!$wh) throw new Exception("Selected warehouse is not active.");
            $approvedRaw = post_value("approved_quantities", "");
            $approved = json_decode($approvedRaw, true);
            if (!is_array($approved)) $approved = [];
            $totalApproved = 0;
            $totalRemaining = 0;
            foreach ($items as $it) {
                $ri = (int)$it["id"];
                $requested = (int)$it["quantity"];
                $aq = array_key_exists((string)$ri, $approved) ? (int)$approved[(string)$ri] : (array_key_exists($ri, $approved) ? (int)$approved[$ri] : $requested);
                if ($aq < 0 || $aq > $requested) throw new Exception("Approved quantity is invalid for {$it["item_name"]}.");
                $remaining = $requested - $aq;
                $itemId = (int)$it["resolved_item_id"];
                if ($aq > 0) {
                    $q = $pdo->prepare("SELECT COALESCE(quantity,0) FROM inventory WHERE item_id=? AND warehouse_id=? FOR UPDATE");
                    $q->execute([$itemId, $warehouseId]);
                    $available = (int)$q->fetchColumn();
                    if ($aq > $available) throw new Exception("Insufficient stock for {$it["item_name"]}. Approved: {$aq}; available: {$available}.");
                }
                $pdo->prepare("UPDATE request_items SET item_id=?,warehouse_id=?,approved_quantity=?,rejected_quantity=0,rejection_reason='' WHERE id=? AND request_id=?")->execute([$itemId, $warehouseId, $aq, $ri, $id]);
                $totalApproved += $aq;
                $totalRemaining += $remaining;
            }
            if ($totalApproved === 0) throw new Exception("Approve at least one item quantity. Use the Remaining Balance for any unapproved quantity.");
            $finalStatus = $totalRemaining > 0 ? "Partially Approved" : "Approved";
            $s = $pdo->prepare("UPDATE requests SET status=?,approved_by=?,approved_at=NOW() WHERE id=? AND status='Pending'");
            $s->execute([$finalStatus, $_SESSION["user_id"], $id]);
            if (!$s->rowCount()) throw new Exception("Request is no longer pending.");
            $index = 0;
            foreach ($items as $it) {
                $index++;
                $ri = (int)$it["id"];
                $q = $pdo->prepare("SELECT approved_quantity FROM request_items WHERE id=?");
                $q->execute([$ri]);
                $aq = (int)$q->fetchColumn();
                if ($aq <= 0) continue;
                $itemId = (int)$it["resolved_item_id"];
                $s = $pdo->prepare("SELECT id FROM stock_out WHERE reference_number=? AND item_id=? LIMIT 1");
                $s->execute([$req["request_number"], $itemId]);
                if (!$s->fetch()) {
                    $sout = "SOUT-" . date("YmdHis") . "-" . $id . "-" . $index;
                    $requesterDestination = trim((string)($req["destination"] ?? ""));
                    $pdo->prepare("INSERT INTO stock_out(stock_out_number,item_id,warehouse_id,quantity,purpose,destination,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$sout, $itemId, $warehouseId, $aq, "Approved Request", $requesterDestination, $req["request_number"], "Automatic stock out for approved request - " . $wh["warehouse_name"], date("Y-m-d"), $_SESSION["user_id"]]);
                    $pdo->prepare("UPDATE inventory SET quantity=quantity-? WHERE item_id=? AND warehouse_id=?")->execute([$aq, $itemId, $warehouseId]);
                    $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?)")->execute([$itemId, $warehouseId, "STOCK OUT", $aq, $req["request_number"], "Stock out for approved quantity", date("Y-m-d"), $_SESSION["user_id"]]);
                }
            }
            if ($totalApproved > 0) {
                ensure_request_delivery($pdo, $id, $req["expected_receive_date"]);
                $requesterTeam = strtolower(trim((string)infer_requester_team($pdo, $req["requested_by"] ?? 0)));
                if ($requesterTeam === "inventory") {
                    $pdo->prepare("UPDATE deliveries SET status='Delivered',delivered_date=COALESCE(delivered_date,CURDATE()) WHERE request_id=?")->execute([$id]);
                }
            }
            $msg = "Request {$req["request_number"]}: {$totalApproved} approved, {$totalRemaining} remaining balance.";
            notify_request_participants($pdo, $req, $finalStatus, $msg, "request", $_SESSION["user_id"]);
        }
        $pdo->commit();
        json_response(["success" => true, "message" => "Approval processed successfully."]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(["success" => false, "message" => $e->getMessage()], 422);
    }
}

if ($action === "accept_delivery") {
    require_login();
    $deliveryId = (int)post_value("id");
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("SELECT d.*,r.request_number,r.requested_by,r.destination,r.recipient_gmail FROM deliveries d JOIN requests r ON r.id=d.request_id WHERE d.id=? FOR UPDATE");
        $s->execute([$deliveryId]);
        $d = $s->fetch();
        if (!$d) throw new Exception("Delivery not found.");
        if ((int)($_SESSION["user_id"] ?? 0) !== (int)$d["requested_by"] && ($_SESSION["role"] ?? "USER") !== "ADMIN") throw new Exception("Only the requesting team can accept this delivery.");
        if (!in_array($d["status"], ["Out for Delivery", "Partially Delivered"], true)) throw new Exception("Delivery must be recorded before receipt can be confirmed.");
        $s = $pdo->prepare("SELECT quantity,approved_quantity,delivered_quantity,inspection_accepted_quantity FROM request_items WHERE request_id=? FOR UPDATE");
        $s->execute([$d["request_id"]]);
        $items = $s->fetchAll();
        $remaining = 0;
        foreach ($items as $it) {
            if ((int)$it["delivered_quantity"] < request_target_qty($it)) throw new Exception("Some approved items are still incomplete.");
            $remaining += max(0, (int)$it["quantity"] - (int)$it["inspection_accepted_quantity"]);
        }
        $pdo->prepare("UPDATE request_items SET accepted_quantity=inspection_accepted_quantity WHERE request_id=?")->execute([$d["request_id"]]);
        $receiverQuery = $pdo->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
        $receiverQuery->execute([$_SESSION["user_id"]]);
        $receiver = (string)$receiverQuery->fetchColumn();
        $newStatus = $remaining > 0 ? "Partially Delivered" : "Delivered";
        $pdo->prepare("UPDATE deliveries SET status=?,delivered_date=COALESCE(delivered_date,CURDATE()),received_date=CURDATE() WHERE id=?")->execute([$newStatus, $deliveryId]);
        $pdo->prepare("UPDATE delivery_receipts SET received_by=?,received_date=CURDATE() WHERE delivery_id=?")->execute([$receiver, $deliveryId]);
        create_notification_for_admins($pdo, "Delivery Accepted", "Delivery {$d["request_number"]} was confirmed by {$receiver}. Status: {$newStatus}.", "delivery", $_SESSION["user_id"]);
        create_notification_for_team($pdo, $d["destination"] ?? "", "Delivery Accepted", "Delivery {$d["request_number"]} was confirmed by {$receiver}. Status: {$newStatus}.", "delivery", $_SESSION["user_id"]);
        $pdo->commit();
        json_response(["success" => true, "message" => "Receipt confirmed. Received By: {$receiver}. Status: {$newStatus}."]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(["success" => false, "message" => $e->getMessage()], 422);
    }
}

function InventoryAppQty($n)
{
    $n = (float)$n;
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}
json_response(["success" => false, "message" => "Invalid action."], 400);
