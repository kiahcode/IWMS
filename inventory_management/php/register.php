<?php
require_once "database.php";
$full = post_value("full_name");
$email = post_value("email");
$username = post_value("username");
$password = post_value("password");
$confirm = post_value("confirm_password");
if ($full === "" || $email === "" || $username === "" || $password === "") json_response(["success" => false, "message" => "All required fields must be filled."], 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(["success" => false, "message" => "Please enter a valid email."], 422);
if (strlen($password) < 6) json_response(["success" => false, "message" => "Password must be at least 6 characters."], 422);
if ($password !== $confirm) json_response(["success" => false, "message" => "Passwords do not match."], 422);
$stmt = $pdo->prepare("SELECT id FROM users WHERE email=? OR username=? LIMIT 1");
$stmt->execute([$email, $username]);
if ($stmt->fetch()) json_response(["success" => false, "message" => "Email or username already exists."], 409);
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users(full_name,email,username,password,role) VALUES(?,?,?,?,?)");
$stmt->execute([$full, $email, $username, $hash, "USER"]);
json_response(["success" => true, "message" => "Account created successfully."]);
