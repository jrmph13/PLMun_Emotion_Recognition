<?php
require_once 'config.php';

// Check if user is admin - USE THE PROPER FUNCTION FROM CONFIG
requireAdmin();

// Get filter parameters
$action_type = isset($_GET['action_type']) ? $_GET['action_type'] : '';
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Initialize variables
$audit_logs = [];
$all_users = [];
$active_users = [];
$login_activity = [];
$stats = [
    'total_actions' => 0,
    'unique_users' => 0,
    'total_logins' => 0,
    'security_events' => 0
];
$error_message = '';

$action_types = [
    'login' => 'Login',
    'logout' => 'Logout', 
    'create' => 'Create',
    'update' => 'Update',
    'delete' => 'Delete',
    'view' => 'View',
    'settings_change' => 'Settings Change',
    'system_event' => 'System Event',
    'security_event' => 'Security Event'
];

try {
    // Get all users for dropdown
    $stmt = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY full_name");
    $all_users = $stmt->fetchAll();
    
    // Build WHERE clause for filters
    $where_conditions = [];
    $params = [];
    
    if (!empty($action_type)) {
        $where_conditions[] = "action_type = ?";
        $params[] = $action_type;
    }
    
    if ($user_id > 0) {
        $where_conditions[] = "user_id = ?";
        $params[] = $user_id;
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = "DATE(created_at) >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "DATE(created_at) <= ?";
        $params[] = $date_to;
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(username LIKE ? OR action_description LIKE ? OR ip_address LIKE ?)";
        $search_param = "%" . $search . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    }
    
    // Get stats - FIXED: Use empty array if no params
    $stats_sql = "SELECT 
                    COUNT(*) as total_actions,
                    COUNT(DISTINCT user_id) as unique_users,
                    SUM(CASE WHEN action_type = 'login' THEN 1 ELSE 0 END) as total_logins,
                    SUM(CASE WHEN action_type = 'security_event' THEN 1 ELSE 0 END) as security_events
                  FROM audit_logs $where_clause";
    
    $stmt = $pdo->prepare($stats_sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $stats_result = $stmt->fetch();
    if ($stats_result) {
        $stats = array_merge($stats, $stats_result);
    }
    
    // Get audit logs WITHOUT pagination
    $sql = "SELECT 
                al.*,
                u.full_name,
                u.role as user_role_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $where_clause
            ORDER BY al.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $audit_logs = $stmt->fetchAll();
    
    // Get most active users (for sidebar chart)
    $active_users_sql = "SELECT 
                            u.id, u.full_name, u.role,
                            COUNT(al.id) as action_count,
                            MAX(al.created_at) as last_activity
                        FROM audit_logs al
                        JOIN users u ON al.user_id = u.id
                        WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        GROUP BY u.id, u.full_name, u.role
                        ORDER BY action_count DESC
                        LIMIT 5";
    
    $stmt = $pdo->prepare($active_users_sql);
    $stmt->execute();
    $active_users = $stmt->fetchAll();
    
    // Get login activity for chart (last 30 days)
    $login_activity_sql = "SELECT 
                              DATE(created_at) as date,
                              COUNT(*) as login_count,
                              COUNT(DISTINCT user_id) as unique_users
                           FROM audit_logs 
                           WHERE action_type = 'login' 
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                           GROUP BY DATE(created_at)
                           ORDER BY date ASC";
    
    $stmt = $pdo->prepare($login_activity_sql);
    $stmt->execute();
    $login_activity = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Audit Logs Error: " . $e->getMessage());
    $error_message = "Database Error: " . $e->getMessage();
}

// Function to get action icon
function getActionIcon($action_type) {
    $icons = [
        'login' => 'fas fa-sign-in-alt',
        'logout' => 'fas fa-sign-out-alt',
        'create' => 'fas fa-plus-circle',
        'update' => 'fas fa-edit',
        'delete' => 'fas fa-trash-alt',
        'view' => 'fas fa-eye',
        'settings_change' => 'fas fa-cog',
        'system_event' => 'fas fa-server',
        'security_event' => 'fas fa-shield-alt'
    ];
    
    return $icons[$action_type] ?? 'fas fa-info-circle';
}

// Function to get action color
function getActionColor($action_type) {
    $colors = [
        'login' => 'success',
        'logout' => 'secondary',
        'create' => 'info',
        'update' => 'warning',
        'delete' => 'danger',
        'view' => 'primary',
        'settings_change' => 'dark',
        'system_event' => 'purple',
        'security_event' => 'danger'
    ];
    
    return $colors[$action_type] ?? 'secondary';
}

// Function to format additional data
function formatAdditionalData($data) {
    if (empty($data)) return '';
    
    $decoded = json_decode($data, true);
    if (!$decoded) return htmlspecialchars($data);
    
    $formatted = '';
    foreach ($decoded as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_PRETTY_PRINT);
        }
        $formatted .= "<div><strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value) . "</div>";
    }
    
    return $formatted;
}

