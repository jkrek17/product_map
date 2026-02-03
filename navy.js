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

// Navy OPAREA definitions with approximate coordinates
var navyOpareas = {
    atlantic: {
        boston: {
            name: "Boston OPAREA",
            longName: "Boston",
            coords: [
                [42.8, -70.0], [42.8, -69.0], [42.0, -69.0], [42.0, -69.5],
                [41.5, -69.5], [41.5, -70.5], [42.3, -70.5], [42.8, -70.0]
            ],
            center: [42.15, -69.75],
            pil: "WRKFWNX02"
        },
        race_rock: {
            name: "Race Rock OPAREA",
            longName: "Race Rock",
            coords: [
                [41.4, -72.5], [41.4, -71.5], [41.0, -71.5], [41.0, -72.0],
                [40.8, -72.0], [40.8, -72.5], [41.4, -72.5]
            ],
            center: [41.1, -72.0],
            pil: "WRKFWNX02"
        },
        narrabay: {
            name: "Narragansett Bay OPAREA",
            longName: "Narragansett Bay",
            coords: [
                [41.6, -71.5], [41.6, -70.5], [41.0, -70.5], [41.0, -71.0],
                [40.5, -71.0], [40.5, -71.5], [41.6, -71.5]
            ],
            center: [41.05, -71.0],
            pil: "WRKFWNX02"
        },
        vacapes: {
            name: "VACAPES OPAREA",
            longName: "Virginia Capes",
            coords: [
                [37.5, -75.0], [37.5, -74.0], [37.0, -74.0], [37.0, -74.5],
                [36.5, -74.5], [36.5, -75.5], [37.0, -75.5], [37.5, -75.0]
            ],
            center: [37.0, -74.75],
            pil: "WRKFWNX01"
        },
        cherry_point: {
            name: "Cherry Point OPAREA",
            longName: "Cherry Point",
            coords: [
                [35.5, -75.5], [35.5, -74.5], [34.5, -74.5], [34.5, -75.0],
                [34.0, -75.0], [34.0, -76.0], [35.0, -76.0], [35.5, -75.5]
            ],
            center: [34.75, -75.25],
            pil: "WRKFWNX01"
        },
        charleston: {
            name: "Charleston OPAREA",
            longName: "Charleston",
            coords: [
                [33.5, -78.5], [33.5, -77.5], [32.5, -77.5], [32.5, -78.0],
                [32.0, -78.0], [32.0, -79.0], [33.0, -79.0], [33.5, -78.5]
            ],
            center: [32.75, -78.25],
            pil: "WRKFWNX01"
        },
        jacksonville: {
            name: "Jacksonville OPAREA",
            longName: "Jacksonville",
            coords: [
                [31.5, -80.0], [31.5, -79.0], [30.5, -79.0], [30.5, -79.5],
                [29.5, -79.5], [29.5, -80.5], [30.5, -80.5], [31.5, -80.0]
            ],
            center: [30.5, -79.75],
            pil: "WRKFWNX01"
        },
        port_canaveral: {
            name: "Port Canaveral OPAREA",
            longName: "Port Canaveral",
            coords: [
                [29.0, -79.5], [29.0, -78.5], [28.0, -78.5], [28.0, -79.0],
                [27.5, -79.0], [27.5, -80.0], [28.5, -80.0], [29.0, -79.5]
            ],
            center: [28.25, -79.25],
            pil: "WRKFWNX01"
        },
        toto: {
            name: "Tongue of the Ocean OPAREA",
            longName: "Tongue of the Ocean",
            coords: [
                [25.0, -77.5], [25.0, -76.5], [24.0, -76.5], [24.0, -77.0],
                [23.5, -77.0], [23.5, -78.0], [24.5, -78.0], [25.0, -77.5]
            ],
            center: [24.25, -77.25],
            pil: "WRKFWNX01"
        }
    },
    pacific: {
        socal: {
            name: "SOCAL OPAREA",
            longName: "Southern California",
            coords: [
                [34.0, -120.5], [34.0, -117.5], [32.5, -117.5], [32.5, -118.5],
                [32.0, -118.5], [32.0, -120.5], [33.0, -120.5], [34.0, -120.5]
            ],
            center: [33.0, -119.0],
            pil: "WRKFWNXPT"
        },
        point_mugu: {
            name: "Point Mugu OPAREA",
            longName: "Point Mugu",
            coords: [
                [34.5, -120.0], [34.5, -119.0], [34.0, -119.0], [34.0, -119.5],
                [33.5, -119.5], [33.5, -120.5], [34.0, -120.5], [34.5, -120.0]
            ],
            center: [34.0, -119.5],
            pil: "WRKFWNXPT"
        },
        san_clemente: {
            name: "San Clemente Island OPAREA",
            longName: "San Clemente Island",
            coords: [
                [33.5, -119.0], [33.5, -118.0], [32.5, -118.0], [32.5, -118.5],
                [32.0, -118.5], [32.0, -119.5], [33.0, -119.5], [33.5, -119.0]
            ],
            center: [32.75, -118.5],
            pil: "WRKFWNXPT"
        }
    }
};

