<?php
$required_role = 'admin';
require_once "auth_check.php";

$error = "";
$success = "";
$last_action = "";

function lecturer_exists($conn, $id) {
    $s = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'lecturer'");
    $s->bind_param("i", $id);
    $s->execute();
    $s->store_result();
    $ok = $s->num_rows > 0;
    $s->close();
    return $ok;
}

// Add a course and assign it to a lecturer
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_course"])) {
    $last_action = "add_course";
    $code  = trim($_POST["course_code"] ?? "");
    $title = trim($_POST["course_title"] ?? "");
    $unit  = (int)($_POST["course_unit"] ?? 0);
    $lid   = (int)($_POST["lecturer_id"] ?? 0);
    if ($code === "" || $title === "") {
        $error = "Course code and title are required.";
    } elseif ($unit < 1 || $unit > 6) {
        $error = "Course unit must be between 1 and 6.";
    } elseif (!lecturer_exists($conn, $lid)) {
        $error = "Please choose a lecturer for this course.";
    } else {
        $ins = $conn->prepare("INSERT INTO courses (course_code, course_title, course_unit, lecturer_id) VALUES (?, ?, ?, ?)");
        $ins->bind_param("ssii", $code, $title, $unit, $lid);
        if ($ins->execute()) {
            $success = "Course " . $code . " added.";
        } else {
            $error = "Could not add the course. Please try again.";
        }
        $ins->close();
    }
}

// Edit a course (and reassign it to another lecturer if needed)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["edit_course"])) {
    $last_action = "edit_course";
    $cid   = (int)($_POST["course_id"] ?? 0);
    $code  = trim($_POST["course_code"] ?? "");
    $title = trim($_POST["course_title"] ?? "");
    $unit  = (int)($_POST["course_unit"] ?? 0);
    $lid   = (int)($_POST["lecturer_id"] ?? 0);
    if ($cid <= 0 || $code === "" || $title === "") {
        $error = "Course code and title are required.";
    } elseif ($unit < 1 || $unit > 6) {
        $error = "Course unit must be between 1 and 6.";
    } elseif (!lecturer_exists($conn, $lid)) {
        $error = "Please choose a lecturer for this course.";
    } else {
        $conn->begin_transaction();
        try {
            $up = $conn->prepare("UPDATE courses SET course_code = ?, course_title = ?, course_unit = ?, lecturer_id = ? WHERE course_id = ?");
            $up->bind_param("ssiii", $code, $title, $unit, $lid, $cid);
            $up->execute();
            $up->close();
            // keep the course's sessions with the same lecturer
            $us = $conn->prepare("UPDATE attendance_sessions SET lecturer_id = ? WHERE course_id = ?");
            $us->bind_param("ii", $lid, $cid);
            $us->execute();
            $us->close();
            $conn->commit();
            $success = "Course " . $code . " updated.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = "Could not update the course. Please try again.";
        }
    }
}

// Delete a course together with its sessions and attendance records
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_course"])) {
    $last_action = "delete_course";
    $cid = (int)($_POST["course_id"] ?? 0);
    $cs = $conn->prepare("SELECT course_code FROM courses WHERE course_id = ?");
    $cs->bind_param("i", $cid);
    $cs->execute();
    $crow = $cs->get_result()->fetch_assoc();
    $cs->close();
    if (!$crow) {
        $error = "That course no longer exists.";
    } else {
        $conn->begin_transaction();
        try {
            $d1 = $conn->prepare("DELETE r FROM attendance_records r JOIN attendance_sessions s ON r.session_id = s.session_id WHERE s.course_id = ?");
            $d1->bind_param("i", $cid);
            $d1->execute();
            $d1->close();
            $d2 = $conn->prepare("DELETE FROM attendance_sessions WHERE course_id = ?");
            $d2->bind_param("i", $cid);
            $d2->execute();
            $d2->close();
            $d3 = $conn->prepare("DELETE FROM courses WHERE course_id = ?");
            $d3->bind_param("i", $cid);
            $d3->execute();
            $d3->close();
            $conn->commit();
            $success = "Course " . $crow['course_code'] . " removed, with its sessions and attendance records.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = "Could not delete the course. Please try again.";
        }
    }
}

// Lecturers for the dropdowns
$lecturers = [];
$lr = $conn->query("SELECT user_id, full_name FROM users WHERE role = 'lecturer' ORDER BY full_name");
while ($row = $lr->fetch_assoc()) { $lecturers[] = $row; }

