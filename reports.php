<?php
// reports.php
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

$conn = get_db_connection();

// --- Fetch Report Data ---

// 1. User Statistics
$total_users = 0;
$active_users = 0;
$pending_users = 0;
$rejected_users = 0;
$inactive_users = 0;
$total_donors = 0;
$total_recipients = 0;

$stmt_users_stats = $conn->prepare("SELECT COUNT(*) AS total, 
                                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                                        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
                                        SUM(CASE WHEN role = 'donor' THEN 1 ELSE 0 END) AS donors,
                                        SUM(CASE WHEN role = 'recipient' THEN 1 ELSE 0 END) AS recipients
                                    FROM users");
if ($stmt_users_stats && $stmt_users_stats->execute()) {
    $result = $stmt_users_stats->get_result();
    $row = $result->fetch_assoc();
    $total_users = $row['total'];
    $active_users = $row['active'];
    $pending_users = $row['pending'];
    $rejected_users = $row['rejected'];
    $inactive_users = $row['inactive'];
    $total_donors = $row['donors'];
    $total_recipients = $row['recipients'];
    $stmt_users_stats->close();
} else {
    error_log("SahaniShare Reports Error: Failed to fetch user statistics: " . $conn->error);
}

// 2. Donation Statistics
$total_donations = 0;
$pending_donations = 0;
$approved_donations = 0;
$fulfilled_donations = 0;
$rejected_donations = 0;

$stmt_donations_stats = $conn->prepare("SELECT COUNT(*) AS total,
                                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                                            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                                            SUM(CASE WHEN status = 'fulfilled' THEN 1 ELSE 0 END) AS fulfilled,
                                            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
                                        FROM donations");
if ($stmt_donations_stats && $stmt_donations_stats->execute()) {
    $result = $stmt_donations_stats->get_result();
    $row = $result->fetch_assoc();
    $total_donations = $row['total'];
    $pending_donations = $row['pending'];
    $approved_donations = $row['approved'];
    $fulfilled_donations = $row['fulfilled'];
    $rejected_donations = $row['rejected'];
    $stmt_donations_stats->close();
} else {
    error_log("SahaniShare Reports Error: Failed to fetch donation statistics: " . $conn->error);
}

// 3. Request Statistics (assuming a 'requests' table exists)
$total_requests = 0;
$pending_requests = 0;
$accepted_requests = 0;
$completed_requests = 0;
$cancelled_requests = 0;

// This query assumes a 'requests' table with a 'status' column (e.g., 'pending', 'accepted', 'completed', 'cancelled')
$stmt_requests_stats = $conn->prepare("SELECT COUNT(*) AS total,
                                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                                            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted,
                                            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                                            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
                                        FROM requests");
if ($stmt_requests_stats && $stmt_requests_stats->execute()) {
    $result = $stmt_requests_stats->get_result();
    $row = $result->fetch_assoc();
    $total_requests = $row['total'];
    $pending_requests = $row['pending'];
    $accepted_requests = $row['accepted'];
    $completed_requests = $row['completed'];
    $cancelled_requests = $row['cancelled'];
    $stmt_requests_stats->close();
} else {
    // This might fail if the 'requests' table doesn't exist yet or has different columns.
    // Log a warning, but don't stop the page.
    error_log("SahaniShare Reports Warning: Failed to fetch request statistics (requests table might be missing or columns differ): " . $conn->error);
}


$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Reports</title>
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
                    <a href="admin-panel.php?view=dashboard" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="admin-panel.php?view=users" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-users mr-3"></i> Manage Users
                    </a>
                </li>
                <li>
                    <a href="admin-panel.php?view=donations" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-boxes mr-3"></i> Manage Donations
                    </a>
                </li>
                <li>
                    <a href="reports.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors bg-primary-green-dark">
                        <i class="fas fa-chart-bar mr-3"></i> Reports
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
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
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">System Reports</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            <!-- User Statistics Card -->
            <div class="card p-6">
                <h3 class="text-2xl font-semibold text-neutral-dark mb-4"><i class="fas fa-users mr-2 text-primary-green"></i> User Statistics</h3>
                <ul class="space-y-2 text-gray-700">
                    <li class="flex justify-between items-center">
                        <span>Total Users:</span>
                        <span class="font-bold text-lg"><?php echo $total_users; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Active Users:</span>
                        <span class="font-semibold text-green-600"><?php echo $active_users; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Pending Users:</span>
                        <span class="font-semibold text-orange-600"><?php echo $pending_users; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Rejected Users:</span>
                        <span class="font-semibold text-red-600"><?php echo $rejected_users; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Inactive Users:</span>
                        <span class="font-semibold text-gray-600"><?php echo $inactive_users; ?></span>
                    </li>
                    <li class="border-t pt-2 mt-2 flex justify-between items-center">
                        <span>Total Donors:</span>
                        <span class="font-bold"><?php echo $total_donors; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Total Recipients:</span>
                        <span class="font-bold"><?php echo $total_recipients; ?></span>
                    </li>
                </ul>
                <div class="mt-6 text-center">
                    <a href="download_user_report.php" class="btn-primary inline-flex items-center">
                        <i class="fas fa-download mr-2"></i> Download User Report (CSV)
                    </a>
                </div>
            </div>

            <!-- Donation Statistics Card -->
            <div class="card p-6">
                <h3 class="text-2xl font-semibold text-neutral-dark mb-4"><i class="fas fa-boxes mr-2 text-primary-green"></i> Donation Statistics</h3>
                <ul class="space-y-2 text-gray-700">
                    <li class="flex justify-between items-center">
                        <span>Total Donations:</span>
                        <span class="font-bold text-lg"><?php echo $total_donations; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Pending Donations:</span>
                        <span class="font-semibold text-orange-600"><?php echo $pending_donations; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Approved Donations:</span>
                        <span class="font-semibold text-blue-600"><?php echo $approved_donations; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Fulfilled Donations:</span>
                        <span class="font-semibold text-green-600"><?php echo $fulfilled_donations; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Rejected Donations:</span>
                        <span class="font-semibold text-red-600"><?php echo $rejected_donations; ?></span>
                    </li>
                </ul>
                <div class="mt-6 text-center">
                    <a href="download_donation_report.php" class="btn-primary inline-flex items-center">
                        <i class="fas fa-download mr-2"></i> Download Donation Report (CSV)
                    </a>
                </div>
            </div>

            <!-- Request Statistics Card -->
            <div class="card p-6">
                <h3 class="text-2xl font-semibold text-neutral-dark mb-4"><i class="fas fa-hand-holding-heart mr-2 text-primary-green"></i> Request Statistics</h3>
                <ul class="space-y-2 text-gray-700">
                    <li class="flex justify-between items-center">
                        <span>Total Requests:</span>
                        <span class="font-bold text-lg"><?php echo $total_requests; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Pending Requests:</span>
                        <span class="font-semibold text-orange-600"><?php echo $pending_requests; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Accepted Requests:</span>
                        <span class="font-semibold text-blue-600"><?php echo $accepted_requests; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Completed Requests:</span>
                        <span class="font-semibold text-green-600"><?php echo $completed_requests; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span>Cancelled Requests:</span>
                        <span class="font-semibold text-red-600"><?php echo $cancelled_requests; ?></span>
                    </li>
                </ul>
                <div class="mt-6 text-center">
                    <a href="download_request_report.php" class="btn-primary inline-flex items-center">
                        <i class="fas fa-download mr-2"></i> Download Request Report (CSV)
                    </a>
                </div>
            </div>

            <!-- You can add more detailed reports here, e.g., Top Donors, Donations by Category, etc. -->

        </div>
    </main>

    <script>
        // Mobile Menu Toggle Logic (copied from admin-panel.php for consistency)
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
    </script>
</body>
</html>




