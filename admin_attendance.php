<?php
$required_role = 'admin';
require_once "auth_check.php";

$error = "";
$success = "";

function bind_params_dynamic($stmt, $types, $params) {
    $refs = [];
    foreach ($params as $key => $value) { $refs[$key] = &$params[$key]; }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Close a session
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["close_session"])) {
    $sid = (int)($_POST["session_id"] ?? 0);
    $st = $conn->prepare("UPDATE attendance_sessions SET status = 'closed' WHERE session_id = ?");
    $st->bind_param("i", $sid);
    $st->execute();
    $st->close();
    $success = "Session closed.";
}

// Delete a session and all of its attendance records
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_session"])) {
    $sid = (int)($_POST["session_id"] ?? 0);
    $conn->begin_transaction();
    try {
        $d1 = $conn->prepare("DELETE FROM attendance_records WHERE session_id = ?");
        $d1->bind_param("i", $sid);
        $d1->execute();
        $d1->close();
        $d2 = $conn->prepare("DELETE FROM attendance_sessions WHERE session_id = ?");
        $d2->bind_param("i", $sid);
        $d2->execute();
        $d2->close();
        $conn->commit();
        $success = "Session deleted, with its attendance records.";
    } catch (Throwable $e) {
        $conn->rollback();
        $error = "Could not delete the session. Please try again.";
    }
}

// Remove one student's attendance record
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_record"])) {
    $rid = (int)($_POST["record_id"] ?? 0);
    $st = $conn->prepare("DELETE FROM attendance_records WHERE record_id = ?");
    $st->bind_param("i", $rid);
    $st->execute();
    $st->close();
    $success = "Attendance record removed.";
}

// Filters
$filter_course = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$filter_status = $_GET['status'] ?? 'all';
if (!in_array($filter_status, ['all', 'active', 'closed'], true)) { $filter_status = 'all'; }
$view = isset($_GET['view']) ? (int)$_GET['view'] : 0;

