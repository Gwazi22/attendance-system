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
    <title>Lecturer Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-dark px-3">
    <span class="navbar-brand mb-0 h1"><i class="bi bi-mortarboard-fill me-2"></i>Attendance System — Lecturer</span>
    <div class="d-flex align-items-center">
        <button class="btn btn-outline-light btn-sm me-2" onclick="toggleTheme()" title="Toggle dark mode">
            <span id="themeToggleIcon">🌙</span>
        </button>
        <span class="text-light me-3">Welcome, <?= htmlspecialchars($_SESSION["full_name"]) ?></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
    </div>
</nav>

<div class="container mt-4" style="max-width: 900px;">

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3"><i class="bi bi-journal-plus me-2"></i>Add a Course</h5>
            <form method="POST" action="lecturer_dashboard.php" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-book me-1"></i>Course Code</label>
                    <input type="text" name="course_code" class="form-control" placeholder="e.g. CSC401" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-card-text me-1"></i>Course Title</label>
                    <input type="text" name="course_title" class="form-control" placeholder="e.g. Software Engineering" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="bi bi-hash me-1"></i>Unit</label>
                    <input type="number" name="course_unit" class="form-control" min="1" max="6" value="3" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="add_course" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3"><i class="bi bi-calendar-event me-2"></i>Start a New Attendance Session</h5>

            <?php if (empty($courses)): ?>
                <p class="text-muted mb-0">
                    Add a course above first before opening a session.
                </p>
            <?php else: ?>
                <form method="POST" action="lecturer_dashboard.php" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-journal-bookmark me-1"></i>Course</label>
                        <select name="course_id" class="form-select" required>
                            <option value="" selected disabled>-- Select a course --</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['course_id'] ?>">
                                    <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_title']) ?> (<?= (int)$c['course_unit'] ?> Units)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-calendar3 me-1"></i>Date</label>
                        <input type="date" name="session_date" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-clock me-1"></i>Start Time</label>
                        <input type="time" name="start_time" id="start_time" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-clock-history me-1"></i>End Time</label>
                        <input type="time" name="end_time" id="end_time" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="create_session" class="btn btn-primary"><i class="bi bi-play-fill me-1"></i>Start Session</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3"><i class="bi bi-list-check me-2"></i>Your Sessions</h5>

            <?php if (empty($sessions)): ?>
                <p class="text-muted mb-0">No sessions created yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Unit</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Join Code</th>
                                <th>Status</th>
                                <th>Present</th>
                                <th></th>
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
                                        <div class="d-flex align-items-center gap-2">
                                            <code class="fs-6" id="<?= $join_code_dom_id ?>"><?= htmlspecialchars($s['join_code']) ?></code>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                    onclick="copyElementText('<?= $join_code_dom_id ?>', 'Join code copied!')"
                                                    title="Copy join code">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['status'] === 'active'): ?>
                                        <?php if ($is_closing_soon): ?>
                                            <span class="badge bg-warning text-dark">Closing Soon</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Closed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$s['attendance_count'] ?></td>
                                <td>
                                    <?php if ($s['status'] === 'active'): ?>
                                        <form method="POST" action="lecturer_dashboard.php" class="d-inline">
                                            <input type="hidden" name="session_id" value="<?= $s['session_id'] ?>">
                                            <button type="submit" name="close_session" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-x-circle me-1"></i>Close
                                            </button>
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

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/ui-polish.js"></script>
<script>
document.getElementById('start_time').addEventListener('change', function() {
    document.getElementById('end_time').min = this.value;
});

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