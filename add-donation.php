<?php
// add-donation.php
session_start();

// Include the database connection file
include_once 'db_connection.php';

// Check if user is logged in and is a donor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'donor') {
    header('Location: login-register.php');
    exit();
}

$message = '';

$conn = get_db_connection();

// --- Fetch Donation Categories for the dropdown ---
$donation_categories = [];
$stmt_categories = $conn->prepare("SELECT id, name FROM donation_categories ORDER BY name ASC");
if ($stmt_categories && $stmt_categories->execute()) {
    $result_categories = $stmt_categories->get_result();
    while ($row = $result_categories->fetch_assoc()) {
        $donation_categories[] = $row;
    }
    $stmt_categories->close();
} else {
    error_log("SahaniShare Error: Failed to fetch donation categories for add-donation.php: " . $conn->error);
    $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error loading donation categories. Please try again later.</div>';
}

// --- Handle Add Donation Form Submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_donation'])) {
    $donor_id = $_SESSION['user_id'];
    $description = trim($_POST['food_description']);
    $quantity = floatval($_POST['quantity']);
    $unit = trim($_POST['unit']);
    $expiry_time = trim($_POST['expiry_time']); // Format:YYYY-MM-DDTHH:MM
    $category_id = filter_var($_POST['category_id'], FILTER_VALIDATE_INT); // Get category ID
    $pickup_location = trim($_POST['location']);
    $photo_url = null; // Placeholder for photo URL

    // Basic validation
    if (empty($description) || $quantity <= 0 || empty($unit) || empty($expiry_time) || empty($pickup_location)) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Please fill in all required fields (Description, Quantity, Unit, Expiry Time, Pickup Location). Quantity must be positive.</div>';
    } elseif ($category_id === false || $category_id <= 0) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Please select a valid donation category.</div>';
    } else {
        // Re-establish connection if it was closed or for a fresh POST
        if (!isset($conn) || !$conn->ping()) {
            $conn = get_db_connection();
        }

        // Handle photo upload
        // CORRECTED: Upload directory now points directly to 'uploads/'
        $upload_dir = 'uploads/'; 
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['photo']['tmp_name'];
            $file_name = $_FILES['photo']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif']; // Common image formats

            if (in_array($file_ext, $allowed_ext)) {
                // Generate a unique file name to prevent conflicts
                $new_file_name = uniqid('donation_img_', true) . '.' . $file_ext;
                $dest_path = $upload_dir . $new_file_name;

                if (move_uploaded_file($file_tmp, $dest_path)) {
                    $photo_url = $dest_path;
                } else {
                    $message .= '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Warning: Failed to upload photo. Donation submitted without photo.</div>';
                }
            } else {
                $message .= '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid photo file type. Allowed types: JPG, JPEG, PNG, GIF.</div>';
            }
        }

        // Insert donation into database, now including category_id
        $stmt = $conn->prepare("INSERT INTO donations (donor_id, description, quantity, unit, expiry_time, category_id, pickup_location, photo_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        // 's' for string, 'd' for double (float), 'i' for integer
        if (!$stmt) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database prepare error: ' . htmlspecialchars($conn->error) . '</div>';
        } else {
            $stmt->bind_param("isdsssss", $donor_id, $description, $quantity, $unit, $expiry_time, $category_id, $pickup_location, $photo_url);

            if ($stmt->execute()) {
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Donation submitted successfully! Awaiting approval.</div>';
                header('Location: donor-dashboard.php'); // Redirect to dashboard
                exit();
            } else {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error adding donation: ' . htmlspecialchars($stmt->error) . '</div>';
            }
            $stmt->close();
        }
    }
}

