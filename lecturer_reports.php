<?php
$required_role = 'lecturer';
require_once "auth_check.php";

// --- Filters: course (required to generate a report) + date range preset ---
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$range     = $_GET['range'] ?? '30';
if (!in_array($range, ['7', '30', '90', 'all'], true)) {
    $range = '30';
}

$lecturer_id = (int)$_SESSION["user_id"];

// Course list for the dropdown: ONLY this lecturer's own courses
$courses = [];
$cl = $conn->prepare("SELECT course_id, course_code, course_title FROM courses WHERE lecturer_id = ? ORDER BY course_code");
$cl->bind_param("i", $lecturer_id);
$cl->execute();
$result = $cl->get_result();
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}
$cl->close();

$report = null;

if ($course_id > 0) {
    // Confirm the course exists AND belongs to this lecturer
    $cstmt = $conn->prepare("SELECT course_code, course_title FROM courses WHERE course_id = ? AND lecturer_id = ?");
    $cstmt->bind_param("ii", $course_id, $lecturer_id);
    $cstmt->execute();
    $course_row = $cstmt->get_result()->fetch_assoc();
    $cstmt->close();

    if ($course_row) {
        // Date boundary for the selected range (applies to session_date)
        $date_clause = "";
        if ($range !== 'all') {
            $days = (int)$range;
            $date_clause = " AND s.session_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)";
        }

        // Roster: every student who has ever checked into ANY session of this course.
        // This system has no separate enrolment table, so "the class roster" is
        // defined operationally as "students with at least one attendance record
        // for this course" (all-time, not limited to the selected range) — the
        // same assumption the lecturer dashboard's student roster uses.
        $roster = [];
        $rstmt = $conn->prepare(
            "SELECT DISTINCT u.user_id, u.full_name, u.matric_number
             FROM attendance_records r
             JOIN attendance_sessions s ON r.session_id = s.session_id
             JOIN users u ON r.student_id = u.user_id
             WHERE s.course_id = ?
             ORDER BY u.full_name"
        );
        $rstmt->bind_param("i", $course_id);
        $rstmt->execute();
        $rresult = $rstmt->get_result();
        while ($row = $rresult->fetch_assoc()) {
            $roster[$row['user_id']] = $row;
        }
        $rstmt->close();

        // Most recent attendance record per roster student WITHIN the selected
        // date range — this is what decides Present vs Absent for the report.
        $present_map = [];
        $pstmt = $conn->prepare(
            "SELECT r.student_id, r.status, MAX(r.marked_at) AS last_marked
             FROM attendance_records r
             JOIN attendance_sessions s ON r.session_id = s.session_id
             WHERE s.course_id = ?" . $date_clause . "
             GROUP BY r.student_id, r.status"
        );
        $pstmt->bind_param("i", $course_id);
        $pstmt->execute();
        $presult = $pstmt->get_result();
        while ($row = $presult->fetch_assoc()) {
            // A student can have both present/flagged rows in range; prefer the
            // most recently marked one as their representative status.
            $existing = $present_map[$row['student_id']] ?? null;
            if (!$existing || strtotime($row['last_marked']) > strtotime($existing['last_marked'])) {
                $present_map[$row['student_id']] = $row;
            }
        }
        $pstmt->close();

        $student_rows = [];
        $present_count = 0;
        foreach ($roster as $uid => $stu) {
            if (isset($present_map[$uid])) {
                $present_count++;
                $student_rows[] = [
                    'full_name'     => $stu['full_name'],
                    'matric_number' => $stu['matric_number'],
                    'status'        => $present_map[$uid]['status'],
                    'time'          => $present_map[$uid]['last_marked'],
                ];
            } else {
                $student_rows[] = [
                    'full_name'     => $stu['full_name'],
                    'matric_number' => $stu['matric_number'],
                    'status'        => 'absent',
                    'time'          => null,
                ];
            }
        }
        // Present/flagged students first (most recently marked), then absent, alphabetical within each
        usort($student_rows, function ($a, $b) {
            if (($a['status'] === 'absent') !== ($b['status'] === 'absent')) {
                return $a['status'] === 'absent' ? 1 : -1;
            }
            return strcmp($a['full_name'], $b['full_name']);
        });

        $total = count($roster);
        $absent_count = $total - $present_count;
        $rate = $total > 0 ? round(($present_count / $total) * 100) : 0;

        $report = [
            'course_code'  => $course_row['course_code'],
            'course_title' => $course_row['course_title'],
            'total'        => $total,
            'present'      => $present_count,
            'absent'       => $absent_count,
            'rate'         => $rate,
            'students'     => $student_rows,
        ];
    }
}

