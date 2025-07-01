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

$user_id = $_SESSION['user_id']; // Current logged-in donor's ID
$organization_name = $_SESSION['organization_name'];
$message = ''; // For success or error messages

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying it
}

$requests_for_my_donations = []; // Requests made for this donor's donations
$my_fulfilled_donations = []; // Donations that have been completely fulfilled

$conn = get_db_connection();

// --- Handle Request Status Update from Donor ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    $donation_id = $_POST['donation_id']; // Passed from the form for quantity deduction logic

    $conn->begin_transaction(); // Start a transaction for atomicity

    try {
        // First, verify this request belongs to one of the donor's donations and is in a valid state
        $stmt_verify = $conn->prepare("
            SELECT r.status, r.requested_quantity, r.recipient_id, d.donor_id, d.quantity AS donation_available_quantity
            FROM requests r
            JOIN donations d ON r.donation_id = d.id
            WHERE r.id = ? AND d.donor_id = ? FOR UPDATE -- Lock row for update
        ");
        $stmt_verify->bind_param("ii", $request_id, $user_id);
        $stmt_verify->execute();
        $stmt_verify->bind_result($current_request_status, $requested_quantity, $recipient_id, $donor_id_check, $donation_available_quantity);
        $stmt_verify->fetch();
        $stmt_verify->close();

        if ($donor_id_check !== $user_id) {
            throw new Exception("Unauthorized: Request does not belong to your donations.");
        }

        $notification_message = '';
        $notification_type = 'request_status_change';
        $notification_link = 'recipient-dashboard.php#my-requests'; // Link for recipient

        switch ($action) {
            case 'approve':
                if ($current_request_status !== 'pending') {
                    throw new Exception("Request cannot be approved from its current status: " . htmlspecialchars($current_request_status));
                }
                $stmt_update = $conn->prepare("UPDATE requests SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt_update->bind_param("i", $request_id);
                if (!$stmt_update->execute()) {
                    throw new Exception("Failed to approve request: " . htmlspecialchars($stmt_update->error));
                }
                $notification_message = "Your request for " . htmlspecialchars($requested_quantity) . " units has been <strong>approved</strong>!";
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Request approved successfully!</div>';
                break;

            case 'dispatch':
                if ($current_request_status !== 'approved') {
                    throw new Exception("Request cannot be dispatched from its current status: " . htmlspecialchars($current_request_status));
                }
                $stmt_update = $conn->prepare("UPDATE requests SET status = 'dispatched', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt_update->bind_param("i", $request_id);
                if (!$stmt_update->execute()) {
                    throw new Exception("Failed to dispatch request: " . htmlspecialchars($stmt_update->error));
                }
                $notification_message = "Your request for " . htmlspecialchars($requested_quantity) . " units has been <strong>dispatched</strong> for pickup!";
                $_SESSION['message'] = '<div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded-md mb-4" role="alert">Request marked as dispatched!</div>';
                break;

            case 'mark_collected':
                // Donor marks as collected. This should also deduct quantity.
                if ($current_request_status !== 'approved' && $current_request_status !== 'dispatched') {
                    throw new Exception("Request cannot be marked collected from its current status: " . htmlspecialchars($current_request_status));
                }

                // Check if donation quantity is sufficient before deduction
                if ($donation_available_quantity < $requested_quantity) {
                    throw new Exception("Not enough quantity left in donation (" . htmlspecialchars($donation_available_quantity) . ") to mark this request as collected for " . htmlspecialchars($requested_quantity) . ".");
                }

                $new_donation_quantity = $donation_available_quantity - $requested_quantity;

                // Update request status to 'collected'
                $stmt_update_request = $conn->prepare("UPDATE requests SET status = 'collected', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt_update_request->bind_param("i", $request_id);
                if (!$stmt_update_request->execute()) {
                    throw new Exception("Failed to mark request as collected (request status update).");
                }
                $stmt_update_request->close(); // Close this statement before starting another

                // Update donation quantity
                $stmt_update_donation_qty = $conn->prepare("UPDATE donations SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt_update_donation_qty->bind_param("di", $new_donation_quantity, $donation_id);
                if (!$stmt_update_donation_qty->execute()) {
                    throw new Exception("Failed to update donation quantity after collection.");
                }
                $stmt_update_donation_qty->close(); // Close this statement before starting another

                // If donation quantity becomes zero or less, mark it as fulfilled
                if ($new_donation_quantity <= 0) {
                    $stmt_fulfill_donation = $conn->prepare("UPDATE donations SET status = 'fulfilled', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt_fulfill_donation->bind_param("i", $donation_id);
                    if (!$stmt_fulfill_donation->execute()) {
                        throw new Exception("Failed to fulfill donation (final status update).");
                    }
                    $stmt_fulfill_donation->close();
                }

                $notification_message = "Your request for " . htmlspecialchars($requested_quantity) . " units has been <strong>collected</strong>!";
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Request marked as collected! Donation quantity updated.</div>';
                break;

            case 'reject':
                if ($current_request_status === 'collected' || $current_request_status === 'rejected') {
                    throw new Exception("Request cannot be rejected from its current status: " . htmlspecialchars($current_request_status));
                }
                $stmt_update = $conn->prepare("UPDATE requests SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt_update->bind_param("i", $request_id);
                if (!$stmt_update->execute()) {
                    throw new Exception("Failed to reject request: " . htmlspecialchars($stmt_update->error));
                }
                $notification_message = "Your request for " . htmlspecialchars($requested_quantity) . " units has been <strong>rejected</strong>. Please check other available donations.";
                $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Request rejected.</div>';
                break;

            default:
                throw new Exception("Invalid action.");
        }

        // --- Insert Notification for Recipient ---
        if (!empty($notification_message)) {
            $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
            $stmt_notify->bind_param("isss", $recipient_id, $notification_type, $notification_message, $notification_link);
            if (!$stmt_notify->execute()) {
                error_log("Failed to insert notification for recipient_id " . $recipient_id . ": " . $stmt_notify->error);
                // Don't throw a fatal error for notification failure, but log it.
            }
            $stmt_notify->close();
        }

        $conn->commit(); // Commit the transaction if all operations succeed
    } catch (Exception $e) {
        $conn->rollback(); // Rollback on any error
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    } finally {
        if (isset($stmt_update) && $stmt_update !== null) $stmt_update->close();
        $conn->close();
    }
    // Always redirect to prevent form resubmission, ensuring a fresh GET request
    header('Location: donor-requests.php');
    exit();
}

// Re-establish connection after POST processing if needed for GET requests
if (!isset($conn) || !$conn->ping()) {
    $conn = get_db_connection();
}

// --- Fetch all requests for donor's donations ---
$stmt = $conn->prepare("
    SELECT r.id AS request_id, r.requested_quantity, r.status AS request_status, r.requested_at,
           d.id AS donation_id, d.description AS donation_description, d.quantity AS donation_quantity, d.unit AS donation_unit,
           u.organization_name AS recipient_org, u.email AS recipient_email
    FROM requests r
    JOIN donations d ON r.donation_id = d.id
    JOIN users u ON r.recipient_id = u.id
    WHERE d.donor_id = ?
    ORDER BY r.requested_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requests_for_my_donations[] = $row;
}
$stmt->close();

// --- Fetch donor's donations that are fulfilled (quantity 0) ---
// FIXED: Added 'status' to the SELECT statement
$stmt = $conn->prepare("
    SELECT id, description, quantity, unit, expiry_time, created_at, status
    FROM donations
    WHERE donor_id = ? AND status = 'fulfilled'
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $my_fulfilled_donations[] = $row;
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Donor Requests</title>
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
        /* Status tags */
        .status-tag {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-pending { @apply bg-orange-100 text-orange-800; }
        .status-approved { @apply bg-green-100 text-green-800; }
        /* FIXED: Added !important to ensure these styles are applied */
        .status-dispatched { background-color: #4f46e5 !important; color: white !important; } /* indigo-500 */
        .status-collected { background-color: #9333ea !important; color: white !important; } /* purple-600 */
        .status-rejected { @apply bg-red-100 text-red-800; }
        .status-fulfilled { @apply bg-gray-600 text-white; }
        /* Fallback for unknown status, ensure it's visible */
        .bg-gray-200.text-gray-700 { @apply bg-gray-200 text-gray-700; }
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
            <a href="donor-requests.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">My Requests</a>
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
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">Requests for Your Donations</h1>
        <div class="card p-6">
            <?php echo $message; // Display messages from session ?>

            <div class="mb-8">
                <h2 class="text-2xl font-semibold text-neutral-dark mb-4">Pending/Active Requests</h2>
                <?php if (empty($requests_for_my_donations)): ?>
                    <p class="text-gray-600">No requests have been made for your donations yet, or all requests have been fulfilled.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                            <thead class="bg-gray-200">
                                <tr>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Donation</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Requested Qty</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Requested By</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Requested On</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Status</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests_for_my_donations as $request): ?>
                                    <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                        <td class="py-3 px-4 text-sm text-gray-800">
                                            <?php echo htmlspecialchars($request['donation_description']); ?>
                                            <span class="block text-xs text-gray-500">Available: <?php echo htmlspecialchars($request['donation_quantity']) . ' ' . htmlspecialchars($request['donation_unit']); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($request['requested_quantity']) . ' ' . htmlspecialchars($request['donation_unit']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800">
                                            <?php echo htmlspecialchars($request['recipient_org']); ?>
                                            <span class="block text-xs text-gray-500"><?php echo htmlspecialchars($request['recipient_email']); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo date('Y-m-d H:i', strtotime($request['requested_at'])); ?></td>
                                        <td class="py-3 px-4 text-sm">
                                            <?php
                                                $status_class = '';
                                                switch ($request['request_status']) {
                                                    case 'pending': $status_class = 'status-pending'; break;
                                                    case 'approved': $status_class = 'status-approved'; break;
                                                    case 'dispatched': $status_class = 'status-dispatched'; break;
                                                    case 'collected': $status_class = 'status-collected'; break;
                                                    case 'rejected': $status_class = 'status-rejected'; break;
                                                    default: $status_class = 'bg-gray-200 text-gray-700'; break; // Fallback for unknown status
                                                }
                                            ?>
                                            <span class="status-tag <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($request['request_status'])); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if ($request['request_status'] === 'pending'): ?>
                                                    <form method="POST" action="donor-requests.php" class="inline-block">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                        <input type="hidden" name="donation_id" value="<?php echo $request['donation_id']; ?>">
                                                        <button type="submit" name="action" value="approve" class="bg-green-500 text-white px-3 py-1 rounded-md hover:bg-green-600 transition-colors text-xs">Approve</button>
                                                    </form>
                                                    <form method="POST" action="donor-requests.php" class="inline-block">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                        <input type="hidden" name="donation_id" value="<?php echo $request['donation_id']; ?>">
                                                        <button type="submit" name="action" value="reject" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs">Reject</button>
                                                    </form>
                                                <?php elseif ($request['request_status'] === 'approved'): ?>
                                                    <form method="POST" action="donor-requests.php" class="inline-block">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                        <input type="hidden" name="donation_id" value="<?php echo $request['donation_id']; ?>">
                                                        <button type="submit" name="action" value="dispatch" class="bg-indigo-500 text-white px-3 py-1 rounded-md hover:bg-indigo-600 transition-colors text-xs">Dispatch</button>
                                                    </form>
                                                    <form method="POST" action="donor-requests.php" class="inline-block">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                        <input type="hidden" name="donation_id" value="<?php echo $request['donation_id']; ?>">
                                                        <button type="submit" name="action" value="mark_collected" class="bg-purple-500 text-white px-3 py-1 rounded-md hover:bg-purple-600 transition-colors text-xs">Mark Collected</button>
                                                    </form>
                                                <?php elseif ($request['request_status'] === 'dispatched'): ?>
                                                    <form method="POST" action="donor-requests.php" class="inline-block">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                        <input type="hidden" name="donation_id" value="<?php echo $request['donation_id']; ?>">
                                                        <button type="submit" name="action" value="mark_collected" class="bg-purple-500 text-white px-3 py-1 rounded-md hover:bg-purple-600 transition-colors text-xs">Mark Collected</button>
                                                    </form>
                                                <?php elseif ($request['request_status'] === 'collected' || $request['request_status'] === 'rejected'): ?>
                                                    <span class="text-gray-500 text-xs">No actions available</span>
                                                <?php else: ?>
                                                    <span class="text-red-500 text-xs">Error: Unknown Status</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Fulfilled Donations Section -->
            <div>
                <h2 class="text-2xl font-semibold text-neutral-dark mb-4">My Fulfilled Donations</h2>
                <?php if (empty($my_fulfilled_donations)): ?>
                    <p class="text-gray-600">No donations have been fully fulfilled yet.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                            <thead class="bg-gray-200">
                                <tr>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Description</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Original Qty</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Expiry</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Created On</th>
                                    <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($my_fulfilled_donations as $donation): ?>
                                    <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($donation['description']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($donation['quantity']) . ' ' . htmlspecialchars($donation['unit']); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo date('Y-m-d H:i', strtotime($donation['expiry_time'])); ?></td>
                                        <td class="py-3 px-4 text-sm text-gray-800"><?php echo date('Y-m-d', strtotime($donation['created_at'])); ?></td>
                                        <td class="py-3 px-4 text-sm">
                                            <!-- Accessing $donation['status'] is now safe -->
                                            <span class="status-tag status-fulfilled"><?php echo htmlspecialchars(ucfirst($donation['status'])); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Mobile Menu Logic
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');

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







