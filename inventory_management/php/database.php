<?php



$host = '127.0.0.1';
$dbname = 'inventory_system';
$dbuser = 'root';
$dbpass = '';
$charset = 'utf8mb4';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function post_value($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function json_response($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset={$charset}",
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    json_response([
        'success' => false,
        'message' => 'Database connection failed. Make sure MySQL/MariaDB is running and the database "inventory_system" exists.'
    ], 500);
}

ensure_notification_tables($pdo);
ensure_user_fields($pdo);
ensure_destination_choices($pdo);

ensure_rejection_tables($pdo);
ensure_inventory_catalog($pdo);

function ensure_migration_done(PDO $pdo, $name)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_migrations (name VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $q = $pdo->prepare("SELECT 1 FROM app_migrations WHERE name=?");
    $q->execute([$name]);
    return (bool)$q->fetchColumn();
}
function mark_migration_done(PDO $pdo, $name)
{
    $pdo->prepare("INSERT IGNORE INTO app_migrations(name) VALUES(?)")->execute([$name]);
}

function ensure_inventory_schema_updates(PDO $pdo)
{
    static $ready = false;
    if ($ready) return;
    $ready = true;

    foreach (
        [
            "ALTER TABLE stock_in ADD COLUMN unit VARCHAR(30) NOT NULL DEFAULT '' AFTER quantity",
            "ALTER TABLE stock_movements ADD COLUMN unit VARCHAR(30) NULL DEFAULT NULL AFTER quantity",
            "ALTER TABLE stock_transfers MODIFY status ENUM('Pending','Completed','Cancelled','Delivered') NOT NULL DEFAULT 'Delivered'"
        ] as $sql
    ) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
        }
    }
    if (!ensure_migration_done($pdo, 'transfer_completed_to_delivered_v1')) {
        try {
            $pdo->exec("UPDATE stock_transfers SET status='Delivered' WHERE status='Completed'");
            mark_migration_done($pdo, 'transfer_completed_to_delivered_v1');
        } catch (PDOException $e) {
        }
    }
}

function ensure_inventory_catalog(PDO $pdo)
{
    static $ready = false;
    if ($ready) return;
    $ready = true;
    try {
        ensure_inventory_schema_updates($pdo);
        if (!ensure_migration_done($pdo, 'catalog_v1')) {
            ensure_base_catalog($pdo);
        }
        if (!ensure_migration_done($pdo, 'finished_goods_four_items_v1')) {
            ensure_four_finished_goods($pdo);
        }
        if (!ensure_migration_done($pdo, 'finished_goods_individual_sizes_v1')) {
            ensure_finished_goods_individual_sizes($pdo);
        }
        if (!ensure_migration_done($pdo, 'finished_goods_all_sizes_v2')) {
            ensure_finished_goods_all_sizes($pdo);
        }
        ensure_inventory_ledger_reconciliation($pdo);
        ensure_secondary_raw_material_stock_50($pdo);
    } catch (Throwable $e) {
        error_log('Inventory setup: ' . $e->getMessage());
    }
}


