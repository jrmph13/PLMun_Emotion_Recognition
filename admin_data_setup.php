<?php
// data_setup.php
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['setup_data'])) {
    try {
        // Insert test data
        $sql = file_get_contents('test_data.sql');
        $pdo->exec($sql);
        $message = '<div style="background: #d1fae5; color: #065f46; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <i class="fas fa-check-circle"></i> Test data inserted successfully! 
                    <a href="analytics.php" style="color: #065f46; text-decoration: underline;">View Analytics</a>
                </div>';
    } catch (PDOException $e) {
        $message = '<div style="background: #fee2e2; color: #7f1d1d; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <i class="fas fa-exclamation-circle"></i> Error: ' . $e->getMessage() . '
                </div>';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Setup Test Data</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .btn { background: #3b82f6; color: white; padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #2563eb; }
        .info { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Setup Test Data for Analytics</h1>
        
        <?php echo $message; ?>
        
        <div class="info">
            <h3>⚠️ This will insert test data into your database:</h3>
            <ul>
                <li>3 instructors</li>
                <li>5 students</li>
                <li>6 classes</li>
                <li>20+ live sessions</li>
                <li>Attendance records</li>
                <li>Emotion data</li>
                <li>Engagement summaries</li>
            </ul>
            <p><strong>Note:</strong> Existing data will not be deleted.</p>
        </div>
        
        <form method="POST">
            <button type="submit" name="setup_data" class="btn">
                <i class="fas fa-database"></i> Insert Test Data
            </button>
            <a href="analytics.php" style="margin-left: 20px;">Cancel</a>
        </form>
    </div>
</body>
</html>