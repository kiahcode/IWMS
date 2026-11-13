<?php
require_once "database.php";
if (!isset($_SESSION["user_id"])) json_response(["logged_in" => false]);
$position = user_position($pdo, $_SESSION["user_id"]);
$canControl = in_array($position, ["Warehouse Manager", "Inventory Controller / Analyst"], true) || (strtoupper((string)($_SESSION["role"] ?? "")) === "ADMIN" && strtolower((string)($_SESSION["full_name"] ?? "")) === "admin");
$_SESSION["position"] = $position;
$_SESSION["can_control"] = $canControl;
json_response(["logged_in" => true, "user" => ["id" => $_SESSION["user_id"], "full_name" => $_SESSION["full_name"], "username" => $_SESSION["username"], "role" => $_SESSION["role"], "position" => $position, "can_control" => $canControl]]);
