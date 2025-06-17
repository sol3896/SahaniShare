<?php
session_start();
include_once 'db_connection.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $organization_name = trim($_POST['organization_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];

    if (empty($organization_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $message = '<div class="alert-error">Please fill in all fields.</div>';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert-error">Invalid email format.</div>';
    } elseif ($password !== $confirm_password) {
        $message = '<div class="alert-error">Passwords do not match.</div>';
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $conn = get_db_connection();

        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $message = '<div class="alert-error">Email already registered. Please login or use a different email.</div>';
        } else {
            $status = ($role === 'donor') ? 'active' : 'pending';
            $stmt = $conn->prepare("INSERT INTO users (organization_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $organization_name, $email, $password_hash, $role, $status);

            if ($stmt->execute()) {
                $_SESSION['user_id'] = $conn->insert_id;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = $role;
                $_SESSION['organization_name'] = $organization_name;
                $_SESSION['user_status'] = $status;
                header('Location: donor-dashboard.php');
                exit();
            } else {
                $message = '<div class="alert-error">Error during registration. Please try again.</div>';
            }
        }
        $stmt->close();
        $conn->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $message = '<div class="alert-error">Please enter both email and password.</div>';
    } else {
        $conn = get_db_connection();
        $stmt = $conn->prepare("SELECT id, password_hash, organization_name, role, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows == 1) {
            $stmt->bind_result($id, $password_hash, $organization_name, $role, $status);
            $stmt->fetch();

            if (password_verify($password, $password_hash)) {
                $_SESSION['user_id'] = $id;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = $role;
                $_SESSION['organization_name'] = $organization_name;
                $_SESSION['user_status'] = $status;

                switch ($role) {
                    case 'donor':
                        header('Location: donor-dashboard.php'); break;
                    case 'recipient':
                        header('Location: recipient-dashboard.php'); break;
                    case 'admin':
                        header('Location: admin-panel.php'); break;
                    case 'moderator':
                        header('Location: admin-panel.php'); break;
                    default:
                        header('Location: login-register.php'); break;
                }
                exit();
            } else {
                $message = '<div class="alert-error">Invalid password.</div>';
            }
        } else {
            $message = '<div class="alert-error">No account found with that email.</div>';
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
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Montserrat:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        h2 { font-family: 'Montserrat', sans-serif; }
        .alert-error {
            @apply bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4;
        }
        .alert-success {
            @apply bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4;
        }
    </style>
</head>
<body class="min-h-screen bg-cover bg-center bg-no-repeat" style="background-image: url('images/food-donation-bg.jpg');">
    <div class="min-h-screen flex items-center justify-center bg-black/60 px-4 py-10">
        <section class="w-full max-w-md bg-white/80 backdrop-blur-lg rounded-2xl shadow-2xl p-8">
            <div class="text-primary-green text-4xl font-extrabold mb-3 flex items-center justify-center text-green-700">
                <i class="fas fa-hand-holding-heart mr-2"></i> SahaniShare
            </div>
            <h2 class="text-2xl font-bold text-center text-gray-800 mb-6" id="auth-title">Welcome Back!</h2>
            <?php echo $message; ?>

            <!-- Login Form -->
            <form id="login-form" class="space-y-4" method="POST">
                <input type="email" name="email" placeholder="Email Address" required class="w-full p-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-green-500">
                <input type="password" name="password" placeholder="Password" required class="w-full p-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-green-500">
                <button type="submit" name="login" class="w-full bg-green-600 text-white py-3 rounded-lg hover:bg-green-700 transition">Login</button>
                <a href="#" class="text-sm text-green-600 hover:underline block text-center">Forgot Password?</a>
                <p class="text-sm text-gray-700 text-center">Don't have an account? <a href="#" id="show-register" class="text-green-700 font-semibold hover:underline">Register here</a></p>
            </form>

            <!-- Register Form -->
            <form id="register-form" class="space-y-4 hidden" method="POST">
                <div class="text-left">
                    <label class="block text-sm font-medium text-gray-700 mb-2">I am a:</label>
                    <div class="flex gap-4">
                        <label class="inline-flex items-center">
                            <input type="radio" name="role" value="donor" class="form-radio text-green-600" checked>
                            <span class="ml-2 text-gray-800">Donor</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="role" value="recipient" class="form-radio text-green-600">
                            <span class="ml-2 text-gray-800">Recipient</span>
                        </label>
                    </div>
                </div>
                <input type="text" name="organization_name" placeholder="Organization Name" required class="w-full p-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-green-500">
                <input type="email" name="email" placeholder="Email Address" required class="w-full p-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-green-500">
                <input type="password" name="password" placeholder="Password" required class="w-full p-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-green-500">
                <input type="password" name="confirm_password" placeholder="Confirm Password" required class="w-full p-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-green-500">
                <button type="submit" name="register" class="w-full bg-green-600 text-white py-3 rounded-lg hover:bg-green-700 transition">Register</button>
                <p class="text-sm text-gray-700 text-center">Already have an account? <a href="#" id="show-login" class="text-green-700 font-semibold hover:underline">Login here</a></p>
            </form>
        </section>
    </div>

    <script>
        const loginForm = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const authTitle = document.getElementById('auth-title');
        document.getElementById('show-register').addEventListener('click', (e) => {
            e.preventDefault();
            loginForm.classList.add('hidden');
            registerForm.classList.remove('hidden');
            authTitle.textContent = 'Join SahaniShare!';
        });
        document.getElementById('show-login').addEventListener('click', (e) => {
            e.preventDefault();
            registerForm.classList.add('hidden');
            loginForm.classList.remove('hidden');
            authTitle.textContent = 'Welcome Back!';
        });
    </script>
</body>
</html>
