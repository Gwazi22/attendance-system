<?php
require_once "config.php";
require_once "auth_check.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Face Enrollment</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <script defer src="assets/facelib/face-api.min.js"></script>
    <style>
        #video { transform: scaleX(-1); }

        .progress-ring-wrap { position: relative; width: 80px; height: 80px; margin: 0 auto; }
        .progress-ring-bg { stroke: #dee2e6; }
        [data-bs-theme="dark"] .progress-ring-bg { stroke: #495057; }
        .progress-ring-fg { stroke: #0d6efd; stroke-linecap: round; transition: stroke-dashoffset 0.15s linear; }
        .progress-ring-label {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 0.9rem;
        }

        .face-video-wrap { position: relative; display: inline-block; }
        .face-oval-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        .face-oval-overlay ellipse {
            fill: none; stroke: rgba(255,255,255,0.7); stroke-width: 3; stroke-dasharray: 8 6;
            transition: stroke 0.2s ease, stroke-dasharray 0.2s ease, stroke-width 0.2s ease;
        }
        .face-oval-overlay ellipse.oval-good { stroke: #28a745; stroke-dasharray: none; stroke-width: 4; }
        .face-oval-overlay ellipse.oval-bad { stroke: #ffc107; }
    </style>
</head>
<body>
<div class="container" style="max-width: 480px; margin-top: 50px;">
    <div class="d-flex justify-content-end mb-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="toggleTheme()" title="Toggle dark mode">
            <span id="themeToggleIcon">🌙</span>
        </button>
    </div>
    <div class="card shadow-sm">
        <div class="card-body p-4 text-center">
            <h4 class="mb-3">Face Enrollment</h4>
            <p class="text-muted">Position your face clearly in the frame, then click Capture.</p>

            <!-- Loader: status text + elapsed timer + progress bar -->
            <div id="loaderWrap" class="mb-3">
                <div id="statusMsg" class="alert alert-info d-flex justify-content-between align-items-center mb-2">
                    <span id="statusText">Preparing…</span>
                    <span id="loaderTimer" class="text-muted small">0.0s</span>
                </div>
                <div class="progress-ring-wrap my-2">
                    <svg width="80" height="80" viewBox="0 0 80 80">
                        <circle class="progress-ring-bg" cx="40" cy="40" r="34" stroke-width="7" fill="none"/>
                        <circle id="loadProgressRing" class="progress-ring-fg" cx="40" cy="40" r="34" stroke-width="7" fill="none"
                                stroke-dasharray="213.6" stroke-dashoffset="213.6" transform="rotate(-90 40 40)"/>
                    </svg>
                    <div class="progress-ring-label">
                        <span id="loadProgressPct">0%</span>
                    </div>
                </div>
            </div>

            <!-- Static reminder shown throughout enrollment -->
            <div class="alert alert-secondary py-2 small mb-2">
                Tip: remove hats, sunglasses, or face masks, and enroll in a well-lit spot for best results.
            </div>

            <!-- Dynamic live guidance (position, distance, lighting, possible obstruction) -->
            <div id="guideMsg" class="alert alert-secondary" style="display:none;"></div>

            <div class="face-video-wrap mb-3">
                <video id="video" width="360" height="270" autoplay muted playsinline webkit-playsinline class="border rounded"></video>
                <svg class="face-oval-overlay" viewBox="0 0 360 270" preserveAspectRatio="none">
                    <ellipse id="faceOval" cx="180" cy="135" rx="95" ry="120"></ellipse>
                </svg>
            </div>

            <div>
                <button id="captureBtn" class="btn btn-primary w-100" disabled>Capture Face</button>
            </div>

            <div id="resultMsg" class="mt-3"></div>

            <a href="student_dashboard.php" class="d-block mt-3">Back to Dashboard</a>
        </div>
    </div>
</div>

<script src="assets/js/ui-polish.js"></script>
<script>
const video = document.getElementById("video");
const statusMsg = document.getElementById("statusMsg");
const statusText = document.getElementById("statusText");
const loaderTimer = document.getElementById("loaderTimer");
const loaderWrap = document.getElementById("loaderWrap");
const loadProgressRing = document.getElementById("loadProgressRing");
const loadProgressPct = document.getElementById("loadProgressPct");
const RING_CIRCUMFERENCE = 2 * Math.PI * 34; // matches r="34" on the ring circle
const guideMsg = document.getElementById("guideMsg");
const captureBtn = document.getElementById("captureBtn");
const resultMsg = document.getElementById("resultMsg");

const MODEL_URL = "assets/facelib/models";
let modelsLoaded = false;
let guideInterval = null;

// Below this detector confidence score, treat the face as likely obstructed
// (hat brim, sunglasses, mask, hair across the face, etc). This is a proxy —
// face-api.js has no dedicated accessory/occlusion classifier — but a clear,
// unobstructed, well-lit face reliably scores much higher than an occluded one.
const OCCLUSION_SCORE_THRESHOLD = 0.75;

// Below this average brightness (0-255 luminance scale), flag the lighting as too dark.
const BRIGHTNESS_THRESHOLD = 60;

/* ---------------------------------------------------------
   Loader: elapsed timer
--------------------------------------------------------- */
let timerInterval = null;
function startTimer() {
    const startedAt = performance.now();
    timerInterval = setInterval(() => {
        const secs = ((performance.now() - startedAt) / 1000).toFixed(1);
        loaderTimer.textContent = secs + "s";
    }, 100);
}
function stopTimer() {
    if (timerInterval) clearInterval(timerInterval);
}

/* ---------------------------------------------------------
   Loader: weighted, smoothly-animated progress bar.
   Weights reflect real relative model sizes — the recognition
   model is by far the largest of the three, so it owns most
   of the bar. There's no true byte-level progress available
   from loadFromUri(), so within each step the bar creeps
   toward a soft ceiling while waiting, then snaps to the real
   milestone once that model actually finishes loading.
--------------------------------------------------------- */
function setProgress(pct) {
    const clamped = Math.min(100, Math.max(0, pct));
    const offset = RING_CIRCUMFERENCE - (clamped / 100) * RING_CIRCUMFERENCE;
    loadProgressRing.style.strokeDashoffset = offset;
    loadProgressPct.textContent = Math.round(clamped) + "%";
}

async function loadStep(promiseFn, fromPct, toPct, label) {
    statusText.textContent = label;
    let current = fromPct;
    const ceiling = fromPct + (toPct - fromPct) * 0.9;
    const tick = setInterval(() => {
        current += (ceiling - current) * 0.08;
        setProgress(current);
    }, 120);
    try {
        await promiseFn();
    } finally {
        clearInterval(tick);
    }
    setProgress(toPct);
}

/* ---------------------------------------------------------
   Model loading
--------------------------------------------------------- */
async function loadModels() {
    startTimer();
    try {
        await loadStep(
            () => faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            0, 8, "Loading face detector…"
        );
        await loadStep(
            () => faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            8, 20, "Loading landmark model…"
        );
        await loadStep(
            () => faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
            20, 100, "Loading recognition model (largest file)…"
        );

        modelsLoaded = true;
        stopTimer();
        statusMsg.classList.remove("alert-info");
        statusMsg.classList.add("alert-success");
        statusText.textContent = "Models loaded. Starting camera…";
        setTimeout(() => { loaderWrap.style.display = "none"; }, 800);
        startCamera();
    } catch (err) {
        stopTimer();
        statusMsg.classList.remove("alert-info");
        statusMsg.classList.add("alert-danger");
        statusText.textContent = "Failed to load face models. Please check your connection and refresh the page.";
    }
}

async function startCamera() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: "user" }
        });
        video.srcObject = stream;
        video.muted = true; // iOS sometimes ignores the muted attribute unless also set via JS

        try {
            // iOS Safari frequently needs an explicit play() call after a
            // programmatically-assigned srcObject — without it the <video>
            // element can silently stay on its blank/white default frame
            // even though the camera stream was granted and attached.
            await video.play();
        } catch (playErr) {
            console.warn("video.play() failed:", playErr);
        }

        captureBtn.disabled = false;
        guideMsg.style.display = "block";
        startGuidance();
    } catch (err) {
        statusMsg.style.display = "flex";
        statusMsg.classList.remove("alert-info", "alert-success");
        statusMsg.classList.add("alert-danger");
        statusText.textContent = "Camera access denied or unavailable.";
    }
}

