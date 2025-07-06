<?php
// settings.php
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

// This variable is used for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);

$conn = get_db_connection();
$message = ''; // To display messages (e.g., settings saved successfully)

// --- Helper Functions for Settings Table ---
function getSetting($conn, $key, $default = null) {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    if (!$stmt) {
        error_log("Failed to prepare getSetting statement: " . $conn->error);
        return $default;
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row['setting_value'];
    }
    $stmt->close();
    return $default;
}

function setSetting($conn, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) {
        error_log("Failed to prepare setSetting statement: " . $conn->error);
        return false;
    }
    $stmt->bind_param("sss", $key, $value, $value);
    $success = $stmt->execute();
    if (!$success) {
        error_log("Failed to execute setSetting statement for key '$key': " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

// --- Helper Functions for Donation Categories ---
function addDonationCategory($conn, $name, $description) {
    $stmt = $conn->prepare("INSERT INTO donation_categories (name, description) VALUES (?, ?)");
    if (!$stmt) {
        return "Error preparing statement: " . $conn->error;
    }
    $stmt->bind_param("ss", $name, $description);
    if ($stmt->execute()) {
        $stmt->close();
        return true;
    } else {
        $error = $stmt->error;
        $stmt->close();
        if (str_contains($error, 'Duplicate entry')) {
            return "Error: Category name already exists.";
        }
        return "Error adding category: " . $error;
    }
}

function deleteDonationCategory($conn, $id) {
    // First, set any donations linked to this category to NULL
    $stmt_update_donations = $conn->prepare("UPDATE donations SET category_id = NULL WHERE category_id = ?");
    if ($stmt_update_donations) {
        $stmt_update_donations->bind_param("i", $id);
        $stmt_update_donations->execute();
        $stmt_update_donations->close();
    } else {
        error_log("Failed to prepare update donations for category delete: " . $conn->error);
    }

    $stmt = $conn->prepare("DELETE FROM donation_categories WHERE id = ?");
    if (!$stmt) {
        return "Error preparing statement: " . $conn->error;
    }
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $stmt->close();
        return true;
    } else {
        $error = $stmt->error;
        $stmt->close();
        return "Error deleting category: " . $error;
    }
}

// --- Helper Functions for NGO Types ---
function addNgoType($conn, $name, $description) {
    $stmt = $conn->prepare("INSERT INTO ngo_types (name, description) VALUES (?, ?)");
    if (!$stmt) {
        return "Error preparing statement: " . $conn->error;
    }
    $stmt->bind_param("ss", $name, $description);
    if ($stmt->execute()) {
        $stmt->close();
        return true;
    } else {
        $error = $stmt->error;
        $stmt->close();
        if (str_contains($error, 'Duplicate entry')) {
            return "Error: NGO Type name already exists.";
        }
        return "Error adding NGO type: " . $error;
    }
}

function deleteNgoType($conn, $id) {
    // First, set any users linked to this NGO type to NULL
    $stmt_update_users = $conn->prepare("UPDATE users SET ngo_type_id = NULL WHERE ngo_type_id = ?");
    if ($stmt_update_users) {
        $stmt_update_users->bind_param("i", $id);
        $stmt_update_users->execute();
        $stmt_update_users->close();
    } else {
        error_log("Failed to prepare update users for NGO type delete: " . $conn->error);
    }

    $stmt = $conn->prepare("DELETE FROM ngo_types WHERE id = ?");
    if (!$stmt) {
        return "Error preparing statement: " . $conn->error;
    }
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $stmt->close();
        return true;
    } else {
        $error = $stmt->error;
        $stmt->close();
        return "Error deleting NGO type: " . $error;
    }
}


