<?php
require_once "config.php";

// Self-registration is for STUDENTS only. Lecturer and admin accounts are
// created by an administrator (admin_users.php), so the role is fixed here
// and any role value sent from the browser is ignored.
$role = "student";

$full_name = "";
$matric_number = "";
$email = "";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST["full_name"] ?? "");
    $matric_number = trim($_POST["matric_number"] ?? "");
    $email = trim($_POST["email"] ?? "");

    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($full_name === "") {
        $error = "Please enter your full name.";
    } elseif ($matric_number === "") {
        $error = "Please enter your matric number.";
    } elseif ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password === "") {
        $error = "Please enter a password.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must contain at least 6 characters.";
    } else {

        // Email must be unique
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $emailTaken = $result->num_rows > 0;
        $stmt->close();

        if ($emailTaken) {
            $error = "An account with this email already exists.";
        } else {

            $matricTaken = false;

            // Students: matric number must be unique
            if ($role === "student") {
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE matric_number = ? LIMIT 1");
                $stmt->bind_param("s", $matric_number);
                $stmt->execute();
                $result = $stmt->get_result();
                $matricTaken = $result->num_rows > 0;
                $stmt->close();
            } else {
                // Lecturers have no matric number
                $matric_number = null;
            }

            if ($matricTaken) {
                $error = "This matric number is already registered.";
            } else {

                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "INSERT INTO users
                     (full_name, matric_number, email, password_hash, role)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sssss", $full_name, $matric_number, $email, $password_hash, $role);

                if ($stmt->execute()) {
                    $success = "Registration successful. You can now log in.";
                    $full_name = "";
                    $matric_number = "";
                    $email = "";
                } else {
                    $error = "Registration failed. Please try again.";
                }

                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register · AttendX</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/theme.css">
<script src="assets/js/theme.js"></script>

<style>
/* Page-only layout; everything else comes from theme.css */
.auth-wrap { position: relative; z-index: 1; min-height: 100vh; display: flex; align-items: center; padding: 30px 16px; }
.wordmark-x { color: var(--blue-600); }

.role-tabs { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; background: var(--surface-alt); padding: 4px; border-radius: 12px; margin-bottom: 20px; }
.role-tabs input { position: absolute; opacity: 0; pointer-events: none; }
.role-tabs label { margin: 0; text-align: center; padding: 9px 4px; font-size: 13px; font-weight: 600; color: var(--ink-600); border-radius: 9px; cursor: pointer; transition: .15s ease; }
.role-tabs input:checked + label { background: var(--surface); color: var(--blue-600); box-shadow: 0 1px 4px rgba(15,23,42,.12); }
.role-tabs input:focus-visible + label { outline: 2px solid var(--blue-500); outline-offset: 2px; }
</style>
</head>

<body>

<div class="field-bg">
    <div class="glow a"></div>
    <div class="glow b"></div>
    <svg class="wave" viewBox="0 0 1440 200" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 110 C240 40 420 180 720 110 S1200 40 1440 110 V200 H0Z" fill="#2563eb" opacity=".10"/>
        <path d="M0 150 C260 90 460 200 760 148 S1220 90 1440 150 V200 H0Z" fill="#2563eb" opacity=".14"/>
    </svg>
</div>

<div class="top-controls">
    <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
</div>

<div class="auth-wrap">
<div class="stage">

    <div class="card">

        <!-- BRAND -->
        <div class="brand-lockup stacked" style="margin-bottom:20px;">
            <div class="logo-mark size-lg">
                <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                    <path d="M24 5 41 43H31L24 27 17 43H7L24 5Z" fill="#fff"/>
                    <path d="M18 31l4.5 4.5L31 25" stroke="#2563eb" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div>
                <div class="wordmark size-lg">Attend<span class="wordmark-x">X</span></div>
                <div class="tagline">Smart Attendance. Anytime. Anywhere.</div>
            </div>
        </div>

        <h2 style="text-align:center;">Create a student account</h2>
        <p class="sub" style="text-align:center;">Enter your details to get started</p>

        <?php if ($error !== ""): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ""): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="registerForm">

            <input type="hidden" name="role" value="student">

            <!-- FULL NAME -->
            <div class="field-group">
                <label for="full_name">Full Name</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="8" r="3.5"/>
                        <path d="M5 20c.8-3.4 3.3-5.2 7-5.2s6.2 1.8 7 5.2"/>
                    </svg>
                    <input type="text" id="full_name" name="full_name"
                           value="<?= htmlspecialchars($full_name) ?>"
                           placeholder="Full Name" required>
                </div>
            </div>

            <!-- MATRIC NUMBER (students only) -->
            <div class="field-group" id="matricField">
                <label for="matric_number">Matric Number</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path d="M7 9h10"/><path d="M7 13h6"/><path d="M7 16h3"/>
                    </svg>
                    <input type="text" id="matric_number" name="matric_number"
                           value="<?= htmlspecialchars($matric_number ?? "") ?>"
                           placeholder="Matric Number">
                </div>
            </div>

            <!-- EMAIL -->
            <div class="field-group">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path d="m4 7 8 6 8-6"/>
                    </svg>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($email) ?>"
                           placeholder="Email Address" autocomplete="email" required>
                </div>
            </div>

            <!-- PASSWORD -->
            <div class="field-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="5" y="11" width="14" height="9" rx="2"/>
                        <path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                    </svg>
                    <input type="password" id="password" name="password"
                           placeholder="At least 6 characters" autocomplete="new-password" required>
                    <button type="button" class="eye-btn" data-target="password" aria-label="Show password">
                        <svg class="icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                            <circle cx="12" cy="12" r="2.5"/>
                        </svg>
                        <svg class="icon-hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M3 3l18 18"/>
                            <path d="M10.6 6.1A9.8 9.8 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-3.2 3.8M6.7 7.7C4 9.4 2.5 12 2.5 12s3.5 6 9.5 6c1.4 0 2.7-.3 3.8-.8"/>
                            <path d="M9.9 9.9a2.5 2.5 0 0 0 3.5 3.5"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- CONFIRM PASSWORD -->
            <div class="field-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="5" y="11" width="14" height="9" rx="2"/>
                        <path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                    </svg>
                    <input type="password" id="confirm_password" name="confirm_password"
                           placeholder="Re-enter password" autocomplete="new-password" required>
                    <button type="button" class="eye-btn" data-target="confirm_password" aria-label="Show password">
                        <svg class="icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                            <circle cx="12" cy="12" r="2.5"/>
                        </svg>
                        <svg class="icon-hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M3 3l18 18"/>
                            <path d="M10.6 6.1A9.8 9.8 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-3.2 3.8M6.7 7.7C4 9.4 2.5 12 2.5 12s3.5 6 9.5 6c1.4 0 2.7-.3 3.8-.8"/>
                            <path d="M9.9 9.9a2.5 2.5 0 0 0 3.5 3.5"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-full">Create Account</button>
        </form>

        <div class="divider">OR</div>

        <div class="foot">
            Already have an account? <a href="login.php">Login</a>
        </div>

    </div>
</div>
</div>

<script>
const roleInputs = document.querySelectorAll('input[name="role"]');
const matricField = document.getElementById("matricField");
const matricInput = document.getElementById("matric_number");

function updateRegistrationForm(role) {
    if (role === "student") {
        matricField.style.display = "block";
        matricInput.required = true;
    } else {
        matricField.style.display = "none";
        matricInput.required = false;
        matricInput.value = "";
    }
}

roleInputs.forEach(function (input) {
    input.addEventListener("change", function () { updateRegistrationForm(this.value); });
});

const checked = document.querySelector('input[name="role"]:checked');
updateRegistrationForm(checked ? checked.value : "student");

</script>
</body>
</html>