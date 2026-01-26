<?php
// student_profile.php
require_once 'config.php';
requireInstructor();

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($student_id <= 0) {
    header('Location: teacher_students.php');
    exit();
}

// Get student details
try {
    $stmt = $pdo->prepare("
        SELECT 
            s.*, 
            u.*,
            COUNT(DISTINCT ce.class_id) as total_classes,
            COUNT(DISTINCT sa.session_id) as total_sessions,
            AVG(ses.engagement_score) as avg_engagement,
            MAX(sa.join_time) as last_attendance
        FROM " . TABLE_STUDENTS . " s
        JOIN " . TABLE_USERS . " u ON s.user_id = u.id
        LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
        LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON s.id = sa.student_id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON s.id = ses.student_id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        $_SESSION['error'] = "Student not found.";
        header('Location: teacher_students.php');
        exit();
    }
    
    // Get enrolled classes
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name as instructor_name
        FROM " . TABLE_CLASSES . " c
        JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
        JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
        WHERE ce.student_id = ?
        ORDER BY c.class_name
    ");
    $stmt->execute([$student_id]);
    $enrolled_classes = $stmt->fetchAll();
    
    // Get recent sessions
    $stmt = $pdo->prepare("
        SELECT ls.*, c.class_name, ses.engagement_score
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id AND ses.student_id = ?
        WHERE c.id IN (SELECT class_id FROM " . TABLE_CLASS_ENROLLMENTS . " WHERE student_id = ?)
        ORDER BY ls.start_time DESC
        LIMIT 10
    ");
    $stmt->execute([$student_id, $student_id]);
    $recent_sessions = $stmt->fetchAll();
    
    // Get emotion data summary
    $stmt = $pdo->prepare("
        SELECT 
            facial_emotion,
            COUNT(*) as count,
            AVG(confidence_score) as avg_confidence
        FROM " . TABLE_EMOTION_DATA . "
        WHERE student_id = ?
        GROUP BY facial_emotion
        ORDER BY count DESC
    ");
    $stmt->execute([$student_id]);
    $emotion_summary = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Student Profile Error: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while loading student profile.";
    header('Location: teacher_students.php');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Profile - <?php echo htmlspecialchars($student['full_name']); ?></title>
    <!-- Include CSS from teacher_students.php -->
</head>
<body>
    <!-- Similar layout to teacher_students.php with detailed student info -->
    <div class="student-profile-container">
        <!-- Student header with avatar and basic info -->
        <!-- Enrolled classes section -->
        <!-- Recent sessions section -->
        <!-- Emotion data summary -->
        <!-- Engagement charts -->
    </div>
</body>
</html>