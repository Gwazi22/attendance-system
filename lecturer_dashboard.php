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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lecturer Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark px-3">
    <span class="navbar-brand mb-0 h1">Attendance System — Lecturer</span>
    <div>
        <span class="text-light me-3">Welcome, <?= htmlspecialchars($_SESSION["full_name"]) ?></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container mt-4" style="max-width: 900px;">

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">Add a Course</h5>
            <form method="POST" action="lecturer_dashboard.php" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Course Code</label>
                    <input type="text" name="course_code" class="form-control" placeholder="e.g. CSC401" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Course Title</label>
                    <input type="text" name="course_title" class="form-control" placeholder="e.g. Software Engineering" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Unit</label>
                    <input type="number" name="course_unit" class="form-control" min="1" max="6" value="3" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="add_course" class="btn btn-primary w-100">Add</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">Start a New Attendance Session</h5>

            <?php if (empty($courses)): ?>
                <p class="text-muted mb-0">
                    Add a course above first before opening a session.
                </p>
            <?php else: ?>
                <form method="POST" action="lecturer_dashboard.php" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Course</label>
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
                        <label class="form-label">Date</label>
                        <input type="date" name="session_date" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Start Time</label>
                        <input type="time" name="start_time" id="start_time" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Time</label>
                        <input type="time" name="end_time" id="end_time" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="create_session" class="btn btn-primary">Start Session</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">Your Sessions</h5>

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
                                        <code class="fs-6"><?= htmlspecialchars($s['join_code']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['status'] === 'active'): ?>
                                        <span class="badge bg-success">Active</span>
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
                                                Close
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

<script>
document.getElementById('start_time').addEventListener('change', function() {
    document.getElementById('end_time').min = this.value;
});
</script>
</body>
</html>