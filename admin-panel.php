<?php
// admin-panel.php
session_start(); // Start the session to manage user login state

// Include the database connection file. This file defines get_db_connection() and DB_NAME, DB_USER, DB_PASSWORD etc.
include_once 'db_connection.php';

// Check if the user is logged in AND has an 'admin' or 'moderator' role.
// If not authorized, redirect them to the login page immediately.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'moderator')) {
    header('Location: login-register.php');
    exit(); // Always exit after a header redirect
}

// Retrieve user specific session data for display
$user_id = $_SESSION['user_id'];
$organization_name = $_SESSION['organization_name'];
$user_role = $_SESSION['user_role'];

// Initialize variables for dashboard statistics and table data
$total_donations = 0;
$pending_approvals = 0;
$active_users = 0;
$display_pending_donations = []; // Used for both dashboard pending and all donations view
$display_users = [];             // Used for user management view

// Establish a database connection
$conn = get_db_connection();

// --- Handle POST requests for Donation Approval/Rejection ---
// This block processes actions coming from the "Approve" or "Reject" buttons.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['donation_id'])) {
    $action = $_POST['action'];        // Value will be 'approve' or 'reject'
    $donation_id = $_POST['donation_id']; // ID of the donation to update

    $new_status = '';
    if ($action === 'approve') {
        $new_status = 'approved';
    } elseif ($action === 'reject') {
        $new_status = 'rejected';
    } else {
        // If an invalid action is sent, redirect with an error message.
        header('Location: admin-panel.php?message=invalid_donation_action');
        exit();
    }

    // Prepare an SQL statement to update the donation's status
    $stmt = $conn->prepare("UPDATE donations SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    // 'si' means bind string (for status) and integer (for donation_id)
    $stmt->bind_param("si", $new_status, $donation_id);

    if ($stmt->execute()) {
        // If update is successful, redirect back to the admin panel with a success message.
        header('Location: admin-panel.php?message=donation_updated');
    } else {
        // If update fails, redirect with an error message.
        error_log("Failed to update donation status: " . $stmt->error); // Log the actual SQL error
        header('Location: admin-panel.php?message=donation_update_failed');
    }
    $stmt->close(); // Close the statement
    exit(); // Crucial: terminate script execution after a header redirect
}

// --- Handle POST requests for User Status Change (User Management) ---
// This block processes actions coming from user management buttons (e.g., Approve Recipient, Deactivate).
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_action']) && isset($_POST['user_id'])) {
    $user_action = $_POST['user_action']; // e.g., 'approve_recipient', 'deactivate', 'activate'
    $target_user_id = $_POST['user_id'];  // ID of the user to update

    $new_user_status = '';
    switch ($user_action) {
        case 'approve_recipient':
        case 'activate':
            $new_user_status = 'active';
            break;
        case 'deactivate':
            $new_user_status = 'inactive';
            break;
        // No case for 'reject' role, as roles aren't typically "rejected"
    }

    if (!empty($new_user_status)) {
        // Prepare an SQL statement to update the user's status
        $stmt = $conn->prepare("UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param("si", $new_user_status, $target_user_id); // s for string, i for integer

        if ($stmt->execute()) {
            // Redirect back to the user management view with a success message.
            header('Location: admin-panel.php?view=users&message=user_updated');
        } else {
            // Log and redirect on failure.
            error_log("Failed to update user status: " . $stmt->error); // Log the actual SQL error
            header('Location: admin-panel.php?view=users&message=user_update_failed');
        }
        $stmt->close(); // Close the statement
    }
    exit(); // Crucial: terminate script execution after a header redirect
}

// --- Fetch Dashboard Statistics ---
// These queries run regardless of the specific admin panel view to always provide up-to-date stats.
// Total Donations
$stmt = $conn->prepare("SELECT COUNT(*) FROM donations");
$stmt->execute();
$stmt->bind_result($total_donations); // Bind the result to the variable
$stmt->fetch(); // Fetch the value
$stmt->close(); // Close the statement

// Pending Donations for Approval
$stmt = $conn->prepare("SELECT COUNT(*) FROM donations WHERE status = 'pending'");
$stmt->execute();
$stmt->bind_result($pending_approvals);
$stmt->fetch();
$stmt->close();

