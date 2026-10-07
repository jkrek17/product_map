/**
 * Oceanic Forecast - NDFD gridded products (NOAA GeoServer WMS)
 *
 * - Products and their legends (fetched from the server, with built-in
 *   fallbacks so the legend never goes blank).
 * - Availability: each layer's TIME dimension is read from GetCapabilities,
 *   so the app only requests grids that exist (oceanic grids are 3-hourly
 *   and NDFD keeps no past grids).
 * - GridLayer: two raster buffers; the next time step loads hidden and is
 *   cross-faded in once its tiles arrive, so stepping or playing never
 *   flashes an empty map.
 * - Point values via GetFeatureInfo.
 */
window.Oceanic = window.Oceanic || {};

window.Oceanic.Ndfd = (function () {
    'use strict';

    var BASE = 'https://mapservices.weather.noaa.gov/geoserver/ndfd';
    var CAPS_TTL_MS = 20 * 60 * 1000;
    var NEAREST_MAX_MS = 3 * 3600 * 1000;
    var NODATA_MIN = 9000;

    var PRODUCTS = [
        { id: 'waveh', name: 'Significant Wave Height', short: 'Waves', unit: 'ft', icon: 'waves' },
        { id: 'wspd', name: 'Wind Speed', short: 'Wind', unit: 'kt', icon: 'wind' },
        { id: 'wgust', name: 'Wind Gust', short: 'Gust', unit: 'kt', icon: 'gust' },
        { id: 'wind', name: 'Wind Barbs', short: 'Barbs', unit: 'kt', icon: 'barb', legend: 'barbs' }
    ];

    // Server colormaps as of 2026-10 ([value, color]); used if the legend
    // request fails.
    var WIND_RAMP = [[0, '#103F78'], [5, '#225EA8'], [10, '#1D91C0'], [15, '#41B6C4'], [20, '#7FCDBB'],
        [25, '#B4D79E'], [30, '#DFFF9E'], [35, '#FFFFA6'], [40, '#FFE873'], [45, '#FFC400'], [50, '#FFAA00'],
        [60, '#FF5900'], [70, '#FF0000'], [80, '#A80000'], [100, '#6E0000'], [120, '#FFBEE8'], [140, '#FF73DF']];
    var FALLBACK_LEGENDS = {
        waveh: [[0, '#EBFDFF'], [1, '#ABEDF5'], [2, '#78CDD6'], [3, '#4BB8C4'], [4, '#55B59F'], [5, '#86D483'],
            [7, '#B0E890'], [10, '#DDFF99'], [12, '#FED976'], [15, '#FEB24C'], [20, '#FD8D3C'], [25, '#FC4E2A'],
            [30, '#E31A1C'], [35, '#BD0026'], [40, '#800026'], [50, '#5C002F'], [60, '#330023']],
        wspd: WIND_RAMP,
        wgust: WIND_RAMP
    };

    var caps = {};    // id -> { state, times:Set<ms>, sorted:[ms], fetchedAt, promise }
    var legends = {}; // id -> Promise<[[value, color]]>

    function product(id) {
        for (var i = 0; i < PRODUCTS.length; i++) {
            if (PRODUCTS[i].id === id) return PRODUCTS[i];
        }
        return PRODUCTS[0];
    }

    function fetchWithTimeout(url, ms) {
        var ctrl = new AbortController();
        var timer = setTimeout(function () { ctrl.abort(); }, ms);
        return fetch(url, { signal: ctrl.signal }).then(function (r) {
            clearTimeout(timer);
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r;
        }, function (err) {
            clearTimeout(timer);
            throw err;
        });
    }

    function sleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    // Retry transient failures with backoff (1 s, 3 s)
    async function fetchRetry(url, ms) {
        var delays = [1000, 3000];
        for (var attempt = 0; ; attempt++) {
            try {
                return await fetchWithTimeout(url, ms);
            } catch (err) {
                if (attempt >= delays.length) throw err;
                await sleep(delays[attempt]);
            }
        }
    }

    // --- Availability -------------------------------------------------------
    function loadTimes(id, force) {
        var c = caps[id];
        if (c && c.promise && (c.inflight || (!force && Date.now() - c.fetchedAt < CAPS_TTL_MS))) {
            return c.promise;
        }
        var prev = c && c.state === 'ok' ? c : null;
        c = caps[id] = {
            state: prev ? 'ok' : 'loading',
            times: prev ? prev.times : new Set(),
            sorted: prev ? prev.sorted : [],
            fetchedAt: prev ? prev.fetchedAt : 0,
            inflight: true
        };
        var url = BASE + '/' + id + '/wms?SERVICE=WMS&REQUEST=GetCapabilities&VERSION=1.3.0';
        c.promise = fetchRetry(url, 20000)
            .then(function (r) { return r.text(); })
            .then(function (xml) {
                var doc = new DOMParser().parseFromString(xml, 'text/xml');
                var dims = doc.getElementsByTagName('Dimension');
                var list = null;
                for (var i = 0; i < dims.length; i++) {
                    if (dims[i].getAttribute('name') === 'time') { list = dims[i].textContent; break; }
                }
                if (!list) throw new Error('No time dimension');
                var sorted = list.split(',').map(function (t) { return Date.parse(t.trim()); })
                    .filter(function (t) { return !isNaN(t); })
                    .sort(function (a, b) { return a - b; });
                c.times = new Set(sorted);
                c.sorted = sorted;
                c.state = 'ok';
                c.fetchedAt = Date.now();
                c.inflight = false;
                return c;
            })
            .catch(function () {
                // Keep a previously good list; otherwise availability is unknown
                c.state = prev ? 'ok' : 'error';
                c.fetchedAt = Date.now();
                c.inflight = false;
                return c;
            });
        return c.promise;
    }

    function capsState(id) {
        return caps[id] ? caps[id].state : 'loading';
    }

    // -> { status: 'exact'|'nearest'|'none'|'unknown', ms }
    function resolveGridTime(id, ms) {
        var c = caps[id];
        if (!c || c.state !== 'ok') return { status: 'unknown', ms: ms };
        if (c.times.has(ms)) return { status: 'exact', ms: ms };
        // Oceanic grids exist only at 3-hourly synoptic times; the hourly
        // entries in between cover coastal waters only.
        var best = null;
        c.sorted.forEach(function (t) {
            if (new Date(t).getUTCHours() % 3) return;
            if (Math.abs(t - ms) <= NEAREST_MAX_MS && (best === null || Math.abs(t - ms) < Math.abs(best - ms))) best = t;
        });
        return best === null ? { status: 'none', ms: ms } : { status: 'nearest', ms: best };
    }

    function hasExact(id, ms) {
        var c = caps[id];
        if (!c || c.state !== 'ok') return null;
        return c.times.has(ms);
    }

    function range(id) {
        var c = caps[id];
        if (!c || c.state !== 'ok' || !c.sorted.length) return null;
        return { first: c.sorted[0], last: c.sorted[c.sorted.length - 1] };
    }

    // --- Tiles ---------------------------------------------------------------
    function wmsTileUrl(params) {
        var url = BASE + '/wms?SERVICE=WMS&REQUEST=GetMap&VERSION=1.3.0&STYLES=&FORMAT=image/png' +
            '&TRANSPARENT=true&CRS=EPSG:3857&WIDTH=256&HEIGHT=256&BBOX={bbox-epsg-3857}';
        Object.keys(params).forEach(function (k) {
            url += '&' + k + '=' + encodeURIComponent(params[k]);
        });
        return url;
    }

    // grid: result of resolveGridTime. Unknown availability (capabilities
    // unreachable) falls back to TIME for future and VTIT for past times.
    function tileUrl(id, grid) {
        var params = { LAYERS: 'ndfd:' + id };
        var iso = new Date(grid.ms).toISOString();
        if (grid.status === 'unknown' && grid.ms <= Date.now()) {
            var t = iso.substring(0, 19) + 'Z';
            params.VTIT = t + '_' + t;
        } else {
            params.TIME = iso;
        }
        return wmsTileUrl(params);
    }

    function waitForSource(map, id, timeoutMs, isStale) {
        return new Promise(function (resolve) {
            var start = performance.now();
            var sawData = false;
            function onData(e) { if (e.sourceId === id) sawData = true; }
            map.on('sourcedataloading', onData);
            map.on('sourcedata', onData);
            function done(result) {
                map.off('sourcedataloading', onData);
                map.off('sourcedata', onData);
                resolve(result);
            }
            (function tick() {
                if (isStale()) return done('stale');
                var elapsed = performance.now() - start;
                if ((sawData || elapsed > 500) && map.isSourceLoaded(id)) return done('loaded');
                if (elapsed > timeoutMs) return done('timeout');
                setTimeout(tick, 100);
            })();
        });
    }

    function GridLayer(map, beforeId, opacity) {
        this.map = map;
        this.opacity = opacity;
        this.buffers = ['ndfd-a', 'ndfd-b'];
        this.active = 0;
        this.urls = [null, null];
        this.seq = 0;
        var self = this;
        this.buffers.forEach(function (id) {
            map.addSource(id, {
                type: 'raster', tileSize: 256, tiles: [wmsTileUrl({ LAYERS: 'ndfd:waveh' })],
                attribution: 'NDFD &copy; NOAA/NWS'
            });
            map.addLayer({
                id: id, type: 'raster', source: id,
                layout: { visibility: 'none' },
                paint: { 'raster-opacity': 0, 'raster-fade-duration': 150 }
            }, beforeId);
        });
        this.isGridSource = function (sourceId) { return self.buffers.indexOf(sourceId) >= 0; };
    }

    // Resolves 'loaded' | 'timeout' | 'stale' | 'same'
    GridLayer.prototype.show = async function (url) {
        var map = this.map;
        var seq = ++this.seq;
        var self = this;
        var cur = this.buffers[this.active];
        if (this.urls[this.active] === url && map.getLayoutProperty(cur, 'visibility') === 'visible') {
            map.setPaintProperty(cur, 'raster-opacity', this.opacity);
            return 'same';
        }

        var nextIdx = 1 - this.active;
        var next = this.buffers[nextIdx];
        map.setPaintProperty(next, 'raster-opacity-transition', { duration: 0, delay: 0 });
        map.setPaintProperty(next, 'raster-opacity', 0);
        if (this.urls[nextIdx] !== url) {
            map.getSource(next).setTiles([url]);
            this.urls[nextIdx] = url;
        }
        map.setLayoutProperty(next, 'visibility', 'visible');

        var result = await waitForSource(map, next, 20000, function () { return seq !== self.seq; });
        if (result === 'stale') return result;

        // Cross-fade; partially loaded tiles on timeout beat an empty map
        map.setPaintProperty(next, 'raster-opacity-transition', { duration: 300, delay: 0 });
        map.setPaintProperty(next, 'raster-opacity', this.opacity);
        map.setPaintProperty(cur, 'raster-opacity-transition', { duration: 300, delay: 0 });
        map.setPaintProperty(cur, 'raster-opacity', 0);
        this.active = nextIdx;
        setTimeout(function () {
            if (self.buffers[self.active] !== cur) map.setLayoutProperty(cur, 'visibility', 'none');
        }, 350);
        return result;
    };

    GridLayer.prototype.hide = function () {
        var map = this.map;
        this.seq++;
        this.buffers.forEach(function (id) {
            map.setLayoutProperty(id, 'visibility', 'none');
        });
    };

    GridLayer.prototype.setOpacity = function (opacity) {
        this.opacity = opacity;
        var cur = this.buffers[this.active];
        this.map.setPaintProperty(cur, 'raster-opacity-transition', { duration: 0, delay: 0 });
        this.map.setPaintProperty(cur, 'raster-opacity', opacity);
    };

    // --- Legend --------------------------------------------------------------
    function legend(id) {
        if (product(id).legend === 'barbs') return Promise.resolve(null);
        if (!legends[id]) {
            var url = BASE + '/wms?SERVICE=WMS&REQUEST=GetLegendGraphic&VERSION=1.3.0&FORMAT=application/json&LAYER=ndfd:' + id;
            legends[id] = fetchWithTimeout(url, 15000)
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    var stops = [];
                    json.Legend[0].rules.forEach(function (rule) {
                        rule.symbolizers.forEach(function (s) {
                            if (!s.Raster) return;
                            s.Raster.colormap.entries.forEach(function (e) {
                                var q = parseFloat(e.quantity);
                                // Skip nodata sentinels (transparent entries)
                                if (isNaN(q) || q < 0 || q >= NODATA_MIN || parseFloat(e.opacity) < 0.1) return;
                                stops.push([q, e.color]);
                            });
                        });
                    });
                    if (stops.length < 2) throw new Error('Empty legend');
                    return stops;
                })
                .catch(function () {
                    delete legends[id]; // retry next time
                    return FALLBACK_LEGENDS[id] || null;
                });
        }
        return legends[id];
    }

    // --- Point values ---------------------------------------------------------
    var VALUE_FIELDS = { wave_height: 'waveh', wind_speed: 'wspd', wind_gust: 'wgust' };

    function pointValues(lng, lat, ms) {
        var d = 0.01;
        var layers = 'ndfd:waveh,ndfd:wspd,ndfd:wgust';
        var url = BASE + '/wms?SERVICE=WMS&REQUEST=GetFeatureInfo&VERSION=1.3.0&STYLES=&CRS=EPSG:4326' +
            '&LAYERS=' + layers + '&QUERY_LAYERS=' + layers + '&FEATURE_COUNT=10&INFO_FORMAT=application/json' +
            '&BBOX=' + [lat - d, lng - d, lat + d, lng + d].join(',') + '&WIDTH=3&HEIGHT=3&I=1&J=1' +
            '&TIME=' + encodeURIComponent(new Date(ms).toISOString());
        return fetchWithTimeout(url, 15000)
            .then(function (r) { return r.text(); })
            .then(function (text) {
                if (text.charAt(0) === '<') {
                    // WMS ServiceExceptionReport, e.g. the time rolled off the server
                    var err = new Error('NDFD service exception');
                    if (text.indexOf('InvalidDimensionValue') >= 0) err.code = 'nogrid';
                    throw err;
                }
                var json = JSON.parse(text);
                var out = {};
                (json.features || []).forEach(function (f) {
                    Object.keys(f.properties || {}).forEach(function (k) {
                        var v = f.properties[k];
                        if (VALUE_FIELDS[k] && typeof v === 'number' && v < NODATA_MIN && v > -NODATA_MIN) {
                            out[VALUE_FIELDS[k]] = v;
                        }
                    });
                });
                return out;
            });
    }

    return {
        PRODUCTS: PRODUCTS,
        product: product,
        loadTimes: loadTimes,
        capsState: capsState,
        resolveGridTime: resolveGridTime,
        hasExact: hasExact,
        range: range,
        tileUrl: tileUrl,
        GridLayer: GridLayer,
        legend: legend,
        pointValues: pointValues,
        fetchWithTimeout: fetchWithTimeout
    };
})();
