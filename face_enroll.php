<?php
require_once "config.php";
require_once "auth_check.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Enrollment · AttendX</title>
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

<div class="top-controls">
    <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
</div>

<div style="min-height:100vh; display:flex; align-items:center; justify-content:center; padding:40px 20px; position:relative;">
<div class="stage" style="max-width:420px;">

    <div class="brand-lockup stacked" style="margin-bottom:22px;">
        <div class="logo-mark size-lg">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 2.5 L21.5 20.5 H2.5 L12 2.5Z" fill="white"/>
                <path d="M8.7 14.3 L11 16.8 L15.3 10.1" stroke="var(--blue-600)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            </svg>
        </div>
        <div>
            <div class="wordmark size-lg">AttendX</div>
            <div class="tagline">Smart Attendance. Anytime. Anywhere.</div>
        </div>
    </div>

    <div class="card" style="text-align:center;">
        <h1 style="font-size:22px; margin-bottom:4px;">Face enrollment</h1>
        <p class="sub">Position your face clearly in the frame, then capture.</p>

        <!-- Loader: status text + elapsed timer + progress ring -->
        <div id="loaderWrap">
            <div id="statusMsg" class="status-box state-info">
                <span id="statusText">Preparing…</span>
                <span id="loaderTimer" class="timer">0.0s</span>
            </div>
            <div class="progress-ring-wrap">
                <svg width="76" height="76" viewBox="0 0 76 76">
                    <circle class="progress-ring-bg" cx="38" cy="38" r="32" stroke-width="6" fill="none"/>
                    <circle id="loadProgressRing" class="progress-ring-fg" cx="38" cy="38" r="32" stroke-width="6" fill="none"
                            stroke-dasharray="201.06" stroke-dashoffset="201.06" transform="rotate(-90 38 38)"/>
                </svg>
                <div class="progress-ring-label">
                    <span id="loadProgressPct">0%</span>
                </div>
            </div>
        </div>

        <!-- Static reminder shown throughout enrollment -->
        <div class="tip-box">
            Tip: remove hats, sunglasses, or face masks, and enroll in a well-lit spot for best results.
        </div>

        <!-- Dynamic live guidance (position, distance, lighting, possible obstruction) -->
        <div id="guideMsg" class="guide-box state-warn" style="display:none;"></div>

        <div class="face-video-wrap">
            <video id="video" width="360" height="270" autoplay muted playsinline webkit-playsinline></video>
            <svg class="face-oval-overlay" viewBox="0 0 360 270" preserveAspectRatio="none">
                <ellipse id="faceOval" cx="180" cy="135" rx="95" ry="120"></ellipse>
            </svg>
        </div>
        <div id="engineInfo" class="muted small" style="margin:8px 0;"></div>

        <div>
            <button id="captureBtn" class="btn btn-primary w-full" disabled>Capture Face</button>
        </div>

        <div id="resultMsg" class="result-msg"></div>

        <div class="foot"><a href="student_dashboard.php">← Back to Dashboard</a></div>
    </div>
</div>
</div>

<script>
const video = document.getElementById("video");
const statusMsg = document.getElementById("statusMsg");
const statusText = document.getElementById("statusText");
const loaderTimer = document.getElementById("loaderTimer");
const loaderWrap = document.getElementById("loaderWrap");
const loadProgressRing = document.getElementById("loadProgressRing");
const loadProgressPct = document.getElementById("loadProgressPct");
const RING_CIRCUMFERENCE = 2 * Math.PI * 32; // matches r="32" on the ring circle
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

        statusText.textContent = "Preparing face engine…";
        await FaceEngine.prepare();
        modelsLoaded = true;
        stopTimer();
        statusMsg.classList.remove("state-info");
        statusMsg.classList.add("state-success");
        statusText.textContent = "Models loaded. Starting camera…";
        setTimeout(() => { loaderWrap.style.display = "none"; }, 800);
        startCamera();
    } catch (err) {
        stopTimer();
        statusMsg.classList.remove("state-info");
        statusMsg.classList.add("state-danger");
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
        statusMsg.classList.remove("state-info", "state-success");
        statusMsg.classList.add("state-danger");
        statusText.textContent = "Camera access denied or unavailable.";
    }
}

