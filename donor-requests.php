<?php
// donor-requests.php
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
$message = ''; // To store success or error messages

// Check for messages from previous redirects
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying it
}

$donations_with_requests = []; // To store donations and their associated requests

// Establish a database connection
$conn = get_db_connection();

// --- Handle Request Approval/Rejection/Dispatch/Collection ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['request_action']; // 'approve_request', 'reject_request', 'mark_dispatched', 'mark_collected'

    // Fetch request details to get donation_id, requested_quantity, and current status
    $stmt_fetch_request = $conn->prepare("SELECT donation_id, requested_quantity, status FROM requests WHERE id = ?");
    $stmt_fetch_request->bind_param("i", $request_id);
    $stmt_fetch_request->execute();
    $stmt_fetch_request->bind_result($donation_id, $requested_quantity, $current_request_status);
    $stmt_fetch_request->fetch();
    $stmt_fetch_request->close();

    if ($current_request_status === null) {
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error: Request not found or invalid.</div>';
        header('Location: donor-requests.php');
        exit();
    }
    
    $conn->begin_transaction(); // Start a transaction for atomicity

    try {
        $new_request_status = ''; // Will hold the new status for the request
        $perform_quantity_deduction = false; // Flag to control quantity deduction

        switch ($action) {
            case 'approve_request':
                if ($current_request_status !== 'pending') {
                    throw new Exception("Request is not pending and cannot be approved.");
                }
                $new_request_status = 'approved';
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Request approved!</div>';
                break;

            case 'reject_request':
                if ($current_request_status !== 'pending') {
                    throw new Exception("Request is not pending and cannot be rejected.");
                }
                $new_request_status = 'rejected';
                $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Request rejected.</div>';
                break;

            case 'mark_dispatched':
                if ($current_request_status !== 'approved') {
                    throw new Exception("Request is not approved and cannot be dispatched.");
                }
                $new_request_status = 'dispatched';
                $_SESSION['message'] = '<div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded-md mb-4" role="alert">Request marked as dispatched.</div>';
                break;

            case 'mark_collected':
                // Donor marks collected. This implies the item has been physically collected.
                // It can be marked collected from 'approved' (direct pickup) or 'dispatched' (after delivery).
                if ($current_request_status !== 'approved' && $current_request_status !== 'dispatched') {
                    throw new Exception("Request status (" . htmlspecialchars($current_request_status) . ") cannot be marked collected by donor.");
                }
                $new_request_status = 'collected';
                $perform_quantity_deduction = true; // Deduct quantity when donor marks collected
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Request marked as collected.</div>';
                break;

            default:
                throw new Exception("Invalid request action provided.");
        }

        // Apply the new status to the request
        $stmt_update_request_status = $conn->prepare("UPDATE requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt_update_request_status->bind_param("si", $new_request_status, $request_id);
        if (!$stmt_update_request_status->execute()) {
            throw new Exception("Failed to update request status to " . $new_request_status);
        }
        $stmt_update_request_status->close();

        // Perform quantity deduction ONLY if the action indicates collection and it hasn't been done
        // The quantity deduction should ideally only happen ONCE for a request when its status FIRST becomes 'collected'
        if ($perform_quantity_deduction) {
            // Re-fetch current donation quantity to avoid race conditions
            $stmt_fetch_donation_qty = $conn->prepare("SELECT quantity FROM donations WHERE id = ? AND donor_id = ? FOR UPDATE"); // FOR UPDATE locks the row
            $stmt_fetch_donation_qty->bind_param("ii", $donation_id, $user_id);
            $stmt_fetch_donation_qty->execute();
            $stmt_fetch_donation_qty->bind_result($current_donation_quantity);
            $stmt_fetch_donation_qty->fetch();
            $stmt_fetch_donation_qty->close();

            if ($current_donation_quantity === null) {
                throw new Exception("Donation not found or not owned by this donor. Quantity deduction aborted.");
            }

            // Ensure we don't deduct more than available (should be handled by request logic, but a safeguard)
            if ($requested_quantity > $current_donation_quantity) {
                throw new Exception("Requested quantity (" . $requested_quantity . ") exceeds available donation quantity (" . $current_donation_quantity . "). Quantity deduction aborted.");
            }

            $new_donation_quantity = $current_donation_quantity - $requested_quantity;

            // Update donation quantity
            $stmt_update_donation_qty = $conn->prepare("UPDATE donations SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt_update_donation_qty->bind_param("di", $new_donation_quantity, $donation_id);
            if (!$stmt_update_donation_qty->execute()) {
                throw new Exception("Failed to update donation quantity.");
            }
            $stmt_update_donation_qty->close();

            // If donation quantity becomes zero or less, mark it as fulfilled
            if ($new_donation_quantity <= 0) {
                $stmt_fulfill_donation = $conn->prepare("UPDATE donations SET status = 'fulfilled', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt_fulfill_donation->bind_param("i", $donation_id);
                if (!$stmt_fulfill_donation->execute()) {
                    throw new Exception("Failed to fulfill donation (final status update).");
                }
                $stmt_fulfill_donation->close();
            }
            $_SESSION['message'] .= '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Donation quantity reduced.</div>';

        }

        $conn->commit(); // Commit the transaction if all successful

    } catch (Exception $e) {
        $conn->rollback(); // Rollback on error
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Transaction failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }

    header('Location: donor-requests.php');
    exit();
}


// --- Fetch Donations and their Requests ---
// Select all donations belonging to the current donor
$stmt_donations = $conn->prepare("SELECT id, description, quantity, unit, expiry_time, status FROM donations WHERE donor_id = ? ORDER BY created_at DESC");
$stmt_donations->bind_param("i", $user_id);
$stmt_donations->execute();
$result_donations = $stmt_donations->get_result();

while ($donation = $result_donations->fetch_assoc()) {
    $donation_id = $donation['id'];
    $donation['requests'] = []; // Initialize an empty array for requests

    // Fetch all requests for this specific donation, sorted by status priority
    $stmt_requests = $conn->prepare("
        SELECT r.id as request_id, r.requested_quantity, r.status, r.requested_at, u.organization_name as recipient_org_name, u.email as recipient_email
        FROM requests r
        JOIN users u ON r.recipient_id = u.id
        WHERE r.donation_id = ?
        ORDER BY FIELD(r.status, 'pending', 'approved', 'dispatched', 'collected', 'rejected'), r.requested_at ASC
    ");
    $stmt_requests->bind_param("i", $donation_id);
    $stmt_requests->execute();
    $result_requests = $stmt_requests->get_result();
    while ($request = $result_requests->fetch_assoc()) {
        $donation['requests'][] = $request; // Add request to the donation's requests array
    }
    $stmt_requests->close();

    $donations_with_requests[] = $donation; // Add the donation (with its requests) to the main array
}
$stmt_donations->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Manage Requests</title>
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
        .request-item {
            @apply bg-gray-50 p-4 rounded-lg shadow-sm mb-3;
        }
        .request-item:last-child {
            margin-bottom: 0;
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
            <a href="donor-history.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">History</a>
            <a href="donor-requests.php" class="text-primary-green font-medium transition duration-200">Requests</a>
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
                <li><a href="donor-history.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">History</a></li>
                <li><a href="donor-requests.php" class="block text-primary-green font-medium py-2">Requests</a></li>
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
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">Requests for Your Donations</h1>
        <div class="card p-6">
            <?php echo $message; // Display messages ?>
            <p class="text-gray-700 mb-6">Here you can view and manage requests made by recipients for your donated food items.</p>

            <?php if (empty($donations_with_requests)): ?>
                <p class="text-gray-600 text-center">No donations with active requests found at the moment.</p>
            <?php else: ?>
                <?php foreach ($donations_with_requests as $donation): ?>
                    <div class="mb-8 p-6 bg-white rounded-lg shadow-md border border-gray-200">
                        <h3 class="text-2xl font-bold text-primary-green mb-3 flex items-center">
                            <i class="fas fa-box-open mr-2"></i>
                            <?php echo htmlspecialchars($donation['description']); ?>
                            <span class="ml-auto text-sm text-gray-600 font-normal">
                                Available: <?php echo htmlspecialchars($donation['quantity']) . ' ' . htmlspecialchars($donation['unit']); ?>
                            </span>
                        </h3>
                        <p class="text-gray-600 mb-4">Expires: <?php echo date('Y-m-d H:i', strtotime($donation['expiry_time'])); ?> | Status: <span class="status-tag <?php
                                $status_class = '';
                                switch ($donation['status']) {
                                    case 'pending': $status_class = 'status-pending'; break;
                                    case 'approved': $status_class = 'status-approved'; break;
                                    case 'fulfilled': $status_class = 'bg-green-700 text-white'; break; // Darker green for fulfilled donation
                                    case 'rejected': $status_class = 'status-rejected'; break;
                                }
                                echo $status_class;
                            ?>"><?php echo htmlspecialchars(ucfirst($donation['status'])); ?></span>
                        </p>

                        <h4 class="text-xl font-semibold text-neutral-dark mb-3">Incoming Requests:</h4>
                        <?php if (empty($donation['requests'])): ?>
                            <p class="text-gray-500">No requests for this donation yet.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($donation['requests'] as $request): ?>
                                    <div class="request-item flex flex-col md:flex-row items-start md:items-center justify-between">
                                        <div class="mb-2 md:mb-0">
                                            <p class="font-semibold text-lg"><?php echo htmlspecialchars($request['requested_quantity']) . ' ' . htmlspecialchars($donation['unit']) . ' requested by ' . htmlspecialchars($request['recipient_org_name']); ?></p>
                                            <p class="text-sm text-gray-600">Email: <?php echo htmlspecialchars($request['recipient_email']); ?> | Requested: <?php echo date('Y-m-d H:i', strtotime($request['requested_at'])); ?></p>
                                        </div>
                                        <div class="flex flex-wrap gap-2 md:ml-auto">
                                            <?php
                                                $request_status_class = '';
                                                switch ($request['status']) {
                                                    case 'pending': $request_status_class = 'status-pending'; break; // Orange
                                                    case 'approved': $request_status_class = 'status-approved'; break; // Primary Green
                                                    case 'dispatched': $request_status_class = 'bg-indigo-500 text-white'; break; // New color for dispatched
                                                    case 'collected': $request_status_class = 'status-fulfilled'; break; // Darker green for collected
                                                    case 'rejected': $request_status_class = 'status-rejected'; break; // Red
                                                }
                                            ?>
                                            <span class="status-tag <?php echo $request_status_class; ?>"><?php echo htmlspecialchars(ucfirst($request['status'])); ?></span>

                                            <?php if ($request['status'] === 'pending'): // Only show Approve/Reject for pending requests ?>
                                                <form method="POST" action="donor-requests.php" class="inline-block">
                                                    <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                    <button type="submit" name="request_action" value="approve_request" class="bg-primary-green text-white px-3 py-1 text-sm rounded-md hover:bg-primary-green-dark transition duration-200">
                                                        <i class="fas fa-check mr-1"></i> Approve
                                                    </button>
                                                </form>
                                                <form method="POST" action="donor-requests.php" class="inline-block">
                                                    <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                    <button type="submit" name="request_action" value="reject_request" class="bg-red-500 text-white px-3 py-1 text-sm rounded-md hover:bg-red-600 transition duration-200">
                                                        <i class="fas fa-times mr-1"></i> Reject
                                                    </button>
                                                </form>
                                            <?php elseif ($request['status'] === 'approved'): // Show "Mark Dispatched" and "Mark Collected (direct)" if approved ?>
                                                <form method="POST" action="donor-requests.php" class="inline-block">
                                                    <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                    <button type="submit" name="request_action" value="mark_dispatched" class="bg-blue-500 text-white px-3 py-1 text-sm rounded-md hover:bg-blue-600 transition duration-200">
                                                        <i class="fas fa-truck mr-1"></i> Mark Dispatched
                                                    </button>
                                                </form>
                                                <form method="POST" action="donor-requests.php" class="inline-block">
                                                    <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                    <button type="submit" name="request_action" value="mark_collected" class="bg-green-600 text-white px-3 py-1 text-sm rounded-md hover:bg-green-700 transition duration-200">
                                                        <i class="fas fa-handshake mr-1"></i> Mark Collected (Direct)
                                                    </button>
                                                </form>
                                            <?php elseif ($request['status'] === 'dispatched'): // Show "Mark Collected" if dispatched ?>
                                                <form method="POST" action="donor-requests.php" class="inline-block">
                                                    <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                    <button type="submit" name="request_action" value="mark_collected" class="bg-green-600 text-white px-3 py-1 text-sm rounded-md hover:bg-green-700 transition duration-200">
                                                        <i class="fas fa-handshake mr-1"></i> Mark Collected
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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

        mobileMenuOverlay.addEventListener('click', (event) => {
            if (event.target === mobileMenuOverlay) {
                mobileMenu.classList.remove('mobile-menu-open');
                mobileMenuOverlay.classList.add('hidden');
            }
        });
    </script>
</body>
</html>


