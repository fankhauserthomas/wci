<?php
/**
 * Weather Data Fetcher for GeoSphere Austria API
 * Fetches current high-resolution weather data for coordinates 47°N 11°E
 * 
 * API Documentation: https://dataset.api.hub.geosphere.at/v1/
 * License: Creative Commons Attribution 4.0
 */

class WeatherDataFetcher {
    private $baseUrl = 'https://dataset.api.hub.geosphere.at/v1';
    private $latitude = 47.0;
    private $longitude = 11.0;
    
    public function __construct($lat = 47.0, $lon = 11.0) {
        $this->latitude = $lat;
        $this->longitude = $lon;
    }
    
    /**
     * Get current weather data from TAWES weather stations
     */
    public function getCurrentStationData() {
        $url = $this->baseUrl . '/station/current/tawes-v1-10min';
        
        // Parameters for temperature, humidity, wind, pressure, precipitation
        $params = [
            'parameters' => ['TL', 'RF', 'WG', 'WR', 'P', 'RR'],
            'output_format' => 'json'
        ];
        
        return $this->makeRequest($url, $params);
    }
    
    /**
     * Get current forecast data using NWP (Numerical Weather Prediction)
     */
    public function getCurrentForecast() {
        $url = $this->baseUrl . '/timeseries/forecast/nwp-v1-1h-2500m';
        
        $params = [
            'lat_lon' => $this->latitude . ',' . $this->longitude,
            'parameters' => ['T2M', 'RH2M', 'WS10M', 'WD10M', 'MSL', 'TP'],
            'output_format' => 'json'
        ];
        
        return $this->makeRequest($url, $params);
    }
    
    /**
     * Get high-resolution nowcast data (15-minute resolution)
     */
    public function getNowcastData() {
        $url = $this->baseUrl . '/timeseries/forecast/nowcast-v1-15min-1km';
        
        $params = [
            'lat_lon' => $this->latitude . ',' . $this->longitude,
            'parameters' => ['T2M', 'RH', 'WS', 'WD', 'RR'],
            'output_format' => 'json'
        ];
        
        return $this->makeRequest($url, $params);
    }
    
    /**
     * Get recent historical data from INCA (high-resolution analysis)
     */
    public function getRecentHistoricalData($hours = 24) {
        $url = $this->baseUrl . '/timeseries/historical/inca-v1-1h-1km';
        
        $endTime = new DateTime();
        $startTime = clone $endTime;
        $startTime->sub(new DateInterval('PT' . $hours . 'H'));
        
        $params = [
            'lat_lon' => $this->latitude . ',' . $this->longitude,
            'parameters' => ['T2M', 'RH2M', 'WS10M', 'WD10M', 'RR'],
            'start' => $startTime->format('Y-m-d\TH:i'),
            'end' => $endTime->format('Y-m-d\TH:i'),
            'output_format' => 'json'
        ];
        
        return $this->makeRequest($url, $params);
    }
    
    /**
     * Get ensemble forecast data for uncertainty information
     */
    public function getEnsembleForecast() {
        $url = $this->baseUrl . '/timeseries/forecast/ensemble-v1-1h-2500m';
        
        $params = [
            'lat_lon' => $this->latitude . ',' . $this->longitude,
            'parameters' => ['T2M', 'RH2M', 'WS10M', 'TP'],
            'output_format' => 'json'
        ];
        
        return $this->makeRequest($url, $params);
    }
    
    /**
     * Make HTTP request to the API
     */
    private function makeRequest($url, $params = []) {
        $queryString = http_build_query($params);
        $requestUrl = $url . '?' . $queryString;
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/json',
                    'User-Agent: WeatherDataFetcher/1.0'
                ],
                'timeout' => 30
            ]
        ]);
        
        $response = @file_get_contents($requestUrl, false, $context);
        
        if ($response === false) {
            return [
                'error' => true,
                'message' => 'Failed to fetch data from API',
                'url' => $requestUrl
            ];
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'error' => true,
                'message' => 'Failed to decode JSON response',
                'raw_response' => $response
            ];
        }
        
        return [
            'error' => false,
            'data' => $data,
            'url' => $requestUrl,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get comprehensive weather data from multiple sources
     */
    public function getAllWeatherData() {
        $results = [];
        
        echo "Fetching current station data...\n";
        $results['station_current'] = $this->getCurrentStationData();
        
        echo "Fetching forecast data...\n";
        $results['forecast'] = $this->getCurrentForecast();
        
        echo "Fetching nowcast data...\n";
        $results['nowcast'] = $this->getNowcastData();
        
        echo "Fetching recent historical data...\n";
        $results['historical'] = $this->getRecentHistoricalData(24);
        
        echo "Fetching ensemble forecast...\n";
        $results['ensemble'] = $this->getEnsembleForecast();
        
        return $results;
    }
    
    /**
     * Format weather data for display
     */
    public function formatWeatherData($data) {
        if (!isset($data['data']) || $data['error']) {
            return "Error: " . ($data['message'] ?? 'Unknown error');
        }
        
        $output = "=== Weather Data ===\n";
        $output .= "Fetched at: " . $data['timestamp'] . "\n";
        $output .= "API URL: " . $data['url'] . "\n\n";
        
        if (isset($data['data']['features'])) {
            foreach ($data['data']['features'] as $feature) {
                if (isset($feature['properties'])) {
                    $props = $feature['properties'];
                    $output .= "Timestamp: " . ($props['time'] ?? 'N/A') . "\n";
                    
                    foreach ($props as $key => $value) {
                        if ($key !== 'time' && !is_null($value)) {
                            $output .= ucfirst($key) . ": " . $value . "\n";
                        }
                    }
                    $output .= "\n";
                }
            }
        } else {
            $output .= json_encode($data['data'], JSON_PRETTY_PRINT) . "\n";
        }
        
        return $output;
    }
}

