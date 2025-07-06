<?php
// download_user_report.php
session_start();

// Include the database connection file
include_once dirname(__FILE__) . '/db_connection.php';

// Check if user is logged in and is an admin or moderator
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'moderator')) {
    header('Location: login-register.php');
    exit();
}

$conn = get_db_connection();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="user_report_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Output CSV header row
fputcsv($output, array('User ID', 'Organization Name', 'Email', 'Role', 'Status', 'Document Path', 'Document Verified', 'Rejection Reason', 'Created At'));

// Fetch all user data
$stmt = $conn->prepare("SELECT id, organization_name, email, role, status, document_path, document_verified, rejection_reason, created_at FROM users ORDER BY created_at ASC");
if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Format document_verified for readability in CSV
        $row['document_verified'] = $row['document_verified'] ? 'Yes' : 'No';
        // Output each row to CSV
        fputcsv($output, $row);
    }
    $stmt->close();
} else {
    // Log error but don't output sensitive info to CSV
    error_log("SahaniShare Download Report Error: Failed to fetch user data for CSV: " . $conn->error);
    fputcsv($output, array('Error: Could not retrieve user data. Check server logs.'));
}

fclose($output);
$conn->close();
exit();
?>
