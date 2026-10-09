<?php
$required_role = 'admin';
require_once "auth_check.php";

// --- System-wide stats (admin sees across all lecturers/courses) ---
$total_students  = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'student'")->fetch_assoc()['c'];
$total_lecturers = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'lecturer'")->fetch_assoc()['c'];
$total_courses   = (int)$conn->query("SELECT COUNT(*) AS c FROM courses")->fetch_assoc()['c'];
$active_sessions = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM attendance_sessions
     WHERE status = 'active' AND CONCAT(session_date, ' ', end_time) >= NOW()"
)->fetch_assoc()['c'];

// Most recent sessions across the whole system
$recent_sessions = [];
$result = $conn->query(
    "SELECT s.session_id, s.session_date, s.start_time, s.end_time, s.status,
            c.course_code, c.course_title, u.full_name AS lecturer_name,
            (SELECT COUNT(*) FROM attendance_records r WHERE r.session_id = s.session_id) AS attendance_count
     FROM attendance_sessions s
     JOIN courses c ON s.course_id = c.course_id
     JOIN users u ON s.lecturer_id = u.user_id
     ORDER BY s.session_id DESC
     LIMIT 6"
);
while ($row = $result->fetch_assoc()) {
    $recent_sessions[] = $row;
}

$hour = (int)date("G");
$greeting = $hour < 12 ? "Good morning" : ($hour < 17 ? "Good afternoon" : "Good evening");
$first_name = trim(explode(" ", $_SESSION["full_name"])[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard · AttendX</title>
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
</div>

<div id="toastStack"></div>

<div class="shell">
    <aside class="sidebar sidebar-dark">
        <div class="sidebar-brand">
            <div class="logo-mark size-sm">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 2.5 L21.5 20.5 H2.5 L12 2.5Z" fill="white"/>
                    <path d="M8.7 14.3 L11 16.8 L15.3 10.1" stroke="var(--blue-600)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </svg>
            </div>
            <div class="wordmark size-sm">AttendX</div>
        </div>

        <nav class="sidebar-nav">
            <a href="admin_dashboard.php" class="active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>
                Dashboard
            </a>
            <a href="admin_users.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="18" cy="8.5" r="2.3"/><path d="M16.5 14.3c2.6.4 4.5 2.2 4.5 5.1"/></svg>
                Users
            </a>
            <a href="admin_courses.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
                Courses
            </a>
            <a href="admin_attendance.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                Attendance
            </a>
            <a href="admin_reports.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17h4v4H3zM10 10h4v11h-4zM17 4h4v17h-4z"/></svg>
                Reports
            </a>
        </nav>

        <div class="sidebar-foot">
            <div class="sidebar-user">
                <span class="name"><?= htmlspecialchars($_SESSION["full_name"]) ?></span>
                <span class="role">System Administrator</span>
            </div>
            <a href="logout.php" class="btn btn-outline btn-sm w-full">Logout</a>
        </div>
    </aside>

    <div class="main">
        <div class="dash-topbar">
            <div class="greeting">
                <h1><?= $greeting ?>, <?= htmlspecialchars($first_name) ?></h1>
                <p>Here's how AttendX looks across the whole system today.</p>
            </div>
            <div class="dash-topbar-right">
                <span class="dash-date"><?= date("D, j M Y") ?></span>
                <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
                </button>
            </div>
        </div>

        <div class="content">

            <div class="stat-grid">
                <div class="stat-card">
                    <div><div class="stat-label">Total Students</div><div class="stat-value"><?= $total_students ?></div></div>
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="18" cy="8.5" r="2.3"/><path d="M16.5 14.3c2.6.4 4.5 2.2 4.5 5.1"/></svg></div>
                </div>
                <div class="stat-card">
                    <div><div class="stat-label">Total Lecturers</div><div class="stat-value"><?= $total_lecturers ?></div></div>
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></div>
                </div>
                <div class="stat-card">
                    <div><div class="stat-label">Total Courses</div><div class="stat-value"><?= $total_courses ?></div></div>
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v4H4zM4 11h16v9H4z"/></svg></div>
                </div>
                <div class="stat-card">
                    <div><div class="stat-label">Active Sessions</div><div class="stat-value"><?= $active_sessions ?></div></div>
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg></div>
                </div>
            </div>

            <div class="panel-grid" style="grid-template-columns: 1.4fr 1fr;">
                <div class="panel">
                    <div class="panel-head">
                        <h3>Recent Sessions (system-wide)</h3>
                    </div>
                    <?php if (empty($recent_sessions)): ?>
                        <p class="muted small" style="padding-bottom:14px;">No sessions have been created yet.</p>
                    <?php else: ?>
                        <div class="session-list">
                            <?php foreach ($recent_sessions as $rs):
                                $chip = $rs['status'] === 'active' ? ['Active', 'chip-success'] : ['Closed', 'chip-secondary'];
                            ?>
                                <div class="session-row">
                                    <div class="meta">
                                        <div class="course"><?= htmlspecialchars($rs['course_code']) ?> — <?= htmlspecialchars($rs['course_title']) ?></div>
                                        <div class="time"><?= htmlspecialchars($rs['lecturer_name']) ?> · <?= date("M j", strtotime($rs['session_date'])) ?> · <?= (int)$rs['attendance_count'] ?> present</div>
                                    </div>
                                    <span class="chip <?= $chip[1] ?>"><?= $chip[0] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-head"><h3>Quick actions</h3></div>
                    <div style="display:flex; flex-direction:column; gap:10px; padding-bottom:16px;">
                        <a href="admin_users.php" class="btn btn-outline w-full" style="justify-content:flex-start;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/></svg>
                            Manage Users
                        </a>
                        <a href="admin_reports.php" class="btn btn-outline w-full" style="justify-content:flex-start;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17h4v4H3zM10 10h4v11h-4zM17 4h4v17h-4z"/></svg>
                            View Reports
                        </a>
                        <a href="admin_courses.php" class="btn btn-outline w-full" style="justify-content:flex-start;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
                            Manage Courses
                        </a>
                        <a href="admin_attendance.php" class="btn btn-outline w-full" style="justify-content:flex-start;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                            Manage Attendance
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

</body>
</html>