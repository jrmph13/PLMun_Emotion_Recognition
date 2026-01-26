<?php
require_once 'config.php';
requireAdmin();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=audit_logs_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, [
    'ID', 'Timestamp', 'User ID', 'Username', 'User Role', 
    'Action Type', 'Description', 'Table Affected', 'Record ID',
    'IP Address', 'User Agent', 'Additional Data'
]);

try {
    // Get filter parameters from GET
    $action_type = $_GET['action_type'] ?? '';
    $user_id = $_GET['user_id'] ?? 0;
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $search = $_GET['search'] ?? '';
    
    // Build WHERE clause
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
    
    // Get audit logs
    $sql = "SELECT * FROM audit_logs $where_clause ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    
    // Output each row
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['created_at'],
            $row['user_id'],
            $row['username'],
            $row['user_role'],
            $row['action_type'],
            $row['action_description'],
            $row['table_affected'],
            $row['record_id'],
            $row['ip_address'],
            $row['user_agent'],
            $row['additional_data']
        ]);
    }
    
    // Log the export action
    logAuditTrail(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['username'],
        'view',
        "Exported audit logs to CSV",
        'audit_logs',
        null,
        [
            'filters' => [
                'action_type' => $action_type,
                'user_id' => $user_id,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'search' => $search
            ]
        ]
    );
    
    fclose($output);
    
} catch (PDOException $e) {
    error_log("Export audit logs error: " . $e->getMessage());
    fclose($output);
    die("Error exporting audit logs");
}
?>