/**
 * Navy Forecast Areas - JavaScript
 * U.S. Navy Operations Areas (OPAREAs) - Marine Forecast Visualization
 */

var map;
var zonesLayer;
var forecastData = {};
var weatherChart = null;
var currentZone = null;
var pendingZoneFromUrl = null;

// Forecast data URLs (via PHP proxy)
var forecastUrls = {
    WRKFWNX01: 'get-forecast.php?pil=WRKFWNX01',
    WRKFWNX02: 'get-forecast.php?pil=WRKFWNX02',
    WRKFWNXPT: 'get-forecast.php?pil=WRKFWNXPT',
    FWCSD: 'get-forecast.php?pil=FWCSD'
};

// Parsed forecast data storage
var parsedForecasts = {
    atlantic: {},
    pacific: {}
};

// Raw text storage for each product
var rawTexts = {};

// Warning colors (matching index.html)
var warningColors = {
    'HURRICANE FORCE WIND WARNING': '#ff0000',
    'HURRICANE WARNING': '#ff0000',
    'STORM WARNING': '#ffa500',
    'TROPICAL STORM WARNING': '#ffa500',
    'GALE WARNING': '#ffff00',
    'GALE FORCE POSSIBLE': '#ffc0cb',
    'STORM FORCE POSSIBLE': '#800080',
    'TROPICAL STORM CONDITIONS POSSIBLE': '#800080',
    'NONE': '#808080'
};

// Navy GeoJSON data (loaded dynamically)
var navyGeoJson = null;

// OPAREA parsing configuration
var opareaConfig = {
    atlantic: {
        boston: {
            pil: 'WRKFWNX02',
            startPattern: 'BOSTON OPAREA:',
            endPattern: 'NARRAGANSETT BAY'
        },
        narrabay: {
            pil: 'WRKFWNX02',
            startPattern: 'NARRAGANSETT BAY OPAREA:',
            endPattern: 'FORECASTER'
        },
        vacapes: {
            pil: 'WRKFWNX01',
            startPattern: 'VACAPES OPAREA:',
            endPattern: 'CHERRY POINT'
        },
        cherry_point: {
            pil: 'WRKFWNX01',
            startPattern: 'CHERRY POINT OPAREA:',
            endPattern: 'CHARLESTON'
        },
        charleston: {
            pil: 'WRKFWNX01',
            startPattern: 'CHARLESTON OPAREA:',
            endPattern: 'JAX'
        },
        jacksonville: {
            pil: 'WRKFWNX01',
            startPattern: 'JAX OPAREA:',
            endPattern: 'PORT CANAVERAL'
        },
        port_canaveral: {
            pil: 'WRKFWNX01',
            startPattern: 'PORT CANAVERAL OPAREA:',
            endPattern: 'TONGUE'
        },
        toto: {
            pil: 'WRKFWNX01',
            startPattern: 'TONGUE OF THE OCEAN OPAREA:',
            endPattern: 'FORECASTER'
        }
    },
    pacific: {
        area_a: {
            pil: 'FWCSD',
            startPattern: '4. AREA A:',
            endPattern: '5. AREA B:'
        },
        area_b: {
            pil: 'FWCSD',
            startPattern: '5. AREA B:',
            endPattern: '6. AREA C:'
        },
        area_c: {
            pil: 'FWCSD',
            startPattern: '6. AREA C:',
            endPattern: '7. AREA D:'
        },
        area_d: {
            pil: 'FWCSD',
            startPattern: '7. AREA D:',
            endPattern: '8.'
        }
    }
};

// URL Parameter functions for bookmarkable URLs
function getUrlParams() {
    var params = {};
    var search = window.location.search.substring(1);
    if (search) {
        search.split('&').forEach(function(part) {
            var pair = part.split('=');
            params[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1] || '');
        });
    }
    return params;
}

function updateUrl(zone, basin) {
    var params = [];
    if (zone) params.push('zone=' + encodeURIComponent(zone));
    if (basin) params.push('basin=' + encodeURIComponent(basin));
    
    var newUrl = window.location.pathname + (params.length ? '?' + params.join('&') : '');
    history.replaceState({ zone: zone, basin: basin }, '', newUrl);
}

// Extract issue time from text
function extractIssueTime(text) {
    var timeMatch = text.match(/(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})/i);
    return timeMatch ? timeMatch[1] : 'Time unavailable';
}

// Extract synopsis from text
function extractSynopsis(text) {
    var synopsisMatch = text.match(/\d+\.\s*(METEOROLOGICAL SITUATION[^]*?)(?=\n\s*\d+\.\s*[A-Z])/i);
    if (synopsisMatch) {
        return synopsisMatch[1].replace(/\s+/g, ' ').trim();
    }
    return 'Synopsis unavailable';
}

// Extract warning from text
function extractWarning(text) {
    var upperText = text.toUpperCase();
    if (upperText.indexOf('HURRICANE FORCE WIND WARNING') !== -1) return 'HURRICANE FORCE WIND WARNING';
    if (upperText.indexOf('HURRICANE WARNING') !== -1) return 'HURRICANE WARNING';
    if (upperText.indexOf('STORM WARNING') !== -1) return 'STORM WARNING';
    if (upperText.indexOf('TROPICAL STORM WARNING') !== -1) return 'TROPICAL STORM WARNING';
    if (upperText.indexOf('GALE WARNING') !== -1) return 'GALE WARNING';
    if (upperText.indexOf('GALE FORCE') !== -1) return 'GALE FORCE POSSIBLE';
    if (upperText.indexOf('STORM FORCE') !== -1) return 'STORM FORCE POSSIBLE';
    return 'NONE';
}

// Get warning color
function getWarningColor(warning) {
    if (!warning) return '#808080';
    var upperWarning = warning.toUpperCase();
    for (var key in warningColors) {
        if (warningColors.hasOwnProperty(key) && upperWarning.indexOf(key) !== -1) {
            return warningColors[key];
        }
    }
    return '#808080';
}

