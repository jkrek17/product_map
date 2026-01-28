<?php
// 
//                            Leaflet display to see OPC text products
//

$TITLE_PREHTML = "";

// This page's title 
$TITLE = "Product Map";

include($_SERVER['DOCUMENT_ROOT'] . "/templates/header.php");

?>
<style>
/*
 * These CSS rules affect the tooltips within maps with the custom-popup
 * class. See the full CSS for all customizable options:
 */
.custom-popup .leaflet-popup-content-wrapper {
  background:#2c3e50;
  color:#fff;
  font-size:14px;
  line-height:22px;
  }
.custom-popup .leaflet-popup-content-wrapper a {
  color:rgba(255,255,255,0.5);
  }
.custom-popup .leaflet-popup-tip-container {
  width:30px;
  height:15px;
  }
.custom-popup .leaflet-popup-tip {
  border-left:15px solid transparent;
  border-right:15px solid transparent;
  border-top:15px solid #2c3e50;
  }

.info {
    padding: 6px 8px;
    font: 14px/16px Arial, Helvetica, sans-serif;
    background: grey;
    box-shadow: 0 0 15px rgba(0,0,0,0.2);
    border-radius: 8px;
    color: white;
}
.info h4 {
    margin: 0 0 3px;
    color: white;
}
.info h5 {
    margin: 0 0 3px;
    color: white;
}

.select {
  font-size: 14px;
  font-family: Arial, sans-serif;
  padding: 10px;
  margin: 5px;
  background-color: #2554c7;
  border: 1px solid #ccc;
  border-radius: 4px;
  -webkit-appearance: none; /* Removes default Chrome and Safari style */
  -moz-appearance: none; /* Removes default style Firefox */
  appearance: none; /* Removes default style for IE */
  display: block;
}

.select-wrapper:hover {
    background-color: #3498db; /* Change to your preferred hover color */
    color: #fff; /* Optional: Change text color on hover */
}

.dropdown-label {
    font-weight: bold;
}

.select-wrapper {
    margin-bottom: 5px; /* Adds some space below the dropdown */
    color: #fff;
}

.select {
    color: #fff;
    font-weight: bold; 
}


/* To style the dropdown arrow */
.select-wrapper {
  position: relative;
  display: inline-block;
}

.select-wrapper:after {
  content: '\25BC';
  position: absolute;
  top: 50%;
  right: 15px;
  pointer-events: none;
  transform: translateY(-50%);
}

/* Additional styles to improve the layout and typography */
body {
  font-family: Arial, sans-serif;
  color: #fff;
  padding: 20px;
}

ul.select {
  list-style-type: none;
  color: #fff;
  padding: 0;
  margin: 0 auto; /* Center the menu */
  display: flex; /* Use flexbox for layout */
  justify-content: center; /* Center flex items horizontally */
  flex-wrap: wrap; /* Allow items to wrap */
}

li {
  margin-right: 25px; /* Space between menu items */
}

li:last-child {
  margin-right: 0; /* Remove margin for the last item */
}

#text {
  white-space: pre-line;
  text-align: left;
  margin: 0 auto;
}

.content-container {
    display: flex;
    flex-wrap: nowrap; /* Prevents wrapping to ensure side-by-side layout */
    align-items: stretch; /* Makes children of the container stretch to fit the container's height */
}

#map {
    flex: 50%; /* Assigns 60% of the parent container's width to the map */
    height: 810px; /* Or any desired height */
}

#forecast-container {
    flex: 50%; /* Assigns 40% of the parent container's width to the forecast container */
    padding: 2px;
    overflow-y: auto; /* Adds scroll for overflow content */
    height: 800px; /* Match the map height or adjust as needed */
}

/* Optional: Add some space between the map and forecast container */
#map, #forecast-container {
    margin: 5px;
    border: 2px solid #000; /* Example: black border, 2 pixels thick */
}

#forecast-header {
    font-size: 20px;
    font-weight: bold;
    margin-bottom: 10px;
}

.forecast-content {
    font-family: 'Arial', sans-serif;
    font-size: 16px;
    background-color: #f5f5f5;
    padding: 5px; 
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 10px; 
}

.forecast-header {
    color: #333;
    font-size: 20px;
    margin-bottom: 8px; 
}

.forecast-time, .forecast-warning {
    color: #555;
    font-size: 0.95em;
    margin-bottom: 8px;
}

