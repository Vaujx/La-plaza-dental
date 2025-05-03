<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include config file
session_start();
require_once "config.php";
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';
// Check if the user is logged in and is an admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["admin_id"])){
    header("location: index.php?type=admin");
    exit;
}

// Function to get appointments by status
function getAppointmentsByStatus($pdo, $status) {
    $sql = "SELECT * FROM dental_patients WHERE status = :status ORDER BY appointment_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(":status", $status, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get pending user registrations
function getPendingUsers($pdo) {
    $sql = "SELECT id, username, full_name, email, phone_number FROM userss WHERE is_approved = FALSE ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get appointments by status
$pendingAppointments = getAppointmentsByStatus($pdo, 'pending');
$confirmedAppointments = getAppointmentsByStatus($pdo, 'confirmed');
$cancelledAppointments = getAppointmentsByStatus($pdo, 'cancelled');

// Get pending user registrations
$pendingUsers = getPendingUsers($pdo);

// Handle user approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_user'])) {
    $userId = $_POST['user_id'];
    
    // Generate activation token
    $token = bin2hex(random_bytes(32));
    
    // Update user record
    $sql = "UPDATE userss SET is_approved = TRUE, activation_token = :token WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(":token", $token, PDO::PARAM_STR);
    $stmt->bindParam(":id", $userId, PDO::PARAM_INT);
    
    if ($stmt->execute()) {
        // Get user email
        $sql = "SELECT email, full_name, username FROM userss WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":id", $userId, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Send activation email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'carigaimylot@gmail.com'; // Update with your email
                $mail->Password = 'ujrt libk awwi zktz'; // Update with your app password
                $mail->SMTPSecure = 'ssl';
                $mail->Port = 465;

                $mail->setFrom('your_email@gmail.com', 'LaPlaza Dental Clinic');
                $mail->addAddress($user['email'], $user['full_name']);

                // Create activation link
                $activationLink = "http://" . $_SERVER['HTTP_HOST'] . "/activate.php?token=" . $token;

                $mail->isHTML(true);
                $mail->Subject = 'Account Activation - LaPlaza Dental Clinic';
                $mail->Body = "
                    <h2>Account Approved</h2>
                    <p>Dear <strong>{$user['full_name']}</strong>,</p>
                    <p>Your account has been approved by the administrator. Please click the link below to activate your account:</p>
                    <p><a href='$activationLink'>Activate Your Account</a></p>
                    <p>Or copy and paste this URL into your browser:</p>
                    <p>$activationLink</p>
                    <br>
                    <p>Thank you,<br>LaPlaza Dental Clinic</p>
                    <p><small>This is an automated message. Please do not reply.</small></p>
                ";
                $mail->AltBody = "Dear {$user['full_name']}, your account has been approved. Please activate your account by visiting: $activationLink";
                $mail->send();
                
                $success_message = "User approved and activation email sent.";
            } catch (Exception $e) {
                error_log("Activation email failed: {$mail->ErrorInfo}");
                $error_message = "User approved but failed to send activation email.";
            }
        }
        
        // Refresh pending users list
        $pendingUsers = getPendingUsers($pdo);
    } else {
        $error_message = "Error approving user.";
    }
}

