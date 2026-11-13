<?php
require_once "database.php";
require_login();


$pdo->exec("CREATE TABLE IF NOT EXISTS low_stock_alert_state (
    item_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    last_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    alert_active TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, warehouse_id)
) ENGINE=InnoDB");

$pdo->beginTransaction();
try {


    $hasState = (int)$pdo->query("SELECT COUNT(*) FROM low_stock_alert_state")->fetchColumn() > 0;
    if (!$hasState) {
        $pdo->exec("INSERT IGNORE INTO low_stock_alert_state (item_id, warehouse_id, last_quantity, alert_active)
                    SELECT inv.item_id, inv.warehouse_id, inv.quantity, 0
                    FROM inventory inv");
    }

    $inventoryRows = $pdo->query("SELECT inv.item_id, inv.warehouse_id, inv.quantity,
                                         i.reorder_level, i.status AS item_status,
                                         w.status AS warehouse_status
                                  FROM inventory inv
                                  INNER JOIN items i ON i.id = inv.item_id
                                  INNER JOIN warehouses w ON w.id = inv.warehouse_id")->fetchAll();
    $getState = $pdo->prepare("SELECT last_quantity, alert_active FROM low_stock_alert_state WHERE item_id=? AND warehouse_id=?");
    $insertState = $pdo->prepare("INSERT IGNORE INTO low_stock_alert_state (item_id, warehouse_id, last_quantity, alert_active) VALUES (?,?,?,0)");
    $updateState = $pdo->prepare("UPDATE low_stock_alert_state SET last_quantity=?, alert_active=? WHERE item_id=? AND warehouse_id=?");

    foreach ($inventoryRows as $row) {
        $itemId = (int)$row['item_id'];
        $warehouseId = (int)$row['warehouse_id'];
        $quantity = (float)$row['quantity'];
        $reorder = (float)$row['reorder_level'];
        $getState->execute([$itemId, $warehouseId]);
        $state = $getState->fetch();
        if (!$state) {

            $insertState->execute([$itemId, $warehouseId, $quantity]);
            continue;
        }
        $previousQuantity = (float)$state['last_quantity'];
        $alertActive = (int)$state['alert_active'];

        if ($row['item_status'] !== 'Active' || $row['warehouse_status'] !== 'Active' || $reorder <= 0) {
            $alertActive = 0;
        } elseif ($quantity > $reorder) {

            $alertActive = 0;
        } elseif ($previousQuantity > $reorder && $quantity <= $reorder) {

            $alertActive = 1;
        }
        $updateState->execute([$quantity, $alertActive, $itemId, $warehouseId]);
    }

    $sql = "SELECT i.id, i.item_code, i.item_name, i.item_type, i.size,
                   w.id AS warehouse_id, w.warehouse_name,
                   inv.quantity AS quantity, inv.quantity AS remaining_balance,
                   i.reorder_level
            FROM inventory inv
            INNER JOIN items i ON i.id = inv.item_id
            INNER JOIN warehouses w ON w.id = inv.warehouse_id
            INNER JOIN low_stock_alert_state s ON s.item_id=inv.item_id AND s.warehouse_id=inv.warehouse_id
            WHERE i.status='Active' AND w.status='Active'
              AND i.reorder_level > 0 AND s.alert_active=1
              AND inv.quantity <= i.reorder_level
            ORDER BY i.created_at DESC, i.id DESC, inv.quantity ASC";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $quantity = (float)$r['remaining_balance'];
        $reorder = (float)$r['reorder_level'];
        $r['status'] = $quantity <= ($reorder * 0.5) ? 'Critical' : 'Low Stock';
    }
    unset($r);
    $pdo->commit();
    json_response(['success' => true, 'rows' => $rows, 'count' => count($rows)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['success' => false, 'message' => 'Unable to load low stock alerts.'], 500);
}
