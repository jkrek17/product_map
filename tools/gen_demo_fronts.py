"""Generate synthetic (demo) OPC-style surface analysis/forecast GeoJSON.

Usage: python tools/gen_demo_fronts.py OUT_DIR   (needs numpy + contourpy)

Writes {Pacific,Atlantic}_{m012,m006,p000,p024,p048,p072,p096}.geo.json,
loaded by oceanic.html when window.OCEANIC_CONFIG.demoFrontsDir is set.
The data is made up: it is for demonstrating the renderer only.

A pressure field is built from a latitude-dependent background plus
Gaussian highs/lows (and troughs along cold fronts) that move along
tracks; isobars are contoured from it every 4 mb, and fronts are attached
to each low with a maturity-dependent occlusion.
"""
import json
import math
import os
import sys

import numpy as np
import contourpy

OUT = sys.argv[1]
TIMES = [-12, -6, 0, 24, 48, 72, 96]
LEVEL_STEP = 4


def interp(track, t):
    """track: {t: tuple}; linear interpolation, None outside range."""
    ts = sorted(track)
    if t < ts[0] or t > ts[-1]:
        return None
    for a, b in zip(ts, ts[1:]):
        if a <= t <= b:
            f = (t - a) / (b - a)
            return tuple(x + f * (y - x) for x, y in zip(track[a], track[b]))
    return track[ts[0]]


def background(lat):
    return (1012.0 + 6.0 * np.exp(-((lat - 33.0) / 11.0) ** 2)
            - 7.0 * np.clip((lat - 45.0) / 18.0, 0.0, 1.0))


def rel(lon0, lat0, pts):
    """Offsets in (east-degrees-at-equator, north-degrees) -> lon/lat."""
    k = 1.0 / math.cos(math.radians(lat0))
    return [(lon0 + dx * k, lat0 + dy) for dx, dy in pts]


def frontal_system(lon0, lat0, occ, wf, cf, tail, mature_trof, tail_type):
    fronts = []
    tp = (lon0, lat0)
    if occ > 0.3:
        o = occ
        line = rel(lon0, lat0, [(0, 0), (0.6 * o, 0.12 * o), (1.0 * o, -0.3 * o), (1.1 * o, -0.8 * o)])
        fronts.append(('OCCLUDED_FRONT', line))
        tp = line[-1]
    if wf > 0.3:
        fronts.append(('WARM_FRONT', rel(tp[0], tp[1], [(0, 0), (0.4 * wf, -0.05 * wf), (0.75 * wf, -0.2 * wf), (1.0 * wf, -0.42 * wf)])))
    cold = None
    if cf > 0.3:
        cold = rel(tp[0], tp[1], [(0, 0), (-0.05 * cf, -0.3 * cf), (-0.2 * cf, -0.6 * cf), (-0.45 * cf, -0.85 * cf), (-0.75 * cf, -1.0 * cf)])
        fronts.append(('COLD_FRONT', cold))
        if tail > 0.3:
            e = cold[-1]
            fronts.append((tail_type, rel(e[0], e[1], [(0, 0), (-0.4 * tail, 0.02 * tail), (-0.8 * tail, 0.1 * tail), (-1.2 * tail, 0.08 * tail)])))
        if mature_trof:
            fronts.append(('TROF', rel(lon0, lat0, [(-0.15 * cf, -0.35 * cf), (-0.35 * cf, -0.6 * cf), (-0.6 * cf, -0.8 * cf)])))
    return fronts, cold


