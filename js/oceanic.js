/**
 * Oceanic Forecast - application shell
 *
 * MapLibre GL globe with NDFD grids (js/oceanic-ndfd.js) and OPC PGEN
 * fronts/isobars (js/oceanic-pgen.js): timeline + playback, layer panel,
 * legend, click readout, status reporting and keyboard shortcuts.
 *
 * Page options (set window.OCEANIC_CONFIG before this script):
 *   geojsonDir     PGEN GeoJSON directory (default '/data/geoJson/')
 *   demoFrontsDir  load synthetic fronts named by hours from the current
 *                  cycle (Pacific_p024.geo.json, Atlantic_m006.geo.json)
 *   demoHours      hours that have demo files
 *   homeLink       { href, label } shown in the top bar
 */
(function () {
    'use strict';

    var Ndfd = window.Oceanic.Ndfd;
    var Pgen = window.Oceanic.Pgen;

    var CONFIG = window.OCEANIC_CONFIG || {};
    var GEOJSON_DIR = CONFIG.geojsonDir || '/data/geoJson/';
    var DEMO_FRONTS_DIR = CONFIG.demoFrontsDir || null;
    var DEMO_HOURS = CONFIG.demoHours || [-12, -6, 0, 24, 48, 72, 96];

    var ESRI_OCEAN = 'https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Base/MapServer/tile/{z}/{y}/{x}';
    var WARNINGS_WMS = 'https://mapservices.weather.noaa.gov/eventdriven/services/WWA/watch_warn_adv/MapServer/WMSServer';
    var HAZARDS_QUERY = 'https://mapservices.weather.noaa.gov/eventdriven/rest/services/WWA/watch_warn_adv/MapServer/1/query';
    var BASINS = ['Pacific', 'Atlantic'];

    var MIN_H = -12;
    var MAX_H = 96;
    var STEP_H = 3;
    var HOUR_MS = 3600000;
    var PLAY_DWELL_MS = 1100;
    var HOME_VIEW = { center: [-105, 35], zoom: 1.8, bearing: 0, pitch: 0 };
    var PREFS_KEY = 'oceanic.prefs.v1';
    var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var ICONS = {
        layers: '<path d="M12 3 2 8l10 5 10-5-10-5Z"/><path d="m2 13 10 5 10-5"/><path d="m2 18 10 5 10-5" opacity=".5"/>',
        help: '<circle cx="12" cy="12" r="9.5"/><path d="M9.5 9.2a2.6 2.6 0 0 1 5 1c0 1.8-2.5 2.2-2.5 3.8"/><circle cx="12" cy="17.3" r=".6" fill="currentColor"/>',
        close: '<path d="M6 6l12 12M18 6 6 18"/>',
        prev: '<path d="M15 6l-6 6 6 6"/>',
        next: '<path d="M9 6l6 6-6 6"/>',
        play: '<path d="M8 5.5v13l10.5-6.5L8 5.5Z" fill="currentColor"/>',
        pause: '<path d="M8 5v14M16 5v14" stroke-width="3"/>',
        waves: '<path d="M2 9c2.5-2.5 4.5-2.5 7 0s4.5 2.5 7 0 4.5-2.5 6-1"/><path d="M2 15c2.5-2.5 4.5-2.5 7 0s4.5 2.5 7 0 4.5-2.5 6-1"/>',
        wind: '<path d="M3 8h11a3 3 0 1 0-3-3"/><path d="M3 12h16a3 3 0 1 1-3 3"/><path d="M3 16h7"/>',
        gust: '<path d="M3 7h9a2.5 2.5 0 1 0-2.5-2.5"/><path d="M3 12h15a3 3 0 1 1-3 3"/><path d="M3 17h5M12 17h2"/>',
        barb: '<path d="M4 20 18 6"/><path d="m18 6 3 4M15 9l3 4M12 12l1.6 2.2"/><circle cx="4" cy="20" r="1.4" fill="currentColor"/>'
    };

    var $ = function (id) { return document.getElementById(id); };

    // --- State ------------------------------------------------------------
    var state = {
        baseTime: getBaseTime(),
        hour: 0,
        product: 'waveh',
        opacity: 0.7,
        scale: 1,
        overlays: { fronts: true, isobars: true, centers: true, warnings: false },
        playing: false,
        grid: null // last resolved grid time { status, ms }
    };

    var map;
    var gridLayer;
    var popup = null;
    var applySeq = 0;
    var applyTimer = null;
    var frontsCache = new Map();

    // --- Utilities --------------------------------------------------------
    function getBaseTime() {
        var d = new Date();
        d.setUTCHours(Math.floor(d.getUTCHours() / 6) * 6, 0, 0, 0);
        return d.getTime();
    }

    function validMs(h) {
        return state.baseTime + h * HOUR_MS;
    }

    function pad(n, width) {
        return String(n).padStart(width, '0');
    }

    function sleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function icon(name) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" ' +
            'stroke-linecap="round" stroke-linejoin="round">' + ICONS[name] + '</svg>';
    }

    function fmtValid(ms) {
        var d = new Date(ms);
        return DAYS[d.getUTCDay()] + ' ' + pad(d.getUTCDate(), 2) + ' ' + MONTHS[d.getUTCMonth()] +
            ' · ' + pad(d.getUTCHours(), 2) + ':00 UTC';
    }

    function fmtShort(ms) {
        var d = new Date(ms);
        return DAYS[d.getUTCDay()] + ' ' + pad(d.getUTCHours(), 2) + 'Z';
    }

    function fmtCycle(ms) {
        var d = new Date(ms);
        return pad(d.getUTCHours(), 2) + 'Z ' + pad(d.getUTCDate(), 2) + ' ' + MONTHS[d.getUTCMonth()];
    }

    function fmtHour(h) {
        return (h > 0 ? '+' : h < 0 ? '−' : '') + Math.abs(h) + ' h';
    }

    function fmtLatLon(lat, lng, digits) {
        return Math.abs(lat).toFixed(digits) + '°' + (lat >= 0 ? 'N' : 'S') + ' ' +
            Math.abs(lng).toFixed(digits) + '°' + (lng >= 0 ? 'E' : 'W');
    }

    function fmtDegMin(lat, lng) {
        function dm(v) {
            var a = Math.abs(v);
            var deg = Math.floor(a);
            var min = Math.round((a - deg) * 60);
            if (min === 60) { deg += 1; min = 0; }
            return deg + '°' + pad(min, 2) + '′';
        }
        return dm(lat) + (lat >= 0 ? 'N' : 'S') + '  ' + dm(lng) + (lng >= 0 ? 'E' : 'W');
    }

    function isMobile() {
        return window.matchMedia('(max-width: 760px)').matches;
    }

    function loadPrefs() {
        try {
            var p = JSON.parse(localStorage.getItem(PREFS_KEY) || 'null');
            if (!p) return;
            if (p.product && Ndfd.product(p.product).id === p.product) state.product = p.product;
            if (p.opacity >= 0.2 && p.opacity <= 1) state.opacity = p.opacity;
            if (p.scale >= 0.75 && p.scale <= 2) state.scale = p.scale;
            if (p.overlays) {
                Object.keys(state.overlays).forEach(function (k) {
                    if (typeof p.overlays[k] === 'boolean') state.overlays[k] = p.overlays[k];
                });
            }
        } catch (e) { /* storage unavailable: use defaults */ }
    }

    function savePrefs() {
        try {
            localStorage.setItem(PREFS_KEY, JSON.stringify({
                product: state.product, opacity: state.opacity, scale: state.scale, overlays: state.overlays
            }));
        } catch (e) { /* ignore */ }
    }

    // --- Status: chips, progress bar, toasts ---------------------------------
    function setChip(id, chipState, text, title) {
        var el = $(id);
        el.dataset.state = chipState;
        el.querySelector('span').textContent = text;
        el.title = title || text;
    }

    var progressCount = 0;
    var progressTimer = null;

    function progress(delta) {
        progressCount = Math.max(0, progressCount + delta);
        clearTimeout(progressTimer);
        if (progressCount > 0) {
            // Only show for loads that take a noticeable time
            progressTimer = setTimeout(function () { $('progress').classList.add('active'); }, 150);
        } else {
            $('progress').classList.remove('active');
        }
    }

    var toastTimes = {};

    function toast(message, opts) {
        opts = opts || {};
        var key = opts.key || message;
        var now = Date.now();
        if (opts.throttle && toastTimes[key] && now - toastTimes[key] < opts.throttle) return;
        toastTimes[key] = now;

        var existing = document.querySelector('.toast[data-key="' + CSS.escape(key) + '"]');
        if (existing) existing.remove();

        var el = document.createElement('div');
        el.className = 'toast toast-' + (opts.kind || 'info');
        el.dataset.key = key;
        var msg = document.createElement('span');
        msg.textContent = message;
        el.appendChild(msg);
        if (opts.action) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-small';
            btn.textContent = opts.action.label;
            btn.addEventListener('click', function () { el.remove(); opts.action.run(); });
            el.appendChild(btn);
        }
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'icon-btn icon-btn-xs';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = icon('close');
        close.addEventListener('click', function () { el.remove(); });
        el.appendChild(close);

        $('toasts').appendChild(el);
        if (opts.timeout !== 0) {
            setTimeout(function () { el.remove(); }, opts.timeout || 7000);
        }
    }

    // --- Fronts (PGEN) --------------------------------------------------------
    // Cycle that issued a fronts chart valid at validTime: analyses every 6 h,
    // F024/F048 from 00Z & 12Z, F072/F096 from 12Z only.
    function getBestFrontsFile(validTime) {
        for (var hoursBack = 0; hoursBack <= 96; hoursBack += 6) {
            var cycle = state.baseTime - hoursBack * HOUR_MS;
            var fHour = (validTime - cycle) / HOUR_MS;
            var cycleH = new Date(cycle).getUTCHours();
            if (fHour === 0 ||
                ((fHour === 24 || fHour === 48) && (cycleH === 0 || cycleH === 12)) ||
                ((fHour === 72 || fHour === 96) && cycleH === 12)) {
                return { cycle: cycle, fHour: fHour };
            }
        }
        return null;
    }

    function frontsSource(h) {
        if (DEMO_FRONTS_DIR) {
            if (DEMO_HOURS.indexOf(h) < 0) return null;
            var tag = (h < 0 ? 'm' : 'p') + pad(Math.abs(h), 3);
            return {
                key: 'demo' + tag,
                label: h === 0 ? 'Demo analysis' : 'Demo ' + fmtHour(h),
                paths: BASINS.map(function (b) { return DEMO_FRONTS_DIR + b + '_' + tag + '.geo.json'; })
            };
        }
        var info = getBestFrontsFile(validMs(h));
        if (!info) return null;
        var d = new Date(info.cycle);
        var stamp = d.getUTCFullYear() + pad(d.getUTCMonth() + 1, 2) + pad(d.getUTCDate(), 2) + '.' +
            pad(d.getUTCHours(), 2) + '00.F' + pad(info.fHour, 3);
        var cycleZ = pad(d.getUTCHours(), 2) + 'Z';
        return {
            key: stamp,
            label: info.fHour === 0 ? 'Analysis ' + cycleZ : 'F' + pad(info.fHour, 3) + ' · ' + cycleZ + ' cycle',
            paths: BASINS.map(function (b) { return GEOJSON_DIR + b + '_HS_Surface.' + stamp + '.geo.json'; })
        };
    }

    // [] for a missing basin file (404); throws on network/server errors
    function fetchFeatures(path) {
        var ctrl = new AbortController();
        var timer = setTimeout(function () { ctrl.abort(); }, 15000);
        return fetch(path, { signal: ctrl.signal }).then(function (r) {
            clearTimeout(timer);
            if (r.status === 404) return [];
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json().then(function (j) { return (j && j.features) || []; });
        }, function (err) {
            clearTimeout(timer);
            throw err;
        });
    }

    // -> Promise<{ fc } | { empty: true } | { error }>, cached per chart
    function loadFronts(src) {
        if (frontsCache.has(src.key)) return frontsCache.get(src.key);
        var p = Promise.all(src.paths.map(fetchFeatures)).then(function (results) {
            var all = [].concat.apply([], results);
            if (!all.length) return { empty: true };
            return { fc: Pgen.process(all) };
        }).catch(function (err) {
            frontsCache.delete(src.key); // retry on next visit
            return { error: err };
        });
        frontsCache.set(src.key, p);
        return p;
    }

    function nextFrontsHour(h) {
        for (var x = h + STEP_H; x <= MAX_H; x += STEP_H) {
            if (frontsSource(x)) return x;
        }
        return null;
    }

    async function updateFronts(h, seq) {
        var src = frontsSource(h);
        if (!src) {
            Pgen.setData(null);
            var next = nextFrontsHour(h);
            setChip('chipFronts', 'none', 'No fronts this step',
                next === null ? 'No fronts chart for this valid time' :
                    'No fronts chart for this valid time. Next: ' + fmtHour(next));
            return;
        }
        if (!state.playing) setChip('chipFronts', 'loading', 'Fronts · loading');
        progress(1);
        var result = await loadFronts(src);
        progress(-1);
        if (seq !== applySeq) return;
        if (result.fc) {
            Pgen.setData(result.fc);
            setChip('chipFronts', 'ok', 'Fronts · ' + src.label);
        } else if (result.empty) {
            Pgen.setData(null);
            setChip('chipFronts', 'warn', 'Fronts · not issued', 'No fronts files found for ' + src.label);
        } else {
            Pgen.setData(null);
            setChip('chipFronts', 'error', 'Fronts · unavailable', 'Could not load fronts (' + result.error.message + ')');
            toast('Fronts could not be loaded. They will be retried on the next step.', { kind: 'warn', key: 'fronts-error', throttle: 60000 });
        }
    }

    // --- NDFD grid ------------------------------------------------------------
    async function updateGrid(seq) {
        var p = Ndfd.product(state.product);
        var ms = validMs(state.hour);

        // During playback the progress bar suffices; don't flash the chip each frame
        if (!state.playing) setChip('chipGrid', 'loading', p.short + ' · loading');
        progress(1);
        try {
            await Ndfd.loadTimes(p.id);
            if (seq !== applySeq) return;

            var grid = Ndfd.resolveGridTime(p.id, ms);
            state.grid = grid;
            if (grid.status === 'none') {
                gridLayer.hide();
                var range = Ndfd.range(p.id);
                var why = !range ? '' : ms < range.first ? 'NDFD keeps no past grids' : 'NDFD runs to ' + fmtShort(range.last);
                setChip('chipGrid', 'none', 'No ' + p.short.toLowerCase() + ' grid', 'No NDFD grid for this valid time. ' + why);
                return;
            }

            var result = await gridLayer.show(Ndfd.tileUrl(p.id, grid));
            if (result === 'stale' || seq !== applySeq) return;

            if (grid.status === 'unknown') {
                setChip('chipGrid', 'warn', p.short + ' · unverified', 'Grid availability could not be checked; the server may have no grid for this time.');
            } else if (result === 'timeout') {
                setChip('chipGrid', 'warn', p.short + ' · slow', 'The NDFD server is responding slowly; some tiles may be missing.');
            } else if (grid.status === 'nearest') {
                setChip('chipGrid', 'ok', p.short + ' · ' + fmtShort(grid.ms), 'Nearest NDFD grid: valid ' + fmtValid(grid.ms));
            } else {
                setChip('chipGrid', 'ok', p.name);
            }
        } finally {
            progress(-1);
        }
    }

    // --- Time --------------------------------------------------------------
    function applyTime() {
        if (!gridLayer) return Promise.resolve(); // map not ready; onMapLoad applies
        var seq = ++applySeq;
        return Promise.all([updateGrid(seq), updateFronts(state.hour, seq)]);
    }

    function renderTimeLabels() {
        var h = state.hour;
        var ms = validMs(h);
        $('validTime').textContent = fmtValid(ms);
        $('validRel').textContent = (h === 0 ? 'Analysis' : fmtHour(h)) + ' · ' + fmtCycle(state.baseTime) + ' cycle';
        $('validTime').title = 'Local: ' + new Date(ms).toLocaleString();
        $('tlHour').textContent = h === 0 ? '0 h' : fmtHour(h);
        $('tlCycle').textContent = fmtShort(ms);
        var slider = $('timeSlider');
        slider.value = h;
        slider.setAttribute('aria-valuetext', fmtValid(ms) + ', ' + fmtHour(h));
    }

    // immediate: apply now (buttons, playback); otherwise debounce (dragging)
    function setHour(h, immediate) {
        h = Math.max(MIN_H, Math.min(MAX_H, h));
        state.hour = h;
        renderTimeLabels();
        clearTimeout(applyTimer);
        if (immediate) return applyTime();
        applyTimer = setTimeout(applyTime, 160);
        return Promise.resolve();
    }

    function step(delta) {
        stopPlay();
        setHour(state.hour + delta * STEP_H, true);
    }

    function hasAnything(h) {
        var g = Ndfd.resolveGridTime(state.product, validMs(h)).status;
        return g !== 'none' || !!frontsSource(h);
    }

    function nextPlayable(h) {
        for (var i = 1; i <= (MAX_H - MIN_H) / STEP_H + 1; i++) {
            var x = h + i * STEP_H;
            if (x > MAX_H) x = MIN_H + (x - MAX_H - STEP_H);
            if (hasAnything(x)) return x;
        }
        return null;
    }

    async function play() {
        if (state.playing) return;
        state.playing = true;
        renderPlayButton();
        while (state.playing) {
            var t0 = performance.now();
            var next = nextPlayable(state.hour);
            if (next === null) break;
            var wrapped = next < state.hour;
            await setHour(next, true);
            if (!state.playing) break;
            var after = nextPlayable(next);
            var src = after === null ? null : frontsSource(after);
            if (src) loadFronts(src); // prefetch
            var wait = PLAY_DWELL_MS + (wrapped ? 800 : 0) - (performance.now() - t0);
            if (wait > 0) await sleep(wait);
        }
        stopPlay();
    }

    function stopPlay() {
        if (!state.playing) return;
        state.playing = false;
        renderPlayButton();
    }

    function renderPlayButton() {
        var btn = $('btnPlay');
        btn.innerHTML = icon(state.playing ? 'pause' : 'play');
        btn.setAttribute('aria-label', state.playing ? 'Pause' : 'Play');
        btn.setAttribute('aria-pressed', String(state.playing));
    }

    // Re-anchor on a newer 6-hourly cycle, keeping the same valid time
    function reanchor() {
        var keep = validMs(state.hour);
        state.baseTime = getBaseTime();
        frontsCache.clear();
        renderTimeline();
        setHour(Math.round((keep - state.baseTime) / (STEP_H * HOUR_MS)) * STEP_H, true);
    }

    // --- Timeline ------------------------------------------------------------
    function pct(h) {
        return ((h - MIN_H) / (MAX_H - MIN_H)) * 100;
    }

    function renderTimeline() {
        var ticks = $('tlTicks');
        ticks.innerHTML = '';
        for (var h = MIN_H; h <= MAX_H; h += STEP_H) {
            var d = new Date(validMs(h));
            var tick = document.createElement('div');
            tick.className = 'tick';
            tick.style.left = pct(h) + '%';
            if (d.getUTCHours() === 0) {
                tick.classList.add('tick-day');
                tick.innerHTML = '<span>' + DAYS[d.getUTCDay()] + ' ' + pad(d.getUTCDate(), 2) + '</span>';
            } else if (d.getUTCHours() === 12) {
                tick.classList.add('tick-mid');
                tick.innerHTML = '<span>12Z</span>';
            }
            ticks.appendChild(tick);
        }
        renderAvailability();
        renderNow();
    }

    function renderAvailability() {
        var avail = $('tlAvail');
        avail.innerHTML = '';
        for (var h = MIN_H; h <= MAX_H; h += STEP_H) {
            var g = Ndfd.resolveGridTime(state.product, validMs(h)).status;
            var seg = document.createElement('div');
            seg.className = 'seg seg-' + ({ exact: 'on', nearest: 'near', none: 'off', unknown: 'unk' }[g]);
            var left = Math.max(0, pct(h - STEP_H / 2));
            var right = Math.min(100, pct(h + STEP_H / 2));
            seg.style.left = left + '%';
            seg.style.width = (right - left) + '%';
            avail.appendChild(seg);
            if (frontsSource(h)) {
                var dot = document.createElement('div');
                dot.className = 'fronts-dot';
                dot.style.left = pct(h) + '%';
                avail.appendChild(dot);
            }
        }
    }

    function renderNow() {
        var h = (Date.now() - state.baseTime) / HOUR_MS;
        var el = $('tlNow');
        el.hidden = h < MIN_H || h > MAX_H;
        el.style.left = pct(h) + '%';
    }

    // --- Legend --------------------------------------------------------------
    var BARB_KEY =
        '<svg viewBox="0 0 220 34" class="barb-key" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">' +
        '<g transform="translate(10 26)"><circle r="2" fill="currentColor"/><path d="M0 0 32 0M32 0l4 -6"/></g>' +
        '<g transform="translate(80 26)"><circle r="2" fill="currentColor"/><path d="M0 0 32 0M32 0l6 -12"/></g>' +
        '<g transform="translate(150 26)"><circle r="2" fill="currentColor"/><path d="M0 0 32 0"/><path d="M32 0 28 -12 24 0Z" fill="currentColor"/></g>' +
        '</svg><div class="barb-labels"><span>5 kt</span><span>10 kt</span><span>50 kt</span></div>';

    async function renderLegend() {
        var p = Ndfd.product(state.product);
        var el = $('legend');
        var head = '<div class="legend-head"><span>' + escapeHtml(p.name) + '</span><span class="unit">' + p.unit + '</span></div>';
        if (p.legend === 'barbs') {
            el.innerHTML = head + BARB_KEY;
            return;
        }
        el.innerHTML = head + '<div class="legend-bar legend-loading"></div>';
        var stops = await Ndfd.legend(p.id);
        if (state.product !== p.id) return;
        if (!stops) {
            el.innerHTML = head + '<div class="legend-empty">Legend unavailable</div>';
            return;
        }
        var n = stops.length;
        var gradient = stops.map(function (s, i) {
            return s[1] + ' ' + ((i / (n - 1)) * 100).toFixed(2) + '%';
        }).join(', ');
        var every = n > 12 ? 2 : 1;
        var labels = stops.map(function (s, i) {
            if (i % every && i !== n - 1) return '';
            return '<span style="left:' + ((i / (n - 1)) * 100).toFixed(2) + '%">' + s[0] + '</span>';
        }).join('');
        el.innerHTML = head + '<div class="legend-bar" style="background:linear-gradient(90deg,' + gradient + ')"></div>' +
            '<div class="legend-labels">' + labels + '</div>';
    }

    // --- Layer panel ------------------------------------------------------------
    function renderProducts() {
        var list = $('productList');
        list.innerHTML = '';
        Ndfd.PRODUCTS.forEach(function (p, i) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'product';
            btn.setAttribute('role', 'radio');
            btn.dataset.product = p.id;
            btn.innerHTML = icon(p.icon) + '<span class="product-name">' + escapeHtml(p.name) + '</span>' +
                '<span class="product-unit">' + p.unit + '</span><kbd>' + (i + 1) + '</kbd>';
            btn.addEventListener('click', function () { setProduct(p.id); });
            list.appendChild(btn);
        });
        markProduct();
    }

    function markProduct() {
        document.querySelectorAll('.product').forEach(function (b) {
            var on = b.dataset.product === state.product;
            b.setAttribute('aria-checked', String(on));
            b.tabIndex = on ? 0 : -1;
        });
    }

    function setProduct(id) {
        if (id === state.product) return;
        state.product = id;
        savePrefs();
        markProduct();
        renderLegend();
        renderAvailability();
        Ndfd.loadTimes(id).then(function () {
            if (state.product === id) renderAvailability();
        });
        applyTime();
    }

    function setOverlay(name, on) {
        state.overlays[name] = on;
        savePrefs();
        var input = document.querySelector('[data-overlay="' + name + '"]');
        if (input) input.checked = on;
        if (!gridLayer) return; // applied in onMapLoad
        if (name === 'warnings') {
            map.setLayoutProperty('warnings', 'visibility', on ? 'visible' : 'none');
        } else {
            Pgen.setGroupVisible(name, on);
        }
    }

    function setPanelOpen(open) {
        document.body.classList.toggle('panel-open', open);
        $('btnLayers').setAttribute('aria-expanded', String(open));
        $('scrim').hidden = !(open && isMobile());
    }

    // Re-read the server's time list (at most once a minute) and re-apply
    // the current step if its grid availability changed.
    var lastRefresh = 0;

    function refreshAvailability() {
        if (Date.now() - lastRefresh < 60000) return;
        lastRefresh = Date.now();
        var before = Ndfd.resolveGridTime(state.product, validMs(state.hour));
        Ndfd.loadTimes(state.product, true).then(function () {
            renderAvailability();
            var after = Ndfd.resolveGridTime(state.product, validMs(state.hour));
            if (after.status !== before.status || after.ms !== before.ms) applyTime();
        });
    }

    // --- Click readout ------------------------------------------------------------
    function hazardRows(json) {
        var feats = (json && json.features) || [];
        var seen = {};
        var rows = [];
        feats.forEach(function (f) {
            var a = f.attributes || {};
            var key = a.prod_type + '|' + a.expiration;
            if (!a.prod_type || seen[key]) return;
            seen[key] = true;
            var kind = /warning/i.test(a.prod_type) ? 'warning' : /watch/i.test(a.prod_type) ? 'watch' : 'advisory';
            var until = a.expiration ? Date.parse(a.expiration) : NaN;
            rows.push('<li class="hz hz-' + kind + '"><span>' + escapeHtml(a.prod_type) + '</span>' +
                (isNaN(until) ? '' : '<time>until ' + fmtShort(until) + '</time>') + '</li>');
        });
        return rows;
    }

    function queryHazards(lng, lat) {
        var url = HAZARDS_QUERY + '?geometry=' + lng.toFixed(4) + ',' + lat.toFixed(4) +
            '&geometryType=esriGeometryPoint&inSR=4326&spatialRel=esriSpatialRelIntersects' +
            '&outFields=prod_type,expiration&returnGeometry=false&f=json';
        return Ndfd.fetchWithTimeout(url, 15000).then(function (r) { return r.json(); });
    }

    function valueRow(label, value) {
        return '<div class="ro-row"><span>' + label + '</span><strong>' + value + '</strong></div>';
    }

    function openReadout(lngLat) {
        var ll = lngLat.wrap();
        var grid = state.grid || Ndfd.resolveGridTime(state.product, validMs(state.hour));
        if (popup) popup.remove();
        popup = new maplibregl.Popup({ className: 'readout', maxWidth: '300px', focusAfterOpen: false })
            .setLngLat(lngLat)
            .setHTML(
                '<div class="ro-head"><strong>' + fmtDegMin(ll.lat, ll.lng) + '</strong>' +
                '<span>' + (grid.status === 'nearest' ? 'Grid valid ' : 'Valid ') + fmtValid(grid.ms) + '</span></div>' +
                '<div class="ro-values"><div class="ro-skel"></div><div class="ro-skel"></div><div class="ro-skel"></div></div>' +
                '<div class="ro-sub">Active hazards (current)</div><ul class="ro-hazards"><li class="ro-skel"></li></ul>')
            .addTo(map);
        var current = popup;
        var root = current.getElement();

        var valuesEl = root.querySelector('.ro-values');
        if (grid.status === 'none') {
            valuesEl.innerHTML = '<div class="ro-note">No NDFD grid for this valid time.</div>';
        } else {
            Ndfd.pointValues(ll.lng, ll.lat, grid.ms).then(function (v) {
                if (popup !== current) return;
                if (v.waveh === undefined && v.wspd === undefined && v.wgust === undefined) {
                    valuesEl.innerHTML = '<div class="ro-note">No forecast data here (land or outside NDFD coverage).</div>';
                    return;
                }
                valuesEl.innerHTML =
                    valueRow('Wave height', v.waveh === undefined ? '—' : v.waveh.toFixed(1) + ' ft <small>' + (v.waveh * 0.3048).toFixed(1) + ' m</small>') +
                    valueRow('Wind speed', v.wspd === undefined ? '—' : Math.round(v.wspd) + ' kt') +
                    valueRow('Wind gust', v.wgust === undefined ? '—' : Math.round(v.wgust) + ' kt');
            }).catch(function (err) {
                if (popup !== current) return;
                valuesEl.innerHTML = err.code === 'nogrid' ?
                    '<div class="ro-note">This grid is no longer on the NDFD server.</div>' :
                    '<div class="ro-note ro-error">Point values unavailable (NDFD server did not respond).</div>';
                if (err.code === 'nogrid') refreshAvailability();
            });
        }

        var hzEl = root.querySelector('.ro-hazards');
        queryHazards(ll.lng, ll.lat).then(function (json) {
            if (popup !== current) return;
            var rows = hazardRows(json);
            hzEl.innerHTML = rows.length ? rows.join('') : '<li class="ro-note">None in effect</li>';
        }).catch(function () {
            if (popup === current) hzEl.innerHTML = '<li class="ro-note ro-error">Hazard lookup unavailable</li>';
        });
    }

    // --- Map ---------------------------------------------------------------
    function createMap() {
        map = new maplibregl.Map({
            container: 'map',
            center: HOME_VIEW.center,
            zoom: HOME_VIEW.zoom,
            maxZoom: 12,
            attributionControl: false,
            style: {
                version: 8,
                projection: { type: 'globe' },
                sky: { 'atmosphere-blend': ['interpolate', ['linear'], ['zoom'], 0, 1, 5, 1, 7, 0] },
                sources: {
                    ocean: { type: 'raster', tiles: [ESRI_OCEAN], tileSize: 256, maxzoom: 13, attribution: 'Basemap &copy; Esri' },
                    warnings: {
                        type: 'raster', tileSize: 256, attribution: 'Hazards &copy; NOAA/NWS',
                        tiles: [WARNINGS_WMS + '?SERVICE=WMS&REQUEST=GetMap&VERSION=1.3.0&LAYERS=1&STYLES=&FORMAT=image/png' +
                            '&TRANSPARENT=true&CRS=EPSG:3857&WIDTH=256&HEIGHT=256&BBOX={bbox-epsg-3857}']
                    }
                },
                layers: [
                    { id: 'ocean', type: 'raster', source: 'ocean', paint: { 'raster-saturation': -0.15, 'raster-brightness-max': 0.95 } },
                    { id: 'warnings', type: 'raster', source: 'warnings', layout: { visibility: 'none' }, paint: { 'raster-opacity': 0.75 } }
                ]
            }
        });

        map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');
        map.addControl(new maplibregl.AttributionControl({ compact: true }), 'bottom-left');
        map.addControl(new maplibregl.ScaleControl({ unit: 'nautical' }), 'bottom-left');
    }

    function onMapLoad() {
        gridLayer = new Ndfd.GridLayer(map, 'warnings', state.opacity);
        Pgen.init(map, state.scale);
        Object.keys(state.overlays).forEach(function (k) { setOverlay(k, state.overlays[k]); });

        map.on('click', function (e) { openReadout(e.lngLat); });

        var coordsEl = $('coords');
        var pending = null;
        map.on('mousemove', function (e) {
            pending = e.lngLat;
            requestAnimationFrame(function () {
                if (!pending) return;
                var ll = pending.wrap();
                coordsEl.textContent = fmtLatLon(ll.lat, ll.lng, 2);
                pending = null;
            });
        });
        map.getCanvas().addEventListener('mouseleave', function () { coordsEl.textContent = ''; });

        var gridErrors = 0;
        map.on('error', function (e) {
            var sid = e.sourceId || (e.source && e.source.id);
            if (gridLayer.isGridSource(sid)) {
                gridErrors++;
                // Usually the requested time just rolled off the server
                refreshAvailability();
                if (gridErrors >= 6) {
                    toast('Some NDFD tiles failed to load. The server may be busy.', { kind: 'warn', key: 'grid-tiles', throttle: 60000 });
                }
            } else if (sid === 'ocean') {
                toast('Basemap tiles failed to load.', { kind: 'warn', key: 'basemap', throttle: 120000 });
            } else if (sid === 'warnings') {
                toast('Watches & warnings overlay failed to load.', { kind: 'warn', key: 'warnings', throttle: 120000 });
            }
        });
        map.on('dataloading', function () { gridErrors = 0; });

        // Start with attribution collapsed on small screens (it sits beside the legend)
        if (isMobile()) {
            var attrib = document.querySelector('.maplibregl-ctrl-attrib');
            if (attrib) attrib.classList.remove('maplibregl-compact-show');
        }

        applyTime();
    }

    // --- Wiring -----------------------------------------------------------
    function bindUi() {
        $('btnLayers').innerHTML = icon('layers');
        $('btnHelp').innerHTML = icon('help');
        $('btnPanelClose').innerHTML = icon('close');
        $('btnHelpClose').innerHTML = icon('close');
        $('btnPrev').innerHTML = icon('prev');
        $('btnNext').innerHTML = icon('next');
        renderPlayButton();

        $('btnLayers').addEventListener('click', function () {
            setPanelOpen(!document.body.classList.contains('panel-open'));
        });
        $('btnPanelClose').addEventListener('click', function () { setPanelOpen(false); });
        $('scrim').addEventListener('click', function () { setPanelOpen(false); });

        var dialog = $('helpDialog');
        $('btnHelp').addEventListener('click', function () { dialog.showModal(); });
        $('btnHelpClose').addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });

        $('btnPrev').addEventListener('click', function () { step(-1); });
        $('btnNext').addEventListener('click', function () { step(1); });
        $('btnPlay').addEventListener('click', function () { state.playing ? stopPlay() : play(); });

        $('timeSlider').addEventListener('input', function () {
            stopPlay();
            setHour(parseInt(this.value, 10), false);
        });

        $('opacity').value = state.opacity;
        $('opacityOut').textContent = Math.round(state.opacity * 100) + '%';
        $('opacity').addEventListener('input', function () {
            state.opacity = parseFloat(this.value);
            $('opacityOut').textContent = Math.round(state.opacity * 100) + '%';
            if (gridLayer) gridLayer.setOpacity(state.opacity);
            savePrefs();
        });

        $('sizeSlider').value = state.scale;
        $('sizeOut').textContent = state.scale.toFixed(2).replace(/0$/, '') + '×';
        $('sizeSlider').addEventListener('input', function () {
            state.scale = parseFloat(this.value);
            $('sizeOut').textContent = state.scale.toFixed(2).replace(/0$/, '') + '×';
            if (map && map.getSource('pgen')) Pgen.setScale(state.scale);
            savePrefs();
        });

        document.querySelectorAll('[data-overlay]').forEach(function (input) {
            input.checked = state.overlays[input.dataset.overlay];
            input.addEventListener('change', function () {
                setOverlay(input.dataset.overlay, input.checked);
            });
        });

        // Arrow keys move between products like a radio group
        $('productList').addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            e.preventDefault();
            var ids = Ndfd.PRODUCTS.map(function (p) { return p.id; });
            var i = ids.indexOf(state.product) + (e.key === 'ArrowDown' ? 1 : -1);
            setProduct(ids[(i + ids.length) % ids.length]);
            document.querySelector('.product[data-product="' + state.product + '"]').focus();
        });

        document.addEventListener('keydown', onKey);

        if (CONFIG.homeLink) {
            var a = $('homeLink');
            a.href = CONFIG.homeLink.href;
            a.textContent = CONFIG.homeLink.label;
            a.hidden = false;
        }
        if (DEMO_FRONTS_DIR) $('chipDemo').hidden = false;
    }

    function onKey(e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        var t = e.target;
        var tag = t && t.tagName;
        if (tag === 'SELECT' || tag === 'TEXTAREA' || (tag === 'INPUT' && t.type !== 'checkbox')) return;
        if ($('helpDialog').open && e.key !== '?') return;

        var key = e.key;
        var handled = true;
        if (key === 'ArrowLeft') step(-1);
        else if (key === 'ArrowRight') step(1);
        else if (key === ' ' && tag !== 'BUTTON') { state.playing ? stopPlay() : play(); }
        else if (key === 'Home') { stopPlay(); setHour(MIN_H, true); }
        else if (key === 'End') { stopPlay(); setHour(MAX_H, true); }
        else if (key >= '1' && key <= String(Ndfd.PRODUCTS.length)) setProduct(Ndfd.PRODUCTS[parseInt(key, 10) - 1].id);
        else if (key === 'f' || key === 'F') setOverlay('fronts', !state.overlays.fronts);
        else if (key === 'i' || key === 'I') setOverlay('isobars', !state.overlays.isobars);
        else if (key === 'c' || key === 'C') setOverlay('centers', !state.overlays.centers);
        else if (key === 'w' || key === 'W') setOverlay('warnings', !state.overlays.warnings);
        else if (key === 'l' || key === 'L') setPanelOpen(!document.body.classList.contains('panel-open'));
        else if (key === 'r' || key === 'R') map.easeTo(Object.assign({ duration: 800 }, HOME_VIEW));
        else if (key === '?') { var d = $('helpDialog'); d.open ? d.close() : d.showModal(); }
        else if (key === 'Escape') {
            if (popup) { popup.remove(); popup = null; } else if (isMobile()) setPanelOpen(false);
        } else handled = false;
        if (handled) e.preventDefault();
    }

    // Refresh availability and detect a newer cycle while the page stays open
    function startHousekeeping() {
        setInterval(function () {
            renderNow();
            Ndfd.loadTimes(state.product).then(renderAvailability);
            var latest = getBaseTime();
            if (latest > state.baseTime) {
                toast('A newer forecast cycle (' + fmtCycle(latest) + ') is available.', {
                    key: 'new-cycle', timeout: 0,
                    action: { label: 'Update', run: reanchor }
                });
            }
        }, 5 * 60 * 1000);
    }

    function init() {
        loadPrefs();
        renderProducts();
        bindUi();
        renderTimeLabels();
        renderTimeline();
        renderLegend();
        setPanelOpen(!isMobile());

        Ndfd.loadTimes(state.product).then(function (c) {
            renderAvailability();
            if (c.state === 'error') {
                toast('Could not check which NDFD grids are available. Grids will still be requested.', { kind: 'warn', key: 'caps' });
            }
        });

        try {
            createMap();
        } catch (err) {
            $('fatal').hidden = false;
            $('fatalMsg').textContent = 'This page needs a browser with WebGL enabled (' + err.message + ').';
            return;
        }
        map.on('load', onMapLoad);
        map.on('webglcontextlost', function () {
            toast('The graphics context was lost.', { kind: 'error', key: 'webgl', timeout: 0, action: { label: 'Reload', run: function () { location.reload(); } } });
        });
        startHousekeeping();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
