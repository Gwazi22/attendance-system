<?php
require_once "config.php";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name  = trim($_POST["full_name"]);
    $email      = trim($_POST["email"]);
    $password   = $_POST["password"];
    $confirm    = $_POST["confirm_password"];
    $matric_no  = trim($_POST["matric_number"]);

    if (empty($full_name) || empty($email) || empty($password) || empty($matric_no)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Check if email or matric number already exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR matric_number = ?");
        $stmt->bind_param("ss", $email, $matric_no);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Email or matric number already registered.";
        } else {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            $insert = $conn->prepare(
                "INSERT INTO users (full_name, email, password_hash, role, matric_number) VALUES (?, ?, ?, 'student', ?)"
            );
            $insert->bind_param("ssss", $full_name, $email, $password_hash, $matric_no);

            if ($insert->execute()) {
                $success = "Registration successful. You can now log in.";
            } else {
                $error = "Something went wrong. Please try again.";
            }
            $insert->close();
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Registration</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width: 480px; margin-top: 60px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h4 class="mb-3 text-center">Student Registration</h4>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Matric Number</label>
                    <input type="text" name="matric_number" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Register</button>
            </form>
            <p class="text-center mt-3 mb-0">
                Already have an account? <a href="login.php">Login here</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>