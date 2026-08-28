<?php
require_once "config.php";
require_once "auth_check.php"; // must be logged in

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["descriptor"]) || !is_array($data["descriptor"]) || count($data["descriptor"]) !== 128) {
    echo json_encode(["success" => false, "message" => "No descriptor received."]);
    exit;
}

$student_id = $_SESSION["user_id"];
$incoming_descriptor = $data["descriptor"];
$descriptor_json = json_encode($incoming_descriptor);

// Below this Euclidean distance, treat two descriptors as "the same face".
// Deliberately stricter than the 0.6 threshold used at check-in time
// (verify_face.php) — a slightly tighter bound here reduces the chance of
// two genuinely different people being flagged as a false match, while
// still catching the same face enrolled twice under different accounts.
const DUPLICATE_FACE_THRESHOLD = 0.5;

// Cross-account uniqueness check: reject enrollment if this face already
// matches an EXISTING profile belonging to a DIFFERENT student. Without
// this check, nothing stops the same person from enrolling their face on
// multiple student accounts and later checking in under someone else's
// identity — proxy attendance via a shared face rather than a shared
// password, which defeats the whole point of facial verification here.
$check_all = $conn->prepare("SELECT student_id, face_descriptor FROM face_profiles WHERE student_id != ?");
$check_all->bind_param("i", $student_id);
$check_all->execute();
$all_result = $check_all->get_result();

while ($row = $all_result->fetch_assoc()) {
    $other_descriptor = json_decode($row["face_descriptor"], true);
    if (!is_array($other_descriptor) || count($other_descriptor) !== 128) {
        continue; // skip any malformed/legacy row rather than fatal-erroring
    }

    $sum = 0;
    for ($i = 0; $i < 128; $i++) {
        $diff = $other_descriptor[$i] - $incoming_descriptor[$i];
        $sum += $diff * $diff;
    }
    $distance = sqrt($sum);

    if ($distance < DUPLICATE_FACE_THRESHOLD) {
        echo json_encode([
            "success" => false,
            "message" => "This face appears to already be enrolled on another account. Please contact your lecturer or administrator."
        ]);
        exit;
    }
}
$check_all->close();

// Check if a profile already exists for THIS student — update if so, insert if not
$stmt = $conn->prepare("SELECT profile_id FROM face_profiles WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $update = $conn->prepare("UPDATE face_profiles SET face_descriptor = ? WHERE student_id = ?");
    $update->bind_param("si", $descriptor_json, $student_id);
    $update->execute();
    $update->close();
} else {
    $insert = $conn->prepare("INSERT INTO face_profiles (student_id, face_descriptor) VALUES (?, ?)");
    $insert->bind_param("is", $student_id, $descriptor_json);
    $insert->execute();
    $insert->close();
}
$stmt->close();

echo json_encode(["success" => true, "message" => "Face profile saved."]);
?>