// Get display name for a zone from GeoJSON
function getZoneDisplayName(zoneId, basin) {
    if (!navyGeoJson) return null;
    
    for (var i = 0; i < navyGeoJson.features.length; i++) {
        var feature = navyGeoJson.features[i];
        if (feature.properties.id === zoneId && feature.properties.basin === basin) {
            return feature.properties.name;
        }
    }
    return null;
}

// Parse OPAREA forecast from raw text
function parseOparea(text, startPattern, endPattern) {
    if (!text) return null;
    
    // Create regex to find the section
    var escapedStart = startPattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var escapedEnd = endPattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    
    // For Pacific format (4. AREA A: ... 5. AREA B:)
    if (startPattern.match(/^\d+\.\s*AREA/)) {
        var pattern = new RegExp(escapedStart + '([\\s\\S]*?)(?=' + escapedEnd + '|$)', 'i');
        var match = text.match(pattern);
        if (match) {
            return (startPattern + match[1]).trim();
        }
        return null;
    }
    
    // Match from start pattern to before end pattern (which starts a new section)
    var pattern = new RegExp('\\d+\\.\\s*(' + escapedStart + '[^]*?)(?=\\n\\s*\\d+\\.\\s*' + escapedEnd + '|$)', 'i');
    var match = text.match(pattern);
    
    if (match) {
        return match[1].trim();
    }
    
    // Fallback: try simpler pattern
    var simplePattern = new RegExp('(' + escapedStart + '[^]*?)(?=\\n\\s*\\d+\\.)', 'i');
    var simpleMatch = text.match(simplePattern);
    
    return simpleMatch ? simpleMatch[1].trim() : null;
}

// Load Navy GeoJSON (all zones)
function loadNavyGeoJson() {
    return fetch('assets/navy.geojson')
        .then(function(response) {
            if (!response.ok) throw new Error('Failed to load GeoJSON');
            return response.json();
        })
        .then(function(data) {
            navyGeoJson = data;
            console.log('[DEBUG] Loaded Navy GeoJSON with', data.features.length, 'features');
            return data;
        })
        .catch(function(error) {
            console.error('[DEBUG] Error loading Navy GeoJSON:', error);
            return null;
        });
}

// Convert GeoJSON coordinates to Leaflet format [lat, lng]
function geoJsonCoordsToLeaflet(coords) {
    // GeoJSON is [lng, lat], Leaflet needs [lat, lng]
    return coords[0].map(function(coord) {
        return [coord[1], coord[0]];
    });
}

// Calculate center of polygon
function calculateCenter(coords) {
    var latSum = 0, lngSum = 0;
    var points = coords[0];
    for (var i = 0; i < points.length; i++) {
        lngSum += points[i][0];
        latSum += points[i][1];
    }
    return [latSum / points.length, lngSum / points.length];
}

// Generate full text links HTML
function getFullTextLinksHtml() {
    return '<div class="full-text-links">' +
        '<h4>Full Forecast Products</h4>' +
        '<a href="' + forecastUrls.WRKFWNX01 + '" target="_blank">FOXX01 (Atlantic South)</a>' +
        '<a href="' + forecastUrls.WRKFWNX02 + '" target="_blank">FOXX02 (Atlantic North)</a>' +
        '<a href="' + forecastUrls.FWCSD + '" target="_blank">FWC San Diego (Pacific)</a>' +
        '</div>';
}

// Load forecast data from URLs
function loadForecastData() {
    console.log('[DEBUG] Loading forecast data from NWS...');
    
    // Show loading state
    document.getElementById('forecastPanel').innerHTML =
        '<h3>OPAREA Forecast</h3>' +
        getFullTextLinksHtml() +
        '<div class="no-data">Loading forecast data from NWS...</div>';

    var promises = Object.keys(forecastUrls).map(function(pil) {
        return fetch(forecastUrls[pil])
            .then(function(response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function(text) {
                console.log('[DEBUG] Loaded ' + pil + ', length: ' + text.length);
                rawTexts[pil] = text;
                return { pil: pil, text: text };
            })
            .catch(function(error) {
                console.error('[DEBUG] Error loading ' + pil + ':', error);
                return { pil: pil, text: null, error: error };
            });
    });

    Promise.all(promises).then(function(results) {
        console.log('[DEBUG] All forecasts loaded, parsing...');
        
        // Parse each OPAREA
        ['atlantic', 'pacific'].forEach(function(basin) {
            var config = opareaConfig[basin];
            for (var zoneId in config) {
                if (config.hasOwnProperty(zoneId)) {
                    var cfg = config[zoneId];
                    var text = rawTexts[cfg.pil];
                    
                    if (text) {
                        var forecastText = parseOparea(text, cfg.startPattern, cfg.endPattern);
                        var synopsis = extractSynopsis(text);
                        var issueTime = extractIssueTime(text);
                        
                        if (forecastText) {
                            parsedForecasts[basin][zoneId] = {
                                synopsis: synopsis,
                                forecast: forecastText,
                                warning: extractWarning(forecastText),
                                time: issueTime
                            };
                            console.log('[DEBUG] Parsed ' + zoneId + ' from ' + cfg.pil);
                        } else {
                            console.warn('[DEBUG] Could not parse ' + zoneId + ' from ' + cfg.pil);
                        }
                    }
                }
            }
        });
        
        // Reload zones with new data
        loadZones();
        
        // Reset panel
        document.getElementById('forecastPanel').innerHTML =
            '<h3>OPAREA Forecast</h3>' +
            getFullTextLinksHtml() +
            '<div class="no-data">Click on an OPAREA zone to view forecast details</div>';
        
        // Show pending zone from URL if any
        if (pendingZoneFromUrl) {
            var currentBasin = document.getElementById('basin').value;
            var zoneName = getZoneDisplayName(pendingZoneFromUrl, currentBasin);
            if (zoneName) {
                showForecast(pendingZoneFromUrl, zoneName);
            }
            pendingZoneFromUrl = null;
        }
    });
}

// Initialize map
function initMap() {
    var basin = document.getElementById('basin').value;
    var center = basin === 'atlantic' ? [33, -75] : [33, -119];
    var zoom = basin === 'atlantic' ? 5 : 6;

    map = L.map('map', { zoomControl: true }).setView(center, zoom);

    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Base/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 13,
        attribution: 'Esri Ocean Basemap'
    }).addTo(map);

    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Reference/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 13
    }).addTo(map);

    zonesLayer = L.layerGroup().addTo(map);

    L.control.scale({ imperial: true, metric: true, position: 'bottomright' }).addTo(map);
}