.day-forecast {
    background-color: #fff;
    padding: 5px; /* Reduced padding */
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 8px; /* Reduced margin between days */
}

.forecast-day {
    margin-bottom: 4px; /* Space between the day and forecast details */
}

.day-forecast span {
    display: block; /* Each detail on its own line */
    color: black;
    margin-bottom: 2px; /* Reduced space between details */
}

.forecast-warning {
    color: #000;
    padding: 5px;
    border-radius: 4px;
    margin-bottom: 10px;
    font-weight: bold;
}

.forecast-day-container {
    background-color: #2554c7; /* Example background color */
    color: #fff; /* Text color */
    padding: 5px;
    margin-bottom: 4px; /* Space between the day and the forecast details */
    border-radius: 4px;
    font-weight: bold;
}

.select option {
    color: #fff; /* Sets the text color of the options */
}

.legend-container {
  color: black;
  font-weight: bold;
  margin-top: 20px;
  padding: 10px;
  background-color: #f9f9f9;
  border-radius: 5px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  border: 2px solid #000; /* Example: black border, 2 pixels thick */
}

.legend-item {
  font-size: 14px;
  color: black;
  display: flex;
  align-items: center;
  margin-bottom: 5px;
}

.legend-color {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  margin-right: 10px;
  flex-shrink: 0; /* Prevents the color circle from shrinking */
}



</style>
<!-- NOTE: Code changes for the leafletjs settings below this line should not be needed -->
<!-- Change code as needed after the <body> tag -->

<!-- Begin main page content -->
<script src="https://code.jquery.com/jquery-3.1.1.min.js"
	crossorigin="anonymous"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.8.0/dist/leaflet.css"
   integrity="sha512-hoalWLoI8r4UszCkZ5kL8vayOGVae1oxXe/2A4AO6J9+580uKHDO3JdHb7NzwwzK5xr/Fs0W40kiNHxM9vyTtQ=="
   crossorigin=""/>
<link rel="stylesheet" href="/common/leaflet_plugins/leaflet-groupedlayercontrol/dist/leaflet.groupedlayercontrol.min.css" />
<!-- Make sure you put this AFTER Leaflet's CSS -->
<script src="https://unpkg.com/leaflet@1.8.0/dist/leaflet.js"
integrity="sha512-BB3hKbKWOc9Ez/TAwyWxNXeoV9c1v6FIeYiBieIWkpLjauysF18NzgR1MBNBXf8/KABdlkX68nAhlwcDFLGPCQ=="
crossorigin=""></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


<!--<link rel="stylesheet" href="./plugins/climo.css" type="text/html">-->
<script type="text/javascript" src="../data/offshores.js"></script>
<script type="text/javascript" src="../data/navtex2.js"></script>
<script type="text/javascript" src="vobra.js"></script>

<div align="center" id="desc" ><p class="title" style="width: 300px;" ></div>
<div align="center">
<ul class="select">
  <li>
    <label for="basin" class="dropdown-label">Basin:</label>
    <div class="select-wrapper">
      <select class="select" id="basin" onchange="changeBasin()" style="width: 150px;"></select>
    </div>
  </li>
  <li>
    <label for="product" class="dropdown-label">Product:</label>
    <div class="select-wrapper">
      <select class="select" id="product" onchange="changeBasin()" style="width: 150px;"></select>
    </div>
  </li>
</ul>

</div>

<div class="content-container">
    <div id="map" style="height: 800px;"></div>
    <div id="forecast-container"></div>
</div>
<div class="chart-container">
	<canvas id="weather-chart-container"></canvas>
</div>
<!-- Legend Container -->
<div class="legend-container">
  <h3>Legend</h3>
  <div class="legend-item"><span class="legend-color" style="background-color: yellow;"></span> GALE WARNING</div>
  <div class="legend-item"><span class="legend-color" style="background-color: orange;"></span> STORM WARNING / TROPICAL STORM WARNING</div>
  <div class="legend-item"><span class="legend-color" style="background-color: red;"></span> HURRICANE FORCE WIND WARNING / HURRICANE WARNING</div>
  <div class="legend-item"><span class="legend-color" style="background-color: pink;"></span> GALE FORCE POSSIBLE</div>
  <div class="legend-item"><span class="legend-color" style="background-color: purple;"></span> STORM FORCE / TROPICAL STORM CONDITIONS POSSIBLE</div>
  <div class="legend-item"><span class="legend-color" style="background-color: grey;"></span> NONE</div>
