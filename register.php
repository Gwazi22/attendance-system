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
<title>Register · MAU Smart Attendance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
    :root {
        --navy-950: #060e1a;
        --navy-900: #0b1a2c;
        --navy-800: #122540;
        --blue-600: #1e5799;
        --blue-400: #4f8fd6;
        --amber-500: #d9722c;
        --amber-400: #eb9856;
        --cream-300: #e9c98a;
        --ink-050: #f4f7fb;
        --ink-300: #b7c4d6;
        --ink-500: #7f8fa6;
        --glass-fill: rgba(20, 36, 58, 0.46);
        --glass-border: rgba(255, 255, 255, 0.12);
        --danger: #e5694f;
        --success: #4fbf8b;
        --radius: 20px;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        min-height: 100vh;
        font-family: 'Inter', system-ui, sans-serif;
        color: var(--ink-050);
        background: var(--navy-950);
    }

    body {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 24px;
        position: relative;
        overflow-x: hidden;
    }

    .field-bg {
        position: fixed;
        inset: 0;
        z-index: 0;
        overflow: hidden;
        background:
            radial-gradient(circle at 18% 20%, rgba(30,87,153,0.35), transparent 42%),
            radial-gradient(circle at 82% 78%, rgba(217,114,44,0.28), transparent 45%),
            linear-gradient(160deg, var(--navy-950) 0%, var(--navy-900) 55%, #0d1f34 100%);
    }
    .field-bg::before {
        content: "";
        position: absolute;
        inset: -1px;
        background-image:
            linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
        background-size: 42px 42px;
        mask-image: radial-gradient(ellipse at center, black 0%, transparent 72%);
    }
    .orb { position: absolute; border-radius: 50%; filter: blur(2px); opacity: 0.55; }
    .orb.blue { width: 420px; height: 420px; top: -120px; left: -140px; background: radial-gradient(circle, var(--blue-600), transparent 70%); }
    .orb.amber { width: 360px; height: 360px; bottom: -140px; right: -100px; background: radial-gradient(circle, var(--amber-500), transparent 70%); }

    .top-controls { position: fixed; top: 22px; right: 22px; z-index: 10; }

    .theme-switch {
        appearance: none; -webkit-appearance: none;
        width: 62px; height: 32px; border-radius: 999px;
        border: 1px solid var(--glass-border);
        background: var(--navy-800);
        position: relative; cursor: pointer; outline-offset: 3px;
        transition: background 0.25s ease;
    }
    .theme-switch::before {
        content: "";
        position: absolute; top: 3px; left: 3px;
        width: 24px; height: 24px; border-radius: 50%;
        background: var(--ink-050);
        transition: transform 0.25s ease;
        box-shadow: 0 2px 6px rgba(0,0,0,0.35);
    }
    .theme-switch .icon-sun, .theme-switch .icon-moon {
        position: absolute; top: 50%; transform: translateY(-50%);
        width: 14px; height: 14px; pointer-events: none;
    }
    .theme-switch .icon-moon { left: 8px; color: var(--cream-300); }
    .theme-switch .icon-sun { right: 8px; color: var(--amber-400); }
    .theme-switch[data-theme="light"]::before { transform: translateX(30px); }

    html[data-theme="light"] {
        --navy-950: #eef1f6;
        --navy-900: #ffffff;
        --navy-800: #e3e8f0;
        --ink-050: #16233a;
        --ink-300: #48566e;
        --ink-500: #6d7c93;
        --glass-fill: rgba(255, 255, 255, 0.55);
        --glass-border: rgba(20, 40, 70, 0.10);
    }
    html[data-theme="light"] .field-bg {
        background:
            radial-gradient(circle at 18% 20%, rgba(30,87,153,0.14), transparent 42%),
            radial-gradient(circle at 82% 78%, rgba(217,114,44,0.14), transparent 45%),
            linear-gradient(160deg, #f3f5f9 0%, #eef1f6 60%, #eaeef4 100%);
    }
    html[data-theme="light"] .field-bg::before { background-image: linear-gradient(rgba(20,40,70,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(20,40,70,0.04) 1px, transparent 1px); }

    .stage { position: relative; z-index: 1; width: 100%; max-width: 440px; }

    .brand-row { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; padding: 0 4px; }
    .badge-ring {
        width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
        background: conic-gradient(from 200deg, var(--blue-600), var(--blue-400) 35%, var(--amber-400) 65%, var(--amber-500) 100%);
        padding: 2px;
    }
    .badge-ring span {
        display: flex; width: 100%; height: 100%; border-radius: 50%;
        background: var(--navy-900); align-items: center; justify-content: center;
        font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--ink-050);
    }
    .brand-text .eyebrow { font-size: 12px; color: var(--ink-500); }
    .brand-text .name { font-family: 'Space Grotesk', sans-serif; font-weight: 600; font-size: 15.5px; color: var(--ink-050); }

    .card {
        background: var(--glass-fill);
        border: 1px solid var(--glass-border);
        border-radius: var(--radius);
        padding: 34px 30px 28px;
        backdrop-filter: blur(22px) saturate(140%);
        -webkit-backdrop-filter: blur(22px) saturate(140%);
        box-shadow: 0 24px 60px rgba(4, 10, 20, 0.35);
    }

    .card h1 { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 24px; margin: 0 0 6px; }
    .card .sub { margin: 0 0 24px; font-size: 14px; color: var(--ink-300); line-height: 1.5; }

    .alert-glass {
        display: flex; gap: 10px; align-items: flex-start;
        border-radius: 12px; padding: 12px 14px; font-size: 13.5px; margin-bottom: 20px;
    }
    .alert-glass.err { background: rgba(229, 105, 79, 0.14); border: 1px solid rgba(229, 105, 79, 0.35); color: #ffb9a4; }
    .alert-glass.ok  { background: rgba(79, 191, 139, 0.14); border: 1px solid rgba(79, 191, 139, 0.35); color: #a8ecc9; }
    html[data-theme="light"] .alert-glass.err { color: #a53a22; }
    html[data-theme="light"] .alert-glass.ok  { color: #216b47; }

    .field-group { margin-bottom: 16px; }
    label { display: block; font-size: 12.5px; font-weight: 500; color: var(--ink-300); margin-bottom: 7px; }
    .hint { display: block; margin-top: 6px; font-size: 12px; color: var(--ink-500); }

    .input-wrap { position: relative; }

    input[type="text"], input[type="email"], input[type="password"] {
        width: 100%;
        padding: 13px 14px;
        border-radius: 12px;
        border: 1px solid var(--glass-border);
        background: rgba(255,255,255,0.05);
        color: var(--ink-050);
        font-size: 14.5px;
        font-family: 'Inter', sans-serif;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    html[data-theme="light"] input[type="text"], html[data-theme="light"] input[type="email"], html[data-theme="light"] input[type="password"] {
        background: rgba(20,40,70,0.035);
    }
    input::placeholder { color: var(--ink-500); }
    input:focus { outline: none; border-color: var(--blue-400); box-shadow: 0 0 0 3px rgba(79,143,214,0.22); }
    input.field-error { border-color: var(--danger); }
    input.field-error:focus { box-shadow: 0 0 0 3px rgba(229,105,79,0.22); }

    .input-wrap input[type="password"] { padding-right: 44px; }

    .eye-btn {
        position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
        width: 32px; height: 32px; border: none; background: transparent;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; border-radius: 8px; color: var(--ink-500);
    }
    .eye-btn:hover { color: var(--ink-050); background: rgba(255,255,255,0.06); }
    .eye-btn svg { width: 19px; height: 19px; }
    .eye-btn .icon-hidden { display: none; }
    .eye-btn.is-visible .icon-open { display: none; }
    .eye-btn.is-visible .icon-hidden { display: block; }

    .submit-btn {
        width: 100%; padding: 13px; border: none; border-radius: 12px; margin-top: 8px;
        font-family: 'Inter', sans-serif; font-weight: 600; font-size: 14.5px; color: #fff;
        background: linear-gradient(120deg, var(--blue-600), var(--amber-500));
        cursor: pointer;
        box-shadow: 0 10px 24px rgba(30,87,153,0.30);
        transition: transform 0.12s ease, box-shadow 0.12s ease;
    }
    .submit-btn:hover { transform: translateY(-1px); box-shadow: 0 14px 30px rgba(30,87,153,0.38); }
    .submit-btn:active { transform: translateY(0); }

    .foot { text-align: center; margin-top: 22px; font-size: 13.5px; color: var(--ink-300); }
    .foot a { color: var(--cream-300); text-decoration: none; font-weight: 500; }
    .foot a:hover { text-decoration: underline; }

    @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>

<div class="field-bg" aria-hidden="true">
    <div class="orb blue"></div>
    <div class="orb amber"></div>
</div>

<div class="top-controls">
    <button type="button" class="theme-switch" id="themeSwitch" data-theme="dark" aria-label="Toggle day mode" aria-pressed="false">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
</div>

<div class="stage">

    <div class="brand-row">
        <div class="badge-ring"><span>MAU</span></div>
        <div class="brand-text">
            <div class="eyebrow">Smart Attendance</div>
            <div class="name">Modibbo Adama University</div>
        </div>
    </div>

    <div class="card">
        <h1>Create your account</h1>
        <p class="sub">Register with your matric number to start checking in to classes.</p>

        <?php if ($error !== ""): ?>
            <div class="alert-glass err">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success !== ""): ?>
            <div class="alert-glass ok">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><path d="m5 13 4 4L19 7"/></svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" autocomplete="off">
            <div class="field-group">
                <label for="full_name">Full Name</label>
                <input type="text" name="full_name" id="full_name"
                       class="<?= trim(invalid_class('full_name', $invalid_fields)) ?>"
                       required autocomplete="off" value="<?= htmlspecialchars($full_name) ?>">
            </div>

            <div class="field-group">
                <label for="matric_number">Matric Number</label>
                <input type="text" name="matric_number" id="matric_number"
                       class="<?= trim(invalid_class('matric_number', $invalid_fields)) ?>"
                       required autocomplete="off" value="<?= htmlspecialchars($matric_no) ?>">
            </div>

            <div class="field-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email"
                       class="<?= trim(invalid_class('email', $invalid_fields)) ?>"
                       required autocomplete="off" placeholder="e.g. you@gmail.com" value="<?= htmlspecialchars($email) ?>">
                <span class="hint">Gmail, Yahoo, Outlook, iCloud, or similar common providers only.</span>
            </div>

            <div class="field-group">
                <label for="password">Password</label>
                <div class="input-wrap">
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
                    <input type="password" name="confirm_password" id="confirm_password"
                           class="<?= trim(invalid_class('confirm_password', $invalid_fields)) ?>"
                           required autocomplete="off">
                    <button type="button" class="eye-btn" data-target="confirm_password" aria-label="Show password">
                        <svg class="icon-open" viewBox="0 0 24 24" fill="currentColor"><path d="M12 5c-5.5 0-9.5 4.5-10.7 6.6a1 1 0 0 0 0 .8C2.5 14.5 6.5 19 12 19s9.5-4.5 10.7-6.6a1 1 0 0 0 0-.8C21.5 9.5 17.5 5 12 5Zm0 12c-4.2 0-7.6-3.3-8.9-5C4.4 10.3 7.8 7 12 7s7.6 3.3 8.9 5c-1.3 1.7-4.7 5-8.9 5Zm0-8.3A3.3 3.3 0 1 0 12 15a3.3 3.3 0 0 0 0-6.6Z"/></svg>
                        <svg class="icon-hidden" viewBox="0 0 24 24" fill="currentColor"><path d="m2.1 3.5 1.4-1.4 18 18-1.4 1.4-3.2-3.2A11.6 11.6 0 0 1 12 19c-5.5 0-9.5-4.5-10.7-6.6a1 1 0 0 1 0-.8 19.9 19.9 0 0 1 4.2-4.9L2.1 3.5Zm6.6 6.6 6.2 6.2a5.3 5.3 0 0 1-6.2-6.2ZM12 5c5.5 0 9.5 4.5 10.7 6.6a1 1 0 0 1 0 .8 19.6 19.6 0 0 1-3 3.7l-1.4-1.4a17.7 17.7 0 0 0 2.5-3.1c-1.3-1.7-4.7-5-8.8-5-1 0-1.9.16-2.8.46L7.7 5.6A11.5 11.5 0 0 1 12 5Zm-2.7 4.1 1.5 1.5a1.6 1.6 0 0 0 1.6 1.6l1.5 1.5A3.3 3.3 0 0 1 9.3 9.1Z"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="submit-btn">Register</button>
        </form>

        <div class="foot">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</div>

<script>
    // Password visibility toggle (eye / eye-slash) — works for both fields
    document.querySelectorAll(".eye-btn").forEach(function (btn) {
        const input = document.getElementById(btn.dataset.target);
        btn.addEventListener("click", function () {
            const show = input.type === "password";
            input.type = show ? "text" : "password";
            btn.classList.toggle("is-visible", show);
            btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
        });
    });

    // Day/night mode toggle, persisted with the same key used across the app
    (function () {
        const THEME_KEY = "attendance_theme";
        const root = document.documentElement;
        const toggle = document.getElementById("themeSwitch");

        function apply(theme) {
            root.setAttribute("data-theme", theme);
            root.style.colorScheme = theme;
            toggle.dataset.theme = theme;
            toggle.setAttribute("aria-pressed", theme === "light");
            toggle.setAttribute("aria-label", theme === "light" ? "Switch to dark mode" : "Switch to day mode");
        }

        const saved = localStorage.getItem(THEME_KEY) || "dark";
        apply(saved);

        toggle.addEventListener("click", function () {
            const next = root.getAttribute("data-theme") === "light" ? "dark" : "light";
            localStorage.setItem(THEME_KEY, next);
            apply(next);
        });
    })();
</script>

</body>
</html>