function ensure_base_catalog(PDO $pdo)
{
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO categories(category_name,status) VALUES
          ('Raw Materials','Active'),('Footwear','Active')
          ON DUPLICATE KEY UPDATE status='Active'");
        $warehouses = [
            ['WH-MAIN', 'Main Warehouse', 'Main Storage', 'Andrew Suficiencia'],
            ['WH-SECONDARY', 'Secondary Warehouse', 'Secondary Storage', 'Meynard Paje']
        ];
        foreach ($warehouses as $w) {
            $findWh = $pdo->prepare("SELECT id FROM warehouses WHERE warehouse_name=? ORDER BY id LIMIT 1");
            $findWh->execute([$w[1]]);
            $existingWh = (int)$findWh->fetchColumn();
            if ($existingWh) {
                $updWh = $pdo->prepare("UPDATE warehouses SET warehouse_code=?,location=?,manager=?,status='Active' WHERE id=?");
                $updWh->execute([$w[0], $w[2], $w[3], $existingWh]);
            } else {
                $insWh = $pdo->prepare("INSERT INTO warehouses(warehouse_code,warehouse_name,location,manager,status) VALUES(?,?,?,?, 'Active')
                  ON DUPLICATE KEY UPDATE warehouse_name=VALUES(warehouse_name),location=VALUES(location),manager=VALUES(manager),status='Active'");
                $insWh->execute($w);
            }
        }
        $categoryIds = [];
        foreach (['Raw Materials', 'Footwear'] as $name) {
            $q = $pdo->prepare("SELECT id FROM categories WHERE category_name=?");
            $q->execute([$name]);
            $categoryIds[$name] = (int)$q->fetchColumn();
        }
        $raw = [
            ['RM-001', 'Full-Grain Leather', 'square feet', 'Marikina Hide & Leather Co.'],
            ['RM-002', 'Black Leather', 'square feet', 'Marikina Hide & Leather Co.'],
            ['RM-003', 'Nubuck Leather', 'square feet', '2Cordillera Leather Works'],
            ['RM-004', 'Mesh Fabric', 'meters', 'SynthHide Textile Corp.'],
            ['RM-005', 'Canvas Fabric', 'meters', 'SynthHide Textile Corp.'],
            ['RM-006', 'PU Leather', 'meters', 'SynthHide Textile Corp.'],
            ['RM-007', 'Rubber Sole', 'pairs', 'SoleTech Rubber Manufacturing'],
            ['RM-008', 'EVA Foam Sheets', 'pairs', 'SoleTech Rubber Manufacturing'],
            ['RM-009', 'Foam Insole', 'pairs', 'SoleTech Rubber Manufacturing'],
            ['RM-010', 'Metal Eyelets', 'gross', 'MetalCraft Shoe Hardware'],
            ['RM-011', 'Metal Buckles', 'pieces', 'MetalCraft Shoe Hardware'],
            ['RM-012', 'Shoelaces', 'pieces', 'MetalCraft Shoe Hardware'],
            ['RM-013', 'Shoe Boxes', 'pieces', 'EcoBox Packaging Solutions'],
            ['RM-014', 'Tissue Paper Rolls', 'pieces', 'EcoBox Packaging Solutions'],
            ['RM-015', 'Packaging Tape', 'rolls', 'EcoBox Packaging Solutions'],
            ['RM-016', 'Shoe Adhesive', 'liters', 'SynthHide Textile Corp.']
        ];
        $itemStmt = $pdo->prepare("INSERT INTO items(item_code,item_name,brand,supplier_name,size,item_type,category_id,unit,description,reorder_level,status)
          VALUES(?,?,?,?,?,?,?,?,?,10,'Active')
          ON DUPLICATE KEY UPDATE item_name=VALUES(item_name),brand=VALUES(brand),supplier_name=VALUES(supplier_name),
          size=VALUES(size),item_type=VALUES(item_type),category_id=VALUES(category_id),unit=VALUES(unit),description=VALUES(description),status='Active'");
        $pdo->exec("UPDATE items SET status='Inactive' WHERE item_type='Raw Material'");
        foreach ($raw as $r) {
            $findItem = $pdo->prepare("SELECT id FROM items WHERE item_name=? AND item_type='Raw Material' ORDER BY id LIMIT 1");
            $findItem->execute([$r[1]]);
            $existingItem = (int)$findItem->fetchColumn();
            if ($existingItem) {
                $updItem = $pdo->prepare("UPDATE items SET item_code=?,brand='',supplier_name=?,size='',category_id=?,unit=?,description='Shoe manufacturing raw material',status='Active' WHERE id=?");
                $updItem->execute([$r[0], $r[3], $categoryIds['Raw Materials'], $r[2], $existingItem]);
            } else {
                $itemStmt->execute([$r[0], $r[1], '', $r[3], '', 'Raw Material', $categoryIds['Raw Materials'], $r[2], 'Shoe manufacturing raw material']);
            }
        }
        $main = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code='WH-MAIN'")->fetchColumn();
        $secondary = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code='WH-SECONDARY'")->fetchColumn();
        $all = $pdo->query("SELECT id FROM items WHERE item_code LIKE 'RM-%'")->fetchAll(PDO::FETCH_COLUMN);
        $inv = $pdo->prepare("INSERT INTO inventory(item_id,warehouse_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)");
        $movement = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,reference_number,remarks,date,user_id)
          SELECT ?,?,'STOCK IN',?,?, 'Opening stock - catalog setup', '2000-01-01', NULL
          WHERE NOT EXISTS (SELECT 1 FROM stock_movements WHERE reference_number=?)");
        foreach ($all as $id) {
            $id = (int)$id;
            $inv->execute([$id, $main, 50]);
            $inv->execute([$id, $secondary, 50]);
            $pdo->prepare("DELETE FROM stock_movements WHERE item_id=? AND warehouse_id=? AND movement_type='STOCK IN' AND date='2000-01-01' AND reference_number LIKE 'OPENING-%'")->execute([$id, $main]);
            $ref = 'OPENING-CATALOG-' . $id;
            $movement->execute([$id, $main, 50, $ref, $ref]);
            $secondaryRef = 'OPENING-CATALOG-SECONDARY-' . $id;
            $movement->execute([$id, $secondary, 50, $secondaryRef, $secondaryRef]);
        }
        $pdo->commit();
        mark_migration_done($pdo, 'catalog_v1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Inventory catalog setup: ' . $e->getMessage());
    }
}