// --- Handle POST request to save settings ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // General Settings
    if (isset($_POST['save_general_settings'])) {
        $success_count = 0;
        $error_count = 0;

        $allow_new_registrations = isset($_POST['allow_new_registrations']) ? 'true' : 'false';
        if (setSetting($conn, 'allow_new_registrations', $allow_new_registrations)) { $success_count++; } else { $error_count++; }

        $default_new_user_role = $_POST['default_new_user_role'] ?? 'pending';
        if (setSetting($conn, 'default_new_user_role', $default_new_user_role)) { $success_count++; } else { $error_count++; }

        $default_donation_expiry_days = filter_var($_POST['default_donation_expiry_days'] ?? '', FILTER_VALIDATE_INT);
        if ($default_donation_expiry_days !== false && $default_donation_expiry_days >= 0) {
            if (setSetting($conn, 'default_donation_expiry_days', $default_donation_expiry_days)) { $success_count++; } else { $error_count++; }
        } else {
            $message .= '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid value for Default Donation Expiry Days. Must be a non-negative number.</div>';
            $error_count++;
        }

        $enable_donation_categories = isset($_POST['enable_donation_categories']) ? 'true' : 'false';
        if (setSetting($conn, 'enable_donation_categories', $enable_donation_categories)) { $success_count++; } else { $error_count++; }

        if ($success_count > 0 && $error_count === 0) {
            $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">General settings saved successfully!</div>';
        } elseif ($success_count > 0 && $error_count > 0) {
            $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Some general settings were saved, but ' . $error_count . ' failed. Check logs for details.</div>';
        } elseif ($error_count > 0) {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Failed to save general settings. See errors.</div>';
        }
        header('Location: settings.php');
        exit();
    }

    // Donation Categories
    if (isset($_POST['add_category'])) {
        $name = trim($_POST['category_name']);
        $description = trim($_POST['category_description']);
        if (!empty($name)) {
            $result = addDonationCategory($conn, $name, $description);
            if ($result === true) {
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Donation category added successfully!</div>';
            } else {
                $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">' . htmlspecialchars($result) . '</div>';
            }
        } else {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Category name cannot be empty.</div>';
        }
        header('Location: settings.php');
        exit();
    }

    if (isset($_POST['delete_category'])) {
        $id = filter_var($_POST['category_id'], FILTER_VALIDATE_INT);
        if ($id) {
            $result = deleteDonationCategory($conn, $id);
            if ($result === true) {
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Donation category deleted successfully!</div>';
            } else {
                $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">' . htmlspecialchars($result) . '</div>';
            }
        } else {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid category ID for deletion.</div>';
        }
        header('Location: settings.php');
        exit();
    }

    // NGO Types
    if (isset($_POST['add_ngo_type'])) {
        $name = trim($_POST['ngo_type_name']);
        $description = trim($_POST['ngo_type_description']);
        if (!empty($name)) {
            $result = addNgoType($conn, $name, $description);
            if ($result === true) {
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">NGO Type added successfully!</div>';
            } else {
                $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">' . htmlspecialchars($result) . '</div>';
            }
        } else {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">NGO Type name cannot be empty.</div>';
        }
        header('Location: settings.php');
        exit();
    }

    if (isset($_POST['delete_ngo_type'])) {
        $id = filter_var($_POST['ngo_type_id'], FILTER_VALIDATE_INT);
        if ($id) {
            $result = deleteNgoType($conn, $id);
            if ($result === true) {
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">NGO Type deleted successfully!</div>';
            } else {
                $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">' . htmlspecialchars($result) . '</div>';
            }
        } else {
            $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid NGO Type ID for deletion.</div>';
        }
        header('Location: settings.php');
        exit();
    }
}

// Retrieve messages from session if any
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// --- Fetch current settings for display ---
$current_settings = [
    'allow_new_registrations' => getSetting($conn, 'allow_new_registrations', 'true'),
    'default_new_user_role' => getSetting($conn, 'default_new_user_role', 'pending'),
    'default_donation_expiry_days' => getSetting($conn, 'default_donation_expiry_days', '7'),
    'enable_donation_categories' => getSetting($conn, 'enable_donation_categories', 'false'),
];

// --- Fetch all donation categories for display ---
$donation_categories = [];
$stmt_categories = $conn->prepare("SELECT id, name, description FROM donation_categories ORDER BY name ASC");
if ($stmt_categories && $stmt_categories->execute()) {
    $result_categories = $stmt_categories->get_result();
    while ($row = $result_categories->fetch_assoc()) {
        $donation_categories[] = $row;
    }
    $stmt_categories->close();
} else {
    error_log("SahaniShare Settings Error: Failed to fetch donation categories: " . $conn->error);
}

