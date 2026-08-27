<?php
$required_role = 'student';
require_once "auth_check.php";
require_once "wifi_config.php";

$student_id = $_SESSION["user_id"];
$error = "";
$success = "";

function get_client_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'];
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
    $face_verified = isset($_POST["face_verified"]) && $_POST["face_verified"] === "1";

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
                        $insert = $conn->prepare(
                            "INSERT INTO attendance_records (session_id, student_id, ip_address, status)
                             VALUES (?, ?, ?, 'present')"
                        );
                        $insert->bind_param("iis", $session['session_id'], $student_id, $student_ip);

                        if ($insert->execute()) {
                            $success = "You're marked present for " . $session['course_code'] . " — " . $session['course_title'] . ".";
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
    <style>
        #checkinVideo { transform: scaleX(-1); }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark px-3">
    <span class="navbar-brand mb-0 h1">Attendance System — Student</span>
    <div class="d-flex align-items-center">
        <button class="btn btn-outline-light btn-sm me-2" onclick="toggleTheme()" title="Toggle dark mode">
            <span id="themeToggleIcon">🌙</span>
        </button>
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
                <div id="facePrompt" class="alert alert-info">
                    Now position your face in the frame, then tap "Start Verification" below.
                </div>
                <button type="button" id="startVerifyBtn" class="btn btn-primary mb-2">Start Verification</button>
                <div>
                    <video id="checkinVideo" width="320" height="240" autoplay muted playsinline webkit-playsinline class="border rounded mb-2" style="display:none;"></video>
                </div>
                <div id="faceStatus" class="alert alert-info" style="display:none;">Loading...</div>
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
                            <tr><th>Course</th><th>Marked At</th><th>Status</th></tr>
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/ui-polish.js"></script>
<script>
const MODEL_URL = "assets/facelib/models";
let modelsLoaded = false;
let modelsLoadingPromise = null;
let faceAlreadyVerified = false;

// Preload models silently as soon as the dashboard opens — only if student has a face profile
function preloadModels() {
    modelsLoadingPromise = (async () => {
        await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
        await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
        await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
        modelsLoaded = true;
    })();
}

// FIX: wait for full page load (including the deferred face-api.min.js script)
// before calling preloadModels — otherwise "faceapi" may not exist yet.
<?php if ($has_face_profile): ?>
window.addEventListener('load', preloadModels);
<?php endif; ?>

function evaluateFacePosition(detection, video) {
    const box = detection.detection.box;
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
    const section = document.getElementById("faceCheckSection");
    const statusEl = document.getElementById("faceStatus");
    section.style.display = "block";
    statusEl.className = "alert alert-info";
    statusEl.textContent = "Loading face models...";

    // FIX: wrapped in try/catch so a load failure shows a clear message
    // instead of hanging forever on "Loading face models..."
    try {
        if (!modelsLoadingPromise) preloadModels();
        await modelsLoadingPromise;
    } catch (err) {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Failed to load face models. Please refresh the page and try again.";
        return false;
    }

    const video = document.getElementById("checkinVideo");
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } });
    } catch (err) {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Camera access denied or unavailable.";
        return false;
    }
    video.srcObject = stream;
    video.muted = true; // iOS sometimes ignores the muted attribute unless also set via JS

    try {
        // FIX: iOS Safari (confirmed on iPhone 8) can leave the <video>
        // element on its blank/white default frame even after a camera
        // stream is successfully attached via srcObject, unless play()
        // is called explicitly. Desktop browsers don't need this, which
        // is why this only showed up on iPhone testing.
        await video.play();
    } catch (playErr) {
        console.warn("video.play() failed:", playErr);
    }

    statusEl.textContent = "Position your face in the frame...";

    await new Promise(resolve => {
        video.onloadedmetadata = () => resolve();
    });

    let stableGoodCount = 0;
    let detection = null;
    const maxAttempts = 40;
    let attempts = 0;

    while (attempts < maxAttempts) {
        attempts++;
        const d = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions());
        if (!d) {
            statusEl.className = "alert alert-secondary";
            statusEl.textContent = "No face detected — make sure your face is visible.";
            stableGoodCount = 0;
        } else {
            const status = evaluateFacePosition(d, video);
            statusEl.className = status.ok ? "alert alert-success" : "alert alert-secondary";
            statusEl.textContent = status.message;
            if (status.ok) {
                stableGoodCount++;
                if (stableGoodCount >= 3) {
                    detection = d;
                    break;
                }
            } else {
                stableGoodCount = 0;
            }
        }
        await new Promise(resolve => setTimeout(resolve, 300));
    }

    if (!detection) {
        stream.getTracks().forEach(track => track.stop());
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "Could not get a stable face position. Please try again.";
        return false;
    }

    statusEl.className = "alert alert-info";
    statusEl.textContent = "Capturing...";

    const fullDetection = await faceapi
        .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks()
        .withFaceDescriptor();

    stream.getTracks().forEach(track => track.stop());

    if (!fullDetection) {
        statusEl.className = "alert alert-danger";
        statusEl.textContent = "No face detected. Please try again.";
        return false;
    }

    const descriptorArray = Array.from(fullDetection.descriptor);

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
const startVerifyBtn = document.getElementById("startVerifyBtn");

if (checkinForm) {
    checkinForm.addEventListener("submit", function(e) {
        // Entering the join code and hitting "Check In" no longer
        // auto-starts the camera. Instead we reveal a prompt and wait for
        // an explicit "Start Verification" tap — avoids surprising students
        // with an instant camera popup and gives a clear cue on what to do.
        if (faceAlreadyVerified) return;
        e.preventDefault();

        const btn = document.getElementById("checkinBtn");
        const joinCodeInput = document.getElementById("join_code");
        btn.disabled = true;
        joinCodeInput.disabled = true;

        document.getElementById("faceCheckSection").style.display = "block";
        document.getElementById("facePrompt").style.display = "block";
        startVerifyBtn.style.display = "inline-block";
        startVerifyBtn.disabled = false;
        startVerifyBtn.textContent = "Start Verification";
    });
}

if (startVerifyBtn) {
    startVerifyBtn.addEventListener("click", async function() {
        startVerifyBtn.disabled = true;
        startVerifyBtn.textContent = "Verifying...";
        document.getElementById("facePrompt").style.display = "none";
        document.getElementById("checkinVideo").style.display = "block";
        document.getElementById("faceStatus").style.display = "block";

        const verified = await runFaceVerification();

        if (verified) {
            document.getElementById("face_verified").value = "1";
            faceAlreadyVerified = true;
            checkinForm.submit();
        } else {
            document.getElementById("facePrompt").style.display = "block";
            startVerifyBtn.disabled = false;
            startVerifyBtn.textContent = "Try Again";
        }
    });
}

<?php if ($error): ?>
document.addEventListener("DOMContentLoaded", function () {
    showToast(<?= json_encode($error) ?>, "danger");
});
<?php endif; ?>
<?php if ($success): ?>
document.addEventListener("DOMContentLoaded", function () {
    showToast(<?= json_encode($success) ?>, "success");
});
<?php endif; ?>
</script>
</body>
</html>