function ensure_four_finished_goods(PDO $pdo)
{
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO categories(category_name,status) VALUES('Footwear','Active') ON DUPLICATE KEY UPDATE status='Active'");
        $cat = (int)$pdo->query("SELECT id FROM categories WHERE category_name='Footwear'")->fetchColumn();
        $main = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code='WH-MAIN'")->fetchColumn();
        $secondary = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code='WH-SECONDARY'")->fetchColumn();
        if ($main <= 0 || $secondary <= 0) throw new Exception('Main and Secondary warehouses are required.');


        $old = $pdo->query("SELECT id FROM items WHERE item_type='Finished Good'")->fetchAll(PDO::FETCH_COLUMN);
        if ($old) {
            $in = implode(',', array_map('intval', $old));
            $pdo->exec("DELETE FROM stock_transfer_items WHERE item_id IN ($in)");
            foreach ($pdo->query("SELECT id FROM stock_transfers WHERE item_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                $c = $pdo->prepare("SELECT item_id,quantity FROM stock_transfer_items WHERE transfer_id=? ORDER BY id LIMIT 1");
                $c->execute([$tid]);
                $child = $c->fetch();
                if ($child) $pdo->prepare("UPDATE stock_transfers SET item_id=?,quantity=? WHERE id=?")->execute([$child['item_id'], $child['quantity'], $tid]);
                else $pdo->prepare("DELETE FROM stock_transfers WHERE id=?")->execute([$tid]);
            }
            $pdo->exec("DELETE FROM stock_movements WHERE item_id IN ($in)");
            $pdo->exec("DELETE FROM stock_in WHERE item_id IN ($in)");
            $pdo->exec("DELETE FROM stock_out WHERE item_id IN ($in)");
            $pdo->exec("DELETE FROM stock_adjustments WHERE item_id IN ($in)");
            $pdo->exec("DELETE FROM inventory WHERE item_id IN ($in)");
            $pdo->exec("DELETE FROM items WHERE id IN ($in)");
        }

        $goods = [
            ['FG-001', 'Quick Step Classic Low', '26–30'],
            ['FG-002', 'Quick Step Street Runner', '31–36'],
            ['FG-003', 'Quick Step Court Flex', '37–41'],
            ['FG-004', 'Quick Step Urban Walk', '42–45']
        ];
        $ins = $pdo->prepare("INSERT INTO items(item_code,item_name,brand,supplier_name,size,item_type,category_id,unit,description,reorder_level,status)
          VALUES(?,?,'Quick Step','',?,'Finished Good',?,'pairs','Quick Step footwear product',10,'Active')");
        $inv = $pdo->prepare("INSERT INTO inventory(item_id,warehouse_id,quantity) VALUES(?,?,50) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)");
        $mv = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,unit,reference_number,remarks,date,user_id)
          VALUES(?,?,'STOCK IN',50,'pairs',?,'Opening stock - catalog setup','2000-01-01',NULL)");
        foreach ($goods as $g) {
            $ins->execute([$g[0], $g[1], $g[2], $cat]);
            $id = (int)$pdo->lastInsertId();
            foreach ([$main, $secondary] as $wh) {
                $inv->execute([$id, $wh]);
                $mv->execute([$id, $wh, 'OPENING-CATALOG-' . $id . '-' . $wh]);
            }
        }
        $pdo->commit();
        mark_migration_done($pdo, 'finished_goods_four_items_v1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Finished goods setup: ' . $e->getMessage());
    }
}