/* ---------------------------------------------------------
   Live guidance: distance, centering, lighting, obstruction
--------------------------------------------------------- */
function getAverageBrightness(video, box) {
    const canvas = document.createElement("canvas");
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext("2d");
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    const x = Math.max(0, Math.floor(box.x));
    const y = Math.max(0, Math.floor(box.y));
    const w = Math.min(canvas.width - x, Math.floor(box.width));
    const h = Math.min(canvas.height - y, Math.floor(box.height));
    if (w <= 0 || h <= 0) return null;

    const data = ctx.getImageData(x, y, w, h).data;
    let total = 0, count = 0;
    for (let i = 0; i < data.length; i += 4) {
        total += 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
        count++;
    }
    return total / count;
}

function evaluateFacePosition(detection, video) {
    const box = detection.detection.box;
    const score = detection.detection.score;
    const faceWidthRatio = box.width / video.videoWidth;
    const centerX = box.x + box.width / 2;
    const centerY = box.y + box.height / 2;
    const offsetXRatio = Math.abs(centerX - video.videoWidth / 2) / video.videoWidth;
    const offsetYRatio = Math.abs(centerY - video.videoHeight / 2) / video.videoHeight;

    if (faceWidthRatio < 0.22) return { ok: false, message: "Move closer to the camera." };
    if (faceWidthRatio > 0.65) return { ok: false, message: "Move back a little — you're too close." };
    if (offsetXRatio > 0.18) return { ok: false, message: "Center your face horizontally." };
    if (offsetYRatio > 0.18) return { ok: false, message: "Center your face vertically." };

    const brightness = getAverageBrightness(video, box);
    if (brightness !== null && brightness < BRIGHTNESS_THRESHOLD) {
        return { ok: false, message: "Lighting is too dark — move to a brighter spot." };
    }

    if (score < OCCLUSION_SCORE_THRESHOLD) {
        return { ok: false, message: "Face partially hidden — remove hats, glasses, or masks and try again." };
    }

    return { ok: true, message: "Position looks good — hold still and click Capture." };
}