// Load zones onto map from GeoJSON
function loadZones() {
    var basin = document.getElementById('basin').value;

    zonesLayer.clearLayers();

    if (!navyGeoJson) {
        console.warn('[DEBUG] Navy GeoJSON not loaded yet');
        return;
    }

    navyGeoJson.features.forEach(function(feature) {
        // Only show zones for the selected basin
        if (feature.properties.basin !== basin) {
            return;
        }

        var zoneId = feature.properties.id;
        var displayName = feature.properties.name;

        var forecast = parsedForecasts[basin] && parsedForecasts[basin][zoneId];
        var warning = forecast ? forecast.warning : 'NONE';
        var color = getWarningColor(warning);

        var coords = geoJsonCoordsToLeaflet(feature.geometry.coordinates);
        var center = calculateCenter(feature.geometry.coordinates);

        var polygon = L.polygon(coords, {
            fillColor: color,
            weight: 2,
            opacity: 1,
            color: '#0a2647',
            fillOpacity: 0.55
        });

        polygon.zoneId = zoneId;
        polygon.zoneName = displayName;
        polygon.longName = displayName;

        polygon.bindPopup(
            '<div class="zone-popup-title">' + displayName + '</div>' +
            '<div class="zone-popup-id">Click to view forecast</div>'
        );

        polygon.on({
            mouseover: function(e) {
                e.target.setStyle({ weight: 3, color: '#2c74b3', fillOpacity: 0.75 });
                e.target.bringToFront();
            },
            mouseout: function(e) {
                var zId = e.target.zoneId;
                var currentBasin = document.getElementById('basin').value;
                var fcst = parsedForecasts[currentBasin];
                var w = fcst && fcst[zId] ? fcst[zId].warning : 'NONE';
                e.target.setStyle({
                    fillColor: getWarningColor(w),
                    weight: 2,
                    opacity: 1,
                    color: '#0a2647',
                    fillOpacity: 0.55
                });
            },
            click: function(e) {
                showForecast(e.target.zoneId, e.target.longName);
            }
        });

        polygon.addTo(zonesLayer);

        // Add label
        var label = L.marker([center[0], center[1]], {
            icon: L.divIcon({
                className: 'zone-label',
                html: '<div style="background: rgba(10,38,71,0.9); color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; border: 1px solid #2c74b3;">' + displayName + '</div>',
                iconSize: null
            })
        });
        label.addTo(zonesLayer);
    });
}

// Show forecast for a zone
function showForecast(zoneId, zoneName) {
    currentZone = zoneId;
    
    // Update URL with current zone
    var basin = document.getElementById('basin').value;
    updateUrl(zoneId, basin);

    // Open sidebar on mobile
    var sidebar = document.getElementById('sidebar');
    var toggle = document.getElementById('mobileToggle');
    if (window.innerWidth <= 900 && !sidebar.classList.contains('open')) {
        sidebar.classList.add('open');
        toggle.innerHTML = '&#9660;';
    }

    var panel = document.getElementById('forecastPanel');
    var forecast = parsedForecasts[basin] && parsedForecasts[basin][zoneId];

    if (!forecast) {
        panel.innerHTML = '<h3>OPAREA Forecast</h3>' +
            getFullTextLinksHtml() +
            '<div class="no-data">No forecast data available for ' + zoneName + '</div>';
        return;
    }

    var warningColor = getWarningColor(forecast.warning);
    var textColor = (warningColor === '#ffff00') ? '#000' : '#fff';

    var html = '<h3>OPAREA Forecast</h3>' +
        getFullTextLinksHtml() +
        '<div class="forecast-header">' + zoneName + ' OPAREA</div>' +
        '<div class="forecast-time">Issued: ' + forecast.time + '</div>';

    if (forecast.warning && forecast.warning !== 'NONE') {
        html += '<div class="warning-banner" style="background:' + warningColor + ';color:' + textColor + ';">' + forecast.warning + '</div>';
    }

    html += '<div class="synopsis-section">' +
        '<h4>Meteorological Situation</h4>' +
        '<p>' + forecast.synopsis + '</p>' +
        '</div>';

    // Format forecast as table
    html += '<div class="forecast-content">' +
        '<h4>Area Forecast</h4>' +
        formatForecastTable(forecast.forecast) +
        '</div>';

    panel.innerHTML = html;
    updateChart(forecast);
}

// Convert full direction names to abbreviations
function abbreviateDirection(dir) {
    if (!dir || dir === '-') return '-';
    var abbrevMap = {
        'NORTH': 'N', 'SOUTH': 'S', 'EAST': 'E', 'WEST': 'W',
        'NORTHEAST': 'NE', 'NORTHWEST': 'NW', 'SOUTHEAST': 'SE', 'SOUTHWEST': 'SW',
        'NORTH-NORTHEAST': 'NNE', 'NORTH-NORTHWEST': 'NNW',
        'SOUTH-SOUTHEAST': 'SSE', 'SOUTH-SOUTHWEST': 'SSW',
        'EAST-NORTHEAST': 'ENE', 'EAST-SOUTHEAST': 'ESE',
        'WEST-NORTHWEST': 'WNW', 'WEST-SOUTHWEST': 'WSW'
    };
    var upper = dir.toUpperCase().trim();
    return abbrevMap[upper] || dir;
}

