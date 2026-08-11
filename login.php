<?php
require_once "config.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = trim($_POST["email"]);
    $password = $_POST["password"];

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $conn->prepare("SELECT user_id, full_name, password_hash, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user["password_hash"])) {
                // Set session variables
                $_SESSION["user_id"]   = $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["role"]      = $user["role"];

                // Redirect based on role
                if ($user["role"] === "lecturer") {
                    header("Location: lecturer_dashboard.php");
                } else {
                    header("Location: student_dashboard.php");
                }
                exit;
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Invalid email or password.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width: 420px; margin-top: 80px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h4 class="mb-3 text-center">Login</h4>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
            <p class="text-center mt-3 mb-0">
                Not registered? <a href="register.php">Create a student account</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>