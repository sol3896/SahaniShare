<?php
// download-report.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

// Check if user is logged in and is an admin or moderator
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'moderator')) {
    header('Location: login-register.php');
    exit();
}

$report_type = $_GET['type'] ?? 'donations_all'; // Default report type
$conn = get_db_connection();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="sahani_share_report_' . $report_type . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w'); // Open output stream

switch ($report_type) {
    case 'donations_all':
        fputcsv($output, ['Donation ID', 'Description', 'Quantity', 'Unit', 'Expiry Time', 'Category', 'Pickup Location', 'Status', 'Donor Org', 'Created At']);
        $stmt = $conn->prepare("
            SELECT d.id, d.description, d.quantity, d.unit, d.expiry_time, d.category, d.pickup_location, d.status, u.organization_name, d.created_at
            FROM donations d JOIN users u ON d.donor_id = u.id ORDER BY d.created_at DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, $row);
        }
        $stmt->close();
        break;

    case 'users_all':
        fputcsv($output, ['User ID', 'Organization Name', 'Email', 'Role', 'Status', 'Created At']);
        $stmt = $conn->prepare("SELECT id, organization_name, email, role, status, created_at FROM users ORDER BY created_at DESC");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, $row);
        }
        $stmt->close();
        break;

    case 'requests_all':
        fputcsv($output, ['Request ID', 'Donation ID', 'Donation Description', 'Requested Quantity', 'Unit', 'Recipient Org', 'Status', 'Requested At']);
        $stmt = $conn->prepare("
            SELECT r.id, d.id as donation_id, d.description, r.requested_quantity, d.unit, u.organization_name, r.status, r.requested_at
            FROM requests r JOIN donations d ON r.donation_id = d.id JOIN users u ON r.recipient_id = u.id
            ORDER BY r.requested_at DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, $row);
        }
        $stmt->close();
        break;

    // Add more report types here as needed (e.g., feedback, specific date ranges)
    default:
        // Handle invalid report type or redirect
        fputcsv($output, ['Error', 'Invalid report type specified.']);
        break;
}

fclose($output); // Close the output stream
$conn->close();
exit(); // Ensure no extra output after CSV
?>
