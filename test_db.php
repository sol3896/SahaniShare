<?php
// test_db.php
echo "Attempting to include db_connection.php...<br>";

// This line must match the exact path and filename of your db_connection.php
include_once 'db_connection.php'; 

echo "db_connection.php included.<br>";

// Attempt to call the function
if (function_exists('get_db_connection')) {
    echo "Function get_db_connection() exists.<br>";
    try {
        $test_conn = get_db_connection();
        if ($test_conn) {
            echo "Successfully connected to the database using get_db_connection()!<br>";
            echo "Database: " . DB_NAME . "<br>";
            $test_conn->close();
            echo "Connection closed.<br>";
        } else {
            echo "Failed to get a connection object from get_db_connection().<br>";
        }
    } catch (Exception $e) {
        echo "Error calling get_db_connection(): " . $e->getMessage() . "<br>";
    }
} else {
    echo "ERROR: Function get_db_connection() DOES NOT exist after including db_connection.php.<br>";
    echo "This indicates an issue with db_connection.php itself or its parsing.<br>";
}

echo "Test complete.<br>";
?>