// Format forecast text as a nice table
function formatForecastTable(text) {
    if (!text) return '<p>No forecast data</p>';
    
    // Check if this is Pacific format (starts with "4. AREA" or similar)
    var isPacific = /^\d+\.\s*AREA\s+[A-D]:/i.test(text.trim());
    
    if (isPacific) {
        return formatPacificForecast(text);
    }
    
    return formatAtlanticForecast(text);
}

// Format Pacific (FWC San Diego) forecast
function formatPacificForecast(text) {
    var html = '<div class="forecast-table-container">';
    
    // Pacific format parsing
    // Look for WIND and SEAS patterns
    var windData = [];
    var seasData = [];
    var skyData = [];
    
    // Parse wind: "DD/HHZ: DIRECTION ## TO ##" or "WINDS...DIRECTION ## TO ## KT"
    var windPattern = /(\d{2}\/\d{2}Z):\s*([A-Z-]+)\s+(\d+)\s+TO\s+(\d+)(G\d+)?/gi;
    var match;
    while ((match = windPattern.exec(text)) !== null) {
        windData.push({
            time: match[1],
            dir: match[2],
            low: match[3],
            high: match[4],
            gust: match[5] || ''
        });
    }
    
    // Also try "WINDS...N 10 TO 15 KT" pattern
    if (windData.length === 0) {
        var altWindPattern = /WINDS?[^.]*?([A-Z-]+)\s+(\d+)\s+TO\s+(\d+)\s*(?:KT|KNOTS)?/gi;
        while ((match = altWindPattern.exec(text)) !== null) {
            windData.push({
                time: '-',
                dir: match[1],
                low: match[2],
                high: match[3],
                gust: ''
            });
        }
    }
    
    // Parse seas: "COMBINED SEAS...DIRECTION ## TO ## FT" or similar
    var seasPattern = /COMBINED\s+SEAS[^.]*?([A-Z-]+)\s+(\d+)\s+TO\s+(\d+)\s*(?:FT|FEET)?/gi;
    while ((match = seasPattern.exec(text)) !== null) {
        seasData.push({
            time: '-',
            dir: match[1],
            low: match[2],
            high: match[3]
        });
    }
    
    // Also try timestamped seas
    if (seasData.length === 0) {
        var altSeasPattern = /(\d{2}\/\d{2}Z):[^,\n]*?(\d+)\s+TO\s+(\d+)\s*(?:FT|FEET)/gi;
        while ((match = altSeasPattern.exec(text)) !== null) {
            seasData.push({
                time: match[1],
                dir: '-',
                low: match[2],
                high: match[3]
            });
        }
    }
    
    // Try generic seas pattern "SEAS ## TO ## FT"
    if (seasData.length === 0) {
        var genericSeasPattern = /SEAS[^.]*?(\d+)\s+TO\s+(\d+)\s*(?:FT|FEET)?/gi;
        while ((match = genericSeasPattern.exec(text)) !== null) {
            seasData.push({
                time: '-',
                dir: '-',
                low: match[1],
                high: match[2]
            });
        }
    }
    
    // Parse sky/weather
    var skyPattern = /SKY\/(?:WX|WEATHER)[^.]*?\.\.\.([^.]+)/gi;
    while ((match = skyPattern.exec(text)) !== null) {
        skyData.push({ time: '-', value: match[1].trim() });
    }
    
    // Build table with same format as Atlantic
    html += '<table class="forecast-table"><thead><tr><th>Time</th><th>Sky</th><th>Wind Dir</th><th>Wind (kt)</th><th>Gust</th><th>Wave Dir</th><th>Waves (ft)</th></tr></thead><tbody>';
    
    // Combine wind and seas data by time
    var allTimes = [];
    windData.forEach(function(w) { if (allTimes.indexOf(w.time) === -1) allTimes.push(w.time); });
    seasData.forEach(function(s) { if (allTimes.indexOf(s.time) === -1) allTimes.push(s.time); });
    skyData.forEach(function(s) { if (allTimes.indexOf(s.time) === -1) allTimes.push(s.time); });
    
    // Sort times (handle '-' as first)
    allTimes.sort(function(a, b) {
        if (a === '-') return -1;
        if (b === '-') return 1;
        return a.localeCompare(b);
    });
    
    // Get sky value (often just one general description for Pacific)
    var generalSky = skyData.length > 0 ? skyData[0].value : '-';
    
    // If we only have '-' times, create rows for each data point
    if (allTimes.length === 1 && allTimes[0] === '-') {
        var maxRows = Math.max(windData.length, seasData.length);
        for (var i = 0; i < maxRows; i++) {
            var wind = windData[i];
            var seas = seasData[i];
            var skyVal = i === 0 ? generalSky : '-';
            
            var windDir = wind ? abbreviateDirection(wind.dir) : '-';
            var windSpd = wind ? wind.low + '-' + wind.high : '-';
            var gustVal = wind && wind.gust ? wind.gust.replace('G', '') : '-';
            var waveDir = seas ? abbreviateDirection(seas.dir) : '-';
            var waveHt = seas ? seas.low + '-' + seas.high : '-';
            
            html += '<tr><td>-</td><td>' + skyVal + '</td><td>' + windDir + '</td><td>' + windSpd + '</td><td>' + gustVal + '</td><td>' + waveDir + '</td><td>' + waveHt + '</td></tr>';
        }
    } else {
        allTimes.forEach(function(time, idx) {
            var sky = skyData.find(function(s) { return s.time === time; });
            var wind = windData.find(function(w) { return w.time === time; });
            var seas = seasData.find(function(s) { return s.time === time; });
            
            // For Pacific, sky is often general - show on first row or if matched
            var skyVal = sky ? sky.value : (idx === 0 && skyData.length > 0 ? generalSky : '-');
            var windDir = wind ? abbreviateDirection(wind.dir) : '-';
            var windSpd = wind ? wind.low + '-' + wind.high : '-';
            var gustVal = wind && wind.gust ? wind.gust.replace('G', '') : '-';
            var waveDir = seas ? abbreviateDirection(seas.dir) : '-';
            var waveHt = seas ? seas.low + '-' + seas.high : '-';
            
            html += '<tr><td>' + time + '</td><td>' + skyVal + '</td><td>' + windDir + '</td><td>' + windSpd + '</td><td>' + gustVal + '</td><td>' + waveDir + '</td><td>' + waveHt + '</td></tr>';
        });
    }
    
    html += '</tbody></table>';
    
    // Show raw text in collapsible section if parsing didn't get much
    if (windData.length === 0 && seasData.length === 0) {
        html += '<pre style="font-size:12px;margin-top:10px;white-space:pre-wrap;">' + text + '</pre>';
    }
    
    html += '</div>';
    return html;
}

