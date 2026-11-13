<?php
require_once "database.php";
$username = post_value("username");
$password = post_value("password");
if ($username === "" || $password === "") json_response(["success" => false, "message" => "Username and password are required."], 422);
$stmt = $pdo->prepare("SELECT id,full_name,email,username,password,role,COALESCE(position,'') position FROM users WHERE username=? OR email=? LIMIT 1");
$stmt->execute([$username, $username]);
$user = $stmt->fetch();
if (!$user || !password_verify($password, $user["password"])) json_response(["success" => false, "message" => "Invalid username or password."], 401);
session_regenerate_id(true);
$_SESSION["user_id"] = $user["id"];
$_SESSION["full_name"] = $user["full_name"];
$_SESSION["username"] = $user["username"];
$_SESSION["role"] = $user["role"];
$_SESSION["position"] = $user["position"] ?? user_position($pdo, $user["id"]);
$_SESSION["can_control"] = in_array($_SESSION["position"], ["Warehouse Manager", "Inventory Controller / Analyst"], true) || (strtoupper($_SESSION["role"]) === "ADMIN" && strtolower($_SESSION["full_name"]) === "admin");
json_response(["success" => true, "message" => "Login successful.", "user" => ["id" => $user["id"], "full_name" => $user["full_name"], "username" => $user["username"], "role" => $user["role"], "position" => $_SESSION["position"], "can_control" => $_SESSION["can_control"]]]);
