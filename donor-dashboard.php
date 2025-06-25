<?php
// donor-dashboard.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'donor') {
    // If not logged in or not a donor, redirect to login page
    header('Location: login-register.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$organization_name = $_SESSION['organization_name'];

$recent_donations = [];
$recipient_feedback = [];

$conn = get_db_connection();

// --- Fetch Recent Donations ---
$stmt = $conn->prepare("SELECT description, quantity, unit, created_at, status FROM donations WHERE donor_id = ? ORDER BY created_at DESC LIMIT 3");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_donations[] = $row;
}
$stmt->close();

// --- Fetch Recipient Feedback ---
// This query fetches feedback given by recipients for donations made by this donor.
$stmt = $conn->prepare("
    SELECT f.comment, f.rating, u.organization_name as recipient_org_name
    FROM feedback f
    JOIN requests r ON f.donation_id = r.donation_id AND f.recipient_id = r.recipient_id -- Link feedback to specific request for donation
    JOIN donations d ON r.donation_id = d.id
    JOIN users u ON f.recipient_id = u.id
    WHERE d.donor_id = ?
    ORDER BY f.created_at DESC LIMIT 2
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recipient_feedback[] = $row;
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Donor Dashboard</title>
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
    <!-- Inline style to apply Inter as base font (Montserrat is applied in style.css for headings) -->
    <style>
        body {
            font-family: 'Inter', sans-serif;
            @apply bg-gray-100 text-gray-800;
        }
        /* No need for h1, h2, h3 styles here, they are in style.css now */
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- Top Navigation Bar for Desktop & Mobile Header -->
    <header class="bg-white shadow-md py-4 px-6 flex items-center justify-between sticky top-0 z-50">
        <div class="flex items-center">
            <!-- SahaniShare Logo Placeholder -->
            <div class="text-primary-green text-2xl font-bold mr-2">
                <i class="fas fa-hand-holding-heart"></i> SahaniShare
            </div>
        </div>
        <!-- Desktop Navigation -->
        <nav class="hidden md:flex space-x-6">
            <a href="donor-dashboard.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Dashboard</a>
            <a href="add-donation.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Add Donation</a>
            <a href="donor-history.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">History</a>
            <a href="donor-requests.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Requests</a>
            <a href="#" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Profile</a>
            <a href="logout.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Logout</a>
        </nav>
        <!-- Mobile Hamburger Icon -->
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
                <li><a href="donor-dashboard.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Donor Dashboard</a></li>
                <li><a href="add-donation.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Add Donation</a></li>
                <li><a href="donor-history.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">History</a></li>
                <li><a href="donor-requests.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Requests</a></li>
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
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">Welcome, <?php echo htmlspecialchars($organization_name); ?>!</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            <!-- Quick Actions Card -->
            <div class="card col-span-1 md:col-span-2 lg:col-span-1">
                <h3 class="text-xl font-semibold text-neutral-dark mb-4">Quick Actions</h3>
                <a href="add-donation.php" class="btn-primary mb-4 block text-center">
                    <i class="fas fa-plus mr-2"></i> Add New Donation
                </a>
                <div class="flex flex-col space-y-2">
                    <a href="donor-history.php" class="text-primary-green hover:underline font-medium"><i class="fas fa-clipboard-list mr-2"></i> View Donation History</a>
                    <a href="donor-requests.php" class="text-primary-green hover:underline font-medium"><i class="fas fa-inbox mr-2"></i> View Incoming Requests</a>
                    <a href="#" class="text-primary-green hover:underline font-medium"><i class="fas fa-comments mr-2"></i> View Recipient Feedback</a>
                </div>
            </div>

            <!-- Recent Donations Card -->
            <div class="card col-span-1 md:col-span-2 lg:col-span-2" id="recent-donations">
                <h3 class="text-xl font-semibold text-neutral-dark mb-4">Recent Donations</h3>
                <div class="space-y-4">
                    <?php if (empty($recent_donations)): ?>
                        <p class="text-gray-600">No recent donations found. Why not <a href="add-donation.php" class="text-primary-green hover:underline">add one</a>?</p>
                    <?php else: ?>
                        <?php foreach ($recent_donations as $donation): ?>
                            <div class="flex items-center justify-between border-b pb-3 border-gray-200 last:border-b-0 last:pb-0">
                                <div>
                                    <p class="font-semibold text-lg"><?php echo htmlspecialchars($donation['quantity']) . ' ' . htmlspecialchars($donation['unit']) . ' ' . htmlspecialchars($donation['description']); ?></p>
                                    <p class="text-sm text-gray-600">Submitted: <?php echo date('Y-m-d', strtotime($donation['created_at'])); ?></p>
                                </div>
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
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <a href="donor-history.php" class="text-primary-green hover:underline font-medium block mt-4 text-right">View All History <i class="fas fa-arrow-right ml-1"></i></a>
            </div>

            <!-- Recipient Feedback Card -->
            <div class="card col-span-1 md:col-span-2" id="recipient-feedback">
                <h3 class="text-xl font-semibold text-neutral-dark mb-4">Recipient Feedback</h3>
                <div class="space-y-4">
                    <?php if (empty($recipient_feedback)): ?>
                        <p class="text-gray-600">No feedback yet. Keep donating to make an impact!</p>
                    <?php else: ?>
                        <?php foreach ($recipient_feedback as $feedback): ?>
                            <div class="border-b pb-3 border-gray-200 last:border-b-0 last:pb-0">
                                <p class="italic text-gray-700">"<?php echo htmlspecialchars($feedback['comment']); ?>"</p>
                                <p class="text-sm text-gray-500 mt-1">- <?php echo htmlspecialchars($feedback['recipient_org_name']); ?>
                                    <span class="text-accent-orange">
                                        <?php for ($i = 0; $i < $feedback['rating']; $i++): ?><i class="fas fa-star"></i><?php endfor; ?>
                                        <?php for ($i = $feedback['rating']; $i < 5; $i++): ?><i class="far fa-star"></i><?php endfor; ?>
                                    </span>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <a href="#" class="text-primary-green hover:underline font-medium block mt-4 text-right">View All Feedback <i class="fas fa-arrow-right ml-1"></i></a>
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

