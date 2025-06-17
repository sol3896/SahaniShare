<?php
// recipient-dashboard.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

// Check if user is logged in and is a recipient
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'recipient') {
    // If not logged in or not a recipient, redirect to login page
    header('Location: login-register.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$organization_name = $_SESSION['organization_name'];

$available_donations = [];
$my_requests = [];

$conn = get_db_connection();

// --- Fetch Available Donations ---
// This query should filter by 'approved' status and future expiry time
$stmt = $conn->prepare("
    SELECT d.id, d.description, d.quantity, d.unit, d.expiry_time, d.category, d.pickup_location, d.photo_url, u.organization_name as donor_org
    FROM donations d
    JOIN users u ON d.donor_id = u.id
    WHERE d.status = 'approved' AND d.expiry_time > NOW()
    ORDER BY d.expiry_time ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $available_donations[] = $row;
}
$stmt->close();

// --- Fetch My Requests ---
$stmt = $conn->prepare("
    SELECT r.id, d.description, r.requested_quantity, d.unit, r.status, r.requested_at
    FROM requests r
    JOIN donations d ON r.donation_id = d.id
    WHERE r.recipient_id = ?
    ORDER BY r.requested_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $my_requests[] = $row;
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Recipient Dashboard</title>
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
            <a href="recipient-dashboard.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Dashboard</a>
            <a href="recipient-dashboard.php#my-requests" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">My Requests</a>
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
        <div class="card p-6">
            <p class="text-gray-700 mb-6">Here you can browse available food donations and manage your requests.</p>

            <!-- Available Donations Section -->
            <h2 class="text-2xl font-semibold text-neutral-dark mb-4">Available Donations</h2>
            <div class="mb-6 flex flex-col md:flex-row gap-4">
                <input type="text" placeholder="Search by food type or donor..." class="flex-grow">
                <select class="w-full md:w-auto">
                    <option value="">Filter by Category</option>
                    <option value="produce">Produce</option>
                    <option value="baked-goods">Baked Goods</option>
                    <option value="prepared-meals">Prepared Meals</option>
                    <!-- Add more categories dynamically if possible -->
                </select>
                <select class="w-full md:w-auto">
                    <option value="">Sort By</option>
                    <option value="expiry-soonest">Expiry Soonest</option>
                    <option value="quantity-highest">Quantity (Highest)</option>
                    <option value="date-newest">Date (Newest)</option>
                </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <?php if (empty($available_donations)): ?>
                    <div class="lg:col-span-3 text-center text-gray-600">No available donations at the moment. Please check back later!</div>
                <?php else: ?>
                    <?php foreach ($available_donations as $donation): ?>
                        <!-- Donation Card -->
                        <div class="card p-4">
                            <?php
                                $img_src = !empty($donation['photo_url']) ? htmlspecialchars($donation['photo_url']) : 'https://placehold.co/400x250/EEEEEE/424242?text=No+Image';
                            ?>
                            <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($donation['description']); ?>" class="w-full h-40 object-cover rounded-md mb-4">
                            <h4 class="font-bold text-lg mb-1"><?php echo htmlspecialchars($donation['description']); ?></h4>
                            <p class="text-gray-700 text-sm mb-2">Quantity: <?php echo htmlspecialchars($donation['quantity']) . ' ' . htmlspecialchars($donation['unit']); ?></p>
                            <p class="text-red-500 text-sm font-medium mb-2"><i class="fas fa-clock mr-1"></i> Expires: <?php echo date('Y-m-d H:i', strtotime($donation['expiry_time'])); ?></p>
                            <p class="text-gray-600 text-sm mb-3">Donor: <?php echo htmlspecialchars($donation['donor_org']); ?> | Location: <?php echo htmlspecialchars($donation['pickup_location']); ?></p>
                            <!-- You would implement a form or AJAX call for "Request Item" -->
                            <button class="btn-primary !py-2 !px-4 text-sm">Request Item</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- My Requests Section -->
            <h2 class="text-2xl font-semibold text-neutral-dark mb-4" id="my-requests">My Requests</h2>
            <div class="space-y-4">
                <?php if (empty($my_requests)): ?>
                    <p class="text-gray-600">You haven't made any requests yet.</p>
                <?php else: ?>
                    <?php foreach ($my_requests as $request): ?>
                        <!-- Request Item -->
                        <div class="flex items-center justify-between border-b pb-3 border-gray-200 last:border-b-0 last:pb-0">
                            <div>
                                <p class="font-semibold text-lg"><?php echo htmlspecialchars($request['requested_quantity']) . ' ' . htmlspecialchars($request['unit']) . ' ' . htmlspecialchars($request['description']); ?></p>
                                <p class="text-sm text-gray-600">Requested: <?php echo date('Y-m-d', strtotime($request['requested_at'])); ?></p>
                            </div>
                            <?php
                                $status_class = '';
                                switch ($request['status']) {
                                    case 'pending': $status_class = 'status-pending'; break;
                                    case 'approved': $status_class = 'status-approved'; break;
                                    case 'collected': $status_class = 'status-fulfilled'; break; // Using fulfilled for collected
                                    case 'rejected': $status_class = 'status-rejected'; break;
                                }
                            ?>
                            <span class="status-tag <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($request['status'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <a href="#" class="text-primary-green hover:underline font-medium block mt-4 text-right">View All Requests <i class="fas fa-arrow-right ml-1"></i></a>
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
