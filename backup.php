<?php
session_start();
require_once "config.php";

// Check if the user is logged in and is an admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["admin_id"])){
    header("location: index.php");
    exit;
}

// Function to create a PostgreSQL database backup using pg_dump
function createPostgresBackup($pdo, $tableName = null) {
    // We need to get database credentials from config.php
    // Since we're including config.php at the top, we can use global variables
    // that are likely defined there, such as $db_host, $db_name, $db_user, $db_password
    
    // These variable names might be different in your config.php
    // Adjust them according to your actual configuration
    global $db_host, $db_name, $db_user, $db_password;
    
    // If globals aren't available, try to extract from DSN
    if (!isset($db_host) || !isset($db_name) || !isset($db_user)) {
        try {
            // Get DSN attributes
            $dsn = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            preg_match('/host=([^;]+);.*dbname=([^;]+)/', $dsn, $matches);
            
            // Default values if not found
            $db_host = isset($matches[1]) ? $matches[1] : 'localhost';
            $db_name = isset($matches[2]) ? $matches[2] : '';
            
            // We can't reliably get username from PDO, so use a default if not set
            if (!isset($db_user)) {
                $db_user = 'postgres'; // Default PostgreSQL username
            }
        } catch (Exception $e) {
            // If we can't get connection info, use defaults
            $db_host = 'localhost';
            $db_name = '';
            $db_user = 'postgres';
        }
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    
    // Create a temporary directory for the backup
    $tempDir = sys_get_temp_dir() . '/pg_backup_' . uniqid();
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    // Set the backup filename
    $backupFile = $tempDir . '/backup_';
    if ($tableName) {
        $backupFile .= $tableName;
    } else {
        $backupFile .= $db_name;
    }
    $backupFile .= '_' . $timestamp . '.sql';
    
    // Build the pg_dump command
    $command = "PGPASSWORD='" . escapeshellarg($db_password) . "' pg_dump -h " . escapeshellarg($db_host) . " -U " . escapeshellarg($db_user) . " ";
    
    // If a specific table is requested, add it to the command
    if ($tableName) {
        $command .= "-t " . escapeshellarg($tableName) . " ";
    }
    
    // Complete the command
    $command .= "-f " . escapeshellarg($backupFile) . " " . escapeshellarg($db_name) . " 2>&1";
    
    // Execute the command
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        // Error occurred
        return [
            'success' => false,
            'error' => implode("\n", $output)
        ];
    }
    
    // Check if the backup file was created and has content
    if (!file_exists($backupFile) || filesize($backupFile) === 0) {
        return [
            'success' => false,
            'error' => 'Backup file was not created or is empty'
        ];
    }
    
    return [
        'success' => true,
        'filename' => basename($backupFile),
        'filepath' => $backupFile,
        'tempdir' => $tempDir
    ];
}

// Function to clean up temporary files
function cleanupTempFiles($tempDir) {
    if (is_dir($tempDir)) {
        $files = glob($tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($tempDir);
    }
}

// Handle backup request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup'])) {
    // Only backup confirmed_appointments table
    $table = 'confirmed_appointments';
    $backupResult = createPostgresBackup($pdo, $table);
    
    if ($backupResult['success']) {
        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backupResult['filename'] . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backupResult['filepath']));
        
        // Clear output buffer
        ob_clean();
        flush();
        
        // Read the file and output it to the browser
        readfile($backupResult['filepath']);
        
        // Clean up temporary files
        cleanupTempFiles($backupResult['tempdir']);
        
        exit;
    } else {
        $message = [
            'type' => 'danger',
            'text' => 'Backup failed: ' . $backupResult['error']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Backup - LA PLAZA DENTISTA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #c72029;
            text-align: center;
            margin-bottom: 30px;
        }
        .btn-primary {
            background-color: #001F3F;
            border: none;
        }
        .btn-primary:hover {
            background-color: #003366;
        }
        .btn-secondary {
            background-color: #6c757d;
            border: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Database Backup</h1>
        
        <?php 
        if (isset($message)) {
            echo '<div class="alert alert-' . $message['type'] . '">' . $message['text'] . '</div>';
        }
        ?>
        
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Create Backup</h5>
                <p class="card-text">This will create a backup of the confirmed appointments table using PostgreSQL's pg_dump utility.</p>
                <form method="POST">
                    <button type="submit" name="backup" class="btn btn-primary">Create Backup</button>
                    <a href="admin_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
