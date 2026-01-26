<?php
// get_class_data.php
require_once 'config.php';
requireInstructor();

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $class_id = (int)$_GET['id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM " . TABLE_CLASSES . " WHERE id = ? AND instructor_id = ?");
        $stmt->execute([$class_id, $user_id]);
        $class = $stmt->fetch();
        
        if ($class) {
            echo json_encode(['success' => true] + $class);
        } else {
            echo json_encode(['success' => false, 'message' => 'Class not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No ID provided']);
}
?>