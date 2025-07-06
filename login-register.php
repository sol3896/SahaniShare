<?php
// login-register.php
session_start();

// Include the database connection file
include_once dirname(__FILE__) . '/db_connection.php';

$message = ''; // To display messages to the user (e.g., success, error, pending approval)

// Check for any session messages (e.g., from successful registration, or admin rejection)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying it
}

// Special message for pending/rejected/inactive users trying to log in
// This block is for users who were previously logged in and then their status changed
if (isset($_SESSION['user_status']) && ($_SESSION['user_status'] === 'pending' || $_SESSION['user_status'] === 'rejected' || $_SESSION['user_status'] === 'inactive')) {
    $conn = get_db_connection();
    $user_id_from_session = $_SESSION['user_id'] ?? null; // Use null coalescing for safety

    if ($user_id_from_session) {
        $stmt = $conn->prepare("SELECT status, rejection_reason FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id_from_session);
            $stmt->execute();
            $stmt->bind_result($user_current_status, $user_rejection_reason);
            $stmt->fetch();
            $stmt->close();

            if ($user_current_status === 'pending') {
                $message = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Your account is pending approval. Please wait for an administrator to review your registration.</div>';
            } elseif ($user_current_status === 'rejected') {
                $rejection_info = !empty($user_rejection_reason) ? ' Reason: ' . htmlspecialchars($user_rejection_reason) : '';
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Your account has been rejected.' . $rejection_info . ' Please contact support for more information or re-register if issues can be resolved.</div>';
            } elseif ($user_current_status === 'inactive') {
                $message = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Your account is currently inactive. Please contact support.</div>';
            }
        }
    }
    // Clear the specific session variables that indicate a user is logged in but not active
    unset($_SESSION['user_id']);
    unset($_SESSION['user_role']);
    unset($_SESSION['user_status']);
    unset($_SESSION['organization_name']);
    unset($_SESSION['user_email']); // Ensure email is also cleared
}


// Check if the user is already logged in (and active), then redirect to their respective dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['user_status']) && $_SESSION['user_status'] === 'active') {
    if ($_SESSION['user_role'] === 'donor') {
        header('Location: donor-dashboard.php');
        exit();
    } elseif ($_SESSION['user_role'] === 'recipient') {
        header('Location: recipient-dashboard.php');
        exit();
    } elseif ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'moderator') {
        header('Location: admin-panel.php');
        exit();
    }
}

$conn_fetch_types = get_db_connection(); // Use a separate connection for fetching types
$ngo_types = [];
$stmt_ngo_types = $conn_fetch_types->prepare("SELECT id, name FROM ngo_types ORDER BY name ASC");
if ($stmt_ngo_types && $stmt_ngo_types->execute()) {
    $result_ngo_types = $stmt_ngo_types->get_result();
    while ($row = $result_ngo_types->fetch_assoc()) {
        $ngo_types[] = $row;
    }
    $stmt_ngo_types->close();
} else {
    error_log("SahaniShare Error: Failed to fetch NGO types for login-register.php: " . $conn_fetch_types->error);
    // Optionally add a user-facing message here if this failure is critical
}
$conn_fetch_types->close(); // Close this connection after fetching types


