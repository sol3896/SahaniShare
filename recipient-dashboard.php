<?php
// recipient-dashboard.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

// Check if user is logged in and is a recipient
// Also check if the recipient's status is 'active' to ensure they can view donations
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'recipient' || $_SESSION['user_status'] !== 'active') {
    // If not logged in, not a recipient, or recipient is not active, redirect to login page
    header('Location: login-register.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$organization_name = $_SESSION['organization_name'];
$message = ''; // For success/error messages

// Check for messages from previous redirects (e.g., after submitting a request or feedback)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying it
}

$available_donations = [];
$my_requests = [];

// Establish a database connection
$conn = get_db_connection();

// --- Handle Request Item Form Submission ---
// This block processes the data when the request form inside the modal is submitted.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_item'])) {
    $donation_id_to_request = $_POST['donation_id'];
    $requested_quantity_str = $_POST['requested_quantity'];
    $requested_quantity = floatval($requested_quantity_str); // Convert to float for numeric comparison

    // Basic validation for requested quantity
    if ($requested_quantity <= 0) {
        $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Requested quantity must be positive.</div>';
    } else {
        // Step 1: Check if the donation exists and has enough quantity
        // Also ensure it's still approved and not expired
        $stmt_check_donation = $conn->prepare("SELECT quantity FROM donations WHERE id = ? AND status = 'approved' AND expiry_time > NOW()");
        $stmt_check_donation->bind_param("i", $donation_id_to_request);
        $stmt_check_donation->execute();
        $stmt_check_donation->bind_result($available_quantity);
        $stmt_check_donation->fetch();
        $stmt_check_donation->close();

        if ($available_quantity === null) {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Donation is no longer available or valid.</div>';
        } elseif ($requested_quantity > $available_quantity) {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Requested quantity exceeds available amount (' . htmlspecialchars($available_quantity) . ').</div>';
        } else {
            // Step 2: Check if the recipient has already made a pending request for this specific donation
            $stmt_check_existing_request = $conn->prepare("SELECT id FROM requests WHERE donation_id = ? AND recipient_id = ? AND status = 'pending'");
            $stmt_check_existing_request->bind_param("ii", $donation_id_to_request, $user_id);
            $stmt_check_existing_request->execute();
            $stmt_check_existing_request->store_result();

            if ($stmt_check_existing_request->num_rows > 0) {
                $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">You have already submitted a pending request for this item.</div>';
            } else {
                // Step 3: Insert the new request into the 'requests' table
                $stmt_insert_request = $conn->prepare("INSERT INTO requests (donation_id, recipient_id, requested_quantity, status) VALUES (?, ?, ?, 'pending')");
                // 'iid' specifies integer, integer, double for the bind_param types
                $stmt_insert_request->bind_param("iid", $donation_id_to_request, $user_id, $requested_quantity);

                if ($stmt_insert_request->execute()) {
                    $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Request submitted successfully!</div>';
                } else {
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error submitting request: ' . htmlspecialchars($stmt_insert_request->error) . '</div>';
                }
                $stmt_insert_request->close();
            }
            $stmt_check_existing_request->close();
        }
    }
    // Redirect back to the same page to prevent form resubmission and display message
    header('Location: recipient-dashboard.php');
    exit();
}