// Active Users
$stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE status = 'active'");
$stmt->execute();
$stmt->bind_result($active_users);
$stmt->fetch();
$stmt->close();

// Determine which sub-view of the admin panel to display (dashboard, donations, users)
$view = $_GET['view'] ?? 'dashboard'; // Default to 'dashboard' if no view is specified in the URL

// --- Fetch data for specific views (Donations Table, Users Table) ---
if ($view === 'donations' || $view === 'dashboard') {
    // For the dashboard, we only show PENDING donations in the table.
    // For the 'donations' view, we show ALL donations with their current statuses.
    $sql_donations = "
        SELECT d.id, d.description, d.quantity, d.unit, d.expiry_time, d.status, u.organization_name as donor_org
        FROM donations d
        JOIN users u ON d.donor_id = u.id
    ";
    if ($view === 'dashboard') {
        $sql_donations .= " WHERE d.status = 'pending'"; // Only show pending for dashboard summary
    }
    $sql_donations .= " ORDER BY d.created_at DESC";

    $stmt = $conn->prepare($sql_donations);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $display_pending_donations[] = $row; // This variable now holds data for either pending or all donations based on $view
    }
    $stmt->close();
}

if ($view === 'users') {
    // Fetch all users for the user management table
    $stmt = $conn->prepare("SELECT id, organization_name, email, role, status, created_at FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $display_users[] = $row;
    }
    $stmt->close();
}

// Close the main database connection after all operations are done.
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
    </style>
