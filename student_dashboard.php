<?php
$required_role = 'student';
require_once "auth_check.php";
require_once "wifi_config.php";

$student_id = $_SESSION["user_id"];
$error = "";
$success = "";

function get_client_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'];
}

// Helper for binding a dynamic number of params to a mysqli prepared
// statement. mysqli_stmt::bind_param requires its arguments by reference,
// so a plain `...$params` spread won't work reliably — this builds the
// reference array bind_param actually needs.
function bind_params_dynamic($stmt, $types, $params) {
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

$has_face_profile = false;
$fp_stmt = $conn->prepare("SELECT profile_id FROM face_profiles WHERE student_id = ?");
$fp_stmt->bind_param("i", $student_id);
$fp_stmt->execute();
$fp_stmt->store_result();
$has_face_profile = $fp_stmt->num_rows > 0;
$fp_stmt->close();

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["join_code"])) {
    $join_code = trim($_POST["join_code"]);
    $student_ip = get_client_ip();
    $face_verified = isset($_POST["face_verified"]) && $_POST["face_verified"] === "1";

    if (empty($join_code)) {
        $error = "Please enter the join code given by your lecturer.";
    } else {
        $stmt = $conn->prepare(
            "SELECT s.session_id, s.start_time, s.end_time, s.status, c.course_code, c.course_title
             FROM attendance_sessions s
             JOIN courses c ON s.course_id = c.course_id
             WHERE s.join_code = ?
             ORDER BY s.session_id DESC LIMIT 1"
        );
        $stmt->bind_param("s", $join_code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $error = "Invalid join code. Please check with your lecturer and try again.";
        } else {
            $session = $result->fetch_assoc();
            $now = time();
            $start = strtotime($session['start_time']);
            $end   = strtotime($session['end_time']);

            if ($session['status'] !== 'active') {
                $error = "This session has been closed by the lecturer.";
            } elseif ($now < $start || $now > $end) {
                $conn->query("UPDATE attendance_sessions SET status = 'closed' WHERE session_id = " . intval($session['session_id']));
                $error = "This session is not currently open for check-in.";
            } else {
                if (!ip_matches_allowed_prefix($student_ip, $allowed_ip_prefixes)) {
                    $error = "You must be connected to the lecturer's WiFi network to check in. Your IP ($student_ip) was not recognized.";
                } elseif (!$face_verified) {
                    $error = "Face verification did not complete. Please try checking in again.";
                } else {
                    $dup = $conn->prepare(
                        "SELECT record_id FROM attendance_records WHERE session_id = ? AND student_id = ?"
                    );
                    $dup->bind_param("ii", $session['session_id'], $student_id);
                    $dup->execute();
                    $dup->store_result();

                    if ($dup->num_rows > 0) {
                        $error = "You have already been marked present for this session.";
                    } else {
                        $insert = $conn->prepare(
                            "INSERT INTO attendance_records (session_id, student_id, ip_address, status)
                             VALUES (?, ?, ?, 'present')"
                        );
                        $insert->bind_param("iis", $session['session_id'], $student_id, $student_ip);

                        if ($insert->execute()) {
                            $success = "You're marked present for " . $session['course_code'] . " — " . $session['course_title'] . ".";
                        } else {
                            $error = "Something went wrong recording your attendance. Please try again.";
                        }
                        $insert->close();
                    }
                    $dup->close();
                }
            }
        }
        $stmt->close();
    }
}

// --- Attendance history filters (course + date range) -----------------
// Uses GET so results are bookmarkable/shareable and don't interfere with
// the POST check-in form above.
$filter_course_id = isset($_GET['filter_course']) ? intval($_GET['filter_course']) : 0;
$filter_date_from = $_GET['filter_from'] ?? '';
$filter_date_to   = $_GET['filter_to'] ?? '';
$has_filters = ($filter_course_id > 0) || !empty($filter_date_from) || !empty($filter_date_to);

