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

// --- Dashboard-home stats (all derived from real records, nothing fabricated) ---

// Today's sessions for this lecturer
$today_sessions = [];
$stmt = $conn->prepare(
    "SELECT s.session_id, s.start_time, s.end_time, s.status, c.course_code, c.course_title
     FROM attendance_sessions s
     JOIN courses c ON s.course_id = c.course_id
     WHERE s.lecturer_id = ? AND s.session_date = CURDATE()
     ORDER BY s.start_time ASC"
);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $today_sessions[] = $row;
}
$stmt->close();

// Total distinct students who have ever checked into one of this lecturer's sessions
$total_students = 0;
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT r.student_id) AS c
     FROM attendance_records r
     JOIN attendance_sessions s ON r.session_id = s.session_id
     WHERE s.lecturer_id = ?"
);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$total_students = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Attendance rate: share of recorded check-ins marked 'present' rather than 'flagged'
$attendance_rate = null; // null = not enough data yet
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total, SUM(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END) AS present_count
     FROM attendance_records r
     JOIN attendance_sessions s ON r.session_id = s.session_id
     WHERE s.lecturer_id = ?"
);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$rate_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($rate_row && (int)$rate_row['total'] > 0) {
    $attendance_rate = round(((int)$rate_row['present_count'] / (int)$rate_row['total']) * 100);
}

// Most recent attendance records across all of this lecturer's sessions
$recent_records = [];
$stmt = $conn->prepare(
    "SELECT r.marked_at, r.status, u.full_name, c.course_code
     FROM attendance_records r
     JOIN attendance_sessions s ON r.session_id = s.session_id
     JOIN courses c ON s.course_id = c.course_id
     JOIN users u ON r.student_id = u.user_id
     WHERE s.lecturer_id = ?
     ORDER BY r.marked_at DESC
     LIMIT 5"
);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_records[] = $row;
}
$stmt->close();

// Student roster — distinct students derived from attendance history (this system
// has no separate enrolment table; "my students" means "students who have checked
// into at least one of my sessions").
$roster = [];
$stmt = $conn->prepare(
    "SELECT u.full_name, u.matric_number, COUNT(*) AS checkins, MAX(r.marked_at) AS last_seen
     FROM attendance_records r
     JOIN attendance_sessions s ON r.session_id = s.session_id
     JOIN users u ON r.student_id = u.user_id
     WHERE s.lecturer_id = ?
     GROUP BY u.user_id, u.full_name, u.matric_number
     ORDER BY u.full_name
     LIMIT 50"
);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $roster[] = $row;
}
$stmt->close();