// --- Fetch all NGO types for display ---
$ngo_types = [];
$stmt_ngo_types = $conn->prepare("SELECT id, name, description FROM ngo_types ORDER BY name ASC");
if ($stmt_ngo_types && $stmt_ngo_types->execute()) {
    $result_ngo_types = $stmt_ngo_types->get_result();
    while ($row = $result_ngo_types->fetch_assoc()) {
        $ngo_types[] = $row;
    }
    $stmt_ngo_types->close();
} else {
    error_log("SahaniShare Settings Error: Failed to fetch NGO types: " . $conn->error);
}


// Close the connection
if (isset($conn) && $conn->ping()) {
    $conn->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Settings</title>
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
                    <a href="reports.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors">
                        <i class="fas fa-chart-bar mr-3"></i> Reports
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="flex items-center p-3 rounded-lg hover:bg-primary-green-dark transition-colors <?php echo ($current_page === 'settings.php' ? 'bg-primary-green-dark' : ''); ?>">
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
        <h1 class="text-4xl font-bold text-neutral-dark mb-8 text-center md:text-left">System Settings</h1>

        <?php echo $message; // Display messages ?>

        <form method="POST" action="settings.php" class="space-y-6">
            <div class="card p-6">
                <h3 class="text-2xl font-semibold text-neutral-dark mb-4">General Settings</h3>
                <div class="space-y-4">
                    <div>
                        <label for="allow_new_registrations" class="flex items-center cursor-pointer">
                            <input type="checkbox" id="allow_new_registrations" name="allow_new_registrations" class="form-checkbox h-5 w-5 text-primary-green rounded"
                                <?php echo ($current_settings['allow_new_registrations'] === 'true' ? 'checked' : ''); ?>>
                            <span class="ml-2 text-gray-700">Allow New User Registrations</span>
                        </label>
                        <p class="text-sm text-gray-500 mt-1">If unchecked, new users will not be able to sign up.</p>
                    </div>

                    <div>
                        <label for="default_new_user_role" class="block text-sm font-medium text-gray-700 mb-1">Default Role for New Registrations</label>
                        <select id="default_new_user_role" name="default_new_user_role"
                                class="w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-primary-green focus:border-primary-green">
                            <option value="pending" <?php echo ($current_settings['default_new_user_role'] === 'pending' ? 'selected' : ''); ?>>Pending (Requires Admin Approval)</option>
                            <option value="donor" <?php echo ($current_settings['default_new_user_role'] === 'donor' ? 'selected' : ''); ?>>Donor</option>
                            <option value="recipient" <?php echo ($current_settings['default_new_user_role'] === 'recipient' ? 'selected' : ''); ?>>Recipient</option>
                        </select>
                        <p class="text-sm text-gray-500 mt-1">This sets the initial role for newly registered users.</p>
                    </div>

                    <div>
                        <label for="default_donation_expiry_days" class="block text-sm font-medium text-gray-700 mb-1">Default Donation Expiry (Days)</label>
                        <input type="number" id="default_donation_expiry_days" name="default_donation_expiry_days" min="0"
                               value="<?php echo htmlspecialchars($current_settings['default_donation_expiry_days']); ?>"
                               class="w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-primary-green focus:border-primary-green">
                        <p class="text-sm text-gray-500 mt-1">The default number of days a new donation is valid before expiring. Set to 0 for no default expiry.</p>
                    </div>

                    <div>
                        <label for="enable_donation_categories" class="flex items-center cursor-pointer">
                            <input type="checkbox" id="enable_donation_categories" name="enable_donation_categories" class="form-checkbox h-5 w-5 text-primary-green rounded"
                                <?php echo ($current_settings['enable_donation_categories'] === 'true' ? 'checked' : ''); ?>>
                            <span class="ml-2 text-gray-700">Enable Donation Categories</span>
                        </label>
                        <p class="text-sm text-gray-500 mt-1">Toggle whether users can categorize donations (requires further development to manage categories).</p>
                    </div>
                </div>
                <div class="text-center mt-6">
                    <button type="submit" name="save_general_settings" class="btn-primary inline-flex items-center px-6 py-3">
                        <i class="fas fa-save mr-2"></i> Save General Settings
                    </button>
                </div>
            </div>
        </form>

        <div class="card p-6 mt-6">
            <h3 class="text-2xl font-semibold text-neutral-dark mb-4">Manage Donation Categories</h3>
            <form method="POST" action="settings.php" class="space-y-4 mb-6 p-4 border border-gray-200 rounded-md bg-gray-50">
                <h4 class="text-lg font-medium text-gray-800">Add New Category</h4>
                <div>
                    <label for="category_name" class="block text-sm font-medium text-gray-700 mb-1">Category Name</label>
                    <input type="text" id="category_name" name="category_name" required
                           class="w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-primary-green focus:border-primary-green" 
                           placeholder="e.g., Fresh Produce">
                </div>
                <div>
                    <label for="category_description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                    <textarea id="category_description" name="category_description" rows="2"
                              class="w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-primary-green focus:border-primary-green" 
                              placeholder="e.g., Fruits, vegetables, and other perishable produce."></textarea>
                </div>
                <button type="submit" name="add_category" class="btn-primary inline-flex items-center">
                    <i class="fas fa-plus-circle mr-2"></i> Add Category
                </button>
            </form>

            <h4 class="text-lg font-medium text-gray-800 mb-3">Existing Categories</h4>
            <?php if (empty($donation_categories)): ?>
                <p class="text-gray-600">No donation categories defined yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                        <thead class="bg-gray-200">
                            <tr>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">ID</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Name</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Description</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($donation_categories as $category): ?>
                                <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                    <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($category['id']); ?></td>
                                    <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($category['name']); ?></td>
                                    <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($category['description']); ?></td>
                                    <td class="py-3 px-4 text-sm">
                                        <form method="POST" action="settings.php" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this category? This will also remove it from any linked donations.');">
                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                            <button type="submit" name="delete_category" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card p-6 mt-6">
            <h3 class="text-2xl font-semibold text-neutral-dark mb-4">Manage NGO Types</h3>
            <form method="POST" action="settings.php" class="space-y-4 mb-6 p-4 border border-gray-200 rounded-md bg-gray-50">
                <h4 class="text-lg font-medium text-gray-800">Add New NGO Type</h4>
                <div>
                    <label for="ngo_type_name" class="block text-sm font-medium text-gray-700 mb-1">NGO Type Name</label>
                    <input type="text" id="ngo_type_name" name="ngo_type_name" required
                           class="w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-primary-green focus:border-primary-green" 
                           placeholder="e.g., Food Bank">
                </div>
                <div>
                    <label for="ngo_type_description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                    <textarea id="ngo_type_description" name="ngo_type_description" rows="2"
                              class="w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-primary-green focus:border-primary-green" 
                              placeholder="e.g., Organizations that collect and distribute food to those in need."></textarea>
                </div>
                <button type="submit" name="add_ngo_type" class="btn-primary inline-flex items-center">
                    <i class="fas fa-plus-circle mr-2"></i> Add NGO Type
                </button>
            </form>

            <h4 class="text-lg font-medium text-gray-800 mb-3">Existing NGO Types</h4>
            <?php if (empty($ngo_types)): ?>
                <p class="text-gray-600">No NGO types defined yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                        <thead class="bg-gray-200">
                            <tr>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">ID</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Name</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Description</th>
                                <th class="py-3 px-4 text-left text-sm font-medium text-gray-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ngo_types as $type): ?>
                                <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                    <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($type['id']); ?></td>
                                    <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($type['name']); ?></td>
                                    <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($type['description']); ?></td>
                                    <td class="py-3 px-4 text-sm">
                                        <form method="POST" action="settings.php" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this NGO Type? This will also remove it from any linked users.');">
                                            <input type="hidden" name="ngo_type_id" value="<?php echo $type['id']; ?>">
                                            <button type="submit" name="delete_ngo_type" class="bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600 transition-colors text-xs">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
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