// Courses this student has at least one attendance record for — populates
// the course filter dropdown. Not the same as "enrolled courses" (this
// system doesn't track enrolment separately; attendance is join-code based).
$student_courses = [];
$sc_stmt = $conn->prepare(
    "SELECT DISTINCT c.course_id, c.course_code
     FROM attendance_records r
     JOIN attendance_sessions s ON r.session_id = s.session_id
     JOIN courses c ON s.course_id = c.course_id
     WHERE r.student_id = ?
     ORDER BY c.course_code"
);
$sc_stmt->bind_param("i", $student_id);
$sc_stmt->execute();
$sc_result = $sc_stmt->get_result();
while ($row = $sc_result->fetch_assoc()) {
    $student_courses[] = $row;
}
$sc_stmt->close();

$history_sql = "SELECT r.marked_at, r.status, c.course_code, c.course_title
                FROM attendance_records r
                JOIN attendance_sessions s ON r.session_id = s.session_id
                JOIN courses c ON s.course_id = c.course_id
                WHERE r.student_id = ?";
$history_params = [$student_id];
$history_types = "i";

if ($filter_course_id > 0) {
    $history_sql .= " AND c.course_id = ?";
    $history_params[] = $filter_course_id;
    $history_types .= "i";
}
if (!empty($filter_date_from)) {
    $history_sql .= " AND DATE(r.marked_at) >= ?";
    $history_params[] = $filter_date_from;
    $history_types .= "s";
}
if (!empty($filter_date_to)) {
    $history_sql .= " AND DATE(r.marked_at) <= ?";
    $history_params[] = $filter_date_to;
    $history_types .= "s";
}

$history_sql .= " ORDER BY r.marked_at DESC";
// Default view stays capped at 10 (unchanged prior behavior); once any
// filter is applied, show up to 200 matching records instead.
$history_sql .= $has_filters ? " LIMIT 200" : " LIMIT 10";

