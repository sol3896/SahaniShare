<?php
// donor-dashboard.php
session_start();

// Include the database connection file
include_once dirname(__FILE__) . '/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'donor') {
    // If not logged in or not a donor, redirect to login page
    header('Location: login-register.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$organization_name = $_SESSION['organization_name'];

// --- Safely get user_email from session or database ---
$user_email = ''; // Initialize to empty string
if (isset($_SESSION['user_email'])) {
    $user_email = $_SESSION['user_email'];
} else {
    // If user_email is not in session, fetch it from the database
    $conn_temp = get_db_connection();
    $stmt_email = $conn_temp->prepare("SELECT email FROM users WHERE id = ?");
    if ($stmt_email) {
        $stmt_email->bind_param("i", $user_id);
        $stmt_email->execute();
        $stmt_email->bind_result($fetched_email);
        $stmt_email->fetch();
        $stmt_email->close();
        $user_email = $fetched_email;
        $_SESSION['user_email'] = $user_email; // Store it in session for future requests
    }
    $conn_temp->close();
}
// --- End Safely get user_email ---

$message = ''; // To store success or error messages for the donor
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying it
}

$conn = get_db_connection(); // Re-establish connection for main data fetches

// --- Fetch Donor's Document Status ---
$document_verified = false;
$document_path = null;
$document_rejection_reason = null; // Field for document-specific rejection reason

$stmt_doc = $conn->prepare("SELECT document_verified, document_path, rejection_reason FROM users WHERE id = ?");
if (!$stmt_doc) {
    die("Prepare failed for document status: " . $conn->error);
}
$stmt_doc->bind_param("i", $user_id);
if (!$stmt_doc->execute()) {
    die("Execute failed for document status: " . $stmt_doc->error);
}
$stmt_doc->bind_result($document_verified, $document_path, $document_rejection_reason);
$stmt_doc->fetch();
$stmt_doc->close();


// --- Fetch Recent Donations (Your Original Code) ---
$recent_donations = [];
$stmt = $conn->prepare("SELECT description, quantity, unit, created_at, status FROM donations WHERE donor_id = ? ORDER BY created_at DESC LIMIT 3");
if (!$stmt) { die("Prepare failed for recent donations: " . $conn->error); }
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) { die("Execute failed for recent donations: " . $stmt->error); }
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_donations[] = $row;
}
$stmt->close();

// --- Fetch Recipient Feedback (Your Original Code) ---
$recipient_feedback = [];
$stmt = $conn->prepare("
    SELECT f.comment, f.rating, u.organization_name as recipient_org_name
    FROM feedback f
    JOIN requests r ON f.donation_id = r.donation_id AND f.recipient_id = r.recipient_id -- Link feedback to specific request for donation
    JOIN donations d ON r.donation_id = d.id
    JOIN users u ON f.recipient_id = u.id
    WHERE d.donor_id = ?
    ORDER BY f.created_at DESC LIMIT 2
");
if (!$stmt) { die("Prepare failed for recipient feedback: " . $conn->error); }
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) { die("Execute failed for recipient feedback: " . $stmt->error); }
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recipient_feedback[] = $row;
}
$stmt->close();