// Function to get user data (for display)
function getUserDisplayData($log) {
    if ($log['user_id'] && $log['full_name']) {
        return [
            'initials' => getInitials($log['full_name']),
            'name' => $log['full_name'],
            'role' => $log['user_role_name'] ?? $log['user_role'] ?? 'Unknown'
        ];
    } elseif ($log['username']) {
        return [
            'initials' => getInitials($log['username']),
            'name' => $log['username'],
            'role' => $log['user_role'] ?? 'Unknown'
        ];
    } else {
        return [
            'initials' => '??',
            'name' => 'System',
            'role' => 'System'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - PLMUN Emotion AI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reuse styles from analytics page */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: #f5f5f5;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden; /* Prevent horizontal scroll on body */
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 4px 0 15px rgba(0,0,0,0.2);
            z-index: 1000;
            transition: all 0.3s ease;
        }
        
        .sidebar-header {
            padding: 25px 20px;
            background: rgba(15, 23, 42, 0.9);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        
        .sidebar-header h1 {
            font-size: 24px;
            margin-bottom: 5px;
            color: white;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }
        
        .sidebar-header p {
            font-size: 12px;
            opacity: 0.8;
            letter-spacing: 1px;
        }
        
        .user-profile {
            padding: 25px 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(30, 41, 59, 0.5);
            margin: 10px;
            border-radius: 10px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            margin-right: 15px;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }
        
        .user-info h3 {
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .user-info p {
            font-size: 12px;
            opacity: 0.8;
            background: rgba(139, 92, 246, 0.1);
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .menu-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            padding: 0 25px 12px;
            color: rgba(255,255,255,0.5);
            font-weight: 600;
            margin-top: 15px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
            margin: 3px 10px;
            border-radius: 0 8px 8px 0;
            position: relative;
        }
        
        .menu-item:hover {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.1) 0%, transparent 100%);
            color: white;
            border-left-color: #8b5cf6;
            transform: translateX(5px);
        }
        
        .menu-item.active {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.2) 0%, transparent 100%);
            color: white;
            border-left-color: #8b5cf6;
            font-weight: 500;
        }
        
        .menu-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #8b5cf6;
            border-radius: 0 2px 2px 0;
        }
        
        .menu-icon {
            margin-right: 15px;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }
        
        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px); /* Ensure proper width calculation */
        }
        
        .topbar {
            background: white;
            padding: 0 30px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .menu-toggle {
            background: none;
            border: none;
            font-size: 20px;
            color: #4b5563;
            cursor: pointer;
            display: none;
        }
        
        .topbar-left h2 {
            color: #1f2937;
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .notification-btn {
            position: relative;
            background: none;
            border: none;
            font-size: 20px;
            color: #6b7280;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s;
        }
        
        .notification-btn:hover {
            background: #f3f4f6;
            color: #8b5cf6;
        }
        
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            font-size: 10px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-menu-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .user-menu-btn:hover {
            background: #f3f4f6;
        }
        
        .user-avatar-small {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }
        
        .user-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            min-width: 200px;
            display: none;
            z-index: 1000;
            overflow: hidden;
        }
        
        .user-menu-dropdown.show {
            display: block;
        }
        
        .user-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: #4b5563;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .user-menu-item:hover {
            background: #f3f4f6;
            color: #8b5cf6;
        }
        
        .user-menu-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 5px 0;
        }
        
        .content-wrapper {
            padding: 30px;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        
        .sidebar-logo {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .sidebar-logo img {
            width: 70px;
            height: 70px;
            object-fit: cover; /* Changed from 'contain' to 'cover' for better circle fill */
            border-radius: 50%; /* Changed from '10px' to '50%' for perfect circle */
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            background: white;
            padding: 5px;
        }
        
        .sidebar-logo img:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.3);
        }

        /* Audit Logs Specific Styles */
        .audit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-title h1 {
            color: #1f2937;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .header-title p {
            color: #6b7280;
            font-size: 14px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(139, 92, 246, 0.4);
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(16, 185, 129, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(239, 68, 68, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(245, 158, 11, 0.4);
        }
        
        /* Filters Section */
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 14px;
            color: #374151;
            font-weight: 600;
        }
        
        .filter-select, .filter-input {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            color: #4b5563;
            background: white;
            transition: all 0.3s;
            width: 100%;
            box-sizing: border-box;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .filter-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 2px solid #f3f4f6;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .filter-results {
            font-size: 14px;
            color: #6b7280;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: #1f2937;
            line-height: 1;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }
        
        /* NEW: Scrollable Audit Logs Container */
        .scrollable-audit-logs {
            max-height: 600px; /* Shows about 10 items */
            overflow-y: auto;
            border: 2px solid #f3f4f6;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        /* Custom scrollbar styling */
        .scrollable-audit-logs::-webkit-scrollbar {
            width: 8px;
        }
        
        .scrollable-audit-logs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .scrollable-audit-logs::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .scrollable-audit-logs::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Audit Logs Table */
        .table-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .table-header h2 {
            color: #1f2937;
            font-size: 20px;
            font-weight: 700;
        }
        
        .audit-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .audit-table th {
            background: #f8fafc;
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .audit-table td {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            color: #4b5563;
            vertical-align: top;
            word-break: break-word;
        }
        
        .audit-table tr:hover {
            background: #f8fafc;
        }
        
        .audit-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Action Badges */
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
            white-space: nowrap;
        }
        
        .badge-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .badge-secondary {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #6b7280;
        }
        
        .badge-info {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        
        .badge-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .badge-danger {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #7f1d1d;
        }
        
        .badge-dark {
            background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
            color: white;
        }
        
        .badge-purple {
            background: linear-gradient(135deg, #e9d5ff 0%, #d8b4fe 100%);
            color: #5b21b6;
        }
        
        /* User Cell */
        .user-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 150px;
        }
        
        .user-avatar-xs {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 12px;
            flex-shrink: 0;
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        
        .user-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .user-role {
            font-size: 11px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Additional Data */
        .additional-data {
            max-width: 300px;
            max-height: 100px;
            overflow-y: auto;
            font-size: 12px;
            line-height: 1.4;
            word-break: break-word;
        }
        
        .additional-data div {
            margin-bottom: 4px;
        }
        
        /* Time Cell */
        .time-cell {
            font-size: 12px;
            color: #6b7280;
            min-width: 120px;
        }
        
        .time-ago {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            width: 100%;
            box-sizing: border-box;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .chart-title h3 {
            color: #1f2937;
            font-size: 18px;
            font-weight: 700;
        }
        
        .chart-title p {
            color: #6b7280;
            font-size: 12px;
        }
        
        .chart-container {
            height: 250px;
            position: relative;
            width: 100%;
        }
        
        /* Activity Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 25px;
        }
        
        .timeline-dot {
            position: absolute;
            left: -30px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #e5e7eb;
        }
        
        .timeline-dot.success { background: #10b981; }
        .timeline-dot.warning { background: #f59e0b; }
        .timeline-dot.danger { background: #ef4444; }
        .timeline-dot.info { background: #3b82f6; }
        .timeline-dot.purple { background: #8b5cf6; }
        
        .timeline-content {
            background: white;
            padding: 15px;
            border-radius: 10px;
            border: 2px solid #f3f4f6;
        }
        
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .timeline-title {
            font-weight: 600;
            color: #1f2937;
        }
        
        .timeline-time {
            font-size: 12px;
            color: #6b7280;
        }
        
        .timeline-description {
            font-size: 14px;
            color: #4b5563;
            margin-bottom: 8px;
            word-break: break-word;
        }
        
        .timeline-meta {
            display: flex;
            gap: 10px;
            font-size: 12px;
            color: #6b7280;
            flex-wrap: wrap;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 80px;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .sidebar-header h1,
            .user-info,
            .menu-title,
            .menu-item span:not(.menu-icon) {
                display: none;
            }
            
            .menu-item {
                justify-content: center;
                padding: 15px;
                margin: 5px;
                border-radius: 10px;
            }
            
            .menu-icon {
                margin-right: 0;
                font-size: 20px;
            }
            
            .sidebar:hover .sidebar-header h1,
            .sidebar:hover .user-info,
            .sidebar:hover .menu-title,
            .sidebar:hover .menu-item span:not(.menu-icon) {
                display: block;
            }
            
            .sidebar:hover .menu-item {
                justify-content: flex-start;
                padding: 14px 25px;
            }
            
            .sidebar:hover .menu-icon {
                margin-right: 15px;
            }
            
            .audit-table th,
            .audit-table td {
                padding: 12px 15px;
                font-size: 13px;
            }
        }
        
        @media (max-width: 768px) {
            .content-wrapper {
                padding: 20px;
            }
            
            .topbar {
                padding: 0 20px;
                height: 70px;
            }
            
            .topbar-left h2 {
                font-size: 20px;
            }
            
            .audit-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .audit-table {
                font-size: 12px;
            }
            
            .audit-table th,
            .audit-table td {
                padding: 10px 12px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .user-cell {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .audit-table {
                display: block;
            }
            
            .audit-table thead {
                display: none;
            }
            
            .audit-table tr {
                display: block;
                margin-bottom: 15px;
                border: 2px solid #f3f4f6;
                border-radius: 10px;
                padding: 15px;
            }
            
            .audit-table td {
                display: block;
                padding: 10px 0;
                border-bottom: 1px solid #e5e7eb;
            }
            
            .audit-table td:before {
                content: attr(data-label);
                font-weight: 600;
                color: #374151;
                display: block;
                margin-bottom: 5px;
                font-size: 12px;
                text-transform: uppercase;
            }
            
            .audit-table td:last-child {
                border-bottom: none;
            }
        }
        
        /* Error Message */
        .error-message {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #ef4444;
            color: #7f1d1d;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 15px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .error-message i {
            font-size: 24px;
            color: #ef4444;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            width: 100%;
        }
        
        .empty-state-icon {
            font-size: 64px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }
        
        .empty-state-title {
            color: #6b7280;
            font-size: 18px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .empty-state-description {
            color: #9ca3af;
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="image/logo1.png" alt="Emotion AI Logo">
            </div>

            <h1>PLMUN Emotion Monitoring</h1>
            <p>Administration Panel</p>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-title">Main Navigation</div>
            <a href="admin_dashboard.php" class="menu-item">
                <i class="menu-icon fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="menu-title">User Management</div>
            <a href="admin_user_management.php" class="menu-item">
                <i class="menu-icon fas fa-users-cog"></i>
                <span>User Management</span>
            </a>
            
            <div class="menu-title">Analytics & Reports</div>
            <a href="admin_analytics_reports.php" class="menu-item">
                <i class="menu-icon fas fa-chart-line"></i>
                <span>Analytics & Reports</span>
            </a>
            
            <div class="menu-title">System Settings</div>
            <a href="admin_system_settings.php" class="menu-item">
                <i class="menu-icon fas fa-cogs"></i>
                <span>System Settings</span>
            </a>
            <a href="admin_audit_logs.php" class="menu-item active">
                <i class="menu-icon fas fa-clipboard-list"></i>
                <span>Audit Logs</span>
            </a>
            
            <div class="menu-title">Account</div>
            <a href="admin_profile.php" class="menu-item">
                <i class="menu-icon fas fa-user-circle"></i>
                <span>Profile</span>
            </a>
            <a href="logout.php" class="menu-item">
                <i class="menu-icon fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Audit Logs & Security</h2>
            </div>
            <div class="topbar-right">
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <div class="user-avatar-small">
                            <?php echo getInitials($_SESSION['full_name'] ?? 'AD'); ?>
                        </div>
                        <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <a href="admin_profile.php" class="user-menu-item">
                            <i class="fas fa-user-circle"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="admin_system_settings.php" class="user-menu-item">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                        <div class="user-menu-divider"></div>
                        <a href="logout.php" class="user-menu-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Content -->
        <div class="content-wrapper">
            <!-- Audit Header -->
            <div class="audit-header">
                <div class="header-title">
                    <h1>Audit Logs & System Activities</h1>
                    <p>Track login history, user actions, and system events for security and accountability</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="exportAuditLogs()">
                        <i class="fas fa-file-export"></i>
                        <span>Export Logs</span>
                    </button>
                    <button class="btn btn-danger" onclick="clearOldLogs()">
                        <i class="fas fa-trash-alt"></i>
                        <span>Clear Old Logs</span>
                    </button>
                </div>
            </div>
            
            <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Stats Grid -->
        
            
            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" action="" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Action Type</label>
                            <select name="action_type" class="filter-select">
                                <option value="">All Actions</option>
                                <?php foreach ($action_types as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $action_type == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">User</label>
                            <select name="user_id" class="filter-select">
                                <option value="0">All Users</option>
                                <?php foreach ($all_users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['role'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Date From</label>
                            <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Date To</label>
                            <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" name="search" class="filter-input" placeholder="Search username, action, or IP..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <div class="filter-results">
                            Showing <?php echo number_format(count($audit_logs)); ?> audit log entries
                        </div>
                        <div style="display: flex; gap: 15px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i>
                                <span>Apply Filters</span>
                            </button>
                            <a href="admin_audit_logs.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i>
                                <span>Reset Filters</span>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Charts Grid -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <h3>Login Activity (Last 30 Days)</h3>
                            <p>Daily login patterns</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="loginChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <h3>Action Distribution</h3>
                            <p>Types of actions performed</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="actionChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Audit Logs Table -->
            <div class="table-container">
                <div class="table-header">
                    <h2>System Audit Logs</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-secondary" onclick="refreshLogs()">
                            <i class="fas fa-sync-alt"></i>
                            <span>Refresh</span>
                        </button>
                    </div>
                </div>
                
                <?php if (empty($audit_logs)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <h3 class="empty-state-title">No Audit Logs Found</h3>
                        <p class="empty-state-description">
                            <?php echo !empty($where_conditions) ? 
                                'Try adjusting your filters to see more results.' : 
                                'System activities will appear here once users start using the system.'; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <!-- NEW: Scrollable Container -->
                    <div class="scrollable-audit-logs">
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                    <th>IP Address</th>
                                    <th>Additional Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($audit_logs as $log): ?>
                                    <?php
                                    $action_color = getActionColor($log['action_type']);
                                    $time_ago = relativeTime($log['created_at']);
                                    $user_data = getUserDisplayData($log);
                                    ?>
                                    <tr>
                                        <td class="time-cell">
                                            <div><?php echo formatDate($log['created_at'], 'M j, Y'); ?></div>
                                            <div><?php echo formatDate($log['created_at'], 'H:i:s'); ?></div>
                                            <div class="time-ago"><?php echo $time_ago; ?></div>
                                        </td>
                                        <td>
                                            <?php if ($log['user_id'] || $log['username']): ?>
                                                <div class="user-cell">
                                                    <div class="user-avatar-xs"><?php echo $user_data['initials']; ?></div>
                                                    <div class="user-details">
                                                        <div class="user-name"><?php echo htmlspecialchars($user_data['name']); ?></div>
                                                        <div class="user-role"><?php echo htmlspecialchars($user_data['role']); ?></div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="action-badge badge-secondary">
                                                    <i class="fas fa-server"></i>
                                                    System
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="action-badge badge-<?php echo $action_color; ?>">
                                                <i class="<?php echo getActionIcon($log['action_type']); ?>"></i>
                                                <?php echo htmlspecialchars($action_types[$log['action_type']] ?? $log['action_type']); ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 300px;">
                                            <?php echo htmlspecialchars($log['action_description']); ?>
                                            <?php if ($log['table_affected'] && $log['record_id']): ?>
                                                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                                    <i class="fas fa-database"></i>
                                                    <?php echo htmlspecialchars($log['table_affected']); ?> #<?php echo $log['record_id']; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($log['ip_address']): ?>
                                                <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                                                <?php if ($log['user_agent']): ?>
                                                    <div style="font-size: 11px; color: #9ca3af; margin-top: 4px; max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                                        <?php echo htmlspecialchars(substr($log['user_agent'], 0, 50)); ?>...
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #9ca3af;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="additional-data">
                                                <?php echo formatAdditionalData($log['additional_data'] ?? ''); ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Active Users & Recent Activity -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <h3>Most Active Users</h3>
                            <p>Top users by activity count</p>
                        </div>
                    </div>
                    <div style="max-height: 300px; overflow-y: auto; border: 2px solid #f3f4f6; border-radius: 10px;">
                        <table class="audit-table" style="min-width: unset;">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Actions</th>
                                    <th>Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($active_users)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; padding: 20px; color: #9ca3af;">
                                            No active users data available
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($active_users as $active_user): ?>
                                        <tr>
                                            <td>
                                                <div class="user-cell">
                                                    <div class="user-avatar-xs"><?php echo getInitials($active_user['full_name']); ?></div>
                                                    <div class="user-details">
                                                        <div class="user-name"><?php echo htmlspecialchars($active_user['full_name']); ?></div>
                                                        <div class="user-role"><?php echo htmlspecialchars($active_user['role']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="font-weight: 600; color: #1f2937;">
                                                    <?php echo number_format($active_user['action_count']); ?>
                                                </span>
                                            </td>
                                            <td class="time-cell">
                                                <?php echo formatDate($active_user['last_activity'], 'M j, H:i'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <h3>Recent Security Events</h3>
                            <p>Latest security-related activities</p>
                        </div>
                    </div>
                    <div class="timeline">
                        <?php
                        // Get recent security events (last 5)
                        $security_logs = array_slice(array_filter($audit_logs, function($log) {
                            return $log['action_type'] === 'security_event';
                        }), 0, 5);
                        ?>
                        
                        <?php if (empty($security_logs)): ?>
                            <div style="text-align: center; padding: 40px; color: #9ca3af;">
                                <i class="fas fa-shield-alt" style="font-size: 48px; margin-bottom: 15px;"></i>
                                <p>No recent security events</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($security_logs as $log): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot <?php echo getActionColor($log['action_type']); ?>"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-header">
                                            <div class="timeline-title">
                                                <?php echo htmlspecialchars($action_types[$log['action_type']] ?? $log['action_type']); ?>
                                            </div>
                                            <div class="timeline-time">
                                                <?php echo relativeTime($log['created_at']); ?>
                                            </div>
                                        </div>
                                        <div class="timeline-description">
                                            <?php echo htmlspecialchars($log['action_description']); ?>
                                        </div>
                                        <div class="timeline-meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($log['username'] ?? 'System'); ?></span>
                                            <?php if ($log['ip_address']): ?>
                                                <span><i class="fas fa-globe"></i> <?php echo htmlspecialchars($log['ip_address']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });
        
        // User menu dropdown
        const userMenuBtn = document.getElementById('userMenuBtn');
        const userMenuDropdown = document.getElementById('userMenuDropdown');
        
        userMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenuDropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', () => {
            userMenuDropdown.classList.remove('show');
        });
        
        // Login Activity Chart
        const loginCtx = document.getElementById('loginChart');
        if (loginCtx) {
            const loginActivityData = <?php echo json_encode($login_activity ?: []); ?>;
            
            if (loginActivityData.length > 0) {
                const loginChart = new Chart(loginCtx, {
                    type: 'line',
                    data: {
                        labels: loginActivityData.map(item => {
                            const date = new Date(item.date);
                            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                        }),
                        datasets: [{
                            label: 'Logins',
                            data: loginActivityData.map(item => item.login_count),
                            borderColor: '#8b5cf6',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4
                        }, {
                            label: 'Unique Users',
                            data: loginActivityData.map(item => item.unique_users),
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                }
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Count'
                                },
                                grid: {
                                    drawBorder: false
                                }
                            }
                        }
                    }
                });
            } else {
                // Show placeholder if no data
                loginCtx.parentElement.innerHTML = '<div style="text-align: center; padding: 60px 20px; color: #9ca3af;"><i class="fas fa-chart-line" style="font-size: 48px; margin-bottom: 15px;"></i><p>No login activity data available</p></div>';
            }
        }
        
        // Action Distribution Chart
        const actionCtx = document.getElementById('actionChart');
        if (actionCtx) {
            const auditLogs = <?php echo json_encode($audit_logs); ?>;
            
            if (auditLogs.length > 0) {
                // Calculate action type counts
                const actionCounts = {};
                auditLogs.forEach(log => {
                    const actionType = log.action_type;
                    actionCounts[actionType] = (actionCounts[actionType] || 0) + 1;
                });
                
                const actionLabels = Object.keys(actionCounts).map(key => {
                    const actionNames = {
                        'login': 'Login',
                        'logout': 'Logout', 
                        'create': 'Create',
                        'update': 'Update',
                        'delete': 'Delete',
                        'view': 'View',
                        'settings_change': 'Settings Change',
                        'system_event': 'System Event',
                        'security_event': 'Security Event'
                    };
                    return actionNames[key] || key;
                });
                
                const actionChart = new Chart(actionCtx, {
                    type: 'doughnut',
                    data: {
                        labels: actionLabels,
                        datasets: [{
                            data: Object.values(actionCounts),
                            backgroundColor: [
                                '#10b981', // login - green
                                '#6b7280', // logout - gray
                                '#3b82f6', // create - blue
                                '#f59e0b', // update - yellow
                                '#ef4444', // delete - red
                                '#8b5cf6', // view - purple
                                '#374151', // settings_change - dark
                                '#ec4899', // system_event - pink
                                '#f97316'  // security_event - orange
                            ],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                            }
                        },
                        cutout: '60%'
                    }
                });
            } else {
                // Show placeholder if no data
                actionCtx.parentElement.innerHTML = '<div style="text-align: center; padding: 60px 20px; color: #9ca3af;"><i class="fas fa-chart-pie" style="font-size: 48px; margin-bottom: 15px;"></i><p>No action data available</p></div>';
            }
        }
        
        // Export audit logs
        function exportAuditLogs() {
            showNotification('Preparing audit logs for export...', 'info');
            
            // Get current filters
            const params = new URLSearchParams(window.location.search);
            
            // Create export URL
            const exportUrl = 'export_audit_logs.php?' + params.toString();
            
            // Trigger download
            window.open(exportUrl, '_blank');
            
            setTimeout(() => {
                showNotification('Audit logs export initiated', 'success');
            }, 1000);
        }
        
        // Clear old logs
        function clearOldLogs() {
            if (confirm('Are you sure you want to clear audit logs older than 90 days? This action cannot be undone.')) {
                showNotification('Clearing old audit logs...', 'info');
                
                // Send AJAX request to clear logs
                fetch('clear_audit_logs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=clear_old_logs'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Old audit logs cleared successfully', 'success');
                        setTimeout(() => {
                            location.reload(); // Refresh the page
                        }, 1500);
                    } else {
                        showNotification(data.message || 'Error clearing logs', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error clearing logs: ' + error.message, 'error');
                });
            }
        }
        
        // Refresh logs
        function refreshLogs() {
            document.getElementById('filterForm').submit();
        }
        
        // Show notification
        function showNotification(message, type) {
            // Create notification element
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b'};
                color: white;
                padding: 15px 20px;
                border-radius: 10px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.2);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 10px;
                animation: slideInRight 0.3s ease;
            `;
            
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        // Add CSS for notifications
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            @keyframes slideOutRight {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportAuditLogs();
            }
            
            // Ctrl + R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                refreshLogs();
            }
            
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
        });
        
        console.log('Audit Logs Dashboard Ready');
        console.log('Shortcuts: Ctrl+E (Export), Ctrl+R (Refresh), Ctrl+F (Search)');
    </script>
</body>
</html>