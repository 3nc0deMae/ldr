/* ═══════════════════════════════════════════════════════════════════════════
   VOICE QUEUE — sequential speech-synthesis queue for the attendance scanners
   (gate/timein.php, gate/timeout.php, teacher/attendance.php)

   VoiceQueue.say(text, { key, onstart })  enqueue a line (plays one at a time)
   VoiceQueue.clear()                      kill the speaking line + pending lines
   VoiceQueue.size()                       number of pending lines
   ═══════════════════════════════════════════════════════════════════════ */
(function (global) {
    'use strict';

    var supported = typeof global.speechSynthesis !== 'undefined' &&
                    typeof global.SpeechSynthesisUtterance !== 'undefined';

    var pending = [];
    var speaking = null;
    var active = false;
    var token = 0;
    var watchdog = null;

    var MAX_PENDING = 8;
    var RATE = 0.9;
    var CHARS_PER_SECOND = 11;
    var WATCHDOG_SLACK = 5000;

    function cancelEngine() {
        if (!supported) return;
        try { global.speechSynthesis.cancel(); } catch (e) {}
    }

    function clearWatchdog() {
        if (watchdog !== null) {
            clearTimeout(watchdog);
            watchdog = null;
        }
    }

    function pump() {
        if (!active) return;
        clearWatchdog();

        if (!supported || pending.length === 0) {
            pending = [];
            speaking = null;
            active = false;
            return;
        }

        var line = pending.shift();
        var myToken = token;
        var settled = false;
        speaking = line;

        function advance() {
            if (settled) return;
            settled = true;
            clearWatchdog();
            if (myToken !== token) return;
            speaking = null;
            pump();
        }

        if (typeof line.onstart === 'function') {
            try { line.onstart(line.text); } catch (e) {}
        }

        var utterance = new global.SpeechSynthesisUtterance(line.text);
        utterance.lang = 'en-US';
        utterance.rate = RATE;
        utterance.volume = 1;
        utterance.onend = advance;
        utterance.onerror = advance;

        var spokenMs = (line.text.length / CHARS_PER_SECOND) * 1000;
        var expected = Math.min(20000, Math.max(1500, spokenMs)) + WATCHDOG_SLACK;
        watchdog = setTimeout(function () {
            if (myToken !== token) return;
            cancelEngine();
            advance();
        }, expected);

        try {
            global.speechSynthesis.speak(utterance);
        } catch (e) {
            advance();
        }
    }

    function say(text, options) {
        options = options || {};
        text = String(text === null || text === undefined ? '' : text).trim();
        if (!text) return false;

        if (!supported) {
            if (typeof options.onstart === 'function') {
                try { options.onstart(text); } catch (e) {}
            }
            return true;
        }

        var key = options.key || null;

        if (speaking) {
            var busy = key ? (speaking.key === key)
                           : (speaking.key === null && speaking.text === text);
            if (busy) return true;
        }

        for (var i = 0; i < pending.length; i++) {
            var same = key ? (pending[i].key === key)
                           : (pending[i].key === null && pending[i].text === text);
            if (same) {
                pending[i].text = text;
                if (options.onstart) pending[i].onstart = options.onstart;
                return true;
            }
        }

        if (pending.length >= MAX_PENDING) pending.shift();

        pending.push({ text: text, key: key, onstart: options.onstart || null });

        if (!active) {
            active = true;
            pump();
        }
        return true;
    }

    function clear() {
        pending = [];
        speaking = null;
        active = false;
        token++;
        clearWatchdog();
        cancelEngine();
    }

    global.addEventListener('pagehide', clear);
    global.addEventListener('beforeunload', clear);

    global.VoiceQueue = {
        say: say,
        clear: clear,
        cancel: clear,
        size: function () { return pending.length; },
        supported: supported
    };
})(window);
