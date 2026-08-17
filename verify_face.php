<?php
require_once "config.php";
require_once "auth_check.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["descriptor"]) || !is_array($data["descriptor"])) {
    echo json_encode(["match" => false, "message" => "No descriptor received."]);
    exit;
}

$student_id = $_SESSION["user_id"];
$incoming_descriptor = $data["descriptor"];

$stmt = $conn->prepare("SELECT face_descriptor FROM face_profiles WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["match" => false, "message" => "No enrolled face profile found. Please enroll first."]);
    exit;
}

$row = $result->fetch_assoc();
$stored_descriptor = json_decode($row["face_descriptor"], true);

// Calculate Euclidean distance between the two 128-d vectors
$sum = 0;
for ($i = 0; $i < 128; $i++) {
    $diff = $stored_descriptor[$i] - $incoming_descriptor[$i];
    $sum += $diff * $diff;
}
$distance = sqrt($sum);

$threshold = 0.6; // standard face-api.js threshold

if ($distance < $threshold) {
    echo json_encode(["match" => true, "distance" => $distance]);
} else {
    echo json_encode(["match" => false, "message" => "Face does not match enrolled profile.", "distance" => $distance]);
}

$stmt->close();
?>