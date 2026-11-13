<?php
require_once "_bootstrap.php";
api_method(["GET"]);
$team = api_auth();
$reportId = (int)($_GET["report_id"] ?? 0);
$sql = "SELECT sr.id, sr.report_number, sr.po_number, sr.supplier_name, sr.supplier_gmail,
               sr.received_date, sr.report_status, r.request_number, r.destination,
               dr.dr_number, dr.id delivery_receipt_id
        FROM supplier_rejections sr
        JOIN requests r ON r.id=sr.request_id
        JOIN deliveries d ON d.id=sr.delivery_id
        LEFT JOIN delivery_receipts dr ON dr.delivery_id=d.id
        WHERE r.destination=?";
$params = [$team];
if ($reportId > 0) { $sql .= " AND sr.id=?"; $params[] = $reportId; }
$sql .= " ORDER BY sr.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $items = $pdo->prepare("SELECT item_name, ordered_quantity, delivered_quantity,
        accepted_quantity, rejected_quantity, unit, rejection_reason
        FROM supplier_rejection_items WHERE rejection_id=? ORDER BY id");
    $items->execute([(int)$row["id"]]);
    $row["items"] = $items->fetchAll(PDO::FETCH_ASSOC);
}
unset($row);
api_response(true, "Rejected items retrieved successfully.", $reportId > 0 ? ($rows[0] ?? null) : $rows);
?>
