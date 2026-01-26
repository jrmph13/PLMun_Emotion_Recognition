<?php
require_once 'config.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_old_logs') {
    try {
        // Clear logs older than 90 days
        $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $stmt->execute();
        $deleted_count = $stmt->rowCount();
        
        // Log this action
        logAuditTrail(
            $_SESSION['user_id'],
            $_SESSION['role'],
            $_SESSION['username'],
            'delete',
            "Cleared {$deleted_count} old audit logs",
            'audit_logs',
            null,
            ['deleted_count' => $deleted_count]
        );
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully cleared {$deleted_count} old audit logs"
        ]);
    } catch (PDOException $e) {
        error_log("Clear audit logs error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
}
?>