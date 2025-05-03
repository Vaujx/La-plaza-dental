<?php
session_start();
require_once "config.php";

// Logout logic
if (isset($_GET['logout'])) {
    $_SESSION = array();
    session_destroy();
    header("location: index.php");
    exit;
}

// Redirect if already logged in
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if (isset($_SESSION["admin_id"])) {
        header("location: admin_dashboard.php");
    } else {
        header("location: client_dashboard.php");
    }
    exit;
}

// Init
$username = $password = "";
$username_err = $password_err = $login_err = "";

// Handle login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter username.";
    } else {
        $username = trim($_POST["username"]);
    }

    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }

    if (empty($username_err) && empty($password_err)) {
        // Check for hardcoded admin credentials
        if ($username === "EFREN MD" && $password === "098765") {
            // Hardcoded admin login successful
            $_SESSION["loggedin"] = true;
            $_SESSION["admin_id"] = 1; // Assuming ID 1 for hardcoded admin
            $_SESSION["admin_username"] = $username;
            $_SESSION["role"] = "admin";
            header("location: admin_dashboard.php");
            exit;
        } else {
            // First: Try the admin table (for other admins)
            $sql_admin = "SELECT id, username, password FROM admin WHERE username = :username";
            $stmt_admin = $pdo->prepare($sql_admin);
            $stmt_admin->bindParam(":username", $username, PDO::PARAM_STR);
            $stmt_admin->execute();

            if ($stmt_admin->rowCount() == 1) {
                $row = $stmt_admin->fetch();
                if (password_verify($password, $row["password"])) {
                    $_SESSION["loggedin"] = true;
                    $_SESSION["admin_id"] = $row["id"];
                    $_SESSION["admin_username"] = $row["username"];
                    $_SESSION["role"] = "admin";
                    header("location: admin_dashboard.php");
                    exit;
                } else {
                    $login_err = "Invalid username or password.";
                }
            } else {
                // Then: Try the users table (patients)
                $sql_user = "SELECT id, username, password, is_approved, is_activated FROM userss WHERE username = :username";
                $stmt_user = $pdo->prepare($sql_user);
                $stmt_user->bindParam(":username", $username, PDO::PARAM_STR);
                $stmt_user->execute();

                if ($stmt_user->rowCount() == 1) {
                    $row = $stmt_user->fetch();
                    if (password_verify($password, $row["password"])) {
                        // Check if account is approved and activated
                        if (!$row["is_approved"]) {
                            $login_err = "Your account is pending approval by an administrator.";
                        } elseif (!$row["is_activated"]) {
                            $login_err = "Please check your email and activate your account before logging in.";
                        } else {
                            // Account is approved and activated, proceed with login
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $row["id"];
                            $_SESSION["username"] = $row["username"];
                            $_SESSION["role"] = "patient";
                            header("location: client_dashboard.php");
                            exit;
                        }
                    } else {
                        $login_err = "Invalid username or password.";
                    }
                } else {
                    $login_err = "Invalid username or password.";
                }
            }
        }
    }

    unset($stmt_admin, $stmt_user, $pdo);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - LA PLAZA DENTISTA</title>
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
            max-width: 400px;
        }
        h1 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
            font-family: times two roman;
        }
        .form-group label {
            font-weight: bold;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        .btn-primary {
            width: 100%;
            padding: 10px;
        }
        .text-center a {
            color: #007bff;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <h1>LA PLAZA DENTISTA</h1>
        <h2 class="text-center">Login</h2>
        <p class="text-center">Enter your credentials to access your dashboard.</p>

        <?php 
        if (!empty($login_err)) {
            echo '<div class="alert alert-danger">' . $login_err . '</div>';
        }        
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" 
                    class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" 
                    value="<?php echo htmlspecialchars($username); ?>">
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>    
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" 
                    class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            <div class="form-group mt-3">
                <input type="submit" class="btn btn-primary" value="Login">
            </div>
            <p class="text-center mt-2">Don't have an account? <a href="usregister.php">Register here</a>.</p>
        </form>
    </div>    
</body>
</html>