// --- Handle Document Resubmission (New Feature) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_document'])) {
    // Re-establish connection as it might have been closed by previous operations or if this is a fresh POST
    if (!isset($conn) || !$conn->ping()) {
        $conn = get_db_connection();
    }

    $upload_dir = 'uploads/documents/'; // Directory to store uploaded documents
    // Ensure the directory exists and is writable
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['document_file']['tmp_name'];
        $file_name = $_FILES['document_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        if (in_array($file_ext, $allowed_ext)) {
            // Generate a unique file name to prevent conflicts
            $new_file_name = uniqid('doc_', true) . '.' . $file_ext;
            $dest_path = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp_path, $dest_path)) {
                // Update user's document path and set document_verified to FALSE (for re-review)
                $stmt_update_doc = $conn->prepare("UPDATE users SET document_path = ?, document_verified = FALSE, rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                if (!$stmt_update_doc) {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database prepare error: ' . htmlspecialchars($conn->error) . '</div>';
                } else {
                    $stmt_update_doc->bind_param("si", $dest_path, $user_id);
                    if ($stmt_update_doc->execute()) {
                        $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Document uploaded successfully! It will be reviewed by an administrator.</div>';
                        // Update local variables to reflect new state
                        $document_path = $dest_path;
                        $document_verified = false;
                        $document_rejection_reason = null;
                    } else {
                        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database update error: ' . htmlspecialchars($stmt_update_doc->error) . '</div>';
                    }
                    $stmt_update_doc->close();
                }
            } else {
                $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to move uploaded file. Check directory permissions.</div>';
            }
        } else {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid file type. Allowed types: PDF, DOC, DOCX, JPG, JPEG, PNG.</div>';
        }
    } elseif (isset($_FILES['document_file']) && $_FILES['document_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">File upload error: ' . htmlspecialchars($_FILES['document_file']['error']) . '</div>';
    } else {
        // Only show this message if a file was expected but not provided
        // For donors, document upload is optional at registration, but required for resubmission
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Please select a file to upload.</div>';
    }
    header('Location: donor-dashboard.php'); // Redirect to prevent form resubmission
    exit();
}

