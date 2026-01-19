<?php
namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RouteComputeController
{
    private string $poiFile = '/var/www/data/pois.json';
    private string $ghBaseUrl = 'http://graphhopper:8989';

    private function error(Response $response, string $code, string $message, array $details = null, int $status = 400): Response
    {
        return ResponseHelper::json($response, [
            'code' => $code,
            'message' => $message,
            'details' => $details
        ], $status);
    }

    private function loadPoisById(): array
    {
        if (!file_exists($this->poiFile)) return [];
        $data = json_decode((string)file_get_contents($this->poiFile), true);
        if (!is_array($data)) return [];

        $map = [];
        foreach ($data as $poi) {
            if (isset($poi['id'])) $map[(string)$poi['id']] = $poi;
        }
        return $map;
    }

    private function httpGetJson(string $url, int $timeoutSeconds = 20): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['_error' => "curl_error: $err", '_status' => 0];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['_error' => 'invalid_json_from_graphhopper', '_status' => $code, '_raw' => $raw];
        }

        $json['_status'] = $code;
        return $json;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (string)$request->getBody();

        // Strip UTF-8 BOM if present
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            return $this->error($response, 'invalid_request', 'Invalid JSON body.', null, 400);
        }

        $locations = $payload['locations'] ?? null;
        if (!is_array($locations) || count($locations) < 2) {
            return $this->error($response, 'invalid_request', 'locations must be an array with at least 2 items.', ['minItems' => 2], 400);
        }

        $vehicle = isset($payload['vehicle']) ? (string)$payload['vehicle'] : 'car';
        $format  = isset($payload['format']) ? (string)$payload['format'] : 'geojson';

        if (!in_array($format, ['geojson', 'encodedpolyline'], true)) {
            return $this->error($response, 'invalid_request', 'format must be geojson or encodedpolyline.', ['format' => $format], 400);
        }

        $poisById = $this->loadPoisById();

        $points = [];
        $poiSequence = [];

        foreach ($locations as $i => $loc) {
            if (!is_array($loc)) {
                return $this->error($response, 'invalid_request', 'Each location must be an object.', ['index' => $i], 400);
            }

            if (isset($loc['poiId'])) {
                $poiId = (string)$loc['poiId'];
                $poi = $poisById[$poiId] ?? null;

                if ($poi === null || !isset($poi['location']['lat'], $poi['location']['lon'])) {
                    return $this->error($response, 'invalid_request', 'Unknown poiId or POI missing coordinates.', ['index' => $i, 'poiId' => $poiId], 400);
                }

                $lat = (float)$poi['location']['lat'];
                $lon = (float)$poi['location']['lon'];

                $points[] = [$lat, $lon];
                $poiSequence[] = ['poiId' => $poiId, 'name' => $poi['name'] ?? null];
                continue;
            }

            if (isset($loc['lat'], $loc['lon']) && is_numeric($loc['lat']) && is_numeric($loc['lon'])) {
                $points[] = [(float)$loc['lat'], (float)$loc['lon']];
                continue;
            }


            return $this->error($response, 'invalid_request', 'Each location must have either poiId or lat/lon.', ['index' => $i, 'location' => $loc], 400);
        }

        $qs = [];
        foreach ($points as [$lat, $lon]) {
            $qs[] = 'point=' . rawurlencode($lat . ',' . $lon);
        }
        $qs[] = 'profile=' . rawurlencode($vehicle);
        $qs[] = 'instructions=false';
        $qs[] = 'calc_points=true';
        $qs[] = 'points_encoded=' . ($format === 'encodedpolyline' ? 'true' : 'false');

        $url = $this->ghBaseUrl . '/route?' . implode('&', $qs);

        $gh = $this->httpGetJson($url, 20);
        $status = (int)($gh['_status'] ?? 0);

        if ($status < 200 || $status >= 300 || !isset($gh['paths'][0])) {
            return $this->error($response, 'graphhopper_error', 'GraphHopper returned an error.', ['status' => $status, 'graphhopper' => $gh], 502);
        }

        $path = $gh['paths'][0];
        $distance = (float)($path['distance'] ?? 0.0);
        $timeMs   = (int)($path['time'] ?? 0);

        $out = [
            'distanceMeters' => $distance,
            'durationMillis' => $timeMs,
            'geometry' => null,
            'poiSequence' => $poiSequence
        ];

        if ($format === 'geojson') {
            $pointsObj = $path['points'] ?? null;
            if (is_array($pointsObj) && isset($pointsObj['type'], $pointsObj['coordinates'])) {
                $out['geometry'] = [
                    'type' => 'LineString',
                    'coordinates' => $pointsObj['coordinates']
                ];
            } else {
                return $this->error($response, 'graphhopper_error', 'GraphHopper did not return GeoJSON points.', ['path.points' => $pointsObj], 502);
            }
        } else {
            $poly = $path['points'] ?? null;
            if (!is_string($poly) || $poly === '') {
                return $this->error($response, 'graphhopper_error', 'GraphHopper did not return encoded polyline.', ['path.points' => $poly], 502);
            }
            $out['geometry'] = $poly;
        }

        return ResponseHelper::json($response, $out, 200);
    }
}