function ensure_finished_goods_individual_sizes(PDO $pdo)
{
    $pdo->beginTransaction();
    try {
        $goods = [
            ['FG-001', 'Quick Step Classic Low'],
            ['FG-002', 'Quick Step Street Runner'],
            ['FG-003', 'Quick Step Court Flex'],
            ['FG-004', 'Quick Step Urban Walk']
        ];
        $main = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code='WH-MAIN' LIMIT 1")->fetchColumn();
        $secondary = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code IN ('WH-SECONDARY','WH-SEC') ORDER BY CASE WHEN warehouse_code='WH-SECONDARY' THEN 0 ELSE 1 END LIMIT 1")->fetchColumn();
        $cat = (int)$pdo->query("SELECT id FROM categories WHERE category_name='Footwear' LIMIT 1")->fetchColumn();
        if (!$main || !$secondary || !$cat) throw new Exception('Finished Goods setup requires Footwear category and both warehouses.');
        $find = $pdo->prepare("SELECT id FROM items WHERE item_code=? AND item_type='Finished Good' LIMIT 1");
        $update = $pdo->prepare("UPDATE items SET size='26', brand='Quick Step', unit='pairs', status='Active' WHERE id=?");
        $insert = $pdo->prepare("INSERT INTO items(item_code,item_name,brand,supplier_name,size,item_type,category_id,unit,description,reorder_level,status) VALUES(?,?,'Quick Step','',?,'Finished Good',?,'pairs','Quick Step footwear demo inventory record',10,'Active')");
        $inventory = $pdo->prepare("INSERT INTO inventory(item_id,warehouse_id,quantity) VALUES(?,?,50) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)");
        $movement = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,unit,reference_number,remarks,date,user_id) SELECT ?,?,'STOCK IN',50,'pairs',?,'Opening demo stock by individual shoe size','2000-01-01',NULL WHERE NOT EXISTS (SELECT 1 FROM stock_movements WHERE reference_number=?)");
        foreach ($goods as $g) {
            $find->execute([$g[0]]);
            $baseId = (int)$find->fetchColumn();
            if (!$baseId) {
                $insert->execute([$g[0], $g[1], '26', $cat]);
                $baseId = (int)$pdo->lastInsertId();
            } else {
                $update->execute([$baseId]);
            }
            foreach ([26, ...range(27, 45)] as $size) {
                if ($size === 26) {
                    $id = $baseId;
                } else {
                    $code = $g[0] . '-S' . $size;
                    $find->execute([$code]);
                    $id = (int)$find->fetchColumn();
                    if (!$id) {
                        $insert->execute([$code, $g[1], (string)$size, $cat]);
                        $id = (int)$pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE items SET size=?, item_name=?, brand='Quick Step', item_type='Finished Good', status='Active' WHERE id=?")->execute([(string)$size, $g[1], $id]);
                    }
                }
                foreach ([$main, $secondary] as $wh) {
                    $inventory->execute([$id, $wh]);
                    $ref = 'OPENING-SIZE-' . $id . '-' . $wh;
                    $movement->execute([$id, $wh, $ref, $ref]);
                }
            }
        }
        $pdo->commit();
        mark_migration_done($pdo, 'finished_goods_individual_sizes_v1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Finished goods individual sizes setup: ' . $e->getMessage());
    }
}






function ensure_finished_goods_all_sizes(PDO $pdo)
{
    $pdo->beginTransaction();
    try {
        $cat = (int)$pdo->query("SELECT id FROM categories WHERE category_name='Footwear' LIMIT 1")->fetchColumn();
        $main = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code='WH-MAIN' LIMIT 1")->fetchColumn();
        $secondary = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code IN ('WH-SECONDARY','WH-SEC') ORDER BY CASE WHEN warehouse_code='WH-SECONDARY' THEN 0 ELSE 1 END LIMIT 1")->fetchColumn();
        if (!$cat || !$main || !$secondary) throw new Exception('Finished Goods setup requires Footwear category and both warehouses.');

        $goods = [
            ['FG-001', 'Quick Step Classic Low'],
            ['FG-002', 'Quick Step Street Runner'],
            ['FG-003', 'Quick Step Court Flex'],
            ['FG-004', 'Quick Step Urban Walk']
        ];
        $find = $pdo->prepare("SELECT id FROM items WHERE item_code=? AND item_type='Finished Good' LIMIT 1");
        $insert = $pdo->prepare("INSERT INTO items(item_code,item_name,brand,supplier_name,size,item_type,category_id,unit,description,reorder_level,status) VALUES(?,?,'Quick Step','',?,'Finished Good',?,'pairs','Sample demo inventory record by individual shoe size',10,'Active')");
        $update = $pdo->prepare("UPDATE items SET item_name=?,brand='Quick Step',size=?,item_type='Finished Good',category_id=?,unit='pairs',status='Active' WHERE id=?");
        $stock = $pdo->prepare("INSERT INTO inventory(item_id,warehouse_id,quantity) VALUES(?,?,50) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)");
        $movement = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,unit,reference_number,remarks,date,user_id) SELECT ?,?,'STOCK IN',50,'pairs',?,'Sample opening inventory by shoe size','2000-01-01',NULL WHERE NOT EXISTS (SELECT 1 FROM stock_movements WHERE reference_number=?)");

        foreach ($goods as $g) {
            foreach (range(26, 45) as $size) {
                $code = $size === 26 ? $g[0] : $g[0] . '-S' . $size;
                $find->execute([$code]);
                $id = (int)$find->fetchColumn();
                if ($id) {
                    $update->execute([$g[1], (string)$size, $cat, $id]);
                } else {
                    $insert->execute([$code, $g[1], (string)$size, $cat]);
                    $id = (int)$pdo->lastInsertId();
                }
                foreach ([$main, $secondary] as $wh) {
                    $stock->execute([$id, $wh]);
                    $ref = 'OPENING-SIZE-V2-' . $id . '-' . $wh;
                    $movement->execute([$id, $wh, $ref, $ref]);
                }
            }
        }
        $pdo->commit();
        mark_migration_done($pdo, 'finished_goods_all_sizes_v2');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Finished Goods all-size inventory setup: ' . $e->getMessage());
    }
}