# --- Scenario -----------------------------------------------------------
# Lows: track {t: (lon, lat, central_mb)}; fronts {t: (occ, wf, cf, tail)};
# text {t: label}. Longitudes are continuous (< -180 is west of the dateline).
BASIN_DEFS = {
    'Pacific': {
        'lows': [
            {
                'track': {-12: (-172, 45, 984), 0: (-166, 47.5, 976), 24: (-157, 51.5, 970),
                          48: (-150, 55, 976), 72: (-145, 57, 986), 96: (-141, 58, 996)},
                'fronts': {-12: (3, 10, 22, 12), 0: (5, 11, 24, 12), 24: (8, 10, 22, 10),
                           48: (10, 8, 18, 8), 72: (9, 5, 12, 0), 96: (6, 0, 0, 0)},
                'text': {-12: 'STORM', -6: 'STORM', 0: 'HURCN FORCE', 24: 'HURCN FORCE', 48: 'STORM', 72: 'GALE'},
            },
            {
                'track': {-12: (-203, 37, 1012), 0: (-200, 38.5, 1006), 24: (-192, 41, 996),
                          48: (-183, 44.5, 982), 72: (-174, 47.5, 972), 96: (-166, 50, 970)},
                'fronts': {-12: (0, 5, 6, 10), 0: (0, 8, 12, 10), 24: (2, 10, 18, 10),
                           48: (5, 11, 22, 10), 72: (8, 10, 22, 8), 96: (10, 9, 20, 6)},
                'text': {-12: 'DEVELOPING', -6: 'DEVELOPING', 0: 'DEVELOPING', 24: 'GALE', 48: 'STORM', 72: 'STORM', 96: 'HURCN FORCE'},
            },
        ],
        'highs': [
            {-12: (-145, 36, 1031), 96: (-136, 39, 1036)},
            {-12: (-186, 32, 1022), 48: (-168, 34, 1025), 96: (-158, 35, 1024)},
        ],
        'tropical': {
            -12: (-108, 15, 975, 'Hurricane'), 0: (-110.5, 16.2, 962, 'Hurricane'),
            24: (-114, 17.8, 958, 'Hurricane'), 48: (-117.5, 19.5, 968, 'Hurricane'),
            72: (-121, 20.5, 990, 'TropicalStorm'), 96: (-124, 21, 1004, 'TropicalDepression'),
        },
    },
    'Atlantic': {
        'lows': [
            {
                'track': {-12: (-54, 44, 996), -6: (-52, 45, 992), 0: (-49, 47, 986), 24: (-38, 53, 970),
                          48: (-29, 58, 966), 72: (-23, 61, 976), 96: (-18, 62.5, 988)},
                'fronts': {-12: (0, 9, 16, 10), -6: (1, 9, 18, 10), 0: (3, 10, 20, 10), 24: (7, 10, 24, 8),
                           48: (10, 7, 20, 6), 72: (9, 4, 12, 0), 96: (6, 0, 0, 0)},
                'text': {-12: 'GALE', -6: 'GALE', 0: 'STORM', 24: 'HURCN FORCE', 48: 'STORM', 72: 'GALE'},
            },
            {
                'track': {24: (-78, 34, 1012), 48: (-70, 37.5, 1002), 72: (-61, 42, 990), 96: (-51, 46.5, 980)},
                'fronts': {24: (0, 4, 6, 8), 48: (0, 7, 12, 10), 72: (2, 9, 16, 10), 96: (4, 10, 20, 8)},
                'text': {24: 'DEVELOPING', 48: 'GALE', 72: 'GALE', 96: 'STORM'},
            },
        ],
        'highs': [
            {-12: (-40, 34, 1028), 96: (-34, 35, 1031)},
            {-12: (-80, 44, 1026), 0: (-74, 42, 1028), 24: (-63, 39, 1026), 48: (-55, 36, 1023)},
        ],
        'tropical': {
            -12: (-55, 18.5, 1006, 'TropicalDepression'), -6: (-56, 19.5, 1002, 'TropicalStorm'),
            0: (-57.5, 21, 997, 'TropicalStorm'), 24: (-61, 25, 985, 'Hurricane'),
            48: (-63, 30, 975, 'Hurricane'), 72: (-60, 36, 990, 'TropicalStorm'),
        },
    },
}

TROPICAL_PREFIX = {'Hurricane': 'HURCN', 'TropicalStorm': 'TS', 'TropicalDepression': 'TD'}


def wrap(lon):
    while lon < -180:
        lon += 360
    while lon > 180:
        lon -= 360
    return round(lon, 2)


def feature(geom_type, coords, props):
    xs = [coords[0]] if geom_type == 'Point' else [c[0] for c in coords]
    props = dict(props)
    props['_basin'] = 'Pacific' if sum(xs) / len(xs) < SPLIT_LON else 'Atlantic'
    if geom_type == 'Point':
        geom = {'type': 'Point', 'coordinates': [wrap(coords[0]), round(coords[1], 2)]}
    else:
        geom = {'type': 'LineString', 'coordinates': [[wrap(x), round(y, 2)] for x, y in coords]}
    return {'type': 'Feature', 'geometry': geom, 'properties': props}


def dist_to_polyline(LON, LAT, line):
    """Approximate distance in degrees (lon scaled by cos lat)."""
    best = np.full(LON.shape, np.inf)
    for (x1, y1), (x2, y2) in zip(line, line[1:]):
        k = math.cos(math.radians((y1 + y2) / 2))
        ax, ay = (LON - x1) * k, LAT - y1
        bx, by = (x2 - x1) * k, y2 - y1
        L2 = bx * bx + by * by
        t = np.clip((ax * bx + ay * by) / L2, 0, 1) if L2 > 0 else 0
        d = np.hypot(ax - t * bx, ay - t * by)
        best = np.minimum(best, d)
    return best


def resample(line, step=1.6):
    """Keep points ~step degrees apart (by arc length)."""
    out = [line[0]]
    acc = 0.0
    for p, q in zip(line, line[1:]):
        acc += math.hypot(q[0] - p[0], q[1] - p[1])
        if acc >= step:
            out.append(q)
            acc = 0.0
    if out[-1] is not line[-1]:
        out.append(line[-1])
    return out


DOMAIN = (-230.0, 5.0, 12.0, 66.0)
SPLIT_LON = -105.0  # features west of this go in the Pacific file


def merged():
    return {
        'highs': sum((d['highs'] for d in BASIN_DEFS.values()), []),
        'lows': sum((d['lows'] for d in BASIN_DEFS.values()), []),
        'tropicals': [d['tropical'] for d in BASIN_DEFS.values()],
    }


