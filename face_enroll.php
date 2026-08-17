<?php
require_once "config.php";
require_once "auth_check.php"; // any logged-in user can enroll their own face
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Face Enrollment</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <script defer src="assets/facelib/face-api.min.js"></script>
</head>
<body class="bg-light">
<div class="container" style="max-width: 480px; margin-top: 50px;">
    <div class="card shadow-sm">
        <div class="card-body p-4 text-center">
            <h4 class="mb-3">Face Enrollment</h4>
            <p class="text-muted">Position your face clearly in the frame, then click Capture.</p>

            <div id="statusMsg" class="alert alert-info">Loading models...</div>

            <video id="video" width="360" height="270" autoplay muted class="border rounded mb-3"></video>

            <div>
                <button id="captureBtn" class="btn btn-primary w-100" disabled>Capture Face</button>
            </div>

            <div id="resultMsg" class="mt-3"></div>

            <a href="student_dashboard.php" class="d-block mt-3">Back to Dashboard</a>
        </div>
    </div>
</div>

<script>
const video = document.getElementById("video");
const statusMsg = document.getElementById("statusMsg");
const captureBtn = document.getElementById("captureBtn");
const resultMsg = document.getElementById("resultMsg");

const MODEL_URL = "assets/facelib/models";

async function loadModels() {
    await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
    await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
    await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
    statusMsg.textContent = "Models loaded. Starting camera...";
    startCamera();
}

async function startCamera() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: {} });
        video.srcObject = stream;
        statusMsg.classList.remove("alert-info");
        statusMsg.classList.add("alert-success");
        statusMsg.textContent = "Camera ready. Position your face and click Capture.";
        captureBtn.disabled = false;
    } catch (err) {
        statusMsg.classList.remove("alert-info");
        statusMsg.classList.add("alert-danger");
        statusMsg.textContent = "Camera access denied or unavailable.";
    }
}

captureBtn.addEventListener("click", async () => {
    resultMsg.innerHTML = `<div class="alert alert-info">Detecting face...</div>`;

    const detection = await faceapi
        .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks()
        .withFaceDescriptor();

    if (!detection) {
        resultMsg.innerHTML = `<div class="alert alert-danger">No face detected. Try again with better lighting/positioning.</div>`;
        return;
    }

    const descriptorArray = Array.from(detection.descriptor);

    try {
        const response = await fetch("save_face.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ descriptor: descriptorArray })
        });
        const result = await response.json();

        if (result.success) {
            resultMsg.innerHTML = `<div class="alert alert-success">Face enrolled successfully!</div>`;
        } else {
            resultMsg.innerHTML = `<div class="alert alert-danger">${result.message}</div>`;
        }
    } catch (err) {
        resultMsg.innerHTML = `<div class="alert alert-danger">Error saving face profile. Try again.</div>`;
    }
});

window.addEventListener("load", loadModels);
</script>
</body>
</html>