// Format Atlantic (OPAREA) forecast
function formatAtlanticForecast(text) {
    var sections = {
        hazards: '',
        sky: [],
        visibility: [],
        wind: [],
        seas: [],
        temps: '',
        sst: '',
        outlookWind: [],
        outlookSeas: [],
        comments: ''
    };
    
    // Extract hazards (A section)
    var hazardsMatch = text.match(/A\.\s*(?:NATIONAL WEATHER SERVICE\s*)?HAZARDS?[^:]*:([\s\S]*?)(?=\s*B\.\s|$)/i);
    if (hazardsMatch) {
        sections.hazards = hazardsMatch[1].replace(/\.\.\./g, '').trim();
    }
    
    // Extract sky/weather (B section)
    var skyMatch = text.match(/B\.\s*SKY[^:]*:([\s\S]*?)(?=\s*C\.\s|$)/i);
    if (skyMatch) {
        var skyPattern = /(\d{2}\/\d{2}Z):\s*([^,\n]+)/gi;
        var match;
        while ((match = skyPattern.exec(skyMatch[1])) !== null) {
            sections.sky.push({ time: match[1], value: match[2].trim().replace(/[,.]$/, '') });
        }
    }
    
    // Extract visibility (C section)
    var visMatch = text.match(/C\.\s*V[SI][SB][BY][^:]*:([\s\S]*?)(?=\s*D\.\s|$)/i);
    if (visMatch) {
        var visPattern = /(\d{2}\/\d{2}Z):\s*([^,\n]+)/gi;
        var match;
        while ((match = visPattern.exec(visMatch[1])) !== null) {
            sections.visibility.push({ time: match[1], value: match[2].trim().replace(/[,.]$/, '') });
        }
    }
    
    // Extract wind (D section)
    var windMatch = text.match(/D\.\s*SURFACE WIND[^:]*:([\s\S]*?)(?=\s*E\.\s|$)/i);
    if (windMatch) {
        var windPattern = /(\d{2}\/\d{2}Z):\s*([A-Z-]+)\s+(\d+)\s+TO\s+(\d+)(G\d+)?/gi;
        var match;
        while ((match = windPattern.exec(windMatch[1])) !== null) {
            var gust = match[5] ? match[5] : '';
            sections.wind.push({ 
                time: match[1], 
                dir: match[2], 
                low: match[3], 
                high: match[4],
                gust: gust
            });
        }
    }
    
    // Extract seas (E section)
    var seasMatch = text.match(/E\.\s*COMBINED SEAS[^:]*:([\s\S]*?)(?=\s*F\.\s|$)/i);
    if (seasMatch) {
        var seasPattern = /(\d{2}\/\d{2}Z):\s*([A-Z-]+)\s+(\d+)\s+TO\s+(\d+)/gi;
        var match;
        while ((match = seasPattern.exec(seasMatch[1])) !== null) {
            sections.seas.push({ 
                time: match[1], 
                dir: match[2], 
                low: match[3], 
                high: match[4]
            });
        }
    }
    
    // Extract temps (F section) - format is DD/HHZ: MAX/MIN (e.g., 04/00Z: 49/25)
    var tempsMatch = text.match(/F\.\s*MAX\/MIN TEMPS[^:]*:([\s\S]*?)(?=\s*G\.\s|$)/i);
    if (tempsMatch) {
        // Match pattern after timestamp: "04/00Z: 49/25" - we want 49 and 25
        var tempVal = tempsMatch[1].match(/\d{2}\/\d{2}Z:\s*(\d+)\/(\d+)/);
        if (tempVal) {
            sections.temps = 'Max: ' + tempVal[1] + '°F, Min: ' + tempVal[2] + '°F';
        }
    }
    
    // Extract SST (G section) - format is DD/HHZ: TEMP (e.g., 02/00Z: 48)
    var sstMatch = text.match(/G\.\s*SST[^:]*:([\s\S]*?)(?=\s*H\.\s|$)/i);
    if (sstMatch) {
        // Match pattern after timestamp: "02/00Z: 48" - we want 48
        var sstVal = sstMatch[1].match(/\d{2}\/\d{2}Z:\s*(\d+)/);
        if (sstVal) {
            sections.sst = sstVal[1] + '°F';
        }
    }
    
    // Extract outlook winds (H.1 section)
    var outlookMatch = text.match(/H\.\s*OUTLOOK[^:]*:([\s\S]*?)(?=\s*I\.\s|$)/i);
    if (outlookMatch) {
        var outlookWindMatch = outlookMatch[1].match(/\(1\)\s*WINDS[^:]*:([\s\S]*?)(?=\s*\(2\)|$)/i);
        if (outlookWindMatch) {
            var windPattern = /(\d{2}\/\d{2}Z):\s*([A-Z-]+)\s+(\d+)\s+TO\s+(\d+)(G\d+)?/gi;
            var match;
            while ((match = windPattern.exec(outlookWindMatch[1])) !== null) {
                var gust = match[5] ? match[5] : '';
                sections.outlookWind.push({ 
                    time: match[1], 
                    dir: match[2], 
                    low: match[3], 
                    high: match[4],
                    gust: gust
                });
            }
        }
        
        var outlookSeasMatch = outlookMatch[1].match(/\(2\)\s*COMBINED SEAS[^:]*:([\s\S]*?)(?=\s*\(\d\)|I\.\s|$)/i);
        if (outlookSeasMatch) {
            var seasPattern = /(\d{2}\/\d{2}Z):\s*([A-Z-]+)\s+(\d+)\s+TO\s+(\d+)/gi;
            var match;
            while ((match = seasPattern.exec(outlookSeasMatch[1])) !== null) {
                sections.outlookSeas.push({ 
                    time: match[1], 
                    dir: match[2], 
                    low: match[3], 
                    high: match[4]
                });
            }
        }
    }
    
    // Extract comments (I section)
    var commentsMatch = text.match(/I\.\s*OTHER COMMENTS[^:]*:([\s\S]*?)$/i);
    if (commentsMatch) {
        sections.comments = commentsMatch[1].replace(/\(\d+\)/g, '•').trim();
    }
    
    // Build HTML table
    var html = '<div class="forecast-table-container">';
    
    // Hazards row
    if (sections.hazards) {
        html += '<div class="forecast-row hazards-row"><span class="row-label">Hazards:</span> ' + sections.hazards + '</div>';
    }
    
    // 24-Hour Forecast Table
    html += '<table class="forecast-table"><thead><tr><th>Time</th><th>Sky</th><th>Wind Dir</th><th>Wind (kt)</th><th>Gust</th><th>Wave Dir</th><th>Waves (ft)</th></tr></thead><tbody>';
    
    // Combine all times
    var allTimes = [];
    sections.wind.forEach(function(w) { if (allTimes.indexOf(w.time) === -1) allTimes.push(w.time); });
    sections.seas.forEach(function(s) { if (allTimes.indexOf(s.time) === -1) allTimes.push(s.time); });
    sections.sky.forEach(function(s) { if (allTimes.indexOf(s.time) === -1) allTimes.push(s.time); });
    allTimes.sort();
    
    allTimes.forEach(function(time) {
        var sky = sections.sky.find(function(s) { return s.time === time; });
        var wind = sections.wind.find(function(w) { return w.time === time; });
        var seas = sections.seas.find(function(s) { return s.time === time; });
        
        var skyVal = sky ? sky.value : '-';
        var windDir = wind ? abbreviateDirection(wind.dir) : '-';
        var windSpd = wind ? wind.low + '-' + wind.high : '-';
        var gustVal = wind && wind.gust ? wind.gust.replace('G', '') : '-';
        var waveDir = seas ? abbreviateDirection(seas.dir) : '-';
        var waveHt = seas ? seas.low + '-' + seas.high : '-';
        
        html += '<tr><td>' + time + '</td><td>' + skyVal + '</td><td>' + windDir + '</td><td>' + windSpd + '</td><td>' + gustVal + '</td><td>' + waveDir + '</td><td>' + waveHt + '</td></tr>';
    });
    
    html += '</tbody></table>';
    
    // Temps and SST
    if (sections.temps || sections.sst) {
        html += '<div class="forecast-row temps-row">';
        if (sections.temps) html += '<span><strong>Max/Min Temp:</strong> ' + sections.temps + '</span>';
        if (sections.sst) html += '<span style="margin-left:20px;"><strong>SST:</strong> ' + sections.sst + '</span>';
        html += '</div>';
    }
    
    // 48-Hour Outlook Table
    if (sections.outlookWind.length > 0 || sections.outlookSeas.length > 0) {
        html += '<h5 style="margin-top:15px;margin-bottom:5px;">48-Hour Outlook</h5>';
        html += '<table class="forecast-table"><thead><tr><th>Time</th><th>Wind Dir</th><th>Wind (kt)</th><th>Gust</th><th>Wave Dir</th><th>Waves (ft)</th></tr></thead><tbody>';
        
        var outlookTimes = [];
        sections.outlookWind.forEach(function(w) { if (outlookTimes.indexOf(w.time) === -1) outlookTimes.push(w.time); });
        sections.outlookSeas.forEach(function(s) { if (outlookTimes.indexOf(s.time) === -1) outlookTimes.push(s.time); });
        outlookTimes.sort();
        
        outlookTimes.forEach(function(time) {
            var wind = sections.outlookWind.find(function(w) { return w.time === time; });
            var seas = sections.outlookSeas.find(function(s) { return s.time === time; });
            
            var windDir = wind ? abbreviateDirection(wind.dir) : '-';
            var windSpd = wind ? wind.low + '-' + wind.high : '-';
            var gustVal = wind && wind.gust ? wind.gust.replace('G', '') : '-';
            var waveDir = seas ? abbreviateDirection(seas.dir) : '-';
            var waveHt = seas ? seas.low + '-' + seas.high : '-';
            
            html += '<tr><td>' + time + '</td><td>' + windDir + '</td><td>' + windSpd + '</td><td>' + gustVal + '</td><td>' + waveDir + '</td><td>' + waveHt + '</td></tr>';
        });
        
        html += '</tbody></table>';
    }
    
    // Comments
    if (sections.comments) {
        html += '<div class="forecast-row comments-row"><strong>Notes:</strong><br>' + sections.comments + '</div>';
    }
    
    html += '</div>';
    return html;
}