function ensure_secondary_raw_material_stock_50(PDO $pdo)
{
    if (ensure_migration_done($pdo, 'secondary_raw_material_stock_50_v1')) return;
    $pdo->beginTransaction();
    try {
        $warehouseId = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code IN ('WH-SECONDARY','WH-SEC') ORDER BY CASE WHEN warehouse_code='WH-SECONDARY' THEN 0 ELSE 1 END LIMIT 1")->fetchColumn();
        if ($warehouseId <= 0) throw new Exception('Secondary Warehouse was not found.');

        $items = $pdo->query("SELECT id,unit FROM items WHERE item_type='Raw Material' ORDER BY id")->fetchAll();
        $balanceQuery = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN ('STOCK IN','STOCK TRANSFER IN') THEN quantity WHEN movement_type IN ('STOCK OUT','STOCK TRANSFER OUT') THEN -quantity WHEN movement_type='STOCK ADJUSTMENT' THEN quantity ELSE 0 END),0) FROM stock_movements WHERE item_id=? AND warehouse_id=?");
        $insert = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,unit,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,CURRENT_DATE(),NULL)");
        $setInventory = $pdo->prepare("INSERT INTO inventory(item_id,warehouse_id,quantity) VALUES(?,?,50) ON DUPLICATE KEY UPDATE quantity=50");

        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            $balanceQuery->execute([$itemId, $warehouseId]);
            $current = (int)$balanceQuery->fetchColumn();
            $difference = 50 - $current;
            if ($difference > 0) {
                $insert->execute([$itemId, $warehouseId, 'STOCK IN', $difference, $item['unit'], 'OPENING-SECONDARY-RM50-' . $itemId, 'Opening balance set to 50 in Secondary Warehouse']);
            } elseif ($difference < 0) {
                $insert->execute([$itemId, $warehouseId, 'STOCK ADJUSTMENT', $difference, $item['unit'], 'OPENING-SECONDARY-RM50-' . $itemId, 'Opening balance aligned to 50 in Secondary Warehouse']);
            }
            $setInventory->execute([$itemId, $warehouseId]);
        }
        mark_migration_done($pdo, 'secondary_raw_material_stock_50_v1');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Secondary raw-material opening stock: ' . $e->getMessage());
    }
}

