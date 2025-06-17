<?php
// db_connection.php

// Database configuration
define('DB_SERVER', 'localhost'); // Your database server, usually 'localhost' with XAMPP
define('DB_USERNAME', 'root');   // Default XAMPP MySQL username
define('DB_PASSWORD', '');       // Default XAMPP MySQL password (empty by default)
define('DB_NAME', 'sahanishare_db'); // The database name we created

// Attempt to connect to MySQL database
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($mysqli->connect_error) {
    die("ERROR: Could not connect. " . $mysqli->connect_error);
}

// Set character set to UTF-8 for proper handling of characters
$mysqli->set_charset("utf8mb4");

// You can now use $mysqli for database queries, e.g.:
/*
$sql = "SELECT id, email, organization_name FROM users";
$result = $mysqli->query($sql);

if ($result->num_rows > 0) {
    // Output data of each row
    while($row = $result->fetch_assoc()) {
        echo "ID: " . $row["id"]. " - Email: " . $row["email"]. " - Org: " . $row["organization_name"]. "<br>";
    }
} else {
    echo "0 results";
}

// Close connection (important to do after all database operations)
$mysqli->close();
*/

// Example of a function to get a database connection
function get_db_connection() {
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Example usage in another PHP file (e.g., login.php)
// include_once 'db_connection.php';
// $conn = get_db_connection();
// // Perform your database operations with $conn
// $conn->close();

?>