/* ---------------------------------------------------------
   Live guidance: distance, centering, lighting, obstruction
--------------------------------------------------------- */
let brightCanvas = null;
function getAverageBrightness(video, box) {
    // small reusable canvas keeps this cheap on slow phones
    const scale = 0.25;
    if (!brightCanvas) brightCanvas = document.createElement("canvas");
    const cw = Math.max(1, Math.floor(video.videoWidth * scale));
    const ch = Math.max(1, Math.floor(video.videoHeight * scale));
    brightCanvas.width = cw;
    brightCanvas.height = ch;
    const ctx = brightCanvas.getContext("2d", { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, cw, ch);

    const x = Math.max(0, Math.floor(box.x * scale));
    const y = Math.max(0, Math.floor(box.y * scale));
    const w = Math.min(cw - x, Math.floor(box.width * scale));
    const h = Math.min(ch - y, Math.floor(box.height * scale));
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

let guideActive = false;
let guideDone = Promise.resolve();

// One detection at a time. The old timer started a new detection every 0.4s
// even if the last one had not finished, which piled up on slower phones.
function startGuidance() {
    const ovalEl = document.getElementById("faceOval");
    const engineInfo = document.getElementById("engineInfo");
    guideActive = true;
    guideDone = (async () => {
        while (guideActive) {
            try {
                if (modelsLoaded && video.readyState >= 2 && video.videoWidth) {
                    const detection = await FaceEngine.detect(video, 320);
                    if (!guideActive) break;
                    if (engineInfo) engineInfo.textContent = "Engine: " + FaceEngine.backend + " · " + FaceEngine.lastMs + " ms per check";
                    if (!detection) {
                        guideMsg.className = "guide-box state-warn";
                        guideMsg.textContent = "No face detected — make sure your face is visible.";
                        ovalEl.classList.remove("oval-good", "oval-bad");
                    } else {
                        const status = evaluateFacePosition({ detection: detection }, video);
                        guideMsg.className = status.ok ? "guide-box state-good" : "guide-box state-warn";
                        guideMsg.textContent = status.message;
                        ovalEl.classList.toggle("oval-good", status.ok);
                        ovalEl.classList.toggle("oval-bad", !status.ok);
                    }
                }
            } catch (err) {
                console.warn("Guidance check failed:", err);
            }
            await FaceEngine.sleep(300);
        }
    })();
}
async function stopGuidance() {
    guideActive = false;
    await guideDone;
}

/* ---------------------------------------------------------
   Capture and save
--------------------------------------------------------- */
captureBtn.addEventListener("click", async () => {
    captureBtn.disabled = true;
    await stopGuidance();   // free the processor for the capture
    const info = (t) => `<div class="status-box state-info"><span>${t}</span></div>`;
    const fail = (t) => `<div class="status-box state-danger"><span>${t}</span></div>`;
    resultMsg.innerHTML = info("Capturing… hold still");

    let captured = null;
    try {
        captured = await FaceEngine.captureAverage(video, 5, (n, total) => {
            resultMsg.innerHTML = info("Capturing " + n + " of " + total + "… hold still");
        });
    } catch (err) {
        resultMsg.innerHTML = fail("Face capture is too slow on this device. Please try again.");
        captureBtn.disabled = false;
        startGuidance();
        return;
    }

    if (!captured || captured.count < 3) {
        resultMsg.innerHTML = fail("Could not get a clear face. Hold still in good light and try again.");
        captureBtn.disabled = false;
        startGuidance();
        return;
    }

    try {
        const response = await fetch("save_face.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ descriptor: captured.descriptor })
        });
        const result = await response.json();

        if (result.success) {
            resultMsg.innerHTML = `<div class="status-box state-success"><span>Face enrolled successfully!</span></div>`;
        } else {
            resultMsg.innerHTML = fail(result.message);
            startGuidance();
        }
    } catch (err) {
        resultMsg.innerHTML = fail("Error saving face profile. Try again.");
        startGuidance();
    }
    captureBtn.disabled = false;
});

// Wait for the full page load (including the deferred face-api.min.js script)
// before calling loadModels() — otherwise "faceapi" may not exist yet.
window.addEventListener('load', loadModels);
</script>
</body>
</html>