</head>
<body class="min-h-screen flex">

    <!-- Sidebar Navigation -->
    <aside class="w-64 bg-white shadow-md p-6 hidden md:block flex-shrink-0">
        <div class="text-primary-green text-2xl font-bold mb-8">
            <i class="fas fa-hand-holding-heart"></i> SahaniShare
        </div>
        <nav>
            <ul class="space-y-3">
                <li><a href="admin-panel.php?view=dashboard" class="flex items-center text-neutral-dark hover:text-primary-green font-medium py-2 px-3 rounded-lg hover:bg-gray-100 transition duration-200"><i class="fas fa-tachometer-alt mr-3"></i> Dashboard</a></li>
                <li><a href="admin-panel.php?view=donations" class="flex items-center text-neutral-dark hover:text-primary-green font-medium py-2 px-3 rounded-lg hover:bg-gray-100 transition duration-200"><i class="fas fa-box mr-3"></i> Donations</a></li>
                <li><a href="admin-panel.php?view=users" class="flex items-center text-neutral-dark hover:text-primary-green font-medium py-2 px-3 rounded-lg hover:bg-gray-100 transition duration-200"><i class="fas fa-users mr-3"></i> Users</a></li>
                <li><a href="reports.php" class="flex items-center text-neutral-dark hover:text-primary-green font-medium py-2 px-3 rounded-lg hover:bg-gray-100 transition duration-200"><i class="fas fa-chart-pie mr-3"></i> Reports</a></li>
                <li><a href="#" class="flex items-center text-neutral-dark hover:text-primary-green font-medium py-2 px-3 rounded-lg hover:bg-gray-100 transition duration-200"><i class="fas fa-cog mr-3"></i> Settings</a></li>
                <li class="pt-4"><a href="logout.php" class="flex items-center text-red-500 hover:text-red-700 font-medium py-2 px-3 rounded-lg hover:bg-gray-100 transition duration-200"><i class="fas fa-sign-out-alt mr-3"></i> Logout</a></li>
            </ul>
        </nav>
    </aside>

    <div class="flex-grow flex flex-col">
        <!-- Top Navigation Bar for Mobile Header (replicated for consistency) -->
        <header class="bg-white shadow-md py-4 px-6 flex items-center justify-between sticky top-0 z-50 md:hidden">
            <div class="flex items-center">
                <div class="text-primary-green text-2xl font-bold mr-2">
                    <i class="fas fa-hand-holding-heart"></i> SahaniShare
                </div>
            </div>
            <button id="mobile-menu-button" class="p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-green">
                <i class="fas fa-bars text-neutral-dark text-xl"></i>
            </button>
        </header>

        <!-- Mobile Menu Overlay -->
        <div id="mobile-menu-overlay" class="fixed inset-0 bg-gray-800 bg-opacity-75 z-40 hidden md:hidden"></div>
        <nav id="mobile-menu" class="fixed top-0 right-0 w-64 h-full bg-white shadow-lg z-50 transform translate-x-full transition-transform duration-300 ease-in-out md:hidden">
            <div class="p-6">
                <button id="close-mobile-menu" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
                <div class="text-primary-green text-xl font-bold mb-8">SahaniShare</div>
                <ul class="space-y-4">
                    <li><a href="admin-panel.php?view=dashboard" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Dashboard</a></li>
                    <li><a href="admin-panel.php?view=donations" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Donations</a></li>
                    <li><a href="admin-panel.php?view=users" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Users</a></li>
                    <li><a href="reports.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Reports</a></li>
                    <li><a href="#" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Settings</a></li>
                    <li><a href="logout.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Logout</a></li>
                </ul>
            </div>
        </nav>

        <!-- Main Content Area -->
        <main class="flex-grow container mx-auto p-4 md:p-8">
            <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">Admin Panel (<?php echo htmlspecialchars(ucfirst($user_role)); ?>)</h1>
            <div class="card p-6">
                <p class="text-gray-700 mb-6">Welcome, Administrator! Use this panel to manage donations, user accounts, and generate reports.</p>

                <?php if ($view === 'dashboard'): ?>
                    <!-- Admin Dashboard Overview -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                        <div class="card p-4 text-center">
                            <i class="fas fa-box-open text-primary-green text-4xl mb-3"></i>
                            <h4 class="font-semibold text-xl">Total Donations</h4>
                            <p class="text-4xl font-bold text-neutral-dark"><?php echo htmlspecialchars($total_donations); ?></p>
                        </div>
                        <div class="card p-4 text-center">
                            <i class="fas fa-hourglass-half text-accent-orange text-4xl mb-3"></i>
                            <h4 class="font-semibold text-xl">Pending Approvals</h4>
                            <p class="text-4xl font-bold text-neutral-dark"><?php echo htmlspecialchars($pending_approvals); ?></p>
                        </div>
                        <div class="card p-4 text-center">
                            <i class="fas fa-users text-blue-500 text-4xl mb-3"></i>
                            <h4 class="font-semibold text-xl">Active Users</h4>
                            <p class="text-4xl font-bold text-neutral-dark"><?php echo htmlspecialchars($active_users); ?></p>
                        </div>
                    </div>

                    <!-- Pending Donations Table -->
                    <h2 class="text-2xl font-semibold text-neutral-dark mb-4">Pending Donations</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Food Item</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Donor</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expiry</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($display_pending_donations)): ?>
                                    <tr>
                                        <td colspan="5" class="py-4 px-4 text-center text-gray-600">No pending donations.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($display_pending_donations as $donation): ?>
                                        <tr>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($donation['description']); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($donation['donor_org']); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($donation['quantity'] . ' ' . $donation['unit']); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo date('Y-m-d', strtotime($donation['expiry_time'])); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap">
                                                <form method="POST" action="admin-panel.php" class="inline-block">
                                                    <input type="hidden" name="donation_id" value="<?php echo $donation['id']; ?>">
                                                    <button type="submit" name="action" value="approve" class="text-primary-green hover:text-primary-green-dark mr-2"><i class="fas fa-check"></i> Approve</button>
                                                </form>
                                                <form method="POST" action="admin-panel.php" class="inline-block">
                                                    <input type="hidden" name="donation_id" value="<?php echo $donation['id']; ?>">
                                                    <button type="submit" name="action" value="reject" class="text-red-500 hover:text-red-700"><i class="fas fa-times"></i> Reject</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($view === 'donations'): ?>
                    <!-- All Donations Table -->
                    <h2 class="text-2xl font-semibold text-neutral-dark mb-4">All Donations</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Food Item</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Donor</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expiry</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($display_pending_donations)): // Using same variable for all donations, needs rename if truly separating ?>
                                    <tr>
                                        <td colspan="6" class="py-4 px-4 text-center text-gray-600">No donations found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($display_pending_donations as $donation): ?>
                                        <tr>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($donation['description']); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($donation['donor_org']); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($donation['quantity'] . ' ' . $donation['unit']); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo date('Y-m-d', strtotime($donation['expiry_time'])); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap">
                                                <?php
                                                    $status_class = '';
                                                    switch ($donation['status']) {
                                                        case 'pending': $status_class = 'status-pending'; break;
                                                        case 'approved': $status_class = 'status-approved'; break;
                                                        case 'fulfilled': $status_class = 'status-fulfilled'; break;
                                                        case 'rejected': $status_class = 'status-rejected'; break;
                                                    }
                                                ?>
                                                <span class="status-tag <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($donation['status'])); ?></span>
                                            </td>
                                            <td class="py-4 px-4 whitespace-nowrap">
                                                <!-- Actions: View Details, Edit, maybe direct status change -->
                                                <button class="text-blue-500 hover:text-blue-700 mr-2"><i class="fas fa-eye"></i> View</button>
                                                <?php if ($donation['status'] === 'pending'): ?>
                                                    <form method="POST" action="admin-panel.php" class="inline-block">
                                                        <input type="hidden" name="donation_id" value="<?php echo $donation['id']; ?>">
                                                        <button type="submit" name="action" value="approve" class="text-primary-green hover:text-primary-green-dark mr-2"><i class="fas fa-check"></i> Approve</button>
                                                    </form>
                                                    <form method="POST" action="admin-panel.php" class="inline-block">
                                                        <input type="hidden" name="donation_id" value="<?php echo $donation['id']; ?>">
                                                        <button type="submit" name="action" value="reject" class="text-red-500 hover:text-red-700"><i class="fas fa-times"></i> Reject</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($view === 'users'): ?>
                    <!-- User Management Table -->
                    <h2 class="text-2xl font-semibold text-neutral-dark mb-4">User Accounts</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organization</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($display_users)): ?>
                                    <tr>
                                        <td colspan="5" class="py-4 px-4 text-center text-gray-600">No users found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($display_users as $user): ?>
                                        <tr>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($user['organization_name']); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                                            <td class="py-4 px-4 whitespace-nowrap">
                                                <?php
                                                    $status_class = '';
                                                    switch ($user['status']) {
                                                        case 'pending': $status_class = 'status-pending'; break;
                                                        case 'active': $status_class = 'status-approved'; break; // Using approved for active
                                                        case 'inactive': $status_class = 'status-rejected'; break; // Using rejected for inactive
                                                        case 'rejected': $status_class = 'status-rejected'; break;
                                                    }
                                                ?>
                                                <span class="status-tag <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($user['status'])); ?></span>
                                            </td>
                                            <td class="py-4 px-4 whitespace-nowrap">
                                                <form method="POST" action="admin-panel.php" class="inline-block">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <?php if ($user['status'] === 'pending' && $user['role'] === 'recipient'): ?>
                                                        <button type="submit" name="user_action" value="approve_recipient" class="text-primary-green hover:text-primary-green-dark mr-2"><i class="fas fa-user-check"></i> Approve Recipient</button>
                                                    <?php elseif ($user['status'] === 'active'): ?>
                                                        <button type="submit" name="user_action" value="deactivate" class="text-orange-500 hover:text-orange-700 mr-2"><i class="fas fa-user-times"></i> Deactivate</button>
                                                    <?php elseif ($user['status'] === 'inactive' || $user['status'] === 'rejected'): ?>
                                                        <button type="submit" name="user_action" value="activate" class="text-blue-500 hover:text-blue-700 mr-2"><i class="fas fa-user-plus"></i> Activate</button>
                                                    <?php endif; ?>
                                                    <!-- More actions like 'Edit Profile' could go here -->
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');

        // Toggle mobile menu
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.add('mobile-menu-open');
            mobileMenuOverlay.classList.remove('hidden');
        });

        closeMobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.remove('mobile-menu-open');
            mobileMenuOverlay.classList.add('hidden');
        });

        mobileMenuOverlay.addEventListener('click', () => {
            mobileMenu.classList.remove('mobile-menu-open');
            mobileMenuOverlay.classList.add('hidden');
        });
    </script>
</body>
</html>

