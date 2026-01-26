<?php
// student_engagement.php
require_once 'config.php';
requireInstructor();

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

if ($student_id <= 0) {
    header('Location: teacher_students.php');
    exit();
}

// Get student details
$stmt = $pdo->prepare("
    SELECT s.*, u.full_name, u.student_number 
    FROM " . TABLE_STUDENTS . " s
    JOIN " . TABLE_USERS . " u ON s.user_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    $_SESSION['error'] = "Student not found.";
    header('Location: teacher_students.php');
    exit();
}

// Get engagement history
$stmt = $pdo->prepare("
    SELECT 
        ls.*,
        c.class_name,
        ses.engagement_score,
        ses.happy_percent,
        ses.bored_percent,
        ses.neutral_percent,
        ses.created_at as summary_date
    FROM " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses
    JOIN " . TABLE_LIVE_SESSIONS . " ls ON ses.session_id = ls.id
    JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
    WHERE ses.student_id = ?
    ORDER BY ls.start_time DESC
");
$stmt->execute([$student_id]);
$engagement_history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Engagement History - <?php echo htmlspecialchars($student['full_name']); ?></title>
    <!-- Include CSS and Chart.js -->
</head>
<body>
    <!-- Display engagement history with charts -->
    <div class="engagement-history">
        <h1>Engagement History for <?php echo htmlspecialchars($student['full_name']); ?></h1>
        <!-- Line chart showing engagement over time -->
        <!-- Table with session-by-session engagement data -->
    </div>
</body>
</html>