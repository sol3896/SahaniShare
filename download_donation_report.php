<?php
// download_donation_report.php
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
header('Content-Disposition: attachment; filename="donation_report_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Output CSV header row
fputcsv($output, array('Donation ID', 'Description', 'Quantity', 'Unit', 'Expiry Time', 'Status', 'Rejection Reason', 'Donor Organization', 'Created At'));

// Fetch all donation data, joining with users to get donor organization name
$stmt = $conn->prepare("SELECT d.id, d.description, d.quantity, d.unit, d.expiry_time, d.status, d.rejection_reason, u.organization_name as donor_org, d.created_at FROM donations d JOIN users u ON d.donor_id = u.id ORDER BY d.created_at ASC");
if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Output each row to CSV
        fputcsv($output, $row);
    }
    $stmt->close();
} else {
    // Log error but don't output sensitive info to CSV
    error_log("SahaniShare Download Report Error: Failed to fetch donation data for CSV: " . $conn->error);
    fputcsv($output, array('Error: Could not retrieve donation data. Check server logs.'));
}

fclose($output);
$conn->close();
exit();
?>