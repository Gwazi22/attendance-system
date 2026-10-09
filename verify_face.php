<?php
require_once "config.php";
require_once "auth_check.php";

header("Content-Type: application/json");

// ---- Tunable thresholds -------------------------------------------------
// MATCH_THRESHOLD: max distance to the account owner's own profile.
// DUPLICATE_THRESHOLD: if ANY other student is closer than this, reject.
//   Set this to the SAME value your enrollment duplicate check uses.
const MATCH_THRESHOLD     = 0.50;
const DUPLICATE_THRESHOLD = 0.50;
// Someone else must be at least this much farther away than the owner
const SAFETY_MARGIN       = 0.03;

function fail($message, $extra = []) {
    unset($_SESSION["face_verified_at"], $_SESSION["face_score"]);
    echo json_encode(array_merge(["match" => false, "message" => $message], $extra));
    exit;
}

function valid_descriptor($d) {
    if (!is_array($d) || count($d) !== 128) return false;
    foreach ($d as $v) {
        if (!is_numeric($v) || !is_finite((float)$v)) return false;
    }
    return true;
}

function euclidean($a, $b) {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $diff = (float)$a[$i] - (float)$b[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["descriptor"]) || !valid_descriptor($data["descriptor"])) {
    fail("No valid descriptor received.");
}

$student_id = (int)$_SESSION["user_id"];
$incoming   = $data["descriptor"];

// ---- 1) Own profile -------------------------------------------------------
$stmt = $conn->prepare("SELECT face_descriptor FROM face_profiles WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    fail("No enrolled face profile found. Please enroll first.");
}

$row    = $result->fetch_assoc();
$stmt->close();
$stored = json_decode($row["face_descriptor"], true);

if (!valid_descriptor($stored)) {
    fail("Your stored face profile is invalid. Please re-enroll.");
}

$own_distance = euclidean($stored, $incoming);

if ($own_distance >= MATCH_THRESHOLD) {
    fail("Face does not match enrolled profile.", ["distance" => $own_distance]);
}

// ---- 2) Cross-check against every OTHER student ---------------------------
$stmt = $conn->prepare("SELECT student_id, face_descriptor FROM face_profiles WHERE student_id != ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$others = $stmt->get_result();

$closest_other = INF;
$closest_other_id = null;
while ($o = $others->fetch_assoc()) {
    $desc = json_decode($o["face_descriptor"], true);
    if (!valid_descriptor($desc)) continue;
    $d = euclidean($desc, $incoming);
    if ($d < $closest_other) {
        $closest_other = $d;
        $closest_other_id = (int)$o["student_id"];
    }
}
$stmt->close();

// Reject if another enrolled person is a closer (or nearly as close) match
if ($closest_other < DUPLICATE_THRESHOLD || $closest_other <= $own_distance + SAFETY_MARGIN) {
    error_log("Face verify rejected: student $student_id own=$own_distance other=$closest_other (student $closest_other_id)");
    // Generic message: never reveal whose face it resembles
    fail("This face could not be confirmed as belonging to this account.", ["distance" => $own_distance]);
}

// ---- 3) Success -----------------------------------------------------------
$_SESSION["face_verified_at"] = time();
$_SESSION["face_score"] = $own_distance;
echo json_encode(["match" => true, "distance" => $own_distance]);
?>