// OPAREA parsing configuration
var opareaConfig = {
    atlantic: {
        boston: {
            pil: 'WRKFWNX02',
            startPattern: 'BOSTON OPAREA:',
            endPattern: 'NARRAGANSETT BAY'
        },
        race_rock: {
            pil: 'WRKFWNX02',
            startPattern: 'RACE ROCK',
            endPattern: 'BOSTON'
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
        socal: {
            pil: 'FWCSD',
            startPattern: 'SOCAL',
            endPattern: 'POINT MUGU'
        },
        point_mugu: {
            pil: 'FWCSD',
            startPattern: 'POINT MUGU',
            endPattern: 'SAN CLEMENTE'
        },
        san_clemente: {
            pil: 'FWCSD',
            startPattern: 'SAN CLEMENTE',
            endPattern: 'FORECASTER'
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

// Parse OPAREA forecast from raw text
function parseOparea(text, startPattern, endPattern) {
    if (!text) return null;
    
    // Create regex to find the section
    var escapedStart = startPattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var escapedEnd = endPattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    
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
            var opareas = navyOpareas[currentBasin];
            if (opareas && opareas[pendingZoneFromUrl]) {
                showForecast(pendingZoneFromUrl, opareas[pendingZoneFromUrl].longName);
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

// Load zones onto map
function loadZones() {
    var basin = document.getElementById('basin').value;
    var opareas = navyOpareas[basin];

    zonesLayer.clearLayers();

    for (var zoneId in opareas) {
        if (opareas.hasOwnProperty(zoneId)) {
            var zone = opareas[zoneId];
            var forecast = parsedForecasts[basin] && parsedForecasts[basin][zoneId];
            var warning = forecast ? forecast.warning : 'NONE';
            var color = getWarningColor(warning);

            var polygon = L.polygon(zone.coords, {
                fillColor: color,
                weight: 2,
                opacity: 1,
                color: '#0a2647',
                fillOpacity: 0.55
            });

            polygon.zoneId = zoneId;
            polygon.zoneName = zone.name;
            polygon.longName = zone.longName;

            polygon.bindPopup(
                '<div class="zone-popup-title">' + zone.name + '</div>' +
                '<div class="zone-popup-id">Click to view forecast</div>'
            );

            polygon.on({
                mouseover: function(e) {
                    e.target.setStyle({ weight: 3, color: '#2c74b3', fillOpacity: 0.75 });
                    e.target.bringToFront();
                },
                mouseout: function(e) {
                    var zId = e.target.zoneId;
                    var fcst = parsedForecasts[document.getElementById('basin').value];
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
            var label = L.marker(zone.center, {
                icon: L.divIcon({
                    className: 'zone-label',
                    html: '<div style="background: rgba(10,38,71,0.9); color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; border: 1px solid #2c74b3;">' + zone.longName + '</div>',
                    iconSize: null
                })
            });
            label.addTo(zonesLayer);
        }
    }
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
    var center = basin === 'atlantic' ? [33, -75] : [33, -119];
    var zoom = basin === 'atlantic' ? 5 : 6;
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
        map.setView([33, -119], 6);
    }
    
    // Load forecast data from NWS (this will also call loadZones and handle pendingZoneFromUrl)
    loadForecastData();

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