</div>



<br>
 

<script>
$(document).ready(function (){

	var dropdown = $('#basin');
	dropdown.empty()
	dropdown.append('<option selected="true" disabled>Choose</option>');
	//dropdown.append('<option>Atlantic Basin</option>');
	//dropdown.append('<option>Pacific Basin</option>');
	dropdown.append('<option>Western Atlantic</option>');
	dropdown.append('<option>Eastern Pacific</option>');
	dropdown.prop('selectedIndex',1);

	var dropdown = $('#product');
	dropdown.append('<option selected="true" disabled>Choose</option>');
	dropdown.append('<option>Offshore</option>');
	dropdown.append('<option>Navtex</option>');
	dropdown.append('<option>Vobra</option>');
	dropdown.prop('selectedIndex',1);

	changeBasin();
	//off_check();
	//nav_check();
	//vobra_check();
	check_warnings()
});


let zones = new L.LayerGroup();
let navtex = new L.LayerGroup();
let vobras = new L.LayerGroup();
let towers = new L.LayerGroup();
let lines = new L.LayerGroup();
let hs_zones = new L.LayerGroup();

function getColorForWarning(warning) {
    if (warning.match("GALE WARNING")) return 'yellow';
    if (warning.match("STORM WARNING") || warning.match("TROPICAL STORM WARNING")) return 'orange';
    if (warning.match("HURRICANE FORCE WIND WARNING") || warning.match("HURRICANE WARNING")) return 'red';
    if (warning.match("GALE FORCE")) return 'pink';
    if (warning.match("STORM FORCE") || warning.match("TROPICAL STORM CONDITIONS")) return 'purple';
    return 'grey'; // Default color
}

function paint_warning(warning, layer){
    var color = getColorForWarning(warning);
    layer.setStyle({fillColor: color});
}


async function loadOff(zone, name) {
	const response = await fetch('./off.json');
	const off_text = await response.json();
	//console.log(off_text); 
	updateForecast(zone, off_text, name);
}
async function loadNav(zone, name) {
	const response = await fetch('./nav.json');
	const off_text = await response.json();
	//console.log(off_text); 
	updateForecast(zone, off_text, name);
}
async function loadVob(zone, name) {
	console.log("Vobra");
	const response = await fetch('./vob.json');
	const vobra_text = await response.json();
	//console.log(vobra_text); 
	updateForecast(zone, vobra_text, name);
}

async function check_warnings() {

  let products = [
    { layerGroup: off, jsonFile: './off.json', identifyer: 'ID' },
    { layerGroup: navtex_js, jsonFile: './nav.json', identifyer: 'Name' },
    { layerGroup: vobras_js, jsonFile: './vob.json', identifyer: 'ID' }
  ];

  for(let i = 0; i < products.length; i++){ 
    let product = products[i];
    let response = await fetch(product.jsonFile);
    let text = await response.json();
    let input_layer = product.layerGroup;
    let identifyer = product.identifyer;

    //console.log(input_layer, text, identifyer);

    input_layer.eachLayer(function(layer) {
      for(let j = 0; j < text.length; j++){
        var zone = text[j].zone;
        var fcast = text[j].forecast;
        var warning = text[j].warning;
        var id = layer.feature.properties[identifyer];
        //console.log(zone, warning);
        if (zone == id){
          paint_warning(warning, layer)
	  //applyColorToWarningLine(warning, fcast);
        }
      }
    });
  }
}

let off = new L.geoJson(offshores, {
	style: style,
	onEachFeature: onEachFeature
});

let navtex_js = new L.geoJson(navtexs, {
	style: style,
	onEachFeature: onEachFeature
});

let vobras_js = new L.geoJson(vobras_poly, {
	style: style,
	onEachFeature: onEachFeature
});

