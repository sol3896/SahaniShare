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
$organization_name = $_SESSION['organization_name'];
$user_role = $_SESSION['user_role'];

$total_donations = 0;
$pending_approvals = 0;
$active_users = 0;
$pending_donations = []; // For the table display

$conn = get_db_connection();

// --- Fetch Dashboard Stats ---
$stmt = $conn->prepare("SELECT COUNT(*) FROM donations");
$stmt->execute();
$stmt->bind_result($total_donations);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM donations WHERE status = 'pending'");
$stmt->execute();
$stmt->bind_result($pending_approvals);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE status = 'active'");
$stmt->execute();
$stmt->bind_result($active_users);
$stmt->fetch();
$stmt->close();

// --- Fetch Pending Donations for the Table ---
$stmt = $conn->prepare("
    SELECT d.id, d.description, d.quantity, d.unit, d.expiry_time, u.organization_name as donor_org
    FROM donations d
    JOIN users u ON d.donor_id = u.id
    WHERE d.status = 'pending'
    ORDER BY d.created_at ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pending_donations[] = $row;
}
$stmt->close();

$conn->close();

// --- Handle Donation Approval/Rejection (basic example) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['donation_id'])) {
    $action = $_POST['action']; // 'approve' or 'reject'
    $donation_id = $_POST['donation_id'];

    $conn = get_db_connection();
    if ($action === 'approve') {
        $new_status = 'approved';
    } elseif ($action === 'reject') {
        $new_status = 'rejected';
    } else {
        // Invalid action
        header('Location: admin-panel.php?message=invalid_action');
        exit();
    }

    $stmt = $conn->prepare("UPDATE donations SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->bind_param("si", $new_status, $donation_id);
    if ($stmt->execute()) {
        header('Location: admin-panel.php?message=donation_updated');
    } else {
        header('Location: admin-panel.php?message=update_failed');
    }
    $stmt->close();
    $conn->close();
    exit();
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
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
                <li><a href="admin-panel.php" class="flex items-center text-neutral-dark hover:text-primary-green font-medium py-2 px-3 rounded-lg hover:bg-gray-100 transition duration-200"><i class="fas fa-tachometer-alt mr-3"></i> Dashboard</a></li>
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
                    <li><a href="admin-panel.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Dashboard</a></li>
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

                <!-- Recent Activity/Pending Donations Table (Example) -->
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
                            <?php if (empty($pending_donations)): ?>
                                <tr>
                                    <td colspan="5" class="py-4 px-4 text-center text-gray-600">No pending donations.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pending_donations as $donation): ?>
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

                <a href="#" class="text-primary-green hover:underline font-medium block mt-4 text-right">View All Donations <i class="fas fa-arrow-right ml-1"></i></a>
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

