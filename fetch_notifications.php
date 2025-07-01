<?php
// fetch_notifications.php
session_start();
header('Content-Type: application/json'); // Always respond with JSON

$response = [
    'success' => false,
    'notifications' => [],
    'unread_count' => 0,
    'error' => ''
];

if (!isset($_SESSION['user_id'])) {
    $response['error'] = 'User not logged in.';
    echo json_encode($response);
    exit();
}

include_once 'db_connection.php'; // Include the database connection file

$user_id = $_SESSION['user_id'];
$conn = get_db_connection();

try {
    // Fetch all notifications for the user, ordered by unread status then by creation time
    // Limit to a reasonable number, e.g., 20, to prevent overloading the dropdown
    $stmt = $conn->prepare("
        SELECT id, type, message, link, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY is_read ASC, created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();

    // Get the actual unread count
    $stmt_count = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt_count->bind_param("i", $user_id);
    $stmt_count->execute();
    $stmt_count->bind_result($unread_count);
    $stmt_count->fetch();
    $stmt_count->close();

    $response['success'] = true;
    $response['notifications'] = $notifications;
    $response['unread_count'] = $unread_count;

} catch (Exception $e) {
    $response['error'] = 'Database error: ' . $e->getMessage();
} finally {
    $conn->close();
}

echo json_encode($response);
?>
