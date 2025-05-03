<?php
session_start();
require_once "config.php";

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["id"])){
    header("location: index.php");
    exit;
}

// Get the logged-in user's ID
$user_id = $_SESSION["id"];

// Handle appointment form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['addAppointment'])) {
    $patientName = trim($_POST['patientName']);
    $patientPhone = trim($_POST['patientPhone']);
    $email = trim($_POST['email']);
    $patientAddress = trim($_POST['patientAddress']);
    $gender = $_POST['gender'];
    $dob = $_POST['dobYear'] . '-' . $_POST['dobMonth'] . '-' . $_POST['dobDay'];
    $allergies = isset($_POST['allergies']) ? implode(", ", $_POST['allergies']) : "";
    $procedure = isset($_POST['procedure']) ? implode(", ", $_POST['procedure']) : "";
    $appointmentDate = $_POST['appointmentDate'];

    // Validate inputs
    $errors = [];
    if(empty($patientName)) $errors[] = "Patient name is required";
    if(!preg_match('/^[0-9]{10,15}$/', $patientPhone)) $errors[] = "Invalid phone number format";
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    
    if(empty($errors)) {
        // Prepare an insert statement
        $sql = "INSERT INTO dental_patients (user_id, patient_name, phone_number, email, address, gender, date_of_birth, allergies, procedure, appointment_date, status) 
                VALUES (:user_id, :patient_name, :phone_number, :email, :address, :gender, :date_of_birth, :allergies, :procedure, :appointment_date, 'pending')";
        
        if($stmt = $pdo->prepare($sql)){
            // Bind variables to the prepared statement as parameters
            $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
            $stmt->bindParam(":patient_name", $patientName, PDO::PARAM_STR);
            $stmt->bindParam(":phone_number", $patientPhone, PDO::PARAM_STR);
            $stmt->bindParam(":email", $email, PDO::PARAM_STR);
            $stmt->bindParam(":address", $patientAddress, PDO::PARAM_STR);
            $stmt->bindParam(":gender", $gender, PDO::PARAM_STR);
            $stmt->bindParam(":date_of_birth", $dob, PDO::PARAM_STR);
            $stmt->bindParam(":allergies", $allergies, PDO::PARAM_STR);
            $stmt->bindParam(":procedure", $procedure, PDO::PARAM_STR);
            $stmt->bindParam(":appointment_date", $appointmentDate, PDO::PARAM_STR);
            
            // Attempt to execute the prepared statement
            if($stmt->execute()){
                $success_message = "Appointment scheduled successfully! Please wait for admin approval.";
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
        }
        
        // Close statement
        unset($stmt);
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Fetch user's appointments - MODIFIED to only show the logged-in user's appointments
$appointments = [];
$sql = "SELECT * FROM dental_patients WHERE user_id = :user_id ORDER BY appointment_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
$stmt->execute();
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch confirmed appointments - MODIFIED to only show the logged-in user's appointments
$confirmedAppointments = [];
$sql = "SELECT * FROM confirmed_appointments WHERE user_id = :user_id ORDER BY appointment_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
$stmt->execute();
$confirmedAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Client Dashboard - LA PLAZA DENTISTA</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            display: flex;
            flex-direction: row;
            min-height: 100vh;
            background-color: white;
        }
        .sidebar {
            width: 250px;
            background-color: #0a192f;
            color: white;
            padding: 0;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar h1 {
            font-size: 24px;
            margin: 0;
            color: black;
            background-color: white;
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            margin-bottom: 20px;
        }
        .sidebar .logo {
            width: 50px;
            height: auto;
            margin-right: 10px;
        }
        .sidebar h3 {
            font-size: 18px;
            margin: 0;
            color: white;
            text-align: center;
            background-color: #c72029;
            padding: 10px;
            margin-bottom: 20px;
        }
        .sidebar-nav {
            padding: 0 15px;
        }
        .sidebar-nav button {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            background-color: #081f3a;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-align: left;
        }
        .sidebar-nav button:hover {
            background-color: #c72029;
        }
        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            padding: 20px;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        h2 {
            color: #c72029;
            margin-bottom: 20px;
            text-align: center;
        }
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #081f3a;
            color: white;
            padding: 20px;
            border-radius: 10px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #d8ecff;
        }
        .form-check {
            margin-bottom: 5px;
        }
        .btn-success {
            background-color: #27ae60;
            border: none;
            width: 100%;
            padding: 10px;
            font-size: 16px;
            margin-top: 15px;
        }
        .btn-success:hover {
            background-color: #219653;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #dee2e6;
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #c72029;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .logout-btn {
            display: block;
            width: 100%;
            padding: 10px;
            margin-top: 20px;
            background-color: #dc3545;
            color: white;
            text-align: center;
            border-radius: 5px;
            text-decoration: none;
        }
        .logout-btn:hover {
            background-color: #c82333;
            color: white;
            text-decoration: none;
        }
        .allergies-container, .procedure-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        footer {
            text-align: center;
            padding: 10px;
            background-color: #f8f9fa;
            margin-top: 30px;
        }
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            .allergies-container, .procedure-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <h3>Dental Clinic</h3>
        <h1><img src="logo.png" alt="Logo" class="logo"> LA PLAZA DENTISTA</h1>
        
        <div class="sidebar-nav">
            <button onclick="showTab('appointmentForm')">Schedule Appointment</button>
            <button onclick="showTab('appointmentHistory')">View Appointments</button>
            <button onclick="showTab('confirmedAppointments')">Confirmed Appointments</button>
            
            <a href="index.php?logout=1" class="logout-btn btn btn-danger">Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container">
            <h2>Welcome, <?php echo htmlspecialchars($_SESSION["username"]); ?>!</h2>
            
            <?php 
            if (isset($success_message)) {
                echo '<div class="alert alert-success">' . $success_message . '</div>';
            }
            if (isset($error_message)) {
                echo '<div class="alert alert-danger">' . $error_message . '</div>';
            }
            ?>
            
            <!-- Appointment Form -->
            <div id="appointmentForm" class="tab-content active">
                <h3 class="text-center mb-4">Schedule a New Appointment</h3>
                
                <div class="form-container">
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <div class="form-group">
                            <label for="patientName">Patient Name</label>
                            <input type="text" class="form-control" id="patientName" name="patientName" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="patientPhone">Phone Number</label>
                            <input type="text" class="form-control" id="patientPhone" name="patientPhone" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="patientAddress">Address</label>
                            <textarea class="form-control" id="patientAddress" name="patientAddress" required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Gender</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gender" id="male" value="Male" required>
                                <label class="form-check-label" for="male">Male</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gender" id="female" value="Female" required>
                                <label class="form-check-label" for="female">Female</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gender" id="other" value="Other" required>
                                <label class="form-check-label" for="other">Other</label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Date of Birth</label>
                            <div class="row">
                                <div class="col">
                                    <select class="form-control" id="dobYear" name="dobYear" required>
                                        <option value="">Year</option>
                                        <?php
                                        $currentYear = date("Y");
                                        for ($year = $currentYear; $year >= 1900; $year--) {
                                            echo "<option value=\"$year\">$year</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col">
                                    <select class="form-control" id="dobMonth" name="dobMonth" required>
                                        <option value="">Month</option>
                                        <?php
                                        for ($month = 1; $month <= 12; $month++) {
                                            $monthPadded = str_pad($month, 2, "0", STR_PAD_LEFT);
                                            echo "<option value=\"$monthPadded\">$monthPadded</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col">
                                    <select class="form-control" id="dobDay" name="dobDay" required>
                                        <option value="">Day</option>
                                        <?php
                                        for ($day = 1; $day <= 31; $day++) {
                                            $dayPadded = str_pad($day, 2, "0", STR_PAD_LEFT);
                                            echo "<option value=\"$dayPadded\">$dayPadded</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Dental Procedures</label>
                            <div class="procedure-container">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="procedure[]" id="extraction" value="Tooth Extractions">
                                    <label class="form-check-label" for="extraction">Tooth Extractions</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="procedure[]" id="implants" value="Dental Implants">
                                    <label class="form-check-label" for="implants">Dental Implants</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="procedure[]" id="wisdom" value="Wisdom Teeth Removal">
                                    <label class="form-check-label" for="wisdom">Wisdom Teeth Removal</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="procedure[]" id="cleaning" value="Teeth Cleaning">
                                    <label class="form-check-label" for="cleaning">Teeth Cleaning</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="procedure[]" id="filling" value="Dental Filling">
                                    <label class="form-check-label" for="filling">Dental Filling</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="procedure[]" id="root" value="Root Canal">
                                    <label class="form-check-label" for="root">Root Canal</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Allergies (if any)</label>
                            <div class="allergies-container">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="allergies[]" id="latex" value="Latex">
                                    <label class="form-check-label" for="latex">Latex</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="allergies[]" id="anesthesia" value="Anesthesia">
                                    <label class="form-check-label" for="anesthesia">Anesthesia</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="allergies[]" id="resin" value="Resin">
                                    <label class="form-check-label" for="resin">Resin (Fillings)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="allergies[]" id="fluoride" value="Fluoride">
                                    <label class="form-check-label" for="fluoride">Fluoride</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="allergies[]" id="propolis" value="Propolis">
                                    <label class="form-check-label" for="propolis">Propolis</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="allergies[]" id="acrylic" value="Acrylic">
                                    <label class="form-check-label" for="acrylic">Acrylic/Metal Alloys</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="appointmentDate">Preferred Appointment Date</label>
                            <input type="date" class="form-control" id="appointmentDate" name="appointmentDate" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <button type="submit" name="addAppointment" class="btn btn-success">Schedule Appointment</button>
                    </form>
                </div>
            </div>
            
            <!-- Appointment History -->
            <div id="appointmentHistory" class="tab-content">
                <h3 class="text-center mb-4">Your Appointment History</h3>
                
                <?php if (count($appointments) > 0): ?>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Appointment Date</th>
                                <th>Procedure</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $appointment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
                                    <td><?php echo htmlspecialchars($appointment['procedure']); ?></td>
                                    <td>
                                        <?php 
                                        $status = $appointment['status'];
                                        $statusClass = '';
                                        
                                        if ($status == 'confirmed') {
                                            $statusClass = 'text-success';
                                        } elseif ($status == 'cancelled') {
                                            $statusClass = 'text-danger';
                                        } else {
                                            $statusClass = 'text-warning';
                                        }
                                        
                                        echo '<span class="' . $statusClass . '">' . ucfirst($status) . '</span>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert alert-info">You have no appointments yet.</div>
                <?php endif; ?>
            </div>
            
            <!-- Confirmed Appointments -->
            <div id="confirmedAppointments" class="tab-content">
                <h3 class="text-center mb-4">Your Confirmed Appointments</h3>
                
                <?php if (count($confirmedAppointments) > 0): ?>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Appointment Date</th>
                                <th>Appointment Time</th>
                                <th>Procedure</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($confirmedAppointments as $appointment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
                                    <td><?php echo htmlspecialchars($appointment['appointment_time']); ?></td>
                                    <td><?php echo htmlspecialchars($appointment['procedure']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert alert-info">You have no confirmed appointments yet.</div>
                <?php endif; ?>
            </div>
        </div>
        
        <footer>
            <p>2025 LA PLAZA DENTISTA. ALL RIGHTS RESERVED</p>
         </footer>
    </div>
    
    <script>
        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
        }

        // Update day options based on selected year and month
        document.getElementById('dobYear').addEventListener('change', updateDays);
        document.getElementById('dobMonth').addEventListener('change', updateDays);

        function updateDays() {
            const yearSelect = document.getElementById('dobYear');
            const monthSelect = document.getElementById('dobMonth');
            const daySelect = document.getElementById('dobDay');
            
            const year = yearSelect.value;
            const month = monthSelect.value;
            
            if (year && month) {
                const daysInMonth = new Date(year, month, 0).getDate();
                let currentDay = daySelect.value;
                
                daySelect.innerHTML = '<option value="">Day</option>';
                for (let day = 1; day <= daysInMonth; day++) {
                    const option = document.createElement('option');
                    option.value = day < 10 ? '0' + day : day;
                    option.textContent = day;
                    if (day == currentDay) {
                        option.selected = true;
                    }
                    daySelect.appendChild(option);
                }
            }
        }
    </script>
</body>
</html>