// --- Fetch Available Donations ---
// Retrieves donations that are 'approved' and have an expiry time in the future.
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
// Retrieves all requests made by the current recipient.
// Includes 'donation_id_for_feedback' to pass to the feedback modal.
$stmt = $conn->prepare("
    SELECT r.id, d.description, r.requested_quantity, d.unit, r.status, r.requested_at, d.id as donation_id_for_feedback
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

$conn->close(); // Close the main database connection
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Recipient Dashboard</title>
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
        /* Modal specific styles - Ensure display: none by default */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
            align-items: center; /* Center vertically via flex */
            justify-content: center; /* Center horizontally via flex */
            padding: 1rem; /* Add some padding for small screens */
        }
        .modal-content {
            background-color: #fefefe;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 500px;
            position: relative;
            animation: fadeIn 0.3s ease-out; /* Simple fade-in animation */
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .close-button {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        /* Star Rating Styles */
        .rating-stars {
            font-size: 1.8rem; /* Larger stars */
            color: #ccc; /* Default grey color for unselected stars */
            cursor: pointer;
            display: inline-block; /* Allows stars to sit side-by-side */
        }
        .rating-stars .fas.fa-star { /* Filled star */
            color: #FF9800; /* Accent Orange for filled stars */
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
            <?php echo $message; // Display messages from session ?>
            <p class="text-gray-700 mb-6">Here you can browse available food donations and manage your requests.</p>

            <!-- Available Donations Section -->
            <h2 class="text-2xl font-semibold text-neutral-dark mb-4">Available Donations</h2>
            <div class="mb-6 flex flex-col md:flex-row gap-4">
                <input type="text" placeholder="Search by food type or donor..." class="flex-grow">
                <select class="w-full md:w-auto">
                    <option value="">Filter by Category</option>
                    <option value="produce">Produce</option>
                    <option value="baked-goods">Baked Goods</option>
                    <option value="dairy">Dairy</option>
                    <option value="prepared-meals">Prepared Meals</option>
                    <option value="pantry-staples">Pantry Staples</option>
                    <option value="other">Other</option>
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
                                // Use photo_url if available, otherwise a placeholder
                                $img_src = !empty($donation['photo_url']) ? htmlspecialchars($donation['photo_url']) : 'https://placehold.co/400x250/EEEEEE/424242?text=No+Image';
                            ?>
                            <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($donation['description']); ?>" class="w-full h-40 object-cover rounded-md mb-4">
                            <h4 class="font-bold text-lg mb-1"><?php echo htmlspecialchars($donation['description']); ?></h4>
                            <p class="text-gray-700 text-sm mb-2">Quantity: <?php echo htmlspecialchars($donation['quantity']) . ' ' . htmlspecialchars($donation['unit']); ?></p>
                            <p class="text-red-500 text-sm font-medium mb-2"><i class="fas fa-clock mr-1"></i> Expires: <?php echo date('Y-m-d H:i', strtotime($donation['expiry_time'])); ?></p>
                            <p class="text-gray-600 text-sm mb-3">Donor: <?php echo htmlspecialchars($donation['donor_org']); ?> | Location: <?php echo htmlspecialchars($donation['pickup_location']); ?></p>

                            <!-- "Request Item" button triggers the modal -->
                            <button type="button" class="btn-primary !py-2 !px-4 text-sm request-item-btn"
                                data-donation-id="<?php echo $donation['id']; ?>"
                                data-donation-desc="<?php echo htmlspecialchars($donation['description']); ?>"
                                data-donation-quantity="<?php echo htmlspecialchars($donation['quantity']); ?>"
                                data-donation-unit="<?php echo htmlspecialchars($donation['unit']); ?>">
                                Request Item
                            </button>
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
                        <!-- Individual Request Item Display -->
                        <div class="flex items-center justify-between border-b pb-3 border-gray-200 last:border-b-0 last:pb-0">
                            <div>
                                <p class="font-semibold text-lg"><?php echo htmlspecialchars($request['requested_quantity']) . ' ' . htmlspecialchars($request['unit']) . ' ' . htmlspecialchars($request['description']); ?></p>
                                <p class="text-sm text-gray-600">Requested: <?php echo date('Y-m-d H:i', strtotime($request['requested_at'])); ?></p>
                            </div>
                            <div class="flex items-center gap-2">
                                <?php
                                    // Determine status tag styling
                                    $status_class = '';
                                    switch ($request['status']) {
                                        case 'pending': $status_class = 'status-pending'; break;
                                        case 'approved': $status_class = 'status-approved'; break;
                                        case 'collected': $status_class = 'status-fulfilled'; break; // Using 'fulfilled' for collected for consistent styling
                                        case 'rejected': $status_class = 'status-rejected'; break;
                                    }
                                ?>
                                <span class="status-tag <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($request['status'])); ?></span>

                                <?php if ($request['status'] === 'approved' || $request['status'] === 'collected'): ?>
                                    <!-- "Feedback" button appears if the request is approved or collected -->
                                    <button class="bg-blue-500 text-white text-xs font-semibold px-2 py-1 rounded-full hover:bg-blue-600 transition-colors feedback-btn"
                                            data-donation-id="<?php echo $request['donation_id_for_feedback']; ?>"
                                            data-recipient-id="<?php echo $user_id; ?>">
                                        <i class="fas fa-comment-alt mr-1"></i> Feedback
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <a href="#" class="text-primary-green hover:underline font-medium block mt-4 text-right">View All Requests <i class="fas fa-arrow-right ml-1"></i></a>
        </div>
    </main>

    <!-- Request Quantity Modal -->
    <div id="request-modal" class="modal">
        <div class="modal-content card">
            <span class="close-button" id="close-request-modal">&times;</span>
            <h3 class="text-xl font-semibold text-neutral-dark mb-4">Request Food Item</h3>
            <p class="mb-4">You are requesting: <span id="modal-donation-desc" class="font-bold text-primary-green"></span></p>
            <p class="mb-4">Available: <span id="modal-available-quantity" class="font-bold text-primary-green"></span></p>
            <form method="POST" action="recipient-dashboard.php" class="space-y-4">
                <input type="hidden" name="donation_id" id="modal-donation-id">
                <div>
                    <label for="requested-quantity" class="block text-sm font-medium text-gray-700 mb-1">Quantity to Request</label>
                    <input type="number" id="requested-quantity" name="requested_quantity" min="0.01" step="0.01" required>
                </div>
                <button type="submit" name="request_item" class="btn-primary">Submit Request</button>
            </form>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div id="feedback-modal" class="modal">
        <div class="modal-content card">
            <span class="close-button" id="close-feedback-modal">&times;</span>
            <h3 class="text-xl font-semibold text-neutral-dark mb-4">Submit Feedback</h3>
            <form method="POST" action="submit-feedback.php" class="space-y-4">
                <input type="hidden" name="donation_id" id="feedback-modal-donation-id">
                <input type="hidden" name="recipient_id" id="feedback-modal-recipient-id">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rating</label>
                    <div class="rating-stars flex space-x-1">
                        <i class="far fa-star text-gray-400 cursor-pointer" data-rating="1"></i>
                        <i class="far fa-star text-gray-400 cursor-pointer" data-rating="2"></i>
                        <i class="far fa-star text-gray-400 cursor-pointer" data-rating="3"></i>
                        <i class="far fa-star text-gray-400 cursor-pointer" data-rating="4"></i>
                        <i class="far fa-star text-gray-400 cursor-pointer" data-rating="5"></i>
                    </div>
                    <input type="hidden" name="rating" id="rating-value" required> <!-- Hidden input to store selected rating -->
                </div>
                <div>
                    <label for="comment" class="block text-sm font-medium text-gray-700 mb-1">Comment (Optional)</label>
                    <textarea id="comment" name="comment" rows="4" class="w-full"></textarea>
                </div>
                <button type="submit" name="submit_feedback" class="btn-primary">Submit Feedback</button>
            </form>
        </div>
    </div>

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

        // Request Modal Logic
        const requestModal = document.getElementById('request-modal');
        const closeRequestModalButton = document.getElementById('close-request-modal');
        const requestItemButtons = document.querySelectorAll('.request-item-btn');
        const modalDonationIdInput = document.getElementById('modal-donation-id');
        const modalDonationDescSpan = document.getElementById('modal-donation-desc');
        const modalAvailableQuantitySpan = document.getElementById('modal-available-quantity');
        const modalRequestedQuantityInput = document.getElementById('requested-quantity');

        requestItemButtons.forEach(button => {
            button.addEventListener('click', () => {
                const donationId = button.dataset.donationId;
                const donationDesc = button.dataset.donationDesc;
                const donationQuantity = parseFloat(button.dataset.donationQuantity); // Parse as float
                const donationUnit = button.dataset.donationUnit;

                modalDonationIdInput.value = donationId;
                modalDonationDescSpan.textContent = `${donationDesc}`;
                modalAvailableQuantitySpan.textContent = `${donationQuantity} ${donationUnit}`;
                modalRequestedQuantityInput.value = donationQuantity; // Pre-fill with available quantity
                modalRequestedQuantityInput.max = donationQuantity; // Set max to available quantity
                modalRequestedQuantityInput.min = 0.01; // Set minimum to a small positive value
                modalRequestedQuantityInput.step = 0.01; // Allow decimal quantities

                requestModal.style.display = 'flex'; // Show the modal using flex for centering
            });
        });

        closeRequestModalButton.addEventListener('click', () => {
            requestModal.style.display = 'none'; // Hide the modal
        });

        // Click outside the request modal to close it
        window.addEventListener('click', (event) => {
            if (event.target === requestModal) {
                requestModal.style.display = 'none';
            }
        });

        // Feedback Modal Logic
        const feedbackModal = document.getElementById('feedback-modal');
        const closeFeedbackModalButton = document.getElementById('close-feedback-modal');
        const feedbackButtons = document.querySelectorAll('.feedback-btn');
        const feedbackModalDonationIdInput = document.getElementById('feedback-modal-donation-id');
        const feedbackModalRecipientIdInput = document.getElementById('feedback-modal-recipient-id');
        const starRatingContainer = document.querySelector('.rating-stars');
        const stars = starRatingContainer.querySelectorAll('i');
        const ratingValueInput = document.getElementById('rating-value');
        let currentRating = 0; // To keep track of the selected rating

        // Event listeners for feedback buttons to open the modal
        feedbackButtons.forEach(button => {
            button.addEventListener('click', () => {
                const donationId = button.dataset.donationId;
                const recipientId = button.dataset.recipientId;

                feedbackModalDonationIdInput.value = donationId;
                feedbackModalRecipientIdInput.value = recipientId;

                // Reset stars and rating value when opening modal
                currentRating = 0;
                ratingValueInput.value = ''; // Clear hidden input
                stars.forEach(star => {
                    star.classList.remove('fas');
                    star.classList.add('far');
                    star.style.color = '#ccc'; // Reset color
                });

                feedbackModal.style.display = 'flex'; // Show the modal
            });
        });

        // Close feedback modal
        closeFeedbackModalButton.addEventListener('click', () => {
            feedbackModal.style.display = 'none'; // Hide the modal
        });

        // Click outside the feedback modal to close it
        window.addEventListener('click', (event) => {
            if (event.target === feedbackModal) {
                feedbackModal.style.display = 'none';
            }
        });

        // Star rating functionality
        stars.forEach(star => {
            star.addEventListener('mouseover', () => {
                const hoverRating = parseInt(star.dataset.rating);
                stars.forEach((s, index) => {
                    if (index < hoverRating) {
                        s.classList.remove('far');
                        s.classList.add('fas');
                        s.style.color = '#FF9800'; // Highlight color
                    } else {
                        s.classList.remove('fas');
                        s.classList.add('far');
                        s.style.color = '#ccc'; // Default color
                    }
                });
            });

            star.addEventListener('click', () => {
                currentRating = parseInt(star.dataset.rating);
                ratingValueInput.value = currentRating; // Set hidden input value
                // Persist the selected stars visually
                stars.forEach((s, index) => {
                    if (index < currentRating) {
                        s.classList.remove('far');
                        s.classList.add('fas');
                        s.style.color = '#FF9800';
                    } else {
                        s.classList.remove('fas');
                        s.classList.add('far');
                        s.style.color = '#ccc';
                    }
                });
            });
        });

        starRatingContainer.addEventListener('mouseleave', () => {
            // Revert to selected rating when mouse leaves container
            stars.forEach((star, index) => {
                if (index < currentRating) {
                    star.classList.remove('far');
                    star.classList.add('fas');
                    star.style.color = '#FF9800';
                } else {
                    star.classList.remove('fas');
                    star.classList.add('far');
                    star.style.color = '#ccc';
                }
            });
        });

    </script>
</body>
</html>