function changeBasin(){

	//info.addTo(map);

	var basin_selected = document.getElementById('basin');
	b_sel = basin_selected.options[basin_selected.selectedIndex].value;

	var prod_selected = document.getElementById('product');
	p_sel = prod_selected.options[prod_selected.selectedIndex].value;

	plotTowers(p_sel);


	/*$.getJSON('hs.json', function(data){
		var hs_json = L.geoJson(data, {
			onEachFeature: function(feature, featureLayer, layer){
				onEachFeature;
				var wfo = feature.properties.WFO;
				var lon = feature.properties.LON;

				if( wfo == "OPC" && lon > -90){
					featureLayer.bindPopup("<a href= 'https://ocean.weather.gov/shtml/NFDHSFAT1.txt'>High Seas Forecast - North Atlantic</a>");
				}
				if( wfo == "OPC" && lon <= -90){
					featureLayer.bindPopup("<a href= 'https://ocean.weather.gov/shtml/NFDHSFEP1.txt'>High Seas Forecast - North Pacific</a>");
				}
				if( wfo == "NHC" && lon > -90){
					featureLayer.bindPopup("<a href= 'https://ocean.weather.gov/shtml/NFDHSFAT1.txt'>High Seas Forecast - North Atlantic</a>");
				}
				if( wfo == "NHC" && lon <= -90){
					featureLayer.bindPopup("<a href= 'https://ocean.weather.gov/shtml/NFDHSFEP1.txt'>High Seas Forecast - North Pacific</a>");
				}
				if( wfo == "HPA"){
					featureLayer.bindPopup("<a href= 'https://ocean.weather.gov/shtml/NFDHSFEPI.txt'>High Seas Forecast - East and Central North Pacific</a>");
				}		
			}
		}).addTo(hs_zones);
	});

	if (b_sel == "Atlantic Basin"){
		map.flyTo([45, -45],3.5);
		map.removeLayer(zones);
		map.removeLayer(towers);
		map.removeLayer(navtex_js);
		map.addLayer(hs_zones);
	}
	if (b_sel == "Pacific Basin"){
		map.flyTo([45,-160],3.5);
		map.removeLayer(zones);
		map.removeLayer(towers);
		map.removeLayer(navtex_js);
		map.addLayer(hs_zones);
	}*/

	if (b_sel == "Western Atlantic"){
		map.flyTo([38,-72],5);

		var defaultZoneID = "ANZ910"; 
		var defaultZoneName = "East of 69W and south of 39N to 250 NM ofshore"; 
		loadOff(defaultZoneID, defaultZoneName);

		if( p_sel == "Offshore"){
			map.removeLayer(navtex_js);
			map.removeLayer(vobras_js);
			zones.addLayer(off);
			zones.addTo(map);
		}
		if( p_sel == "Navtex"){
			navtex_js.addTo(map);
			map.removeLayer(zones);
			map.removeLayer(vobras_js);

		}
		if( p_sel == "Vobra"){
			map.removeLayer(navtex_js);
			map.removeLayer(zones);
			vobras_js.addTo(map);

		}
		towers.addTo(map);
		map.removeLayer(hs_zones);

	}
	if (b_sel == "Eastern Pacific"){
		map.flyTo([40,-125],5);
		towers.addTo(map);

		var defaultZoneID = "PZZ820"; 
		var defaultZoneName = "Point St. George to Point Arena between 60 NM and 150 NM offshore"; 
		loadOff(defaultZoneID, defaultZoneName);

		if( p_sel == "Offshore"){
			map.removeLayer(navtex_js);
			map.removeLayer(vobras_js);
			zones.addLayer(off);
			zones.addTo(map);
		}
		if( p_sel == "Navtex"){
			navtex_js.addTo(map);
			map.removeLayer(zones);
			map.removeLayer(vobras_js);

		}
		if( p_sel == "Vobra"){
			map.removeLayer(navtex_js);
			map.removeLayer(zones);
			vobras_js.addTo(map);

		}
		map.removeLayer(hs_zones);
	}
}

// Set basemap control options
// add here if we want another option for the background map
var oceanmap = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');


// Initialize map
var map = L.map('map', {
  center: [38,-65],
  zoom: 5,
  //Define the layers we want on by default
  layers: [oceanmap]
});

let tower_json =[ 
	{	"loc":[46.18771027961516, -123.83259449677874],
		"name": "Astoria, OR",
		"prod": "Navtex"
	},
	{	"loc": [38.0177981639767, -122.99135995585763],
		"name": "Pt. Reyes, CA",
		"prod": "Navtex"
	},
	{	"loc": [38.0177981639767, -122.99135995585763],
		"name": "Pt. Reyes, CA",
		"prod": "Vobra"
	},
	{	"loc": [35.562160413108046, -121.1061164158261],
		"name": "Cambria, CA",
		"prod": "Navtex"
	},
	{	"loc": [32.00616522986957, -80.8706967427459],
		"name": "Savannah, GA",
		"prod": "Navtex"
	},
	{	"loc": [36.77114451371314, -76.2867724183752],
		"name": "Cheasapeake, VA",
		"prod": "Vobra"
	},
	{	"loc": [42.35315943683515, -70.96204604850226],
		"name": "Boston, MA",
		"prod": "Navtex"
	},
	{	"loc": [36.91384586976777, -76.36374998697568],
		"name": "Portsmouth, VA",
		"prod": "Navtex"
	}];

