<?php
// reports.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

// Check if user is logged in and is an admin or moderator
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'moderator')) {
    header('Location: login-register.php');
    exit();
}

$conn = get_db_connection();

// --- Data for Charts (Example fetches) ---
// You would dynamically fetch data based on date ranges selected by the user.
// For now, these are static dummy data or basic aggregates.

// Donations Over Time (e.g., last 5 weeks)
$donations_over_time = [
    'labels' => ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5'],
    'data' => [0, 0, 0, 0, 0] // Default to 0, fetch actual counts
];
// Example: Fetch last 5 weeks data
// $stmt = $conn->prepare("SELECT COUNT(*) as count, WEEK(created_at) as week_num FROM donations WHERE created_at >= CURDATE() - INTERVAL 5 WEEK GROUP BY WEEK(created_at) ORDER BY WEEK(created_at)");
// Execute and populate $donations_over_time['data']

// Food Type Distribution
$food_type_distribution = [
    'labels' => ['Produce', 'Baked Goods', 'Prepared Meals', 'Dairy', 'Pantry Staples', 'Other'],
    'data' => [0, 0, 0, 0, 0, 0]
];
$stmt = $conn->prepare("SELECT category, COUNT(*) as count FROM donations GROUP BY category");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $category_index = array_search($row['category'], $food_type_distribution['labels']);
    if ($category_index !== false) {
        $food_type_distribution['data'][$category_index] = $row['count'];
    } else {
        // If category not in predefined labels, add to 'Other' or add new label
        $food_type_distribution['data'][count($food_type_distribution['data']) - 1] += $row['count']; // Add to 'Other'
    }
}
$stmt->close();

// Fulfillment Rates
$fulfilled_count = 0;
$pending_requests_count = 0;
$rejected_requests_count = 0;

$stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE status = 'collected'");
$stmt->execute();
$stmt->bind_result($fulfilled_count);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE status = 'pending'");
$stmt->execute();
$stmt->bind_result($pending_requests_count);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE status = 'rejected'");
$stmt->execute();
$stmt->bind_result($rejected_requests_count);
$stmt->fetch();
$stmt->close();

$total_requests = $fulfilled_count + $pending_requests_count + $rejected_requests_count;
$fulfillment_rate_data = [
    ($total_requests > 0 ? ($fulfilled_count / $total_requests) * 100 : 0),
    ($total_requests > 0 ? ($pending_requests_count / $total_requests) * 100 : 0),
    ($total_requests > 0 ? ($rejected_requests_count / $total_requests) * 100 : 0)
];


$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Reports & Analytics</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Link to external style.css -->
    <link rel="stylesheet" href="style.css">
    <!-- Chart.js for graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            <a href="admin-panel.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Dashboard</a>
            <a href="reports.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Reports</a>
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
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">Reports & Analytics</h1>
        <div class="card p-6">
            <p class="text-gray-700 mb-6">Gain insights into SahaniShare's impact with detailed reports and analytics.</p>

            <div class="mb-6 flex flex-col md:flex-row gap-4 justify-between items-center">
                <div class="flex flex-wrap gap-2">
                    <button class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 transition-colors">Last 7 Days</button>
                    <button class="bg-primary-green text-white py-2 px-4 rounded-md hover:bg-primary-green-dark transition-colors">Last 30 Days</button>
                    <button class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 transition-colors">Last 90 Days</button>
                    <input type="date" class="py-2 px-3 rounded-md border border-gray-300">
                    <span>-</span>
                    <input type="date" class="py-2 px-3 rounded-md border border-gray-300">
                </div>
                <button class="btn-primary !w-auto !py-2 !px-4"><i class="fas fa-download mr-2"></i> Download Report</button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Chart 1: Donations Over Time -->
                <div class="card p-4">
                    <h3 class="text-xl font-semibold text-neutral-dark mb-4">Donations Over Time</h3>
                    <canvas id="donationsChart"></canvas>
                </div>

                <!-- Chart 2: Food Type Distribution -->
                <div class="card p-4">
                    <h3 class="text-xl font-semibold text-neutral-dark mb-4">Food Type Distribution</h3>
                    <canvas id="foodTypeChart"></canvas>
                </div>

                <!-- Chart 3: Fulfillment Rates -->
                <div class="card p-4 lg:col-span-2">
                    <h3 class="text-xl font-semibold text-neutral-dark mb-4">Fulfillment Rates</h3>
                    <canvas id="fulfillmentChart"></canvas>
                </div>
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

        // PHP variables passed to JavaScript
        const donationsLabels = <?php echo json_encode($donations_over_time['labels']); ?>;
        const donationsData = <?php echo json_encode($donations_over_time['data']); ?>;

        const foodTypeLabels = <?php echo json_encode($food_type_distribution['labels']); ?>;
        const foodTypeData = <?php echo json_encode($food_type_distribution['data']); ?>;

        const fulfillmentData = <?php echo json_encode($fulfillment_rate_data); ?>;


        // Chart.js data and rendering (example data)
        document.addEventListener('DOMContentLoaded', () => {
            // Donations Over Time Chart
            const donationsCtx = document.getElementById('donationsChart').getContext('2d');
            new Chart(donationsCtx, {
                type: 'line',
                data: {
                    labels: donationsLabels,
                    datasets: [{
                        label: 'Number of Donations',
                        data: donationsData,
                        borderColor: '#4CAF50',
                        backgroundColor: 'rgba(76, 175, 80, 0.2)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });

            // Food Type Distribution Chart
            const foodTypeCtx = document.getElementById('foodTypeChart').getContext('2d');
            new Chart(foodTypeCtx, {
                type: 'pie',
                data: {
                    labels: foodTypeLabels,
                    datasets: [{
                        label: 'Food Types',
                        data: foodTypeData,
                        backgroundColor: [
                            '#4CAF50', // Primary Green
                            '#FF9800', // Accent Orange
                            '#8BC34A', // Lighter Green
                            '#FFC107', // Lighter Orange
                            '#607D8B',  // Blue-gray neutral
                            '#757575' // Even more neutral for "Other"
                        ],
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        }
                    }
                }
            });

            // Fulfillment Rates Chart
            const fulfillmentCtx = document.getElementById('fulfillmentChart').getContext('2d');
            new Chart(fulfillmentCtx, {
                type: 'bar',
                data: {
                    labels: ['Fulfilled', 'Pending', 'Rejected'],
                    datasets: [{
                        label: 'Fulfillment Status',
                        data: fulfillmentData,
                        backgroundColor: [
                            '#4CAF50', // Green for Fulfilled
                            '#FF9800', // Orange for Pending
                            '#F44336'  // Red for Rejected
                        ],
                        borderColor: [
                            '#4CAF50',
                            '#FF9800',
                            '#F44336'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>

