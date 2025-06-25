<?php
// login-register.php
session_start(); // Start the session for user authentication

// Include the database connection file
include_once 'db_connection.php';

$message = ''; // To store success or error messages

// Check for messages from previous redirects
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying it
}

// --- Handle User Registration ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $organization_name = trim($_POST['organization_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role']; // donor, recipient

    // Basic validation
    if (empty($organization_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Please fill in all fields.</div>';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid email format.</div>';
    } elseif ($password !== $confirm_password) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Passwords do not match.</div>';
    } else {
        // Hash the password before storing (IMPORTANT!)
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $conn = get_db_connection(); // Get a new database connection

        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Email already registered. Please login or use a different email.</div>';
        } else {
            // Set default status based on role
            $status = 'pending'; // Default for new registrations (recipients)
            if ($role === 'donor' || $role === 'admin') { // Donors and directly registered admins are active immediately
                $status = 'active';
            }
            // For now, only donor/recipient can register here. Admin registration is manual or through specific flow.

            // Insert new user into the database
            $stmt = $conn->prepare("INSERT INTO users (organization_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $organization_name, $email, $password_hash, $role, $status);

            if ($stmt->execute()) {
                $_SESSION['message'] = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">Registration successful! Please login.</div>';
                
                // Auto-login for 'donor' accounts directly (since they are active immediately)
                if ($role === 'donor') {
                     $_SESSION['user_id'] = $conn->insert_id;
                     $_SESSION['user_email'] = $email;
                     $_SESSION['user_role'] = $role;
                     $_SESSION['organization_name'] = $organization_name;
                     $_SESSION['user_status'] = $status;
                     header('Location: donor-dashboard.php');
                     exit();
                }
                
                // For recipients (pending status), just redirect back to login page
                header('Location: login-register.php?registered=true');
                exit();
            } else {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Error during registration: ' . htmlspecialchars($stmt->error) . '</div>';
            }
        }
        $stmt->close();
        $conn->close();
    }
}