var towerIcon = L.icon({
    iconUrl: 'https://img.icons8.com/external-smashingstocks-glyph-smashing-stocks/66/000000/external-radio-tower-network-and-communication-smashingstocks-glyph-smashing-stocks.png',

    iconSize:     [40, 50], // size of the icon
    shadowSize:   [50, 64], // size of the shadow
    iconAnchor:   [20, 40], // point of the icon which will correspond to marker's location
    shadowAnchor: [4, 62],  // the same for the shadow
    popupAnchor:  [-3, -76] // point from which the popup should open relative to the iconAnchor
});

function plotTowers(p_sel){
	towers.clearLayers();
	for(x = 0; x < tower_json.length; x++){
		let popup = "Location: " + tower_json[x].name + "<br>" + "Products: " + tower_json[x].prod;
		//console.log(p_sel, tower_json[x].prod);
		if (tower_json[x].prod == p_sel){L.marker(tower_json[x].loc, {icon: towerIcon}).addTo(towers).bindPopup(popup);}
	}
}

var forecastContainer = document.getElementById('forecast-container');


function updateForecast(zone, data, zoneName) {
    forecastContainer.innerHTML = ""; // Clear previous text

    // Create a container for the forecast content
    let forecastContent = document.createElement("div");
    forecastContent.className = "forecast-content";

    // Append zone name as a header
    let header = document.createElement("h4");
    header.className = "forecast-header";
    header.textContent = zoneName;
    forecastContent.appendChild(header);

    // Find the matching entry for the given zone
    const entry = data.find(entry => entry['zone'] === zone);
    if (entry) {
        let time = document.createElement("p");
        time.className = "forecast-time";
        time.innerHTML = `<strong>Issued: ${entry['time']}</strong><br>`;
        forecastContent.appendChild(time);

        if (entry['warning'] && entry['warning'] !== "none" && entry['warning'].trim() !== ""){
            let warning = document.createElement("div");
            warning.className = "forecast-warning";
            warning.style.backgroundColor = getColorForWarning(entry['warning']);
            warning.innerHTML = `<strong>${entry['warning']}</strong><br>`;
            forecastContent.appendChild(warning);
        }

        // Loop through the forecast entries
	entry['forecast'].forEach(forecast => {
	    let dayForecast = document.createElement("div");
	    dayForecast.className = "day-forecast";

	    // Create a separate container for the day of the week
	    let dayContainer = document.createElement("div");
	    dayContainer.className = "forecast-day-container"; // Apply a class for styling
	    dayContainer.textContent = forecast['Day'];

	    dayForecast.appendChild(dayContainer);

	    let forecastDetails = `
		<span><strong>Winds:</strong> ${forecast['Winds']}</span>
		<span><strong>Seas:</strong> ${forecast['Seas']}</span>
	    `;

	    if (forecast['Weather'] !== "N/A") {
		forecastDetails += `<span><strong>Weather:</strong> ${forecast['Weather']}</span>`;
	    }

	    // Add forecast details to dayForecast
	    let detailsDiv = document.createElement("div");
	    detailsDiv.innerHTML = forecastDetails;
	    dayForecast.appendChild(detailsDiv);

	    forecastContent.appendChild(dayForecast);
	});
	let chartContainer = document.createElement("div");
	chartContainer.id = "weather-chart-container";
	forecastContent.appendChild(chartContainer);

	// Load the chart into the placeholder
	loadWeatherChart(data, zone);
    }

    // Append the forecast content to the forecast container
    forecastContainer.appendChild(forecastContent);
}