// Handle appointment status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_id']) && isset($_POST['action'])) {
    $appointmentId = $_POST['appointment_id'];
    $action = $_POST['action'];
    $time = isset($_POST['time']) ? $_POST['time'] : null;

    if ($action === 'confirm') {
        if (empty($time)) {
            $error_message = "Time must be provided for confirmation.";
        } else {
            $sql = "SELECT * FROM dental_patients WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $appointmentId, PDO::PARAM_INT);
            $stmt->execute();
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($appointment) {
                $sql = "INSERT INTO confirmed_appointments 
                        (user_id, patient_name, phone_number, email, address, gender, date_of_birth, allergies, procedure, appointment_date, appointment_time, status) 
                        VALUES 
                        (:user_id, :patient_name, :phone_number, :email, :address, :gender, :date_of_birth, :allergies, :procedure, :appointment_date, :appointment_time, :status)";

                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(":user_id", $appointment['user_id'], PDO::PARAM_INT);
                $stmt->bindParam(":patient_name", $appointment['patient_name'], PDO::PARAM_STR);
                $stmt->bindParam(":phone_number", $appointment['phone_number'], PDO::PARAM_STR);
                $stmt->bindParam(":email", $appointment['email'], PDO::PARAM_STR);
                $stmt->bindParam(":address", $appointment['address'], PDO::PARAM_STR);
                $stmt->bindParam(":gender", $appointment['gender'], PDO::PARAM_STR);
                $stmt->bindParam(":date_of_birth", $appointment['date_of_birth'], PDO::PARAM_STR);
                $stmt->bindParam(":allergies", $appointment['allergies'], PDO::PARAM_STR);
                $stmt->bindParam(":procedure", $appointment['procedure'], PDO::PARAM_STR);
                $stmt->bindParam(":appointment_date", $appointment['appointment_date'], PDO::PARAM_STR);
                $stmt->bindParam(":appointment_time", $time, PDO::PARAM_STR);
                $status = 'confirmed';
                $stmt->bindParam(":status", $status, PDO::PARAM_STR);

                if ($stmt->execute()) {
                    $sql = "UPDATE dental_patients SET status = 'confirmed' WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(":id", $appointmentId, PDO::PARAM_INT);
                    $stmt->execute();

                    // Send confirmation email
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'carigaimylot@gmail.com'; // Update with your email
                        $mail->Password = 'ujrt libk awwi zktz'; // Update with your app password
                        $mail->SMTPSecure = 'ssl';
                        $mail->Port = 465;

                        $mail->setFrom('your_email@gmail.com', 'LaPlaza Dental Clinic');
                        $mail->addAddress($appointment['email'], $appointment['patient_name']);

                        $mail->isHTML(true);
                        $mail->Subject = 'Appointment Confirmation - LaPlaza Dental Clinic';
                        $mail->Body = "
                            <h2>Appointment Confirmed</h2>
                            <p>Dear <strong>{$appointment['patient_name']}</strong>,</p>
                            <p>Your dental appointment on <strong>{$appointment['appointment_date']}</strong> at <strong>$time</strong> has been <b>confirmed</b>.</p>
                            <p>Please arrive 10 minutes early and bring any necessary documents.</p>
                            <br>
                            <p>Thank you,<br>LaPlaza Dental Clinic</p>
                            <p><small>This is an automated message. Please do not reply.</small></p>
                        ";
                        $mail->AltBody = "Dear {$appointment['patient_name']}, your appointment on {$appointment['appointment_date']} at $time is confirmed. Thank you - LaPlaza Dental Clinic.";
                        $mail->send();
                    } catch (Exception $e) {
                        error_log("Confirmation     	 failed: {$mail->ErrorInfo}");
                    }

                    $success_message = "Appointment confirmed and email sent.";
                    echo "<script>
                        alert('$success_message');
                        window.location.href = 'admin_dashboard.php?message=" . urlencode($success_message) . "';
                    </script>";
                    exit;
                } else {
                    $error_message = "Error saving confirmed appointment.";
                    echo "<script>
                        alert('$error_message');
                        window.location.href = 'admin_dashboard.php?message=" . urlencode($error_message) . "';
                    </script>";
                    exit;
                }
            } else {
                $error_message = "Appointment not found.";
                echo "<script>
                    alert('$error_message');
                    window.location.href = 'admin_dashboard.php?message=" . urlencode($error_message) . "';
                </script>";
                exit;
            }
        }
    } elseif ($action === 'cancel') {
        $sql = "UPDATE dental_patients SET status = 'cancelled' WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":id", $appointmentId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $sql = "SELECT patient_name, email, appointment_date FROM dental_patients WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $appointmentId, PDO::PARAM_INT);
            $stmt->execute();
            $cancelled = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cancelled) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'carigaimylot@gmail.com'; // Update with your email
                    $mail->Password = 'ujrt libk awwi zktz'; // Update with your app password
                    $mail->SMTPSecure = 'ssl';
                    $mail->Port = 465;

                    $mail->setFrom('your_email@gmail.com', 'LaPlaza Dental Clinic');
                    $mail->addAddress($cancelled['email'], $cancelled['patient_name']);

                    $mail->isHTML(true);
                    $mail->Subject = 'Appointment Cancelled - LaPlaza Dental Clinic';
                    $mail->Body = "
                        <h2>Appointment Cancelled</h2>
                        <p>Dear <strong>{$cancelled['patient_name']}</strong>,</p>
                        <p>We regret to inform you that your appointment scheduled for <strong>{$cancelled['appointment_date']}</strong> has been <b>cancelled</b>.</p>
                        <p>If this is a mistake, please contact our clinic to reschedule.</p>
                        <br>
                        <p>LaPlaza Dental Clinic</p>
                        <p><small>This is an automated message. Please do not reply.</small></p>
                    ";
                    $mail->AltBody = "Dear {$cancelled['patient_name']}, your appointment on {$cancelled['appointment_date']} has been cancelled. Contact us to reschedule.";

                    $mail->send();
                } catch (Exception $e) {
                    error_log("Cancellation email failed: {$mail->ErrorInfo}");
                }
            }

            $success_message = "Appointment cancelled and email sent.";
            echo "<script>
                alert('$success_message');
                window.location.href = 'admin_dashboard.php?message=" . urlencode($success_message) . "';
            </script>";
            exit;
        } else {
            $error_message = "Error cancelling appointment.";
            echo "<script>
                alert('$error_message');
                window.location.href = 'admin_dashboard.php?message=" . urlencode($error_message) . "';
            </script>";
            exit;
        }
    }
    
    // Refresh appointment lists
    $pendingAppointments = getAppointmentsByStatus($pdo, 'pending');
    $confirmedAppointments = getAppointmentsByStatus($pdo, 'confirmed');
    $cancelledAppointments = getAppointmentsByStatus($pdo, 'cancelled');
}

