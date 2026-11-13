<?php
require_once "database.php";
require_login();
require_admin();





function sync_missing_rejection_reports(PDO $pdo): void
{
    $deliveries = $pdo->query("SELECT d.id AS delivery_id, d.request_id,
        COALESCE(NULLIF(GROUP_CONCAT(DISTINCT NULLIF(i.supplier_name,'')),''), NULLIF(r.recipient_name,''), r.destination, 'Unknown Supplier') AS supplier_name,
        COALESCE(dr.received_date, dr.receipt_date, CURDATE()) AS received_date
        FROM deliveries d
        JOIN requests r ON r.id=d.request_id
        JOIN request_items ri ON ri.request_id=r.id
        LEFT JOIN items i ON i.id=ri.item_id
        JOIN delivery_receipts dr ON dr.delivery_id=d.id
        WHERE ri.inspection_rejected_quantity > 0
        GROUP BY d.id, d.request_id, dr.received_date, dr.receipt_date")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deliveries as $delivery) {
        $find = $pdo->prepare("SELECT id FROM supplier_rejections WHERE delivery_id=? LIMIT 1");
        $find->execute([(int)$delivery['delivery_id']]);
        $rejectionId = (int)$find->fetchColumn();
        if (!$rejectionId) {
            $insert = $pdo->prepare("INSERT INTO supplier_rejections
                (request_id, delivery_id, po_number, supplier_name, received_date, report_status, created_by)
                VALUES (?, ?, '', ?, ?, 'Pending', NULL)");
            $insert->execute([(int)$delivery['request_id'], (int)$delivery['delivery_id'], $delivery['supplier_name'], $delivery['received_date']]);
            $rejectionId = (int)$pdo->lastInsertId();
            $number = 'RR-' . date('Y') . '-' . str_pad((string)$rejectionId, 3, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE supplier_rejections SET report_number=? WHERE id=?")->execute([$number, $rejectionId]);
        }



        $items = $pdo->prepare("SELECT ri.id AS request_item_id, ri.item_name, ri.quantity AS ordered_quantity,
            ri.delivered_quantity, ri.inspection_accepted_quantity AS accepted_quantity,
            ri.inspection_rejected_quantity AS rejected_quantity, ri.unit,
            ri.inspection_rejection_reason AS rejection_reason
            FROM request_items ri WHERE ri.request_id=? AND ri.inspection_rejected_quantity > 0 ORDER BY ri.id");
        $items->execute([(int)$delivery['request_id']]);
        $existsItem = $pdo->prepare("SELECT id FROM supplier_rejection_items WHERE rejection_id=? AND request_item_id=? LIMIT 1");
        $insertItem = $pdo->prepare("INSERT INTO supplier_rejection_items
            (rejection_id, request_item_id, item_name, ordered_quantity, delivered_quantity,
             accepted_quantity, rejected_quantity, unit, rejection_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $updateItem = $pdo->prepare("UPDATE supplier_rejection_items SET item_name=?, ordered_quantity=?,
            delivered_quantity=?, accepted_quantity=?, rejected_quantity=?, unit=?, rejection_reason=? WHERE id=?");
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $existsItem->execute([$rejectionId, $item['request_item_id']]);
            $existingItemId = (int)$existsItem->fetchColumn();
            $values = [
                $item['item_name'],
                (int)$item['ordered_quantity'],
                (int)$item['delivered_quantity'],
                (int)$item['accepted_quantity'],
                (int)$item['rejected_quantity'],
                $item['unit'] ?: 'pcs',
                $item['rejection_reason'] ?: ''
            ];
            if ($existingItemId) {
                $updateItem->execute(array_merge($values, [$existingItemId]));
            } else {
                $insertItem->execute([$rejectionId, $item['request_item_id'], ...$values]);
            }
        }
    }
}

$action = $_REQUEST["action"] ?? "list";

if ($action === "list") {
    sync_missing_rejection_reports($pdo);
    $sql = "SELECT sr.id, sr.report_number, sr.po_number, sr.supplier_name, sr.supplier_gmail,
                   sr.received_date, sr.report_status, sr.email_sent_at,
                   sr.supplier_response_at, sr.created_at, r.request_number,
                   COALESCE(NULLIF(r.destination,''), NULLIF(r.requester_team,''), 'Internal Department') AS department,
                   dr.dr_number,
                   COUNT(CASE WHEN sri.rejected_quantity > 0 THEN 1 END) AS rejected_line_count,
                   COALESCE(SUM(sri.rejected_quantity),0) AS rejected_total
            FROM supplier_rejections sr
            LEFT JOIN requests r ON r.id = sr.request_id
            LEFT JOIN deliveries d ON d.id = sr.delivery_id
            LEFT JOIN delivery_receipts dr ON dr.delivery_id = d.id
            LEFT JOIN supplier_rejection_items sri ON sri.rejection_id = sr.id
            GROUP BY sr.id
            ORDER BY sr.id DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    json_response(["success" => true, "rows" => $rows]);
}


if ($action === "details") {
    $id = (int)($_GET["id"] ?? 0);
    $s = $pdo->prepare("SELECT sr.*, r.request_number, r.request_date,
                               COALESCE(NULLIF(r.destination,''), NULLIF(r.requester_team,''), 'Internal Department') AS department,
                               dr.id AS delivery_receipt_id, dr.dr_number, dr.receipt_date, dr.received_by, dr.received_date AS dr_received_date, dr.remarks AS dr_remarks,
                               d.status AS delivery_status, d.expected_date, d.shipped_date, d.out_for_delivery_date, d.delivered_date,
                               r.recipient_name, r.destination,
                               COALESCE(NULLIF(u.full_name,''),'Inventory / Warehouse Team') AS requester
                        FROM supplier_rejections sr
                        LEFT JOIN requests r ON r.id = sr.request_id
                        LEFT JOIN deliveries d ON d.id = sr.delivery_id
                        LEFT JOIN delivery_receipts dr ON dr.delivery_id = d.id
                        LEFT JOIN users u ON u.id = sr.created_by
                        WHERE sr.id = ? LIMIT 1");
    $s->execute([$id]);
    $rejection = $s->fetch(PDO::FETCH_ASSOC);
    if (!$rejection) json_response(["success" => false, "message" => "Rejection report not found."], 404);

    if (empty($rejection["report_number"])) {
        $rejection["report_number"] = "RR-" . date("Y") . "-" . str_pad((string)$id, 3, "0", STR_PAD_LEFT);
        $pdo->prepare("UPDATE supplier_rejections SET report_number=? WHERE id=? AND (report_number IS NULL OR report_number='')")
            ->execute([$rejection["report_number"], $id]);
    }

    $q = $pdo->prepare("SELECT item_name, ordered_quantity, delivered_quantity, accepted_quantity,
                               rejected_quantity, unit, rejection_reason
                        FROM supplier_rejection_items WHERE rejection_id=? ORDER BY id");
    $q->execute([$id]);
    $rejection["items"] = $q->fetchAll(PDO::FETCH_ASSOC);
    json_response(["success" => true, "rejection" => $rejection]);
}

if ($action === "email_sent") {
    $id = (int)post_value("id");
    $check = $pdo->prepare("SELECT id, supplier_gmail FROM supplier_rejections WHERE id=? LIMIT 1");
    $check->execute([$id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_response(["success" => false, "message" => "Rejection report not found."], 404);
    $email = trim((string)post_value("supplier_gmail", $row["supplier_gmail"]));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(["success" => false, "message" => "Enter a valid recipient email address."], 422);
    $pdo->prepare("UPDATE supplier_rejections SET supplier_gmail=?, report_status='Email Sent', email_sent_at=NOW() WHERE id=?")
        ->execute([$email, $id]);
    json_response(["success" => true, "message" => "Email status updated. The report is marked as Email Sent."]);
}

if ($action === "supplier_response") {
    $id = (int)post_value("id");
    $response = trim((string)post_value("supplier_response"));
    if ($response === "") json_response(["success" => false, "message" => "Enter the supplier's response first."], 422);
    $stmt = $pdo->prepare("UPDATE supplier_rejections SET supplier_response=?, report_status='Supplier Response', supplier_response_at=NOW() WHERE id=?");
    $stmt->execute([$response, $id]);
    if (!$stmt->rowCount()) {
        $exists = $pdo->prepare("SELECT id FROM supplier_rejections WHERE id=?");
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) json_response(["success" => false, "message" => "Rejection report not found."], 404);
    }
    json_response(["success" => true, "message" => "Supplier response saved."]);
}

if ($action === "seed_demo") {
    try {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM supplier_rejections WHERE report_number LIKE 'RR-DEMO-%'")->fetchColumn();
        if ($exists > 0) json_response(["success" => true, "message" => "Sample reports are already loaded. Use Remove Sample Data before loading them again."]);
        $warehouseId = (int)$pdo->query("SELECT id FROM warehouses ORDER BY id LIMIT 1")->fetchColumn();
        if ($warehouseId < 1) json_response(["success" => false, "message" => "Create at least one warehouse before loading sample reports."], 422);
        $pdo->beginTransaction();
        $samples = [
            ["Procurement", "PO-DEMO-001", "SynthHide Textile Corp.", "supplier.procurement@example.com", "DN-DEMO-001", [["Canvas Fabric (Natural)", 100, 100, 0, "meters", ""], ["PU Leather (Black)", 80, 75, 5, "meters", "Torn edges in transit"], ["Nubuck Leather (Tan)", 60, 60, 0, "sq.ft.", ""]]],
            ["Production", "PO-DEMO-002", "Production Department", "production@example.com", "DN-DEMO-002", [["Classic Runner Upper", 50, 45, 5, "pcs", "Stitching defect"], ["Rubber Outsole", 50, 50, 0, "pcs", ""]]],
            ["Sales", "PO-DEMO-003", "Sales Department", "sales@example.com", "DN-DEMO-003", [["Classic Runner - Size 37", 20, 18, 2, "pairs", "Visible scratches"], ["Classic Runner - Size 38", 15, 15, 0, "pairs", ""]]]
        ];
        $createdBy = (int)($_SESSION["user_id"] ?? 0) ?: null;
        foreach ($samples as $index => $sample) {
            [$department, $po, $supplier, $gmail, $dr, $items] = $sample;
            $requestNo = "REQ-DEMO-" . ($index + 1) . "-" . date("Ymd");
            $stmt = $pdo->prepare("INSERT INTO requests (request_number, requester_team, request_date, status, recipient_name, recipient_gmail, destination, remarks) VALUES (?, ?, CURDATE(), 'Approved', ?, ?, ?, 'DEMO SAMPLE - safe to remove')");
            $stmt->execute([$requestNo, $department, $supplier, $gmail, $department]);
            $requestId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO deliveries (request_id,status,remarks) VALUES (?,'Delivered','DEMO SAMPLE - safe to remove')");
            $stmt->execute([$requestId]);
            $deliveryId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO delivery_receipts (dr_number,delivery_id,request_id,receipt_date,received_by,received_date,remarks) VALUES (?,?,?,CURDATE(),'Demo Warehouse Receiver',CURDATE(),'DEMO SAMPLE - safe to remove')");
            $stmt->execute([$dr, $deliveryId, $requestId]);
            $stmt = $pdo->prepare("INSERT INTO supplier_rejections (request_id,delivery_id,report_number,po_number,supplier_name,supplier_gmail,received_date,report_status,created_by) VALUES (?,?,?,?,?,?,CURDATE(),'Pending',?)");
            $stmt->execute([$requestId, $deliveryId, "RR-DEMO-" . date("Y") . "-" . str_pad((string)($index + 1), 3, "0", STR_PAD_LEFT), $po, $supplier, $gmail, $createdBy]);
            $rejectionId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare("INSERT INTO request_items (request_id,item_name,warehouse_id,quantity,approved_quantity,delivered_quantity,accepted_quantity,inspection_accepted_quantity,inspection_rejected_quantity,inspection_rejection_reason,unit) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $rejectStmt = $pdo->prepare("INSERT INTO supplier_rejection_items (rejection_id,request_item_id,item_name,ordered_quantity,delivered_quantity,accepted_quantity,rejected_quantity,unit,rejection_reason) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($items as $item) {
                [$name, $ordered, $accepted, $rejected, $unit, $reason] = $item;
                $itemStmt->execute([$requestId, $name, $warehouseId, $ordered, $ordered, $ordered, $accepted, $accepted, $rejected, $reason, $unit]);
                $requestItemId = (int)$pdo->lastInsertId();
                $rejectStmt->execute([$rejectionId, $requestItemId, $name, $ordered, $ordered, $accepted, $rejected, $unit, $reason]);
            }
        }
        $pdo->commit();
        json_response(["success" => true, "message" => "3 sample rejection reports added for Procurement, Production and Sales."]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(["success" => false, "message" => "Unable to load sample reports: " . $e->getMessage()], 500);
    }
}

if ($action === "clear_demo") {
    try {
        $pdo->beginTransaction();
        $ids = $pdo->query("SELECT request_id FROM supplier_rejections WHERE report_number LIKE 'RR-DEMO-%'")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM requests WHERE id IN ($marks)")->execute($ids);
        }
        $pdo->commit();
        json_response(["success" => true, "message" => "Sample rejection reports and their DEMO-only transactions were removed. Real records were kept."]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(["success" => false, "message" => "Unable to remove sample reports: " . $e->getMessage()], 500);
    }
}

json_response(["success" => false, "message" => "Invalid action."], 400);