// Main execution
if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    $fetcher = new WeatherDataFetcher(47.0, 11.0);
    
    if (isset($_GET['type'])) {
        $type = $_GET['type'];
        switch ($type) {
            case 'station':
                $data = $fetcher->getCurrentStationData();
                break;
            case 'forecast':
                $data = $fetcher->getCurrentForecast();
                break;
            case 'nowcast':
                $data = $fetcher->getNowcastData();
                break;
            case 'historical':
                $data = $fetcher->getRecentHistoricalData(24);
                break;
            case 'ensemble':
                $data = $fetcher->getEnsembleForecast();
                break;
            default:
                $data = $fetcher->getAllWeatherData();
        }
    } else {
        $data = $fetcher->getAllWeatherData();
    }
    
    // Output format
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        if (is_array($data) && isset($data['station_current'])) {
            // Multiple data sources
            foreach ($data as $source => $sourceData) {
                echo "=== " . strtoupper($source) . " ===\n";
                echo $fetcher->formatWeatherData($sourceData);
                echo "\n" . str_repeat("=", 50) . "\n\n";
            }
        } else {
            // Single data source
            echo $fetcher->formatWeatherData($data);
        }
    }
} else {
    // Web interface
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Weather Data for 47°N 11°E</title>
        <meta charset="utf-8">
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .button { padding: 10px 15px; margin: 5px; background: #007cba; color: white; text-decoration: none; border-radius: 5px; }
            .button:hover { background: #005a8b; }
            pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
            .info { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <h1>GeoSphere Austria Weather Data Fetcher</h1>
        <p>High-resolution weather data for coordinates <strong>47°N 11°E</strong></p>
        
        <div class="info">
            <h3>Available Data Sources:</h3>
            <ul>
                <li><strong>Station Data:</strong> Current measurements from TAWES weather stations (10-minute resolution)</li>
                <li><strong>Forecast:</strong> Numerical Weather Prediction model data (1-hour resolution, 2.5km grid)</li>
                <li><strong>Nowcast:</strong> High-resolution short-term forecast (15-minute resolution, 1km grid)</li>
                <li><strong>Historical:</strong> Recent INCA analysis data (1-hour resolution, 1km grid)</li>
                <li><strong>Ensemble:</strong> Ensemble forecast with uncertainty information</li>
            </ul>
        </div>
        
        <div>
            <a href="?run=1" class="button">Get All Weather Data</a>
            <a href="?run=1&type=station" class="button">Station Data</a>
            <a href="?run=1&type=forecast" class="button">Forecast</a>
            <a href="?run=1&type=nowcast" class="button">Nowcast</a>
            <a href="?run=1&type=historical" class="button">Historical (24h)</a>
            <a href="?run=1&type=ensemble" class="button">Ensemble</a>
        </div>
        
        <div style="margin-top: 20px;">
            <a href="?run=1&format=json" class="button">JSON Output</a>
            <a href="?run=1&format=json&type=forecast" class="button">Forecast JSON</a>
        </div>
        
        <div class="info">
            <h3>API Information:</h3>
            <p><strong>Base URL:</strong> https://dataset.api.hub.geosphere.at/v1/</p>
            <p><strong>License:</strong> Creative Commons Attribution 4.0</p>
            <p><strong>Rate Limits:</strong> 5 requests/second, 240 requests/hour</p>
            <p><strong>Coordinates:</strong> 47°N 11°E (approximately Innsbruck area)</p>
        </div>
        
        <h3>Parameter Explanations:</h3>
        <ul>
            <li><strong>T2M/TL:</strong> Temperature at 2m height (°C)</li>
            <li><strong>RH2M/RF:</strong> Relative humidity at 2m height (%)</li>
            <li><strong>WS10M/WG:</strong> Wind speed at 10m height (m/s)</li>
            <li><strong>WD10M/WR:</strong> Wind direction at 10m height (°)</li>
            <li><strong>MSL/P:</strong> Mean sea level pressure (hPa)</li>
            <li><strong>TP/RR:</strong> Total precipitation (mm)</li>
        </ul>
    </body>
    </html>
    <?php
}
?>
