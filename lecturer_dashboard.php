<?php
$required_role = 'lecturer';
require_once "auth_check.php";

$lecturer_id = $_SESSION["user_id"];
$error = "";
$success = "";

// Proactively close any of this lecturer's sessions whose end time has already passed —
// runs every time the dashboard loads, not just when a student tries to check in late.
$conn->query(
    "UPDATE attendance_sessions
     SET status = 'closed'
     WHERE lecturer_id = " . intval($lecturer_id) . "
       AND status = 'active'
       AND CONCAT(session_date, ' ', end_time) < NOW()"
);

// Handle: Add a new course
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_course"])) {
    $course_code  = trim($_POST["course_code"]);
    $course_title = trim($_POST["course_title"]);
    $course_unit  = intval($_POST["course_unit"]);

    if (empty($course_code) || empty($course_title)) {
        $error = "Course code and title are required.";
    } elseif ($course_unit < 1 || $course_unit > 6) {
        $error = "Course unit must be between 1 and 6.";
    } else {
        $stmt = $conn->prepare("INSERT INTO courses (course_code, course_title, course_unit, lecturer_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssii", $course_code, $course_title, $course_unit, $lecturer_id);
        if ($stmt->execute()) {
            $success = "Course added successfully.";
        } else {
            $error = "Could not add course. Please try again.";
        }
        $stmt->close();
    }
}

// Handle new session creation
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_session"])) {
    $course_id  = $_POST["course_id"];
    $session_date = $_POST["session_date"];
    $start_time = $_POST["start_time"];
    $end_time   = $_POST["end_time"];

    if (empty($course_id) || empty($session_date) || empty($start_time) || empty($end_time)) {
        $error = "All fields are required to start a session.";
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $error = "End time must be after start time.";
    } else {
        $start_datetime = $session_date . " " . $start_time . ":00";
        $end_datetime   = $session_date . " " . $end_time . ":00";

        $check = $conn->prepare("SELECT course_id FROM courses WHERE course_id = ? AND lecturer_id = ?");
        $check->bind_param("ii", $course_id, $lecturer_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows === 0) {
            $error = "Invalid course selection.";
        } else {
            $join_code = str_pad(strval(random_int(0, 999999)), 6, "0", STR_PAD_LEFT);

            $insert = $conn->prepare(
                "INSERT INTO attendance_sessions (course_id, lecturer_id, session_date, start_time, end_time, status, join_code)
                 VALUES (?, ?, ?, ?, ?, 'active', ?)"
            );
            $insert->bind_param("iissss", $course_id, $lecturer_id, $session_date, $start_datetime, $end_datetime, $join_code);

            if ($insert->execute()) {
                $success = "Session created. Share this join code with students: $join_code";
            } else {
                $error = "Something went wrong creating the session.";
            }
            $insert->close();
        }
        $check->close();
    }
}

// Handle closing a session early
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["close_session"])) {
    $session_id = $_POST["session_id"];
    $close = $conn->prepare("UPDATE attendance_sessions SET status = 'closed' WHERE session_id = ? AND lecturer_id = ?");
    $close->bind_param("ii", $session_id, $lecturer_id);
    $close->execute();
    $close->close();
    $success = "Session closed.";
}

// Fetch this lecturer's courses
$courses = [];
$stmt = $conn->prepare("SELECT course_id, course_code, course_title, course_unit FROM courses WHERE lecturer_id = ?");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}
$stmt->close();

// Fetch this lecturer's sessions (most recent first) — now also pulling course_unit
$sessions = [];
$stmt = $conn->prepare(
    "SELECT s.session_id, s.session_date, s.start_time, s.end_time, s.status, s.join_code,
            c.course_code, c.course_title, c.course_unit,
            (SELECT COUNT(*) FROM attendance_records r WHERE r.session_id = s.session_id) AS attendance_count
     FROM attendance_sessions s
     JOIN courses c ON s.course_id = c.course_id
     WHERE s.lecturer_id = ?
     ORDER BY s.session_date DESC, s.start_time DESC"
);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $sessions[] = $row;
}
$stmt->close();

