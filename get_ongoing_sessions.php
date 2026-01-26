<?php
require_once 'config.php';
requireInstructor();

$userId = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT ls.id, TIMESTAMPDIFF(SECOND, ls.start_time, NOW()) as duration
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        WHERE c.instructor_id = ? AND ls.status = 'active'
    ");
    $stmt->execute([$userId]);
    $sessions = $stmt->fetchAll();
    
    $result = [];
    foreach ($sessions as $session) {
        $result[$session['id']] = $session;
    }
    
    header('Content-Type: application/json');
    echo json_encode($result);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>