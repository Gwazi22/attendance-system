<?php
require_once "config.php";

$role = $_POST["role"] ?? "student";
$identifier = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $role = $_POST["role"] ?? "";
    $identifier = trim($_POST["identifier"] ?? "");
    $password = $_POST["password"] ?? "";

    $allowed_roles = ["student", "lecturer", "admin"];

    if (!in_array($role, $allowed_roles, true)) {
        $error = "Please select who is signing in.";
        $role = "student";
    } elseif ($identifier === "" || $password === "") {
        $error = "Please complete all required fields.";
    } else {

        // Students: matric number only. Lecturers / administrators: email only.
        if ($role === "student") {
            $stmt = $conn->prepare(
                "SELECT user_id, full_name, password_hash, role
                 FROM users
                 WHERE matric_number = ? AND role = 'student'
                 LIMIT 1"
            );
            $stmt->bind_param("s", $identifier);
        } else {
            $stmt = $conn->prepare(
                "SELECT user_id, full_name, password_hash, role
                 FROM users
                 WHERE email = ? AND role = ?
                 LIMIT 1"
            );
            $stmt->bind_param("ss", $identifier, $role);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user["password_hash"])) {

                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["role"] = $user["role"];

                if ($user["role"] === "student") {
                    header("Location: student_dashboard.php");
                    exit;
                } elseif ($user["role"] === "lecturer") {
                    header("Location: lecturer_dashboard.php");
                    exit;
                } elseif ($user["role"] === "admin") {
                    header("Location: admin_dashboard.php");
                    exit;
                }
            } else {
                $error = "Invalid login details.";
            }
        } else {
            $error = "Invalid login details.";
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · AttendX</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/theme.css">
<script src="assets/js/theme.js"></script>

<style>
/* Page-only layout; everything else comes from theme.css */
.auth-wrap { position: relative; z-index: 1; min-height: 100vh; display: flex; align-items: center; padding: 30px 16px; }
.wordmark-x { color: var(--blue-600); }

.role-tabs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; background: var(--surface-alt); padding: 4px; border-radius: 12px; margin-bottom: 20px; }
.role-tabs input { position: absolute; opacity: 0; pointer-events: none; }
.role-tabs label { margin: 0; text-align: center; padding: 9px 4px; font-size: 13px; font-weight: 600; color: var(--ink-600); border-radius: 9px; cursor: pointer; transition: .15s ease; }
.role-tabs input:checked + label { background: var(--surface); color: var(--blue-600); box-shadow: 0 1px 4px rgba(15,23,42,.12); }
.role-tabs input:focus-visible + label { outline: 2px solid var(--blue-500); outline-offset: 2px; }
</style>
</head>

<body>

<!-- Ambient background (light theme: soft glows + wave, per brand board) -->
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
        <div class="brand-lockup stacked" style="margin-bottom:22px;">
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

        <?php if ($error !== ""): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm">

            <!-- ROLE -->
            <div class="role-tabs" role="radiogroup" aria-label="Sign in as">
                <input type="radio" name="role" id="r_student" value="student" <?= $role === "student" ? "checked" : "" ?>>
                <label for="r_student">Student</label>

                <input type="radio" name="role" id="r_lecturer" value="lecturer" <?= $role === "lecturer" ? "checked" : "" ?>>
                <label for="r_lecturer">Lecturer</label>

                <input type="radio" name="role" id="r_admin" value="admin" <?= $role === "admin" ? "checked" : "" ?>>
                <label for="r_admin">Admin</label>
            </div>

            <!-- IDENTIFIER -->
            <div class="field-group">
                <label for="identifier" id="identifierLabel">Matric Number</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path d="M7 9h10"/><path d="M7 13h6"/><path d="M7 16h3"/>
                    </svg>
                    <input type="text" id="identifier" name="identifier"
                           value="<?= htmlspecialchars($identifier) ?>"
                           placeholder="Matric Number" autocomplete="username" required>
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
                           placeholder="Password" autocomplete="current-password" required>
                    <button type="button" class="eye-btn" id="eyeBtn" aria-label="Show password">
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

            <button type="submit" class="btn btn-primary w-full">Login</button>
        </form>

        <div style="text-align:center; margin-top:16px;">
            <a href="#" class="btn-link">Forgot password?</a>
        </div>

        <div class="divider">OR</div>

        <div class="foot">
            Don't have an account? <a href="register.php">Register</a>
        </div>

    </div>
</div>
</div>

<script>
const roleInputs = document.querySelectorAll('input[name="role"]');
const identifier = document.getElementById("identifier");
const identifierLabel = document.getElementById("identifierLabel");
const password = document.getElementById("password");
const eyeBtn = document.getElementById("eyeBtn");

function updateLoginForm(role) {
    if (role === "student") {
        identifierLabel.textContent = "Matric Number";
        identifier.type = "text";
        identifier.placeholder = "Matric Number";
    } else {
        identifierLabel.textContent = "Email Address";
        identifier.type = "email";
        identifier.placeholder = "Email Address";
    }
}

roleInputs.forEach(function (input) {
    input.addEventListener("change", function () { updateLoginForm(this.value); });
});

const checked = document.querySelector('input[name="role"]:checked');
updateLoginForm(checked ? checked.value : "student");

eyeBtn.addEventListener("click", function () {
    const show = password.type === "password";
    password.type = show ? "text" : "password";
    eyeBtn.classList.toggle("is-visible", show);
    eyeBtn.setAttribute("aria-label", show ? "Hide password" : "Show password");
});
</script>
</body>
</html>