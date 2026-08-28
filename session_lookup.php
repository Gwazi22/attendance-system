<?php
// session_lookup.php
// Lightweight preview endpoint: given a join code, returns the course name
// and remaining time on the session, WITHOUT performing WiFi, face, or
// duplicate-attendance checks (those still only happen on actual submit
// in student_dashboard.php). Used purely to power the countdown display
// before a student starts face verification.

$required_role = 'student';
require_once "auth_check.php";

header('Content-Type: application/json');

$join_code = trim($_POST["join_code"] ?? "");

if (empty($join_code)) {
    echo json_encode(["success" => false, "message" => "Please enter a join code."]);
    exit;
}

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
    echo json_encode(["success" => false, "message" => "Invalid join code. Please check with your lecturer."]);
    exit;
}

$session = $result->fetch_assoc();
$stmt->close();

$now = time();
$start = strtotime($session['start_time']);
$end   = strtotime($session['end_time']);

if ($session['status'] !== 'active') {
    echo json_encode(["success" => false, "message" => "This session has been closed by the lecturer."]);
    exit;
}

if ($now < $start) {
    echo json_encode(["success" => false, "message" => "This session hasn't opened yet."]);
    exit;
}

if ($now > $end) {
    // Keep the DB in sync, same as the proactive close-on-load elsewhere
    $conn->query("UPDATE attendance_sessions SET status = 'closed' WHERE session_id = " . intval($session['session_id']));
    echo json_encode(["success" => false, "message" => "This session is no longer open for check-in."]);
    exit;
}

echo json_encode([
    "success" => true,
    "course_code" => $session['course_code'],
    "course_title" => $session['course_title'],
    "seconds_remaining" => $end - $now
]);
?>