// --- Registration Logic ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $organization_name = trim($_POST['organization_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role']; // 'donor' or 'recipient'
    $ngo_type_id = null; // Initialize to null

    // If role is recipient, get ngo_type_id
    if ($role === 'recipient') {
        $ngo_type_id = filter_var($_POST['ngo_type_id'], FILTER_VALIDATE_INT);
        if ($ngo_type_id === false || $ngo_type_id <= 0) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Please select a valid NGO type for recipient registration.</div>';
        }
    }

    // Document upload handling
    $document_path = null;
    $upload_success = false;

    // Define upload directory relative to this script
    $upload_dir = 'uploads/documents/'; // This is for documents, as per your structure
    if (!is_dir($upload_dir)) {
        // Attempt to create directory if it doesn't exist
        if (!mkdir($upload_dir, 0777, true)) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Server error: Could not create upload directory. Check permissions.</div>';
            error_log("SahaniShare Error: Could not create upload directory: " . $upload_dir);
        } else {
            error_log("SahaniShare Info: Upload directory created: " . $upload_dir);
        }
    }

    // Only attempt file upload if no prior error message
    if (empty($message)) {
        error_log("SahaniShare Debug: Starting file upload check for role: " . $role);
        if (isset($_FILES['organization_document']) && $_FILES['organization_document']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_name = $_FILES['organization_document']['tmp_name'];
            $file_name = $_FILES['organization_document']['name'];
            $file_size = $_FILES['organization_document']['size'];
            $file_type = $_FILES['organization_document']['type'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            error_log("SahaniShare Debug: File details - Name: " . $file_name . ", Size: " . $file_size . ", Ext: " . $file_ext);

            // Basic validation
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $max_file_size = 5 * 1024 * 1024; // 5MB

            if (!in_array($file_ext, $allowed_extensions)) {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid file type. Only PDF, JPG, JPEG, PNG, DOC, DOCX are allowed for documentation.</div>';
                error_log("SahaniShare Upload Error: Invalid file type for " . $file_name);
            } elseif ($file_size > $max_file_size) {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">File size exceeds 5MB limit.</div>';
                error_log("SahaniShare Upload Error: File size too large for " . $file_name);
            } else {
                // Generate a unique file name
                $unique_file_name = uniqid('doc_', true) . '.' . $file_ext;
                $destination_path = $upload_dir . $unique_file_name;

                error_log("SahaniShare Debug: Attempting to move file to: " . $destination_path);
                if (move_uploaded_file($file_tmp_name, $destination_path)) {
                    $document_path = $destination_path;
                    $upload_success = true;
                    error_log("SahaniShare Upload Success: File " . $unique_file_name . " uploaded to " . $destination_path);
                } else {
                    $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error uploading document. Please try again. (Move failed)</div>';
                    error_log("SahaniShare Upload Error: Failed to move uploaded file from " . $file_tmp_name . " to " . $destination_path . ". PHP error: " . error_get_last()['message']);
                }
            }
        } elseif (isset($_FILES['organization_document']) && $_FILES['organization_document']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Specific file upload errors
            $php_upload_errors = array(
                UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            );
            $error_code = $_FILES['organization_document']['error'];
            $error_desc = isset($php_upload_errors[$error_code]) ? $php_upload_errors[$error_code] : 'Unknown upload error.';
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">File upload error: ' . htmlspecialchars($error_desc) . '</div>';
            error_log("SahaniShare Upload Error: PHP upload error code " . $error_code . " - " . $error_desc);
        } else {
            // If no file was uploaded (UPLOAD_ERR_NO_FILE)
            // For recipients, document is required. For donors, it's optional at registration.
            if ($role === 'recipient') {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Recipient registration requires document upload.</div>';
                error_log("SahaniShare Registration Error: Recipient attempted registration without document.");
            } else {
                error_log("SahaniShare Debug: Donor registration, no document uploaded (optional).");
            }
        }
    }


    // Proceed with registration only if there were no file upload errors and other validations pass
    if (empty($message)) { // Only proceed if no prior error message
        if (empty($organization_name) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">All fields are required.</div>';
            error_log("SahaniShare Validation Error: Missing required fields.");
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid email format.</div>';
            error_log("SahaniShare Validation Error: Invalid email format for " . $email);
        } elseif (strlen($password) < 6) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Password must be at least 6 characters long.</div>';
            error_log("SahaniShare Validation Error: Password too short.");
        } elseif ($password !== $confirm_password) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Passwords do not match.</div>';
            error_log("SahaniShare Validation Error: Passwords do not match.");
        } else {
            $conn = get_db_connection();

            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            if (!$stmt) {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database prepare error (email check): ' . htmlspecialchars($conn->error) . '</div>';
                error_log("SahaniShare DB Error: Prepare failed for email check: " . $conn->error);
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Email already registered.</div>';
                    error_log("SahaniShare Registration Error: Email already registered: " . $email);
                } else {
                    // Determine status and document_verified based on role
                    $status_for_db = ($role === 'recipient') ? 'pending' : 'active';
                    $document_verified_for_db = 0; // Always FALSE (0) on initial registration, requires admin verification

                    // Insert new user into the database, now including ngo_type_id
                    // The 'i' for ngo_type_id means it's an integer. If it's null, it will be handled correctly by MySQL.
                    $stmt_insert = $conn->prepare("INSERT INTO users (organization_name, email, password_hash, role, status, document_path, document_verified, ngo_type_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                    if (!$stmt_insert) {
                        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database prepare error (user insert): ' . htmlspecialchars($conn->error) . '</div>';
                        error_log("SahaniShare DB Error: Prepare failed for user insert: " . $conn->error);
                    } else {
                        // Bind parameters, including document_path, document_verified, and ngo_type_id
                        // Use 's' for document_path (string) and 'i' for document_verified (int) and ngo_type_id (int)
                        // For ngo_type_id, if it's null, bind_param will treat it as such if the column is nullable.
                        $stmt_insert->bind_param("ssssssii", $organization_name, $email, $hashed_password, $role, $status_for_db, $document_path, $document_verified_for_db, $ngo_type_id);

                        if ($stmt_insert->execute()) {
                            $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Registration successful! You can now log in.</div>';
                            if ($role === 'recipient') {
                                $_SESSION['message'] .= '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Your account is pending admin approval. You will be notified once approved.</div>';
                            }
                            error_log("SahaniShare Registration Success: User " . $email . " registered as " . $role . " with status " . $status_for_db . ". Document path: " . ($document_path ?? 'N/A') . ", Verified: " . $document_verified_for_db . ", NGO Type ID: " . ($ngo_type_id ?? 'N/A'));
                            header('Location: login-register.php'); // Redirect to self to show message and clear form data
                            exit();
                        } else {
                            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error registering user: ' . htmlspecialchars($stmt_insert->error) . '</div>';
                            error_log("SahaniShare DB Error: Execute failed for user insert: " . $stmt_insert->error);
                        }
                        $stmt_insert->close();
                    }
                }
                $stmt->close();
                // The main connection will be closed at the end of the script.
            }
        }
    }
}