function ensure_inventory_ledger_reconciliation(PDO $pdo)
{
    if (ensure_migration_done($pdo, 'inventory_ledger_reconciliation_v1')) return;
    $pdo->beginTransaction();
    try {


        $fgIds = $pdo->query("SELECT id FROM items WHERE item_type='Finished Good'")->fetchAll(PDO::FETCH_COLUMN);
        if ($fgIds) {
            $in = implode(',', array_map('intval', $fgIds));
            $pdo->exec("DELETE FROM stock_movements WHERE item_id IN ($in) AND date='2000-01-01' AND (reference_number LIKE 'OPENING-SIZE-%' OR reference_number LIKE 'OPENING-SIZE-V2-%' OR reference_number LIKE 'OPENING-CATALOG-%')");
            $main = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code='WH-MAIN' LIMIT 1")->fetchColumn();
            $secondary = (int)$pdo->query("SELECT id FROM warehouses WHERE warehouse_code IN ('WH-SECONDARY','WH-SEC') ORDER BY CASE WHEN warehouse_code='WH-SECONDARY' THEN 0 ELSE 1 END LIMIT 1")->fetchColumn();
            $ins = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,unit,reference_number,remarks,date,user_id) VALUES(?,?,'STOCK IN',50,'pairs',?,'Opening demo stock (single reconciled opening entry)','2000-01-01',NULL)");
            foreach ($fgIds as $iid) foreach ([$main, $secondary] as $wid) {
                if (!$wid) continue;
                $ref = 'OPENING-RESET-V3-' . $iid . '-' . $wid;
                $ins->execute([(int)$iid, $wid, $ref]);
            }
        }


        $q = $pdo->query("SELECT id,item_id,warehouse_id,quantity,unit,COALESCE(NULLIF(reference_number,''),stock_in_number) ref,remarks,date,user_id FROM stock_in");
        $exists = $pdo->prepare("SELECT id FROM stock_movements WHERE item_id=? AND warehouse_id=? AND movement_type='STOCK IN' AND reference_number=? AND quantity=? AND date=? LIMIT 1");
        $add = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,unit,reference_number,remarks,date,user_id) VALUES(?,?,'STOCK IN',?,?,?,?,?,?)");
        foreach ($q as $r) {
            $exists->execute([$r['item_id'], $r['warehouse_id'], $r['ref'], $r['quantity'], $r['date']]);
            if (!$exists->fetchColumn()) $add->execute([$r['item_id'], $r['warehouse_id'], $r['quantity'], $r['unit'], $r['ref'], $r['remarks'], $r['date'], $r['user_id']]);
        }


        $q = $pdo->query("SELECT id,item_id,warehouse_id,quantity,COALESCE(NULLIF(reference_number,''),stock_out_number) ref,remarks,date,user_id FROM stock_out");
        $exists = $pdo->prepare("SELECT id FROM stock_movements WHERE item_id=? AND warehouse_id=? AND movement_type='STOCK OUT' AND reference_number=? AND quantity=? AND date=? LIMIT 1");
        $add = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,reference_number,remarks,date,user_id) VALUES(?,?,'STOCK OUT',?,?,?,?,?)");
        foreach ($q as $r) {
            $exists->execute([$r['item_id'], $r['warehouse_id'], $r['ref'], $r['quantity'], $r['date']]);
            if (!$exists->fetchColumn()) $add->execute([$r['item_id'], $r['warehouse_id'], $r['quantity'], $r['ref'], $r['remarks'], $r['date'], $r['user_id']]);
        }


        $q = $pdo->query("SELECT item_id,warehouse_id,difference,adjustment_number,remarks,date,user_id FROM stock_adjustments");
        $exists = $pdo->prepare("SELECT id FROM stock_movements WHERE item_id=? AND warehouse_id=? AND movement_type='STOCK ADJUSTMENT' AND reference_number=? AND quantity=? AND date=? LIMIT 1");
        $add = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,reference_number,remarks,date,user_id) VALUES(?,?,'STOCK ADJUSTMENT',?,?,?,?,?)");
        foreach ($q as $r) {
            $delta = (int)$r['difference'];
            $exists->execute([$r['item_id'], $r['warehouse_id'], $r['adjustment_number'], $delta, $r['date']]);
            if (!$exists->fetchColumn() && $delta !== 0) $add->execute([$r['item_id'], $r['warehouse_id'], $delta, $r['adjustment_number'], $r['remarks'], $r['date'], $r['user_id']]);
        }



        $transfers = $pdo->query("SELECT id,transfer_number,item_id,from_warehouse,to_warehouse,quantity,remarks,date,user_id,status FROM stock_transfers WHERE status IN ('Delivered','Completed')")->fetchAll();
        $hasItems = $pdo->prepare("SELECT item_id,quantity FROM stock_transfer_items WHERE transfer_id=? ORDER BY id");
        $exists = $pdo->prepare("SELECT id FROM stock_movements WHERE item_id=? AND warehouse_id=? AND movement_type=? AND reference_number=? AND quantity=? AND date=? LIMIT 1");
        $add = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?)");
        foreach ($transfers as $tr) {
            $hasItems->execute([$tr['id']]);
            $lines = $hasItems->fetchAll();
            if (!$lines) $lines = [['item_id' => $tr['item_id'], 'quantity' => $tr['quantity']]];
            foreach ($lines as $line) {
                foreach ([[$tr['from_warehouse'], 'STOCK TRANSFER OUT'], [$tr['to_warehouse'], 'STOCK TRANSFER IN']] as [$wid, $kind]) {
                    $exists->execute([$line['item_id'], $wid, $kind, $tr['transfer_number'], $line['quantity'], $tr['date']]);
                    if (!$exists->fetchColumn()) $add->execute([$line['item_id'], $wid, $kind, $line['quantity'], $tr['transfer_number'], $tr['remarks'], $tr['date'], $tr['user_id']]);
                }
            }
        }


        $pdo->exec("INSERT INTO inventory(item_id,warehouse_id,quantity) SELECT i.id,w.id,0 FROM items i CROSS JOIN warehouses w ON DUPLICATE KEY UPDATE quantity=quantity");
        $pdo->exec("UPDATE inventory inv LEFT JOIN (SELECT item_id,warehouse_id,SUM(CASE WHEN movement_type IN ('STOCK IN','STOCK TRANSFER IN') THEN quantity WHEN movement_type IN ('STOCK OUT','STOCK TRANSFER OUT') THEN -quantity WHEN movement_type='STOCK ADJUSTMENT' THEN quantity ELSE 0 END) balance FROM stock_movements GROUP BY item_id,warehouse_id) ledger ON ledger.item_id=inv.item_id AND ledger.warehouse_id=inv.warehouse_id SET inv.quantity=COALESCE(ledger.balance,0)");
        mark_migration_done($pdo, 'inventory_ledger_reconciliation_v1');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Inventory ledger reconciliation: ' . $e->getMessage());
    }
}