// Update wind/wave chart
function updateChart(forecast) {
    var ctx = document.getElementById('weatherChart').getContext('2d');
    if (weatherChart) weatherChart.destroy();

    var labels = [];
    var windSpeeds = [];
    var waveHeights = [];

    var text = forecast.forecast || '';
    
    // Navy forecast format parsing
    // D. SURFACE WIND (KTS): 
    //    04/00Z: SOUTHWEST 15 TO 20G25,
    //    04/06Z: WEST-SOUTHWEST 15 TO 20G25,
    // E. COMBINED SEAS (FT): 
    //    04/00Z: SOUTH-SOUTHEAST 2 TO 4,
    
    // Extract wind section - find content between "SURFACE WIND" and next section letter
    var windSectionMatch = text.match(/D\.\s*SURFACE WIND[^:]*:([\s\S]*?)(?=\s*E\.\s|$)/i);
    if (windSectionMatch) {
        var windContent = windSectionMatch[1];
        // Match "04/00Z: DIRECTION 15 TO 20" patterns
        var windPattern = /(\d{2})\/(\d{2})Z:\s*[A-Z-]+\s+(\d+)\s+TO\s+(\d+)/gi;
        var match;
        while ((match = windPattern.exec(windContent)) !== null) {
            var day = match[1];
            var hour = match[2];
            var windLow = parseInt(match[3]);
            var windHigh = parseInt(match[4]);
            windSpeeds.push(Math.max(windLow, windHigh));
            labels.push(day + '/' + hour + 'Z');
        }
    }

    // Extract seas section (E)
    var seasSectionMatch = text.match(/E\.\s*COMBINED SEAS[^:]*:([\s\S]*?)(?=\s*F\.\s|$)/i);
    if (seasSectionMatch) {
        var seasContent = seasSectionMatch[1];
        var seasPattern = /(\d{2})\/(\d{2})Z:\s*[A-Z-]+\s+(\d+)\s+TO\s+(\d+)/gi;
        var match;
        while ((match = seasPattern.exec(seasContent)) !== null) {
            var seaLow = parseInt(match[3]);
            var seaHigh = parseInt(match[4]);
            waveHeights.push(Math.max(seaLow, seaHigh));
        }
    }
    
    // Extract outlook section (H) for 48-hour data
    var outlookMatch = text.match(/H\.\s*OUTLOOK[^:]*:([\s\S]*?)(?=\s*I\.\s|$)/i);
    if (outlookMatch) {
        // Outlook winds (H.1)
        var outlookWindMatch = outlookMatch[1].match(/\(1\)\s*WINDS[^:]*:([\s\S]*?)(?=\s*\(2\)|$)/i);
        if (outlookWindMatch) {
            var windPattern = /(\d{2})\/(\d{2})Z:\s*[A-Z-]+\s+(\d+)\s+TO\s+(\d+)/gi;
            var match;
            while ((match = windPattern.exec(outlookWindMatch[1])) !== null) {
                var day = match[1];
                var hour = match[2];
                var windLow = parseInt(match[3]);
                var windHigh = parseInt(match[4]);
                windSpeeds.push(Math.max(windLow, windHigh));
                labels.push(day + '/' + hour + 'Z');
            }
        }
        
        // Outlook seas (H.2)
        var outlookSeasMatch = outlookMatch[1].match(/\(2\)\s*COMBINED SEAS[^:]*:([\s\S]*?)(?=\s*\(\d\)|I\.\s|$)/i);
        if (outlookSeasMatch) {
            var seasPattern = /(\d{2})\/(\d{2})Z:\s*[A-Z-]+\s+(\d+)\s+TO\s+(\d+)/gi;
            var match;
            while ((match = seasPattern.exec(outlookSeasMatch[1])) !== null) {
                var seaLow = parseInt(match[3]);
                var seaHigh = parseInt(match[4]);
                waveHeights.push(Math.max(seaLow, seaHigh));
            }
        }
    }

    // Fallback: try generic patterns if Atlantic format didn't work (e.g., Pacific format)
    if (windSpeeds.length === 0) {
        // Try timestamped pattern first
        var genericWindPattern = /(\d{2})\/(\d{2})Z:\s*[A-Z-]+\s+(\d+)\s+TO\s+(\d+)/gi;
        var match;
        while ((match = genericWindPattern.exec(text)) !== null) {
            var windLow = parseInt(match[3]);
            var windHigh = parseInt(match[4]);
            windSpeeds.push(Math.max(windLow, windHigh));
            if (labels.length < windSpeeds.length) {
                labels.push(match[1] + '/' + match[2] + 'Z');
            }
        }
        
        // Try Pacific "WINDS...DIRECTION ## TO ## KT" pattern
        if (windSpeeds.length === 0) {
            var pacificWindPattern = /WINDS?[^.]*?[A-Z-]+\s+(\d+)\s+TO\s+(\d+)\s*(?:KT|KNOTS)?/gi;
            while ((match = pacificWindPattern.exec(text)) !== null) {
                windSpeeds.push(Math.max(parseInt(match[1]), parseInt(match[2])));
            }
        }
    }
    
    // Fallback for seas if not found
    if (waveHeights.length === 0) {
        // Try "COMBINED SEAS...DIRECTION ## TO ## FT" pattern
        var pacificSeasPattern = /COMBINED\s+SEAS[^.]*?[A-Z-]*\s*(\d+)\s+TO\s+(\d+)\s*(?:FT|FEET)?/gi;
        var match;
        while ((match = pacificSeasPattern.exec(text)) !== null) {
            waveHeights.push(Math.max(parseInt(match[1]), parseInt(match[2])));
        }
        
        // Try generic "SEAS ## TO ## FT" pattern
        if (waveHeights.length === 0) {
            var genericSeasPattern = /SEAS[^.]*?(\d+)\s+TO\s+(\d+)\s*(?:FT|FEET)?/gi;
            while ((match = genericSeasPattern.exec(text)) !== null) {
                waveHeights.push(Math.max(parseInt(match[1]), parseInt(match[2])));
            }
        }
    }

    // Default labels if none set
    if (labels.length === 0) {
        if (windSpeeds.length > 0) {
            for (var i = 0; i < windSpeeds.length; i++) {
                labels.push('Period ' + (i + 1));
            }
        } else {
            labels = ['Current'];
        }
    }

    // Ensure we have data points matching labels
    while (windSpeeds.length < labels.length) windSpeeds.push(windSpeeds[windSpeeds.length - 1] || 0);
    while (waveHeights.length < labels.length) waveHeights.push(waveHeights[waveHeights.length - 1] || 0);
    
    // Trim arrays to same length, max 8 for readability
    var maxPoints = Math.min(labels.length, windSpeeds.length, 8);
    labels = labels.slice(0, maxPoints);
    windSpeeds = windSpeeds.slice(0, maxPoints);
    waveHeights = waveHeights.slice(0, maxPoints);

    weatherChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Wind (kt)',
                    data: windSpeeds,
                    borderColor: '#2c74b3',
                    backgroundColor: 'rgba(44,116,179,0.15)',
                    yAxisID: 'y',
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Waves (ft)',
                    data: waveHeights,
                    borderColor: '#205295',
                    backgroundColor: 'rgba(32,82,149,0.15)',
                    yAxisID: 'y1',
                    tension: 0.3,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { usePointStyle: true, padding: 10, font: { size: 11 } }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    title: { display: true, text: 'Wind (kt)', font: { size: 10 } },
                    beginAtZero: true
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    title: { display: true, text: 'Waves (ft)', font: { size: 10 } },
                    beginAtZero: true,
                    grid: { drawOnChartArea: false }
                }
            }
        }
    });
}

