<?php
// donor-history.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

// Check if user is logged in and is a donor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'donor') {
    header('Location: login-register.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$organization_name = $_SESSION['organization_name'];

$all_donations = [];

$conn = get_db_connection();

// Fetch ALL donations made by this donor, ordered by most recent
$stmt = $conn->prepare("SELECT id, description, quantity, unit, expiry_time, created_at, status FROM donations WHERE donor_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_donations[] = $row;
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - My Donation History</title>
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
<body class="min-h-screen flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="bg-white shadow-md py-4 px-6 flex items-center justify-between sticky top-0 z-50">
        <div class="flex items-center">
            <div class="text-primary-green text-2xl font-bold mr-2">
                <i class="fas fa-hand-holding-heart"></i> SahaniShare
            </div>
        </div>
        <nav class="hidden md:flex space-x-6">
            <a href="donor-dashboard.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Dashboard</a>
            <a href="add-donation.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Add Donation</a>
            <a href="donor-history.php" class="text-primary-green font-medium transition duration-200">History</a>
            <a href="#" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Profile</a>
            <a href="logout.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Logout</a>
        </nav>
        <button id="mobile-menu-button" class="md:hidden p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-green">
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
                <li><a href="login-register.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Login/Register</a></li>
                <li><a href="donor-dashboard.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Dashboard</a></li>
                <li><a href="add-donation.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Add Donation</a></li>
                <li><a href="donor-history.php" class="block text-primary-green font-medium py-2">History</a></li>
                <li><a href="#" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Profile</a></li>
                <li><a href="recipient-dashboard.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Recipient Dashboard</a></li>
                <li><a href="admin-panel.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Admin Panel</a></li>
                <li><a href="reports.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Reports</a></li>
                <li><a href="logout.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Logout</a></li>
            </ul>
        </div>
    </nav>

    <!-- Main Content Area -->
    <main class="flex-grow container mx-auto p-4 md:p-8">
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">My Donation History</h1>
        <div class="card p-6">
            <p class="text-gray-700 mb-6">Here is a complete list of all your past and current food donations.</p>

            <div class="overflow-x-auto">
                <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Food Item</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expiry Date</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted On</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($all_donations)): ?>
                            <tr>
                                <td colspan="6" class="py-4 px-4 text-center text-gray-600">No donations found in your history.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_donations as $donation): ?>
                                <tr>
                                    <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($donation['description']); ?></td>
                                    <td class="py-4 px-4 whitespace-nowrap"><?php echo htmlspecialchars($donation['quantity'] . ' ' . $donation['unit']); ?></td>
                                    <td class="py-4 px-4 whitespace-nowrap"><?php echo date('Y-m-d', strtotime($donation['expiry_time'])); ?></td>
                                    <td class="py-4 px-4 whitespace-nowrap"><?php echo date('Y-m-d', strtotime($donation['created_at'])); ?></td>
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
                                        <!-- You can add actions here like 'View Details' or 'Edit' if applicable -->
                                        <button class="text-blue-500 hover:text-blue-700"><i class="fas fa-eye mr-1"></i> View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

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
