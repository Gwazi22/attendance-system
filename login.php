<?php
require_once "config.php";

$identifier = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $identifier = trim($_POST["identifier"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($identifier === "" || $password === "") {
        $error = "Please enter both your email/matric number and password.";
    } else {
        // Lecturers (and admins) sign in with email; students may use either
        // their matric number or email, so the login form accepts both and
        // this query matches on whichever one was typed.
        $stmt = $conn->prepare(
            "SELECT user_id, full_name, password_hash, role
             FROM users
             WHERE matric_number = ? OR email = ?"
        );
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user["password_hash"])) {
                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["role"] = $user["role"];

                if ($user["role"] === "lecturer") {
                    header("Location: lecturer_dashboard.php");
                    exit;
                } elseif ($user["role"] === "admin") {
                    header("Location: admin_dashboard.php");
                    exit;
                } else {
                    header("Location: student_dashboard.php");
                    exit;
                }
            } else {
                $error = "Invalid email/matric number or password.";
            }
        } else {
            $error = "Invalid email/matric number or password.";
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
<title>Sign In · AttendX</title>
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
<div class="stage">

    <div class="brand-lockup stacked" style="margin-bottom:28px;">
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
        <?php if ($error !== ""): ?>
            <div class="alert alert-danger">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                <span style="margin-right:auto;"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="field-group">
                <label for="identifier">Email or Matric Number</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>
                    <input type="text" id="identifier" name="identifier" value="<?= htmlspecialchars($identifier) ?>" placeholder="you@example.com" required autofocus>
                </div>
            </div>

            <div class="field-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <input type="password" name="password" id="password" placeholder="Enter your password" required>
                    <button type="button" class="eye-btn" data-target="password" aria-label="Show password">
                        <svg class="icon-open" viewBox="0 0 24 24" fill="currentColor"><path d="M12 5c-5.5 0-9.5 4.5-10.7 6.6a1 1 0 0 0 0 .8C2.5 14.5 6.5 19 12 19s9.5-4.5 10.7-6.6a1 1 0 0 0 0-.8C21.5 9.5 17.5 5 12 5Zm0 12c-4.2 0-7.6-3.3-8.9-5C4.4 10.3 7.8 7 12 7s7.6 3.3 8.9 5c-1.3 1.7-4.7 5-8.9 5Zm0-8.3A3.3 3.3 0 1 0 12 15a3.3 3.3 0 0 0 0-6.6Z"/></svg>
                        <svg class="icon-hidden" viewBox="0 0 24 24" fill="currentColor"><path d="m2.1 3.5 1.4-1.4 18 18-1.4 1.4-3.2-3.2A11.6 11.6 0 0 1 12 19c-5.5 0-9.5-4.5-10.7-6.6a1 1 0 0 1 0-.8 19.9 19.9 0 0 1 4.2-4.9L2.1 3.5Zm6.6 6.6 6.2 6.2a5.3 5.3 0 0 1-6.2-6.2ZM12 5c5.5 0 9.5 4.5 10.7 6.6a1 1 0 0 1 0 .8 19.6 19.6 0 0 1-3 3.7l-1.4-1.4a17.7 17.7 0 0 0 2.5-3.1c-1.3-1.7-4.7-5-8.8-5-1 0-1.9.16-2.8.46L7.7 5.6A11.5 11.5 0 0 1 12 5Zm-2.7 4.1 1.5 1.5a1.6 1.6 0 0 0 1.6 1.6l1.5 1.5A3.3 3.3 0 0 1 9.3 9.1Z"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-full">Login</button>
        </form>

        <div class="foot" style="margin-top:14px;">
            <button type="button" class="btn-link" id="forgotPasswordBtn" style="background:none; border:none; cursor:pointer;">Forgot password?</button>
        </div>
        <div id="forgotPasswordNotice" class="alert alert-info" style="display:none; margin-top:10px;">
            <span style="margin-right:auto;">There's no self-service reset — contact your system administrator and they can reset it for you.</span>
        </div>

        <div class="divider">OR</div>

        <div class="foot">
            Don't have an account? <a href="register.php">Register</a>
        </div>
    </div>

</div>
</div>

<script>
document.getElementById("forgotPasswordBtn").addEventListener("click", function () {
    const notice = document.getElementById("forgotPasswordNotice");
    notice.style.display = notice.style.display === "none" ? "flex" : "none";
});
</script>

</body>
</html>