// --- Login Logic ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Email and password are required.</div>';
    } else {
        $conn = get_db_connection();
        $stmt = $conn->prepare("SELECT id, organization_name, email, password_hash, role, status, rejection_reason FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($user_id_db, $organization_name_db, $email_db, $password_hash_db, $role_db, $status_db, $rejection_reason_db);
        $stmt->fetch();

        if ($stmt->num_rows > 0 && password_verify($password, $password_hash_db)) {
            $_SESSION['user_id'] = $user_id_db;
            $_SESSION['organization_name'] = $organization_name_db;
            $_SESSION['user_role'] = $role_db;
            $_SESSION['user_status'] = $status_db;
            $_SESSION['user_email'] = $email_db;

            if ($status_db === 'active') {
                if ($role_db === 'donor') {
                    header('Location: donor-dashboard.php');
                    exit();
                } elseif ($role_db === 'recipient') {
                    header('Location: recipient-dashboard.php');
                    exit();
                } elseif ($role_db === 'admin' || $role_db === 'moderator') {
                    header('Location: admin-panel.php');
                    exit();
                }
            } else {
                if ($status_db === 'pending') {
                    $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Your account is pending approval. Please wait for an administrator to review your registration.</div>';
                } elseif ($status_db === 'inactive') {
                    $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Your account is currently inactive. Please contact support.</div>';
                } elseif ($status_db === 'rejected') {
                    $rejection_info = !empty($rejection_reason_db) ? ' Reason: ' . htmlspecialchars($rejection_reason_db) : '';
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Your account has been rejected.' . $rejection_info . ' Please contact support for more information.</div>';
                }
                header('Location: login-register.php');
                exit();
            }
        } else {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid email or password.</div>';
        }
        $stmt->close();
        // The main connection will be closed at the end of the script.
    }
}

