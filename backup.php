<?php
session_start();
require_once "config.php";

// Check if the user is logged in and is an admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["admin_id"])){
    header("location: index.php");
    exit;
}

// Enhanced PHP backup method for confirmed_appointments table
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
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Hosting: Render\n\n";
        
        // Try to get primary key information
        try {
            $stmt = $pdo->prepare("SELECT a.attname as column_name
                                  FROM pg_index i
                                  JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                                  WHERE i.indrelid = :tableName::regclass AND i.indisprimary");
            $stmt->bindParam(':tableName', $tableName, PDO::PARAM_STR);
            $stmt->execute();
            $primaryKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // If we can't get primary keys, just continue without them
            $primaryKeys = [];
        }
        
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
            $sql .= "-- " . count($rows) . " records found\n";
            
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
        } else {
            $sql .= "-- No data found in table\n";
        }
        
        // Write to file
        file_put_contents($tempFile, $sql);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $tempFile,
            'records' => count($rows)
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
            'text' => 'Backup failed: ' . $backupResult['error']
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
        .card {
            margin-bottom: 20px;
        }
        .custom-file-label::after {
            content: "Browse";
        }
        .render-note {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 10px 15px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Database Backup & Restore</h1>
        
        <div class="render-note">
            <strong>Note:</strong> This backup tool is optimized for Render hosting and uses PHP to create SQL backups of your confirmed appointments data.
        </div>
        
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
                <p class="card-text">This will create a backup of the confirmed appointments table. The backup file will be downloaded to your computer.</p>
                
                <form method="POST" class="mb-3">
                    <button type="submit" name="backup" class="btn btn-primary btn-block">Create Backup</button>
                </form>
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
        
        <div class="card mt-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">About Database Backups</h5>
            </div>
            <div class="card-body">
                <p><strong>What gets backed up?</strong></p>
                <ul>
                    <li>All appointment data from the <code>confirmed_appointments</code> table</li>
                    <li>Table structure including columns, data types, and constraints</li>
                </ul>
                
                <p><strong>Backup Best Practices:</strong></p>
                <ul>
                    <li>Create regular backups (weekly or monthly)</li>
                    <li>Store backup files in multiple secure locations</li>
                    <li>Test restoring from backups periodically</li>
                </ul>
            </div>
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
