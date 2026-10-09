<?php
$required_role = 'student';
require_once "auth_check.php";
require_once "wifi_config.php";

$student_id = $_SESSION["user_id"];
$error = "";
$success = "";
$last_checkin = null; // set on a successful check-in, used to render the Success screen

function get_client_ip() {
    // Use only the real connection address. Request headers such as
    // X-Forwarded-For can be edited by the client, so they are ignored.
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
    // Face verification is confirmed from the SERVER-side session (set by
    // verify_face.php), valid for 5 minutes, not from a browser form field.
    $face_verified = isset($_SESSION["face_verified_at"]) && (time() - $_SESSION["face_verified_at"]) <= 300;

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
                        $face_score = $_SESSION["face_score"] ?? null;
                        $insert = $conn->prepare(
                            "INSERT INTO attendance_records (session_id, student_id, ip_address, face_match_score, status)
                             VALUES (?, ?, ?, ?, 'present')"
                        );
                        $insert->bind_param("iisd", $session['session_id'], $student_id, $student_ip, $face_score);

                        if ($insert->execute()) {
                            // One verification = one check-in
                            unset($_SESSION["face_verified_at"], $_SESSION["face_score"]);
                            $success = "You're marked present for " . $session['course_code'] . " — " . $session['course_title'] . ".";
                            $last_checkin = [
                                'course_code'  => $session['course_code'],
                                'course_title' => $session['course_title'],
                                'time'         => date('Y-m-d H:i:s'),
                                'status'       => 'present',
                            ];
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

$show_success = ($success !== "" && $last_checkin !== null);

// --- Profile info (email, matric number) for the Profile tab -----------
$profile = ['email' => '', 'matric_number' => ''];
$pstmt = $conn->prepare("SELECT email, matric_number FROM users WHERE user_id = ?");
$pstmt->bind_param("i", $student_id);
$pstmt->execute();
$prow = $pstmt->get_result()->fetch_assoc();
if ($prow) {
    $profile = $prow;
}
$pstmt->close();

// --- Courses tab: distinct courses this student has checked into, with a
// check-in count and the most recent check-in date. ---------------------
$my_courses = [];
$mc_stmt = $conn->prepare(
    "SELECT c.course_id, c.course_code, c.course_title, COUNT(*) AS checkins, MAX(r.marked_at) AS last_seen
     FROM attendance_records r
     JOIN attendance_sessions s ON r.session_id = s.session_id
     JOIN courses c ON s.course_id = c.course_id
     WHERE r.student_id = ?
     GROUP BY c.course_id, c.course_code, c.course_title
     ORDER BY c.course_code"
);
$mc_stmt->bind_param("i", $student_id);
$mc_stmt->execute();
$mc_result = $mc_stmt->get_result();
while ($row = $mc_result->fetch_assoc()) {
    $my_courses[] = $row;
}
$mc_stmt->close();

// --- Attendance history filters (course + date range) -----------------
// Uses GET so results are bookmarkable/shareable and don't interfere with
// the POST check-in form above.
$filter_course_id = isset($_GET['filter_course']) ? intval($_GET['filter_course']) : 0;
$filter_date_from = $_GET['filter_from'] ?? '';
$filter_date_to   = $_GET['filter_to'] ?? '';
$has_filters = ($filter_course_id > 0) || !empty($filter_date_from) || !empty($filter_date_to);

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
$history_sql .= $has_filters ? " LIMIT 200" : " LIMIT 50";

$history = [];
$stmt = $conn->prepare($history_sql);
bind_params_dynamic($stmt, $history_types, $history_params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();

$recent_history = array_slice($history, 0, 3);

$hour = (int)date("G");
$greeting = $hour < 12 ? "Good morning" : ($hour < 17 ? "Good afternoon" : "Good evening");
$first_name = trim(explode(" ", $_SESSION["full_name"])[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $show_success ? "Attendance Recorded" : "Dashboard" ?> · AttendX</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css">
    <script src="assets/js/theme.js"></script>
    <script defer src="assets/facelib/face-api.min.js"></script>
    <script defer src="assets/js/face-engine.js"></script>
</head>
<body>

<div class="field-bg" aria-hidden="true">
    <div class="glow a"></div>
    <div class="glow b"></div>
    <div class="grid"></div>
</div>

<div id="toastStack"></div>

<?php if ($show_success): ?>

    <!-- ===================== SUCCESS SCREEN ===================== -->
    <div class="mobile-shell" style="padding-bottom: 30px;">
        <div class="mobile-topbar">
            <div class="brand-lockup">
                <div class="logo-mark size-sm">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 2.5 L21.5 20.5 H2.5 L12 2.5Z" fill="white"/>
                        <path d="M8.7 14.3 L11 16.8 L15.3 10.1" stroke="var(--blue-600)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    </svg>
                </div>
                <div class="wordmark size-sm">AttendX</div>
            </div>
            <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
            </button>
        </div>

        <div class="success-screen">
            <div class="success-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
            </div>
            <h2>Attendance Recorded!</h2>
            <p class="lede">You've successfully marked your attendance for <?= htmlspecialchars($last_checkin['course_code']) ?>.</p>

            <div class="success-card">
                <div class="profile-row">
                    <span class="p-label">Course</span>
                    <span class="p-value"><?= htmlspecialchars($last_checkin['course_code']) ?> — <?= htmlspecialchars($last_checkin['course_title']) ?></span>
                </div>
                <div class="profile-row">
                    <span class="p-label">Time</span>
                    <span class="p-value"><?= date("g:i A", strtotime($last_checkin['time'])) ?></span>
                </div>
                <div class="profile-row">
                    <span class="p-label">Date</span>
                    <span class="p-value"><?= date("M j, Y", strtotime($last_checkin['time'])) ?></span>
                </div>
                <div class="profile-row">
                    <span class="p-label">Status</span>
                    <span class="chip chip-success">Present</span>
                </div>
            </div>

            <a href="student_dashboard.php" class="btn btn-primary w-full" style="max-width:340px;">Back to Dashboard</a>
        </div>
    </div>

<?php else: ?>

    <!-- ===================== MAIN DASHBOARD ===================== -->
    <div class="mobile-shell">
        <div class="mobile-topbar">
            <div>
                <div class="hello"><?= $greeting ?>, <?= htmlspecialchars($first_name) ?></div>
                <p class="sub">Keep showing up. It matters.</p>
            </div>
            <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
            </button>
        </div>

        <div class="mobile-content">

            <!-- ---------- HOME TAB ---------- -->
            <div class="tab-panel active" id="tab-home">

                <?php if (!$has_face_profile): ?>
                    <div class="alert alert-danger" style="align-items:flex-start;">
                        <span style="margin-right:auto;">You haven't enrolled your face yet. Enroll before you can check in.</span>
                        <a href="face_enroll.php" class="btn btn-sm btn-primary" style="flex-shrink:0;">Enroll</a>
                    </div>
                <?php endif; ?>

                <div class="next-class-card <?= $has_face_profile ? '' : 'idle' ?>">
                    <?php if ($has_face_profile): ?>
                        <div class="label">Ready when you are</div>
                        <div class="course">Mark your attendance</div>
                        <div class="time">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                            Enter the join code your lecturer shares in class
                        </div>
                    <?php else: ?>
                        <div class="label">Face enrollment required</div>
                        <div class="course">Enroll your face to get started</div>
                        <div class="time">Head to Profile → Enroll Face</div>
                    <?php endif; ?>
                </div>

                <div class="quick-grid">
                    <button type="button" class="quick-card" id="qaMarkAttendance" <?= !$has_face_profile ? "disabled" : "" ?>>
                        <div class="q-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><circle cx="12" cy="13.5" r="3.2"/></svg></div>
                        <div class="q-label">Mark Attendance</div>
                        <div class="q-sub">Join your active session</div>
                    </button>
                    <button type="button" class="quick-card" data-tab="courses">
                        <div class="q-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></div>
                        <div class="q-label">My Courses</div>
                        <div class="q-sub">View enrolled courses</div>
                    </button>
                    <button type="button" class="quick-card" data-tab="attendance">
                        <div class="q-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="m8.5 14 2 2 4-4"/></svg></div>
                        <div class="q-label">My Attendance</div>
                        <div class="q-sub">View your records</div>
                    </button>
                    <button type="button" class="quick-card" data-tab="profile">
                        <div class="q-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 13.5a7.4 7.4 0 0 0 0-3l2-1.6-2-3.4-2.4.8a7.6 7.6 0 0 0-2.6-1.5L14 2h-4l-.4 2.4a7.6 7.6 0 0 0-2.6 1.5l-2.4-.8-2 3.4 2 1.6a7.4 7.4 0 0 0 0 3l-2 1.6 2 3.4 2.4-.8a7.6 7.6 0 0 0 2.6 1.5L10 22h4l.4-2.4a7.6 7.6 0 0 0 2.6-1.5l2.4.8 2-3.4-2-1.6Z"/></svg></div>
                        <div class="q-label">Settings</div>
                        <div class="q-sub">Manage your profile</div>
                    </button>
                </div>

                <div class="section-head">
                    <h3>Recent Attendance</h3>
                    <button type="button" class="btn-link" data-tab="attendance">View All</button>
                </div>
                <?php if (empty($recent_history)): ?>
                    <p class="muted small" style="padding-bottom:16px;">No attendance recorded yet.</p>
                <?php else: ?>
                    <div class="list-card">
                        <?php foreach ($recent_history as $h): ?>
                            <div class="list-row">
                                <div class="meta">
                                    <div class="title"><?= htmlspecialchars($h['course_code']) ?></div>
                                    <div class="sub"><?= date("M j, g:i A", strtotime($h['marked_at'])) ?></div>
                                </div>
                                <span class="chip <?= $h['status'] === 'present' ? 'chip-success' : 'chip-warning' ?>"><?= $h['status'] === 'present' ? 'Present' : 'Flagged' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ---------- COURSES TAB ---------- -->
            <div class="tab-panel" id="tab-courses">
                <div class="section-head"><h3>My Courses</h3></div>
                <?php if (empty($my_courses)): ?>
                    <p class="muted small">You haven't checked into any courses yet.</p>
                <?php else: ?>
                    <div class="list-card">
                        <?php foreach ($my_courses as $mc): ?>
                            <div class="course-card">
                                <div class="c-icon"><?= htmlspecialchars(substr($mc['course_code'], 0, 2)) ?></div>
                                <div style="flex:1; min-width:0;">
                                    <div class="c-title"><?= htmlspecialchars($mc['course_code']) ?> — <?= htmlspecialchars($mc['course_title']) ?></div>
                                    <div class="c-sub"><?= (int)$mc['checkins'] ?> check-in<?= (int)$mc['checkins'] === 1 ? '' : 's' ?> · last <?= date("M j", strtotime($mc['last_seen'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ---------- ATTENDANCE TAB ---------- -->
            <div class="tab-panel" id="tab-attendance">
                <div class="section-head">
                    <h3>Attendance History</h3>
                    <button type="button" class="btn btn-sm btn-primary" id="qaMarkAttendance2" <?= !$has_face_profile ? "disabled" : "" ?>>+ Mark Attendance</button>
                </div>

                <?php if (!empty($my_courses)): ?>
                    <form method="GET" action="student_dashboard.php#tab-attendance" class="form-grid cols-session" style="margin-bottom:16px;">
                        <div>
                            <select name="filter_course">
                                <option value="0">All Courses</option>
                                <?php foreach ($my_courses as $mc): ?>
                                    <option value="<?= (int)$mc['course_id'] ?>" <?= $filter_course_id === (int)$mc['course_id'] ? "selected" : "" ?>>
                                        <?= htmlspecialchars($mc['course_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div></div>
                        <div>
                            <input type="date" name="filter_from" value="<?= htmlspecialchars($filter_date_from) ?>" title="From date">
                        </div>
                        <div>
                            <input type="date" name="filter_to" value="<?= htmlspecialchars($filter_date_to) ?>" title="To date">
                        </div>
                        <div class="span-2">
                            <button type="submit" class="btn btn-outline btn-sm w-full">Filter</button>
                        </div>
                    </form>
                    <?php if ($has_filters): ?>
                        <a href="student_dashboard.php#tab-attendance" class="btn-link small" style="display:inline-block; margin-bottom:12px;">Clear filters</a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (empty($history)): ?>
                    <p class="muted small"><?= $has_filters ? "No attendance records match your filters." : "No attendance records yet." ?></p>
                <?php else: ?>
                    <div class="list-card">
                        <?php foreach ($history as $h): ?>
                            <div class="list-row">
                                <div class="meta">
                                    <div class="title"><?= htmlspecialchars($h['course_code']) ?></div>
                                    <div class="sub"><?= date("M j, g:i A", strtotime($h['marked_at'])) ?></div>
                                </div>
                                <span class="chip <?= $h['status'] === 'present' ? 'chip-success' : 'chip-warning' ?>"><?= $h['status'] === 'present' ? 'Present' : 'Flagged' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ---------- PROFILE TAB ---------- -->
            <div class="tab-panel" id="tab-profile">
                <div class="section-head"><h3>Profile</h3></div>

                <div class="profile-card">
                    <div class="profile-row">
                        <span class="p-label">Full Name</span>
                        <span class="p-value"><?= htmlspecialchars($_SESSION["full_name"]) ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="p-label">Matric Number</span>
                        <span class="p-value"><?= htmlspecialchars($profile['matric_number'] ?? '—') ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="p-label">Email</span>
                        <span class="p-value"><?= htmlspecialchars($profile['email'] ?? '—') ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="p-label">Face Enrollment</span>
                        <span class="chip <?= $has_face_profile ? 'chip-success' : 'chip-warning' ?>"><?= $has_face_profile ? 'Enrolled' : 'Not Enrolled' ?></span>
                    </div>
                </div>

                <div class="profile-card">
                    <a href="face_enroll.php" class="profile-row clickable" style="text-decoration:none; color:inherit;">
                        <span class="p-value" style="font-weight:600;"><?= $has_face_profile ? 'Re-enroll Face' : 'Enroll Face' ?></span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                    <div class="profile-row clickable" id="resetPasswordRow">
                        <span class="p-value" style="font-weight:600;">Change Password</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                    </div>
                </div>

                <div id="resetPasswordNotice" class="alert alert-info" style="display:none;">
                    <span style="margin-right:auto;">Passwords can't be changed here — ask your system administrator to reset it for you.</span>
                </div>

                <a href="logout.php" class="btn btn-outline w-full">Logout</a>
            </div>

        </div>
    </div>

    <!-- bottom tab bar -->
    <nav class="bottom-tabs">
        <button type="button" class="tab-btn active" data-tab="home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
            Home
        </button>
        <button type="button" class="tab-btn" data-tab="courses">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
            Courses
        </button>
        <button type="button" class="tab-btn" data-tab="attendance">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
            Attendance
        </button>
        <button type="button" class="tab-btn" data-tab="profile">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
            Profile
        </button>
    </nav>

    <!-- ===================== MARK ATTENDANCE WIZARD ===================== -->
    <div class="wizard-overlay" id="wizardOverlay">
        <div class="wizard-head">
            <button type="button" class="wizard-back" id="wizardBackBtn" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <h2>Mark Attendance</h2>
        </div>

        <div class="wizard-steps">
            <div class="wizard-step active" data-step="1"><div class="dot">1</div><div class="label">Details</div></div>
            <div class="wizard-connector"></div>
            <div class="wizard-step" data-step="2"><div class="dot">2</div><div class="label">Face</div></div>
            <div class="wizard-connector"></div>
            <div class="wizard-step" data-step="3"><div class="dot">3</div><div class="label">Confirm</div></div>
        </div>

        <form method="POST" action="student_dashboard.php" id="checkinForm" class="wizard-body">
            <!-- Step 1: Details -->
            <div class="wizard-step-panel active" data-step-panel="1">
                <div class="field-group">
                    <label for="join_code">Join Code</label>
                    <input type="text" name="join_code" id="join_code" placeholder="Enter 6-digit code" maxlength="6" autocomplete="off">
                    <span class="caption" style="display:block; margin-top:8px;">Make sure you're connected to your lecturer's WiFi hotspot before continuing.</span>
                </div>
                <button type="button" id="wizardContinueBtn" class="btn btn-primary w-full">Continue</button>
            </div>

            <!-- Step 2: Face -->
            <div class="wizard-step-panel" data-step-panel="2">
                <div id="sessionSummary" class="alert alert-info" style="display:none;">
                    <span id="sessionSummaryText" style="margin-right:auto;"></span>
                    <span id="sessionCountdown" class="fw-bold"></span>
                </div>

                <p class="caption" style="text-align:center; margin-bottom:10px;">Look at the camera and hold still.</p>

                <div style="text-align:center;">
                    <div id="modelLoadRing" style="display:none;">
                        <div class="progress-ring-wrap">
                            <svg width="76" height="76" viewBox="0 0 76 76">
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
                </div>

                <div id="faceStatus" class="alert alert-info" style="display:none;"><span style="margin-right:auto;">Loading...</span></div>
                <button type="button" id="startVerifyBtn" class="btn btn-primary w-full">Verify Face</button>
            </div>

            <!-- Step 3: Confirm -->
            <div class="wizard-step-panel" data-step-panel="3">
                <div class="success-card" style="margin: 10px auto 24px;">
                    <div class="profile-row">
                        <span class="p-label">Course</span>
                        <span class="p-value" id="confirmCourse">—</span>
                    </div>
                    <div class="profile-row">
                        <span class="p-label">Matric Number</span>
                        <span class="p-value"><?= htmlspecialchars($profile['matric_number'] ?? '—') ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="p-label">Face</span>
                        <span class="chip chip-success">Verified</span>
                    </div>
                </div>
                <button type="button" id="confirmAttendanceBtn" class="btn btn-success w-full">Confirm Attendance</button>
            </div>

            <input type="hidden" name="face_verified" id="face_verified" value="0">
        </form>
    </div>

<?php endif; ?>

<script>
<?php if (!$show_success): ?>
/* ---------------------------------------------------------
   Bottom tab navigation
--------------------------------------------------------- */
function activateTab(tabName) {
    document.querySelectorAll(".tab-panel").forEach(p => p.classList.toggle("active", p.id === "tab-" + tabName));
    document.querySelectorAll(".bottom-tabs .tab-btn").forEach(b => b.classList.toggle("active", b.dataset.tab === tabName));
}
document.querySelectorAll(".bottom-tabs .tab-btn").forEach(btn => {
    btn.addEventListener("click", () => activateTab(btn.dataset.tab));
});
document.querySelectorAll("[data-tab]:not(.tab-btn)").forEach(el => {
    el.addEventListener("click", () => activateTab(el.dataset.tab));
});

/* ---------------------------------------------------------
   Change Password row — no self-service reset; point to admin
--------------------------------------------------------- */
const resetRow = document.getElementById("resetPasswordRow");
const resetNotice = document.getElementById("resetPasswordNotice");
if (resetRow) {
    resetRow.addEventListener("click", function () {
        resetNotice.style.display = resetNotice.style.display === "none" ? "flex" : "none";
    });
}

/* ---------------------------------------------------------
   Mark Attendance wizard
--------------------------------------------------------- */
const wizardOverlay = document.getElementById("wizardOverlay");
const wizardBackBtn = document.getElementById("wizardBackBtn");
const checkinForm = document.getElementById("checkinForm");
let currentWizardStep = 1;
let faceAlreadyVerified = false;
let activeSessionInfo = null;

function openWizard() {
    wizardOverlay.classList.add("open");
    setWizardStep(1);
}
function closeWizard() {
    wizardOverlay.classList.remove("open");
}

const markBtn1 = document.getElementById("qaMarkAttendance");
const markBtn2 = document.getElementById("qaMarkAttendance2");
[markBtn1, markBtn2].forEach(btn => {
    if (btn) btn.addEventListener("click", function () {
        if (btn.disabled) return;
        openWizard();
    });
});
wizardBackBtn.addEventListener("click", closeWizard);

function setWizardStep(n) {
    currentWizardStep = n;
    document.querySelectorAll(".wizard-step").forEach(s => {
        const step = parseInt(s.dataset.step, 10);
        s.classList.toggle("active", step === n);
        s.classList.toggle("done", step < n);
    });
    document.querySelectorAll(".wizard-step-panel").forEach(p => {
        p.classList.toggle("active", parseInt(p.dataset.stepPanel, 10) === n);
    });
}

/* ---------------------------------------------------------
   Step 1 → Step 2: look up the join code
--------------------------------------------------------- */
let countdownInterval = null;
function stopCountdown() {
    if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
}
function startCountdown(secondsRemaining) {
    stopCountdown();
    let remaining = secondsRemaining;
    const el = document.getElementById("sessionCountdown");
    function render() {
        if (remaining <= 0) {
            el.textContent = "Closed";
            el.className = "fw-bold text-danger";
            stopCountdown();
            showToast("This session has just closed. Please check with your lecturer.", "danger");
            return;
        }
        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;
        el.textContent = "Closes in " + mins + ":" + String(secs).padStart(2, "0");
        el.className = remaining <= 60 ? "fw-bold text-danger" : "fw-bold";
        remaining--;
    }
    render();
    countdownInterval = setInterval(render, 1000);
}

const wizardContinueBtn = document.getElementById("wizardContinueBtn");
wizardContinueBtn.addEventListener("click", async function () {
    const joinCodeInput = document.getElementById("join_code");
    const joinCode = joinCodeInput.value.trim();
    if (!joinCode) { showToast("Enter the join code your lecturer shared.", "danger"); return; }

    wizardContinueBtn.disabled = true;
    wizardContinueBtn.textContent = "Checking...";

    try {
        const response = await fetch("session_lookup.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "join_code=" + encodeURIComponent(joinCode)
        });
        const result = await response.json();

        wizardContinueBtn.disabled = false;
        wizardContinueBtn.textContent = "Continue";

        if (!result.success) {
            showToast(result.message || "Invalid join code.", "danger");
            return;
        }

        activeSessionInfo = result;
        document.getElementById("sessionSummary").style.display = "flex";
        document.getElementById("sessionSummaryText").textContent = result.course_code + " — " + result.course_title;
        document.getElementById("confirmCourse").textContent = result.course_code + " — " + result.course_title;
        startCountdown(result.seconds_remaining);

        setWizardStep(2);
    } catch (err) {
        wizardContinueBtn.disabled = false;
        wizardContinueBtn.textContent = "Continue";
        showToast("Could not reach the server. Please try again.", "danger");
    }
});

/* ---------------------------------------------------------
   Step 2: face verification (model loading, live guidance, capture)
--------------------------------------------------------- */
const MODEL_URL = "assets/facelib/models";
let modelsLoaded = false;
let modelsLoadingPromise = null;

const RING_CIRCUMFERENCE = 2 * Math.PI * 34;
function setRingProgress(pct) {
    const circle = document.getElementById("progressRingCircle");
    const label = document.getElementById("progressRingPct");
    if (!circle || !label) return;
    const clamped = Math.min(100, Math.max(0, pct));
    circle.style.strokeDashoffset = RING_CIRCUMFERENCE - (clamped / 100) * RING_CIRCUMFERENCE;
    label.textContent = Math.round(clamped) + "%";
}
async function loadStepWithRing(promiseFn, fromPct, toPct) {
    let current = fromPct;
    const ceiling = fromPct + (toPct - fromPct) * 0.9;
    const tick = setInterval(() => { current += (ceiling - current) * 0.08; setRingProgress(current); }, 120);
    try { await promiseFn(); } finally { clearInterval(tick); }
    setRingProgress(toPct);
}
function preloadModels() {
    modelsLoadingPromise = (async () => {
        await loadStepWithRing(() => faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL), 0, 8);
        await loadStepWithRing(() => faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL), 8, 20);
        await loadStepWithRing(() => faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL), 20, 100);
        await FaceEngine.prepare();
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
    return new Promise(resolve => setTimeout(() => { overlay.style.display = "none"; resolve(); }, 900));
}

function evaluateFacePosition(detection, video) {
    const box = detection.box || detection.detection.box;
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
    const statusEl = document.getElementById("faceStatus");
    const ringWrap = document.getElementById("modelLoadRing");
    statusEl.style.display = "flex";
    statusEl.className = "alert alert-info";
    statusEl.querySelector("span").textContent = modelsLoaded ? "Starting camera..." : "Loading face models...";
    if (!modelsLoaded) ringWrap.style.display = "block";

    try {
        if (!modelsLoadingPromise) preloadModels();
        await Promise.race([
            modelsLoadingPromise,
            new Promise((_, reject) => setTimeout(() => reject(new Error("model load timeout")), 90000))
        ]);
    } catch (err) {
        ringWrap.style.display = "none";
        statusEl.className = "alert alert-danger";
        statusEl.querySelector("span").textContent = "Failed to load face models. Please refresh and try again.";
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
        statusEl.querySelector("span").textContent = "Camera access denied or unavailable.";
        return false;
    }
    video.srcObject = stream;
    video.muted = true;
    try {
        // play() can stay pending on some devices, so never wait on it forever
        await Promise.race([video.play(), new Promise(resolve => setTimeout(resolve, 5000))]);
    } catch (playErr) { console.warn("video.play() failed:", playErr); }

    statusEl.querySelector("span").textContent = "Position your face in the frame...";
    // The browser has usually fired "loadedmetadata" already while we awaited play().
    // Waiting for an event that already happened never finishes (the page then
    // sits on this message), so check the video state directly instead.
    for (let i = 0; i < 50 && (video.readyState < 2 || !video.videoWidth); i++) {
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    if (!video.videoWidth) {
        stream.getTracks().forEach(track => track.stop());
        statusEl.className = "alert alert-danger";
        statusEl.querySelector("span").textContent = "The camera did not start. Close other apps using the camera and try again.";
        return false;
    }

    let stableGoodCount = 0;
    let detection = null;
    const maxAttempts = 40;
    let attempts = 0;
    videoWrap.classList.add("scanning-active");

    while (attempts < maxAttempts) {
        attempts++;
        let d = null;
        try { d = await FaceEngine.detect(video, 320); }
        catch (frameErr) { console.warn("Face detection error on this frame:", frameErr); d = null; }

        if (!d) {
            statusEl.className = "alert alert-info";
            statusEl.querySelector("span").textContent = "No face detected — make sure your face is visible.";
            stableGoodCount = 0;
            ovalEl.classList.remove("oval-good", "oval-bad");
        } else {
            const status = evaluateFacePosition(d, video);
            statusEl.className = status.ok ? "alert alert-success" : "alert alert-info";
            statusEl.querySelector("span").textContent = status.message;
            if (status.ok) {
                ovalEl.classList.add("oval-good"); ovalEl.classList.remove("oval-bad");
                stableGoodCount++;
                if (stableGoodCount >= 3) { detection = d; break; }
            } else {
                ovalEl.classList.add("oval-bad"); ovalEl.classList.remove("oval-good");
                stableGoodCount = 0;
            }
        }
        await new Promise(resolve => setTimeout(resolve, 300));
    }
    videoWrap.classList.remove("scanning-active");

    if (!detection) {
        stream.getTracks().forEach(track => track.stop());
        statusEl.className = "alert alert-danger";
        statusEl.querySelector("span").textContent = "Could not get a stable face position. Please try again.";
        await showResultOverlay("failure");
        return false;
    }

    statusEl.className = "alert alert-info";
    statusEl.querySelector("span").textContent = "Capturing...";

    let captured = null;
    try {
        captured = await FaceEngine.captureAverage(video, 3, function (n, total) {
            statusEl.querySelector("span").textContent = "Capturing " + n + " of " + total + "... hold still";
        });
    } catch (captureErr) {
        console.warn("Final capture error:", captureErr);
        stream.getTracks().forEach(track => track.stop());
        statusEl.className = "alert alert-danger";
        statusEl.querySelector("span").textContent = "Face capture is too slow on this device. Please try again.";
        return false;
    }
    stream.getTracks().forEach(track => track.stop());

    if (!captured || captured.count < 2) {
        statusEl.className = "alert alert-danger";
        statusEl.querySelector("span").textContent = "No clear face detected. Please try again.";
        await showResultOverlay("failure");
        return false;
    }

    const descriptorArray = captured.descriptor;
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
        statusEl.querySelector("span").textContent = "Error contacting server. Please try again.";
        await showResultOverlay("failure");
        return false;
    }

    if (result.match) {
        statusEl.className = "alert alert-success";
        statusEl.querySelector("span").textContent = "Face verified!";
        await showResultOverlay("success");
        return true;
    } else {
        statusEl.className = "alert alert-danger";
        statusEl.querySelector("span").textContent = result.message || "Face verification failed.";
        await showResultOverlay("failure");
        return false;
    }
}

const startVerifyBtn = document.getElementById("startVerifyBtn");
startVerifyBtn.addEventListener("click", async function () {
    startVerifyBtn.disabled = true;
    startVerifyBtn.textContent = "Verifying...";
    document.getElementById("checkinVideo").style.display = "block";

    let verified = false;
    try {
        verified = await runFaceVerification();
    } catch (err) {
        console.error("Face verification error:", err);
        showToast("Face verification stopped unexpectedly. Please try again.", "danger");
    }

    if (verified) {
        faceAlreadyVerified = true;
        document.getElementById("face_verified").value = "1";
        setWizardStep(3);
    }
    startVerifyBtn.disabled = false;
    startVerifyBtn.textContent = "Verify Face";
});

/* ---------------------------------------------------------
   Step 3: confirm + submit
--------------------------------------------------------- */
document.getElementById("confirmAttendanceBtn").addEventListener("click", function () {
    if (!faceAlreadyVerified) { showToast("Please verify your face first.", "danger"); return; }
    stopCountdown();
    checkinForm.submit();
});

<?php endif; ?>

<?php if ($error): ?>
document.addEventListener("DOMContentLoaded", function () { showToast(<?= json_encode($error) ?>, "danger"); });
<?php endif; ?>
</script>
</body>
</html>