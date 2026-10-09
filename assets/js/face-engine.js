/**
 * face-engine.js
 * Shared helpers for face enrolment and check-in (used with face-api.js).
 *  - prepare():        picks / confirms the TensorFlow backend (webgl or cpu)
 *  - detect():         one detection at a time (never overlapping)
 *  - captureAverage(): takes several descriptors and averages them, which
 *                      gives a steadier face template than a single photo
 *  - withTimeout():    stops any step from waiting forever
 */
(function () {
    var FaceEngine = {};
    FaceEngine.backend = "unknown";
    FaceEngine.lastMs = 0;

    FaceEngine.sleep = function (ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    };

    FaceEngine.withTimeout = function (promise, ms, label) {
        var timer;
        var timeout = new Promise(function (_, reject) {
            timer = setTimeout(function () { reject(new Error((label || "Operation") + " timed out")); }, ms);
        });
        return Promise.race([promise, timeout]).then(
            function (v) { clearTimeout(timer); return v; },
            function (e) { clearTimeout(timer); throw e; }
        );
    };

    FaceEngine.options = function (inputSize, score) {
        return new faceapi.TinyFaceDetectorOptions({ inputSize: inputSize || 320, scoreThreshold: score || 0.4 });
    };

    // Add ?engine=cpu to the page address to force the CPU engine when testing
    // a phone whose graphics (WebGL) support gives poor results.
    FaceEngine.prepare = async function () {
        var forced = new URLSearchParams(location.search).get("engine");
        try {
            if (forced) { await faceapi.tf.setBackend(forced); }
            await faceapi.tf.ready();
            FaceEngine.backend = faceapi.tf.getBackend();
        } catch (e) {
            console.warn("Face engine: preferred backend failed, using cpu.", e);
            try {
                await faceapi.tf.setBackend("cpu");
                await faceapi.tf.ready();
                FaceEngine.backend = "cpu";
            } catch (e2) { console.warn(e2); }
        }
        console.log("Face engine backend:", FaceEngine.backend);
        return FaceEngine.backend;
    };

    FaceEngine.detect = async function (video, inputSize) {
        var t0 = performance.now();
        var d = await faceapi.detectSingleFace(video, FaceEngine.options(inputSize || 320, 0.4));
        FaceEngine.lastMs = Math.round(performance.now() - t0);
        return d;
    };

    // Returns { descriptor: [128 numbers], count } or null if no face was captured.
    FaceEngine.captureAverage = async function (video, wanted, onProgress) {
        var list = [];
        var tries = 0;
        while (list.length < wanted && tries < wanted * 3) {
            tries++;
            var r = null;
            try {
                r = await FaceEngine.withTimeout(
                    faceapi.detectSingleFace(video, FaceEngine.options(416, 0.4)).withFaceLandmarks().withFaceDescriptor(),
                    20000, "Face capture"
                );
            } catch (e) {
                console.warn(e);
                if (/timed out/.test(e.message)) { throw e; }
            }
            if (r && r.detection.score >= 0.5) {
                list.push(r.descriptor);
                if (onProgress) { onProgress(list.length, wanted); }
            }
            await FaceEngine.sleep(250);
        }
        if (list.length === 0) { return null; }
        var mean = new Array(128).fill(0);
        for (var k = 0; k < list.length; k++) {
            for (var i = 0; i < 128; i++) { mean[i] += list[k][i] / list.length; }
        }
        return { descriptor: mean, count: list.length };
    };

    window.FaceEngine = FaceEngine;
})();
