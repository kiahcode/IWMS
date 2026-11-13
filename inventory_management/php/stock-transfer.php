<?php
require_once "database.php";
require_login();

$action = $_REQUEST["action"] ?? "list";



try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfer_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_id INT NOT NULL,
        item_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        CONSTRAINT fk_transfer_items_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
        CONSTRAINT fk_transfer_items_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
} catch (PDOException $e) {
}


if ($action === "availability") {
    $item = (int)($_GET["item_id"] ?? 0);
    $warehouse = (int)($_GET["warehouse_id"] ?? 0);
    if ($item <= 0 || $warehouse <= 0) json_response(["success" => false, "message" => "Item and warehouse are required."], 422);
    $s = $pdo->prepare("SELECT COALESCE(quantity,0) quantity FROM inventory WHERE item_id=? AND warehouse_id=?");
    $s->execute([$item, $warehouse]);
    $row = $s->fetch();
    json_response(["success" => true, "quantity" => (float)($row["quantity"] ?? 0)]);
}

function transfer_items_for(PDO $pdo, int $transferId): array
{
    $s = $pdo->prepare("SELECT sti.id,sti.item_id,sti.quantity,i.item_code,i.item_name,i.item_type,i.size,i.unit,COALESCE(inv.quantity,0) available_quantity
        FROM stock_transfer_items sti
        JOIN items i ON i.id=sti.item_id
        LEFT JOIN inventory inv ON inv.item_id=sti.item_id AND inv.warehouse_id=(SELECT from_warehouse FROM stock_transfers WHERE id=sti.transfer_id)
        WHERE sti.transfer_id=? ORDER BY sti.id");
    $s->execute([$transferId]);
    return $s->fetchAll();
}

function legacy_or_child_items(PDO $pdo, array $row): array
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id=?");
    $s->execute([$row['id']]);
    if ((int)$s->fetchColumn() > 0) return transfer_items_for($pdo, (int)$row['id']);
    if (empty($row['item_id'])) return [];
    $s = $pdo->prepare("SELECT i.id item_id,i.item_code,i.item_name,i.item_type,i.size,i.unit,st.quantity,COALESCE(inv.quantity,0) available_quantity
        FROM items i JOIN stock_transfers st ON st.item_id=i.id
        LEFT JOIN inventory inv ON inv.item_id=st.item_id AND inv.warehouse_id=st.from_warehouse WHERE st.id=?");
    $s->execute([$row['id']]);
    return $s->fetchAll();
}

function transfer_rows(PDO $pdo): array
{
    $q = $pdo->query("SELECT st.*, f.warehouse_code from_warehouse_code,f.warehouse_name from_warehouse_name,
        t.warehouse_code to_warehouse_code,t.warehouse_name to_warehouse_name,u.username
        FROM stock_transfers st
        JOIN warehouses f ON f.id=st.from_warehouse
        JOIN warehouses t ON t.id=st.to_warehouse
        LEFT JOIN users u ON u.id=st.user_id
        ORDER BY st.date DESC,st.id DESC");
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $r['items'] = legacy_or_child_items($pdo, $r);
        $r['item_count'] = count($r['items']);
        $out[] = $r;
    }
    return $out;
}

if ($action === "list") json_response(["success" => true, "rows" => transfer_rows($pdo)]);

if (in_array($action, ["create", "update", "delete"], true)) {
    require_admin();
    try {
        $pdo->beginTransaction();
        $id = (int)post_value("id");

        if ($action !== "create") {
            $s = $pdo->prepare("SELECT * FROM stock_transfers WHERE id=? FOR UPDATE");
            $s->execute([$id]);
            $old = $s->fetch();
            if (!$old) throw new Exception("Transfer record not found.");


            if (in_array($old['status'], ['Completed', 'Delivered'], true)) {
                $oldItems = legacy_or_child_items($pdo, $old);
                foreach ($oldItems as $it) {
                    $qty = (float)$it['quantity'];
                    $pdo->prepare("UPDATE inventory SET quantity=quantity+? WHERE item_id=? AND warehouse_id=?")->execute([$qty, $it['item_id'], $old['from_warehouse']]);
                    $pdo->prepare("UPDATE inventory SET quantity=quantity-? WHERE item_id=? AND warehouse_id=?")->execute([$qty, $it['item_id'], $old['to_warehouse']]);
                }
                $pdo->prepare("DELETE FROM stock_movements WHERE reference_number=? AND movement_type IN ('STOCK TRANSFER IN','STOCK TRANSFER OUT')")->execute([$old['transfer_number']]);
            }
            if ($action === "delete") {
                $pdo->prepare("DELETE FROM stock_transfers WHERE id=?")->execute([$id]);
                $pdo->commit();
                json_response(["success" => true, "message" => "Transfer deleted successfully."]);
            }
            $pdo->prepare("DELETE FROM stock_transfer_items WHERE transfer_id=?")->execute([$id]);
        }

        $num = trim((string)post_value("transfer_number"));
        $date = post_value("date");
        $from = (int)post_value("from_warehouse");
        $to = (int)post_value("to_warehouse");
        $reason = post_value("reason");
        $remarks = post_value("remarks");
        $status = "Delivered";
        $rawItems = $_POST['items'] ?? '[]';
        $items = json_decode($rawItems, true);
        if (!is_array($items)) throw new Exception("Invalid transfer items.");
        $clean = [];
        $seen = [];
        foreach ($items as $it) {
            $itemId = (int)($it['item_id'] ?? 0);
            $qty = (float)($it['quantity'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) continue;
            if (isset($seen[$itemId])) $seen[$itemId] += $qty;
            else $seen[$itemId] = $qty;
        }
        foreach ($seen as $itemId => $qty) $clean[] = ['item_id' => $itemId, 'quantity' => $qty];

        if ($num === "" || $date === "" || $from <= 0 || $to <= 0 || !$clean) throw new Exception("Please complete the transfer number, date, warehouses, and at least one item.");
        if ($from === $to) throw new Exception("Source and destination warehouse cannot be the same.");


        if ($action === "create") {
            $first = $clean[0];
            $s = $pdo->prepare("INSERT INTO stock_transfers(transfer_number,item_id,from_warehouse,to_warehouse,quantity,reason,remarks,date,user_id,status) VALUES(?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$num, $first['item_id'], $from, $to, $first['quantity'], $reason, $remarks, $date, $_SESSION['user_id'], $status]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $first = $clean[0];
            $s = $pdo->prepare("UPDATE stock_transfers SET transfer_number=?,item_id=?,from_warehouse=?,to_warehouse=?,quantity=?,reason=?,remarks=?,date=?,status=? WHERE id=?");
            $s->execute([$num, $first['item_id'], $from, $to, $first['quantity'], $reason, $remarks, $date, $status, $id]);
        }

        $ins = $pdo->prepare("INSERT INTO stock_transfer_items(transfer_id,item_id,quantity) VALUES(?,?,?)");
        foreach ($clean as $it) {
            $ins->execute([$id, $it['item_id'], $it['quantity']]);
        }

        if ($status === 'Delivered') {
            foreach ($clean as $it) {
                $itemId = $it['item_id'];
                $qty = $it['quantity'];
                $s = $pdo->prepare("SELECT quantity FROM inventory WHERE item_id=? AND warehouse_id=? FOR UPDATE");
                $s->execute([$itemId, $from]);
                $inv = $s->fetch();
                if (!$inv || (float)$inv['quantity'] < $qty) throw new Exception("Insufficient stock for item ID {$itemId} in the selected source warehouse.");
            }
            foreach ($clean as $it) {
                $itemId = $it['item_id'];
                $qty = $it['quantity'];
                $pdo->prepare("UPDATE inventory SET quantity=quantity-? WHERE item_id=? AND warehouse_id=?")->execute([$qty, $itemId, $from]);
                $pdo->prepare("INSERT INTO inventory(item_id,warehouse_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)")->execute([$itemId, $to, $qty]);
                $m = $pdo->prepare("INSERT INTO stock_movements(item_id,warehouse_id,movement_type,quantity,reference_number,remarks,date,user_id) VALUES(?,?,?,?,?,?,?,?)");
                $m->execute([$itemId, $from, 'STOCK TRANSFER OUT', $qty, $num, $remarks, $date, $_SESSION['user_id']]);
                $m->execute([$itemId, $to, 'STOCK TRANSFER IN', $qty, $num, $remarks, $date, $_SESSION['user_id']]);
            }
        }
        create_notification($pdo, "Stock Transfer", $action === 'create' ? "A stock transfer with " . count($clean) . " item(s) was created." : "A stock transfer was updated.", "stock-transfer", $_SESSION['user_id']);
        $pdo->commit();
        json_response(["success" => true, "message" => $action === 'create' ? "Stock transfer saved successfully." : "Stock transfer updated successfully."]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(["success" => false, "message" => $e->getMessage()], 422);
    }
}
json_response(["success" => false, "message" => "Invalid action."], 400);