// All courses in the system
$courses = [];
$cr = $conn->query(
    "SELECT c.course_id, c.course_code, c.course_title, c.course_unit, c.lecturer_id,
            u.full_name AS lecturer_name,
            (SELECT COUNT(*) FROM attendance_sessions s WHERE s.course_id = c.course_id) AS session_count,
            (SELECT COUNT(*) FROM attendance_records r JOIN attendance_sessions s2 ON r.session_id = s2.session_id
              WHERE s2.course_id = c.course_id) AS record_count
     FROM courses c
     LEFT JOIN users u ON c.lecturer_id = u.user_id
     ORDER BY c.course_code"
);
while ($row = $cr->fetch_assoc()) { $courses[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses · AttendX</title>
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
            <a href="admin_courses.php" class="active">
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
                <h1>Manage Courses</h1>
                <p>Add, edit, reassign or remove any course in the system.</p>
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

            <div style="margin-bottom:14px;">
                <button type="button" class="btn btn-primary btn-sm" id="toggleAddCourse">+ Add Course</button>
            </div>

            <div class="panel add-panel" id="addCoursePanel" style="padding-bottom:20px;">
                <div class="panel-head"><h3>Add a course</h3></div>
                <?php if (empty($lecturers)): ?>
                    <p class="muted">Create a lecturer account on the Users page first.</p>
                <?php else: ?>
                <form method="POST" action="admin_courses.php" class="form-grid cols-session">
                    <div><label>Course Code</label><input type="text" name="course_code" placeholder="e.g. CSC401" required></div>
                    <div><label>Course Title</label><input type="text" name="course_title" placeholder="e.g. Software Engineering" required></div>
                    <div><label>Unit</label><input type="number" name="course_unit" min="1" max="6" value="3" required></div>
                    <div>
                        <label>Lecturer</label>
                        <select name="lecturer_id" required>
                            <option value="" selected disabled>-- Select a lecturer --</option>
                            <?php foreach ($lecturers as $l): ?>
                                <option value="<?= (int)$l['user_id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="span-2"><button type="submit" name="add_course" class="btn btn-primary">Add Course</button></div>
                </form>
                <?php endif; ?>
            </div>

            <div class="panel add-panel" id="editCoursePanel" style="padding-bottom:20px;">
                <div class="panel-head"><h3>Edit <span id="editTargetName">course</span></h3></div>
                <form method="POST" action="admin_courses.php" class="form-grid cols-session">
                    <input type="hidden" name="course_id" id="editCourseId" value="">
                    <div><label>Course Code</label><input type="text" name="course_code" id="editCode" required></div>
                    <div><label>Course Title</label><input type="text" name="course_title" id="editTitle" required></div>
                    <div><label>Unit</label><input type="number" name="course_unit" id="editUnit" min="1" max="6" required></div>
                    <div>
                        <label>Lecturer</label>
                        <select name="lecturer_id" id="editLecturer" required>
                            <?php foreach ($lecturers as $l): ?>
                                <option value="<?= (int)$l['user_id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="span-2" style="display:flex; gap:10px;">
                        <button type="submit" name="edit_course" class="btn btn-primary">Save Changes</button>
                        <button type="button" class="btn btn-outline" id="cancelEditBtn">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="panel" style="padding-bottom:10px;">
                <div class="panel-head"><h3>All courses (<?= count($courses) ?>)</h3></div>
                <?php if (empty($courses)): ?>
                    <p class="muted" style="padding-bottom:16px;">No courses have been added yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Course</th><th>Unit</th><th>Lecturer</th><th>Sessions</th><th>Records</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($courses as $c): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($c['course_code']) ?>
                                            <div class="muted small"><?= htmlspecialchars($c['course_title']) ?></div>
                                        </td>
                                        <td><?= (int)$c['course_unit'] ?></td>
                                        <td><?= $c['lecturer_name'] ? htmlspecialchars($c['lecturer_name']) : '<span class="muted">—</span>' ?></td>
                                        <td><?= (int)$c['session_count'] ?></td>
                                        <td><?= (int)$c['record_count'] ?></td>
                                        <td>
                                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                                <button type="button" class="btn-icon js-edit-course"
                                                        data-id="<?= (int)$c['course_id'] ?>"
                                                        data-code="<?= htmlspecialchars($c['course_code'], ENT_QUOTES) ?>"
                                                        data-title="<?= htmlspecialchars($c['course_title'], ENT_QUOTES) ?>"
                                                        data-unit="<?= (int)$c['course_unit'] ?>"
                                                        data-lecturer="<?= (int)$c['lecturer_id'] ?>">Edit</button>
                                                <form method="POST" action="admin_courses.php" class="js-confirm"
                                                      data-confirm="<?= htmlspecialchars('Delete ' . $c['course_code'] . '? Its ' . (int)$c['session_count'] . ' session(s) and ' . (int)$c['record_count'] . ' attendance record(s) will be deleted too. This cannot be undone.', ENT_QUOTES) ?>">
                                                    <input type="hidden" name="course_id" value="<?= (int)$c['course_id'] ?>">
                                                    <button type="submit" name="delete_course" class="btn-outline-danger">Delete</button>
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
const addCP = document.getElementById("addCoursePanel");
const editCP = document.getElementById("editCoursePanel");
document.getElementById("toggleAddCourse").addEventListener("click", function () {
    editCP.classList.remove("open");
    addCP.classList.toggle("open");
});
document.querySelectorAll(".js-edit-course").forEach(function (btn) {
    btn.addEventListener("click", function () {
        addCP.classList.remove("open");
        document.getElementById("editCourseId").value = btn.dataset.id;
        document.getElementById("editCode").value = btn.dataset.code;
        document.getElementById("editTitle").value = btn.dataset.title;
        document.getElementById("editUnit").value = btn.dataset.unit;
        document.getElementById("editLecturer").value = btn.dataset.lecturer;
        document.getElementById("editTargetName").textContent = btn.dataset.code;
        editCP.classList.add("open");
        editCP.scrollIntoView({ behavior: "smooth", block: "center" });
    });
});
document.getElementById("cancelEditBtn").addEventListener("click", function () { editCP.classList.remove("open"); });
<?php if ($last_action === "add_course" && $error !== ""): ?>
addCP.classList.add("open");
<?php endif; ?>
<?php if ($error): ?>
document.addEventListener("DOMContentLoaded", function () { showToast(<?= json_encode($error) ?>, "danger"); });
<?php endif; ?>
<?php if ($success): ?>
document.addEventListener("DOMContentLoaded", function () { showToast(<?= json_encode($success) ?>, "success"); });
<?php endif; ?>
</script>
</body>
</html>