// Handle admin registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_admin'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Validate inputs
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "Username is required.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores.";
    } else {
        // Check if username exists
        $sql = "SELECT id FROM admin WHERE username = :username";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $errors[] = "Username already exists.";
        }
    }
    
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new admin
        $sql = "INSERT INTO admin (username, password) VALUES (:username, :password)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->bindParam(":password", $hashed_password, PDO::PARAM_STR);
        
        if ($stmt->execute()) {
            $admin_success = "Admin account created successfully.";
        } else {
            $admin_error = "Error creating admin account.";
        }
    } else {
        $admin_error = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - LA PLAZA DENTISTA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container-fluid {
            padding: 0;
        }
        .sidebar {
            background-color: #001F3F;
            color: white;
            height: 100vh;
            position: fixed;
            width: 250px;
            padding: 20px;
        }
        .sidebar .logo{
            width: 50px;
            height: auto;
            margin-right: 10px;
        }
        .h1, h1 {
            font-size: 24px;
            background-color: white;
            color: black;
            display: flex;
        }
        .sidebar h2 {
            font-size: 18px;
            margin: 0;
            color: white;
            text-align: center;
            background-color: #c72029;
            padding: 10px;
            margin-bottom: 20px;
        }
        .sidebar-nav {
            list-style: none;
            padding: 0;
        }
        .sidebar-nav li {
            margin-bottom: 10px;
        }
        .sidebar-nav button {
            width: 100%;
            text-align: left;
            padding: 10px;
            background: none;
            border: 1px solid white;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
        }
        .sidebar-nav button:hover {
            background-color: white;
            color: #001F3F;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        .dashboard-container {
            background-color: white;
            border-radius: 5px;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h3 {
            color: #c72029;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #c72029;
            color: white;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .btn-confirm {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        }
        .btn-cancel {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        }
        .form-container {
            max-width: 500px;
            margin: 0 auto;
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .logout-btn {
            display: block;
            width: 100%;
            padding: 10px;
            margin-top: 20px;
            background-color: #dc3545;
            color: white;
            text-align: center;
            border: 1px solid white;
            border-radius: 3px;
            text-decoration: none;
        }
        .logout-btn:hover {
            background-color: white;
            color: #dc3545;
            text-decoration: none;
        }
        .input-wrapper {
            position: relative;
        }
        .input-wrapper .form-control {
            padding-right: 40px;
        }
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 1.2rem;
            user-select: none;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="sidebar">
                <h2>DENTAL CLINIC</h2>
                <h1><img src="logo.png" alt="Logo" class="logo"> LA PLAZA DENTISTA</h1>
                <ul class="sidebar-nav">
                    <li><button onclick="showTab('pendingTab')">Pending Appointment</button></li>
                    <li><button onclick="showTab('confirmedTab')">Confirmed Appointments</button></li>
                    <li><button onclick="showTab('cancelledTab')">Cancelled Appointments</button></li>
                    <li><button onclick="showTab('usersTab')">User Approvals</button></li>
                    <li><button onclick="showTab('adminTab')">Admin Management</button></li>
                    <li>
                        <a href="backup.php" class="btn btn-success">📥 Backup</a>
                        <a href="index.php?logout=1" class="logout-btn">Logout</a>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="main-content">
                <h2>Admin Dashboard</h2>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION["admin_username"]); ?>!</p>
                
                <?php 
                if (isset($success_message)) {
                    echo '<div class="alert alert-success">' . $success_message . '</div>';
                }
                if (isset($error_message)) {
                    echo '<div class="alert alert-danger">' . $error_message . '</div>';
                }
                ?>
                
                <!-- Pending Appointments Tab -->
                <div id="pendingTab" class="tab-content active">
                    <div class="dashboard-container">
                        <h3>Pending Appointments</h3>
                        
                        <?php if (count($pendingAppointments) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Patient Name</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Date</th>
                                        <th>Procedure</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingAppointments as $appointment): ?>
                                        <tr>
                                            <td><?php echo $appointment['id']; ?></td>
                                            <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['phone_number']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['email']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['procedure']); ?></td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                    <input type="time" name="time" required>
                                                    <button type="submit" name="action" value="confirm" class="btn-confirm">Confirm</button>
                                                    <button type="submit" name="action" value="cancel" class="btn-cancel">Cancel</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No pending appointments.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Confirmed Appointments Tab -->
                <div id="confirmedTab" class="tab-content">
                    <div class="dashboard-container">
                        <h3>Confirmed Appointments</h3>
                        
                        <?php if (count($confirmedAppointments) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Patient Name</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Date</th>
                                        <th>Procedure</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($confirmedAppointments as $appointment): ?>
                                        <tr>
                                            <td><?php echo $appointment['id']; ?></td>
                                            <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['phone_number']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['email']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['procedure']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No confirmed appointments.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Cancelled Appointments Tab -->
                <div id="cancelledTab" class="tab-content">
                    <div class="dashboard-container">
                        <h3>Cancelled Appointments</h3>
                        
                        <?php if (count($cancelledAppointments) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Patient Name</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Date</th>
                                        <th>Procedure</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cancelledAppointments as $appointment): ?>
                                        <tr>
                                            <td><?php echo $appointment['id']; ?></td>
                                            <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['phone_number']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['email']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
                                            <td><?php echo htmlspecialchars($appointment['procedure']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No cancelled appointments.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- User Approvals Tab -->
                <div id="usersTab" class="tab-content">
                    <div class="dashboard-container">
                        <h3>Pending User Approvals</h3>
                        
                        <?php if (count($pendingUsers) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingUsers as $user): ?>
                                        <tr>
                                            <td><?php echo $user['id']; ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['phone_number']); ?></td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" name="approve_user" class="btn-confirm">Approve User</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No pending user approvals.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Admin Management Tab -->
                <div id="adminTab" class="tab-content">
                    <div class="dashboard-container">
                        <h3>Admin Management</h3>
                        
                        <?php 
                        if (isset($admin_success)) {
                            echo '<div class="alert alert-success">' . $admin_success . '</div>';
                        }
                        if (isset($admin_error)) {
                            echo '<div class="alert alert-danger">' . $admin_error . '</div>';
                        }
                        ?>
                        
                        <div class="form-container">
                            <h4>Register New Admin</h4>
                            <form method="POST">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>

                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <div class="input-wrapper">
                                        <input type="password" class="form-control" id="password" name="password" required>
                                        <span class="toggle-password" id="togglePassword" onclick="togglePasswordVisibility('password', 'togglePassword')">👁</span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="confirm_password">Confirm Password</label>
                                    <div class="input-wrapper">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        <span class="toggle-password" id="toggleConfirmPassword" onclick="togglePasswordVisibility('confirm_password', 'toggleConfirmPassword')">👁</span>
                                    </div>
                                </div>

                                <button type="submit" name="register_admin" class="btn btn-primary">Register Admin</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function showTab(tabId) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabId).classList.add('active');
        }

        function togglePasswordVisibility(passwordFieldId, toggleIconId) {
            const passwordField = document.getElementById(passwordFieldId);
            const toggleIcon = document.getElementById(toggleIconId);

            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.textContent = '👁‍🗨';
            } else {
                passwordField.type = 'password';
                toggleIcon.textContent = '👁';
            }
        }
    </script>
</body>
</html>

## 3. Create an Activation Page (activate.php)

Here's the code for the activation page that users will be directed to when they click the link in their email:

```php type="code"
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
