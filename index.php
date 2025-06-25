<?php
// index.php
session_start(); // Start the session to check if a user is logged in

// Optionally include db_connection if you plan to show dynamic content on homepage
// For a simple static homepage, it might not be strictly necessary unless you need session_start() specifically here.
// For now, let's keep it minimal for performance and focus on content.
// include_once 'db_connection.php';

// Determine if a user is logged in to adjust navigation
$is_logged_in = isset($_SESSION['user_id']);
$user_role = $is_logged_in ? $_SESSION['user_role'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SahaniShare - Connecting Surplus Food to Those in Need</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts: Inter for body, Montserrat for headings -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Link to external style.css (for custom colors and typography if set) -->
    <link rel="stylesheet" href="style.css">
    <style>
        /* Base styles - applying explicitly with !important for maximum override */
        body {
            font-family: 'Inter', sans-serif !important;
            background-color: #f9fafb !important; /* bg-gray-50 */
            color: #374151 !important; /* text-gray-800 */
        }
        h1, h2, h3, h4 {
            font-family: 'Montserrat', sans-serif !important;
            color: #1f2937 !important; /* text-neutral-dark - a very dark gray */
        }
        p {
            color: #374151 !important; /* Ensuring paragraph text is dark gray */
        }

        /* Custom color definitions - also with !important */
        .text-primary-green { color: #28a745 !important; }
        .bg-primary-green { background-color: #28a745 !important; }
        .hover\:bg-primary-green-dark:hover { background-color: #218838 !important; }
        .text-neutral-dark { color: #343a40 !important; }
        .bg-neutral-dark { background-color: #343a40 !important; }

        /* Inverted button for hero section, ensuring good contrast */
        .btn-primary-inverted {
            background-color: #ffffff !important; /* white */
            color: #28a745 !important; /* primary-green */
            border: 1px solid #28a745 !important; /* primary-green border */
            font-weight: 600 !important;
            padding: 0.75rem 1.5rem !important; /* py-3 px-6 */
            border-radius: 0.375rem !important; /* rounded-md */
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
            transition: all 0.3s ease-in-out !important;
        }
        .btn-primary-inverted:hover {
            background-color: #28a745 !important; /* primary-green */
            color: #ffffff !important; /* white */
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
        }
        /* Basic button styles common across the app, ensuring good contrast */
        .btn-primary {
            background-color: #28a745 !important; /* primary-green */
            color: #ffffff !important; /* white */
            font-weight: 600 !important;
            padding: 0.75rem 1.5rem !important; /* py-3 px-6 */
            border-radius: 0.375rem !important; /* rounded-md */
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,.15) !important; /* shadow-md */
            transition: background-color 0.2s ease-in-out !important;
        }
        .btn-primary:hover {
            background-color: #218838 !important; /* primary-green-dark */
        }


        /* Keyframe animations for hero section */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.8s ease-out forwards;
        }
        .animate-fade-in-up.delay-200 { animation-delay: 0.2s; }
        .animate-fade-in-up.delay-400 { animation-delay: 0.4s; }
    </style>
</head>
<body class="flex flex-col min-h-screen">

    <!-- Navigation Bar -->
    <header class="bg-white shadow-md py-4 px-6 flex items-center justify-between sticky top-0 z-50">
        <div class="flex items-center">
            <a href="index.php" class="text-primary-green text-2xl font-bold mr-2">
                <i class="fas fa-hand-holding-heart"></i> SahaniShare
            </a>
        </div>
        <!-- Desktop Navigation -->
        <nav class="hidden md:flex space-x-6">
            <a href="index.php#about" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">About Us</a>
            <a href="index.php#how-it-works" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">How It Works</a>
            <a href="index.php#contact" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Contact</a>
            <?php if ($is_logged_in): ?>
                <?php
                    $dashboard_link = '';
                    switch ($user_role) {
                        case 'donor': $dashboard_link = 'donor-dashboard.php'; break;
                        case 'recipient': $dashboard_link = 'recipient-dashboard.php'; break;
                        case 'admin':
                        case 'moderator': $dashboard_link = 'admin-panel.php'; break;
                        default: $dashboard_link = '#'; break; // Fallback
                    }
                ?>
                <a href="<?php echo $dashboard_link; ?>" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Dashboard</a>
                <a href="logout.php" class="text-neutral-dark hover:text-primary-green font-medium transition duration-200">Logout</a>
            <?php else: ?>
                <a href="login-register.php" class="text-white bg-primary-green px-4 py-2 rounded-md hover:bg-primary-green-dark transition-colors font-medium">Login / Register</a>
            <?php endif; ?>
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
                <li><a href="index.php#about" class="block text-neutral-dark hover:text-primary-green font-medium py-2">About Us</a></li>
                <li><a href="index.php#how-it-works" class="block text-neutral-dark hover:text-primary-green font-medium py-2">How It Works</a></li>
                <li><a href="index.php#contact" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Contact</a></li>
                <?php if ($is_logged_in): ?>
                    <li><a href="<?php echo $dashboard_link; ?>" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Dashboard</a></li>
                    <li><a href="logout.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Logout</a></li>
                <?php else: ?>
                    <li><a href="login-register.php" class="block text-neutral-dark hover:text-primary-green font-medium py-2">Login / Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <main class="flex-grow">
        <!-- Hero Section -->
        <section class="relative bg-gradient-to-r from-primary-green to-green-600 text-white py-20 md:py-32 overflow-hidden">
            <div class="container mx-auto px-4 text-center z-10 relative">
                <h1 class="text-4xl md:text-6xl font-extrabold leading-tight mb-6 animate-fade-in-up">
                    Nourishing Communities, Reducing Waste.
                </h1>
                <p class="text-lg md:text-xl mb-10 opacity-90 animate-fade-in-up delay-200">
                    Connecting surplus food from businesses to those who need it most, sustainably.
                </p>
                <?php if (!$is_logged_in): ?>
                    <a href="login-register.php" class="btn-primary-inverted text-lg md:text-xl px-8 py-4 inline-block animate-fade-in-up delay-400">
                        Join SahaniShare Today!
                    </a>
                <?php else: ?>
                    <a href="<?php echo $dashboard_link; ?>" class="btn-primary-inverted text-lg md:text-xl px-8 py-4 inline-block animate-fade-in-up delay-400">
                        Go to Your Dashboard
                    </a>
                <?php endif; ?>
            </div>
            <!-- Background Blob/Shape for aesthetics -->
            <div class="absolute inset-0 z-0 opacity-20">
                <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" class="w-full h-full">
                    <path fill="#FFFFFF" d="M43.2,-78.4C56.6,-71.4,68.9,-61.7,77.3,-48.5C85.7,-35.3,90.2,-18.6,86.2,-2.3C82.2,14.1,69.7,29.9,59.3,46.9C48.9,63.9,40.6,82.1,28.2,88.7C15.7,95.3,-0.9,90.3,-18.2,85.2C-35.5,80.1,-53.5,75,-62.4,62.8C-71.3,50.7,-71.1,31.6,-70.7,14.9C-70.3,-1.7,-69.6,-16.9,-65.4,-31.4C-61.2,-45.9,-53.4,-59.8,-42,-69.2C-30.7,-78.6,-15.3,-83.5,1.2,-85.4C17.7,-87.3,35.4,-86.3,43.2,-78.4Z" transform="translate(100 100)" />
                </svg>
            </div>
        </section>

        <!-- About Us Section -->
        <section id="about" class="container mx-auto px-4 py-16 md:py-24">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-4xl font-bold text-neutral-dark mb-6">About SahaniShare</h2>
                    <p class="text-lg text-gray-700 mb-6 leading-relaxed">
                        In a world where food waste is a major issue and food insecurity affects millions, SahaniShare steps in as a bridge. We are a non-profit initiative dedicated to creating a seamless connection between businesses with surplus food and organizations serving communities in need.
                    </p>
                    <p class="text-lg text-gray-700 leading-relaxed">
                        Our platform simplifies the process of donating and receiving food, ensuring that nutritious, edible surplus food reaches plates instead of landfills. We believe in the power of collective action to build a more sustainable and equitable food system.
                    </p>
                </div>
                <div class="rounded-xl shadow-lg overflow-hidden">
                    <!-- Image: Food Donation Example -->
                    <img src="images/donation.jpg" alt="Food Donation" class="w-full h-auto object-cover">
                </div>
            </div>
        </section>

        <!-- How It Works Section -->
        <section id="how-it-works" class="bg-gray-100 py-16 md:py-24">
            <div class="container mx-auto px-4 text-center">
                <h2 class="text-4xl font-bold text-neutral-dark mb-12">How It Works</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-10">
                    <!-- Step 1: Donors Offer -->
                    <div class="flex flex-col items-center p-6 bg-white rounded-xl shadow-md transform hover:scale-105 transition-transform duration-300">
                        <div class="bg-primary-green text-white p-4 rounded-full mb-6">
                            <i class="fas fa-hand-holding-usd text-3xl"></i>
                        </div>
                        <h3 class="text-2xl font-semibold text-neutral-dark mb-4">For Donors: Offer Surplus</h3>
                        <p class="text-gray-700 leading-relaxed">
                            Businesses and individuals with surplus edible food can easily post details of their donations, including type, quantity, and expiry date, directly on our platform.
                        </p>
                    </div>
                    <!-- Step 2: Recipients Request -->
                    <div class="flex flex-col items-center p-6 bg-white rounded-xl shadow-md transform hover:scale-105 transition-transform duration-300">
                        <div class="bg-primary-green text-white p-4 rounded-full mb-6">
                            <i class="fas fa-box-open text-3xl"></i>
                        </div>
                        <h3 class="text-2xl font-semibold text-neutral-dark mb-4">For Recipients: Request Food</h3>
                        <p class="text-gray-700 leading-relaxed">
                            Approved non-profits, shelters, and community centers can browse available donations and request items that meet their needs.
                        </p>
                    </div>
                    <!-- Step 3: Match & Collect -->
                    <div class="flex flex-col items-center p-6 bg-white rounded-xl shadow-md transform hover:scale-105 transition-transform duration-300">
                        <div class="bg-primary-green text-white p-4 rounded-full mb-6">
                            <i class="fas fa-truck-loading text-3xl"></i>
                        </div>
                        <h3 class="text-2xl font-semibold text-neutral-dark mb-4">Match & Logistics</h3>
                        <p class="text-gray-700 leading-relaxed">
                            Donors approve requests, and recipients confirm collection. Our system facilitates the smooth transfer, ensuring food reaches its destination efficiently.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Call to Action / Join Us Section -->
        <section class="bg-neutral-dark text-white py-16 md:py-24 text-center">
            <div class="container mx-auto px-4">
                <h2 class="text-4xl font-bold mb-6">Make a Difference Today!</h2>
                <p class="text-lg mb-10 opacity-90 max-w-2xl mx-auto">
                    Join SahaniShare and become a part of the movement to fight hunger and reduce food waste in our communities.
                </p>
                <?php if (!$is_logged_in): ?>
                    <a href="login-register.php" class="btn-primary-inverted text-lg px-8 py-4 inline-block">
                        Sign Up Now
                    </a>
                <?php else: ?>
                    <a href="<?php echo $dashboard_link; ?>" class="btn-primary-inverted text-lg px-8 py-4 inline-block">
                        Go to Dashboard
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <!-- Contact Section -->
        <section id="contact" class="container mx-auto px-4 py-16 md:py-24">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
                <div>
                    <h2 class="text-4xl font-bold text-neutral-dark mb-6">Get in Touch</h2>
                    <p class="text-lg text-gray-700 mb-6">
                        Have questions, suggestions, or want to partner with us? We'd love to hear from you.
                    </p>
                    <ul class="space-y-4 text-lg text-gray-700">
                        <li><i class="fas fa-envelope text-primary-green mr-3"></i> info@sahanishare.org</li>
                        <li><i class="fas fa-phone-alt text-primary-green mr-3"></i> +254 711 222 333 (Kenya)</li>
                        <li><i class="fas fa-map-marker-alt text-primary-green mr-3"></i> Nairobi, Kenya</li>
                    </ul>
                </div>
                <div class="rounded-xl shadow-lg overflow-hidden">
                    <!-- Image: Community or Team Photo -->
                    <img src="images/community-support.jpg" alt="Community Support" class="w-full h-auto object-cover">
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white py-8 px-6 text-center">
        <div class="container mx-auto">
            <p class="mb-4">&copy; <?php echo date('Y'); ?> SahaniShare. All rights reserved.</p>
            <div class="flex justify-center space-x-6 text-2xl">
                <a href="#" class="hover:text-primary-green transition-colors"><i class="fab fa-facebook-f"></i></a>
                <a href="#" class="hover:text-primary-green transition-colors"><i class="fab fa-twitter"></i></a>
                <a href="#" class="hover:text-primary-green transition-colors"><i class="fab fa-instagram"></i></a>
                <a href="#" class="hover:text-primary-green transition-colors"><i class="fab fa-linkedin-in"></i></a>
            </div>
        </div>
    </footer>

    <script>
        // Mobile Menu Toggle Logic
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

        // Optional: Smooth scrolling for anchor links (e.g., #about)
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>