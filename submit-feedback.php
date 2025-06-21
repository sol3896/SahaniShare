<?php
// submit-feedback.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

// Check if user is logged in and is a recipient
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'recipient' || $_SESSION['user_status'] !== 'active') {
    header('Location: login-register.php');
    exit();
}

// Ensure it's a POST request and feedback specific data is set
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $donation_id = $_POST['donation_id'];
    $recipient_id = $_SESSION['user_id']; // Use session recipient ID for security
    $rating = $_POST['rating'];
    $comment = trim($_POST['comment']);

    // Basic validation
    if (empty($donation_id) || empty($recipient_id) || !is_numeric($rating) || $rating < 1 || $rating > 5) {
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid feedback submission. Please provide a valid rating.</div>';
        header('Location: recipient-dashboard.php');
        exit();
    }

    $conn = get_db_connection();

    // Check if feedback already exists for this donation by this recipient
    $stmt_check = $conn->prepare("SELECT id FROM feedback WHERE donation_id = ? AND recipient_id = ?");
    $stmt_check->bind_param("ii", $donation_id, $recipient_id);
    $stmt_check->execute();
    $stmt_check->store_result();

    if ($stmt_check->num_rows > 0) {
        $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">You have already submitted feedback for this donation.</div>';
    } else {
        // Insert new feedback
        $stmt_insert = $conn->prepare("INSERT INTO feedback (donation_id, recipient_id, rating, comment) VALUES (?, ?, ?, ?)");
        $stmt_insert->bind_param("iiis", $donation_id, $recipient_id, $rating, $comment);

        if ($stmt_insert->execute()) {
            $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Thank you for your feedback!</div>';
            // Optional: Update donation/request status to indicate feedback received
            // E.g., update request status to 'completed' or 'feedback_given'
        } else {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error submitting feedback: ' . htmlspecialchars($stmt_insert->error) . '</div>';
        }
        $stmt_insert->close();
    }
    $stmt_check->close();
    $conn->close();

    header('Location: recipient-dashboard.php'); // Redirect back to recipient dashboard
    exit();
} else {
    // If accessed directly without POST data
    header('Location: recipient-dashboard.php');
    exit();
}
?>