// Close the main connection if it's still open
if (isset($conn) && $conn->ping()) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Add New Donation</title>
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
            flex-direction: column; /* Stack on mobile */
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Montserrat', sans-serif;
            color: var(--neutral-dark); /* Ensure headings use the defined neutral-dark */
        }
        .btn-primary {
            background-color: var(--primary-green);
            color: white;
            @apply px-4 py-2 rounded-md hover:bg-primary-green-dark transition-colors;
        }
        .btn-secondary {
            background-color: #6B7280; /* bg-gray-500 */
            color: white;
            @apply px-4 py-2 rounded-md hover:bg-gray-600 transition-colors;
        }
        /* Style for form inputs and textareas */
        input[type="text"],
        input[type="number"],
        input[type="datetime-local"],
        select,
        textarea {
            @apply w-full p-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-green focus:border-primary-green;
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
            <a href="donor-dashboard.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Dashboard</a>
            <a href="add-donation.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Add Donation</a>
            <a href="donor-history.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">History</a>
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
    <main class="flex-grow container mx-auto p-4 md:p-8 flex items-center justify-center">
        <!-- Add Donation Form Section -->
        <section class="w-full max-w-2xl">
            <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center">New Food Donation</h1>
            <div class="card">
                <?php echo $message; // Display messages ?>
                <form class="space-y-6" method="POST" action="add-donation.php" enctype="multipart/form-data">
                    <div>
                        <label for="food-description" class="block text-sm font-medium text-gray-700 mb-1">Food Description</label>
                        <textarea id="food-description" name="food_description" rows="3" placeholder="e.g., 20 kgs of fresh tomatoes, 50 loaves of bread, 100 servings of prepared chicken curry..." required></textarea>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="quantity" class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                            <div class="flex">
                                <input type="number" id="quantity" name="quantity" placeholder="e.g., 20" class="flex-grow" required min="1" step="0.01">
                                <select name="unit" class="ml-2 p-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-green">
                                    <option value="kgs">kgs</option>
                                    <option value="liters">liters</option>
                                    <option value="pieces">pieces</option>
                                    <option value="servings">servings</option>
                                    <option value="boxes">boxes</option>
                                    <option value="items">items</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="category_id" class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <select id="category_id" name="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach ($donation_categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['id']); ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="expiry-time" class="block text-sm font-medium text-gray-700 mb-1">Expiry Time / Best By Date</label>
                        <input type="datetime-local" id="expiry-time" name="expiry_time" required>
                    </div>
                    <div>
                        <label for="location" class="block text-sm font-medium text-gray-700 mb-1">Pickup Location</label>
                        <input type="text" id="location" name="location" placeholder="Full address for pickup" required>
                        <p class="text-xs text-gray-500 mt-1">This will be shared with approved recipients.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Optional Photo Upload</label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                            <div class="space-y-1 text-center">
                                <i class="fas fa-cloud-upload-alt text-4xl text-gray-400"></i>
                                <div class="flex text-sm text-gray-600">
                                    <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium text-primary-green hover:text-primary-green-dark focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-primary-green">
                                        <span>Upload a file</span>
                                        <input id="file-upload" name="photo" type="file" class="sr-only" accept="image/*">
                                    </label>
                                    <p class="pl-1">or drag and drop</p>
                                </div>
                                <p class="text-xs text-gray-500">PNG, JPG, GIF up to 10MB</p>
                            </div>
                        </div>
                        <div id="image-preview" class="mt-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                            <!-- Image previews will be dynamically added here -->
                        </div>
                    </div>
                    <div class="flex gap-4 pt-4">
                        <button type="submit" name="add_donation" class="btn-primary">Submit Donation</button>
                        <a href="donor-dashboard.php" class="btn-secondary !bg-gray-500 hover:!bg-gray-600 block text-center">Cancel</a>
                    </div>
                </form>
            </div>
        </section>
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

        // Photo Upload Preview Logic for Add Donation Form
        const fileUpload = document.getElementById('file-upload');
        const imagePreview = document.getElementById('image-preview');

        fileUpload.addEventListener('change', (event) => {
            imagePreview.innerHTML = ''; // Clear previous previews
            const files = event.target.files;
            if (files) {
                Array.from(files).forEach(file => {
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            const imgContainer = document.createElement('div');
                            imgContainer.classList.add('relative', 'group');
                            imgContainer.innerHTML = `
                                <img src="${e.target.result}" alt="${file.name}" class="w-full h-24 object-cover rounded-md shadow-sm">
                                <button type="button" class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 text-xs opacity-0 group-hover:opacity-100 transition-opacity remove-image-btn" data-file-name="${file.name}">
                                    <i class="fas fa-times"></i>
                                </button>
                            `;
                            imagePreview.appendChild(imgContainer);

                            // Add event listener to remove button
                            imgContainer.querySelector('.remove-image-btn').addEventListener('click', (btnEvent) => {
                                imgContainer.remove();
                                // In a real app, you'd also remove the file from a list to be uploaded
                                // This simple example doesn't handle actual file input clearing.
                                // For true removal, you'd need to manage a FileList or similar.
                            });
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
        });
    </script>
</body>
</html>



