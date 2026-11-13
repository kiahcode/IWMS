<?php
require_once "_bootstrap.php";
api_method(["GET"]);
$team = api_auth();

$userQuery = $pdo->query("SELECT id FROM users");
$userIds = [];

foreach ($userQuery->fetchAll(PDO::FETCH_COLUMN) as $userId) {
    if (infer_user_team($pdo, (int)$userId) === $team) {
        $userIds[] = (int)$userId;
    }
}

if (!$userIds) {
    api_response(true, "No user notifications were found for this team.", [
        "destination" => $team,
        "notifications" => []
    ]);
}

$placeholders = implode(",", array_fill(0, count($userIds), "?"));
$sql = "SELECT id, title, message, type, created_at
        FROM notifications
        WHERE recipient_user_id IN ($placeholders)
        ORDER BY created_at DESC
        LIMIT 100";
$query = $pdo->prepare($sql);
$query->execute($userIds);

api_response(true, "Notifications retrieved successfully.", [
    "destination" => $team,
    "notifications" => $query->fetchAll()
]);
?>
