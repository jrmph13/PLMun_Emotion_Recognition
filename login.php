<?php
include 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id']) || isset($_SESSION['student_user_id'])) {
    if (isset($_SESSION['user_id'])) {
        if ($_SESSION['role'] === 'admin') {
            header("Location: admin_dashboard.php");
        } elseif ($_SESSION['role'] === 'instructor') {
            header("Location: teacher_dashboard.php");
        } else {
            header("Location: student_dashboard.php");
        }
    } else {
        header("Location: student_dashboard.php");
    }
    exit();
}

$error = '';
$username = '';
$login_type = 'admin'; // Default login type

// Process login form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $login_type = $_POST['login_type'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        if ($login_type === 'teacher') {
            // Teacher login
            $sql = "SELECT * FROM users WHERE username = ? AND role = 'instructor'";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$username])) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check if user exists and password is correct
                if ($user && password_verify($password, $user['password'])) {
                    // Login successful
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    
                    // Log successful login
                    logUserLogin($user['id'], $user['username'], $user['role'], [
                        'login_type' => 'teacher',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    // Redirect to TEACHER dashboard
                    header("Location: teacher_dashboard.php");
                    exit();
                } else {
                    $error = 'Invalid Teacher ID or password.';
                    // Log failed login attempt
                    logFailedLogin($username, $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'Invalid credentials');
                }
            } else {
                $error = 'Database error. Please try again.';
            }
        } elseif ($login_type === 'admin') {
            // Admin login
            $sql = "SELECT * FROM users WHERE username = ? AND role = 'admin'";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$username])) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check if user exists and password is correct
                if ($user && password_verify($password, $user['password'])) {
                    // Login successful
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    
                    // Log successful login
                    logUserLogin($user['id'], $user['username'], $user['role'], [
                        'login_type' => 'admin',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    // Redirect to ADMIN dashboard
                    header("Location: admin_dashboard.php");
                    exit();
                } else {
                    $error = 'Invalid Admin credentials.';
                    // Log failed login attempt
                    logFailedLogin($username, $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'Invalid credentials');
                }
            } else {
                $error = 'Database error. Please try again.';
            }
        } else {
            // Student login
            // FIXED: Use student_number instead of student_id
            // Also join with users table to get user credentials
            $sql = "SELECT s.*, u.* FROM students s 
                    JOIN users u ON s.user_id = u.id 
                    WHERE s.student_number = ? AND u.role = 'student'";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$username])) {
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check if student exists and password is correct
                if ($student && password_verify($password, $student['password'])) {
                    // Login successful
                    // Store both user_id (from users table) and student_id (from students table)
                    $_SESSION['user_id'] = $student['user_id']; // This is the users.id
                    $_SESSION['student_id'] = $student['id']; // This is the students.id
                    $_SESSION['username'] = $student['username'];
                    $_SESSION['full_name'] = $student['full_name'];
                    $_SESSION['email'] = $student['email'];
                    $_SESSION['role'] = 'student';
                    $_SESSION['student_number'] = $student['student_number'];
                    
                    // Log successful login
                    logUserLogin($student['user_id'], $student['username'], 'student', [
                        'login_type' => 'student',
                        'student_number' => $student['student_number'],
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    // Redirect to student dashboard
                    header("Location: student_dashboard.php");
                    exit();
                } else {
                    $error = 'Invalid Student ID or password.';
                    // Log failed login attempt
                    logFailedLogin($username, $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'Invalid credentials');
                }
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - PLMUN EmotionAI</title>
    <style>
        :root {
            --primary-green: #1a8c54;
            --secondary-green: #0d7a45;
            --dark-green: #0d5c33;
            --light-green: #e6f7ef;
            --bright-green: #00d26a;
            --accent-green: #2ecc71;
            --primary-white: #ffffff;
            --secondary-white: #f5f5f5;
            --off-white: #f9f9f9;
            --light-gray: #e0e0e0;
            --medium-gray: #cccccc;
            --dark-gray: #333333;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #014421;
            color: var(--dark-gray);
            line-height: 1.6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 15px;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Background image overlay */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('image/background1.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0.15;
            z-index: -1;
        }
        
        /* Responsive container */
        .login-container {
            width: 100%;
            max-width: 450px;
            min-width: 280px;
            z-index: 1;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .logo-image {
            width: 60px;
            height: 60px;
            margin-right: 12px;
            margin-bottom: 5px;
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            box-shadow: 0 3px 5px rgba(0, 0, 0, 0.1);
            background-image: url('image/logo1.png');
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            border: 2px solid var(--primary-green);
            flex-shrink: 0;
        }
        
        .logo-text {
            font-size: 22px;
            font-weight: 700;
            color: var(--primary-white);
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5);
            line-height: 1.2;
        }
        
        .logo-text span {
            color: var(--light-green);
            display: block;
        }
        
        /* Login card */
        .login-card {
            background-color: rgba(208, 216, 195, 0.95);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(5px);
            width: 100%;
        }
        
        .login-type-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            margin-bottom: 20px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 6px;
            padding: 3px;
            border: 1px solid rgba(0, 0, 0, 0.1);
            gap: 3px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .login-type-btn {
            padding: 10px 5px;
            text-align: center;
            cursor: pointer;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            background: transparent;
            color: var(--dark-gray);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .login-type-btn i {
            font-size: 12px;
        }
        
        .login-type-btn:hover {
            background-color: rgba(1, 68, 33, 0.1);
        }
        
        .login-type-btn.active {
            color: var(--primary-white);
        }
        
        .login-type-btn.teacher.active {
            background: #014421;
            box-shadow: 0 3px 6px rgba(1, 68, 33, 0.3);
        }
        
        .login-type-btn.student.active {
            background: #014421;
            box-shadow: 0 3px 6px rgba(1, 68, 33, 0.3);
        }
        
        .login-type-btn.admin.active {
            background: #014421;
            box-shadow: 0 3px 6px rgba(1, 68, 33, 0.3);
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 6px;
            color: var(--dark-gray);
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 14px;
            background-color: rgba(255, 255, 255, 0.9);
            border: 1px solid var(--medium-gray);
            border-radius: 6px;
            color: var(--dark-gray);
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(26, 140, 84, 0.2);
            background-color: var(--primary-white);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
            font-size: 16px;
            margin-top: 8px;
            box-shadow: 0 3px 5px rgba(0, 0, 0, 0.1);
            background-color: #014421 !important;
            color: var(--primary-white) !important;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary,
        .btn-student,
        .btn-admin {
            background-color: #014421;
            color: var(--primary-white);
        }
        
        .btn-primary:hover,
        .btn-student:hover,
        .btn-admin:hover {
            background-color: var(--dark-green);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .error-message {
            background-color: rgba(244, 67, 54, 0.15);
            border: 1px solid rgba(244, 67, 54, 0.4);
            color: #d32f2f;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 18px;
            text-align: center;
            font-weight: 500;
            font-size: 14px;
            box-shadow: 0 1px 3px rgba(244, 67, 54, 0.1);
            line-height: 1.4;
        }
        
        .forgot-password {
            margin-top: 20px;
            padding: 15px;
            background-color: rgba(255, 243, 205, 0.8);
            border: 1px solid #ffc107;
            border-radius: 6px;
            text-align: center;
            color: #856404;
            font-size: 13px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            line-height: 1.5;
        }
        
        .admin-email {
            color: #014421;
            font-weight: bold;
            text-decoration: none;
            word-break: break-all;
        }
        
        .admin-email:hover {
            text-decoration: underline;
        }
        
        /* Tablet devices (768px and below) */
        @media (max-width: 768px) {
            .login-container {
                max-width: 400px;
                padding: 10px;
            }
            
            .login-card {
                padding: 20px 18px;
            }
            
            .logo {
                margin-bottom: 15px;
            }
            
            .logo-image {
                width: 55px;
                height: 55px;
                margin-right: 10px;
            }
            
            .logo-text {
                font-size: 20px;
            }
            
            .btn {
                padding: 13px;
            }
        }
        
        /* Mobile devices (480px and below) */
        @media (max-width: 480px) {
            body {
                padding: 10px;
                align-items: flex-start;
                padding-top: 20px;
            }
            
            .login-container {
                max-width: 100%;
                margin-top: 10px;
            }
            
            .login-card {
                padding: 18px 16px;
                border-radius: 10px;
            }
            
            .login-header {
                margin-bottom: 20px;
            }
            
            .logo {
                flex-direction: row;
                margin-bottom: 15px;
            }
            
            .logo-image {
                width: 50px;
                height: 50px;
                margin-right: 10px;
                margin-bottom: 0;
            }
            
            .logo-text {
                font-size: 18px;
            }
            
            .login-type-btn {
                padding: 10px 4px;
                font-size: 12px;
                min-height: 38px;
            }
            
            .login-type-btn i {
                font-size: 11px;
            }
            
            .form-input {
                padding: 14px 12px;
                font-size: 15px;
            }
            
            .btn {
                padding: 14px;
                font-size: 15px;
            }
            
            .error-message {
                padding: 10px;
                font-size: 13px;
            }
            
            .forgot-password {
                padding: 12px;
                font-size: 12px;
            }
        }
        
        /* Very small mobile devices (360px and below) */
        @media (max-width: 360px) {
            body {
                padding: 8px;
            }
            
            .login-card {
                padding: 16px 14px;
            }
            
            .logo {
                flex-direction: column;
                text-align: center;
            }
            
            .logo-image {
                margin-right: 0;
                margin-bottom: 8px;
                width: 45px;
                height: 45px;
            }
            
            .logo-text {
                font-size: 16px;
            }
            
            .login-type-btn {
                font-size: 11px;
                padding: 8px 3px;
                min-height: 36px;
            }
            
            .login-type-btn i {
                display: none; /* Hide icons on very small screens */
            }
            
            .form-input {
                padding: 12px 10px;
            }
            
            .btn {
                padding: 12px;
                font-size: 14px;
            }
            
            .forgot-password {
                padding: 10px;
                font-size: 11px;
            }
        }
        
        /* Landscape orientation for mobile */
        @media (max-height: 600px) and (orientation: landscape) {
            body {
                padding: 10px;
                align-items: flex-start;
                min-height: auto;
            }
            
            .login-container {
                margin-top: 10px;
                margin-bottom: 10px;
            }
            
            .login-header {
                margin-bottom: 15px;
            }
            
            .logo {
                margin-bottom: 10px;
            }
            
            .logo-image {
                width: 40px;
                height: 40px;
            }
            
            .logo-text {
                font-size: 18px;
            }
            
            .form-group {
                margin-bottom: 12px;
            }
            
            .btn {
                margin-top: 5px;
                padding: 10px;
            }
        }
        
        /* Large tablets and small laptops (769px to 1024px) */
        @media (min-width: 769px) and (max-width: 1024px) {
            .login-container {
                max-width: 420px;
            }
            
            .login-card {
                padding: 22px;
            }
        }
        
        /* Prevent zoom on input focus in iOS */
        @media screen and (max-width: 768px) {
            input, select, textarea {
                font-size: 16px !important;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <!-- Logo image using CSS background-image -->
                <div class="logo-image"></div>
                <div class="logo-text">PLMUN <span>EmotionAI</span></div>
            </div>
        </div>
        
        <div class="login-card">
            <?php if (!empty($error)): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="login-type-selector">
                <button type="button" class="login-type-btn teacher <?php echo $login_type === 'teacher' ? 'active' : ''; ?>" onclick="setLoginType('teacher')">
                    <i class="fas fa-chalkboard-teacher"></i> <span class="btn-text">Teacher</span>
                </button>
                <button type="button" class="login-type-btn student <?php echo $login_type === 'student' ? 'active' : ''; ?>" onclick="setLoginType('student')">
                    <i class="fas fa-user-graduate"></i> <span class="btn-text">Student</span>
                </button>
                <button type="button" class="login-type-btn admin <?php echo $login_type === 'admin' ? 'active' : ''; ?>" onclick="setLoginType('admin')">
                    <i class="fas fa-user-shield"></i> <span class="btn-text">Admin</span>
                </button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="login_type" id="login_type" value="<?php echo $login_type; ?>">
                
                <div class="form-group">
                    <label for="username" class="form-label" id="username_label">
                        <?php 
                            if ($login_type === 'teacher') echo 'Teacher ID';
                            elseif ($login_type === 'student') echo 'Student ID';
                            else echo 'Admin Username';
                        ?>
                    </label>
                    <input type="text" id="username" name="username" class="form-input <?php echo $login_type . '-input'; ?>" value="<?php echo htmlspecialchars($username); ?>" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-input <?php echo $login_type . '-input'; ?>" required>
                </div>
                
                <button type="submit" class="btn btn-<?php echo $login_type; ?>" id="submitBtn">
                    <i class="fas fa-sign-in-alt"></i> 
                    <span class="btn-text">Sign In as <?php echo ucfirst($login_type); ?></span>
                </button>
            </form>
            
            <div class="forgot-password">
                <p><strong>Forgot Your Password?</strong></p>
                <p style="font-size: 12px; margin-top: 8px; line-height: 1.4;">
                    Please contact the administrator at 
                    <a href="mailto:adminsupport@plmun.edu.ph" class="admin-email">adminsupport@plmun.edu.ph</a> 
                    using your institutional email (IE) for password reset assistance.
                </p>
            </div>
        </div>
    </div>

    <script>
        function setLoginType(type) {
            document.getElementById('login_type').value = type;
            
            // Update active button
            document.querySelectorAll('.login-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`.login-type-btn.${type}`).classList.add('active');
            
            // Update form labels and styling
            const usernameLabel = document.getElementById('username_label');
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const submitBtn = document.getElementById('submitBtn');
            const submitBtnText = submitBtn.querySelector('.btn-text');
            
            let labelText, btnText;
            
            switch(type) {
                case 'teacher':
                    labelText = 'Teacher ID';
                    btnText = 'Teacher';
                    break;
                case 'student':
                    labelText = 'Student ID';
                    btnText = 'Student';
                    break;
                case 'admin':
                    labelText = 'Admin Username';
                    btnText = 'Admin';
                    break;
            }
            
            usernameLabel.textContent = labelText;
            usernameInput.className = `form-input ${type}-input`;
            passwordInput.className = `form-input ${type}-input`;
            
            submitBtn.className = 'btn';
            submitBtnText.textContent = `Sign In as ${btnText}`;
            submitBtn.style.backgroundColor = '#014421';
            submitBtn.style.color = '#ffffff';
        }
        
        // Initialize button color on page load
        document.addEventListener('DOMContentLoaded', function() {
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.style.backgroundColor = '#014421';
                submitBtn.style.color = '#ffffff';
            }
            
            // Prevent form zoom on iOS
            document.addEventListener('touchstart', function() {}, {passive: true});
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            // Adjust logo layout for very small screens
            if (window.innerWidth <= 360) {
                document.querySelector('.logo').style.flexDirection = 'column';
            } else {
                document.querySelector('.logo').style.flexDirection = 'row';
            }
        });
    </script>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</body>
</html>