// Close the main connection if it's still open after all POST processing
if (isset($conn) && $conn->ping()) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Login / Register</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts: Inter for body, Montserrat for headings -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Link to external style.css (ensure this file exists and is correctly linked) -->
    <link rel="stylesheet" href="style.css"> 
    <style>
        /* Define custom colors here to match your preferred aesthetic */
        :root {
            --primary-green: #A7D397; /* Your preferred lighter green */
            --primary-green-dark: #8bbd78; /* A darker shade for hover states */
            --neutral-dark: #333; /* Assuming a dark text color from your style.css */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6; /* Light gray background */
            color: #333;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Montserrat', sans-serif;
        }
        .container {
            display: flex;
            min-height: 100vh;
        }
        .left-panel {
            background-color: var(--primary-green); /* Primary Green */
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            text-align: center;
            flex: 1; /* Take up equal space */
        }
        .right-panel {
            background-color: #ffffff; /* White */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            flex: 1; /* Take up equal space */
        }
        .form-card {
            background-color: #fff;
            padding: 2.5rem;
            border-radius: 0.75rem; /* 12px */
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            width: 100%;
            max-width: 450px;
            text-align: left;
        }
        .tab-button {
            @apply py-3 px-6 text-lg font-semibold rounded-t-lg transition-colors duration-300;
        }
        .tab-button.active {
            @apply bg-white;
            color: var(--primary-green-dark); /* Use darker shade for active tab text */
        }
        .tab-button.inactive {
            @apply bg-gray-200 text-gray-700 hover:bg-gray-300;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select,
        textarea,
        input[type="file"] { /* Apply styles to file input too */
            @apply w-full p-3 border border-gray-300 rounded-md shadow-sm focus:ring-primary-green focus:border-primary-green;
        }
        /* Specific styling for the file input button part */
        input[type="file"]::-webkit-file-upload-button {
            background-color: #E0F2F7; /* Tailwind blue-50 */
            color: #0891B2; /* Tailwind cyan-700 */
            border: 0;
            padding: 0.5rem 1rem;
            margin-right: 1rem;
            border-radius: 0.375rem; /* rounded-md */
            font-size: 0.875rem; /* text-sm */
            font-weight: 600; /* font-semibold */
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }
        input[type="file"]::-webkit-file-upload-button:hover {
            background-color: #CFECF2; /* Tailwind blue-100 */
        }
        
        .btn-primary {
            @apply w-full py-3 px-4 rounded-md font-semibold transition-colors duration-300;
            background-color: var(--primary-green); /* Primary Green */
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--primary-green-dark); /* Darker Primary Green */
        }
        .logo {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .slogan {
            font-size: 1.5rem;
            line-height: 1.4;
        }
        .mobile-switch {
            display: none; /* Hidden by default on larger screens */
            width: 100%;
            margin-top: 1.5rem;
            text-align: center;
        }
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            .left-panel {
                min-height: 200px; /* Shorter height on mobile */
                padding: 1.5rem;
            }
            .right-panel {
                padding: 1.5rem;
            }
            .mobile-switch {
                display: block; /* Show on mobile */
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container flex flex-col md:flex-row">
        <!-- Left Panel for Branding/Info -->
        <div class="left-panel">
            <div class="logo">
                <i class="fas fa-hand-holding-heart"></i>
            </div>
            <h1 class="text-4xl font-bold mb-4">SahaniShare</h1>
            <p class="slogan font-medium">Connecting surplus food with those in need. Share joy, reduce waste.</p>
        </div>

        <!-- Right Panel for Login/Register Forms -->
        <div class="right-panel">
            <div class="w-full max-w-md">
                <!-- Tab Buttons -->
                <div class="flex mb-6 rounded-t-lg overflow-hidden shadow-md">
                    <button id="login-tab" class="tab-button active flex-1">Login</button>
                    <button id="register-tab" class="tab-button inactive flex-1">Register</button>
                </div>

                <!-- Messages Display Area -->
                <?php if (!empty($message)): ?>
                    <div id="alert-message" class="mb-4">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <div id="login-form" class="form-card">
                    <h2 class="text-2xl font-bold text-center mb-6">Login to Your Account</h2>
                    <form action="login-register.php" method="POST" class="space-y-5">
                        <div>
                            <label for="login-email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                            <input type="email" id="login-email" name="email" required>
                        </div>
                        <div>
                            <label for="login-password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                            <input type="password" id="login-password" name="password" required>
                        </div>
                        <button type="submit" name="login" class="btn-primary">Login</button>
                        <p class="text-center text-sm text-gray-600 mt-4">Forgot your password? <a href="#" class="text-primary-green hover:underline">Reset here</a></p>
                    </form>
                </div>

                <!-- Register Form -->
                <div id="register-form" class="form-card hidden">
                    <h2 class="text-2xl font-bold text-center mb-6">Create a New Account</h2>
                    <form action="login-register.php" method="POST" class="space-y-5" enctype="multipart/form-data">
                        <div>
                            <label for="register-org-name" class="block text-sm font-medium text-gray-700 mb-1">Organization Name</label>
                            <input type="text" id="register-org-name" name="organization_name" required>
                        </div>
                        <div>
                            <label for="register-email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                            <input type="email" id="register-email" name="email" required>
                        </div>
                        <div>
                            <label for="register-password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                            <input type="password" id="register-password" name="password" required>
                        </div>
                        <div>
                            <label for="register-confirm-password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                            <input type="password" id="register-confirm-password" name="confirm_password" required>
                        </div>
                        <div>
                            <label for="register-role" class="block text-sm font-medium text-gray-700 mb-1">Register As</label>
                            <select id="register-role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="donor">Food Donor</option>
                                <option value="recipient">Food Recipient</option>
                            </select>
                        </div>
                        <!-- NGO Type Selection (Conditional - for Recipients only) -->
                        <div id="ngo-type-section" class="hidden">
                            <label for="ngo_type_id" class="block text-sm font-medium text-gray-700 mb-1">Type of Organization</label>
                            <select id="ngo_type_id" name="ngo_type_id">
                                <option value="">Select NGO Type</option>
                                <?php foreach ($ngo_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type['id']); ?>">
                                        <?php echo htmlspecialchars($type['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">This helps us match you with relevant donations.</p>
                        </div>
                        <!-- Document Upload Field -->
                        <div id="document-upload-section">
                            <label for="organization-document" class="block text-sm font-medium text-gray-700 mb-1">Organization Documentation (e.g., Business Permit, NGO Certificate)</label>
                            <input type="file" id="organization-document" name="organization_document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <p class="text-xs text-gray-500 mt-1">Accepted formats: PDF, DOC, DOCX, JPG, JPEG, PNG. Max size: 5MB.<br>
                            <span class="font-semibold text-red-600">Required for Recipient accounts. Optional for Donor accounts.</span></p>
                            <p id="file-name-display" class="text-sm text-gray-600 mt-1 italic"></p>
                        </div>
                        <button type="submit" name="register" class="btn-primary">Register</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        console.log("login-register.php: JavaScript loaded.");

        const loginTab = document.getElementById('login-tab');
        const registerTab = document.getElementById('register-tab');
        const loginForm = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const alertMessage = document.getElementById('alert-message');

        const roleSelect = document.getElementById('register-role');
        const documentUploadSection = document.getElementById('document-upload-section');
        const organizationDocumentInput = document.getElementById('organization-document');
        const fileNameDisplay = document.getElementById('file-name-display');
        const ngoTypeSection = document.getElementById('ngo-type-section'); // New element
        const ngoTypeSelect = document.getElementById('ngo_type_id'); // New element

        function toggleRecipientFields() {
            console.log("toggleRecipientFields called. Role selected:", roleSelect.value);
            if (roleSelect.value === 'recipient') {
                documentUploadSection.classList.remove('hidden');
                organizationDocumentInput.setAttribute('required', 'required');
                ngoTypeSection.classList.remove('hidden'); // Show NGO type
                ngoTypeSelect.setAttribute('required', 'required'); // Make NGO type required
                console.log("Document upload and NGO type shown and required for recipient.");
            } else {
                documentUploadSection.classList.add('hidden');
                organizationDocumentInput.removeAttribute('required');
                ngoTypeSection.classList.add('hidden'); // Hide NGO type
                ngoTypeSelect.removeAttribute('required'); // Make NGO type not required
                // Reset NGO type selection if hidden
                ngoTypeSelect.value = ''; 
                console.log("Document upload and NGO type hidden and not required.");
            }
        }

        toggleRecipientFields(); // Initial call on page load

        if (roleSelect) {
            roleSelect.addEventListener('change', toggleRecipientFields);
            console.log("Event listener attached to roleSelect.");
        }

        if (organizationDocumentInput) {
            organizationDocumentInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    fileNameDisplay.textContent = `Selected: ${this.files[0].name}`;
                    console.log("File selected:", this.files[0].name);
                } else {
                    fileNameDisplay.textContent = '';
                    console.log("No file selected.");
                }
            });
        }

        function showTab(tabName) {
            console.log("showTab called. Tab name:", tabName);
            if (tabName === 'login') {
                loginTab.classList.add('active');
                loginTab.classList.remove('inactive');
                registerTab.classList.remove('active');
                registerTab.classList.add('inactive');
                loginForm.classList.remove('hidden');
                registerForm.classList.add('hidden');
            } else { // tabName === 'register'
                registerTab.classList.add('active');
                registerTab.classList.remove('inactive');
                loginTab.classList.remove('active');
                loginTab.classList.add('inactive');
                registerForm.classList.remove('hidden');
                loginForm.classList.add('hidden');
                toggleRecipientFields(); // Ensure fields are correctly toggled when switching to register tab
            }
        }

        if (loginTab) {
            loginTab.addEventListener('click', () => {
                console.log("Login tab button clicked.");
                showTab('login');
            });
        }
        if (registerTab) {
            registerTab.addEventListener('click', () => {
                console.log("Register tab button clicked.");
                showTab('register');
            });
        }

        if (alertMessage) {
            const messageContent = alertMessage.innerHTML;
            if (messageContent.includes("Registration successful") ||
                messageContent.includes("Error registering user") ||
                messageContent.includes("File upload error") ||
                messageContent.includes("Invalid file type") ||
                messageContent.includes("File size exceeds") ||
                (messageContent.includes("All fields are required") && !messageContent.includes("Email and password are required")) ||
                messageContent.includes("Email already registered") ||
                messageContent.includes("Passwords do not match") ||
                messageContent.includes("Password must be at least 6 characters long") ||
                messageContent.includes("Recipient registration requires document upload") ||
                messageContent.includes("Please select a valid NGO type") // New check for NGO type validation
            ) {
                showTab('register');
            } else {
                showTab('login'); // Default to login tab if no message
            }
        } else {
            showTab('login'); // Default to login tab if no message
        }

        if (alertMessage && !alertMessage.innerHTML.includes("pending approval") && !alertMessage.innerHTML.includes("rejected") && !alertMessage.innerHTML.includes("inactive")) {
            setTimeout(() => {
                alertMessage.style.transition = 'opacity 1s ease-out';
                alertMessage.style.opacity = '0';
                setTimeout(() => alertMessage.remove(), 1000); // Remove after transition
            }, 7000); // Hide after 7 seconds
        }
    </script>
</body>
</html>