// Change basin
function changeBasin() {
    var basin = document.getElementById('basin').value;
    var center = basin === 'atlantic' ? [33, -75] : [32, -118];
    var zoom = basin === 'atlantic' ? 5 : 7;
    map.flyTo(center, zoom);
    
    // Update URL with new basin
    currentZone = null;
    updateUrl(null, basin);
    
    loadZones();

    // Reset forecast panel
    document.getElementById('forecastPanel').innerHTML =
        '<h3>OPAREA Forecast</h3>' +
        getFullTextLinksHtml() +
        '<div class="no-data">Click on an OPAREA zone to view forecast details</div>';
}

// Toggle sidebar (mobile)
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var toggle = document.getElementById('mobileToggle');
    sidebar.classList.toggle('open');
    toggle.innerHTML = sidebar.classList.contains('open') ? '&#9660;' : '&#9650;';
}

// Refresh data
function refreshData() {
    var btn = document.getElementById('refreshBtn');
    btn.textContent = 'Refreshing...';
    btn.disabled = true;

    // Clear cached data and reload
    rawTexts = {};
    parsedForecasts = { atlantic: {}, pacific: {} };
    
    loadForecastData();
    
    // Re-enable button after a delay
    setTimeout(function() {
        btn.textContent = 'Refresh Data';
        btn.disabled = false;
    }, 2000);
}

