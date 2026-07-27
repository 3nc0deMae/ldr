/* ============================================================
 * LDB-FRAS Liveness Detection (Anti-Spoofing)
 * ------------------------------------------------------------
 * Requires a REAL person in front of the camera before a face
 * frame is allowed to be sent for recognition.
 *
 * A printed photo or an ID picture cannot blink or move, so the
 * system stays "not live" and refuses to record attendance.
 *
 * Method: MediaPipe FaceMesh -> Eye Aspect Ratio (EAR) blink
 * detection + small head-motion check. When a genuine blink is
 * detected on a moving face, liveness becomes "live" for a short
 * window (LIVE_WINDOW_MS). autoScan() should only send frames
 * while liveness is live.
 *
 * Loaded globally from includes/footer.php.
 * MediaPipe FaceMesh is loaded from CDN (see footer.php).
 * ============================================================ */
(function (global) {
    'use strict';

    // MediaPipe FaceMesh landmark indices for the eyes.
    // Each eye uses 6 points to compute the Eye Aspect Ratio (EAR).
    // Order: [p1 (left corner), p2, p3, p4 (right corner), p5, p6]
    const LEFT_EYE  = [33, 160, 158, 133, 153, 144];
    const RIGHT_EYE = [362, 385, 387, 263, 373, 380];
    // Nose tip - used for a lightweight head-motion check.
    const NOSE_TIP  = 1;

    // --- Tunable thresholds -------------------------------------------------
    const EAR_OPEN       = 0.24;  // eyes considered open above this EAR
    const EAR_CLOSED     = 0.18;  // eyes considered closed below this EAR
    const LIVE_WINDOW_MS = 4000;  // how long a face stays "live" after a blink
    const MOTION_MIN     = 0.004; // min normalized nose movement to count as "alive"
    const MOTION_WINDOW  = 30;    // frames of history kept for motion
    // -----------------------------------------------------------------------

    function dist(a, b) {
        const dx = a.x - b.x;
        const dy = a.y - b.y;
        return Math.sqrt(dx * dx + dy * dy);
    }

    // Eye Aspect Ratio: (vertical1 + vertical2) / (2 * horizontal)
    function eyeAspectRatio(lm, idx) {
        const p1 = lm[idx[0]], p2 = lm[idx[1]], p3 = lm[idx[2]],
              p4 = lm[idx[3]], p5 = lm[idx[4]], p6 = lm[idx[5]];
        const vertical = dist(p2, p6) + dist(p3, p5);
        const horizontal = 2 * dist(p1, p4);
        if (horizontal === 0) return 0;
        return vertical / horizontal;
    }

    class Liveness {
        /**
         * @param {Object} opts
         * @param {HTMLVideoElement} opts.video   - the live camera video element
         * @param {Function} [opts.onStatus]      - callback(statusString, isLive)
         */
        constructor(opts) {
            this.video = opts.video;
            this.onStatus = opts.onStatus || function () {};

            this.faceMesh = null;
            this.running = false;
            this.rafId = null;

            this.eyesClosed = false;   // current closed-state (hysteresis)
            this.blinkCount = 0;
            this.lastBlinkAt = 0;      // timestamp of last detected blink
            this.faceVisible = false;

            this._noseHistory = [];
            this._hasMotion = false;
            this._processing = false;
        }

        /** Is a live person currently verified? */
        isLive() {
            return this.faceVisible &&
                   (Date.now() - this.lastBlinkAt) < LIVE_WINDOW_MS;
        }

        /** Human-readable current status. */
        status() {
            if (!this.faceVisible) return 'no_face';
            if (this.isLive()) return 'live';
            return 'awaiting_blink';
        }

        async start() {
            if (this.running) return;
            if (typeof FaceMesh === 'undefined') {
                console.warn('[Liveness] MediaPipe FaceMesh not loaded; ' +
                    'liveness disabled (fail-open).');
                this.running = true; // fail-open so attendance still works
                this._failOpen = true;
                return;
            }

            // Resolve the folder that holds the local MediaPipe FaceMesh assets.
            // Set window.FACE_MESH_BASE (e.g. "<BASE_URL>/assets/vendor/face_mesh")
            // before loading this script. Falls back to the CDN if unset.
            const meshBase = (global.FACE_MESH_BASE || '').replace(/\/+$/, '');
            this.faceMesh = new FaceMesh({
                locateFile: (file) => meshBase
                    ? `${meshBase}/${file}`
                    : `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}`
            });
            this.faceMesh.setOptions({
                maxNumFaces: 1,
                refineLandmarks: true,
                minDetectionConfidence: 0.5,
                minTrackingConfidence: 0.5
            });
            this.faceMesh.onResults(this._onResults.bind(this));

            this.running = true;
            this._loop();
        }

        stop() {
            this.running = false;
            if (this.rafId) cancelAnimationFrame(this.rafId);
            this.rafId = null;
            this.faceVisible = false;
            this.eyesClosed = false;
            this.lastBlinkAt = 0;
            this._noseHistory = [];
            try { if (this.faceMesh) this.faceMesh.close(); } catch (e) {}
            this.faceMesh = null;
        }

        /** Reset the live window (e.g. after a successful record). */
        reset() {
            this.lastBlinkAt = 0;
            this.eyesClosed = false;
        }

        async _loop() {
            if (!this.running || this._failOpen) return;
            try {
                if (this.video && this.video.readyState >= 2 && !this._processing) {
                    this._processing = true;
                    await this.faceMesh.send({ image: this.video });
                    this._processing = false;
                }
            } catch (e) {
                this._processing = false;
            }
            if (this.running) {
                this.rafId = requestAnimationFrame(this._loop.bind(this));
            }
        }

        _onResults(results) {
            const faces = results.multiFaceLandmarks;
            if (!faces || faces.length === 0) {
                this.faceVisible = false;
                this._noseHistory = [];
                this._emit();
                return;
            }
            this.faceVisible = true;
            const lm = faces[0];

            // --- Blink detection via EAR + hysteresis ---
            const ear = (eyeAspectRatio(lm, LEFT_EYE) +
                         eyeAspectRatio(lm, RIGHT_EYE)) / 2;

            if (!this.eyesClosed && ear < EAR_CLOSED) {
                this.eyesClosed = true;               // eyes just closed
            } else if (this.eyesClosed && ear > EAR_OPEN) {
                this.eyesClosed = false;              // eyes re-opened => a blink
                if (this._hasMotion) {                // only count "live" blinks
                    this.blinkCount++;
                    this.lastBlinkAt = Date.now();
                }
            }

            // --- Head motion check (defeats a photo held perfectly still) ---
            const nose = lm[NOSE_TIP];
            this._noseHistory.push({ x: nose.x, y: nose.y });
            if (this._noseHistory.length > MOTION_WINDOW) this._noseHistory.shift();
            this._hasMotion = this._computeMotion();

            this._emit();
        }

        _computeMotion() {
            if (this._noseHistory.length < 5) return false;
            let maxD = 0;
            const first = this._noseHistory[0];
            for (let i = 1; i < this._noseHistory.length; i++) {
                const d = dist(first, this._noseHistory[i]);
                if (d > maxD) maxD = d;
            }
            return maxD > MOTION_MIN;
        }

        _emit() {
            try { this.onStatus(this.status(), this.isLive()); } catch (e) {}
        }
    }

    global.Liveness = Liveness;
})(window);
