<?php
/* Database credentials for PostgreSQL */
define('DB_SERVER', 'dpg-d0alnibuibrs73aa8q2g-a.oregon-postgres.render.com');
define('DB_PORT', '5432');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'mRlnKINUfWqOH1yUzql6f5F4xk7BGAhL');
define('DB_NAME', 'dentist_pf6d');

/* Attempt to connect to PostgreSQL database */
try {
    $dsn = "pgsql:host=" . DB_SERVER . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
    
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Start session if not already started
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
} catch(PDOException $e) {
    die("ERROR: Could not connect. " . $e->getMessage());
}
?>