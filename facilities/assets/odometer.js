/* Reading the odometer off the photograph.

   This is a suggestion and never an answer. It fills the box; a person
   looks at it and presses the button. A wrong suggestion costs one glance
   and a correction. A wrong number accepted silently costs a service
   interval, so nothing here writes a reading on its own.

   No library, because there is none to have: this PHP has no image
   extension and the network has no CDN. A canvas hands over raw pixels,
   which is all that is actually needed.

   What it does on the bench (odotest.php, drawn displays rather than
   photographs): 11 of 15 read correctly, 4 declined, none read wrongly.
   It declines on anything tilted more than a couple of degrees, and on a
   display that is small in a cluttered frame.

   Declining is the failure this is built around. A wrong number that
   looks plausible gets accepted and lands in the fleet record; an absent
   one costs the six keystrokes the person was going to make anyway. That
   is why several attempts have to agree before anything is offered, and
   why the answer always lands in the box for a person to look at rather
   than being recorded. */
(function () {
    'use strict';

    /* Which of the seven segments are lit, and what digit that makes.
       The box is divided the way a seven segment digit is, and each
       segment region is asked how much of it is on.

       Declared here, above the early return further down, because a var
       assigned after that return is hoisted but never set - and a digit
       table that is quietly undefined does not throw, it just recognises
       nothing. That cost an afternoon. */
    var SEGMENTS = {
        '0': '1110111', '1': '0010010', '2': '1011101', '3': '1011011',
        '4': '0111010', '5': '1101011', '6': '1101111', '7': '1010010',
        '8': '1111111', '9': '1111011'
    };

    var file = document.getElementById('dash');
    var canvas = document.getElementById('shot');
    var wrap = document.getElementById('reader');
    var button = document.getElementById('tryread');
    var note = document.getElementById('readnote');
    var miles = document.getElementById('miles');

    /* The reading is worth having on its own: the bench drives it with
       drawn displays rather than photographs, which is how the failure
       modes were found. */
    window.PriOdometer = {read: readOdometer, trace: trace,
                          _parts: {digitsFrom: digitsFrom, components: components,
                                   dilate: dilate, classify: classify, otsu: otsu}};

    if (!file || !canvas || !button) { return; }

    var loaded = false;

    file.addEventListener('change', function () {
        if (!file.files || !file.files[0]) { wrap.hidden = true; return; }
        var img = new Image();
        img.onload = function () {
            /* Big enough to see the segments, small enough that a phone
               does not spend a second on it. */
            var scale = Math.min(1, 1200 / Math.max(img.width, img.height));
            canvas.width = Math.round(img.width * scale);
            canvas.height = Math.round(img.height * scale);
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            loaded = true;
            wrap.hidden = false;
            note.textContent = '';
            URL.revokeObjectURL(img.src);
        };
        img.onerror = function () { wrap.hidden = true; };
        img.src = URL.createObjectURL(file.files[0]);
    });

    button.addEventListener('click', function () {
        if (!loaded) { return; }
        note.textContent = 'Looking...';
        setTimeout(function () {
            var got = readOdometer(canvas);
            if (got === null) {
                note.textContent = 'Could not read it from the photograph. Type it in.';
                return;
            }
            miles.value = got;
            document.getElementById('suggested').value = got;
            document.getElementById('was_suggested').value = '1';
            note.innerHTML = 'Read as <strong>' + got + '</strong>. Check it against the dash '
                           + 'before recording.';
            miles.focus();
            miles.select();
            /* Typing over the suggestion means it was not accepted, and
               that is worth recording as much as the acceptance. */
            miles.addEventListener('input', function once() {
                document.getElementById('was_suggested').value = '0';
                miles.removeEventListener('input', once);
            });
        }, 30);
    });

    /* ---- the reading itself ------------------------------------------ */

    function readOdometer(cv) {
        var w = cv.width, h = cv.height;
        var px = cv.getContext('2d').getImageData(0, 0, w, h).data;

        /* Grey, and the brightest band of the image. A lit display against
           a dark dash is the easy case and the common one. */
        var grey = new Uint8Array(w * h);
        var hist = new Uint32Array(256);
        for (var i = 0, p = 0; i < px.length; i += 4, p++) {
            var g = (px[i] * 299 + px[i + 1] * 587 + px[i + 2] * 114) / 1000 | 0;
            grey[p] = g;
            hist[g]++;
        }

        var t = otsu(hist, w * h);

        /* Both ways round - pale digits on dark and dark on pale - and a
           few sizes of gap, because how far apart the segments look
           depends on how much of the frame the display fills. Whichever
           attempt yields the tidiest row of digits wins. */
        var votes = {};
        var grows = [2, 3, 5, 8, Math.max(2, Math.round(h * 0.012))];
        [true, false].forEach(function (bright) {
            grows.forEach(function (g) {
                var got = digitsFrom(grey, w, h, t, bright, g);
                if (got) {
                    votes[got.text] = (votes[got.text] || 0) + 1;
                }
            });
        });

        /* The attempts have to agree. Each one closes the gaps by a
           different amount, so they are close to independent readings of
           the same photograph, and a number that only one of them saw is
           a number the picture does not really contain.

           This is the difference between a suggestion that is sometimes
           wrong and one that is sometimes absent, and absent is the one
           somebody can work with: they type it, as they would have done
           anyway. A wrong number that looks plausible gets accepted. */
        var winner = null;
        var top = 0;
        var tied = false;
        Object.keys(votes).forEach(function (text) {
            if (votes[text] > top) {
                top = votes[text];
                winner = text;
                tied = false;
            } else if (votes[text] === top) {
                tied = true;
            }
        });
        if (winner === null || top < 2 || tied) { return null; }
        return winner;
    }

    /** Otsu's threshold: the split that best separates the two humps. */
    function otsu(hist, total) {
        var sum = 0, i;
        for (i = 0; i < 256; i++) { sum += i * hist[i]; }
        var sumB = 0, wB = 0, best = 0, bestVar = -1;
        for (i = 0; i < 256; i++) {
            wB += hist[i];
            if (wB === 0) { continue; }
            var wF = total - wB;
            if (wF === 0) { break; }
            sumB += i * hist[i];
            var mB = sumB / wB, mF = (sum - sumB) / wF;
            var between = wB * wF * (mB - mF) * (mB - mF);
            if (between > bestVar) { bestVar = between; best = i; }
        }
        return best;
    }

    /** Find a row of digit-shaped blobs and read each one. */
    function digitsFrom(grey, w, h, t, bright, grow) {
        var on = new Uint8Array(w * h);
        var count = 0;
        for (var p = 0; p < grey.length; p++) {
            var v = bright ? (grey[p] > t) : (grey[p] < t);
            on[p] = v ? 1 : 0;
            count += v ? 1 : 0;
        }
        /* A frame that is nearly all one colour has nothing in it. */
        if (count < w * h * 0.002 || count > w * h * 0.5) { return null; }

        /* A seven segment digit is seven separate bars with gaps between
           them, so looking for connected blobs finds bars, not digits -
           which is why the first version of this declined on everything,
           including displays it had drawn itself.

           Growing the lit area closes those gaps, so a digit becomes one
           blob and can be found. The digits are then read from the
           original pixels, because the grown ones have no gaps left to
           tell the segments apart. */
        var fat = dilate(on, w, h, grow);
        var boxes = components(fat, w, h);
        for (var bi = 0; bi < boxes.length; bi++) {
            var b = boxes[bi];
            b.x += grow; b.y += grow;
            b.w = Math.max(1, b.w - grow * 2);
            b.h = Math.max(1, b.h - grow * 2);
            b.cy = b.y + b.h / 2;
        }
        if (boxes.length < 3) { return null; }

        /* Digits on an odometer sit in a row, at one height, evenly. Group
           the blobs that agree about that and throw the rest away - which
           is how the speedometer needle and the warning lights get
           excluded without knowing anything about them. */
        boxes.sort(function (a, b) { return a.x - b.x; });
        var runs = [];
        for (var i = 0; i < boxes.length; i++) {
            var run = [boxes[i]];
            for (var j = i + 1; j < boxes.length; j++) {
                var last = run[run.length - 1];
                var b = boxes[j];
                var sameHeight = Math.abs(b.h - last.h) < last.h * 0.35;
                var sameLine = Math.abs(b.cy - last.cy) < last.h * 0.45;
                /* How far apart two digits may be is measured against
                   their height, not against the width of the one before.
                   Widths vary - a 1 is a fifth of the width of an 8 - so
                   scaling the gap by width meant nothing could ever
                   follow a 1, and every reading that began with one lost
                   its first digit. Heights are all the same. */
                var close = (b.x - (last.x + last.w)) < last.h * 0.9 && b.x >= last.x;
                if (sameHeight && sameLine && close) { run.push(b); }
            }
            if (run.length >= 3) { runs.push(run); }
        }
        if (!runs.length) { return null; }

        runs.sort(function (a, b) { return b.length - a.length; });
        var row = runs[0];
        if (row.length > 8) { row = row.slice(0, 8); }

        /* Every digit on a display is the same size, but the blobs are
           not: a 1 is two thin bars on the right of its cell, so its
           bounding box is a fifth of the width of its neighbours. Reading
           that box as though it were the whole digit gives nonsense,
           which is what made the whole row fail.

           So the row is put back on a grid. Heights and tops come from
           the digits that are a normal shape; a narrow blob keeps its
           right edge - where a 1's segments live - and is widened
           leftwards to match. */
        var normal = row.filter(function (b) { return b.h / b.w < 2.4; });
        var widths = normal.map(function (b) { return b.w; }).sort(function (a, b) { return a - b; });
        var tops = row.map(function (b) { return b.y; }).sort(function (a, b) { return a - b; });
        var bots = row.map(function (b) { return b.y + b.h; }).sort(function (a, b) { return a - b; });
        var medW = widths.length ? widths[widths.length >> 1] : row[0].w;
        var top = tops[tops.length >> 1];
        var bottom = bots[bots.length >> 1];
        if (bottom - top < 4) { return null; }

        var cells = row.map(function (b) {
            var right = b.x + b.w;
            var wide = Math.max(b.w, medW);
            return {x: right - wide, y: top, w: wide, h: bottom - top, narrow: b.w < medW * 0.55};
        });

        var text = '';
        var sure = 0;
        for (var k = 0; k < cells.length; k++) {
            var d = classify(on, w, cells[k]);
            if (d === null) {
                /* A cell that reads as nothing but was a thin blob in a
                   row of fat ones is a 1, and nothing else is that shape. */
                if (cells[k].narrow) {
                    text += '1';
                    sure += 0.8;
                    continue;
                }
                return null;
            }
            text += d.digit;
            sure += d.confidence;
        }
        if (!/^[0-9]{3,8}$/.test(text)) { return null; }

        /* An odometer reading does not start with a zero unless it is a
           new vehicle, and a leading zero is much more often a stray blob
           than a genuine digit. */
        text = text.replace(/^0+(?=\d)/, '');
        var avg = sure / row.length;
        if (avg < 0.62) { return null; }
        return {text: text, score: avg * row.length};
    }

    /* Why a photograph was declined. Kept because "it did not read it"
       is not a bug report, and the answer is always at one of four
       points: the threshold, the blobs, the row, or the digits. */
    function trace(cv) {
        var w = cv.width, h = cv.height;
        var px = cv.getContext('2d').getImageData(0, 0, w, h).data;
        var grey = new Uint8Array(w * h);
        var hist = new Uint32Array(256);
        for (var i = 0, p = 0; i < px.length; i += 4, p++) {
            var g = (px[i] * 299 + px[i + 1] * 587 + px[i + 2] * 114) / 1000 | 0;
            grey[p] = g;
            hist[g]++;
        }
        var t = otsu(hist, w * h);
        var out = [];
        [true, false].forEach(function (bright) {
            [2, 3, 5, 8, Math.max(2, Math.round(h * 0.012))].forEach(function (grow) {
                var on = new Uint8Array(w * h);
                var count = 0;
                for (var q = 0; q < grey.length; q++) {
                    var v = bright ? (grey[q] > t) : (grey[q] < t);
                    on[q] = v ? 1 : 0;
                    count += v ? 1 : 0;
                }
                var boxes = components(dilate(on, w, h, grow), w, h);
                var got = digitsFrom(grey, w, h, t, bright, grow);
                out.push({bright: bright, grow: grow, lit: count,
                          blobs: boxes.length,
                          shapes: boxes.slice(0, 10).map(function (b) {
                              return b.w + 'x' + b.h;
                          }).join(' '),
                          result: got ? got.text : null});
            });
        });
        return {threshold: t, attempts: out};
    }

    /** Grow the lit area by r pixels, horizontally then vertically. */
    function dilate(on, w, h, r) {
        if (r <= 0) { return on; }
        var mid = new Uint8Array(w * h);
        var x, y, i, run;
        for (y = 0; y < h; y++) {
            run = -1;
            for (x = 0; x < w; x++) {
                if (on[y * w + x]) { run = r; }
                if (run >= 0) { mid[y * w + x] = 1; run--; }
            }
            run = -1;
            for (x = w - 1; x >= 0; x--) {
                if (on[y * w + x]) { run = r; }
                if (run >= 0) { mid[y * w + x] = 1; run--; }
            }
        }
        var out = new Uint8Array(w * h);
        for (x = 0; x < w; x++) {
            run = -1;
            for (y = 0; y < h; y++) {
                i = y * w + x;
                if (mid[i]) { run = r; }
                if (run >= 0) { out[i] = 1; run--; }
            }
            run = -1;
            for (y = h - 1; y >= 0; y--) {
                i = y * w + x;
                if (mid[i]) { run = r; }
                if (run >= 0) { out[i] = 1; run--; }
            }
        }
        return out;
    }

    /** Connected blobs, by a flood fill, with the obvious rubbish dropped. */
    function components(on, w, h) {
        var seen = new Uint8Array(w * h);
        var boxes = [];
        var stack = new Int32Array(w * h);
        for (var start = 0; start < on.length; start++) {
            if (!on[start] || seen[start]) { continue; }
            var top = 0;
            stack[top++] = start;
            seen[start] = 1;
            var minx = w, maxx = 0, miny = h, maxy = 0, size = 0;
            while (top > 0) {
                var p = stack[--top];
                var x = p % w, y = (p / w) | 0;
                size++;
                if (x < minx) { minx = x; }
                if (x > maxx) { maxx = x; }
                if (y < miny) { miny = y; }
                if (y > maxy) { maxy = y; }
                if (x > 0 && on[p - 1] && !seen[p - 1]) { seen[p - 1] = 1; stack[top++] = p - 1; }
                if (x < w - 1 && on[p + 1] && !seen[p + 1]) { seen[p + 1] = 1; stack[top++] = p + 1; }
                if (y > 0 && on[p - w] && !seen[p - w]) { seen[p - w] = 1; stack[top++] = p - w; }
                if (y < h - 1 && on[p + w] && !seen[p + w]) { seen[p + w] = 1; stack[top++] = p + w; }
            }
            var bw = maxx - minx + 1, bh = maxy - miny + 1;
            var ratio = bh / bw;
            /* A digit is taller than it is wide, a reasonable size, and
               not a hairline. The one is the exception on ratio. */
            if (bh < h * 0.02 || bh > h * 0.75) { continue; }
            if (ratio < 0.8 || ratio > 9) { continue; }
            if (size < bw * bh * 0.10) { continue; }
            boxes.push({x: minx, y: miny, w: bw, h: bh, cy: (miny + maxy) / 2, size: size});
        }
        return boxes;
    }

    function classify(on, w, box) {
        /* a=top, b=upper left, c=upper right, d=middle,
           e=lower left, f=lower right, g=bottom */
        var regions = [
            [0.15, 0.00, 0.70, 0.18],   // a
            [0.00, 0.10, 0.22, 0.35],   // b
            [0.78, 0.10, 0.22, 0.35],   // c
            [0.15, 0.41, 0.70, 0.18],   // d
            [0.00, 0.55, 0.22, 0.35],   // e
            [0.78, 0.55, 0.22, 0.35],   // f
            [0.15, 0.82, 0.70, 0.18]    // g
        ];
        var bits = '';
        var margin = 0;
        for (var r = 0; r < regions.length; r++) {
            var rg = regions[r];
            var x0 = box.x + Math.floor(rg[0] * box.w);
            var y0 = box.y + Math.floor(rg[1] * box.h);
            var x1 = Math.min(box.x + box.w, x0 + Math.max(1, Math.floor(rg[2] * box.w)));
            var y1 = Math.min(box.y + box.h, y0 + Math.max(1, Math.floor(rg[3] * box.h)));
            var lit = 0, total = 0;
            for (var y = y0; y < y1; y++) {
                for (var x = x0; x < x1; x++) {
                    total++;
                    lit += on[y * w + x];
                }
            }
            var frac = total ? lit / total : 0;
            bits += frac > 0.4 ? '1' : '0';
            /* How far the segment is from the fence it was judged on:
               a segment at 0.41 is a coin toss and should say so. */
            margin += Math.min(1, Math.abs(frac - 0.4) / 0.3);
        }
        var digit = null;
        for (var d in SEGMENTS) {
            if (SEGMENTS[d] === bits) { digit = d; break; }
        }
        if (digit === null) { return null; }
        return {digit: digit, confidence: margin / 7};
    }
}());
