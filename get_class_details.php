<?php
require_once 'config.php';
requireInstructor();

$classId = $_GET['id'] ?? 0;
$userId = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT class_name, class_code, description, emotion_tracking, auto_attendance 
        FROM " . TABLE_CLASSES . " 
        WHERE id = ? AND instructor_id = ?
    ");
    $stmt->execute([$classId, $userId]);
    $class = $stmt->fetch();
    
    if ($class) {
        header('Content-Type: application/json');
        echo json_encode($class);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Class not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>