function loadWeatherChart(forecastData, zone) {
	console.log(forecastData);
    let labels = [];
    let windSpeeds = [];
    let waveHeights = [];

	const entry = forecastData.find(entry => entry['zone'] === zone);

    if (entry) {

		entry.forecast.forEach(forecast => {
			fday = forecast.Day;
			wind = forecast.Winds;
			seas = forecast.Seas;
			
			labels.push(fday);

			console.log(fday, wind, seas);

			// Extract numbers and find the max
			let windMatch = forecast.Winds.match(/\d+/g);
			let waveMatch = forecast.Seas.match(/\d+/g);

			console.log(windMatch, waveMatch);

			let maxWindSpeed = windMatch ? Math.max(...windMatch.map(Number)) : 0;
			let maxWaveHeight = waveMatch ? Math.max(...waveMatch.map(Number)) : 0;

			console.log(maxWindSpeed, maxWaveHeight);

			windSpeeds.push(maxWindSpeed);
			waveHeights.push(maxWaveHeight);
		})

	}
	else{
		console.log("no entry found");
	}
	
	console.log(labels);
	console.log(windSpeeds);
	console.log(waveHeights);

    const ctx = document.getElementById('weather-chart-container').getContext('2d');
	// Destroy the old chart if it exists
	if (window.myForecastChart instanceof Chart) {
		window.myForecastChart.destroy();
	}

	// Create the new chart
	window.myForecastChart = new Chart(ctx, {
		type: 'line', // Changed to 'bar' for better visual representation
		data: {
		    labels: labels,
		    datasets: [{
		        label: 'Wind Speed (knots)',
		        data: windSpeeds,
		        backgroundColor: 'rgba(0, 0, 255, 0.7)',
		        borderColor: 'rgba(0, 0, 255, 1)',
		        borderWidth: 1,
		        yAxisID: 'y',
		    }, {
		        label: 'Wave Height (ft)',
		        data: waveHeights,
		        backgroundColor: 'rgba(255, 0, 0, 0.7)',
		        borderColor: 'rgba(255, 0, 0, 1)',
		        borderWidth: 1,
		        yAxisID: 'y1',
		    }]
		},
		options: {
		    scales: {
		        y: {
		            beginAtZero: true,
		            type: 'linear',
		            position: 'left',
		            title: {
		                display: true,
		                text: 'Wind Speed (knots)'
		            },
					suggestedMax: Math.max(...windSpeeds) + 10,
		        },
		        y1: {
		            beginAtZero: true,
		            type: 'linear',
		            position: 'right',
		            title: {
		                display: true,
		                text: 'Wave Height (ft)'
		            },
					suggestedMax: Math.max(...waveHeights) + 10,
		            grid: {
		                drawOnChartArea: false, // only want the grid lines for one axis to show up
		            },
		        },
		    },
		    responsive: true,
		    plugins: {
		        legend: {
		            position: 'top',
		        },
		        title: {
		            display: true,
		            text: 'Forecast Wind Speed and Wave Height'
		        }
		    }
		}
	});
}


function style(feature) {
	
   return {
        weight: 2,
        opacity: 1,
        color: 'black',
        dashArray: '3',
        fillOpacity: 0.7
    };

}

function highlightFeature(e) {

	var layer = e.target;
	//console.log(layer);
	layer.setStyle({
		weight: 5,
		color: 'red',
		dashArray: '',
		fillOpacity: 0.7
	});

	if (!L.Browser.ie && !L.Browser.opera && !L.Browser.edge) {
		layer.bringToFront();
	}
}


function resetHighlight(e) {

	var layer = e.target;

	layer.setStyle({
		weight: 2,
		opacity: 1,
		color: 'black',
		dashArray: '3',
		fillOpacity: 0.7
	});
}

function getForecast(e) {

	var prod_selected = document.getElementById('product');
	p_sel = prod_selected.options[prod_selected.selectedIndex].value;

	var layer = e.target;
	var zone = layer.feature.properties.ID;
	var name = layer.feature.properties.Name;
	console.log(zone, name);

	if( p_sel == "Offshore"){
		loadOff(zone, name);
	}
	if( p_sel == "Navtex"){
		loadNav(name, name);
	}
	if( p_sel == "Vobra"){
		loadVob(zone, name);
	}

	
}

function onEachFeature(feature, layer) {

	//{layer.setStyle({fillColor: '#D3D3D3'});}

	layer.on({
		mouseover: highlightFeature,
		mouseout: resetHighlight,
		click: getForecast
	});
}

$(document).ready(function () {

    // initialization code
    var defaultZoneID = "ANZ910"; 
    var defaultZoneName = "East of 69W and south of 39N to 250 NM ofshore"; 
    loadOff(defaultZoneID, defaultZoneName);

});


</script>

<!-- End main page content -->

<?php include($_SERVER['DOCUMENT_ROOT'] . "/templates/footer_opc.php"); ?>