// --- Handle User Login ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Please enter both email and password.</div>';
    } else {
        $conn = get_db_connection();

        // Retrieve user from database
        $stmt = $conn->prepare("SELECT id, password_hash, organization_name, role, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows == 1) {
            $stmt->bind_result($id, $password_hash, $organization_name, $role, $status);
            $stmt->fetch();

            if (password_verify($password, $password_hash)) {
                // Password is correct
                // Check user status before setting full session and redirecting
                if ($role === 'recipient' && $status !== 'active') {
                    // Recipient account is not active, display message on login page
                    $message_type = 'yellow';
                    $alert_message = '';
                    switch ($status) {
                        case 'pending':
                            $alert_message = 'Your recipient account is currently <strong>pending approval</strong> by an administrator. Please wait for an update.';
                            break;
                        case 'rejected':
                            $alert_message = 'Your recipient account has been <strong>rejected</strong>. Please contact support for more information.';
                            $message_type = 'red'; // Rejected should be a red alert
                            break;
                        case 'inactive': // Generic inactive status
                             $alert_message = 'Your recipient account is currently <strong>inactive</strong>. Please contact support.';
                             $message_type = 'red';
                             break;
                        default:
                            $alert_message = 'Your recipient account is currently ' . htmlspecialchars($status) . '. Please wait for admin approval or contact support.';
                            break;
                    }
                    $message = '<div class="bg-' . $message_type . '-100 border border-' . $message_type . '-400 text-' . $message_type . '-700 px-4 py-3 rounded-md mb-4" role="alert">' . $alert_message . '</div>';
                    // DO NOT set full session variables here, keep them on the login page
                } else {
                    // Account is active or not a recipient, proceed with login
                    $_SESSION['user_id'] = $id;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_role'] = $role;
                    $_SESSION['organization_name'] = $organization_name;
                    $_SESSION['user_status'] = $status; // Store the user's current status from DB

                    // Check for and display specific user approval message only on first successful active login
                    $user_approval_message_key = 'user_status_message_' . $id;
                    if (isset($_SESSION[$user_approval_message_key])) {
                        $_SESSION['message'] = $_SESSION[$user_approval_message_key]; // Set message for next page
                        unset($_SESSION[$user_approval_message_key]); // Clear it
                    }

                    // Redirect based on user role (expand this logic as needed)
                    switch ($role) {
                        case 'donor':
                            header('Location: donor-dashboard.php');
                            break;
                        case 'recipient':
                            header('Location: recipient-dashboard.php');
                            break;
                        case 'admin':
                            header('Location: admin-panel.php');
                            break;
                        default:
                            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Unknown user role. Please contact support.</div>';
                            // Clear session for unknown roles
                            session_unset();
                            session_destroy();
                            session_start();
                            break;
                    }
                    exit(); // Always exit after a header redirect
                }
            } else {
                $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">Invalid password for this email.</div>';
            }
        } else {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">No account found with that email.</div>';
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
    <!-- Link to external style.css -->
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            @apply bg-gray-100 text-gray-800;
        }
    </style>
</head>
<body class="login-page min-h-screen flex flex-col items-center justify-center p-4 md:p-8">

    <!-- Login/Register Page Content -->
    <section class="w-full max-w-md">
        <div class="card text-center">
            <div class="text-primary-green text-5xl font-extrabold mb-4 flex items-center justify-center">
                <i class="fas fa-hand-holding-heart mr-3"></i> SahaniShare
            </div>
            <h2 class="text-3xl font-bold text-neutral-dark mb-6" id="auth-title">Welcome Back!</h2>

            <?php echo $message; // Display messages ?>

            <!-- Login Form -->
            <form id="login-form" class="space-y-4" method="POST" action="login-register.php">
                <input type="email" name="email" placeholder="Email Address" required autocomplete="username">
                <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                <button type="submit" name="login" class="btn-primary">Login</button>
                <a href="#" class="text-sm text-primary-green hover:underline block pt-2">Forgot Password?</a>
                <p class="text-sm text-gray-600 mt-4">Don't have an account? <a href="#" id="show-register" class="text-primary-green hover:underline font-medium">Register here</a></p>
            </form>

            <!-- Register Form (Hidden by default) -->
            <form id="register-form" class="space-y-4 hidden" method="POST" action="login-register.php">
                <div class="text-left mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">I am a:</label>
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                        <label class="inline-flex items-center">
                            <input type="radio" name="role" value="donor" class="form-radio text-primary-green focus:ring-primary-green" checked>
                            <span class="ml-2 text-gray-700">Donor</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="role" value="recipient" class="form-radio text-primary-green focus:ring-primary-green">
                            <span class="ml-2 text-gray-700">Recipient</span>
                        </label>
                    </div>
                </div>
                <input type="text" name="organization_name" placeholder="Organization Name (e.g., ABC Hotel)" required>
                <input type="email" name="email" placeholder="Email Address" required autocomplete="username">
                <input type="password" name="password" placeholder="Password" required autocomplete="new-password">
                <input type="password" name="confirm_password" placeholder="Confirm Password" required autocomplete="new-password">
                <button type="submit" name="register" class="btn-primary">Register</button>
                <p class="text-sm text-gray-600 mt-4">Already have an account? <a href="#" id="show-login" class="text-primary-green hover:underline font-medium">Login here</a></p>
            </form>
        </div>
    </section>

    <script>
        const loginForm = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const authTitle = document.getElementById('auth-title');
        const showRegisterLink = document.getElementById('show-register');
        const showLoginLink = document.getElementById('show-login');

        // Check if there's a message indicating a registration attempt
        const urlParams = new URLSearchParams(window.location.search);
        const registrationAttempted = urlParams.get('registered');
        if (registrationAttempted === 'true') {
            loginForm.classList.remove('hidden'); // Ensure login form is visible
            registerForm.classList.add('hidden');
            authTitle.textContent = 'Welcome Back!';
        }


        // Event listeners for showing register/login forms
        showRegisterLink.addEventListener('click', (e) => {
            e.preventDefault();
            loginForm.classList.add('hidden');
            registerForm.classList.remove('hidden');
            authTitle.textContent = 'Join SahaniShare!';
            // Clear URL parameter if navigating
            window.history.pushState({}, document.title, window.location.pathname);
        });

        showLoginLink.addEventListener('click', (e) => {
            e.preventDefault();
            registerForm.classList.add('hidden');
            loginForm.classList.remove('hidden');
            authTitle.textContent = 'Welcome Back!';
            // Clear URL parameter if navigating
            window.history.pushState({}, document.title, window.location.pathname);
        });
    </script>
</body>
</html>




