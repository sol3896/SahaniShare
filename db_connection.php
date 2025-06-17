<?php
// db_connection.php

// Database configuration
define('DB_SERVER', 'localhost'); // Your database server, usually 'localhost' with XAMPP
define('DB_USERNAME', 'root');   // Default XAMPP MySQL username
// IMPORTANT: For a default XAMPP installation, the root user usually has NO password.
// If you set a password, replace the empty string with your password.
define('DB_PASSWORD', '');       // Default XAMPP MySQL password (empty by default)
define('DB_NAME', 'sahanishare_db'); // The database name we created

// Attempt to connect to MySQL database
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($mysqli->connect_error) {
    // For debugging, it's okay to show $mysqli->connect_error. For production,
    // you'd typically log this error and display a generic message to the user.
    die("ERROR: Could not connect to the database. " . $mysqli->connect_error);
}

// Set character set to UTF-8 for proper handling of characters
$mysqli->set_charset("utf8mb4");

// Example of a function to get a database connection
// This function ensures a fresh connection is returned when called.
function get_db_connection() {
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

?>
