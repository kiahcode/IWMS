<?php
require_once "database.php";
require_login();
$action = $_REQUEST["action"] ?? "list";


function refresh_delivery_receipt_status($pdo, $deliveryId)
{
    $q = $pdo->prepare("SELECT d.id,d.status,d.request_id,r.requester_team FROM deliveries d JOIN requests r ON r.id=d.request_id WHERE d.id=? FOR UPDATE");
    $q->execute([(int)$deliveryId]);
    $d = $q->fetch();
    if (!$d) return null;
    $s = $pdo->prepare("SELECT quantity,approved_quantity,delivered_quantity,accepted_quantity FROM request_items WHERE request_id=?");
    $s->execute([(int)$d["request_id"]]);
    $items = $s->fetchAll();
    if (!$items) return $d;

    $allDelivered = true;
    $anyDelivered = false;
    $remainingBalance = 0;
    foreach ($items as $it) {
        $target = strtolower(trim((string)($d["requester_team"] ?? ""))) === "inventory" ? (int)$it["quantity"] : (int)$it["approved_quantity"];
        $del = (int)$it["delivered_quantity"];
        $acc = $del > 0 ? (int)$it["accepted_quantity"] : (int)$it["approved_quantity"];
        if ($del > 0) $anyDelivered = true;
        if ($del < $target) $allDelivered = false;
        $remainingBalance += max(0, (int)$it["quantity"] - $acc);
    }

    if ($remainingBalance > 0 || !$allDelivered) {
        $newStatus = ($anyDelivered || $remainingBalance > 0) ? "Partially Delivered" : "To Ship";
    } else {
        $newStatus = "Delivered";
    }

    if ($newStatus !== $d["status"]) {
        $sets = ["status=?"];
        $params = [$newStatus];
        if ($anyDelivered) {
            $sets[] = "shipped_date=COALESCE(shipped_date,CURDATE())";
            $sets[] = "out_for_delivery_date=COALESCE(out_for_delivery_date,CURDATE())";
        }
        if ($allDelivered) $sets[] = "delivered_date=COALESCE(delivered_date,CURDATE())";
        if ($newStatus === "Delivered") $sets[] = "received_date=COALESCE(received_date,CURDATE())";
        $params[] = (int)$deliveryId;
        $pdo->prepare("UPDATE deliveries SET " . implode(",", $sets) . " WHERE id=?")->execute($params);
        $d["status"] = $newStatus;
    }
    return $d;
}

function ensure_receipt($pdo, $delivery_id)
{
    $s = $pdo->prepare("SELECT dr.id,dr.dr_number FROM delivery_receipts dr WHERE dr.delivery_id=?");
    $s->execute([$delivery_id]);
    $existing = $s->fetch();
    if ($existing) return $existing;
    $s = $pdo->prepare("SELECT d.id,d.request_id FROM deliveries d WHERE d.id=?");
    $s->execute([$delivery_id]);
    $d = $s->fetch();
    if (!$d) return null;
    $num = "DR-" . date("YmdHis") . "-" . $d["id"];
    try {
        $pdo->prepare("INSERT INTO delivery_receipts(dr_number,delivery_id,request_id,receipt_date) VALUES(?,?,?,CURDATE())")->execute([$num, $d["id"], $d["request_id"]]);
    } catch (Exception $e) {
    }
    $s->execute([$delivery_id]);
    return $s->fetch();
}

