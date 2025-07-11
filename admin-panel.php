<?php
// admin-panel.php
session_start();

// Include the database connection file
include_once dirname(__FILE__) . '/db_connection.php'; 

// Check if user is logged in and is an admin or moderator
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'moderator')) {
    header('Location: login-register.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$organization_name = $_SESSION['organization_name'];

$message = ''; // To store success or error messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying it
}

$current_view = isset($_GET['view']) ? $_GET['view'] : 'dashboard'; // Default view is dashboard

// Determine if the current page being loaded is reports.php, for sidebar highlighting purposes
$is_reports_page_active = false; // By default, admin-panel.php is not reports.php

$conn = get_db_connection(); // Establish database connection once at the beginning

// Function to insert a notification into the database
function insert_notification($conn, $user_id, $type, $message) {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $type, $message);
        if (!$stmt->execute()) {
            error_log("SahaniShare Notification Error: Failed to execute notification insert: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("SahaniShare Notification Error: Failed to prepare notification insert: " . $conn->error);
    }
}

// --- Handle User Actions (Approve, Reject, Deactivate, Activate, Document Verify/Unverify, DELETE) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_action'])) {
    $user_id_to_act_on = $_POST['user_id'];
    $action = $_POST['user_action'];
    $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null; // Get rejection reason

    $stmt = null;
    try {
        // Fetch user's details for notification and approval checks (if not deleting)
        $user_org_name_for_notification = null;
        $user_role_to_check = null;
        $user_doc_path = null;
        $is_doc_verified = null;

        if ($action !== 'delete') { // No need to fetch details if we are deleting the user
            $stmt_check_user_details = $conn->prepare("SELECT role, document_path, document_verified, organization_name FROM users WHERE id = ?");
            if (!$stmt_check_user_details) {
                throw new Exception("Prepare failed for user details check: " . $conn->error);
            }
            $stmt_check_user_details->bind_param("i", $user_id_to_act_on);
            if (!$stmt_check_user_details->execute()) {
                throw new Exception("Execute failed for user details check: " . $stmt_check_user_details->error);
            }
            $stmt_check_user_details->bind_result($user_role_to_check, $user_doc_path, $is_doc_verified, $user_org_name_for_notification);
            $stmt_check_user_details->fetch();
            $stmt_check_user_details->close();

            if (!$user_org_name_for_notification) { // User not found or error fetching details
                $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error: User not found or details could not be retrieved.</div>';
            }
        }

        switch ($action) {
            case 'approve':
                // Only admin can approve.
                if ($user_role === 'admin') {
                    $can_approve = true;
                    // For recipients, document verification is MANDATORY for approval.
                    if ($user_role_to_check === 'recipient' && !$is_doc_verified) {
                        $can_approve = false;
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Cannot approve Recipient: Documentation is not yet verified. Please verify the document first.</div>';
                    } 
                    // For donors, if they uploaded a document, it should be verified before approval.
                    elseif ($user_role_to_check === 'donor' && !empty($user_doc_path) && !$is_doc_verified) {
                        $can_approve = false;
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Cannot approve Donor: Document was uploaded but is not yet verified. Please verify the document first.</div>';
                    }

                    if ($can_approve) {
                        $stmt = $conn->prepare("UPDATE users SET status = 'active', rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?"); // Clear rejection reason on approval
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        $stmt->bind_param("i", $user_id_to_act_on);
                        if (!$stmt->execute()) {
                            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to approve user: ' . htmlspecialchars($stmt->error) . '</div>';
                        } else {
                            $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">User account approved successfully!</div>';
                            // Insert account approval notification for the user
                            insert_notification($conn, $user_id_to_act_on, 'account_approved', 'Your account for ' . htmlspecialchars($user_org_name_for_notification) . ' has been approved. Welcome to SahaniShare!');
                        }
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can approve users.</div>';
                }
                break;
            case 'reject':
                 // Both admin and moderator can reject.
                 if (empty($rejection_reason)) {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Rejection reason cannot be empty.</div>';
                 } else {
                    $stmt = $conn->prepare("UPDATE users SET status = 'rejected', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param("si", $rejection_reason, $user_id_to_act_on);
                    if (!$stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to reject user: ' . htmlspecialchars($stmt->error) . '</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">User account rejected. Reason: ' . htmlspecialchars($rejection_reason) . '</div>';
                        // Insert account rejection notification for the user
                        insert_notification($conn, $user_id_to_act_on, 'account_rejected', 'Your account for ' . htmlspecialchars($user_org_name_for_notification) . ' has been rejected. Reason: ' . htmlspecialchars($rejection_reason));
                    }
                 }
                break;
            case 'deactivate':
                // Only admin can deactivate
                if ($user_role === 'admin') {
                    $stmt = $conn->prepare("UPDATE users SET status = 'inactive', rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?"); // Clear reason
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param("i", $user_id_to_act_on);
                    if (!$stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to deactivate user: ' . htmlspecialchars($stmt->error) . '</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">User account deactivated.</div>';
                        insert_notification($conn, $user_id_to_act_on, 'account_deactivated', 'Your account for ' . htmlspecialchars($user_org_name_for_notification) . ' has been deactivated. Please contact support if you believe this is an error.');
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can deactivate users.</div>';
                }
                break;
            case 'activate':
                // Only admin can activate an inactive account
                if ($user_role === 'admin') {
                    $stmt = $conn->prepare("UPDATE users SET status = 'active', rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?"); // Clear reason
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param("i", $user_id_to_act_on);
                    if (!$stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to reactivate user: ' . htmlspecialchars($stmt->error) . '</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">User account reactivated.</div>';
                        insert_notification($conn, $user_id_to_act_on, 'account_activated', 'Your account for ' . htmlspecialchars($user_org_name_for_notification) . ' has been reactivated. You can now log in.');
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can activate users.</div>';
                }
                break;
            case 'verify_document':
                if ($user_role === 'admin') {
                    $stmt = $conn->prepare("UPDATE users SET document_verified = TRUE, rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?"); // Clear rejection reason on verification
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param("i", $user_id_to_act_on);
                    if (!$stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to verify document: ' . htmlspecialchars($stmt->error) . '</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">User document marked as verified.</div>';
                        insert_notification($conn, $user_id_to_act_on, 'document_verified', 'Your organization document for ' . htmlspecialchars($user_org_name_for_notification) . ' has been verified.');
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can verify documents.</div>';
                }
                break;
            case 'unverify_document':
                if ($user_role === 'admin') {
                    $stmt = $conn->prepare("UPDATE users SET document_verified = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param("i", $user_id_to_act_on);
                    if (!$stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to unverify document: ' . htmlspecialchars($stmt->error) . '</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">User document marked as unverified.</div>';
                        insert_notification($conn, $user_id_to_act_on, 'document_unverified', 'Your organization document for ' . htmlspecialchars($user_org_name_for_notification) . ' has been marked as unverified. Please review and re-upload if necessary.');
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can unverify documents.</div>';
                }
                break;
            case 'delete':
                if ($user_role === 'admin') {
                    // Prevent admin from deleting themselves
                    if ($user_id_to_act_on == $_SESSION['user_id']) {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">You cannot delete your own admin account.</div>';
                        break;
                    }

                    // Fetch organization name for message before deletion
                    $stmt_get_org_name = $conn->prepare("SELECT organization_name FROM users WHERE id = ?");
                    if ($stmt_get_org_name) {
                        $stmt_get_org_name->bind_param("i", $user_id_to_act_on);
                        $stmt_get_org_name->execute();
                        $stmt_get_org_name->bind_result($deleted_org_name);
                        $stmt_get_org_name->fetch();
                        $stmt_get_org_name->close();
                    } else {
                        $deleted_org_name = "a user"; // Fallback
                    }

                    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception("Prepare failed for user deletion: " . $conn->error);
                    }
                    $stmt->bind_param("i", $user_id_to_act_on);
                    if (!$stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to delete user: ' . htmlspecialchars($stmt->error) . '</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">User account for ' . htmlspecialchars($deleted_org_name) . ' deleted successfully!</div>';
                        // No notification for deleted user, as their account is gone.
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can delete users.</div>';
                }
                break;
            default:
                $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid user action.</div>';
                break;
        }
    } catch (Exception $e) {
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database error during user action: ' . htmlspecialchars($e->getMessage()) . '</div>';
    } finally {
        if ($stmt) $stmt->close();
    }
    // Always redirect after POST to prevent form resubmission and ensure fresh data fetch
    header('Location: admin-panel.php?view=' . $current_view);
    exit();
}

// --- Handle Donation Actions (Approve, Reject Donation) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['donation_action'])) {
    $donation_id_to_act_on = $_POST['donation_id'];
    $action = $_POST['donation_action'];
    $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null; // Get rejection reason for donations

    $stmt = null;
    try {
        // First, get the donor_id and donation description for the notification
        $stmt_get_donation_details = $conn->prepare("SELECT donor_id, description FROM donations WHERE id = ?");
        if (!$stmt_get_donation_details) {
            throw new Exception("Prepare failed for donation details: " . $conn->error);
        }
        $stmt_get_donation_details->bind_param("i", $donation_id_to_act_on);
        $stmt_get_donation_details->execute();
        $stmt_get_donation_details->bind_result($donor_id_for_notification, $donation_description_for_notification);
        $stmt_get_donation_details->fetch();
        $stmt_get_donation_details->close();

        if (!$donor_id_for_notification) {
            throw new Exception("Donor ID not found for donation ID: " . $donation_id_to_act_on);
        }

        switch ($action) {
            case 'approve_donation':
                $stmt = $conn->prepare("UPDATE donations SET status = 'approved', rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?"); // Clear reason
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("i", $donation_id_to_act_on);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to approve donation: " . htmlspecialchars($stmt->error));
                }
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Donation approved successfully!</div>';
                // Insert donation approval notification for the donor
                insert_notification($conn, $donor_id_for_notification, 'donation_approved', 'Your donation of "' . htmlspecialchars($donation_description_for_notification) . '" has been approved!');
                break;
            case 'reject_donation':
                if (empty($rejection_reason)) {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Rejection reason cannot be empty.</div>';
                } else {
                    $stmt = $conn->prepare("UPDATE donations SET status = 'rejected', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param("si", $rejection_reason, $donation_id_to_act_on);
                    if (!$stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to reject donation: ' . htmlspecialchars($stmt->error) . '</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Donation rejected. Reason: ' . htmlspecialchars($rejection_reason) . '</div>';
                        // Insert donation rejection notification for the donor
                        insert_notification($conn, $donor_id_for_notification, 'donation_rejected', 'Your donation of "' . htmlspecialchars($donation_description_for_notification) . '" was rejected. Reason: ' . htmlspecialchars($rejection_reason));
                    }
                 }
                break;
            default:
                $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid donation action.</div>';
                break;
        }
    } catch (Exception $e) {
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database error during donation action: ' . htmlspecialchars($e->getMessage()) . '</div>';
    } finally {
        if ($stmt) $stmt->close();
    }
    header('Location: admin-panel.php?view=' . $current_view); // Redirect to donations view
    exit();
}

// --- Fetch Data Based on Current View ---
$pending_users = [];
$active_users = [];
$inactive_users = [];
$rejected_users = [];
$pending_donations = [];
$approved_donations = [];

error_log("SahaniShare Debug: Starting user data fetch for view: " . $current_view);

// Fetch ALL users with their document details AND NGO Type
$stmt_all_users = $conn->prepare("
    SELECT 
        u.id, 
        u.organization_name, 
        u.email, 
        u.role, 
        u.status, 
        u.created_at, 
        u.rejection_reason, 
        u.document_path, 
        u.document_verified,
        nt.name AS ngo_type_name -- Fetch NGO type name
    FROM 
        users u
    LEFT JOIN 
        ngo_types nt ON u.ngo_type_id = nt.id -- Join with ngo_types table
    ORDER BY 
        u.created_at ASC
");
if (!$stmt_all_users) { 
    error_log("SahaniShare DB Error: Prepare failed for all users: " . $conn->error);
    // Set a user-facing error message if this critical query fails
    $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database error: Could not prepare user data query.</div>';
} else {
    if (!$stmt_all_users->execute()) { 
        error_log("SahaniShare DB Error: Execute failed for all users: " . $stmt_all_users->error);
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database error: Could not execute user data query.</div>';
    } else {
        $result_all_users = $stmt_all_users->get_result();
        if (!$result_all_users) { 
            error_log("SahaniShare DB Error: Get result failed for all users: " . $conn->error);
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database error: Could not retrieve user data results.</div>';
        } else {
            while ($row = $result_all_users->fetch_assoc()) {
                // Populate the specific user arrays for display
                if ($row['status'] === 'pending') $pending_users[] = $row;
                elseif ($row['status'] === 'active') $active_users[] = $row;
                elseif ($row['status'] === 'inactive') $inactive_users[] = $row;
                elseif ($row['status'] === 'rejected') $rejected_users[] = $row;
            }
            error_log("SahaniShare Debug: User data fetched. Pending: " . count($pending_users) . ", Active: " . count($active_users));
        }
    }
    $stmt_all_users->close();
}


// Fetch donations by status - NOW INCLUDING 'd.status' AND 'd.rejection_reason' AND category_name
$stmt_donations = $conn->prepare("
    SELECT 
        d.id, 
        d.description, 
        d.quantity, 
        d.unit, 
        d.expiry_time, 
        d.status, 
        d.rejection_reason, 
        u.organization_name as donor_org, 
        d.created_at,
        dc.name AS category_name -- Fetch category name
    FROM 
        donations d 
    JOIN 
        users u ON d.donor_id = u.id 
    LEFT JOIN
        donation_categories dc ON d.category_id = dc.id -- Join with donation_categories table
    ORDER BY 
        d.created_at ASC
");
if (!$stmt_donations) { 
    error_log("SahaniShare DB Error: Prepare failed for donations: " . $conn->error);
    // Optionally set a user-facing message here if this is critical
} else {
    if (!$stmt_donations->execute()) { 
        error_log("SahaniShare DB Error: Execute failed for donations: " . $stmt_donations->error);
        // Optionally set a user-facing message here if this is critical
    } else {
        $result_donations = $stmt_donations->get_result();
        if (!$result_donations) { 
            error_log("SahaniShare DB Error: Get result failed for donations: " . $conn->error);
            // Optionally set a user-facing message here if this is critical
        } else {
            while ($row = $result_donations->fetch_assoc()) {
                if ($row['status'] === 'pending') $pending_donations[] = $row;
                elseif ($row['status'] === 'approved') $approved_donations[] = $row;
            }
        }
    }
    $stmt_donations->close();
}


// Close the main connection before rendering HTML
if ($conn) {
    $conn->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Admin Panel</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts: Inter for body, Montserrat for headings -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Link to external style.css -->
    <link rel="stylesheet" href="style.css">
    <style>
        /* Define custom colors here to match your preferred aesthetic */
        :root {
            --primary-green: #A7D397; /* Your preferred lighter green */
            --primary-green-dark: #8bbd78; /* A darker shade for hover states */
            --neutral-dark: #333; /* From your original style.css, assuming it's a dark text color */
            --accent-orange: #FF8C00; /* From your original style.css, assuming it's an accent color */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6; /* bg-gray-100 */
            color: #374151; /* text-gray-800 */
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .sidebar {
            width: 250px;
            background-color: var(--primary-green); /* primary-green */
            color: white;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            min-height: 100vh; /* Ensure it takes full height */
        }
        .main-content {
            flex-grow: 1;
            padding: 1rem 2rem; /* p-4 md:p-8 */
            margin-left: 250px; /* Offset for sidebar */
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                min-height: auto; /* Reset for mobile */
            }
            .main-content {
                margin-left: 0;
            }
        }
        /* Card styling */
        .card {
            background-color: white;
            border-radius: 0.5rem; /* rounded-lg */
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06); /* shadow */
            padding: 1.5rem; /* p-6 */
        }
        /* Status tags for users and donations */
        .status-tag {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-pending { background-color: #FFEDD5; color: #9A3412; } /* bg-orange-100 text-orange-800 */
        .status-approved { background-color: #D1FAE5; color: #065F46; } /* bg-green-100 text-green-800 */
        .status-rejected { background-color: #FEE2E2; color: #991B1B; } /* bg-red-100 text-red-800 */
        .status-inactive { background-color: #E5E7EB; color: #4B5563; } /* bg-gray-100 text-gray-800 */
        .status-fulfilled { background-color: #059669; color: white; } /* bg-green-600 text-white */
        
        /* Document Verification Status */
        .doc-verified-true { color: #059669; } /* text-green-600 */
        .doc-verified-false { color: #EF4444; } /* text-red-500 */

        /* Modal Specific Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
            align-items: center; /* Center vertically via flex */
            justify-content: center; /* Center horizontally via flex */
            padding: 1rem; /* Add some padding for small screens */
        }
        .modal-content {
            background-color: #fefefe;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 500px;
            position: relative;
            animation: fadeIn 0.3s ease-out; /* Simple fade-in animation */
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .close-button {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        /* Ensure specific elements use the custom green */
        .bg-primary-green { background-color: var(--primary-green); }
        .hover\:bg-primary-green-dark:hover { background-color: var(--primary-green-dark); }
        .text-primary-green { color: var(--primary-green); }
        .hover\:text-primary-green:hover { color: var(--primary-green); }
        .focus\:ring-primary-green:focus { --tw-ring-color: var(--primary-green); }
        .focus\:border-primary-green:focus { border-color: var(--primary-green); }
        .text-accent-orange { color: var(--accent-orange); }
        .btn-primary { /* For the general purpose primary button */
            background-color: var(--primary-green);
            color: white;
            @apply px-4 py-2 rounded-md hover:bg-primary-green-dark transition-colors;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col md:flex-row">

    <!-- Sidebar Navigation -->
    <aside class="sidebar bg-primary-green text-white flex flex-col p-6 shadow-lg md:min-h-screen">
        <div class="text-3xl font-bold mb-8 text-center">
            <i class="fas fa-tools"></i> Admin Panel
        </div>
        <nav class="flex-grow">
            <ul class="space-y-4">
                <li>
                    <a href="admin-panel.php?view=dashboard" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors <?php echo ($current_view === 'dashboard' ? 'bg-primary-green-dark' : ''); ?>">
                        <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="admin-panel.php?view=users" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors <?php echo ($current_view === 'users' ? 'bg-primary-green-dark' : ''); ?>">
                        <i class="fas fa-users mr-3"></i> Manage Users
                    </a>
                </li>
                <li>
                    <a href="admin-panel.php?view=donations" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors <?php echo ($current_view === 'donations' ? 'bg-primary-green-dark' : ''); ?>">
                        <i class="fas fa-boxes mr-3"></i> Manage Donations
                    </a>
                </li>
                <li>
                    <a href="reports.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors <?php echo (basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'bg-primary-green-dark' : ''); ?>">
                        <i class="fas fa-chart-bar mr-3"></i> Reports
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors <?php echo (basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'bg-primary-green-dark' : ''); ?>">
                        <i class="fas fa-cogs mr-3"></i> Settings
                    </a>
                </li>
            </ul>
        </nav>
        <div class="mt-8 text-center">
            <p class="text-sm font-light">Logged in as:</p>
            <p class="font-medium"><?php echo htmlspecialchars($organization_name); ?></p>
            <p class="text-xs italic">(<?php echo htmlspecialchars(ucfirst($user_role)); ?>)</p>
            <a href="logout.php" class="mt-4 inline-block bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md transition-colors text-sm">
                <i class="fas fa-sign-out-alt mr-2"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="main-content flex-grow p-4 md:p-8">
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">
            <?php
            switch ($current_view) {
                case 'dashboard': echo 'Admin Dashboard'; break;
                case 'users': echo 'Manage Users'; break;
                case 'donations': echo 'Manage Donations'; break;
                default: echo 'Admin Panel'; break;
            }
            ?>
        </h1>

        <?php echo $message; // Display messages from session ?>

        <?php if ($current_view === 'dashboard'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Quick Stats -->
                <div class="card p-6 flex flex-col items-center justify-center">
                    <i class="fas fa-user-plus text-5xl text-primary-green mb-3"></i>
                    <p class="text-gray-600 text-lg">Pending Users</p>
                    <p class="text-4xl font-bold text-neutral-dark"><?php echo count((array)$pending_users); ?></p>
                    <a href="admin-panel.php?view=users&filter=pending" class="text-primary-green hover:underline mt-2">View Details</a>
                </div>
                <div class="card p-6 flex flex-col items-center justify-center">
                    <i class="fas fa-box-open text-5xl text-accent-orange mb-3"></i>
                    <p class="text-gray-600 text-lg">Pending Donations</p>
                    <p class="text-4xl font-bold text-neutral-dark"><?php echo count((array)$pending_donations); ?></p>
                    <a href="admin-panel.php?view=donations&filter=pending" class="text-primary-green hover:underline mt-2">View Details</a>
                </div>
                <div class="card p-6 flex flex-col items-center justify-center">
                    <i class="fas fa-users-cog text-5xl text-neutral-dark mb-3"></i>
                    <p class="text-gray-600 text-lg">Total Active Users</p>
                    <p class="text-4xl font-bold text-neutral-dark"><?php echo count((array)$active_users); ?></p>
                    <a href="admin-panel.php?view=users" class="text-primary-green hover:underline mt-2">View Details</a>
                </div>
            </div>

            <!-- Recent Pending Users (Dashboard View) -->
            <div class="card p-6 mt-8">
                <h3 class="text-xl font-semibold text-neutral-dark mb-4">Recent Pending Users</h3>
                <?php if (empty($pending_users)): ?>
                    <p class="text-gray-600 text-center">No pending user registrations.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                            <thead class="bg-gray-200">
                                <tr>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Organization</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Email</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Role</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Status</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">NGO Type</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Documentation</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Reason / Created On</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($pending_users, 0, 5) as $user): // Show max 5 ?>
                                    <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($user['organization_name']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                                        <td class="py-3 px-4 text-sm">
                                            <span class="status-tag <?php
                                                $status_class = '';
                                                switch ($user['status']) {
                                                    case 'pending': $status_class = 'status-pending'; break;
                                                    case 'active': $status_class = 'status-approved'; break;
                                                    case 'inactive': $status_class = 'status-inactive'; break;
                                                    case 'rejected': $status_class = 'status-rejected'; break;
                                                }
                                                echo $status_class;
                                            ?>"><?php echo htmlspecialchars(ucfirst($user['status'])); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-gray-800">
                                            <?php echo htmlspecialchars($user['ngo_type_name'] ?? 'N/A'); ?>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-center">
                                            <?php if (!empty($user['document_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($user['document_path']); ?>" target="_blank" class="text-blue-500 hover:underline">
                                                    <i class="fas fa-file-alt mr-1"></i> View Doc
                                                </a>
                                                <?php if ($user['document_verified']): ?>
                                                    <i class="fas fa-check-circle text-green-600 ml-1" title="Document Verified"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times-circle text-red-500 ml-1" title="Document Not Verified"></i>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-gray-500">No Document</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-sm">
                                            <?php if ($user['status'] === 'rejected' && !empty($user['rejection_reason'])): ?>
                                                <span class="font-semibold">Reason:</span> <?php echo htmlspecialchars($user['rejection_reason']); ?><br>
                                                <span class="text-xs text-gray-500"><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></span>
                                            <?php else: ?>
                                                <?php echo date('Y-m-d', strtotime($user['created_at'])); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if ($user['status'] === 'pending'): ?>
                                                    <?php if ($user_role === 'admin'): ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="approve" class="bg-green-500 text-white px-3 py-1 rounded-md hover:bg-green-600 transition-colors text-xs">Approve</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <button type="button" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs reject-user-btn"
                                                        data-user-id="<?php echo $user['id']; ?>"
                                                        data-organization-name="<?php echo htmlspecialchars($user['organization_name']); ?>">
                                                        Reject
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php // Document Verification/Unverification buttons (for admin, if document exists) ?>
                                                <?php if ($user_role === 'admin' && !empty($user['document_path'])): ?>
                                                    <?php if ($user['document_verified']): ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="unverify_document" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 transition-colors text-xs">Unverify Doc</button>
                                                            </form>
                                                    <?php else: ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="verify_document" class="bg-blue-500 text-white px-3 py-1 rounded-md hover:bg-blue-600 transition-colors text-xs">Verify Doc</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php // Deactivate/Activate buttons (for admin) ?>
                                                <?php if ($user_role === 'admin'): ?>
                                                    <?php if ($user['status'] === 'active'): ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="deactivate" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 transition-colors text-xs">Deactivate</button>
                                                        </form>
                                                    <?php elseif ($user['status'] === 'inactive' || $user['status'] === 'rejected'): ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="activate" class="bg-blue-500 text-white px-3 py-1 rounded-md hover:bg-blue-600 transition-colors text-xs">Activate</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($user['status'] === 'rejected'): // Allow re-reject for admin to change reason ?>
                                                        <button type="button" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs reject-user-btn"
                                                            data-user-id="<?php echo $user['id']; ?>"
                                                            data-organization-name="<?php echo htmlspecialchars($user['organization_name']); ?>"
                                                            data-current-reason="<?php echo htmlspecialchars($user['rejection_reason'] ?? ''); ?>">
                                                            Re-reject
                                                        </button>
                                                    <?php endif; ?>
                                                    <!-- DELETE BUTTON -->
                                                    <button type="button" class="bg-gray-600 text-white px-3 py-1 rounded-md hover:bg-gray-700 transition-colors text-xs delete-user-btn"
                                                        data-user-id="<?php echo $user['id']; ?>"
                                                        data-organization-name="<?php echo htmlspecialchars($user['organization_name']); ?>">
                                                        Delete
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($current_view === 'users'): // This is the "Manage Users" view ?>
            <div class="card p-6">
                <h3 class="text-xl font-semibold text-neutral-dark mb-4">All Users</h3>
                <div class="mb-6 flex flex-wrap gap-4">
                    <a href="admin-panel.php?view=users&filter=all" class="px-4 py-2 rounded-md <?php echo (!isset($_GET['filter']) || $_GET['filter'] === 'all' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">All</a>
                    <a href="admin-panel.php?view=users&filter=pending" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'pending' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Pending (<?php echo count((array)$pending_users); ?>)</a>
                    <a href="admin-panel.php?view=users&filter=active" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'active' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Active (<?php echo count((array)$active_users); ?>)</a>
                    <a href="admin-panel.php?view=users&filter=inactive" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'inactive' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Inactive (<?php echo count((array)$inactive_users); ?>)</a>
                    <a href="admin-panel.php?view=users&filter=rejected" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'rejected' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Rejected (<?php echo count((array)$rejected_users); ?>)</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                        <thead class="bg-gray-200">
                            <tr>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">ID</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Organization</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Email</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Role</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Status</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">NGO Type</th> <!-- New Column -->
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Documentation</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Reason / Created On</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $filter = $_GET['filter'] ?? 'all';
                            $users_to_display = [];
                            if ($filter === 'all') {
                                $users_to_display = array_merge($pending_users, $active_users, $inactive_users, $rejected_users);
                                usort($users_to_display, function($a, $b) {
                                    return strtotime($b['created_at']) - strtotime($a['created_at']);
                                });
                            } elseif ($filter === 'pending') {
                                $users_to_display = $pending_users;
                            } elseif ($filter === 'active') {
                                $users_to_display = $active_users;
                            } elseif ($filter === 'inactive') {
                                $users_to_display = $inactive_users;
                            } elseif ($filter === 'rejected') {
                                $users_to_display = $rejected_users;
                            }
                            ?>
                            <?php if (empty($users_to_display)): ?>
                                <tr><td colspan="9" class="py-4 text-center text-gray-600">No users found for this filter.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users_to_display as $user): ?>
                                    <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($user['organization_name']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                                        <td class="py-3 px-4 text-sm">
                                            <span class="status-tag <?php
                                                $status_class = '';
                                                switch ($user['status']) {
                                                    case 'pending': $status_class = 'status-pending'; break;
                                                    case 'active': $status_class = 'status-approved'; break;
                                                    case 'inactive': $status_class = 'status-inactive'; break;
                                                    case 'rejected': $status_class = 'status-rejected'; break;
                                                }
                                                echo $status_class;
                                            ?>"><?php echo htmlspecialchars(ucfirst($user['status'])); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-gray-800">
                                            <?php echo htmlspecialchars($user['ngo_type_name'] ?? 'N/A'); ?>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-center">
                                            <?php if (!empty($user['document_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($user['document_path']); ?>" target="_blank" class="text-blue-500 hover:underline">
                                                    <i class="fas fa-file-alt mr-1"></i> View Doc
                                                </a>
                                                <?php if ($user['document_verified']): ?>
                                                    <i class="fas fa-check-circle text-green-600 ml-1" title="Document Verified"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times-circle text-red-500 ml-1" title="Document Not Verified"></i>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-gray-500">No Document</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-sm">
                                            <?php if ($user['status'] === 'rejected' && !empty($user['rejection_reason'])): ?>
                                                <span class="font-semibold">Reason:</span> <?php echo htmlspecialchars($user['rejection_reason']); ?><br>
                                                <span class="text-xs text-gray-500"><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></span>
                                            <?php else: ?>
                                                <?php echo date('Y-m-d', strtotime($user['created_at'])); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if ($user['status'] === 'pending'): ?>
                                                    <?php if ($user_role === 'admin'): ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="approve" class="bg-green-500 text-white px-3 py-1 rounded-md hover:bg-green-600 transition-colors text-xs">Approve</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <button type="button" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs reject-user-btn"
                                                        data-user-id="<?php echo $user['id']; ?>"
                                                        data-organization-name="<?php echo htmlspecialchars($user['organization_name']); ?>">
                                                        Reject
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php // Document Verification/Unverification buttons (for admin, if document exists) ?>
                                                <?php if ($user_role === 'admin' && !empty($user['document_path'])): ?>
                                                    <?php if ($user['document_verified']): ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="unverify_document" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 transition-colors text-xs">Unverify Doc</button>
                                                            </form>
                                                    <?php else: ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="verify_document" class="bg-blue-500 text-white px-3 py-1 rounded-md hover:bg-blue-600 transition-colors text-xs">Verify Doc</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php // Deactivate/Activate buttons (for admin) ?>
                                                <?php if ($user_role === 'admin'): ?>
                                                    <?php if ($user['status'] === 'active'): ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="deactivate" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 transition-colors text-xs">Deactivate</button>
                                                        </form>
                                                    <?php elseif ($user['status'] === 'inactive' || $user['status'] === 'rejected'): ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="activate" class="bg-blue-500 text-white px-3 py-1 rounded-md hover:bg-blue-600 transition-colors text-xs">Activate</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($user['status'] === 'rejected'): // Allow re-reject for admin to change reason ?>
                                                        <button type="button" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs reject-user-btn"
                                                            data-user-id="<?php echo $user['id']; ?>"
                                                            data-organization-name="<?php echo htmlspecialchars($user['organization_name']); ?>"
                                                            data-current-reason="<?php echo htmlspecialchars($user['rejection_reason'] ?? ''); ?>">
                                                            Re-reject
                                                        </button>
                                                    <?php endif; ?>
                                                    <!-- DELETE BUTTON -->
                                                    <button type="button" class="bg-gray-600 text-white px-3 py-1 rounded-md hover:bg-gray-700 transition-colors text-xs delete-user-btn"
                                                        data-user-id="<?php echo $user['id']; ?>"
                                                        data-organization-name="<?php echo htmlspecialchars($user['organization_name']); ?>">
                                                        Delete
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Reject User Modal -->
    <div id="reject-user-modal" class="modal">
        <div class="modal-content card">
            <span class="close-button" id="close-reject-user-modal">&times;</span>
            <h3 class="text-xl font-semibold text-neutral-dark mb-4">Reject User Account</h3>
            <p class="mb-4">You are rejecting the account for: <span id="reject-user-org-name" class="font-bold text-primary-green"></span></p>
            <form method="POST" action="admin-panel.php?view=users" class="space-y-4">
                <input type="hidden" name="user_id" id="reject-user-id">
                <input type="hidden" name="user_action" value="reject">
                <div>
                    <label for="rejection-reason" class="block text-sm font-medium text-gray-700 mb-1">Reason for Rejection</label>
                    <textarea id="rejection-reason" name="rejection_reason" rows="4" class="w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-primary-green focus:border-primary-green" placeholder="e.g., Incomplete documentation, Failed verification check, Not a valid organization type" required></textarea>
                </div>
                <button type="submit" class="btn-primary bg-red-500 hover:bg-red-600">Confirm Rejection</button>
            </form>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div id="delete-user-modal" class="modal">
        <div class="modal-content card">
            <span class="close-button" id="close-delete-user-modal">&times;</span>
            <h3 class="text-xl font-semibold text-neutral-dark mb-4 text-red-600">Confirm User Deletion</h3>
            <p class="mb-4 text-gray-700">Are you sure you want to permanently delete the account for: <span id="delete-user-org-name" class="font-bold text-red-700"></span>?</p>
            <p class="text-sm text-red-500 mb-6">This action cannot be undone. All associated data (donations, requests, etc.) will also be removed if your database is configured with cascading deletes.</p>
            <form method="POST" action="admin-panel.php?view=users" class="space-y-4">
                <input type="hidden" name="user_id" id="delete-user-id">
                <input type="hidden" name="user_action" value="delete">
                <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700">Yes, Delete This User</button>
                <button type="button" id="cancel-delete-user" class="btn-primary bg-gray-400 hover:bg-gray-500">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Reject Donation Modal (for Admin Panel) -->
    <div id="reject-donation-modal" class="modal">
        <div class="modal-content card">
            <span class="close-button" id="close-reject-donation-modal">&times;</span>
            <h3 class="text-xl font-semibold text-neutral-dark mb-4">Reject Donation</h3>
            <p class="mb-4">You are rejecting donation: <span id="reject-donation-desc" class="font-bold text-primary-green"></span></p>
            <form method="POST" action="admin-panel.php?view=donations" class="space-y-4">
                <input type="hidden" name="donation_id" id="reject-donation-id">
                <input type="hidden" name="donation_action" value="reject_donation">
                <div>
                    <label for="donation-rejection-reason" class="block text-sm font-medium text-gray-700 mb-1">Reason for Rejection</label>
                    <textarea id="donation-rejection-reason" name="rejection_reason" rows="4" class="w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-primary-green focus:border-primary-green" placeholder="e.g., Expired, Damaged, Incorrect quantity, Not suitable for distribution" required></textarea>
                </div>
                <button type="submit" class="btn-primary bg-red-500 hover:bg-red-600">Confirm Rejection</button>
            </form>
        </div>
    </div>

    <script>
        // Mobile Menu Toggle Logic
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');

        if (mobileMenuButton) {
            mobileMenuButton.addEventListener('click', () => {
                mobileMenu.classList.add('mobile-menu-open');
                mobileMenuOverlay.classList.remove('hidden');
            });
        }

        if (closeMobileMenuButton) {
            closeMobileMenuButton.addEventListener('click', () => {
                mobileMenu.classList.remove('mobile-menu-open');
                mobileMenuOverlay.classList.add('hidden');
            });
        }
        
        if (mobileMenuOverlay) {
            mobileMenuOverlay.addEventListener('click', (event) => {
                if (event.target === mobileMenuOverlay) {
                    mobileMenu.classList.remove('mobile-menu-open');
                    mobileMenuOverlay.classList.add('hidden');
                }
            });
        }


        // Reject User Modal Logic
        const rejectUserModal = document.getElementById('reject-user-modal');
        const closeRejectUserModalButton = document.getElementById('close-reject-user-modal');
        const rejectUserButtons = document.querySelectorAll('.reject-user-btn');
        const rejectUserIdInput = document.getElementById('reject-user-id');
        const rejectUserOrgNameSpan = document.getElementById('reject-user-org-name');
        const rejectUserReasonTextarea = document.getElementById('rejection-reason');

        rejectUserButtons.forEach(button => {
            button.addEventListener('click', () => {
                const userId = button.dataset.userId;
                const organizationName = button.dataset.organizationName;
                const currentReason = button.dataset.currentReason || '';

                rejectUserIdInput.value = userId;
                rejectUserOrgNameSpan.textContent = organizationName;
                rejectUserReasonTextarea.value = currentReason;

                if (rejectUserModal) {
                    rejectUserModal.style.display = 'flex';
                }
            });
        });

        if (closeRejectUserModalButton) {
            closeRejectUserModalButton.addEventListener('click', () => {
                if (rejectUserModal) {
                    rejectUserModal.style.display = 'none';
                }
            });
        }
        
        if (rejectUserModal) {
            window.addEventListener('click', (event) => {
                if (event.target === rejectUserModal) {
                    rejectUserModal.style.display = 'none';
                }
            });
        }


        // Delete User Modal Logic
        const deleteUserModal = document.getElementById('delete-user-modal');
        const closeDeleteUserModalButton = document.getElementById('close-delete-user-modal');
        const cancelDeleteUserButton = document.getElementById('cancel-delete-user');
        const deleteUserButtons = document.querySelectorAll('.delete-user-btn');
        const deleteUserIdInput = document.getElementById('delete-user-id');
        const deleteUserOrgNameSpan = document.getElementById('delete-user-org-name');

        deleteUserButtons.forEach(button => {
            button.addEventListener('click', () => {
                const userId = button.dataset.userId;
                const organizationName = button.dataset.organizationName;

                deleteUserIdInput.value = userId;
                deleteUserOrgNameSpan.textContent = organizationName;

                if (deleteUserModal) {
                    deleteUserModal.style.display = 'flex';
                }
            });
        });

        if (closeDeleteUserModalButton) {
            closeDeleteUserModalButton.addEventListener('click', () => {
                if (deleteUserModal) {
                    deleteUserModal.style.display = 'none';
                }
            });
        }

        if (cancelDeleteUserButton) {
            cancelDeleteUserButton.addEventListener('click', () => {
                if (deleteUserModal) {
                    deleteUserModal.style.display = 'none';
                }
            });
        }
        
        if (deleteUserModal) {
            window.addEventListener('click', (event) => {
                if (event.target === deleteUserModal) {
                    deleteUserModal.style.display = 'none';
                }
            });
        }


        // Reject Donation Modal Logic (Admin Panel)
        const rejectDonationModal = document.getElementById('reject-donation-modal');
        const closeRejectDonationModalButton = document.getElementById('close-reject-donation-modal');
        const rejectDonationButtons = document.querySelectorAll('.reject-donation-btn');
        const rejectDonationIdInput = document.getElementById('reject-donation-id');
        const rejectDonationDescSpan = document.getElementById('reject-donation-desc');
        const rejectDonationReasonTextarea = document.getElementById('donation-rejection-reason');

        rejectDonationButtons.forEach(button => {
            button.addEventListener('click', () => {
                const donationId = button.dataset.donationId;
                const donationDesc = button.dataset.donationDesc;
                // const currentReason = button.dataset.currentReason || ''; // Uncomment if you add this data attribute

                rejectDonationIdInput.value = donationId;
                rejectDonationDescSpan.textContent = donationDesc;
                // rejectDonationReasonTextarea.value = currentReason; 

                if (rejectDonationModal) {
                    rejectDonationModal.style.display = 'flex';
                }
            });
        });

        if (closeRejectDonationModalButton) {
            closeRejectDonationModalButton.addEventListener('click', () => {
                if (rejectDonationModal) {
                    rejectDonationModal.style.display = 'none';
                }
            });
        }

        if (rejectDonationModal) {
            window.addEventListener('click', (event) => {
                if (event.target === rejectDonationModal) {
                    rejectDonationModal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