$donut_radius = 60;
$donut_circumference = 2 * M_PI * $donut_radius;
$donut_offset = $report ? $donut_circumference - ($report['rate'] / 100) * $donut_circumference : $donut_circumference;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports · AttendX</title>
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
            <a href="lecturer_dashboard.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>
                Dashboard
            </a>
            <a href="lecturer_dashboard.php#courses-section">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
                Courses
            </a>
            <a href="lecturer_dashboard.php#attendance-section">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="m8.5 14 2 2 4-4"/></svg>
                Attendance
            </a>
            <a href="lecturer_dashboard.php#students-section">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="18" cy="8.5" r="2.3"/><path d="M16.5 14.3c2.6.4 4.5 2.2 4.5 5.1"/></svg>
                Students
            </a>
            <a href="lecturer_reports.php" class="active">
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
                <h1>Attendance Report</h1>
                <p>Pick one of your courses and a date range to see who showed up.</p>
            </div>
            <div class="dash-topbar-right">
                <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
                </button>
            </div>
        </div>

        <div class="content">

            <div class="panel" style="padding-bottom:20px;">
                <form method="GET" action="lecturer_reports.php" class="form-grid cols-session">
                    <div>
                        <label>Course</label>
                        <select name="course_id" required>
                            <option value="" <?= $course_id === 0 ? "selected disabled" : "" ?>>-- Select a course --</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['course_id'] ?>" <?= $course_id === (int)$c['course_id'] ? "selected" : "" ?>>
                                    <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Date Range</label>
                        <select name="range">
                            <option value="7"  <?= $range === '7'  ? "selected" : "" ?>>Last 7 days</option>
                            <option value="30" <?= $range === '30' ? "selected" : "" ?>>Last 30 days</option>
                            <option value="90" <?= $range === '90' ? "selected" : "" ?>>Last 90 days</option>
                            <option value="all" <?= $range === 'all' ? "selected" : "" ?>>All time</option>
                        </select>
                    </div>
                    <div class="span-2">
                        <button type="submit" class="btn btn-primary">Generate Report</button>
                    </div>
                </form>
            </div>

            <?php if ($course_id > 0 && $report === null): ?>
                <div class="alert alert-danger"><span style="margin-right:auto;">That course could not be found.</span></div>
            <?php elseif ($report === null): ?>
                <div class="panel">
                    <p class="muted" style="padding: 10px 0 16px;"><?= empty($courses) ? "You have no courses yet. Add a course on the dashboard first." : "Select a course above and generate a report to see attendance numbers." ?></p>
                </div>
            <?php elseif ($report['total'] === 0): ?>
                <div class="panel">
                    <div class="panel-head"><h3><?= htmlspecialchars($report['course_code']) ?> — <?= htmlspecialchars($report['course_title']) ?></h3></div>
                    <p class="muted" style="padding-bottom:16px;">No students have checked into this course yet.</p>
                </div>
            <?php else: ?>
                <div class="panel">
                    <div class="panel-head"><h3><?= htmlspecialchars($report['course_code']) ?> — <?= htmlspecialchars($report['course_title']) ?></h3></div>

                    <div class="report-summary" style="padding-bottom: 22px;">
                        <div class="report-stat">
                            <div class="stat-label">Total Students</div>
                            <div class="stat-value"><?= $report['total'] ?></div>
                        </div>
                        <div class="report-stat present">
                            <div class="stat-label">Present</div>
                            <div class="stat-value"><?= $report['present'] ?></div>
                        </div>
                        <div class="report-stat absent">
                            <div class="stat-label">Absent</div>
                            <div class="stat-value"><?= $report['absent'] ?></div>
                        </div>
                        <div class="donut-wrap" role="img" aria-label="<?= $report['rate'] ?> percent attendance rate">
                            <svg viewBox="0 0 140 140">
                                <circle class="donut-track" cx="70" cy="70" r="<?= $donut_radius ?>" stroke-width="14"/>
                                <circle class="donut-value" cx="70" cy="70" r="<?= $donut_radius ?>" stroke-width="14"
                                        stroke-dasharray="<?= $donut_circumference ?>"
                                        stroke-dashoffset="<?= $donut_offset ?>"/>
                            </svg>
                            <div class="donut-label">
                                <span class="pct"><?= $report['rate'] ?>%</span>
                                <span class="cap">Attendance</span>
                            </div>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Student Name</th><th>Matric Number</th><th>Status</th><th>Time</th></tr></thead>
                            <tbody>
                                <?php foreach ($report['students'] as $sr): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($sr['full_name']) ?></td>
                                        <td><?= htmlspecialchars($sr['matric_number']) ?></td>
                                        <td>
                                            <?php if ($sr['status'] === 'present'): ?>
                                                <span class="chip chip-success">Present</span>
                                            <?php elseif ($sr['status'] === 'absent'): ?>
                                                <span class="chip chip-danger">Absent</span>
                                            <?php else: ?>
                                                <span class="chip chip-warning">Flagged</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $sr['time'] ? date("M j, g:i A", strtotime($sr['time'])) : '<span class="muted">—</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

</body>
</html>