/**
 * Oceanic Weather Forecast - MapLibre GL (WebGL) globe
 * NDFD WMS grids + OPC PGEN surface fronts, isobars and pressure centers.
 *
 * Everything drawn from the fronts GeoJSON is a native MapLibre layer.
 * Pips, pressure-center symbols and labels are rasterized once into
 * sprite images on a canvas, so no glyph server is needed.
 */

(function () {
    'use strict';

    // --- CONFIG ---
    var NDFD_WMS = 'https://mapservices.weather.noaa.gov/geoserver/ndfd/wms';
    var WARNINGS_WMS = 'https://mapservices.weather.noaa.gov/eventdriven/services/WWA/watch_warn_adv/MapServer/WMSServer';
    var WARNINGS_LAYER = '1'; // WatchesWarnings
    var ESRI_OCEAN = 'https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Base/MapServer/tile/{z}/{y}/{x}';
    // Optional page-level overrides: set window.OCEANIC_CONFIG before this
    // script. demoFrontsDir switches to synthetic fronts files named by
    // hours from the current cycle (e.g. Pacific_p024.geo.json,
    // Atlantic_m006.geo.json) for hosts without the PGEN GeoJSON feed.
    var CONFIG = window.OCEANIC_CONFIG || {};
    var GEOJSON_DIR = CONFIG.geojsonDir || '/data/geoJson/';
    var DEMO_FRONTS_DIR = CONFIG.demoFrontsDir || null;
    var DEMO_HOURS = CONFIG.demoHours || [-12, -6, 0, 24, 48, 72, 96];
    var BASINS = ['Pacific', 'Atlantic'];
    var LOAD_DEBOUNCE_MS = 200;

    // Sprites are rendered above screen resolution so they stay crisp
    // when the feature-size slider scales them up to 2x.
    var SPRITE_RATIO = Math.min(4, 2 * (window.devicePixelRatio || 1));

    var map;
    var symScale = 1.0;
    var currentHour = 0;
    var loadSeq = 0;
    var loadTimer = null;

    // --- 1. TIME, PATH & CADENCE MATH ---
    function getBaseTime() {
        var d = new Date();
        var cycleHour = Math.floor(d.getUTCHours() / 6) * 6;
        d.setUTCHours(cycleHour, 0, 0, 0);
        return d;
    }

    var baseTime = getBaseTime();

    function getFormattedTime(hoursToAdd) {
        return new Date(baseTime.getTime() + hoursToAdd * 3600000);
    }

    function pad(n, width) {
        return String(n).padStart(width, '0');
    }

    function getGeoJsonPath(basin, bDate, fHour) {
        return GEOJSON_DIR + basin + '_HS_Surface.' +
            bDate.getUTCFullYear() + pad(bDate.getUTCMonth() + 1, 2) + pad(bDate.getUTCDate(), 2) + '.' +
            pad(bDate.getUTCHours(), 2) + '00.F' + pad(fHour, 3) + '.geo.json';
    }

    // Step back through cycles to find the one that issued a fronts
    // forecast valid at targetValidTime (00/06/12/18Z analyses, F024/F048
    // from 00Z & 12Z, F072/F096 from 12Z only).
    function getBestFrontsFile(targetValidTime) {
        for (var hoursBack = 0; hoursBack <= 96; hoursBack += 6) {
            var cycleTime = new Date(baseTime.getTime() - hoursBack * 3600000);
            var fHour = (targetValidTime.getTime() - cycleTime.getTime()) / 3600000;
            var cycleH = cycleTime.getUTCHours();

            var isValid = false;
            if (fHour === 0) isValid = true;
            else if ((fHour === 24 || fHour === 48) && (cycleH === 0 || cycleH === 12)) isValid = true;
            else if ((fHour === 72 || fHour === 96) && cycleH === 12) isValid = true;

            if (isValid) return { base: cycleTime, fHour: fHour };
        }
        return null;
    }

    // --- 2. WMS TILE URLS ---
    function wmsTileUrl(base, params) {
        var url = base + '?SERVICE=WMS&REQUEST=GetMap&VERSION=1.3.0&STYLES=&FORMAT=image/png' +
            '&TRANSPARENT=true&CRS=EPSG:3857&WIDTH=256&HEIGHT=256&BBOX={bbox-epsg-3857}';
        Object.keys(params).forEach(function (k) {
            url += '&' + k + '=' + encodeURIComponent(params[k]);
        });
        return url;
    }

    function ndfdTileUrl() {
        var params = { LAYERS: document.getElementById('layerSelect').value };
        var validTime = getFormattedTime(currentHour);
        // The NDFD TIME dimension starts at the next whole hour, so any valid
        // time already past (including +0 once the cycle has started) must
        // use VTIT: request the 0-hour forecast issued AT that time.
        if (validTime.getTime() <= Date.now()) {
            var timeStr = validTime.toISOString().substring(0, 19) + 'Z';
            params.VTIT = timeStr + '_' + timeStr;
        } else {
            params.TIME = validTime.toISOString();
        }
        return wmsTileUrl(NDFD_WMS, params);
    }

    // --- 3. SPRITES (canvas -> map.addImage) ---
    function makeCanvas(w, h) {
        var canvas = document.createElement('canvas');
        canvas.width = Math.ceil(w * SPRITE_RATIO);
        canvas.height = Math.ceil(h * SPRITE_RATIO);
        var ctx = canvas.getContext('2d');
        ctx.scale(SPRITE_RATIO, SPRITE_RATIO);
        return ctx;
    }

    function addSprite(id, ctx) {
        if (map.hasImage(id)) return;
        var c = ctx.canvas;
        map.addImage(id, ctx.getImageData(0, 0, c.width, c.height), { pixelRatio: SPRITE_RATIO });
    }

    // Front pips. With line placement + map rotation alignment the image
    // +x axis follows the line, so "up" in the image is the left side of
    // the direction of travel.
    function addPipSprites() {
        var ctx;

        function triangle(color) {
            ctx = makeCanvas(16, 16);
            ctx.fillStyle = color;
            ctx.beginPath();
            ctx.moveTo(0, 16);
            ctx.lineTo(8, 0);
            ctx.lineTo(16, 16);
            ctx.closePath();
            ctx.fill();
            return ctx;
        }

        // Semicircle on the left of travel (flat edge on the bottom)
        function semiUp(color) {
            ctx = makeCanvas(16, 8);
            ctx.fillStyle = color;
            ctx.beginPath();
            ctx.arc(8, 8, 8, Math.PI, 0);
            ctx.closePath();
            ctx.fill();
            return ctx;
        }

        // Semicircle on the right of travel (flat edge on the top)
        function semiDown(color) {
            ctx = makeCanvas(16, 8);
            ctx.fillStyle = color;
            ctx.beginPath();
            ctx.arc(8, 0, 8, 0, Math.PI);
            ctx.closePath();
            ctx.fill();
            return ctx;
        }

        addSprite('pip-cold', triangle('#0000FF'));
        addSprite('pip-warm', semiUp('#FF0000'));
        addSprite('pip-stat-warm', semiDown('#FF0000'));
        addSprite('pip-occ-tri', triangle('#800080'));
        addSprite('pip-occ-warm', semiUp('#800080'));
    }

    var HURRICANE_ARMS = [
        'M50,15 c15,0,25,10,30,25 c-5-10-15-15-25-15 a15,15 0 0,0 -5,29.1 z',
        'M50,85 c-15,0-25-10-30-25 c5,10,15,15,25,15 a15,15 0 0,0 5,-29.1 z'
    ];

    function drawHaloText(ctx, text, x, y, color, halo) {
        ctx.lineJoin = 'round';
        ctx.lineWidth = halo * 2;
        ctx.strokeStyle = '#FFFFFF';
        ctx.strokeText(text, x, y);
        ctx.fillStyle = color;
        ctx.fillText(text, x, y);
    }

    var LABEL_FONT = 'bold 14px Arial, Helvetica, sans-serif';
    var LABEL_LINE_H = 17;

    // Pressure-center symbol (H / L / tropical) with its label beneath.
    // The image is padded so the symbol's center sits on the anchor point.
    function pointSpriteId(type, text) {
        var id = 'pt:' + type + ':' + text;
        if (map.hasImage(id)) return id;

        var sz = 28;
        var symbol = null;
        var color = '#000000';
        if (type === 'High') { symbol = 'H'; color = '#0000FF'; }
        else if (type === 'Low' || type === 'TropicalDepression') { symbol = 'L'; color = '#FF0000'; }
        else if (type === 'Hurricane' || type === 'TropicalStorm') { symbol = type; color = '#FF0000'; }
        else if (type === 'Text') { color = '#333333'; }

        var lines = text ? text.split('\n') : [];
        var measure = makeCanvas(1, 1);
        measure.font = LABEL_FONT;
        var textW = 0;
        lines.forEach(function (l) { textW = Math.max(textW, measure.measureText(l).width); });

        var textH = lines.length * LABEL_LINE_H;
        var w = Math.max(symbol ? sz : 0, textW + 6, 1);
        var h = symbol ? sz + 2 * textH : Math.max(textH, 1);
        var ctx = makeCanvas(w, h);
        var cx = w / 2;
        var textTop = symbol ? textH + sz : 0;

        if (symbol === 'H' || symbol === 'L') {
            ctx.font = 'bold ' + sz + 'px Arial, Helvetica, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = color;
            ctx.fillText(symbol, cx, textH + sz / 2 + 1);
        } else if (symbol) {
            ctx.save();
            ctx.translate(cx - sz / 2, textH);
            ctx.scale(sz / 100, sz / 100);
            ctx.fillStyle = '#FF0000';
            HURRICANE_ARMS.forEach(function (d) { ctx.fill(new Path2D(d)); });
            ctx.beginPath();
            if (symbol === 'Hurricane') {
                ctx.arc(50, 50, 15, 0, 2 * Math.PI);
                ctx.fill();
                ctx.beginPath();
                ctx.fillStyle = '#FFFFFF';
                ctx.arc(50, 50, 6, 0, 2 * Math.PI);
                ctx.fill();
            } else {
                ctx.strokeStyle = '#FF0000';
                ctx.lineWidth = 8;
                ctx.arc(50, 50, 15, 0, 2 * Math.PI);
                ctx.stroke();
            }
            ctx.restore();
        }

        ctx.font = LABEL_FONT;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        lines.forEach(function (l, i) {
            drawHaloText(ctx, l, cx, textTop + i * LABEL_LINE_H + LABEL_LINE_H / 2, color, 1.5);
        });

        addSprite(id, ctx);
        return id;
    }

    function isobarSpriteId(value) {
        var id = 'iso:' + value;
        if (map.hasImage(id)) return id;
        var measure = makeCanvas(1, 1);
        measure.font = LABEL_FONT;
        var w = measure.measureText(value).width + 8;
        var ctx = makeCanvas(w, 20);
        ctx.font = LABEL_FONT;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        drawHaloText(ctx, value, w / 2, 10, '#444444', 2);
        addSprite(id, ctx);
        return id;
    }

    // --- 4. DATA PROCESSING ---

    // Keep consecutive longitudes within 180 deg of each other so lines
    // crossing the antimeridian are smoothed and drawn the short way.
    function unwrapLongitudes(coords) {
        var out = [coords[0].slice()];
        for (var i = 1; i < coords.length; i++) {
            var lon = coords[i][0];
            var prev = out[i - 1][0];
            while (lon - prev > 180) lon -= 360;
            while (lon - prev < -180) lon += 360;
            out.push([lon, coords[i][1]]);
        }
        return out;
    }

    function smoothLine(f) {
        var c = unwrapLongitudes(f.geometry.coordinates);
        var line = turf.lineString(c, f.properties);
        if (c.length <= 2) return line;

        var first = c[0];
        var last = c[c.length - 1];
        var isClosed = first[0] === last[0] && first[1] === last[1] && c.length >= 4;
        try {
            if (isClosed) {
                var smoothedPoly = turf.polygonSmooth(turf.polygon([c]), { iterations: 2 });
                return turf.lineString(smoothedPoly.features[0].geometry.coordinates[0], f.properties);
            }
            var smoothed = turf.bezierSpline(line, { resolution: 10000 });
            smoothed.properties = f.properties;
            return smoothed;
        } catch (e) {
            return line;
        }
    }

    function processFeatures(features) {
        var out = [];
        features.forEach(function (f) {
            if (!f || !f.geometry) return;
            var props = Object.assign({}, f.properties || {});
            var feature = { type: 'Feature', geometry: f.geometry, properties: props };

            if (f.geometry.type === 'Point') {
                var text = String(props.pressure_or_text || props.pressure || '');
                props._icon = pointSpriteId(props.featureType || '', text);
                out.push(feature);
                return;
            }

            if (f.geometry.type === 'LineString' && f.geometry.coordinates.length >= 2) {
                feature = smoothLine(feature);
            }

            if (props.featureType === 'Isobar' && props.subtype_or_value != null && props.subtype_or_value !== '') {
                feature.properties._label = isobarSpriteId(String(props.subtype_or_value));
            }
            out.push(feature);
        });
        return { type: 'FeatureCollection', features: out };
    }

    // --- 5. LAYER DEFINITIONS ---
    var SUBTYPE = ['coalesce', ['get', 'subtype_or_value'], ''];
    var IS_LINE = ['in', ['geometry-type'], ['literal', ['LineString', 'MultiLineString']]];
    var IS_FRONT = ['all', IS_LINE, ['in', ['get', 'featureType'], ['literal', ['Front', 'ConvergentBoundary']]]];

    function subtypeIs(list) {
        return ['in', SUBTYPE, ['literal', list]];
    }

    // width is in pixels at feature size 1.0; dash is in line widths
    var LINE_LAYERS = [
        {
            id: 'isobars', group: 'isobars',
            filter: ['all', IS_LINE, ['==', ['get', 'featureType'], 'Isobar']],
            color: '#444444', width: 2
        },
        {
            id: 'fronts-solid', group: 'fronts',
            filter: ['all', IS_FRONT, ['!', subtypeIs(['STATIONARY_FRONT_DISS', 'TROF', 'TROPICAL_TROF', 'DOUBLE_LINE', 'ZZZ_LINE'])]],
            color: ['match', SUBTYPE,
                'COLD_FRONT', '#0000FF',
                'WARM_FRONT', '#FF0000',
                'OCCLUDED_FRONT', '#800080',
                'STATIONARY_FRONT', '#FF0000',
                '#000000'],
            width: ['match', SUBTYPE, ['COLD_FRONT', 'WARM_FRONT', 'OCCLUDED_FRONT', 'STATIONARY_FRONT'], 3, 2]
        },
        {
            id: 'fronts-stat-diss', group: 'fronts',
            filter: ['all', IS_FRONT, subtypeIs(['STATIONARY_FRONT_DISS'])],
            color: '#FF0000', width: 3, dash: [5 / 3, 10 / 3]
        },
        {
            id: 'fronts-trof', group: 'fronts',
            filter: ['all', IS_FRONT, subtypeIs(['TROF', 'TROPICAL_TROF'])],
            color: '#FFA500', width: 2, dash: [5, 5]
        },
        {
            id: 'fronts-double', group: 'fronts',
            filter: ['all', IS_FRONT, subtypeIs(['DOUBLE_LINE'])],
            color: '#FF0000', width: 6, opacity: 0.5
        },
        {
            id: 'fronts-zzz', group: 'fronts',
            filter: ['all', IS_FRONT, subtypeIs(['ZZZ_LINE'])],
            color: '#FF0000', width: 3, dash: [10 / 3, 10 / 3]
        },
        {
            // Blue dashes over the red stationary line -> alternating red/blue
            id: 'fronts-stat-blue', group: 'fronts',
            filter: ['all', IS_FRONT, subtypeIs(['STATIONARY_FRONT'])],
            color: '#0000FF', width: 3, dash: [20 / 3, 20 / 3]
        }
    ];

    // spacing/offset are in pixels at feature size 1.0
    var PIP_LAYERS = [
        { id: 'pips-cold', subtype: 'COLD_FRONT', image: 'pip-cold', anchor: 'bottom', spacing: 75 },
        { id: 'pips-warm', subtype: 'WARM_FRONT', image: 'pip-warm', anchor: 'bottom', spacing: 75 },
        { id: 'pips-occ-tri', subtype: 'OCCLUDED_FRONT', image: 'pip-occ-tri', anchor: 'bottom', spacing: 100 },
        { id: 'pips-occ-warm', subtype: 'OCCLUDED_FRONT', image: 'pip-occ-warm', anchor: 'bottom', spacing: 100, offset: 50 },
        { id: 'pips-stat-cold', subtype: 'STATIONARY_FRONT', image: 'pip-cold', anchor: 'bottom', spacing: 100 },
        { id: 'pips-stat-warm', subtype: 'STATIONARY_FRONT', image: 'pip-stat-warm', anchor: 'top', spacing: 100, offset: 50 }
    ];

    var GROUPS = {
        symbols: { label: 'Weather Symbols', layers: ['points'] },
        fronts: { label: 'Fronts', layers: [] },
        isobars: { label: 'Isobars', layers: ['isobar-labels'] }
    };
    LINE_LAYERS.forEach(function (l) { GROUPS[l.group].layers.push(l.id); });
    PIP_LAYERS.forEach(function (p) { GROUPS.fronts.layers.push(p.id); });

    function scaled(base) {
        return ['*', symScale, base];
    }

    function addWeatherLayers() {
        map.addSource('pgen', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });

        LINE_LAYERS.forEach(function (l) {
            var paint = {
                'line-color': l.color,
                'line-width': scaled(l.width),
                'line-opacity': l.opacity || 1
            };
            if (l.dash) paint['line-dasharray'] = l.dash;
            map.addLayer({
                id: l.id, type: 'line', source: 'pgen', filter: l.filter,
                layout: { 'line-join': 'round', 'line-cap': l.dash ? 'butt' : 'round' },
                paint: paint
            });
        });

        map.addLayer({
            id: 'isobar-labels', type: 'symbol', source: 'pgen',
            filter: ['all', IS_LINE, ['has', '_label']],
            layout: {
                'symbol-placement': 'line',
                'symbol-spacing': 600 * symScale,
                'icon-image': ['get', '_label'],
                'icon-size': symScale,
                'icon-rotation-alignment': 'viewport',
                'icon-pitch-alignment': 'viewport'
            }
        });

        PIP_LAYERS.forEach(function (p) {
            map.addLayer({
                id: p.id, type: 'symbol', source: 'pgen',
                filter: ['all', IS_FRONT, ['==', SUBTYPE, p.subtype]],
                layout: {
                    'symbol-placement': 'line',
                    'symbol-spacing': p.spacing * symScale,
                    'icon-image': p.image,
                    'icon-anchor': p.anchor,
                    'icon-offset': [p.offset || 0, 0],
                    'icon-size': symScale,
                    'icon-rotation-alignment': 'map',
                    'icon-pitch-alignment': 'map',
                    'icon-allow-overlap': true,
                    'icon-ignore-placement': true,
                    'icon-padding': 0
                }
            });
        });

        map.addLayer({
            id: 'points', type: 'symbol', source: 'pgen',
            filter: ['==', ['geometry-type'], 'Point'],
            layout: {
                'icon-image': ['get', '_icon'],
                'icon-size': symScale,
                'icon-allow-overlap': true,
                'icon-ignore-placement': true,
                'icon-rotation-alignment': 'viewport',
                'icon-pitch-alignment': 'viewport'
            }
        });
    }

    function applyScale() {
        LINE_LAYERS.forEach(function (l) {
            map.setPaintProperty(l.id, 'line-width', scaled(l.width));
        });
        map.setLayoutProperty('isobar-labels', 'symbol-spacing', 600 * symScale);
        map.setLayoutProperty('isobar-labels', 'icon-size', symScale);
        PIP_LAYERS.forEach(function (p) {
            map.setLayoutProperty(p.id, 'symbol-spacing', p.spacing * symScale);
            map.setLayoutProperty(p.id, 'icon-size', symScale);
        });
        map.setLayoutProperty('points', 'icon-size', symScale);
    }

    // --- 6. OVERLAY TOGGLE CONTROL ---
    function OverlayControl() {}

    OverlayControl.prototype.onAdd = function () {
        var div = document.createElement('div');
        div.className = 'maplibregl-ctrl maplibregl-ctrl-group overlay-toggle';
        Object.keys(GROUPS).forEach(function (key) {
            var label = document.createElement('label');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = true;
            cb.addEventListener('change', function () {
                GROUPS[key].layers.forEach(function (id) {
                    map.setLayoutProperty(id, 'visibility', cb.checked ? 'visible' : 'none');
                });
            });
            label.appendChild(cb);
            label.appendChild(document.createTextNode(GROUPS[key].label));
            div.appendChild(label);
        });
        this._container = div;
        return div;
    };

    OverlayControl.prototype.onRemove = function () {
        this._container.parentNode.removeChild(this._container);
    };

    // --- 7. LOAD DATA (PACIFIC & ATLANTIC) ---
    function setStatus(text) {
        document.getElementById('frontsStatus').textContent = text;
    }

    function fetchFeatures(path) {
        return fetch(path)
            .then(function (r) { return r.ok ? r.json() : { features: [] }; })
            .then(function (j) { return (j && j.features) || []; })
            .catch(function () { return []; });
    }

    function setFronts(geojson) {
        map.getSource('pgen').setData(geojson || { type: 'FeatureCollection', features: [] });
    }

    function getDemoPath(basin, hoursToAdd) {
        return DEMO_FRONTS_DIR + basin + '_' + (hoursToAdd < 0 ? 'm' : 'p') +
            pad(Math.abs(hoursToAdd), 3) + '.geo.json';
    }

    function loadDataForHour(hoursToAdd) {
        var seq = ++loadSeq;
        var cycleLabel, paths;

        if (DEMO_FRONTS_DIR) {
            if (DEMO_HOURS.indexOf(hoursToAdd) < 0) {
                setFronts(null);
                setStatus('Demo fronts: none for this hour');
                return;
            }
            cycleLabel = 'demo (synthetic) ' + (hoursToAdd > 0 ? '+' : '') + hoursToAdd + ' h';
            paths = BASINS.map(function (basin) { return getDemoPath(basin, hoursToAdd); });
        } else {
            var fileInfo = getBestFrontsFile(getFormattedTime(hoursToAdd));
            if (!fileInfo) {
                setFronts(null);
                setStatus('No fronts cycle for this valid time');
                return;
            }
            cycleLabel = fileInfo.base.toISOString().substring(0, 13).replace('T', ' ') + 'Z F' + pad(fileInfo.fHour, 3);
            paths = BASINS.map(function (basin) { return getGeoJsonPath(basin, fileInfo.base, fileInfo.fHour); });
        }

        setStatus('Fronts: loading ' + cycleLabel + '...');

        Promise.all(paths.map(fetchFeatures)).then(function (results) {
            if (seq !== loadSeq) return; // a newer request superseded this one
            var allFeatures = [].concat.apply([], results);
            if (allFeatures.length === 0) {
                setFronts(null);
                setStatus('Fronts: no data for ' + cycleLabel);
                return;
            }
            setFronts(processFeatures(allFeatures));
            setStatus('Fronts: ' + cycleLabel);
        });
    }

    // --- 8. TIME CONTROLS ---
    function updateTimeLabels() {
        document.getElementById('hourDisplay').textContent = (currentHour > 0 ? '+' : '') + currentHour;
        document.getElementById('validTimeDisplay').textContent = 'Valid: ' + getFormattedTime(currentHour).toUTCString();
    }

    function refreshTimeLayers() {
        map.getSource('ndfd').setTiles([ndfdTileUrl()]);
        loadDataForHour(currentHour);
    }

    function setHour(hour, immediate) {
        currentHour = hour;
        updateTimeLabels();
        clearTimeout(loadTimer);
        if (immediate) refreshTimeLayers();
        else loadTimer = setTimeout(refreshTimeLayers, LOAD_DEBOUNCE_MS);
    }

    function stepHour(delta) {
        var slider = document.getElementById('timeSlider');
        var next = parseInt(slider.value, 10) + delta;
        if (next < parseInt(slider.min, 10) || next > parseInt(slider.max, 10)) return;
        slider.value = next;
        setHour(next, true);
    }

    function bindControls() {
        document.getElementById('layerSelect').addEventListener('change', function () {
            map.getSource('ndfd').setTiles([ndfdTileUrl()]);
        });

        document.getElementById('warningToggle').addEventListener('change', function () {
            map.setLayoutProperty('warnings', 'visibility', this.checked ? 'visible' : 'none');
        });

        document.getElementById('sizeSlider').addEventListener('input', function () {
            symScale = parseFloat(this.value);
            applyScale();
        });

        document.getElementById('timeSlider').addEventListener('input', function () {
            setHour(parseInt(this.value, 10), false);
        });

        document.getElementById('btnPrev').addEventListener('click', function () { stepHour(-3); });
        document.getElementById('btnNext').addEventListener('click', function () { stepHour(3); });
    }

    // --- 9. INITIALIZE MAP ---
    function init() {
        updateTimeLabels();

        map = new maplibregl.Map({
            container: 'map',
            center: [-105, 35],
            zoom: 1.8,
            maxZoom: 13,
            style: {
                version: 8,
                projection: { type: 'globe' },
                sky: {
                    'atmosphere-blend': ['interpolate', ['linear'], ['zoom'], 0, 1, 5, 1, 7, 0]
                },
                sources: {
                    ocean: {
                        type: 'raster', tiles: [ESRI_OCEAN], tileSize: 256, maxzoom: 13,
                        attribution: 'Tiles &copy; Esri'
                    },
                    ndfd: {
                        type: 'raster', tiles: [ndfdTileUrl()], tileSize: 256,
                        attribution: 'NDFD &copy; NOAA/NWS'
                    },
                    warnings: {
                        type: 'raster', tileSize: 256,
                        tiles: [wmsTileUrl(WARNINGS_WMS, { LAYERS: WARNINGS_LAYER })]
                    }
                },
                layers: [
                    { id: 'ocean', type: 'raster', source: 'ocean' },
                    { id: 'ndfd', type: 'raster', source: 'ndfd', paint: { 'raster-opacity': 0.65 } },
                    {
                        id: 'warnings', type: 'raster', source: 'warnings',
                        layout: { visibility: 'none' }, paint: { 'raster-opacity': 0.8 }
                    }
                ]
            }
        });

        map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-left');
        map.addControl(new maplibregl.ScaleControl({ unit: 'nautical' }), 'bottom-left');
        map.addControl(new OverlayControl(), 'top-right');

        map.on('load', function () {
            addPipSprites();
            addWeatherLayers();
            bindControls();
            loadDataForHour(currentHour);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
