<?php
require_once "config.php";
require_once "auth_check.php"; // must be logged in

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["descriptor"]) || !is_array($data["descriptor"])) {
    echo json_encode(["success" => false, "message" => "No descriptor received."]);
    exit;
}

$student_id = $_SESSION["user_id"];
$descriptor_json = json_encode($data["descriptor"]);

// Check if a profile already exists — update if so, insert if not
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