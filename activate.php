<?php
// Include config file
require_once "config.php";

// Initialize variables
$token = "";
$message = "";
$message_class = "";

// Check if token is provided in URL
if(isset($_GET["token"]) && !empty(trim($_GET["token"]))) {
    $token = trim($_GET["token"]);
    
    // Prepare a select statement
    $sql = "SELECT id, username, email, full_name FROM userss WHERE activation_token = :token AND is_approved = TRUE AND is_activated = FALSE";
    
    if($stmt = $pdo->prepare($sql)) {
        // Bind variables to the prepared statement as parameters
        $stmt->bindParam(":token", $token, PDO::PARAM_STR);
        
        // Attempt to execute the prepared statement
        if($stmt->execute()) {
            if($stmt->rowCount() == 1) {
                // Fetch user data
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Update user as activated
                $update_sql = "UPDATE userss SET is_activated = TRUE, activation_token = NULL WHERE id = :id";
                if($update_stmt = $pdo->prepare($update_sql)) {
                    $update_stmt->bindParam(":id", $user["id"], PDO::PARAM_INT);
                    
                    if($update_stmt->execute()) {
                        $message = "Your account has been successfully activated. You can now <a href='index.php'>login</a> with your credentials.";
                        $message_class = "success";
                    } else {
                        $message = "Oops! Something went wrong. Please try again later.";
                        $message_class = "danger";
                    }
                }
            } else {
                $message = "Invalid activation link or your account is already activated.";
                $message_class = "danger";
            }
        } else {
            $message = "Oops! Something went wrong. Please try again later.";
            $message_class = "danger";
        }
        
        // Close statement
        unset($stmt);
    }
} else {
    $message = "Invalid activation link.";
    $message_class = "danger";
}

// Close connection
unset($pdo);
?>

&lt;!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Account Activation - LA PLAZA DENTISTA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            background: linear-gradient(to right, #f0f2f5, #d9e2ec);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: Arial, sans-serif;
        }
        .wrapper {
            background: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0px 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            text-align: center;
        }
        h1 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
            font-family: times two roman;
        }
        .btn-primary {
            background-color: #001F3F;
            border: none;
        }
        .btn-primary:hover {
            background-color: #003366;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <h1>LA PLAZA DENTISTA</h1>
        <h2>Account Activation</h2>
        
        <div class="alert alert-<?php echo $message_class; ?>">
            <?php echo $message; ?>
        </div>
        
        <div class="mt-4">
            <a href="index.php" class="btn btn-primary">Go to Login</a>
        </div>
    </div>
</body>
</html>
