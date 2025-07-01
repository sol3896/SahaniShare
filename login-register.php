<?php
// login-register.php
session_start();

include_once dirname(__FILE__) . '/db_connection.php'; // Include the database connection file

$message = ''; // To display messages to the user (e.g., success, error, pending approval)

// Check for any session messages (e.g., from successful registration, or admin rejection)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying it
}

// Special message for pending/rejected users trying to log in
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

// --- Registration Logic ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $organization_name = trim($_POST['organization_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role']; // 'donor' or 'recipient'

    // Document upload handling
    $document_path = null;
    $upload_success = false;

    // Define upload directory relative to this script
    $upload_dir = 'uploads/documents/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true); // Create directory if it doesn't exist
    }

    // IMPORTANT: Using 'organization_document' as per your original form's name attribute
    if (isset($_FILES['organization_document']) && $_FILES['organization_document']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_name = $_FILES['organization_document']['tmp_name'];
        $file_name = $_FILES['organization_document']['name'];
        $file_size = $_FILES['organization_document']['size'];
        $file_type = $_FILES['organization_document']['type'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Basic validation
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']; // Added doc/docx for consistency
        $max_file_size = 5 * 1024 * 1024; // 5MB

        if (!in_array($file_ext, $allowed_extensions)) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid file type. Only PDF, JPG, JPEG, PNG, DOC, DOCX are allowed for documentation.</div>';
        } elseif ($file_size > $max_file_size) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">File size exceeds 5MB limit.</div>';
        } else {
            // Generate a unique file name
            $unique_file_name = uniqid('doc_', true) . '.' . $file_ext;
            $destination_path = $upload_dir . $unique_file_name;

            if (move_uploaded_file($file_tmp_name, $destination_path)) {
                $document_path = $destination_path;
                $upload_success = true;
            } else {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error uploading document. Please try again.</div>';
            }
        }
    } else {
        // If no file was uploaded, or there was an error
        if ($_FILES['organization_document']['error'] !== UPLOAD_ERR_NO_FILE) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">File upload error: ' . $_FILES['organization_document']['error'] . '</div>';
        }
        // If it's a recipient and no file was uploaded, it's an error
        if ($role === 'recipient' && $_FILES['organization_document']['error'] === UPLOAD_ERR_NO_FILE) {
             $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Recipient registration requires document upload.</div>';
        }
    }


    // Proceed with registration only if there were no file upload errors (or if no file was submitted and it's a donor)
    if (empty($message)) { // Only proceed if no upload errors
        if (empty($organization_name) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">All fields are required.</div>';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid email format.</div>';
        } elseif (strlen($password) < 6) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Password must be at least 6 characters long.</div>';
        } elseif ($password !== $confirm_password) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Passwords do not match.</div>';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $conn = get_db_connection();

            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Email already registered.</div>';
            } else {
                // Insert new user into the database
                // Status is 'pending' for recipients, 'active' for donors. document_verified is FALSE by default.
                $status_for_db = ($role === 'recipient') ? 'pending' : 'active';
                $document_verified_for_db = FALSE; // Always false on initial registration

                $stmt_insert = $conn->prepare("INSERT INTO users (organization_name, email, password_hash, role, status, document_path, document_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                if (!$stmt_insert) {
                    $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Database prepare error: ' . htmlspecialchars($conn->error) . '</div>';
                } else {
                    $stmt_insert->bind_param("sssssis", $organization_name, $email, $hashed_password, $role, $status_for_db, $document_path, $document_verified_for_db);

                    if ($stmt_insert->execute()) {
                        $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Registration successful! You can now log in.</div>';
                        if ($role === 'recipient') {
                            $_SESSION['message'] .= '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Your account is pending admin approval. You will be notified once approved.</div>';
                        }
                        // Redirect to login page after successful registration
                        header('Location: login-register.php');
                        exit();
                    } else {
                        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error registering user: ' . htmlspecialchars($stmt_insert->error) . '</div>';
                    }
                    $stmt_insert->close();
                }
            }
            $stmt->close();
            $conn->close();
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
        $stmt = $conn->prepare("SELECT id, organization_name, email, password_hash, role, status, rejection_reason FROM users WHERE email = ?"); // Ensure email is selected
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($user_id_db, $organization_name_db, $email_db, $password_hash_db, $role_db, $status_db, $rejection_reason_db);
        $stmt->fetch();

        if ($stmt->num_rows > 0 && password_verify($password, $password_hash_db)) {
            $_SESSION['user_id'] = $user_id_db;
            $_SESSION['organization_name'] = $organization_name_db;
            $_SESSION['user_role'] = $role_db;
            $_SESSION['user_status'] = $status_db; // Store the status in session
            $_SESSION['user_email'] = $email_db; // Store the email in session

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
                // Account not active (pending, inactive, or rejected)
                // Set a message and redirect back to login-register
                if ($status_db === 'pending') {
                    $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Your account is pending approval. Please wait for an administrator to review your registration.</div>';
                } elseif ($status_db === 'inactive') {
                    $_SESSION['message'] = '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-4" role="alert">Your account is currently inactive. Please contact support.</div>';
                } elseif ($status_db === 'rejected') {
                    $rejection_info = !empty($rejection_reason_db) ? ' Reason: ' . htmlspecialchars($rejection_reason_db) : '';
                    $_SESSION['message'] = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Your account has been rejected.' . $rejection_info . ' Please contact support for more information.</div>';
                }
                header('Location: login-register.php'); // Redirect to self to show message
                exit();
            }
        } else {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid email or password.</div>';
        }
        $stmt->close();
        $conn->close();
    }
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
                        <!-- Document Upload Field - Name changed to 'organization_document' as per your original code -->
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
        console.log("login-register.php: JavaScript loaded."); // Debugging line

        const loginTab = document.getElementById('login-tab');
        const registerTab = document.getElementById('register-tab');
        const loginForm = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const alertMessage = document.getElementById('alert-message');

        // Function to show/hide document upload based on role
        const roleSelect = document.getElementById('register-role');
        const documentUploadSection = document.getElementById('document-upload-section');
        // IMPORTANT: Use 'organization-document' as the ID for the file input
        const organizationDocumentInput = document.getElementById('organization-document');
        const fileNameDisplay = document.getElementById('file-name-display');

        function toggleDocumentUpload() {
            console.log("toggleDocumentUpload called. Role selected:", roleSelect.value); // Debugging line
            if (roleSelect.value === 'recipient') {
                documentUploadSection.classList.remove('hidden');
                organizationDocumentInput.setAttribute('required', 'required'); // Set required attribute
                console.log("Document upload shown and required."); // Debugging line
            } else {
                documentUploadSection.classList.add('hidden');
                organizationDocumentInput.removeAttribute('required'); // Remove required attribute
                console.log("Document upload hidden and not required."); // Debugging line
            }
        }

        // Initial check on page load
        toggleDocumentUpload();

        // Listen for changes
        if (roleSelect) {
            roleSelect.addEventListener('change', toggleDocumentUpload);
            console.log("Event listener attached to roleSelect."); // Debugging line
        }

        // Display selected file name for 'organization-document'
        if (organizationDocumentInput) {
            organizationDocumentInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    fileNameDisplay.textContent = `Selected: ${this.files[0].name}`;
                    console.log("File selected:", this.files[0].name); // Debugging line
                } else {
                    fileNameDisplay.textContent = '';
                    console.log("No file selected."); // Debugging line
                }
            });
        }

        // Tab switching logic (your original logic)
        function showTab(tabName) {
            console.log("showTab called. Tab name:", tabName); // Debugging line
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
            }
        }

        // Event listeners for tab clicks (your original logic)
        if (loginTab) {
            loginTab.addEventListener('click', () => {
                console.log("Login tab button clicked."); // Debugging line
                showTab('login');
            });
        }
        if (registerTab) {
            registerTab.addEventListener('click', () => {
                console.log("Register tab button clicked."); // Debugging line
                showTab('register');
            });
        }

        // Check for session message and display appropriate tab (your original logic, slightly refined)
        // This will ensure the correct tab is active if redirected after a form submission
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
                messageContent.includes("Recipient registration requires document upload")
            ) {
                showTab('register');
            } else {
                showTab('login');
            }
        } else {
             // Default to login tab if no message
            showTab('login');
        }


        // Remove the alert message after a few seconds if it's not a persistent one (like pending approval)
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