if ($action === "list") {




    $control = can_control_session($pdo);
    $sql = "SELECT dr.id,dr.dr_number,dr.receipt_date,dr.received_by,dr.received_date,dr.remarks,
                 d.id delivery_id,d.status,d.expected_date,
                 r.request_number,r.requested_by,r.requester_team,r.recipient_name,r.destination,
                 GROUP_CONCAT(DISTINCT ri.item_name ORDER BY ri.id SEPARATOR ', ') item_name,
                 MAX(i.item_type) item_type,
                 MAX(COALESCE(NULLIF(ri.size,''),i.size)) item_size,
                 COALESCE(NULLIF(u.full_name,''),NULLIF(r.requester_team,''),NULLIF(r.recipient_name,''),'—') requester
          FROM delivery_receipts dr
          JOIN deliveries d ON d.id=dr.delivery_id
          JOIN requests r ON r.id=dr.request_id
          LEFT JOIN request_items ri ON ri.request_id=r.id
          LEFT JOIN items i ON i.id=ri.item_id OR (ri.item_id IS NULL AND i.item_name=ri.item_name)
          LEFT JOIN users u ON u.id=r.requested_by
          WHERE COALESCE(r.remarks,'') NOT LIKE '%DEMO SAMPLE%' AND COALESCE(d.remarks,'') NOT LIKE '%DEMO SAMPLE%' AND COALESCE(dr.remarks,'') NOT LIKE '%DEMO SAMPLE%' AND COALESCE(r.remarks,'') NOT LIKE '%sample%' AND COALESCE(d.remarks,'') NOT LIKE '%sample%' AND COALESCE(dr.remarks,'') NOT LIKE '%sample%' AND COALESCE(r.request_number,'') NOT LIKE 'REQ-DEMO-%'";
    $p = [];
    if (!$control && (int)($_SESSION["user_id"] ?? 0) > 0) {
        $sql .= " AND r.requested_by=?";
        $p[] = $_SESSION["user_id"];
    }
    $sql .= " GROUP BY dr.id ORDER BY dr.id DESC";
    $s = $pdo->prepare($sql);
    $s->execute($p);
    $rows = $s->fetchAll();

    foreach ($rows as &$row) {
        try {
            $pdo->beginTransaction();
            $fresh = refresh_delivery_receipt_status($pdo, (int)$row["delivery_id"]);
            $pdo->commit();
            if ($fresh) $row["status"] = $fresh["status"];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    unset($row);
    json_response(["success" => true, "rows" => $rows]);
}

if ($action === "details") {
    $id = (int)($_GET["id"] ?? 0);
    $s = $pdo->prepare("SELECT dr.*,d.id delivery_id,d.status,d.expected_date,d.shipped_date,d.out_for_delivery_date,d.delivered_date,
                             r.request_number,r.request_date,r.recipient_name,r.destination,r.requested_by,r.requester_team,
                             r.remarks request_remarks,
                             COALESCE(NULLIF(u.full_name,''),NULLIF(r.requester_team,''),NULLIF(r.recipient_name,''),'—') requester
                      FROM delivery_receipts dr
                      JOIN deliveries d ON d.id=dr.delivery_id
                      JOIN requests r ON r.id=dr.request_id
                      LEFT JOIN users u ON u.id=r.requested_by
                      WHERE dr.id=?");
    $s->execute([$id]);
    $dr = $s->fetch();
    if (!$dr) json_response(["success" => false, "message" => "Delivery Receipt not found."], 404);

    $control = can_control_session($pdo);
    if (!$control && (int)$dr["requested_by"] !== (int)$_SESSION["user_id"]) {
        json_response(["success" => false, "message" => "Access denied."], 403);
    }

    try {
        $pdo->beginTransaction();
        $fresh = refresh_delivery_receipt_status($pdo, (int)$dr["delivery_id"]);
        $pdo->commit();
        if ($fresh) $dr["status"] = $fresh["status"];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }

    $s = $pdo->prepare("SELECT ri.item_name,ri.quantity,ri.approved_quantity,ri.rejected_quantity,
                             ri.rejection_reason,ri.delivered_quantity,ri.accepted_quantity,ri.unit,ri.size,
                             i.item_code,i.item_type,i.size item_size,w.warehouse_name,w.location
                      FROM request_items ri
                      LEFT JOIN items i ON i.id=ri.item_id OR (ri.item_id IS NULL AND i.item_name=ri.item_name)
                      JOIN warehouses w ON w.id=ri.warehouse_id
                      WHERE ri.request_id=? ORDER BY ri.id");
    $s->execute([$dr["request_id"]]);
    $dr["items"] = $s->fetchAll();
    json_response(["success" => true, "receipt" => $dr]);
}

if ($action === "update") {
    require_admin();
    $id = (int)post_value("id");
    $received_by = post_value("received_by");
    $received_date = post_value("received_date");
    $remarks = post_value("remarks");
    $s = $pdo->prepare("UPDATE delivery_receipts SET received_by=?,received_date=?,remarks=? WHERE id=?");
    $s->execute([$received_by, $received_date ?: null, $remarks, $id]);
    json_response(["success" => true, "message" => "Delivery Receipt updated successfully."]);
}
json_response(["success" => false, "message" => "Invalid action."], 400);
