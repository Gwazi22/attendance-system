<?php
require_once "config.php";

$error = "";
$success = "";

// Repopulation values — used to refill the form after a failed submission
// so the student doesn't have to retype everything, only fix what's wrong.
$full_name = "";
$matric_no = "";
$email     = "";

// Tracks which specific field(s) caused the current error, so we can
// highlight just those instead of leaving the student guessing.
$invalid_fields = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name  = trim($_POST["full_name"] ?? "");
    $email      = trim($_POST["email"] ?? "");
    $password   = $_POST["password"] ?? "";
    $confirm    = $_POST["confirm_password"] ?? "";
    $matric_no  = trim($_POST["matric_number"] ?? "");

    $allowed_domains = ["gmail.com", "yahoo.com", "outlook.com", "hotmail.com", "icloud.com", "live.com"];
    $email_domain = strtolower(substr(strrchr($email, "@"), 1));

    if (empty($full_name) || empty($email) || empty($password) || empty($matric_no)) {
        $error = "All fields are required.";
        if (empty($full_name)) $invalid_fields[] = "full_name";
        if (empty($email))     $invalid_fields[] = "email";
        if (empty($password))  $invalid_fields[] = "password";
        if (empty($matric_no)) $invalid_fields[] = "matric_number";
    } elseif (!in_array($email_domain, $allowed_domains)) {
        $error = "Please register with a Gmail, Yahoo, Outlook, iCloud, or similar common email provider.";
        $invalid_fields[] = "email";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
        $invalid_fields[] = "password";
        $invalid_fields[] = "confirm_password";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
        $invalid_fields[] = "password";
        $invalid_fields[] = "confirm_password";
    } else {
        // Check if email or matric number already exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR matric_number = ?");
        $stmt->bind_param("ss", $email, $matric_no);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Email or matric number already registered.";
            $invalid_fields[] = "email";
            $invalid_fields[] = "matric_number";
        } else {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            $insert = $conn->prepare(
                "INSERT INTO users (full_name, email, password_hash, role, matric_number) VALUES (?, ?, ?, 'student', ?)"
            );
            $insert->bind_param("ssss", $full_name, $email, $password_hash, $matric_no);

            if ($insert->execute()) {
                $success = "Registration successful. You can now log in.";
                // Clear repopulation values on success so the form starts fresh
                $full_name = "";
                $matric_no = "";
                $email = "";
            } else {
                $error = "Something went wrong. Please try again.";
            }
            $insert->close();
        }
        $stmt->close();
    }
}

// Small helper: returns " is-invalid" if this field is flagged, so we can
// drop a red Bootstrap outline on just the field(s) that need fixing.
function invalid_class(string $field, array $invalid_fields): string {
    return in_array($field, $invalid_fields) ? " is-invalid" : "";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Registration</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .toggle-eye { cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .toggle-eye svg { width: 20px; height: 20px; }
    </style>
</head>
<body>
<div class="container" style="max-width: 480px; margin-top: 60px;">
    <div class="d-flex justify-content-end mb-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="toggleTheme()" title="Toggle dark mode">
            <span id="themeToggleIcon">🌙</span>
        </button>
    </div>
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h4 class="mb-3 text-center">Student Registration</h4>

            <form method="POST" action="register.php" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control<?= invalid_class('full_name', $invalid_fields) ?>"
                           required autocomplete="off" value="<?= htmlspecialchars($full_name) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Matric Number</label>
                    <input type="text" name="matric_number" class="form-control<?= invalid_class('matric_number', $invalid_fields) ?>"
                           required autocomplete="off" value="<?= htmlspecialchars($matric_no) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control<?= invalid_class('email', $invalid_fields) ?>"
                           required autocomplete="off" placeholder="e.g. you@gmail.com" value="<?= htmlspecialchars($email) ?>">
                    <small class="text-muted">Gmail, Yahoo, Outlook, iCloud, or similar common providers only.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="password" class="form-control<?= invalid_class('password', $invalid_fields) ?>" required autocomplete="off">
                        <span class="input-group-text toggle-eye" onclick="togglePassword('password', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.13 13.13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.133 13.133 0 0 1 1.172 8z"/>
                                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                            </svg>
                        </span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control<?= invalid_class('confirm_password', $invalid_fields) ?>" required autocomplete="off">
                        <span class="input-group-text toggle-eye" onclick="togglePassword('confirm_password', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.13 13.13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.133 13.133 0 0 1 1.172 8z"/>
                                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                            </svg>
                        </span>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Register</button>
            </form>
            <p class="text-center mt-3 mb-0">
                Already have an account? <a href="login.php">Login here</a>
            </p>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/ui-polish.js"></script>
<script>
const eyeIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.13 13.13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.133 13.133 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>`;
const eyeSlashIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"><path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.822.822a2.5 2.5 0 0 0 2.83 2.83z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709z"/><path d="m2.646 2.646.708-.708 10 10-.708.708-10-10z"/></svg>`;
function togglePassword(fieldId, iconSpan) {
    const field = document.getElementById(fieldId);
    if (field.type === "password") { field.type = "text"; iconSpan.innerHTML = eyeSlashIcon; }
    else { field.type = "password"; iconSpan.innerHTML = eyeIcon; }
}

<?php if ($error): ?>
document.addEventListener("DOMContentLoaded", function () {
    showToast(<?= json_encode($error) ?>, "danger");
});
<?php endif; ?>
<?php if ($success): ?>
document.addEventListener("DOMContentLoaded", function () {
    showToast(<?= json_encode($success) ?>, "success");
});
<?php endif; ?>
</script>
</body>
</html>