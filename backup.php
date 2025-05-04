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
    // Use the constants from config.php
    $db_host = DB_SERVER;
    $db_port = DB_PORT;
    $db_name = DB_NAME;
    $db_user = DB_USERNAME;
    $db_password = DB_PASSWORD;
    
    // Check if credentials are available
    if (empty($db_host) || empty($db_name) || empty($db_user)) {
        return [
            'success' => false,
            'error' => 'Database credentials are not properly defined'
        ];
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
    
    // First, check if pg_dump is installed
    exec('which pg_dump 2>/dev/null', $pgDumpPath, $returnCode);
    
    if ($returnCode !== 0) {
        // Try to find pg_dump in common locations
        $commonPaths = [
            '/usr/bin/pg_dump',
            '/usr/local/bin/pg_dump',
            '/usr/local/pgsql/bin/pg_dump',
            '/opt/postgresql/bin/pg_dump'
        ];
        
        $pgDumpCmd = '';
        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                $pgDumpCmd = $path;
                break;
            }
        }
        
        if (empty($pgDumpCmd)) {
            return [
                'success' => false,
                'error' => 'pg_dump command not found. Please install PostgreSQL client tools.'
            ];
        }
    } else {
        $pgDumpCmd = trim($pgDumpPath[0]); // Get the full path to pg_dump
        
        // If pg_dump path is empty, try with just 'pg_dump'
        if (empty($pgDumpCmd)) {
            $pgDumpCmd = 'pg_dump';
        }
    }
    
    // Build the pg_dump command with proper string handling
    $command = '';
    
    // Add password as environment variable if it exists
    if (!empty($db_password)) {
        $command .= "PGPASSWORD=" . escapeshellarg($db_password) . " ";
    }
    
    // Add the pg_dump command with host, port, and user
    $command .= $pgDumpCmd . " -h " . escapeshellarg($db_host) . " -p " . escapeshellarg($db_port) . " -U " . escapeshellarg($db_user) . " ";
    
    // If a specific table is requested, add it to the command
    if ($tableName) {
        $command .= "-t " . escapeshellarg($tableName) . " ";
    }
    
    // Complete the command
    $command .= "-f " . escapeshellarg($backupFile) . " " . escapeshellarg($db_name) . " 2>&1";
    
    // For debugging
    $debug_info = "Command: " . $command;
    
    // Execute the command
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        // Error occurred
        return [
            'success' => false,
            'error' => implode("\n", $output) . "\n\nDebug info: " . $debug_info
        ];
    }
    
    // Check if the backup file was created and has content
    if (!file_exists($backupFile) || filesize($backupFile) === 0) {
        return [
            'success' => false,
            'error' => 'Backup file was not created or is empty. Debug info: ' . $debug_info
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
        if (ob_get_level()) {
            ob_end_clean();
        }
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

// Alternative PHP-only backup method
function createPHPBackup($pdo, $tableName) {
    $timestamp = date('Y-m-d_H-i-s');
    $filename = $tableName . '_backup_' . $timestamp . '.sql';
    $tempFile = sys_get_temp_dir() . '/' . $filename;
    
    try {
        // Get table structure
        $stmt = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = :tableName ORDER BY ordinal_position");
        $stmt->bindParam(':tableName', $tableName, PDO::PARAM_STR);
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($columns)) {
            return [
                'success' => false,
                'error' => 'Table not found or has no columns'
            ];
        }
        
        // Start building SQL file
        $sql = "-- PostgreSQL database backup\n";
        $sql .= "-- Table: " . $tableName . "\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Create table structure
        $sql .= "-- Table structure\n";
        $sql .= "CREATE TABLE IF NOT EXISTS " . $tableName . " (\n";
        
        $columnDefs = [];
        foreach ($columns as $column) {
            $columnDefs[] = "    " . $column['column_name'] . " " . $column['data_type'];
        }
        
        $sql .= implode(",\n", $columnDefs);
        $sql .= "\n);\n\n";
        
        // Get table data
        $stmt = $pdo->prepare("SELECT * FROM " . $tableName);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($rows)) {
            $sql .= "-- Table data\n";
            
            foreach ($rows as $row) {
                $columnNames = array_keys($row);
                $columnValues = array_values($row);
                
                // Escape values
                foreach ($columnValues as &$value) {
                    if ($value === null) {
                        $value = 'NULL';
                    } else {
                        $value = "'" . str_replace("'", "''", $value) . "'";
                    }
                }
                
                $sql .= "INSERT INTO " . $tableName . " (" . implode(", ", $columnNames) . ") VALUES (" . implode(", ", $columnValues) . ");\n";
            }
        }
        
        // Write to file
        file_put_contents($tempFile, $sql);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $tempFile
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ];
    }
}

// Add a button for PHP-only backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['php_backup'])) {
    $table = 'confirmed_appointments';
    $backupResult = createPHPBackup($pdo, $table);
    
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
        if (ob_get_level()) {
            ob_end_clean();
        }
        flush();
        
        // Read the file and output it to the browser
        readfile($backupResult['filepath']);
        
        // Clean up
        unlink($backupResult['filepath']);
        
        exit;
    } else {
        $message = [
            'type' => 'danger',
            'text' => 'PHP Backup failed: ' . $backupResult['error']
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
        .debug-info {
            margin-top: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: monospace;
            white-space: pre-wrap;
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
                <p class="card-text">This will create a backup of the confirmed appointments table.</p>
                
                <div class="row">
                    <div class="col-md-6">
                        <form method="POST" class="mb-3">
                            <button type="submit" name="backup" class="btn btn-primary btn-block">Create Backup (pg_dump)</button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <form method="POST" class="mb-3">
                            <button type="submit" name="php_backup" class="btn btn-success btn-block">Create Backup (PHP Only)</button>
                        </form>
                    </div>
                </div>
                
                <a href="admin_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>   
    </div>
</body>
</html>
