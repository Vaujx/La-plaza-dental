<?php
session_start();
require_once "config.php";

// Check if the user is logged in and is an admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["admin_id"])){
    header("location: index.php");
    exit;
}

// Function to export table data to CSV
function exportTableToCSV($pdo, $tableName) {
    $filename = $tableName . '_' . date('Y-m-d_H-i-s') . '.csv';
    
    // Get column names
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = :tableName ORDER BY ordinal_position");
    $stmt->bindParam(':tableName', $tableName, PDO::PARAM_STR);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get table data
    $stmt = $pdo->prepare("SELECT * FROM $tableName");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create CSV content
    $output = fopen('php://temp', 'w');
    fputcsv($output, $columns);
    
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    
    return [
        'filename' => $filename,
        'content' => $csv
    ];
}

// Handle backup request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup'])) {
    $tables = ['admin', 'userss', 'dental_patients', 'confirmed_appointments'];
    $backupFiles = [];
    
    foreach ($tables as $table) {
        $backupFiles[] = exportTableToCSV($pdo, $table);
    }
    
    // Create a zip file
    $zipname = 'dental_clinic_backup_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipname, ZipArchive::CREATE) === TRUE) {
        foreach ($backupFiles as $file) {
            $zip->addFromString($file['filename'], $file['content']);
        }
        $zip->close();
        
        // Download the zip file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipname . '"');
        header('Content-Length: ' . filesize($zipname));
        readfile($zipname);
        
        // Delete the zip file
        unlink($zipname);
        exit;
    } else {
        $error_message = "Failed to create backup zip file.";
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
        if (isset($error_message)) {
            echo '<div class="alert alert-danger">' . $error_message . '</div>';
        }
        ?>
        
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Create Backup</h5>
                <p class="card-text">This will create a backup of all database tables in CSV format and download them as a ZIP file.</p>
                <form method="POST">
                    <button type="submit" name="backup" class="btn btn-primary">Create Backup</button>
                    <a href="admin_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>