function ensure_user_fields($pdo)
{
    static $ready = false;
    if ($ready) return;
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN position VARCHAR(120) NULL AFTER role");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE requests ADD COLUMN requester_team VARCHAR(50) NULL AFTER requested_by");
    } catch (PDOException $e) {
    }

    $map = [
        'Suficiencia Andrew C' => 'Warehouse Manager',
        'Riel Jurado' => 'Inventory Staff / Clerk',
        'Banisil, Ferdausi' => 'Warehouse Staff / Associate',
        'Paje Meynard' => 'Inventory Controller / Analyst',
        'Mendoza Franz Gian T.' => 'Picker and Packer',
        'Pompa, Eric Joseph F.' => 'Shipping and Receiving Clerk'
    ];
    foreach ($map as $name => $pos) {
        try {
            $q = $pdo->prepare("UPDATE users SET position=? WHERE full_name=? AND (position IS NULL OR position='')");
            $q->execute([$pos, $name]);
        } catch (PDOException $e) {
        }
    }
    $ready = true;
}

function user_position($pdo, $userId)
{
    $q = $pdo->prepare("SELECT full_name,username,email,COALESCE(position,'') position FROM users WHERE id=? LIMIT 1");
    $q->execute([(int)$userId]);
    $u = $q->fetch();
    if (!$u) return '';
    if (trim($u['position'] ?? '') !== '') return trim($u['position']);
    $src = strtolower(trim(($u['full_name'] ?? '') . ' ' . ($u['username'] ?? '') . ' ' . ($u['email'] ?? '')));
    if (strpos($src, 'suficiencia') !== false || strpos($src, 'andrew') !== false) return 'Warehouse Manager';
    if (strpos($src, 'paje') !== false || strpos($src, 'meynard') !== false) return 'Inventory Controller / Analyst';
    if (strpos($src, 'jurado') !== false || strpos($src, 'riel') !== false) return 'Inventory Staff / Clerk';
    if (strpos($src, 'banisil') !== false || strpos($src, 'ferdausi') !== false) return 'Warehouse Staff / Associate';
    if (strpos($src, 'mendoza') !== false || strpos($src, 'franz') !== false) return 'Picker and Packer';
    if (strpos($src, 'pompa') !== false || strpos($src, 'eric') !== false) return 'Shipping and Receiving Clerk';
    return '';
}
function can_control_session($pdo)
{
    require_login();
    $pos = user_position($pdo, $_SESSION['user_id'] ?? 0);
    if (in_array($pos, ['Warehouse Manager', 'Inventory Controller / Analyst'], true)) return true;

    if (strtoupper((string)($_SESSION['role'] ?? '')) === 'ADMIN' && strtolower((string)($_SESSION['full_name'] ?? '')) === 'admin') return true;
    return false;
}
function infer_user_team($pdo, $userId)
{
    $q = $pdo->prepare("SELECT full_name,username,email,COALESCE(position,'') position FROM users WHERE id=? LIMIT 1");
    $q->execute([(int)$userId]);
    $u = $q->fetch();
    $src = strtolower(trim(($u['full_name'] ?? '') . ' ' . ($u['username'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['position'] ?? '')));
    if (strpos($src, 'production') !== false) return 'Production';
    if (strpos($src, 'procurement') !== false) return 'Procurement';
    if (strpos($src, 'sales') !== false) return 'Sales';
    if (strpos($src, 'inventory') !== false || strpos($src, 'warehouse') !== false || strpos($src, 'suficiencia') !== false || strpos($src, 'jurado') !== false || strpos($src, 'banisil') !== false || strpos($src, 'paje') !== false || strpos($src, 'mendoza') !== false || strpos($src, 'pompa') !== false) return 'Inventory';
    return '';
}

function ensure_destination_choices($pdo)
{
    static $ready = false;
    if ($ready) return;

    try {
        $pdo->exec("ALTER TABLE requests MODIFY destination ENUM('Production','Sales','Procurement') NULL DEFAULT NULL");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE stock_out MODIFY destination ENUM('Sales','Production','Procurement') NULL DEFAULT NULL");
    } catch (PDOException $e) {
    }
    $ready = true;
}

function require_login()
{
    if (empty($_SESSION['user_id'])) {
        json_response(['success' => false, 'message' => 'Please log in first.'], 401);
    }
}

function require_admin()
{
    if (!can_control_session($GLOBALS['pdo'])) {
        json_response(['success' => false, 'message' => 'Only the Warehouse Manager and Inventory Controller / Analyst can control this system.'], 403);
    }
}
function ensure_notification_tables($pdo)
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        message VARCHAR(500) NOT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'transaction',
        actor_user_id INT NULL,
        recipient_user_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notifications_created (created_at),
        CONSTRAINT fk_notification_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    try {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN recipient_user_id INT NULL AFTER actor_user_id");
    } catch (PDOException $e) {
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_reads (
        notification_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (notification_id,user_id),
        CONSTRAINT fk_notification_read_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
        CONSTRAINT fk_notification_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $ready = true;
}
function create_notification($pdo, $title, $message, $type = 'transaction', $actor_user_id = null, $recipient_user_id = null)
{
    $s = $pdo->prepare("INSERT INTO notifications(title,message,type,actor_user_id,recipient_user_id) VALUES(?,?,?,?,?)");
    $s->execute([$title, $message, $type, $actor_user_id, $recipient_user_id]);
}
function create_notification_for_admins($pdo, $title, $message, $type = 'transaction', $actor_user_id = null)
{
    $s = $pdo->query("SELECT id FROM users WHERE role='ADMIN'");
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        if ((int)$adminId === (int)$actor_user_id) continue;
        create_notification($pdo, $title, $message, $type, $actor_user_id, (int)$adminId);
    }
}
function create_notification_for_user($pdo, $title, $message, $type, $actor_user_id, $recipient_user_id)
{
    $recipient_user_id = (int)$recipient_user_id;
    if ($recipient_user_id <= 0 || $recipient_user_id === (int)$actor_user_id) return;
    create_notification($pdo, $title, $message, $type, $actor_user_id, $recipient_user_id);
}
function create_notification_for_team($pdo, $team, $title, $message, $type = 'transaction', $actor_user_id = null)
{
    $team = trim((string)$team);
    if ($team === '') return;
    $like = '%' . strtolower($team) . '%';
    $s = $pdo->prepare("SELECT id FROM users WHERE LOWER(CONCAT_WS(' ',full_name,username,email)) LIKE ?");
    $s->execute([$like]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ((int)$uid === (int)$actor_user_id) continue;
        create_notification($pdo, $title, $message, $type, $actor_user_id, (int)$uid);
    }
}
function create_notification_for_email($pdo, $email, $title, $message, $type = 'transaction', $actor_user_id = null)
{
    $email = trim((string)$email);
    if ($email === '') return;
    $s = $pdo->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 20");
    $s->execute([$email]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ((int)$uid === (int)$actor_user_id) continue;
        create_notification($pdo, $title, $message, $type, $actor_user_id, (int)$uid);
    }
}
function notify_request_participants($pdo, $request, $title, $message, $type = 'request', $actor_user_id = null)
{
    create_notification_for_user($pdo, $title, $message, $type, $actor_user_id, (int)($request['requested_by'] ?? 0));
    create_notification_for_email($pdo, $request['recipient_gmail'] ?? '', $title, $message, $type, $actor_user_id);
    create_notification_for_team($pdo, $request['destination'] ?? '', $title, $message, $type, $actor_user_id);
    create_notification_for_admins($pdo, $title, $message, $type, $actor_user_id);
}


function ensure_rejection_tables($pdo)
{
    static $ready = false;
    if ($ready) return;
    $ready = true;

    foreach (
        [
            "ALTER TABLE request_items ADD COLUMN inspection_accepted_quantity INT NOT NULL DEFAULT 0 AFTER accepted_quantity",
            "ALTER TABLE request_items ADD COLUMN inspection_rejected_quantity INT NOT NULL DEFAULT 0 AFTER inspection_accepted_quantity",
            "ALTER TABLE request_items ADD COLUMN inspection_rejection_reason VARCHAR(500) DEFAULT '' AFTER inspection_rejected_quantity",
            "ALTER TABLE requests ADD COLUMN po_number VARCHAR(100) NOT NULL DEFAULT '' AFTER destination"
        ] as $sql
    ) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_rejections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        delivery_id INT NOT NULL,
        report_number VARCHAR(40) DEFAULT NULL,
        po_number VARCHAR(100) NOT NULL DEFAULT '',
        supplier_name VARCHAR(150) NOT NULL DEFAULT '',
        supplier_gmail VARCHAR(190) NOT NULL DEFAULT '',
        received_date DATE NOT NULL,
        attachment_names TEXT,
        report_status ENUM('Pending','Email Sent','Supplier Response') NOT NULL DEFAULT 'Pending',
        supplier_response TEXT NULL,
        email_sent_at DATETIME NULL,
        supplier_response_at DATETIME NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_supplier_rejection_delivery (delivery_id),
        UNIQUE KEY uq_supplier_rejection_report (report_number),
        CONSTRAINT fk_supplier_rejection_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        CONSTRAINT fk_supplier_rejection_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
        CONSTRAINT fk_supplier_rejection_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_rejection_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rejection_id INT NOT NULL,
        request_item_id INT NULL,
        item_name VARCHAR(150) NOT NULL,
        ordered_quantity INT NOT NULL DEFAULT 0,
        delivered_quantity INT NOT NULL DEFAULT 0,
        accepted_quantity INT NOT NULL DEFAULT 0,
        rejected_quantity INT NOT NULL DEFAULT 0,
        unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
        rejection_reason VARCHAR(500) DEFAULT '',
        CONSTRAINT fk_sri_rejection FOREIGN KEY (rejection_id) REFERENCES supplier_rejections(id) ON DELETE CASCADE,
        CONSTRAINT fk_sri_request_item FOREIGN KEY (request_item_id) REFERENCES request_items(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
}