function startGuidance() {
    const ovalEl = document.getElementById("faceOval");
    guideInterval = setInterval(async () => {
        if (!modelsLoaded || video.readyState < 2) return;
        const detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions());
        if (!detection) {
            guideMsg.className = "alert alert-secondary";
            guideMsg.textContent = "No face detected — make sure your face is visible.";
            ovalEl.classList.remove("oval-good", "oval-bad");
            return;
        }
        const status = evaluateFacePosition(detection, video);
        guideMsg.className = status.ok ? "alert alert-success" : "alert alert-secondary";
        guideMsg.textContent = status.message;
        if (status.ok) {
            ovalEl.classList.add("oval-good");
            ovalEl.classList.remove("oval-bad");
        } else {
            ovalEl.classList.add("oval-bad");
            ovalEl.classList.remove("oval-good");
        }
    }, 400);
}

/* ---------------------------------------------------------
   Capture and save
--------------------------------------------------------- */
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
            if (guideInterval) clearInterval(guideInterval);
        } else {
            resultMsg.innerHTML = `<div class="alert alert-danger">${result.message}</div>`;
        }
    } catch (err) {
        resultMsg.innerHTML = `<div class="alert alert-danger">Error saving face profile. Try again.</div>`;
    }
});

// Wait for the full page load (including the deferred face-api.min.js script)
// before calling loadModels() — otherwise "faceapi" may not exist yet.
window.addEventListener('load', loadModels);
</script>
</body>
</html>