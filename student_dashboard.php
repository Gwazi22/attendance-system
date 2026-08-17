<?php
$required_role = 'student';
require_once "auth_check.php";
require_once "wifi_config.php";

$student_id = $_SESSION["user_id"];
$error = "";
$success = "";

// Get the student's real IP address as seen by the server
function get_client_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // May contain a comma-separated list; take the first
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'];
}

// Check whether this student has an enrolled face profile
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
    $face_verified = isset($_POST["face_verified"]) && $_POST["face_verified"] === "1";

    if (empty($join_code)) {
        $error = "Please enter the join code given by your lecturer.";
    } else {
        // 1. Look up the session by join code
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

            // 2. Check the session is active and within its time window
            $now = time();
            $start = strtotime($session['start_time']);
            $end   = strtotime($session['end_time']);

            if ($session['status'] !== 'active') {
                $error = "This session has been closed by the lecturer.";
            } elseif ($now < $start || $now > $end) {
                $error = "This session is not currently open for check-in.";
            } else {
                // 3. WiFi verification — is the student's IP on an allowed network?
                if (!ip_matches_allowed_prefix($student_ip, $allowed_ip_prefixes)) {
                    $error = "You must be connected to the lecturer's WiFi network to check in. Your IP ($student_ip) was not recognized.";
                } elseif (!$face_verified) {
                    // 4. Face verification must have already passed client-side (via verify_face.php)
                    //    before this form submits. If the flag isn't set, block here as a safety net.
                    $error = "Face verification did not complete. Please try checking in again.";
                } else {
                    // 5. Duplicate check
                    $dup = $conn->prepare(
                        "SELECT record_id FROM attendance_records WHERE session_id = ? AND student_id = ?"
                    );
                    $dup->bind_param("ii", $session['session_id'], $student_id);
                    $dup->execute();
                    $dup->store_result();

                    if ($dup->num_rows > 0) {
                        $error = "You have already been marked present for this session.";
                    } else {
                        // 6. Insert attendance record
                        $insert = $conn->prepare(
                            "INSERT INTO attendance_records (session_id, student_id, ip_address, status)
                             VALUES (?, ?, ?, 'present')"
                        );
                        $insert->bind_param("iis", $session['session_id'], $student_id, $student_ip);

                        if ($insert->execute()) {
                            $success = "You're marked present for " . htmlspecialchars($session['course_code']) . " — " . htmlspecialchars($session['course_title']) . ".";
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

// Fetch this student's recent attendance history
$history = [];
$stmt = $conn->prepare(
    "SELECT r.marked_at, r.status, c.course_code, c.course_title
     FROM attendance_records r
     JOIN attendance_sessions s ON r.session_id = s.session_id
     JOIN courses c ON s.course_id = c.course_id
     WHERE r.student_id = ?
     ORDER BY r.marked_at DESC
     LIMIT 10"
);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <script defer src="assets/facelib/face-api.min.js"></script>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark px-3">
    <span class="navbar-brand mb-0 h1">Attendance System — Student</span>
    <div>
        <span class="text-light me-3">Welcome, <?= htmlspecialchars($_SESSION["full_name"]) ?></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container mt-4" style="max-width: 700px;">

    <?php if (!$has_face_profile): ?>
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <span>You haven't enrolled your face yet. You must enroll before you can check in.</span>
            <a href="face_enroll.php" class="btn btn-sm btn-dark">Enroll Now</a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">Check In to a Session</h5>
            <p class="text-muted">
                Make sure you're connected to your lecturer's WiFi hotspot before checking in.
            </p>

            <form method="POST" action="student_dashboard.php" class="row g-3" id="checkinForm">
                <div class="col-8">
                    <input type="text" name="join_code" id="join_code" class="form-control form-control-lg text-center"
                           placeholder="Enter 6-digit code" maxlength="6" required <?= !$has_face_profile ? "disabled" : "" ?>>
                </div>
                <div class="col-4">
                    <button type="submit" id="checkinBtn" class="btn btn-success btn-lg w-100" <?= !$has_face_profile ? "disabled" : "" ?>>
                        Check In
                    </button>
                </div>
                <input type="hidden" name="face_verified" id="face_verified" value="0">
            </form>

            <div id="faceCheckSection" class="mt-3" style="display:none;">
                <p class="text-muted mb-2">Look at the camera to verify your identity.</p>
                <video id="checkinVideo" width="320" height="240" autoplay muted class="border rounded mb-2"></video>
                <div id="faceStatus" class="alert alert-info">Loading...</div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">Your Recent Attendance</h5>
            <?php if (empty($history)): ?>
                <p class="text-muted mb-0">No attendance records yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Marked At</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><?= htmlspecialchars($h['course_code']) ?></td>
                                    <td><?= date("M j, g:i A", strtotime($h['marked_at'])) ?></td>
                                    <td>
                                        <?php if ($h['status'] === 'present'): ?>
                                            <span class="badge bg-success">Present</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Flagged</span>
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
const MODEL_URL = "assets/facelib/models";
let modelsLoaded = false;
let faceAlreadyVerified = false;

async function ensureModelsLoaded() {
    if (modelsLoaded) return;
    await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
    await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
    await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
    modelsLoaded = true;
}

async function runFaceVerification() {
    const section = document.getElementById("faceCheckSection");
    const statusEl = document.getElementById("faceStatus");
    section.style.display = "block";
    statusEl.className = "alert alert-info";
    statusEl.textContent = "Loading face models...";

    await ensureModelsLoaded();

    const video = document.getElementById("checkinVideo");
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: {} });
    } catch (err) {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Camera access denied or unavailable.";
        return false;
    }
    video.srcObject = stream;

    statusEl.textContent = "Position your face and hold still...";
    await new Promise(resolve => setTimeout(resolve, 2000));

    const detection = await faceapi
        .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks()
        .withFaceDescriptor();

    stream.getTracks().forEach(track => track.stop());

    if (!detection) {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "No face detected. Please try again.";
        return false;
    }

    const descriptorArray = Array.from(detection.descriptor);

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
        statusEl.textContent = "Error contacting server. Please try again.";
        return false;
    }

    if (result.match) {
        statusEl.className = "alert alert-success";
        statusEl.textContent = "Face verified! Submitting attendance...";
        return true;
    } else {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = result.message || "Face verification failed.";
        return false;
    }
}

const checkinForm = document.getElementById("checkinForm");
if (checkinForm) {
    checkinForm.addEventListener("submit", async function(e) {
        if (faceAlreadyVerified) {
            return;
        }
        e.preventDefault();

        const btn = document.getElementById("checkinBtn");
        btn.disabled = true;
        btn.textContent = "Verifying...";

        const verified = await runFaceVerification();

        if (verified) {
            document.getElementById("face_verified").value = "1";
            faceAlreadyVerified = true;
            checkinForm.submit();
        } else {
            btn.disabled = false;
            btn.textContent = "Check In";
        }
    });
}
</script>
</body>
</html>