def build(d, t):
    lon_min, lon_max, lat_min, lat_max = DOMAIN
    lons = np.arange(lon_min, lon_max + 0.01, 0.5)
    lats = np.arange(lat_min, lat_max + 0.01, 0.5)
    LON, LAT = np.meshgrid(lons, lats)
    P = background(LAT)
    k = np.cos(np.radians(LAT))

    def gauss(lon0, lat0, radius):
        return np.exp(-(((LON - lon0) * k) ** 2 + (LAT - lat0) ** 2) / radius ** 2)

    features = []
    centers = []

    for h in d['highs']:
        s = interp(h, t)
        if s is None:
            continue
        lon0, lat0, p0 = s
        P += (p0 - background(lat0)) * gauss(lon0, lat0, 15.0)
        centers.append(('High', lon0, lat0, None))

    for low in d['lows']:
        s = interp(low['track'], t)
        if s is None:
            continue
        lon0, lat0, p0 = s
        P -= (background(lat0) - p0) * gauss(lon0, lat0, 8.5)
        occ, wf, cf, tail = interp(low['fronts'], t)
        tail_type = 'STATIONARY_FRONT' if t <= 24 else 'STATIONARY_FRONT_DISS'
        fronts, cold = frontal_system(lon0, lat0, occ, wf, cf, tail, occ >= 5, tail_type)
        if cold is not None:
            P -= 3.0 * np.exp(-(dist_to_polyline(LON, LAT, cold) / 2.5) ** 2)
        for subtype, line in fronts:
            features.append(feature('LineString', line, {'featureType': 'Front', 'subtype_or_value': subtype}))
        centers.append(('Low', lon0, lat0, low['text'].get(t)))

    for trop_track in d['tropicals']:
        trop = interp_tropical(trop_track, t)
        if trop is None:
            continue
        lon0, lat0, p0, kind = trop
        P -= min(16.0, background(lat0) - p0) * gauss(lon0, lat0, 2.2)
        features.append(feature('Point', (lon0, lat0), {
            'featureType': kind, 'pressure_or_text': '%s\n%d MB' % (TROPICAL_PREFIX[kind], p0)}))

    # Pressure-center labels use the contoured field so they agree with isobars
    for kind, lon0, lat0, text in centers:
        j = int(round((lon0 - lon_min) / 0.5))
        i = int(round((lat0 - lat_min) / 0.5))
        if not (0 <= i < P.shape[0] and 0 <= j < P.shape[1]):
            continue
        win = P[max(0, i - 4):i + 5, max(0, j - 4):j + 5]
        value = win.max() if kind == 'High' else win.min()
        features.append(feature('Point', (lon0, lat0), {'featureType': kind, 'pressure_or_text': str(int(round(value)))}))
        if text:
            features.append(feature('Point', (lon0 - 3.0 / math.cos(math.radians(lat0)), lat0 - 5.5),
                                    {'featureType': 'Text', 'pressure_or_text': text}))

    gen = contourpy.contour_generator(lons, lats, P, line_type='Separate')
    lo = int(math.floor(P.min() / LEVEL_STEP) * LEVEL_STEP)
    hi = int(math.ceil(P.max() / LEVEL_STEP) * LEVEL_STEP)
    for level in range(lo, hi + 1, LEVEL_STEP):
        for arr in gen.lines(level):
            pts = [tuple(p) for p in arr.tolist()]
            if len(pts) < 6:
                continue
            closed = pts[0] == pts[-1]
            pts = resample(pts)
            if closed:
                pts[-1] = pts[0]
            if len(pts) < (5 if closed else 3):
                continue
            features.append(feature('LineString', pts, {'featureType': 'Isobar', 'subtype_or_value': str(level)}))

    return {'type': 'FeatureCollection', 'features': features}


def interp_tropical(track, t):
    if not track:
        return None
    ts = sorted(track)
    if t < ts[0] or t > ts[-1]:
        return None
    for a, b in zip(ts, ts[1:]):
        if a <= t <= b:
            f = (t - a) / (b - a)
            A, B = track[a], track[b]
            lon, lat, p = (A[i] + f * (B[i] - A[i]) for i in range(3))
            return lon, lat, int(round(p)), (A[3] if f < 0.5 else B[3])
    return track[ts[0]]


os.makedirs(OUT, exist_ok=True)
for t in TIMES:
    allfc = build(merged(), t)
    for basin in BASIN_DEFS:
        fc = {'type': 'FeatureCollection', 'features': []}
        for f in allfc['features']:
            if f['properties']['_basin'] == basin:
                f = dict(f, properties={k: v for k, v in f['properties'].items() if k != '_basin'})
                fc['features'].append(f)
        name = '%s_%s%03d.geo.json' % (basin, 'm' if t < 0 else 'p', abs(t))
        with open(os.path.join(OUT, name), 'w') as fh:
            json.dump(fc, fh, separators=(',', ':'))
        kinds = {}
        for f in fc['features']:
            key = f['properties']['featureType']
            kinds[key] = kinds.get(key, 0) + 1
        print(name, kinds)
