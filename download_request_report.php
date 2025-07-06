<?php
// download_request_report.php
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
header('Content-Disposition: attachment; filename="request_report_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Output CSV header row - Adjusted to match the actual columns and joined data
fputcsv($output, array(
    'Request ID', 
    'Donation ID', 
    'Requested Item Description', // From donations table
    'Requested Quantity', 
    'Requested Unit', // From donations table
    'Request Status', 
    'Recipient Organization', // From users table (recipient)
    'Donor Organization', // From users table (donor, via donations)
    'Requested At', 
    'Last Updated At', 
    'Rejection Reason'
));

// Fetch all request data, joining with donations and users tables
$stmt = $conn->prepare("
    SELECT 
        r.id AS request_id,
        r.donation_id,
        d.description AS requested_item_description,
        r.requested_quantity,
        d.unit AS requested_unit,
        r.status AS request_status,
        rec.organization_name AS recipient_organization_name,
        don.organization_name AS donor_organization_name,
        r.requested_at,
        r.updated_at,
        r.rejection_reason
    FROM 
        requests r
    JOIN 
        donations d ON r.donation_id = d.id
    JOIN 
        users rec ON r.recipient_id = rec.id -- Join for recipient organization name
    JOIN
        users don ON d.donor_id = don.id -- Join for donor organization name
    ORDER BY 
        r.requested_at ASC
");

if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Output each row to CSV
        fputcsv($output, $row);
    }
    $stmt->close();
} else {
    // Log error but don't output sensitive info to CSV
    error_log("SahaniShare Download Report Error: Failed to fetch request data for CSV: " . $conn->error);
    fputcsv($output, array('Error: Could not retrieve request data. Check server logs.'));
}

fclose($output);
$conn->close();
exit();
?>

