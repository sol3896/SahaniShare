<?php
// mark_notification_read.php
session_start();
header('Content-Type: application/json'); // Respond with JSON

include_once 'db_connection.php';

$response = ['success' => false, 'error' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['error'] = 'User not logged in.';
    echo json_encode($response);
    exit();
}

if (!isset($_POST['notification_id'])) {
    $response['error'] = 'Notification ID not provided.';
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['user_id'];
$notification_id = $_POST['notification_id'];
$conn = get_db_connection();

try {
    // Update the notification to mark it as read, ensuring it belongs to the current user
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response['success'] = true;
        } else {
            $response['error'] = 'Notification not found or already marked as read, or does not belong to user.';
        }
    } else {
        $response['error'] = 'Failed to update notification status: ' . htmlspecialchars($stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    $response['error'] = 'Database error: ' . $e->getMessage();
} finally {
    $conn->close();
}

echo json_encode($response);
?>
