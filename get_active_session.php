<?php
require_once 'config.php';
requireInstructor();

$classId = $_GET['class_id'] ?? 0;
$userId = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT ls.id as session_id, ls.session_name
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        WHERE ls.class_id = ? AND c.instructor_id = ? AND ls.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$classId, $userId]);
    $session = $stmt->fetch();
    
    header('Content-Type: application/json');
    if ($session) {
        echo json_encode($session);
    } else {
        echo json_encode(['error' => 'No active session']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>