$hour = (int)date("G");
$greeting = $hour < 12 ? "Good morning" : ($hour < 17 ? "Good afternoon" : "Good evening");
$first_name = trim(explode(" ", $_SESSION["full_name"])[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard · AttendX</title>
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
            <a href="lecturer_dashboard.php" class="active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>
                Dashboard
            </a>
            <a href="#courses-section">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
                Courses
            </a>
            <a href="#attendance-section">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="m8.5 14 2 2 4-4"/></svg>
                Attendance
            </a>
            <a href="#students-section">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="18" cy="8.5" r="2.3"/><path d="M16.5 14.3c2.6.4 4.5 2.2 4.5 5.1"/></svg>
                Students
            </a>
            <a href="lecturer_reports.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17h4v4H3zM10 10h4v11h-4zM17 4h4v17h-4z"/></svg>
                Reports
            </a>
        </nav>

        <div class="sidebar-foot">
            <div class="sidebar-user">
                <span class="name"><?= htmlspecialchars($_SESSION["full_name"]) ?></span>
                <span class="role">Lecturer</span>
            </div>
            <a href="logout.php" class="btn btn-outline btn-sm w-full">Logout</a>
        </div>
    </aside>

    <div class="main">
        <div class="dash-topbar">
            <div class="greeting">
                <h1><?= $greeting ?>, <?= htmlspecialchars($first_name) ?></h1>
                <p>Here's what's happening with your classes today.</p>
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
                    <div><div class="stat-label">Total Courses</div><div class="stat-value"><?= count($courses) ?></div></div>
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></div>
                </div>
                <div class="stat-card">
                    <div><div class="stat-label">Today's Sessions</div><div class="stat-value"><?= count($today_sessions) ?></div></div>
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg></div>
                </div>
                <div class="stat-card">
                    <div><div class="stat-label">Total Students</div><div class="stat-value"><?= $total_students ?></div></div>
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="18" cy="8.5" r="2.3"/><path d="M16.5 14.3c2.6.4 4.5 2.2 4.5 5.1"/></svg></div>
                </div>
                <div class="stat-card">
                    <div><div class="stat-label">Attendance Rate</div><div class="stat-value"><?= $attendance_rate === null ? "—" : $attendance_rate . "%" ?></div></div>
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17h4v4H3zM10 10h4v11h-4zM17 4h4v17h-4z"/></svg></div>
                </div>
            </div>

            <div class="panel-grid">
                <div class="panel">
                    <div class="panel-head">
                        <h3>Today's Attendance Sessions</h3>
                        <a href="#attendance-section" class="btn-link small">View All</a>
                    </div>
                    <?php if (empty($today_sessions)): ?>
                        <p class="muted small" style="padding-bottom:14px;">No sessions scheduled for today.</p>
                    <?php else: ?>
                        <div class="session-list">
                            <?php foreach ($today_sessions as $ts):
                                $now = time();
                                $start_ts = strtotime($ts['start_time']);
                                $end_ts = strtotime($ts['end_time']);
                                if ($ts['status'] !== 'active') {
                                    $chip = ['Closed', 'chip-secondary'];
                                } elseif ($now < $start_ts) {
                                    $chip = ['Upcoming', 'chip-secondary'];
                                } elseif ($now <= $end_ts) {
                                    $chip = ($end_ts - $now) <= CLOSING_SOON_WINDOW_SECONDS ? ['Closing Soon', 'chip-warning'] : ['Active', 'chip-success'];
                                } else {
                                    $chip = ['Active', 'chip-success'];
                                }
                            ?>
                                <div class="session-row">
                                    <div class="meta">
                                        <div class="course"><?= htmlspecialchars($ts['course_code']) ?> — <?= htmlspecialchars($ts['course_title']) ?></div>
                                        <div class="time"><?= date("g:i A", $start_ts) ?> – <?= date("g:i A", $end_ts) ?></div>
                                    </div>
                                    <span class="chip <?= $chip[1] ?>"><?= $chip[0] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <h3>Recent Attendance</h3>
                        <a href="#students-section" class="btn-link small">View All</a>
                    </div>
                    <?php if (empty($recent_records)): ?>
                        <p class="muted small" style="padding-bottom:14px;">No attendance recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($recent_records as $rr): ?>
                            <div class="record-row">
                                <div class="meta">
                                    <div class="student"><?= htmlspecialchars($rr['full_name']) ?></div>
                                    <div class="course"><?= htmlspecialchars($rr['course_code']) ?> · <?= date("g:i A", strtotime($rr['marked_at'])) ?></div>
                                </div>
                                <span class="chip <?= $rr['status'] === 'present' ? 'chip-success' : 'chip-warning' ?>"><?= $rr['status'] === 'present' ? 'Present' : 'Flagged' ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <h3 id="courses-section" style="margin: 28px 0 14px;">Add a course</h3>
            <div class="panel" style="padding-bottom:20px;">
                <form method="POST" action="lecturer_dashboard.php#courses-section" class="form-grid cols-course">
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
                        <button type="submit" name="add_course" class="btn btn-primary w-full">Add</button>
                    </div>
                </form>
            </div>

            <h3 id="attendance-section" style="margin: 28px 0 14px;">Start a new attendance session</h3>
            <div class="panel" style="padding-bottom:20px;">
                <?php if (empty($courses)): ?>
                    <p class="muted">Add a course above first before opening a session.</p>
                <?php else: ?>
                    <form method="POST" action="lecturer_dashboard.php#attendance-section" class="form-grid cols-session">
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
                            <button type="submit" name="create_session" class="btn btn-primary">Start Session</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="panel" style="padding-bottom:10px;">
                <div class="panel-head"><h3>Your sessions</h3></div>
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
                                    <td><?= date("g:i A", strtotime($s['start_time'])) ?> – <?= date("g:i A", strtotime($s['end_time'])) ?></td>
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
                                            <form method="POST" action="lecturer_dashboard.php#attendance-section" style="display:inline;">
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

            <h3 id="students-section" style="margin: 28px 0 14px;">Your students</h3>
            <div class="panel" style="padding-bottom:10px;">
                <?php if (empty($roster)): ?>
                    <p class="muted">No student check-ins recorded yet. This list fills in as students check into your sessions.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Name</th><th>Matric Number</th><th>Check-ins</th><th>Last Seen</th></tr></thead>
                            <tbody>
                                <?php foreach ($roster as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                                        <td><?= htmlspecialchars($r['matric_number']) ?></td>
                                        <td><?= (int)$r['checkins'] ?></td>
                                        <td><?= date("M j, g:i A", strtotime($r['last_seen'])) ?></td>
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