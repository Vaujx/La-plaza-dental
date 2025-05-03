<?php
// Include config file
require_once "config.php";
 
// Define variables and initialize with empty values
$username = $password = $confirm_password = "";
$username_err = $password_err = $confirm_password_err = $terms_err = "";
 
// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
 
    // Validate terms agreement
    if(!isset($_POST["terms_agreement"])){
        $terms_err = "You must agree to the Terms and Conditions.";
    }

    // Validate username
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter a username.";
    } elseif(!preg_match('/^[a-zA-Z0-9_]+$/', trim($_POST["username"]))){
        $username_err = "Username can only contain letters, numbers, and underscores.";
    } else{
        // Prepare a select statement
        $sql = "SELECT id FROM userss WHERE username = :username";
        
        if($stmt = $pdo->prepare($sql)){
            // Bind variables to the prepared statement as parameters
            $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
            
            // Set parameters
            $param_username = trim($_POST["username"]);
            
            // Attempt to execute the prepared statement
            if($stmt->execute()){
                if($stmt->rowCount() == 1){
                    $username_err = "This username is already taken.";
                } else{
                    $username = trim($_POST["username"]);
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            unset($stmt);
        }
    }
    
    // Validate password
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter a password.";     
    } elseif(strlen(trim($_POST["password"])) < 6){
        $password_err = "Password must have at least 6 characters.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate confirm password
    if(empty(trim($_POST["confirm_password"]))){
        $confirm_password_err = "Please confirm password.";     
    } else{
        $confirm_password = trim($_POST["confirm_password"]);
        if(empty($password_err) && ($password != $confirm_password)){
            $confirm_password_err = "Password did not match.";
        }
    }
    
    // Check input errors before inserting in database
    if(empty($username_err) && empty($password_err) && empty($confirm_password_err) && empty($terms_err)){
        
        // Prepare an insert statement
        $sql = "INSERT INTO userss (username, password) VALUES (:username, :password)";
         
        if($stmt = $pdo->prepare($sql)){
            // Bind variables to the prepared statement as parameters
            $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
            $stmt->bindParam(":password", $param_password, PDO::PARAM_STR);
            
            // Set parameters
            $param_username = $username;
            $param_password = password_hash($password, PASSWORD_DEFAULT); // Creates a password hash
            
            // Attempt to execute the prepared statement
            if($stmt->execute()){
                // Redirect to login page
                header("location: index.php");
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            unset($stmt);
        }
    }
    
    // Close connection
    unset($pdo);
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sign Up - LA PLAZA DENTISTA</title>
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
        .btn-primary {
            width: 100%;
            padding: 10px;
        }
        .text-center a {
            color: #007bff;
        }
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 5px;
            width: 80%;
            max-width: 600px;
            max-height: 70vh;
            overflow-y: auto;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
        }
        .terms-link {
            color: #007bff;
            cursor: pointer;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <h1>LA PLAZA DENTISTA</h1>
        <h2 class="text-center">Sign Up</h2>
        <p class="text-center">Please fill this form to create an account.</p>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $username; ?>">
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>    
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $password; ?>">
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $confirm_password; ?>">
                <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
            </div>
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="terms_agreement" class="form-check-input <?php echo (!empty($terms_err)) ? 'is-invalid' : ''; ?>" id="terms_agreement">
                    <label class="form-check-label" for="terms_agreement">
                        I agree to the <span class="terms-link" onclick="openTermsModal()">Terms and Conditions</span>
                    </label>
                    <?php if(!empty($terms_err)): ?>
                        <div class="invalid-feedback"><?php echo $terms_err; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-group">
                <input type="submit" class="btn btn-primary" value="Submit">
            </div>
            <p class="text-center">Already have an account? <a href="index.php">Login here</a>.</p>
        </form>
    </div>    

    <!-- Terms and Conditions Modal -->
    <div id="termsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeTermsModal()">&times;</span>
            <h2>Terms and Conditions</h2>
            <div class="terms-content">
                <h4>1. Acceptance of Terms</h4>
                <p>By accessing and using the LA PLAZA DENTISTA services, you agree to be bound by these Terms and Conditions, all applicable laws and regulations, and agree that you are responsible for compliance with any applicable local laws.</p>
                
                <h4>2. Dental Appointment Services</h4>
                <p>LA PLAZA DENTISTA provides an online platform for scheduling dental appointments. We do not guarantee the availability of specific appointment times or dental professionals.</p>
                
                <h4>3. User Account</h4>
                <p>To use our services, you must create an account with accurate, complete, and updated information. You are responsible for maintaining the confidentiality of your account and password.</p>
                
                <h4>4. Personal Information</h4>
                <p>By using our services, you consent to the collection and use of your personal information as described in our Privacy Policy. You agree that we may use your contact information to send you notifications related to our services.</p>
                
                <h4>5. Appointment Cancellation</h4>
                <p>You may cancel or reschedule appointments according to our cancellation policy. Repeated no-shows or late cancellations may result in restrictions on future bookings.</p>
                
                <h4>6. Medical Information</h4>
                <p>Any medical information provided is subject to our Privacy Policy and will be used only for the purpose of providing appropriate dental care.</p>
                
                <h4>7. Limitation of Liability</h4>
                <p>LA PLAZA DENTISTA shall not be liable for any direct, indirect, incidental, special, or consequential damages resulting from the use or inability to use our services.</p>
                
                <h4>8. Changes to Terms</h4>
                <p>LA PLAZA DENTISTA reserves the right to modify these terms at any time. We will provide notice of significant changes by updating the date at the top of these terms and by maintaining a current version on our website.</p>
                
                <h4>9. Governing Law</h4>
                <p>These Terms shall be governed by and construed in accordance with the laws of the jurisdiction in which LA PLAZA DENTISTA operates, without regard to its conflict of law provisions.</p>
            </div>
            <div class="text-center mt-4">
                <button class="btn btn-primary" onclick="agreeToTerms()">I Agree</button>
                <button class="btn btn-secondary ml-2" onclick="closeTermsModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Get the modal
        var modal = document.getElementById("termsModal");
        
        // Function to open the modal
        function openTermsModal() {
            modal.style.display = "block";
        }
        
        // Function to close the modal
        function closeTermsModal() {
            modal.style.display = "none";
        }
        
        // Function to agree to terms and close modal
        function agreeToTerms() {
            document.getElementById("terms_agreement").checked = true;
            closeTermsModal();
        }
        
        // Close the modal if the user clicks outside of it
        window.onclick = function(event) {
            if (event.target == modal) {
                closeTermsModal();
            }
        }
    </script>
</body>
</html>