function qs_att($over = []) {
    $base = ['course_id' => $_GET['course_id'] ?? '', 'status' => $_GET['status'] ?? '', 'view' => $_GET['view'] ?? ''];
    $m = array_merge($base, $over);
    return http_build_query(array_filter($m, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0'));
}

$course_list = [];
$cl = $conn->query("SELECT course_id, course_code, course_title FROM courses ORDER BY course_code");
while ($row = $cl->fetch_assoc()) { $course_list[] = $row; }

$where = [];
$params = [];
$types = "";
if ($filter_course > 0) { $where[] = "s.course_id = ?"; $params[] = $filter_course; $types .= "i"; }
if ($filter_status !== 'all') { $where[] = "s.status = ?"; $params[] = $filter_status; $types .= "s"; }
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

$sql = "SELECT s.session_id, s.join_code, s.session_date, s.start_time, s.end_time, s.status,
               c.course_code, c.course_title, u.full_name AS lecturer_name,
               (SELECT COUNT(*) FROM attendance_records r WHERE r.session_id = s.session_id) AS present_count
        FROM attendance_sessions s
        JOIN courses c ON s.course_id = c.course_id
        LEFT JOIN users u ON s.lecturer_id = u.user_id
        $where_sql
        ORDER BY s.session_id DESC
        LIMIT 100";
$stmt = $conn->prepare($sql);
if ($params) { bind_params_dynamic($stmt, $types, $params); }
$stmt->execute();
$sessions = [];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $sessions[] = $row; }
$stmt->close();

// Records of the session being viewed
$view_session = null;
$view_records = [];
if ($view > 0) {
    $vs = $conn->prepare(
        "SELECT s.session_id, s.session_date, c.course_code, c.course_title
         FROM attendance_sessions s JOIN courses c ON s.course_id = c.course_id
         WHERE s.session_id = ?"
    );
    $vs->bind_param("i", $view);
    $vs->execute();
    $view_session = $vs->get_result()->fetch_assoc();
    $vs->close();
    if ($view_session) {
        $vr = $conn->prepare(
            "SELECT r.record_id, r.marked_at, r.ip_address, r.face_match_score, r.status, u.full_name, u.matric_number
             FROM attendance_records r JOIN users u ON r.student_id = u.user_id
             WHERE r.session_id = ? ORDER BY r.marked_at"
        );
        $vr->bind_param("i", $view);
        $vr->execute();
        $rr = $vr->get_result();
        while ($row = $rr->fetch_assoc()) { $view_records[] = $row; }
        $vr->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance · AttendX</title>
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
            <a href="admin_dashboard.php">
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
            <a href="admin_attendance.php" class="active">
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
                <h1>Manage Attendance</h1>
                <p>Review sessions, close them, and remove wrong records.</p>
            </div>
            <div class="dash-topbar-right">
                <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
                </button>
            </div>
        </div>

        <div class="content">
            <?php if ($error !== ""): ?>
                <div class="alert alert-danger"><span style="margin-right:auto;"><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>
            <?php if ($success !== ""): ?>
                <div class="alert alert-success"><span style="margin-right:auto;"><?= htmlspecialchars($success) ?></span></div>
            <?php endif; ?>

            <div class="panel" style="padding-bottom:20px;">
                <form method="GET" action="admin_attendance.php" class="form-grid cols-session">
                    <div>
                        <label>Course</label>
                        <select name="course_id">
                            <option value="0">All courses</option>
                            <?php foreach ($course_list as $c): ?>
                                <option value="<?= (int)$c['course_id'] ?>" <?= $filter_course === (int)$c['course_id'] ? "selected" : "" ?>>
                                    <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="all" <?= $filter_status === 'all' ? "selected" : "" ?>>All</option>
                            <option value="active" <?= $filter_status === 'active' ? "selected" : "" ?>>Active</option>
                            <option value="closed" <?= $filter_status === 'closed' ? "selected" : "" ?>>Closed</option>
                        </select>
                    </div>
                    <div class="span-2"><button type="submit" class="btn btn-primary">Filter</button></div>
                </form>
            </div>

            <?php if ($view > 0): ?>
                <div class="panel" style="padding-bottom:10px;">
                    <?php if (!$view_session): ?>
                        <p class="muted" style="padding-bottom:16px;">That session could not be found.</p>
                    <?php else: ?>
                        <div class="panel-head">
                            <h3><?= htmlspecialchars($view_session['course_code']) ?> — <?= date("M j, Y", strtotime($view_session['session_date'])) ?> (<?= count($view_records) ?> present)</h3>
                            <a href="admin_attendance.php?<?= qs_att(['view' => '']) ?>" class="btn btn-outline btn-sm">Close</a>
                        </div>
                        <?php if (empty($view_records)): ?>
                            <p class="muted" style="padding-bottom:16px;">No attendance has been recorded for this session.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Student</th><th>Matric Number</th><th>Time</th><th>IP Address</th><th>Face Distance</th><th>Status</th><th></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($view_records as $rec): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($rec['full_name']) ?></td>
                                                <td><?= htmlspecialchars($rec['matric_number'] ?? '') ?></td>
                                                <td><?= date("g:i:s A", strtotime($rec['marked_at'])) ?></td>
                                                <td><?= htmlspecialchars($rec['ip_address']) ?></td>
                                                <td><?= $rec['face_match_score'] !== null ? number_format((float)$rec['face_match_score'], 3) : '<span class="muted">—</span>' ?></td>
                                                <td><span class="chip <?= $rec['status'] === 'present' ? 'chip-success' : 'chip-warning' ?>"><?= $rec['status'] === 'present' ? 'Present' : 'Flagged' ?></span></td>
                                                <td>
                                                    <form method="POST" action="admin_attendance.php?<?= qs_att() ?>" class="js-confirm"
                                                          data-confirm="<?= htmlspecialchars('Remove the attendance record of ' . $rec['full_name'] . '?', ENT_QUOTES) ?>">
                                                        <input type="hidden" name="record_id" value="<?= (int)$rec['record_id'] ?>">
                                                        <button type="submit" name="delete_record" class="btn-outline-danger">Remove</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="panel" style="padding-bottom:10px;">
                <div class="panel-head"><h3>Sessions (latest 100)</h3></div>
                <?php if (empty($sessions)): ?>
                    <p class="muted" style="padding-bottom:16px;">No sessions match this filter.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Course</th><th>Lecturer</th><th>Date and time</th><th>Code</th><th>Present</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($sessions as $s): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($s['course_code']) ?><div class="muted small"><?= htmlspecialchars($s['course_title']) ?></div></td>
                                        <td><?= $s['lecturer_name'] ? htmlspecialchars($s['lecturer_name']) : '<span class="muted">—</span>' ?></td>
                                        <td><?= date("M j, Y", strtotime($s['session_date'])) ?><div class="muted small"><?= date("g:i A", strtotime($s['start_time'])) ?> – <?= date("g:i A", strtotime($s['end_time'])) ?></div></td>
                                        <td><?= htmlspecialchars($s['join_code'] ?? '') ?></td>
                                        <td><?= (int)$s['present_count'] ?></td>
                                        <td><span class="chip <?= $s['status'] === 'active' ? 'chip-success' : 'chip-secondary' ?>"><?= $s['status'] === 'active' ? 'Active' : 'Closed' ?></span></td>
                                        <td>
                                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                                <a class="btn-icon" style="text-decoration:none;" href="admin_attendance.php?<?= qs_att(['view' => (int)$s['session_id']]) ?>">View</a>
                                                <?php if ($s['status'] === 'active'): ?>
                                                    <form method="POST" action="admin_attendance.php?<?= qs_att() ?>" class="js-confirm"
                                                          data-confirm="Close this session now? Students will no longer be able to check in.">
                                                        <input type="hidden" name="session_id" value="<?= (int)$s['session_id'] ?>">
                                                        <button type="submit" name="close_session" class="btn-icon">Close</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" action="admin_attendance.php?<?= qs_att() ?>" class="js-confirm"
                                                      data-confirm="<?= htmlspecialchars('Delete this session and its ' . (int)$s['present_count'] . ' attendance record(s)? This cannot be undone.', ENT_QUOTES) ?>">
                                                    <input type="hidden" name="session_id" value="<?= (int)$s['session_id'] ?>">
                                                    <button type="submit" name="delete_session" class="btn-outline-danger">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
<script>
document.querySelectorAll("form.js-confirm").forEach(function (f) {
    f.addEventListener("submit", function (e) {
        if (!confirm(f.dataset.confirm)) { e.preventDefault(); }
    });
});

<?php if ($error): ?>
document.addEventListener("DOMContentLoaded", function () { showToast(<?= json_encode($error) ?>, "danger"); });
<?php endif; ?>
<?php if ($success): ?>
document.addEventListener("DOMContentLoaded", function () { showToast(<?= json_encode($success) ?>, "success"); });
<?php endif; ?>
</script>
</body>
</html>
