<?php
session_start();
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isset($_GET['session_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session ID required']);
    exit();
}

$session_id = intval($_GET['session_id']);

try {
    // Get session details
    $sql = "
        SELECT 
            ls.*,
            c.class_name,
            c.class_code,
            c.emotion_tracking,
            c.auto_attendance,
            u.full_name as instructor_name,
            u.email as instructor_email,
            COUNT(DISTINCT lsp.user_id) as participant_count,
            COUNT(DISTINCT sa.student_id) as attendance_count,
            COALESCE(AVG(ses.engagement_score), 0) as avg_engagement,
            TIMESTAMPDIFF(MINUTE, ls.start_time, NOW()) as duration_minutes
        FROM live_sessions ls
        JOIN classes c ON ls.class_id = c.id
        JOIN users u ON c.instructor_id = u.id
        LEFT JOIN live_session_participants lsp ON ls.id = lsp.session_id AND lsp.is_active = 1
        LEFT JOIN session_attendance sa ON ls.id = sa.session_id
        LEFT JOIN session_engagement_summary ses ON ls.id = ses.session_id
        WHERE ls.id = :session_id
        GROUP BY ls.id, c.class_name, c.class_code, c.emotion_tracking, c.auto_attendance, u.full_name, u.email
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':session_id' => $session_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        // Calculate attendance rate
        $total_students = 0;
        $sql = "SELECT COUNT(*) as total FROM class_enrollments WHERE class_id = :class_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':class_id' => $session['class_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_students = $result['total'] ?? 0;
        
        $attendance_rate = $total_students > 0 ? round(($session['attendance_count'] / $total_students) * 100) : 0;
        
        // Get detection rate
        $sql = "SELECT COUNT(DISTINCT student_id) as count FROM session_engagement_summary WHERE session_id = :session_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':session_id' => $session_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $detections = $result['count'] ?? 0;
        
        $detection_rate = $session['attendance_count'] > 0 ? round(($detections / $session['attendance_count']) * 100) : 0;
        
        // Format duration
        $hours = floor($session['duration_minutes'] / 60);
        $minutes = $session['duration_minutes'] % 60;
        $duration = ($hours > 0 ? $hours . 'h ' : '') . $minutes . 'm';
        
        $session['duration'] = $duration;
        $session['attendance_rate'] = $attendance_rate;
        $session['detection_rate'] = $detection_rate;
        
        echo json_encode(['success' => true, 'session' => $session]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Session not found']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>