// Initialize application
document.addEventListener('DOMContentLoaded', function() {
    // Parse URL parameters
    var params = getUrlParams();
    console.log('[DEBUG] URL parameters:', JSON.stringify(params));
    
    // Set basin from URL if provided
    if (params.basin && (params.basin === 'atlantic' || params.basin === 'pacific')) {
        document.getElementById('basin').value = params.basin;
    }
    
    // Store zone to show after data loads
    if (params.zone) {
        pendingZoneFromUrl = params.zone;
        console.log('[DEBUG] Will show zone from URL after load:', pendingZoneFromUrl);
    }
    
    initMap();
    
    // Set initial map view based on basin
    var basin = document.getElementById('basin').value;
    if (basin === 'pacific') {
        map.setView([32, -118], 7);
    }
    
    // Load Navy GeoJSON first, then forecast data
    loadNavyGeoJson().then(function() {
        // Load forecast data from NWS (this will also call loadZones and handle pendingZoneFromUrl)
        loadForecastData();
    });

    // Event listeners
    document.getElementById('basin').addEventListener('change', changeBasin);
    document.getElementById('refreshBtn').addEventListener('click', refreshData);
    document.getElementById('mobileToggle').addEventListener('click', toggleSidebar);
    
    // Share button - copy URL to clipboard
    document.getElementById('shareBtn').addEventListener('click', function() {
        var btn = this;
        var originalText = btn.textContent;
        navigator.clipboard.writeText(window.location.href).then(function() {
            btn.textContent = 'Link Copied!';
            setTimeout(function() { btn.textContent = originalText; }, 2000);
        }).catch(function() {
            // Fallback for older browsers
            prompt('Copy this link:', window.location.href);
        });
    });

    // About modal
    var aboutModal = document.getElementById('aboutModal');
    document.getElementById('aboutBtn').addEventListener('click', function() {
        aboutModal.classList.add('open');
    });
    document.getElementById('modalClose').addEventListener('click', function() {
        aboutModal.classList.remove('open');
    });
    aboutModal.addEventListener('click', function(e) {
        if (e.target === aboutModal) {
            aboutModal.classList.remove('open');
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && aboutModal.classList.contains('open')) {
            aboutModal.classList.remove('open');
        }
    });
    
    // Handle browser back/forward
    window.addEventListener('popstate', function(event) {
        if (event.state) {
            if (event.state.basin) {
                document.getElementById('basin').value = event.state.basin;
                changeBasin();
            }
            if (event.state.zone) {
                var opareas = navyOpareas[document.getElementById('basin').value];
                if (opareas && opareas[event.state.zone]) {
                    showForecast(event.state.zone, opareas[event.state.zone].longName);
                }
            }
        }
    });
});
