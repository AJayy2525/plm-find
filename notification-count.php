<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = current_user($db);

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required']);
    exit;
}

if (($user['role'] ?? '') === 'admin') {

    // Student acknowledgments awaiting onsite return.
    $stmt = $db->query(
        "SELECT COUNT(*)
         FROM recovery_notices
         WHERE status = 'acknowledged'"
    );

    $count = (int) $stmt->fetchColumn();

} else {

    // Possible-match notices that this student
    // has not acknowledged.
    $stmt = $db->prepare(
        "SELECT COUNT(*)
         FROM recovery_notices n
         JOIN items i ON i.id = n.lost_item_id
         WHERE i.user_id = ?
           AND n.status = 'notified'"
    );

    $stmt->execute([(int) $user['id']]);

    $count = (int) $stmt->fetchColumn();
}

echo json_encode(
    ['count' => $count],
    JSON_THROW_ON_ERROR
);