// How many seconds before an active session's end time counts as "closing soon"
const CLOSING_SOON_WINDOW_SECONDS = 300; // 5 minutes
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard · MAU Smart Attendance</title>
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
            --radius: 18px;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; font-family: 'Inter', system-ui, sans-serif; color: var(--ink-050); background: var(--navy-950); }
        body { min-height: 100vh; position: relative; }

        .field-bg {
            position: fixed; inset: 0; z-index: 0; overflow: hidden;
            background:
                radial-gradient(circle at 12% 8%, rgba(30,87,153,0.30), transparent 40%),
                radial-gradient(circle at 90% 85%, rgba(217,114,44,0.22), transparent 42%),
                linear-gradient(160deg, var(--navy-950) 0%, var(--navy-900) 55%, #0d1f34 100%);
        }
        .field-bg::before {
            content: ""; position: absolute; inset: -1px;
            background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: radial-gradient(ellipse at top, black 0%, transparent 78%);
        }
        html[data-theme="light"] {
            --navy-950: #eef1f6; --navy-900: #ffffff; --navy-800: #e3e8f0;
            --ink-050: #16233a; --ink-300: #48566e; --ink-500: #6d7c93;
            --glass-fill: rgba(255, 255, 255, 0.62);
            --glass-border: rgba(20, 40, 70, 0.10);
        }
        html[data-theme="light"] .field-bg {
            background:
                radial-gradient(circle at 12% 8%, rgba(30,87,153,0.12), transparent 40%),
                radial-gradient(circle at 90% 85%, rgba(217,114,44,0.12), transparent 42%),
                linear-gradient(160deg, #f3f5f9 0%, #eef1f6 60%, #eaeef4 100%);
        }
        html[data-theme="light"] .field-bg::before { background-image: linear-gradient(rgba(20,40,70,0.035) 1px, transparent 1px), linear-gradient(90deg, rgba(20,40,70,0.035) 1px, transparent 1px); }

        /* ---------- navbar ---------- */
        .topbar {
            position: sticky; top: 0; z-index: 20;
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 24px;
            background: var(--glass-fill);
            border-bottom: 1px solid var(--glass-border);
            backdrop-filter: blur(18px) saturate(140%);
            -webkit-backdrop-filter: blur(18px) saturate(140%);
        }
        .brand-row { display: flex; align-items: center; gap: 12px; }
        .badge-ring {
            width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
            background: conic-gradient(from 200deg, var(--blue-600), var(--blue-400) 35%, var(--amber-400) 65%, var(--amber-500) 100%);
            padding: 2px;
        }
        .badge-ring span {
            display: flex; width: 100%; height: 100%; border-radius: 50%;
            background: var(--navy-900); align-items: center; justify-content: center;
            font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: var(--ink-050);
        }
        .brand-text .eyebrow { font-size: 11.5px; color: var(--ink-500); }
        .brand-text .name { font-family: 'Space Grotesk', sans-serif; font-weight: 600; font-size: 14.5px; }
        .topbar-right { display: flex; align-items: center; gap: 14px; }
        .welcome-text { font-size: 13.5px; color: var(--ink-300); }
        .welcome-text strong { color: var(--ink-050); font-weight: 600; }

        .theme-switch {
            appearance: none; -webkit-appearance: none;
            width: 56px; height: 30px; border-radius: 999px;
            border: 1px solid var(--glass-border); background: var(--navy-800);
            position: relative; cursor: pointer; outline-offset: 3px; transition: background 0.25s ease; flex-shrink: 0;
        }
        .theme-switch::before {
            content: ""; position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 50%;
            background: var(--ink-050); transition: transform 0.25s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.35);
        }
        .theme-switch .icon-sun, .theme-switch .icon-moon { position: absolute; top: 50%; transform: translateY(-50%); width: 12px; height: 12px; pointer-events: none; }
        .theme-switch .icon-moon { left: 7px; color: var(--cream-300); }
        .theme-switch .icon-sun { right: 7px; color: var(--amber-400); }
        .theme-switch[data-theme="light"]::before { transform: translateX(26px); }

        .btn-ghost {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 10px; font-size: 13px; font-weight: 500;
            border: 1px solid var(--glass-border); background: rgba(255,255,255,0.04);
            color: var(--ink-050); text-decoration: none; cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-ghost:hover { background: rgba(255,255,255,0.08); }

        /* ---------- layout ---------- */
        .page { position: relative; z-index: 1; max-width: 980px; margin: 0 auto; padding: 28px 20px 60px; }

        .card {
            background: var(--glass-fill);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 24px 24px 22px;
            backdrop-filter: blur(20px) saturate(140%);
            -webkit-backdrop-filter: blur(20px) saturate(140%);
            box-shadow: 0 20px 50px rgba(4, 10, 20, 0.28);
            margin-bottom: 22px;
        }
        .card h2 { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 17.5px; margin: 0 0 18px; }

        .muted { color: var(--ink-500); }

        /* ---------- forms ---------- */
        label { display: block; font-size: 12px; font-weight: 500; color: var(--ink-300); margin-bottom: 6px; }
        input[type="text"], input[type="date"], input[type="time"], input[type="number"], select {
            width: 100%; padding: 11px 13px; border-radius: 11px;
            border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05);
            color: var(--ink-050); font-size: 14px; font-family: 'Inter', sans-serif;
        }
        html[data-theme="light"] input, html[data-theme="light"] select { background: rgba(20,40,70,0.035); }
        input::placeholder { color: var(--ink-500); }
        input:focus, select:focus { outline: none; border-color: var(--blue-400); box-shadow: 0 0 0 3px rgba(79,143,214,0.22); }
        select { appearance: none; -webkit-appearance: none; cursor: pointer; }

        .form-grid { display: grid; gap: 14px; }
        .form-grid.cols-course { grid-template-columns: 1.3fr 1.7fr 0.6fr 0.8fr; align-items: end; }
        .form-grid.cols-session { grid-template-columns: 1fr 1fr; }
        .form-grid.cols-session .span-2 { grid-column: span 2; }
        @media (max-width: 720px) {
            .form-grid.cols-course { grid-template-columns: 1fr 1fr; }
            .form-grid.cols-session { grid-template-columns: 1fr; }
            .form-grid.cols-session .span-2 { grid-column: span 1; }
        }

        .btn-primary {
            border: none; border-radius: 11px; padding: 11px 18px; font-weight: 600; font-size: 14px; color: #fff;
            background: linear-gradient(120deg, var(--blue-600), var(--amber-500));
            cursor: pointer; box-shadow: 0 10px 22px rgba(30,87,153,0.28);
            transition: transform 0.12s ease;
        }
        .btn-primary:hover { transform: translateY(-1px); }

        .btn-outline-danger {
            border: 1px solid rgba(229,105,79,0.5); background: rgba(229,105,79,0.08); color: #ff8f70;
            border-radius: 9px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; cursor: pointer;
        }
        .btn-outline-danger:hover { background: rgba(229,105,79,0.16); }
        html[data-theme="light"] .btn-outline-danger { color: #b8442a; }

        .btn-icon {
            border: 1px solid var(--glass-border); background: rgba(255,255,255,0.04); color: var(--ink-300);
            border-radius: 8px; padding: 5px 9px; font-size: 12px; cursor: pointer; line-height: 1;
        }
        .btn-icon:hover { background: rgba(255,255,255,0.09); color: var(--ink-050); }

        /* ---------- badges ---------- */
        .chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .chip-success { background: rgba(79,191,139,0.18); color: #7fe0b3; }
        .chip-warning { background: rgba(217,114,44,0.18); color: var(--cream-300); }
        .chip-secondary { background: rgba(255,255,255,0.08); color: var(--ink-300); }
        html[data-theme="light"] .chip-success { color: #1c7a4c; }
        html[data-theme="light"] .chip-warning { color: #8a4c1c; }

        /* ---------- table ---------- */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { text-align: left; font-weight: 500; color: var(--ink-500); font-size: 11.5px; padding: 0 10px 10px; border-bottom: 1px solid var(--glass-border); white-space: nowrap; }
        tbody td { padding: 12px 10px; border-bottom: 1px solid var(--glass-border); white-space: nowrap; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: rgba(255,255,255,0.025); }
        .join-code { display: flex; align-items: center; gap: 8px; }
        .join-code code {
            font-family: 'Space Grotesk', monospace; font-size: 14px; letter-spacing: 0.05em;
            background: rgba(255,255,255,0.06); padding: 2px 8px; border-radius: 6px; color: var(--cream-300);
        }

        /* ---------- toast ---------- */
        #toastStack { position: fixed; top: 18px; right: 18px; z-index: 100; display: flex; flex-direction: column; gap: 10px; max-width: 340px; }
        .toast-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; border-radius: 12px; font-size: 13.5px;
            background: var(--glass-fill); border: 1px solid var(--glass-border);
            backdrop-filter: blur(18px) saturate(140%); -webkit-backdrop-filter: blur(18px) saturate(140%);
            box-shadow: 0 14px 34px rgba(4,10,20,0.35);
            animation: toastIn 0.2s ease-out;
        }
        .toast-item.danger  { border-left: 3px solid var(--danger); }
        .toast-item.success { border-left: 3px solid var(--success); }
        .toast-item.info    { border-left: 3px solid var(--blue-400); }
        @keyframes toastIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .toast-close { margin-left: auto; background: none; border: none; color: var(--ink-500); cursor: pointer; font-size: 15px; line-height: 1; }

        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
    </style>
</head>
<body>

<div class="field-bg" aria-hidden="true"></div>
<div id="toastStack"></div>

<nav class="topbar">
    <div class="brand-row">
        <div class="badge-ring"><span>MAU</span></div>
        <div class="brand-text">
            <div class="eyebrow">Smart Attendance</div>
            <div class="name">Lecturer Dashboard</div>
        </div>
    </div>
    <div class="topbar-right">
        <button type="button" class="theme-switch" id="themeSwitch" data-theme="dark" aria-label="Toggle day mode" aria-pressed="false">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
        </button>
        <span class="welcome-text">Welcome, <strong><?= htmlspecialchars($_SESSION["full_name"]) ?></strong></span>
        <a href="logout.php" class="btn-ghost">Logout</a>
    </div>
</nav>

<div class="page">

    <div class="card">
        <h2>Add a course</h2>
        <form method="POST" action="lecturer_dashboard.php" class="form-grid cols-course">
            <div>
                <label>Course Code</label>
                <input type="text" name="course_code" placeholder="e.g. CSC401" required>
            </div>
            <div>
                <label>Course Title</label>
                <input type="text" name="course_title" placeholder="e.g. Software Engineering" required>
            </div>
            <div>
                <label>Unit</label>
                <input type="number" name="course_unit" min="1" max="6" value="3" required>
            </div>
            <div>
                <button type="submit" name="add_course" class="btn-primary" style="width:100%;">Add</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Start a new attendance session</h2>

        <?php if (empty($courses)): ?>
            <p class="muted">Add a course above first before opening a session.</p>
        <?php else: ?>
            <form method="POST" action="lecturer_dashboard.php" class="form-grid cols-session">
                <div class="span-2">
                    <label>Course</label>
                    <select name="course_id" required>
                        <option value="" selected disabled>-- Select a course --</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['course_id'] ?>">
                                <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_title']) ?> (<?= (int)$c['course_unit'] ?> Units)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="span-2">
                    <label>Date</label>
                    <input type="date" name="session_date" required>
                </div>
                <div>
                    <label>Start Time</label>
                    <input type="time" name="start_time" id="start_time" required>
                </div>
                <div>
                    <label>End Time</label>
                    <input type="time" name="end_time" id="end_time" required>
                </div>
                <div class="span-2">
                    <button type="submit" name="create_session" class="btn-primary">Start Session</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Your sessions</h2>

        <?php if (empty($sessions)): ?>
            <p class="muted">No sessions created yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Course</th><th>Unit</th><th>Date</th><th>Time</th>
                            <th>Join Code</th><th>Status</th><th>Present</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sessions as $s): ?>
                        <?php
                            // Determine "closing soon" state for active sessions.
                            // end_time is stored as a full datetime (date + time
                            // combined at session creation), so strtotime() on it
                            // directly gives the real end timestamp.
                            $is_closing_soon = false;
                            if ($s['status'] === 'active') {
                                $end_ts = strtotime($s['end_time']);
                                $remaining = $end_ts - time();
                                if ($remaining > 0 && $remaining <= CLOSING_SOON_WINDOW_SECONDS) {
                                    $is_closing_soon = true;
                                }
                            }
                            $join_code_dom_id = "joinCode" . (int)$s['session_id'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($s['course_code']) ?></td>
                            <td><?= (int)$s['course_unit'] ?></td>
                            <td><?= htmlspecialchars($s['session_date']) ?></td>
                            <td>
                                <?= date("g:i A", strtotime($s['start_time'])) ?> –
                                <?= date("g:i A", strtotime($s['end_time'])) ?>
                            </td>
                            <td>
                                <?php if ($s['status'] === 'active'): ?>
                                    <div class="join-code">
                                        <code id="<?= $join_code_dom_id ?>"><?= htmlspecialchars($s['join_code']) ?></code>
                                        <button type="button" class="btn-icon"
                                                onclick="copyElementText('<?= $join_code_dom_id ?>', 'Join code copied!')"
                                                title="Copy join code">Copy</button>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['status'] === 'active'): ?>
                                    <?php if ($is_closing_soon): ?>
                                        <span class="chip chip-warning">Closing Soon</span>
                                    <?php else: ?>
                                        <span class="chip chip-success">Active</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="chip chip-secondary">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$s['attendance_count'] ?></td>
                            <td>
                                <?php if ($s['status'] === 'active'): ?>
                                    <form method="POST" action="lecturer_dashboard.php" style="display:inline;">
                                        <input type="hidden" name="session_id" value="<?= $s['session_id'] ?>">
                                        <button type="submit" name="close_session" class="btn-outline-danger">Close</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
/* ---------------------------------------------------------
   Toast notifications (self-contained glass toasts — no
   Bootstrap dependency)
--------------------------------------------------------- */
function showToast(message, type) {
    if (!message) return;
    type = type || "info";
    const stack = document.getElementById("toastStack");
    const item = document.createElement("div");
    item.className = "toast-item " + type;
    item.innerHTML = '<span></span><button type="button" class="toast-close" aria-label="Dismiss">&times;</button>';
    item.querySelector("span").textContent = message;
    item.querySelector(".toast-close").addEventListener("click", () => item.remove());
    stack.appendChild(item);
    setTimeout(() => item.remove(), 5000);
}
window.showToast = showToast;

/* ---------------------------------------------------------
   Copy-to-clipboard helper (used for join codes)
--------------------------------------------------------- */
function copyElementText(elementId, successMessage) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(() => {
        showToast(successMessage || "Copied to clipboard!", "success");
    }).catch(() => {
        showToast("Could not copy — please copy it manually.", "danger");
    });
}
window.copyElementText = copyElementText;

/* ---------------------------------------------------------
   Day/night mode toggle, persisted with the same key used
   across the app
--------------------------------------------------------- */
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

const startTimeInput = document.getElementById('start_time');
if (startTimeInput) {
    startTimeInput.addEventListener('change', function() {
        document.getElementById('end_time').min = this.value;
    });
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