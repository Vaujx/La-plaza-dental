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

// PHP-only backup method for confirmed_appointments table
function createPHPBackup($pdo, $tableName) {
    $timestamp = date('Y-m-d_H-i-s');
    $filename = $tableName . '_backup_' . $timestamp . '.sql';
    $tempFile = sys_get_temp_dir() . '/' . $filename;
    
    try {
        // Get table structure
        $stmt = $pdo->prepare("SELECT column_name, data_type, character_maximum_length, 
                              is_nullable, column_default 
                              FROM information_schema.columns 
                              WHERE table_name = :tableName 
                              ORDER BY ordinal_position");
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
        
        // Get primary key information
        $stmt = $pdo->prepare("SELECT a.attname as column_name
                              FROM pg_index i
                              JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                              WHERE i.indrelid = :tableName::regclass AND i.indisprimary");
        $stmt->bindParam(':tableName', $tableName, PDO::PARAM_STR);
        $stmt->execute();
        $primaryKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Create DROP TABLE statement (commented out for safety)
        $sql .= "-- DROP TABLE IF EXISTS " . $tableName . ";\n\n";
        
        // Create table structure
        $sql .= "-- Table structure\n";
        $sql .= "CREATE TABLE IF NOT EXISTS " . $tableName . " (\n";
        
        $columnDefs = [];
        foreach ($columns as $column) {
            $columnDef = "    " . $column['column_name'] . " " . $column['data_type'];
            
            // Add length for character types
            if (!empty($column['character_maximum_length'])) {
                $columnDef .= "(" . $column['character_maximum_length'] . ")";
            }
            
            // Add nullable constraint
            if ($column['is_nullable'] === 'NO') {
                $columnDef .= " NOT NULL";
            }
            
            // Add default value if exists
            if (!empty($column['column_default'])) {
                $columnDef .= " DEFAULT " . $column['column_default'];
            }
            
            $columnDefs[] = $columnDef;
        }
        
        // Add primary key constraint if exists
        if (!empty($primaryKeys)) {
            $columnDefs[] = "    PRIMARY KEY (" . implode(", ", $primaryKeys) . ")";
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

// Function to restore backup
function restoreBackup($pdo, $filePath) {
    try {
        // Read the SQL file
        $sql = file_get_contents($filePath);
        if ($sql === false) {
            return [
                'success' => false,
                'error' => 'Could not read the backup file'
            ];
        }
        
        // Extract INSERT statements
        preg_match_all('/INSERT INTO confirmed_appointments $$(.*?)$$ VALUES $$(.*?)$$;/i', $sql, $matches, PREG_SET_ORDER);
        
        if (empty($matches)) {
            return [
                'success' => false,
                'error' => 'No valid INSERT statements found in the backup file'
            ];
        }
        
        $restoredCount = 0;
        $duplicateCount = 0;
        $errorCount = 0;
        
        // Begin transaction
        $pdo->beginTransaction();
        
        foreach ($matches as $match) {
            $columns = explode(', ', $match[1]);
            $values = explode(', ', $match[2]);
            
            // Check if this record already exists
            // We'll use the first column as a unique identifier (assuming it's the primary key)
            // This is a simplification - in a real app, you'd check all primary key columns
            $firstColumn = trim($columns[0]);
            $firstValue = trim($values[0], "'");
            
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM confirmed_appointments WHERE $firstColumn = :value");
            $checkStmt->bindParam(':value', $firstValue);
            $checkStmt->execute();
            
            if ($checkStmt->fetchColumn() > 0) {
                // Record already exists
                $duplicateCount++;
                continue;
            }
            
            // Insert the record
            $insertSQL = "INSERT INTO confirmed_appointments (" . $match[1] . ") VALUES (" . $match[2] . ")";
            try {
                $pdo->exec($insertSQL);
                $restoredCount++;
            } catch (PDOException $e) {
                $errorCount++;
            }
        }
        
        // Commit transaction
        $pdo->commit();
        
        return [
            'success' => true,
            'restored' => $restoredCount,
            'duplicates' => $duplicateCount,
            'errors' => $errorCount
        ];
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ];
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

// Handle PHP backup request
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

// Handle restore request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore']) && isset($_FILES['backup_file'])) {
    $uploadedFile = $_FILES['backup_file'];
    
    // Check for upload errors
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        $message = [
            'type' => 'danger',
            'text' => 'Upload failed with error code: ' . $uploadedFile['error']
        ];
    } 
    // Check file type
    elseif (pathinfo($uploadedFile['name'], PATHINFO_EXTENSION) !== 'sql') {
        $message = [
            'type' => 'danger',
            'text' => 'Only SQL files are allowed'
        ];
    } 
    else {
        // Process the uploaded file
        $restoreResult = restoreBackup($pdo, $uploadedFile['tmp_name']);
        
        if ($restoreResult['success']) {
            $message = [
                'type' => 'success',
                'text' => 'Restore completed: ' . $restoreResult['restored'] . ' records restored, ' . 
                          $restoreResult['duplicates'] . ' duplicates skipped, ' . 
                          $restoreResult['errors'] . ' errors encountered.'
            ];
        } else {
            $message = [
                'type' => 'danger',
                'text' => 'Restore failed: ' . $restoreResult['error']
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Backup & Restore - LA PLAZA DENTISTA</title>
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
        .btn-success {
            background-color: #28a745;
            border: none;
        }
        .btn-success:hover {
            background-color: #218838;
        }
        .btn-warning {
            background-color: #ffc107;
            border: none;
        }
        .btn-warning:hover {
            background-color: #e0a800;
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
        .card {
            margin-bottom: 20px;
        }
        .custom-file-label::after {
            content: "Browse";
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Database Backup & Restore</h1>
        
        <?php 
        if (isset($message)) {
            echo '<div class="alert alert-' . $message['type'] . '">' . $message['text'] . '</div>';
        }
        ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Create Backup</h5>
            </div>
            <div class="card-body">
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
            </div>
        </div>
        
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">Restore Backup</h5>
            </div>
            <div class="card-body">
                <p class="card-text">Upload a SQL backup file to restore appointments data. Only records that don't already exist will be imported.</p>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <div class="custom-file">
                            <input type="file" class="custom-file-input" id="backup_file" name="backup_file" required accept=".sql">
                            <label class="custom-file-label" for="backup_file">Choose backup file...</label>
                        </div>
                    </div>
                    <button type="submit" name="restore" class="btn btn-warning">Restore Backup</button>
                </form>
            </div>
        </div>
        
        <div class="text-center">
            <a href="admin_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>
        
       
    </div>
    
    <script>
        // Update file input label with selected filename
        document.querySelector('.custom-file-input').addEventListener('change', function(e) {
            var fileName = e.target.files[0].name;
            var nextSibling = e.target.nextElementSibling;
            nextSibling.innerText = fileName;
        });
    </script>
</body>
</html>
