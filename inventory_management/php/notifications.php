<?php
require_once "database.php";
require_login();
ensure_notification_tables($pdo);
$action = $_REQUEST["action"] ?? "list";
$userId = (int)$_SESSION["user_id"];
if ($action === "list") {
    $limit = max(1, min(30, (int)($_REQUEST["limit"] ?? 15)));
    $s = $pdo->prepare("SELECT n.id,n.title,n.message,n.type,n.actor_user_id,n.recipient_user_id,n.created_at,CASE WHEN nr.notification_id IS NULL THEN 0 ELSE 1 END is_read
        FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=? WHERE (n.recipient_user_id IS NULL OR n.recipient_user_id=?)
        ORDER BY n.id DESC LIMIT $limit");
    $s->execute([$userId, $userId]);
    $rows = $s->fetchAll();
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=? WHERE (n.recipient_user_id IS NULL OR n.recipient_user_id=?) AND nr.notification_id IS NULL");
    $s->execute([$userId, $userId]);
    $unread = (int)$s->fetchColumn();
    json_response(["success" => true, "unread" => $unread, "rows" => $rows]);
}
if ($action === "read") {
    $id = (int)post_value("id");
    if ($id > 0) {
        $s = $pdo->prepare("INSERT IGNORE INTO notification_reads(notification_id,user_id) VALUES(?,?)");
        $s->execute([$id, $userId]);
    }
    json_response(["success" => true]);
}
if ($action === "read_all") {
    $pdo->prepare("INSERT IGNORE INTO notification_reads(notification_id,user_id) SELECT n.id,? FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=? WHERE (n.recipient_user_id IS NULL OR n.recipient_user_id=?) AND nr.notification_id IS NULL")->execute([$userId, $userId, $userId]);
    json_response(["success" => true]);
}
json_response(["success" => false, "message" => "Invalid notification action."], 400);