// Close the main connection before rendering HTML
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
    <!-- Link to external style.css (ensure this file exists and contains your base styles) -->
    <link rel="stylesheet" href="style.css">
    
    <!-- Inline style to apply Inter as base font (Montserrat is applied in style.css for headings) -->
    <style>
        /* Define custom colors here to match login-register.php */
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
            display: flex; /* Use flexbox for main layout */
            flex-direction: row; /* Default to row for desktop (sidebar and main content side-by-side) */
        }
        /* Overriding some styles from style.css for consistency and direct control */
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Montserrat', sans-serif;
            color: var(--neutral-dark); /* Ensure headings use the defined neutral-dark */
        }

        /* Sidebar and Main Content Layout */
        .sidebar {
            width: 250px;
            flex-shrink: 0; /* Prevent sidebar from shrinking */
            background-color: var(--primary-green); /* Use custom variable */
            color: white;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            min-height: 100vh; /* Ensure it takes full height */
        }
        .main-content {
            flex-grow: 1;
            padding: 1rem 2rem; /* p-4 md:p-8 */
            /* Removed margin-left from here as it will be handled by media query */
        }
        @media (min-width: 769px) { /* Apply desktop styles for screens larger than 768px */
            .main-content {
                margin-left: 250px; /* Offset for sidebar on desktop */
            }
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column; /* Stack on mobile */
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                min-height: auto; /* Reset for mobile */
            }
            .main-content {
                margin-left: 0; /* Crucial: Remove margin on mobile */
            }
            /* Ensure mobile menu opens correctly */
            #mobile-menu.mobile-menu-open {
                transform: translateX(0) !important;
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
        .status-verified { @apply bg-green-100 text-green-800; }
        .status-unverified { @apply bg-red-100 text-red-800; }
        .status-pending-review { @apply bg-orange-100 text-orange-800; } /* For newly submitted docs */

        /* Buttons matching the new green */
        .btn-primary-green {
            background-color: var(--primary-green);
            color: white;
            @apply px-4 py-2 rounded-md hover:bg-primary-green-dark transition-colors;
        }
        .btn-blue {
            @apply bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors;
        }
        .text-primary-green {
            color: var(--primary-green);
        }
        .hover\:text-primary-green:hover {
            color: var(--primary-green);
        }
        .text-accent-orange {
            color: var(--accent-orange);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col md:flex-row">

    <!-- Top Navigation Bar for Desktop & Mobile Header (Your Original Code) -->
    <header class="bg-white shadow-md py-4 px-6 flex items-center justify-between sticky top-0 z-50 md:hidden"> <!-- Hide on desktop, show on mobile -->
        <div class="flex items-center">
            <!-- SahaniShare Logo Placeholder -->
            <div class="text-primary-green text-2xl font-bold mr-2">
                <i class="fas fa-hand-holding-heart"></i> SahaniShare
            </div>
        </div>
        <!-- Mobile Hamburger Icon -->
        <button id="mobile-menu-button" class="p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-green">
            <i class="fas fa-bars text-neutral-dark text-xl"></i>
        </button>
    </header>

    <!-- Sidebar Navigation (New structure for donor dashboard) -->
    <aside class="sidebar bg-primary-green text-white flex flex-col p-6 shadow-lg hidden md:flex"> <!-- Hidden on mobile, flex on desktop -->
        <div class="text-3xl font-bold mb-8 text-center">
            <i class="fas fa-hand-holding-heart"></i> Donor Panel
        </div>
        <nav class="flex-grow">
            <ul class="space-y-4">
                <li>
                    <a href="donor-dashboard.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors bg-primary-green-dark">
                        <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="add-donation.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-plus-circle mr-3"></i> Add New Donation
                    </a>
                </li>
                <li>
                    <a href="donor-history.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-boxes mr-3"></i> My Donations
                    </a>
                </li>
                <li>
                    <a href="donor-requests.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-inbox mr-3"></i> Incoming Requests
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-bell mr-3"></i> Notifications <span class="ml-auto bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">3</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-user-circle mr-3"></i> Profile
                    </a>
                </li>
            </ul>
        </nav>
        <div class="mt-8 text-center">
            <p class="text-sm font-light">Logged in as:</p>
            <p class="font-medium"><?php echo htmlspecialchars($organization_name); ?></p>
            <p class="text-xs italic">(Donor)</p>
            <a href="logout.php" class="mt-4 inline-block bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md transition-colors text-sm">
                <i class="fas fa-sign-out-alt mr-2"></i> Logout
            </a>
        </div>
    </aside>

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
    <main class="main-content flex-grow p-4 md:p-8">
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">Welcome, <?php echo htmlspecialchars($organization_name); ?>!</h1>

        <?php echo $message; // Display messages from session ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            <!-- Document Verification Status (New Feature) -->
            <div class="card col-span-1">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Document Verification</h2>
                <p class="mb-2">
                    Current Status: 
                    <span class="status-tag <?php echo $document_verified ? 'status-verified' : 'status-unverified'; ?>">
                        <?php echo $document_verified ? 'Verified' : 'Unverified'; ?>
                    </span>
                </p>
                <?php if (!$document_verified && !empty($document_path)): ?>
                    <p class="text-sm text-gray-600 mb-2">
                        Your last submitted document: <a href="<?php echo htmlspecialchars($document_path); ?>" target="_blank" class="text-blue-500 hover:underline">View Document</a>
                    </p>
                    <?php if (!empty($document_rejection_reason)): ?>
                        <p class="text-red-600 text-sm mb-4">
                            Reason for Unverification: <strong><?php echo htmlspecialchars($document_rejection_reason); ?></strong>
                        </p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-700 mb-4">Please re-submit your updated documentation for review.</p>
                <?php elseif (!$document_verified && empty($document_path)): ?>
                     <p class="text-sm text-gray-700 mb-4">No document has been submitted yet. Please upload your documentation for verification.</p>
                <?php endif; ?>

                <form action="donor-dashboard.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <div>
                        <label for="document_file" class="block text-sm font-medium text-gray-700 mb-1">Upload Document (PDF, DOCX, JPG, PNG)</label>
                        <input type="file" name="document_file" id="document_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm text-gray-500
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-md file:border-0
                            file:text-sm file:font-semibold
                            file:bg-green-50 file:text-green-700
                            hover:file:bg-green-100" required>
                    </div>
                    <button type="submit" name="upload_document" class="btn-primary-green">
                        <i class="fas fa-upload mr-2"></i> Re-submit Document
                    </button>
                </form>
            </div>

            <!-- Quick Actions Card (Your Original Code) -->
            <div class="card col-span-1">
                <h3 class="text-xl font-semibold text-neutral-dark mb-4">Quick Actions</h3>
                <a href="add-donation.php" class="btn-primary-green mb-4 block text-center">
                    <i class="fas fa-plus mr-2"></i> Add New Donation
                </a>
                <div class="flex flex-col space-y-2">
                    <a href="donor-history.php" class="text-primary-green hover:underline font-medium"><i class="fas fa-clipboard-list mr-2"></i> View Donation History</a>
                    <a href="donor-requests.php" class="text-primary-green hover:underline font-medium"><i class="fas fa-inbox mr-2"></i> View Incoming Requests</a>
                    <a href="#" class="text-primary-green hover:underline font-medium"><i class="fas fa-comments mr-2"></i> View Recipient Feedback</a>
                </div>
            </div>

            <!-- Donation Notifications (New Feature - Placeholder for now) -->
            <div class="card col-span-1">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Donation Notifications</h2>
                <div class="space-y-3">
                    <?php
                    // Placeholder for fetching and displaying notifications
                    // In the next step, we'll fetch from a 'donor_notifications' table
                    $dummy_notifications = [
                        ['type' => 'approved', 'message' => 'Your donation of "10 Kgs of Rice" has been approved!', 'time' => '2025-07-01 10:30'],
                        ['type' => 'rejected', 'message' => 'Your donation of "Expired Canned Goods" was rejected. Reason: Expired items.', 'time' => '2025-06-28 15:00'],
                        ['type' => 'approved', 'message' => 'Your donation of "Winter Clothes" has been approved!', 'time' => '2025-06-25 09:00'],
                    ];

                    if (empty($dummy_notifications)) {
                        echo '<p class="text-gray-600">No new notifications.</p>';
                    } else {
                        foreach ($dummy_notifications as $notification) {
                            $alert_class = '';
                            $icon_class = '';
                            switch ($notification['type']) {
                                case 'approved':
                                    $alert_class = 'bg-green-100 border-green-400 text-green-700';
                                    $icon_class = 'fas fa-check-circle text-green-500';
                                    break;
                                case 'rejected':
                                    $alert_class = 'bg-red-100 border-red-400 text-red-700';
                                    $icon_class = 'fas fa-times-circle text-red-500';
                                    break;
                                default:
                                    $alert_class = 'bg-blue-100 border-blue-400 text-blue-700';
                                    $icon_class = 'fas fa-info-circle text-blue-500';
                                    break;
                            }
                            echo '<div class="flex items-start p-3 rounded-md border ' . $alert_class . '">';
                            echo '    <i class="' . $icon_class . ' mt-1 mr-3"></i>';
                            echo '    <div>';
                            echo '        <p class="font-medium">' . htmlspecialchars($notification['message']) . '</p>';
                            echo '        <p class="text-xs text-gray-600">' . date('M d, Y H:i', strtotime($notification['time'])) . '</p>';
                            echo '    </div>';
                            echo '</div>';
                        }
                    }
                    ?>
                </div>
                <button class="mt-4 btn-blue">
                    <i class="fas fa-eye mr-2"></i> View All Notifications
                </button>
            </div>


            <!-- Recent Donations Card (Your Original Code) -->
            <div class="card col-span-1 md:col-span-2" id="recent-donations">
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

            <!-- Recipient Feedback Card (Your Original Code) -->
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
        console.log("donor-dashboard.php: JavaScript loaded.");

        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');

        // Toggle mobile menu
        if (mobileMenuButton) {
            mobileMenuButton.addEventListener('click', () => {
                mobileMenu.classList.add('mobile-menu-open');
                mobileMenuOverlay.classList.remove('hidden');
                console.log("Mobile menu button clicked.");
            });
        }

        if (closeMobileMenuButton) {
            closeMobileMenuButton.addEventListener('click', () => {
                mobileMenu.classList.remove('mobile-menu-open');
                mobileMenuOverlay.classList.add('hidden');
                console.log("Close mobile menu button clicked.");
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



