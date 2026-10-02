<?php
require_once "config.php";
require_once "auth_check.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Enrollment · MAU Smart Attendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script defer src="assets/facelib/face-api.min.js"></script>
    <style>
        :root {
            --navy-950: #060e1a;
            --navy-900: #0b1a2c;
            --navy-800: #122540;
            --blue-600: #1e5799;
            --blue-400: #4f8fd6;
            --amber-500: #d9722c;
            --amber-400: #eb9856;
            --cream-300: #e9c98a;
            --ink-050: #f4f7fb;
            --ink-300: #b7c4d6;
            --ink-500: #7f8fa6;
            --glass-fill: rgba(20, 36, 58, 0.46);
            --glass-border: rgba(255, 255, 255, 0.12);
            --danger: #e5694f;
            --success: #4fbf8b;
            --radius: 20px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--ink-050);
            background: var(--navy-950);
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            position: relative;
            overflow-x: hidden;
        }

        .field-bg {
            position: fixed; inset: 0; z-index: 0; overflow: hidden;
            background:
                radial-gradient(circle at 18% 20%, rgba(30,87,153,0.35), transparent 42%),
                radial-gradient(circle at 82% 78%, rgba(217,114,44,0.28), transparent 45%),
                linear-gradient(160deg, var(--navy-950) 0%, var(--navy-900) 55%, #0d1f34 100%);
        }
        .field-bg::before {
            content: ""; position: absolute; inset: -1px;
            background-image:
                linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: radial-gradient(ellipse at center, black 0%, transparent 72%);
        }
        .orb { position: absolute; border-radius: 50%; filter: blur(2px); opacity: 0.55; }
        .orb.blue { width: 420px; height: 420px; top: -120px; left: -140px; background: radial-gradient(circle, var(--blue-600), transparent 70%); }
        .orb.amber { width: 360px; height: 360px; bottom: -140px; right: -100px; background: radial-gradient(circle, var(--amber-500), transparent 70%); }

        .top-controls { position: fixed; top: 22px; right: 22px; z-index: 10; }
        .theme-switch {
            appearance: none; -webkit-appearance: none;
            width: 62px; height: 32px; border-radius: 999px;
            border: 1px solid var(--glass-border);
            background: var(--navy-800);
            position: relative; cursor: pointer; outline-offset: 3px;
            transition: background 0.25s ease;
        }
        .theme-switch::before {
            content: "";
            position: absolute; top: 3px; left: 3px;
            width: 24px; height: 24px; border-radius: 50%;
            background: var(--ink-050);
            transition: transform 0.25s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.35);
        }
        .theme-switch .icon-sun, .theme-switch .icon-moon {
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 14px; height: 14px; pointer-events: none;
        }
        .theme-switch .icon-moon { left: 8px; color: var(--cream-300); }
        .theme-switch .icon-sun { right: 8px; color: var(--amber-400); }
        .theme-switch[data-theme="light"]::before { transform: translateX(30px); }

        html[data-theme="light"] {
            --navy-950: #eef1f6;
            --navy-900: #ffffff;
            --navy-800: #e3e8f0;
            --ink-050: #16233a;
            --ink-300: #48566e;
            --ink-500: #6d7c93;
            --glass-fill: rgba(255, 255, 255, 0.55);
            --glass-border: rgba(20, 40, 70, 0.10);
        }
        html[data-theme="light"] .field-bg {
            background:
                radial-gradient(circle at 18% 20%, rgba(30,87,153,0.14), transparent 42%),
                radial-gradient(circle at 82% 78%, rgba(217,114,44,0.14), transparent 45%),
                linear-gradient(160deg, #f3f5f9 0%, #eef1f6 60%, #eaeef4 100%);
        }
        html[data-theme="light"] .field-bg::before { background-image: linear-gradient(rgba(20,40,70,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(20,40,70,0.04) 1px, transparent 1px); }

        .stage { position: relative; z-index: 1; width: 100%; max-width: 460px; }

        .brand-row { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; padding: 0 4px; }
        .badge-ring {
            width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
            background: conic-gradient(from 200deg, var(--blue-600), var(--blue-400) 35%, var(--amber-400) 65%, var(--amber-500) 100%);
            padding: 2px;
        }
        .badge-ring span {
            display: flex; width: 100%; height: 100%; border-radius: 50%;
            background: var(--navy-900); align-items: center; justify-content: center;
            font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--ink-050);
        }
        .brand-text .eyebrow { font-size: 12px; color: var(--ink-500); }
        .brand-text .name { font-family: 'Space Grotesk', sans-serif; font-weight: 600; font-size: 15.5px; color: var(--ink-050); }

        .card {
            background: var(--glass-fill);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 30px 28px 26px;
            backdrop-filter: blur(22px) saturate(140%);
            -webkit-backdrop-filter: blur(22px) saturate(140%);
            box-shadow: 0 24px 60px rgba(4, 10, 20, 0.35);
            text-align: center;
        }

        .card h1 { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 22px; margin: 0 0 6px; }
        .card .sub { margin: 0 0 20px; font-size: 13.5px; color: var(--ink-300); line-height: 1.5; }

        /* ---------- status / loader ---------- */
        .status-box {
            display: flex; justify-content: space-between; align-items: center;
            border-radius: 12px; padding: 11px 14px; font-size: 13.5px;
            margin-bottom: 14px; text-align: left; gap: 10px;
        }
        .status-box.state-info    { background: rgba(79,143,214,0.14); border: 1px solid rgba(79,143,214,0.35); color: #bcd6f5; }
        .status-box.state-success { background: rgba(79,191,139,0.14); border: 1px solid rgba(79,191,139,0.35); color: #a8ecc9; }
        .status-box.state-danger  { background: rgba(229,105,79,0.14); border: 1px solid rgba(229,105,79,0.35); color: #ffb9a4; }
        html[data-theme="light"] .status-box.state-info    { color: #1e5799; }
        html[data-theme="light"] .status-box.state-success { color: #216b47; }
        html[data-theme="light"] .status-box.state-danger  { color: #a53a22; }
        .timer { color: var(--ink-500); font-size: 12px; flex-shrink: 0; }

        .progress-ring-wrap { position: relative; width: 76px; height: 76px; margin: 4px auto 16px; }
        .progress-ring-bg { stroke: rgba(255,255,255,0.10); }
        html[data-theme="light"] .progress-ring-bg { stroke: rgba(20,40,70,0.10); }
        .progress-ring-fg { stroke: url(#ringGradient); stroke-linecap: round; transition: stroke-dashoffset 0.15s linear; }
        .progress-ring-label {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 0.88rem; font-family: 'Space Grotesk', sans-serif;
        }

        .tip-box {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            padding: 9px 12px;
            font-size: 12.5px;
            color: var(--ink-300);
            margin-bottom: 12px;
            text-align: left;
        }

        .guide-box {
            border-radius: 10px; padding: 9px 12px; font-size: 13px; margin-bottom: 14px; text-align: left;
        }
        .guide-box.state-warn { background: rgba(217,114,44,0.13); border: 1px solid rgba(217,114,44,0.32); color: var(--cream-300); }
        .guide-box.state-good { background: rgba(79,191,139,0.14); border: 1px solid rgba(79,191,139,0.35); color: #a8ecc9; }

        /* ---------- camera ---------- */
        .face-video-wrap { position: relative; display: inline-block; margin-bottom: 16px; }
        #video {
            transform: scaleX(-1);
            border-radius: 14px;
            border: 1px solid var(--glass-border);
            display: block;
            background: #000;
        }
        .face-oval-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        .face-oval-overlay ellipse {
            fill: none; stroke: rgba(255,255,255,0.55); stroke-width: 3; stroke-dasharray: 8 6;
            transition: stroke 0.2s ease, stroke-dasharray 0.2s ease, stroke-width 0.2s ease;
        }
        .face-oval-overlay ellipse.oval-good { stroke: var(--success); stroke-dasharray: none; stroke-width: 4; }
        .face-oval-overlay ellipse.oval-bad { stroke: var(--amber-400); }

        .capture-btn {
            width: 100%; padding: 13px; border: none; border-radius: 12px;
            font-family: 'Inter', sans-serif; font-weight: 600; font-size: 14.5px; color: #fff;
            background: linear-gradient(120deg, var(--blue-600), var(--amber-500));
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(30,87,153,0.30);
            transition: transform 0.12s ease, box-shadow 0.12s ease, opacity 0.15s ease;
        }
        .capture-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 14px 30px rgba(30,87,153,0.38); }
        .capture-btn:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }

        .result-msg { margin-top: 14px; }
        .result-msg .status-box { margin-bottom: 0; justify-content: flex-start; }

        .foot { text-align: center; margin-top: 20px; font-size: 13.5px; color: var(--ink-300); }
        .foot a { color: var(--cream-300); text-decoration: none; font-weight: 500; }
        .foot a:hover { text-decoration: underline; }

        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>

<div class="field-bg" aria-hidden="true">
    <div class="orb blue"></div>
    <div class="orb amber"></div>
</div>

<div class="top-controls">
    <button type="button" class="theme-switch" id="themeSwitch" data-theme="dark" aria-label="Toggle day mode" aria-pressed="false">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
    </button>
</div>

<div class="stage">

    <div class="brand-row">
        <div class="badge-ring"><span>MAU</span></div>
        <div class="brand-text">
            <div class="eyebrow">Smart Attendance</div>
            <div class="name">Modibbo Adama University</div>
        </div>
    </div>

    <div class="card">
        <h1>Face enrollment</h1>
        <p class="sub">Position your face clearly in the frame, then capture.</p>

        <!-- Loader: status text + elapsed timer + progress ring -->
        <div id="loaderWrap">
            <div id="statusMsg" class="status-box state-info">
                <span id="statusText">Preparing…</span>
                <span id="loaderTimer" class="timer">0.0s</span>
            </div>
            <div class="progress-ring-wrap">
                <svg width="76" height="76" viewBox="0 0 76 76">
                    <defs>
                        <linearGradient id="ringGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="var(--blue-400)"/>
                            <stop offset="100%" stop-color="var(--amber-400)"/>
                        </linearGradient>
                    </defs>
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

        <div>
            <button id="captureBtn" class="capture-btn" disabled>Capture Face</button>
        </div>

        <div id="resultMsg" class="result-msg"></div>

        <div class="foot"><a href="student_dashboard.php">← Back to Dashboard</a></div>
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
            guideMsg.className = "guide-box state-warn";
            guideMsg.textContent = "No face detected — make sure your face is visible.";
            ovalEl.classList.remove("oval-good", "oval-bad");
            return;
        }
        const status = evaluateFacePosition(detection, video);
        guideMsg.className = status.ok ? "guide-box state-good" : "guide-box state-warn";
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
    resultMsg.innerHTML = `<div class="status-box state-info">Detecting face...</div>`;

    const detection = await faceapi
        .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks()
        .withFaceDescriptor();

    if (!detection) {
        resultMsg.innerHTML = `<div class="status-box state-danger">No face detected. Try again with better lighting/positioning.</div>`;
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
            resultMsg.innerHTML = `<div class="status-box state-success">Face enrolled successfully!</div>`;
            if (guideInterval) clearInterval(guideInterval);
        } else {
            resultMsg.innerHTML = `<div class="status-box state-danger">${result.message}</div>`;
        }
    } catch (err) {
        resultMsg.innerHTML = `<div class="status-box state-danger">Error saving face profile. Try again.</div>`;
    }
});

/* ---------------------------------------------------------
   Day/night mode toggle, persisted with the same key used
   across the app
--------------------------------------------------------- */
(function () {
    const THEME_KEY = "attendance_theme";
    const root = document.documentElement;
    const toggle = document.getElementById("themeSwitch");

    function apply(theme) {
        root.setAttribute("data-theme", theme);
        root.style.colorScheme = theme;
        toggle.dataset.theme = theme;
        toggle.setAttribute("aria-pressed", theme === "light");
        toggle.setAttribute("aria-label", theme === "light" ? "Switch to dark mode" : "Switch to day mode");
    }

    const saved = localStorage.getItem(THEME_KEY) || "dark";
    apply(saved);

    toggle.addEventListener("click", function () {
        const next = root.getAttribute("data-theme") === "light" ? "dark" : "light";
        localStorage.setItem(THEME_KEY, next);
        apply(next);
    });
})();

// Wait for the full page load (including the deferred face-api.min.js script)
// before calling loadModels() — otherwise "faceapi" may not exist yet.
window.addEventListener('load', loadModels);
</script>
</body>
</html>