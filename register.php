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

// Small helper: returns " field-error" if this field is flagged, so we can
// drop a red outline on just the field(s) that need fixing.
function invalid_class(string $field, array $invalid_fields): string {
    return in_array($field, $invalid_fields) ? " field-error" : "";
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
</head>
<body>

<div class="field-bg" aria-hidden="true">
    <div class="glow a"></div>
    <div class="glow b"></div>
    <div class="grid"></div>
    <svg class="wave" viewBox="0 0 1440 220" preserveAspectRatio="none" height="180">
        <path fill="#dbeafe" opacity="0.6" d="M0,120 C320,200 480,40 760,90 C1040,140 1200,40 1440,100 L1440,220 L0,220 Z"/>
        <path fill="#bfdbfe" opacity="0.5" d="M0,160 C300,100 520,200 820,150 C1080,110 1260,180 1440,150 L1440,220 L0,220 Z"/>
    </svg>
</div>

<div class="top-controls">
    <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
</div>

<div style="min-height:100vh; display:flex; align-items:center; justify-content:center; padding:40px 20px; position:relative;">
<div class="stage" style="max-width:440px;">

    <div class="brand-lockup stacked" style="margin-bottom:24px;">
        <div class="logo-mark size-lg">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 2.5 L21.5 20.5 H2.5 L12 2.5Z" fill="white"/>
                <path d="M8.7 14.3 L11 16.8 L15.3 10.1" stroke="var(--blue-600)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            </svg>
        </div>
        <div>
            <div class="wordmark size-lg">AttendX</div>
            <div class="tagline">Smart Attendance. Anytime. Anywhere.</div>
        </div>
    </div>

    <div class="card">
        <h1 style="font-size:22px; margin-bottom:4px;">Create your account</h1>
        <p class="sub">Register with your matric number to start checking in to classes.</p>

        <?php if ($error !== ""): ?>
            <div class="alert alert-danger">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                <span style="margin-right:auto;"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success !== ""): ?>
            <div class="alert alert-success">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="m5 13 4 4L19 7"/></svg>
                <span style="margin-right:auto;"><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" autocomplete="off">
            <div class="field-group">
                <label for="full_name">Full Name</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                    <input type="text" name="full_name" id="full_name"
                           class="<?= trim(invalid_class('full_name', $invalid_fields)) ?>"
                           required autocomplete="off" value="<?= htmlspecialchars($full_name) ?>">
                </div>
            </div>

            <div class="field-group">
                <label for="matric_number">Matric Number</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h6M7 13h10"/></svg>
                    <input type="text" name="matric_number" id="matric_number"
                           class="<?= trim(invalid_class('matric_number', $invalid_fields)) ?>"
                           required autocomplete="off" value="<?= htmlspecialchars($matric_no) ?>">
                </div>
            </div>

            <div class="field-group">
                <label for="email">Email</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>
                    <input type="email" name="email" id="email"
                           class="<?= trim(invalid_class('email', $invalid_fields)) ?>"
                           required autocomplete="off" placeholder="e.g. you@gmail.com" value="<?= htmlspecialchars($email) ?>">
                </div>
                <span class="caption" style="display:block; margin-top:6px;">Gmail, Yahoo, Outlook, iCloud, or similar common providers only.</span>
            </div>

            <div class="field-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <input type="password" name="password" id="password"
                           class="<?= trim(invalid_class('password', $invalid_fields)) ?>"
                           required autocomplete="off">
                    <button type="button" class="eye-btn" data-target="password" aria-label="Show password">
                        <svg class="icon-open" viewBox="0 0 24 24" fill="currentColor"><path d="M12 5c-5.5 0-9.5 4.5-10.7 6.6a1 1 0 0 0 0 .8C2.5 14.5 6.5 19 12 19s9.5-4.5 10.7-6.6a1 1 0 0 0 0-.8C21.5 9.5 17.5 5 12 5Zm0 12c-4.2 0-7.6-3.3-8.9-5C4.4 10.3 7.8 7 12 7s7.6 3.3 8.9 5c-1.3 1.7-4.7 5-8.9 5Zm0-8.3A3.3 3.3 0 1 0 12 15a3.3 3.3 0 0 0 0-6.6Z"/></svg>
                        <svg class="icon-hidden" viewBox="0 0 24 24" fill="currentColor"><path d="m2.1 3.5 1.4-1.4 18 18-1.4 1.4-3.2-3.2A11.6 11.6 0 0 1 12 19c-5.5 0-9.5-4.5-10.7-6.6a1 1 0 0 1 0-.8 19.9 19.9 0 0 1 4.2-4.9L2.1 3.5Zm6.6 6.6 6.2 6.2a5.3 5.3 0 0 1-6.2-6.2ZM12 5c5.5 0 9.5 4.5 10.7 6.6a1 1 0 0 1 0 .8 19.6 19.6 0 0 1-3 3.7l-1.4-1.4a17.7 17.7 0 0 0 2.5-3.1c-1.3-1.7-4.7-5-8.8-5-1 0-1.9.16-2.8.46L7.7 5.6A11.5 11.5 0 0 1 12 5Zm-2.7 4.1 1.5 1.5a1.6 1.6 0 0 0 1.6 1.6l1.5 1.5A3.3 3.3 0 0 1 9.3 9.1Z"/></svg>
                    </button>
                </div>
            </div>

            <div class="field-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <input type="password" name="confirm_password" id="confirm_password"
                           class="<?= trim(invalid_class('confirm_password', $invalid_fields)) ?>"
                           required autocomplete="off">
                    <button type="button" class="eye-btn" data-target="confirm_password" aria-label="Show password">
                        <svg class="icon-open" viewBox="0 0 24 24" fill="currentColor"><path d="M12 5c-5.5 0-9.5 4.5-10.7 6.6a1 1 0 0 0 0 .8C2.5 14.5 6.5 19 12 19s9.5-4.5 10.7-6.6a1 1 0 0 0 0-.8C21.5 9.5 17.5 5 12 5Zm0 12c-4.2 0-7.6-3.3-8.9-5C4.4 10.3 7.8 7 12 7s7.6 3.3 8.9 5c-1.3 1.7-4.7 5-8.9 5Zm0-8.3A3.3 3.3 0 1 0 12 15a3.3 3.3 0 0 0 0-6.6Z"/></svg>
                        <svg class="icon-hidden" viewBox="0 0 24 24" fill="currentColor"><path d="m2.1 3.5 1.4-1.4 18 18-1.4 1.4-3.2-3.2A11.6 11.6 0 0 1 12 19c-5.5 0-9.5-4.5-10.7-6.6a1 1 0 0 1 0-.8 19.9 19.9 0 0 1 4.2-4.9L2.1 3.5Zm6.6 6.6 6.2 6.2a5.3 5.3 0 0 1-6.2-6.2ZM12 5c5.5 0 9.5 4.5 10.7 6.6a1 1 0 0 1 0 .8 19.6 19.6 0 0 1-3 3.7l-1.4-1.4a17.7 17.7 0 0 0 2.5-3.1c-1.3-1.7-4.7-5-8.8-5-1 0-1.9.16-2.8.46L7.7 5.6A11.5 11.5 0 0 1 12 5Zm-2.7 4.1 1.5 1.5a1.6 1.6 0 0 0 1.6 1.6l1.5 1.5A3.3 3.3 0 0 1 9.3 9.1Z"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-full">Register</button>
        </form>

        <div class="foot" style="margin-top:18px;">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>

</div>
</div>

</body>
</html>