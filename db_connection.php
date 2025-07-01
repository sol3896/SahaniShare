<?php
// db_connection.php

// Database configuration
define('DB_SERVER', 'localhost'); // database server, usually 'localhost' with XAMPP
define('DB_USERNAME', 'root');   // Default XAMPP MySQL username
// IMPORTANT: For a XAMPP installation, the root user usually has NO password.
// If you set a password, replace the empty string with your password.
define('DB_PASSWORD', '');       // Default XAMPP MySQL password (empty by default)
define('DB_NAME', 'sahanishare_db'); // The database name we created

// Attempt to connect to MySQL database using the global $mysqli variable
// This initial connection is for general use throughout the application if needed directly.
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($mysqli->connect_error) {
    // For debugging, it's okay to show $mysqli->connect_error. For production,
    // you'd typically log this error and display a generic message to the user.
    die("ERROR: Could not connect to the database. " . $mysqli->connect_error);
}

// Set character set to UTF-8 for proper handling of characters
$mysqli->set_charset("utf8mb4");

/**
 * Establishes and returns a new MySQLi database connection.
 * This function is used when a fresh, independent connection is needed,
 * for example, within functions or when transaction management requires it.
 *
 * @return mysqli A new MySQLi database connection object.
 * @throws Exception If the database connection fails.
 */
function get_db_connection() {
    // Create a new MySQLi connection instance
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

    // Check if the connection was successful
    if ($conn->connect_error) {
        // If connection fails, terminate script and display error (for development)
        // In a production environment, you would log this error and show a user-friendly message.
        die("Connection failed: " . $conn->connect_error);
    }

    // Set the character set for the new connection
    $conn->set_charset("utf8mb4");

    // Return the established connection
    return $conn;
}

?>

