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

// Forecast data URLs (local shtml directory)
var forecastUrls = {
    WRKFWNX01: '/shtml/WRKFWNX01.txt',
    WRKFWNX02: '/shtml/WRKFWNX02.txt',
    WRKFWNXPT: '/shtml/WRKFWNXPT.txt',
    FWCSD: 'https://ocean.weather.gov/.cj/.monitor/.navy/gfe_navy_zones_fwcsc1_onp_latest.txt'
};

// Parsed forecast data storage
var parsedForecasts = {
    atlantic: {},
    pacific: {}
};

// Raw text storage for each product
var rawTexts = {};

// Warning colors
var warningColors = {
    'HURRICANE FORCE WIND WARNING': '#ff0000',
    'HURRICANE WARNING': '#ff0000',
    'STORM WARNING': '#ffa500',
    'GALE WARNING': '#ffff00',
    'NONE': '#205295'
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
    if (!warning) return '#205295';
    var upperWarning = warning.toUpperCase();
    for (var key in warningColors) {
        if (warningColors.hasOwnProperty(key) && upperWarning.indexOf(key) !== -1) {
            return warningColors[key];
        }
    }
    return '#205295';
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
    return fetch('navy.geojson')
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

    html += '<div class="forecast-content">' +
        '<h4>Area Forecast</h4>' +
        '<pre>' + forecast.forecast + '</pre>' +
        '</div>';

    panel.innerHTML = html;
    updateChart(forecast);
}

// Update wind/wave chart
function updateChart(forecast) {
    var ctx = document.getElementById('weatherChart').getContext('2d');
    if (weatherChart) weatherChart.destroy();

    // Parse simple forecast data for chart
    var labels = ['Today', 'Tonight', 'Tomorrow'];
    var windSpeeds = [];
    var waveHeights = [];

    // Extract wind and wave data from forecast text
    var windMatches = forecast.forecast.match(/WINDS?\s+(\d+)\s+TO\s+(\d+)/gi);
    var seaMatches = forecast.forecast.match(/SEAS?\s+(\d+)\s+TO\s+(\d+)/gi);

    if (windMatches) {
        windMatches.forEach(function(match) {
            var nums = match.match(/(\d+)/g);
            if (nums && nums.length >= 2) {
                windSpeeds.push(Math.max(parseInt(nums[0]), parseInt(nums[1])));
            }
        });
    }

    if (seaMatches) {
        seaMatches.forEach(function(match) {
            var nums = match.match(/(\d+)/g);
            if (nums && nums.length >= 2) {
                waveHeights.push(Math.max(parseInt(nums[0]), parseInt(nums[1])));
            }
        });
    }

    // Ensure we have at least 3 data points
    while (windSpeeds.length < 3) windSpeeds.push(windSpeeds[windSpeeds.length - 1] || 10);
    while (waveHeights.length < 3) waveHeights.push(waveHeights[waveHeights.length - 1] || 4);

    weatherChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Wind (kt)',
                    data: windSpeeds.slice(0, 3),
                    borderColor: '#2c74b3',
                    backgroundColor: 'rgba(44,116,179,0.15)',
                    yAxisID: 'y',
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Waves (ft)',
                    data: waveHeights.slice(0, 3),
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