$history = [];
$stmt = $conn->prepare($history_sql);
bind_params_dynamic($stmt, $history_types, $history_params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard · MAU Smart Attendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script defer src="assets/facelib/face-api.min.js"></script>
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
            --glass-fill-soft: rgba(20, 36, 58, 0.28);
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
            --glass-fill: rgba(255, 255, 255, 0.62); --glass-fill-soft: rgba(255,255,255,0.4);
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
        .page { position: relative; z-index: 1; max-width: 760px; margin: 0 auto; padding: 28px 20px 60px; }

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
        .card-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 6px; flex-wrap: wrap; }
        .card h2 { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 17.5px; margin: 0; }
        .card .lede { margin: 10px 0 18px; font-size: 13.5px; color: var(--ink-300); }

        /* ---------- alerts / status / toast (also driven directly by JS classNames) ---------- */
        .alert {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            border-radius: 12px; padding: 11px 14px; font-size: 13.5px; margin-top: 6px; margin-bottom: 14px;
        }
        .alert-info     { background: rgba(79,143,214,0.14);  border: 1px solid rgba(79,143,214,0.35);  color: #bcd6f5; }
        .alert-success  { background: rgba(79,191,139,0.14);  border: 1px solid rgba(79,191,139,0.35);  color: #a8ecc9; }
        .alert-danger   { background: rgba(229,105,79,0.14);  border: 1px solid rgba(229,105,79,0.35);  color: #ffb9a4; }
        .alert-secondary{ background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border);    color: var(--ink-300); }
        .alert-primary  { background: rgba(79,143,214,0.14);  border: 1px solid rgba(79,143,214,0.35);  color: #bcd6f5; }
        .alert-warning  { background: rgba(217,114,44,0.14);  border: 1px solid rgba(217,114,44,0.35);  color: var(--cream-300); }
        html[data-theme="light"] .alert-info      { color: #1e5799; }
        html[data-theme="light"] .alert-success   { color: #216b47; }
        html[data-theme="light"] .alert-danger    { color: #a53a22; }
        html[data-theme="light"] .alert-primary   { color: #1e5799; }
        html[data-theme="light"] .alert-warning   { color: #8a4c1c; }
        .fw-bold { font-weight: 700; }
        .text-danger { color: var(--danger) !important; }

        /* ---------- enrollment nudge ---------- */
        .enroll-nudge {
            display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
            background: rgba(217,114,44,0.14); border: 1px solid rgba(217,114,44,0.35);
            color: var(--cream-300); border-radius: 14px; padding: 13px 16px; margin-bottom: 22px; font-size: 13.5px;
        }
        html[data-theme="light"] .enroll-nudge { color: #8a4c1c; }
        .pill-btn {
            flex-shrink: 0; padding: 7px 14px; border-radius: 999px; border: none; font-size: 12.5px; font-weight: 600;
            color: #fff; text-decoration: none; background: linear-gradient(120deg, var(--blue-600), var(--amber-500));
        }

        /* ---------- badges ---------- */
        .chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .chip-success { background: rgba(79,191,139,0.18); color: #7fe0b3; }
        .chip-warning { background: rgba(217,114,44,0.18); color: var(--cream-300); }
        html[data-theme="light"] .chip-success { color: #1c7a4c; }
        html[data-theme="light"] .chip-warning { color: #8a4c1c; }

        /* ---------- form fields ---------- */
        label { display: block; font-size: 12px; font-weight: 500; color: var(--ink-300); margin-bottom: 6px; }
        input[type="text"], input[type="date"], select {
            width: 100%; padding: 11px 13px; border-radius: 11px;
            border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05);
            color: var(--ink-050); font-size: 14px; font-family: 'Inter', sans-serif;
        }
        html[data-theme="light"] input[type="text"], html[data-theme="light"] input[type="date"], html[data-theme="light"] select { background: rgba(20,40,70,0.035); }
        input::placeholder { color: var(--ink-500); }
        input:focus, select:focus { outline: none; border-color: var(--blue-400); box-shadow: 0 0 0 3px rgba(79,143,214,0.22); }
        input:disabled, select:disabled { opacity: 0.5; cursor: not-allowed; }
        select { appearance: none; -webkit-appearance: none; cursor: pointer; }

        .checkin-row { display: flex; gap: 10px; }
        .checkin-row input { flex: 1; text-align: center; font-size: 18px; letter-spacing: 0.15em; font-weight: 600; }
        .checkin-row button { flex: 0 0 130px; }

        .btn-primary, .btn-success {
            border: none; border-radius: 11px; padding: 12px 16px; font-weight: 600; font-size: 14px; color: #fff;
            cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, opacity 0.15s ease;
        }
        .btn-primary { background: linear-gradient(120deg, var(--blue-600), var(--blue-400)); box-shadow: 0 10px 22px rgba(30,87,153,0.28); }
        .btn-success { background: linear-gradient(120deg, #2e9f6d, var(--success)); box-shadow: 0 10px 22px rgba(79,191,139,0.25); }
        .btn-primary:hover:not(:disabled), .btn-success:hover:not(:disabled) { transform: translateY(-1px); }
        .btn-primary:disabled, .btn-success:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

        .filter-row { display: grid; grid-template-columns: 1.2fr 1fr 1fr auto; gap: 8px; margin-bottom: 16px; }
        .filter-row button { padding: 10px 14px; border-radius: 11px; border: none; font-size: 13px; font-weight: 600; color: #fff; background: linear-gradient(120deg, var(--blue-600), var(--blue-400)); cursor: pointer; }
        @media (max-width: 620px) { .filter-row { grid-template-columns: 1fr 1fr; } .filter-row button { grid-column: span 2; } }

        /* ---------- table ---------- */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { text-align: left; font-weight: 500; color: var(--ink-500); font-size: 11.5px; text-transform: none; padding: 0 10px 10px; border-bottom: 1px solid var(--glass-border); }
        tbody td { padding: 11px 10px; border-bottom: 1px solid var(--glass-border); }
        tbody tr:last-child td { border-bottom: none; }
        .muted { color: var(--ink-500); }
        .small { font-size: 12.5px; }

        /* ---------- face check-in widgets ---------- */
        .progress-ring-wrap { position: relative; width: 76px; height: 76px; margin: 6px auto 4px; }
        .progress-ring-bg { stroke: rgba(255,255,255,0.10); }
        html[data-theme="light"] .progress-ring-bg { stroke: rgba(20,40,70,0.10); }
        .progress-ring-fg { stroke: url(#studentRingGradient); stroke-linecap: round; transition: stroke-dashoffset 0.15s linear; }
        .progress-ring-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.85rem; font-family: 'Space Grotesk', sans-serif; }

        .face-video-wrap { position: relative; display: inline-block; margin: 6px 0; }
        #checkinVideo { transform: scaleX(-1); border-radius: 14px; border: 1px solid var(--glass-border); display: block; background: #000; }
        .face-oval-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        .face-oval-overlay ellipse {
            fill: none; stroke: rgba(255,255,255,0.55); stroke-width: 3; stroke-dasharray: 8 6;
            transition: stroke 0.2s ease, stroke-dasharray 0.2s ease, stroke-width 0.2s ease;
        }
        .face-oval-overlay ellipse.oval-good { stroke: var(--success); stroke-dasharray: none; stroke-width: 4; }
        .face-oval-overlay ellipse.oval-bad { stroke: var(--amber-400); }

        @keyframes scanPulse {
            0%   { box-shadow: 0 0 0 0 rgba(79,143,214,0.55); }
            70%  { box-shadow: 0 0 0 14px rgba(79,143,214,0); }
            100% { box-shadow: 0 0 0 0 rgba(79,143,214,0); }
        }
        .scanning-active { animation: scanPulse 1.4s infinite; border-radius: 14px; }

        .result-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.35); pointer-events: none; border-radius: 14px; }
        .result-circle { fill: none; stroke-width: 3; stroke-dasharray: 145; stroke-dashoffset: 145; }
        .result-circle.success { stroke: var(--success); }
        .result-circle.failure { stroke: var(--danger); }
        .result-check, .result-cross { fill: none; stroke-width: 4; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 50; stroke-dashoffset: 50; }
        .result-check { stroke: var(--success); }
        .result-cross { stroke: var(--danger); }
        @keyframes iconPop { 0% { transform: scale(0.4); opacity: 0; } 60% { transform: scale(1.1); opacity: 1; } 100% { transform: scale(1); opacity: 1; } }
        @keyframes drawPath { to { stroke-dashoffset: 0; } }
        @keyframes shakeX { 0%, 100% { transform: translateX(0); } 20% { transform: translateX(-6px); } 40% { transform: translateX(6px); } 60% { transform: translateX(-4px); } 80% { transform: translateX(4px); } }
        .result-icon-anim { animation: iconPop 0.3s ease-out forwards; }
        .result-icon-anim .result-circle { animation: drawPath 0.4s ease-out forwards; }
        .result-icon-anim .result-check, .result-icon-anim .result-cross { animation: drawPath 0.25s 0.3s ease-out forwards; }
        .result-icon-anim.shake-fail { animation: iconPop 0.3s ease-out forwards, shakeX 0.4s 0.35s ease-in-out; }

        /* ---------- toast ---------- */
        #toastStack { position: fixed; top: 18px; right: 18px; z-index: 100; display: flex; flex-direction: column; gap: 10px; max-width: 320px; }
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
            <div class="name">Student Dashboard</div>
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

    <?php if (!$has_face_profile): ?>
        <div class="enroll-nudge">
            <span>You haven't enrolled your face yet. You must enroll before you can check in.</span>
            <a href="face_enroll.php" class="pill-btn">Enroll Now</a>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head">
            <h2>Check in to a session</h2>
            <?php if ($has_face_profile): ?>
                <span class="chip chip-success">✅ Face Enrolled</span>
            <?php else: ?>
                <span class="chip chip-warning">⚠️ Not Enrolled</span>
            <?php endif; ?>
        </div>
        <p class="lede">Make sure you're connected to your lecturer's WiFi hotspot before checking in.</p>

        <form method="POST" action="student_dashboard.php" id="checkinForm">
            <div class="checkin-row">
                <input type="text" name="join_code" id="join_code"
                       placeholder="Enter 6-digit code" maxlength="6" required <?= !$has_face_profile ? "disabled" : "" ?>>
                <button type="submit" id="checkinBtn" class="btn-success" <?= !$has_face_profile ? "disabled" : "" ?>>Check In</button>
            </div>
            <input type="hidden" name="face_verified" id="face_verified" value="0">
        </form>

        <div id="sessionCard" class="alert alert-primary" style="display:none;">
            <span id="sessionCardText"></span>
            <span id="sessionCountdown" class="fw-bold"></span>
        </div>

        <div id="faceCheckSection" style="display:none;">
            <div id="facePrompt" class="alert alert-info">
                Now position your face in the frame, then tap "Start Verification" below.
            </div>
            <button type="button" id="startVerifyBtn" class="btn-primary" style="margin-bottom: 10px;">Start Verification</button>

            <div id="modelLoadRing" style="display:none;">
                <div class="progress-ring-wrap">
                    <svg width="76" height="76" viewBox="0 0 76 76">
                        <defs>
                            <linearGradient id="studentRingGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="var(--blue-400)"/>
                                <stop offset="100%" stop-color="var(--amber-400)"/>
                            </linearGradient>
                        </defs>
                        <circle class="progress-ring-bg" cx="38" cy="38" r="34" stroke-width="6" fill="none"/>
                        <circle id="progressRingCircle" class="progress-ring-fg" cx="38" cy="38" r="34" stroke-width="6" fill="none"
                                stroke-dasharray="213.6" stroke-dashoffset="213.6" transform="rotate(-90 38 38)"/>
                    </svg>
                    <div class="progress-ring-label"><span id="progressRingPct">0%</span></div>
                </div>
            </div>

            <div class="face-video-wrap" style="display:none;" id="checkinVideoWrap">
                <video id="checkinVideo" width="320" height="240" autoplay muted playsinline webkit-playsinline></video>
                <svg class="face-oval-overlay" viewBox="0 0 320 240" preserveAspectRatio="none">
                    <ellipse id="checkinOval" cx="160" cy="120" rx="85" ry="105"></ellipse>
                </svg>
                <div id="resultOverlay" class="result-overlay" style="display:none;">
                    <svg id="resultSuccessGroup" class="result-icon-anim" viewBox="0 0 52 52" width="72" height="72" style="display:none;">
                        <circle class="result-circle success" cx="26" cy="26" r="23"/>
                        <path class="result-check" d="M14 27l7 7 16-16"/>
                    </svg>
                    <svg id="resultFailureGroup" class="result-icon-anim" viewBox="0 0 52 52" width="72" height="72" style="display:none;">
                        <circle class="result-circle failure" cx="26" cy="26" r="23"/>
                        <path class="result-cross" d="M16 16 L36 36"/>
                        <path class="result-cross" d="M36 16 L16 36"/>
                    </svg>
                </div>
            </div>
            <div id="faceStatus" class="alert alert-info" style="display:none;">Loading...</div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Your recent attendance</h2>
            <?php if ($has_filters): ?>
                <a href="student_dashboard.php" class="btn-ghost">Clear Filters</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($student_courses)): ?>
            <form method="GET" action="student_dashboard.php" class="filter-row" style="margin-top:16px;">
                <select name="filter_course">
                    <option value="0">All Courses</option>
                    <?php foreach ($student_courses as $sc): ?>
                        <option value="<?= (int)$sc['course_id'] ?>" <?= $filter_course_id === (int)$sc['course_id'] ? "selected" : "" ?>>
                            <?= htmlspecialchars($sc['course_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="filter_from" value="<?= htmlspecialchars($filter_date_from) ?>" title="From date">
                <input type="date" name="filter_to" value="<?= htmlspecialchars($filter_date_to) ?>" title="To date">
                <button type="submit">Filter</button>
            </form>
        <?php endif; ?>

        <?php if (empty($history)): ?>
            <p class="muted"><?= $has_filters ? "No attendance records match your filters." : "No attendance records yet." ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Course</th><th>Marked At</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars($h['course_code']) ?></td>
                                <td><?= date("M j, g:i A", strtotime($h['marked_at'])) ?></td>
                                <td>
                                    <?php if ($h['status'] === 'present'): ?>
                                        <span class="chip chip-success">Present</span>
                                    <?php else: ?>
                                        <span class="chip chip-warning">Flagged</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$has_filters && count($history) >= 10): ?>
                <p class="muted small" style="margin-top:10px;">Showing your 10 most recent records. Use the filters above to see more.</p>
            <?php endif; ?>
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

const MODEL_URL = "assets/facelib/models";
let modelsLoaded = false;
let modelsLoadingPromise = null;
let faceAlreadyVerified = false;

/* ---------------------------------------------------------
   Progress ring (shown only if models aren't already cached/loaded
   by the time the student taps "Start Verification")
--------------------------------------------------------- */
const RING_CIRCUMFERENCE = 2 * Math.PI * 34; // matches r="34" on the ring circle

function setRingProgress(pct) {
    const circle = document.getElementById("progressRingCircle");
    const label = document.getElementById("progressRingPct");
    if (!circle || !label) return;
    const clamped = Math.min(100, Math.max(0, pct));
    const offset = RING_CIRCUMFERENCE - (clamped / 100) * RING_CIRCUMFERENCE;
    circle.style.strokeDashoffset = offset;
    label.textContent = Math.round(clamped) + "%";
}

async function loadStepWithRing(promiseFn, fromPct, toPct) {
    let current = fromPct;
    const ceiling = fromPct + (toPct - fromPct) * 0.9;
    const tick = setInterval(() => {
        current += (ceiling - current) * 0.08;
        setRingProgress(current);
    }, 120);
    try {
        await promiseFn();
    } finally {
        clearInterval(tick);
    }
    setRingProgress(toPct);
}

// Preload models silently as soon as the dashboard opens — only if student has a face profile.
function preloadModels() {
    modelsLoadingPromise = (async () => {
        await loadStepWithRing(() => faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL), 0, 8);
        await loadStepWithRing(() => faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL), 8, 20);
        await loadStepWithRing(() => faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL), 20, 100);
        modelsLoaded = true;
    })();
}

<?php if ($has_face_profile): ?>
window.addEventListener('load', preloadModels);
<?php endif; ?>

function showResultOverlay(type) {
    const overlay = document.getElementById("resultOverlay");
    const successGroup = document.getElementById("resultSuccessGroup");
    const failureGroup = document.getElementById("resultFailureGroup");

    overlay.style.display = "flex";

    const activeGroup = type === "success" ? successGroup : failureGroup;
    const inactiveGroup = type === "success" ? failureGroup : successGroup;

    inactiveGroup.style.display = "none";
    activeGroup.style.display = "block";

    activeGroup.classList.remove("result-icon-anim", "shake-fail");
    void activeGroup.offsetWidth;
    activeGroup.classList.add("result-icon-anim");
    if (type === "failure") activeGroup.classList.add("shake-fail");

    return new Promise(resolve => setTimeout(() => {
        overlay.style.display = "none";
        resolve();
    }, 900));
}

function evaluateFacePosition(detection, video) {
    const box = detection.detection.box;
    const faceWidthRatio = box.width / video.videoWidth;
    const centerX = box.x + box.width / 2;
    const centerY = box.y + box.height / 2;
    const offsetXRatio = Math.abs(centerX - video.videoWidth / 2) / video.videoWidth;
    const offsetYRatio = Math.abs(centerY - video.videoHeight / 2) / video.videoHeight;

    if (faceWidthRatio < 0.22) return { ok: false, message: "Move closer to the camera." };
    if (faceWidthRatio > 0.65) return { ok: false, message: "Move back a little." };
    if (offsetXRatio > 0.18) return { ok: false, message: "Center your face horizontally." };
    if (offsetYRatio > 0.18) return { ok: false, message: "Center your face vertically." };
    return { ok: true, message: "Good position — hold still..." };
}

async function runFaceVerification() {
    const section = document.getElementById("faceCheckSection");
    const statusEl = document.getElementById("faceStatus");
    const ringWrap = document.getElementById("modelLoadRing");
    section.style.display = "block";
    statusEl.className = "alert alert-info";

    if (!modelsLoaded) {
        ringWrap.style.display = "block";
        statusEl.textContent = "Loading face models...";
    } else {
        statusEl.textContent = "Starting camera...";
    }

    try {
        if (!modelsLoadingPromise) preloadModels();
        await modelsLoadingPromise;
    } catch (err) {
        ringWrap.style.display = "none";
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Failed to load face models. Please refresh the page and try again.";
        return false;
    }
    ringWrap.style.display = "none";

    const videoWrap = document.getElementById("checkinVideoWrap");
    const video = document.getElementById("checkinVideo");
    const ovalEl = document.getElementById("checkinOval");
    videoWrap.style.display = "inline-block";
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } });
    } catch (err) {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Camera access denied or unavailable.";
        return false;
    }
    video.srcObject = stream;
    video.muted = true;

    try {
        await video.play();
    } catch (playErr) {
        console.warn("video.play() failed:", playErr);
    }

    statusEl.textContent = "Position your face in the frame...";

    await new Promise(resolve => {
        video.onloadedmetadata = () => resolve();
    });

    let stableGoodCount = 0;
    let detection = null;
    const maxAttempts = 40;
    let attempts = 0;

    videoWrap.classList.add("scanning-active");

    while (attempts < maxAttempts) {
        attempts++;
        let d = null;
        try {
            d = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions());
        } catch (frameErr) {
            console.warn("Face detection error on this frame:", frameErr);
            d = null;
        }

        if (!d) {
            statusEl.className = "alert alert-secondary";
            statusEl.textContent = "No face detected — make sure your face is visible.";
            stableGoodCount = 0;
            ovalEl.classList.remove("oval-good", "oval-bad");
        } else {
            const status = evaluateFacePosition(d, video);
            statusEl.className = status.ok ? "alert alert-success" : "alert alert-secondary";
            statusEl.textContent = status.message;
            if (status.ok) {
                ovalEl.classList.add("oval-good");
                ovalEl.classList.remove("oval-bad");
                stableGoodCount++;
                if (stableGoodCount >= 3) {
                    detection = d;
                    break;
                }
            } else {
                ovalEl.classList.add("oval-bad");
                ovalEl.classList.remove("oval-good");
                stableGoodCount = 0;
            }
        }
        await new Promise(resolve => setTimeout(resolve, 300));
    }

    videoWrap.classList.remove("scanning-active");

    if (!detection) {
        stream.getTracks().forEach(track => track.stop());
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Could not get a stable face position. Please try again.";
        await showResultOverlay("failure");
        return false;
    }

    statusEl.className = "alert alert-info";
    statusEl.textContent = "Capturing...";

    let fullDetection = null;
    try {
        fullDetection = await faceapi
            .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks()
            .withFaceDescriptor();
    } catch (captureErr) {
        console.warn("Final capture detection error:", captureErr);
        stream.getTracks().forEach(track => track.stop());
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Something went wrong capturing your face. Please try again.";
        return false;
    }

    stream.getTracks().forEach(track => track.stop());

    if (!fullDetection) {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "No face detected. Please try again.";
        await showResultOverlay("failure");
        return false;
    }

    const descriptorArray = Array.from(fullDetection.descriptor);

    let result;
    try {
        const response = await fetch("verify_face.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ descriptor: descriptorArray })
        });
        result = await response.json();
    } catch (err) {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Error contacting server. Please try again.";
        await showResultOverlay("failure");
        return false;
    }

    if (result.match) {
        statusEl.className = "alert alert-success";
        statusEl.textContent = "Face verified! Submitting attendance...";
        await showResultOverlay("success");
        return true;
    } else {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = result.message || "Face verification failed.";
        await showResultOverlay("failure");
        return false;
    }
}

const checkinForm = document.getElementById("checkinForm");
const startVerifyBtn = document.getElementById("startVerifyBtn");
const sessionCard = document.getElementById("sessionCard");
const sessionCardText = document.getElementById("sessionCardText");
const sessionCountdown = document.getElementById("sessionCountdown");

let countdownInterval = null;

function stopCountdown() {
    if (countdownInterval) {
        clearInterval(countdownInterval);
        countdownInterval = null;
    }
}

function startCountdown(secondsRemaining) {
    stopCountdown();
    let remaining = secondsRemaining;

    function render() {
        if (remaining <= 0) {
            sessionCountdown.textContent = "Closed";
            sessionCountdown.className = "fw-bold text-danger";
            stopCountdown();
            showToast("This session has just closed. Please check with your lecturer.", "danger");
            document.getElementById("checkinBtn").disabled = false;
            document.getElementById("join_code").disabled = false;
            document.getElementById("faceCheckSection").style.display = "none";
            return;
        }
        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;
        sessionCountdown.textContent = "Closes in " + mins + ":" + String(secs).padStart(2, "0");
        sessionCountdown.className = remaining <= 60 ? "fw-bold text-danger" : "fw-bold";
        remaining--;
    }

    render();
    countdownInterval = setInterval(render, 1000);
}

if (checkinForm) {
    checkinForm.addEventListener("submit", async function(e) {
        if (faceAlreadyVerified) return;
        e.preventDefault();

        const btn = document.getElementById("checkinBtn");
        const joinCodeInput = document.getElementById("join_code");
        const joinCode = joinCodeInput.value.trim();

        if (!joinCode) return;

        btn.disabled = true;
        btn.textContent = "Checking...";

        try {
            const response = await fetch("session_lookup.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "join_code=" + encodeURIComponent(joinCode)
            });
            const result = await response.json();

            btn.textContent = "Check In";

            if (!result.success) {
                btn.disabled = false;
                showToast(result.message || "Invalid join code.", "danger");
                return;
            }

            joinCodeInput.disabled = true;
            btn.disabled = true;

            sessionCardText.textContent = result.course_code + " — " + result.course_title;
            sessionCard.style.display = "flex";
            startCountdown(result.seconds_remaining);

            document.getElementById("faceCheckSection").style.display = "block";
            document.getElementById("facePrompt").style.display = "block";
            startVerifyBtn.style.display = "inline-block";
            startVerifyBtn.disabled = false;
            startVerifyBtn.textContent = "Start Verification";
        } catch (err) {
            btn.disabled = false;
            btn.textContent = "Check In";
            showToast("Could not reach the server. Please try again.", "danger");
        }
    });
}

if (startVerifyBtn) {
    startVerifyBtn.addEventListener("click", async function() {
        startVerifyBtn.disabled = true;
        startVerifyBtn.textContent = "Verifying...";
        document.getElementById("facePrompt").style.display = "none";
        document.getElementById("checkinVideo").style.display = "block";
        document.getElementById("faceStatus").style.display = "block";

        const verified = await runFaceVerification();

        if (verified) {
            document.getElementById("face_verified").value = "1";
            faceAlreadyVerified = true;
            stopCountdown();
            checkinForm.submit();
        } else {
            document.getElementById("facePrompt").style.display = "block";
            startVerifyBtn.disabled = false;
            startVerifyBtn.textContent = "Try Again";
        }
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