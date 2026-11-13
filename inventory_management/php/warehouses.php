<?php
require_once "database.php";
require_login();

$action = $_REQUEST["action"] ?? "list";
if ($action === "list") {
    $sql = "SELECT w.*,COALESCE(SUM(i.quantity),0) total_stock FROM warehouses w LEFT JOIN inventory i ON i.warehouse_id=w.id WHERE 1=1";
    $p = [];
    if (!empty($_GET["search"])) {
        $sql .= " AND (w.warehouse_code LIKE ? OR w.warehouse_name LIKE ? OR w.location LIKE ?)";
        $q = "%" . $_GET["search"] . "%";
        $p = [$q, $q, $q];
    }
    $sql .= " GROUP BY w.id ORDER BY w.warehouse_name";
    $s = $pdo->prepare($sql);
    $s->execute($p);
    json_response(["success" => true, "warehouses" => $s->fetchAll()]);
}

if ($action === "summary") {
    $id = (int)($_GET["warehouse_id"] ?? 0);
    if ($id <= 0) json_response(["success" => false, "message" => "Warehouse is required."], 422);
    $s = $pdo->prepare("SELECT id,warehouse_code,warehouse_name,location,manager,status FROM warehouses WHERE id=?");
    $s->execute([$id]);
    $warehouse = $s->fetch();
    if (!$warehouse) json_response(["success" => false, "message" => "Warehouse not found."], 404);
    $s = $pdo->prepare("SELECT COUNT(DISTINCT item_id) item_count,COALESCE(SUM(quantity),0) current_stock FROM inventory WHERE warehouse_id=?");
    $s->execute([$id]);
    $summary = $s->fetch();
    $s = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) total_stock_in FROM stock_in WHERE warehouse_id=?");
    $s->execute([$id]);
    $summary = array_merge($summary, $s->fetch());
    $s = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) total_stock_out FROM stock_out WHERE warehouse_id=?");
    $s->execute([$id]);
    $summary = array_merge($summary, $s->fetch());
    $s = $pdo->prepare("SELECT i.item_code,i.item_name,i.item_type,i.size,i.brand,c.category_name,i.unit,i.reorder_level,i.status,inv.quantity current_stock FROM inventory inv JOIN items i ON i.id=inv.item_id LEFT JOIN categories c ON c.id=i.category_id WHERE inv.warehouse_id=? ORDER BY i.created_at DESC,i.id DESC");
    $s->execute([$id]);
    json_response(["success" => true, "warehouse" => $warehouse, "summary" => $summary, "items" => $s->fetchAll()]);
}
if ($action === "create" || $action === "update") {
    require_admin();
    $id = (int)post_value("id");
    $code = post_value("warehouse_code");
    $name = post_value("warehouse_name");
    $loc = post_value("location");
    $mgr = post_value("manager");
    $contact = post_value("contact_number");
    $status = post_value("status", "Active");
    if ($code === "" || $name === "") json_response(["success" => false, "message" => "Warehouse code and name are required."], 422);
    try {
        if ($action === "create") {
            $s = $pdo->prepare("INSERT INTO warehouses(warehouse_code,warehouse_name,location,manager,contact_number,status) VALUES(?,?,?,?,?,?)");
            $s->execute([$code, $name, $loc, $mgr, $contact, $status]);
        } else {
            $s = $pdo->prepare("UPDATE warehouses SET warehouse_code=?,warehouse_name=?,location=?,manager=?,contact_number=?,status=? WHERE id=?");
            $s->execute([$code, $name, $loc, $mgr, $contact, $status, $id]);
        }
        create_notification($pdo, "Warehouse Update", $action === "create" ? "A new warehouse was added." : "A warehouse was updated.", "warehouse", $_SESSION["user_id"]);
        json_response(["success" => true, "message" => $action === "create" ? "Warehouse added successfully." : "Warehouse updated successfully."]);
    } catch (PDOException $e) {
        json_response(["success" => false, "message" => "Warehouse code may already exist or the record is in use."], 409);
    }
}
if ($action === "delete") {
    require_admin();
    try {
        $s = $pdo->prepare("DELETE FROM warehouses WHERE id=?");
        $s->execute([(int)post_value("id")]);
        json_response(["success" => true, "message" => "Warehouse deleted successfully."]);
    } catch (PDOException $e) {
        json_response(["success" => false, "message" => "Cannot delete a warehouse used by inventory or transactions."], 409);
    }
}
json_response(["success" => false, "message" => "Invalid warehouse action."], 400);
