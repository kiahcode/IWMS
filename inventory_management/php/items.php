<?php
require_once "database.php";
require_login();
$action = $_REQUEST["action"] ?? "list";
if ($action === "categories") {
    $rows = $pdo->query("SELECT id,category_name FROM categories ORDER BY category_name")->fetchAll();
    json_response(["success" => true, "categories" => $rows]);
}
if ($action === "list") {
    $sql = "SELECT i.*,c.category_name,COALESCE(SUM(inv.quantity),0) current_stock,GROUP_CONCAT(DISTINCT w.warehouse_name ORDER BY w.warehouse_name SEPARATOR ', ') warehouse_name FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN inventory inv ON inv.item_id=i.id LEFT JOIN warehouses w ON w.id=inv.warehouse_id WHERE 1=1";
    $params = [];
    if (!empty($_GET["type"])) {
        $sql .= " AND i.item_type=?";
        $params[] = $_GET["type"];
    }
    if (!empty($_GET["category_id"])) {
        $sql .= " AND i.category_id=?";
        $params[] = (int)$_GET["category_id"];
    }
    if (!empty($_GET["search"])) {
        $q = "%" . $_GET["search"] . "%";
        $sql .= " AND (i.item_code LIKE ? OR i.item_name LIKE ? OR i.brand LIKE ? OR i.supplier_name LIKE ? OR c.category_name LIKE ?)";
        $params = [$q, $q, $q, $q, $q, ...$params];
    }
    $sql .= " GROUP BY i.id ORDER BY i.created_at DESC, i.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(["success" => true, "items" => $stmt->fetchAll()]);
}
if ($action === "create" || $action === "update") {
    require_admin();
    $id = (int)post_value("id");
    $code = post_value("item_code");
    $name = post_value("item_name");
    $brand = post_value("brand");
    $supplier = post_value("supplier_name");
    $size = post_value("size");
    $type = post_value("item_type");
    $cat = (int)post_value("category_id");
    $unit = post_value("unit");
    $desc = post_value("description");
    $reorder = (float)post_value("reorder_level", 0);
    $status = post_value("status", "Active");
    if ($type === "Raw Material") $size = "";
    if ($code === "" || $name === "" || $type === "" || $cat <= 0 || $unit === "") json_response(["success" => false, "message" => "Please complete the required item fields."], 422);
    try {
        if ($action === "create") {
            $s = $pdo->prepare("INSERT INTO items(item_code,item_name,brand,supplier_name,size,item_type,category_id,unit,description,reorder_level,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$code, $name, $brand, $supplier, $size, $type, $cat, $unit, $desc, $reorder, $status]);
            create_notification($pdo, "Item Added", "A new inventory item was added.", "item", $_SESSION["user_id"]);
            json_response(["success" => true, "message" => "Item added successfully."]);
        }
        $s = $pdo->prepare("UPDATE items SET item_code=?,item_name=?,brand=?,supplier_name=?,size=?,item_type=?,category_id=?,unit=?,description=?,reorder_level=?,status=? WHERE id=?");
        $s->execute([$code, $name, $brand, $supplier, $size, $type, $cat, $unit, $desc, $reorder, $status, $id]);
        json_response(["success" => true, "message" => "Item updated successfully."]);
    } catch (PDOException $e) {
        json_response(["success" => false, "message" => "Item code may already exist."], 409);
    }
}
if ($action === "delete") {
    require_admin();
    $id = (int)post_value("id");
    $s = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE item_id=? AND quantity<>0");
    $s->execute([$id]);
    if ($s->fetchColumn() > 0) json_response(["success" => false, "message" => "Cannot delete an item with existing stock."], 409);
    try {
        $s = $pdo->prepare("DELETE FROM items WHERE id=?");
        $s->execute([$id]);
        json_response(["success" => true, "message" => "Item deleted successfully."]);
    } catch (PDOException $e) {
        json_response(["success" => false, "message" => "Item cannot be deleted because it has transaction history."], 409);
    }
}
json_response(["success" => false, "message" => "Invalid item action."], 400);
