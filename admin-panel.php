<?php
// admin-panel.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

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

$conn = get_db_connection();

// --- Handle User Actions (Approve, Reject, Deactivate) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_action'])) {
    $user_id_to_act_on = $_POST['user_id'];
    $action = $_POST['user_action'];
    
    $stmt = null;
    try {
        switch ($action) {
            case 'approve':
                // Only admin can approve. Moderators can't directly approve, but can review.
                if ($user_role === 'admin') {
                    $stmt = $conn->prepare("UPDATE users SET status = 'active', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->bind_param("i", $user_id_to_act_on);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">User account approved successfully!</div>';
                        // Set a specific session message for the approved user to see on next login
                        $_SESSION['user_status_message_' . $user_id_to_act_on] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Welcome! Your account has been <strong>approved</strong> by an administrator. You can now log in and access your dashboard.</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to approve user: ' . htmlspecialchars($stmt->error) . '</div>';
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can approve users.</div>';
                }
                break;
            case 'reject':
                 // Both admin and moderator can reject.
                $stmt = $conn->prepare("UPDATE users SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("i", $user_id_to_act_on);
                if ($stmt->execute()) {
                    $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">User account rejected.</div>';
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to reject user: ' . htmlspecialchars($stmt->error) . '</div>';
                }
                break;
            case 'deactivate':
                // Only admin can deactivate
                if ($user_role === 'admin') {
                    $stmt = $conn->prepare("UPDATE users SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->bind_param("i", $user_id_to_act_on);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">User account deactivated.</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to deactivate user: ' . htmlspecialchars($stmt->error) . '</div>';
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can deactivate users.</div>';
                }
                break;
            case 'activate':
                // Only admin can activate an inactive account
                if ($user_role === 'admin') {
                    $stmt = $conn->prepare("UPDATE users SET status = 'active', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->bind_param("i", $user_id_to_act_on);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">User account reactivated.</div>';
                    } else {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to reactivate user: ' . htmlspecialchars($stmt->error) . '</div>';
                    }
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Permission denied: Only administrators can activate users.</div>';
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
        $conn->close();
    }
    header('Location: admin-panel.php?view=' . $current_view);
    exit();
}

// --- Handle Donation Actions (Approve, Reject Donation) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['donation_action'])) {
    $donation_id_to_act_on = $_POST['donation_id'];
    $action = $_POST['donation_action'];

    $conn = get_db_connection();
    $stmt = null;
    try {
        switch ($action) {
            case 'approve_donation':
                $stmt = $conn->prepare("UPDATE donations SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("i", $donation_id_to_act_on);
                if ($stmt->execute()) {
                    $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Donation approved successfully!</div>';
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to approve donation: ' . htmlspecialchars($stmt->error) . '</div>';
                }
                break;
            case 'reject_donation':
                $stmt = $conn->prepare("UPDATE donations SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("i", $donation_id_to_act_on);
                if ($stmt->execute()) {
                    $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Donation rejected.</div>';
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to reject donation: ' . htmlspecialchars($stmt->error) . '</div>';
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
        $conn->close();
    }
    header('Location: admin-panel.php?view=' . $current_view); // Redirect to donations view
    exit();
}

// Re-establish connection after POST processing if needed for GET requests
if (!isset($conn) || !$conn->ping()) {
    $conn = get_db_connection();
}

// --- Fetch Data Based on Current View ---
$pending_users = [];
$active_users = [];
$inactive_users = [];
$rejected_users = [];
$pending_donations = [];
$approved_donations = [];

if ($current_view === 'dashboard' || $current_view === 'users') {
    // Fetch users by status - NOW INCLUDING 'status' IN SELECT
    $stmt_pending_users = $conn->prepare("SELECT id, organization_name, email, role, status, created_at FROM users WHERE status = 'pending' ORDER BY created_at ASC");
    $stmt_pending_users->execute();
    $result_pending_users = $stmt_pending_users->get_result();
    while ($row = $result_pending_users->fetch_assoc()) {
        $pending_users[] = $row;
    }
    $stmt_pending_users->close();

    $stmt_active_users = $conn->prepare("SELECT id, organization_name, email, role, status, created_at FROM users WHERE status = 'active' ORDER BY created_at DESC");
    $stmt_active_users->execute();
    $result_active_users = $stmt_active_users->get_result();
    while ($row = $result_active_users->fetch_assoc()) {
        $active_users[] = $row;
    }
    $stmt_active_users->close();

    $stmt_inactive_users = $conn->prepare("SELECT id, organization_name, email, role, status, created_at FROM users WHERE status = 'inactive' ORDER BY created_at DESC");
    $stmt_inactive_users->execute();
    $result_inactive_users = $stmt_inactive_users->get_result();
    while ($row = $result_inactive_users->fetch_assoc()) {
        $inactive_users[] = $row;
    }
    $stmt_inactive_users->close();

    $stmt_rejected_users = $conn->prepare("SELECT id, organization_name, email, role, status, created_at FROM users WHERE status = 'rejected' ORDER BY created_at DESC");
    $stmt_rejected_users->execute();
    $result_rejected_users = $stmt_rejected_users->get_result();
    while ($row = $result_rejected_users->fetch_assoc()) {
        $rejected_users[] = $row;
    }
    $stmt_rejected_users->close();
}

if ($current_view === 'dashboard' || $current_view === 'donations') {
    // Fetch donations by status - NOW INCLUDING 'd.status' IN SELECT
    $stmt_pending_donations = $conn->prepare("SELECT d.id, d.description, d.quantity, d.unit, d.expiry_time, d.status, u.organization_name as donor_org, d.created_at FROM donations d JOIN users u ON d.donor_id = u.id WHERE d.status = 'pending' ORDER BY d.created_at ASC");
    $stmt_pending_donations->execute();
    $result_pending_donations = $stmt_pending_donations->get_result();
    while ($row = $result_pending_donations->fetch_assoc()) {
        $pending_donations[] = $row;
    }
    $stmt_pending_donations->close();

    $stmt_approved_donations = $conn->prepare("SELECT d.id, d.description, d.quantity, d.unit, d.expiry_time, d.status, u.organization_name as donor_org, d.created_at FROM donations d JOIN users u ON d.donor_id = u.id WHERE d.status = 'approved' ORDER BY d.created_at DESC");
    $stmt_approved_donations->execute();
    $result_approved_donations = $stmt_approved_donations->get_result();
    while ($row = $result_approved_donations->fetch_assoc()) {
        $approved_donations[] = $row;
    }
    $stmt_approved_donations->close();
}

$conn->close();
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
        body {
            font-family: 'Inter', sans-serif;
            @apply bg-gray-100 text-gray-800;
        }
        .sidebar {
            width: 250px;
            /* other styling */
        }
        .main-content {
            margin-left: 250px;
            /* other styling */
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
            }
        }
        /* Status tags for users and donations */
        .status-tag {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-pending { @apply bg-orange-100 text-orange-800; }
        .status-approved { @apply bg-green-100 text-green-800; }
        .status-rejected { @apply bg-red-100 text-red-800; }
        .status-inactive { @apply bg-gray-100 text-gray-800; } /* For users only */
        .status-fulfilled { @apply bg-green-600 text-white; } /* For donations only */
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
                    <a href="admin-panel.php?view=dashboard" class="flex items-center p-3 rounded-lg hover:bg-green-700 transition-colors <?php echo ($current_view === 'dashboard' ? 'bg-green-700' : ''); ?>">
                        <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="admin-panel.php?view=users" class="flex items-center p-3 rounded-lg hover:bg-green-700 transition-colors <?php echo ($current_view === 'users' ? 'bg-green-700' : ''); ?>">
                        <i class="fas fa-users mr-3"></i> Manage Users
                    </a>
                </li>
                <li>
                    <a href="admin-panel.php?view=donations" class="flex items-center p-3 rounded-lg hover:bg-green-700 transition-colors <?php echo ($current_view === 'donations' ? 'bg-green-700' : ''); ?>">
                        <i class="fas fa-boxes mr-3"></i> Manage Donations
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg hover:bg-green-700 transition-colors">
                        <i class="fas fa-chart-bar mr-3"></i> Reports
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg hover:bg-green-700 transition-colors">
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
                    <p class="text-4xl font-bold text-neutral-dark"><?php echo count($pending_users); ?></p>
                    <a href="admin-panel.php?view=users" class="text-primary-green hover:underline mt-2">View Details</a>
                </div>
                <div class="card p-6 flex flex-col items-center justify-center">
                    <i class="fas fa-box-open text-5xl text-accent-orange mb-3"></i>
                    <p class="text-gray-600 text-lg">Pending Donations</p>
                    <p class="text-4xl font-bold text-neutral-dark"><?php echo count($pending_donations); ?></p>
                    <a href="admin-panel.php?view=donations" class="text-primary-green hover:underline mt-2">View Details</a>
                </div>
                <div class="card p-6 flex flex-col items-center justify-center">
                    <i class="fas fa-users-cog text-5xl text-neutral-dark mb-3"></i>
                    <p class="text-gray-600 text-lg">Total Active Users</p>
                    <p class="text-4xl font-bold text-neutral-dark"><?php echo count($active_users); ?></p>
                    <a href="admin-panel.php?view=users" class="text-primary-green hover:underline mt-2">View Details</a>
                </div>
            </div>

            <!-- Recent Pending Users -->
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
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Registered</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($pending_users, 0, 5) as $user): // Show max 5 ?>
                                    <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($user['organization_name']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                        <td class="py-3 px-4 text-sm">
                                            <div class="flex space-x-2">
                                                <form method="POST" action="admin-panel.php?view=users">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" name="user_action" value="approve" class="bg-green-500 text-white px-3 py-1 rounded-md hover:bg-green-600 transition-colors text-xs">Approve</button>
                                                </form>
                                                <form method="POST" action="admin-panel.php?view=users">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" name="user_action" value="reject" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs">Reject</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($current_view === 'users'): ?>
            <div class="card p-6">
                <h3 class="text-xl font-semibold text-neutral-dark mb-4">All Users</h3>
                <div class="mb-6 flex flex-wrap gap-4">
                    <a href="admin-panel.php?view=users&filter=all" class="px-4 py-2 rounded-md <?php echo (!isset($_GET['filter']) || $_GET['filter'] === 'all' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">All</a>
                    <a href="admin-panel.php?view=users&filter=pending" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'pending' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Pending (<?php echo count($pending_users); ?>)</a>
                    <a href="admin-panel.php?view=users&filter=active" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'active' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Active (<?php echo count($active_users); ?>)</a>
                    <a href="admin-panel.php?view=users&filter=inactive" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'inactive' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Inactive (<?php echo count($inactive_users); ?>)</a>
                    <a href="admin-panel.php?view=users&filter=rejected" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'rejected' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Rejected (<?php echo count($rejected_users); ?>)</a>
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
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Registered On</th>
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
                                <tr><td colspan="7" class="py-4 text-center text-gray-600">No users found for this filter.</td></tr>
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
                                                // Accessing $user['status'] is now safe as it's fetched from DB
                                                switch ($user['status']) {
                                                    case 'pending': $status_class = 'status-pending'; break;
                                                    case 'active': $status_class = 'status-approved'; break;
                                                    case 'inactive': $status_class = 'status-inactive'; break;
                                                    case 'rejected': $status_class = 'status-rejected'; break;
                                                }
                                                echo $status_class;
                                            ?>"><?php echo htmlspecialchars(ucfirst($user['status'])); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                        <td class="py-3 px-4 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if ($user['status'] === 'pending'): ?>
                                                    <?php if ($user_role === 'admin'): // Only Admin can Approve ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="approve" class="bg-primary-green text-white px-3 py-1 rounded-md hover:bg-primary-green-dark transition-colors text-xs">Approve</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" name="user_action" value="reject" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs">Reject</button>
                                                    </form>
                                                <?php elseif ($user['status'] === 'active'): ?>
                                                    <?php if ($user_role === 'admin'): // Only Admin can Deactivate ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="deactivate" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 transition-colors text-xs">Deactivate</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php elseif ($user['status'] === 'inactive' || $user['status'] === 'rejected'): ?>
                                                     <?php if ($user_role === 'admin'): // Only Admin can Activate ?>
                                                        <form method="POST" action="admin-panel.php?view=users" class="inline-block">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="user_action" value="activate" class="bg-blue-500 text-white px-3 py-1 rounded-md hover:bg-blue-600 transition-colors text-xs">Activate</button>
                                                        </form>
                                                     <?php endif; ?>
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

        <?php elseif ($current_view === 'donations'): ?>
            <div class="card p-6">
                <h3 class="text-xl font-semibold text-neutral-dark mb-4">All Donations</h3>
                <div class="mb-6 flex flex-wrap gap-4">
                    <a href="admin-panel.php?view=donations&filter=all" class="px-4 py-2 rounded-md <?php echo (!isset($_GET['filter']) || $_GET['filter'] === 'all' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">All</a>
                    <a href="admin-panel.php?view=donations&filter=pending" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'pending' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Pending (<?php echo count($pending_donations); ?>)</a>
                    <a href="admin-panel.php?view=donations&filter=approved" class="px-4 py-2 rounded-md <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'approved' ? 'bg-primary-green text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'); ?>">Approved (<?php echo count($approved_donations); ?>)</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                        <thead class="bg-gray-200">
                            <tr>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">ID</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Description</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Quantity</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Donor</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Expiry Time</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Status</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $filter = $_GET['filter'] ?? 'all';
                            $donations_to_display = [];
                            if ($filter === 'all') {
                                $donations_to_display = array_merge($pending_donations, $approved_donations);
                                usort($donations_to_display, function($a, $b) {
                                    return strtotime($b['created_at']) - strtotime($a['created_at']);
                                });
                            } elseif ($filter === 'pending') {
                                $donations_to_display = $pending_donations;
                            } elseif ($filter === 'approved') {
                                $donations_to_display = $approved_donations;
                            }
                            ?>
                            <?php if (empty($donations_to_display)): ?>
                                <tr><td colspan="7" class="py-4 text-center text-gray-600">No donations found for this filter.</td></tr>
                            <?php else: ?>
                                <?php foreach ($donations_to_display as $donation): ?>
                                    <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($donation['id']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($donation['description']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($donation['quantity']) . ' ' . htmlspecialchars($donation['unit']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($donation['donor_org']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo date('Y-m-d H:i', strtotime($donation['expiry_time'])); ?></td>
                                        <td class="py-3 px-4 text-sm">
                                            <span class="status-tag <?php
                                                $status_class = '';
                                                // Accessing $donation['status'] is now safe as it's fetched from DB
                                                switch ($donation['status']) {
                                                    case 'pending': $status_class = 'status-pending'; break;
                                                    case 'approved': $status_class = 'status-approved'; break;
                                                    case 'rejected': $status_class = 'status-rejected'; break;
                                                    case 'fulfilled': $status_class = 'status-fulfilled'; break;
                                                }
                                                echo $status_class;
                                            ?>"><?php echo htmlspecialchars(ucfirst($donation['status'])); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if ($donation['status'] === 'pending'): ?>
                                                    <form method="POST" action="admin-panel.php?view=donations" class="inline-block">
                                                        <input type="hidden" name="donation_id" value="<?php echo $donation['id']; ?>">
                                                        <button type="submit" name="donation_action" value="approve_donation" class="bg-primary-green text-white px-3 py-1 rounded-md hover:bg-primary-green-dark transition-colors text-xs">Approve</button>
                                                    </form>
                                                    <form method="POST" action="admin-panel.php?view=donations" class="inline-block">
                                                        <input type="hidden" name="donation_id" value="<?php echo $donation['id']; ?>">
                                                        <button type="submit" name="donation_action" value="reject_donation" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs">Reject</button>
                                                    </form>
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

    <script>
        // No specific JS for this admin panel in this simplified version
        // Mobile menu toggle is not included as sidebars are typically always visible on desktop admin panels
        // For a responsive admin panel, you'd add mobile menu logic here.
    </script>
</body>
</html>


