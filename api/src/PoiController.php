<?php
namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PoiController
{
    private string $dataFile = '/var/www/data/pois.json';

    private function loadPois(): array
    {
        if (!file_exists($this->dataFile)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($this->dataFile), true);
        return is_array($data) ? $data : [];
    }

    private function error(Response $response, string $code, string $message, array $details = null, int $status = 400): Response
    {
        return ResponseHelper::json($response, [
            'code' => $code,
            'message' => $message,
            'details' => $details
        ], $status);
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    public function list(Request $request, Response $response): Response
    {
        $p = $request->getQueryParams();

        $q = isset($p['q']) ? trim((string)$p['q']) : null;
        $category = isset($p['category']) ? trim((string)$p['category']) : null;

        $lat = $p['lat'] ?? null;
        $lon = $p['lon'] ?? null;
        $radius = $p['radius'] ?? null;

        $limit = isset($p['limit']) ? (int)$p['limit'] : 50;
        $offset = isset($p['offset']) ? (int)$p['offset'] : 0;

        if ($limit < 1 || $limit > 500) {
            return $this->error($response, 'invalid_request', 'Invalid limit. Must be 1..500.', ['limit' => $limit], 400);
        }
        if ($offset < 0) {
            return $this->error($response, 'invalid_request', 'Invalid offset. Must be >= 0.', ['offset' => $offset], 400);
        }

        $hasGeo = ($lat !== null || $lon !== null || $radius !== null);
        $latF = null; $lonF = null; $radiusI = null;

        if ($hasGeo) {
            if ($lat === null || $lon === null || $radius === null) {
                return $this->error($response, 'invalid_request', 'Missing lat/lon/radius parameters.', [
                    'lat' => $lat, 'lon' => $lon, 'radius' => $radius
                ], 400);
            }
            if (!is_numeric($lat) || !is_numeric($lon)) {
                return $this->error($response, 'invalid_request', 'lat/lon must be numeric.', ['lat' => $lat, 'lon' => $lon], 400);
            }
            $latF = (float)$lat;
            $lonF = (float)$lon;

            $radiusI = (int)$radius;
            if ($radiusI < 1) {
                return $this->error($response, 'invalid_request', 'radius must be >= 1 (meters).', ['radius' => $radiusI], 400);
            }
        }

        $pois = $this->loadPois();

        if ($q !== null && $q !== '') {
            $qLower = mb_strtolower($q);
            $pois = array_values(array_filter($pois, function ($poi) use ($qLower) {
                $name = mb_strtolower((string)($poi['name'] ?? ''));
                $cat  = mb_strtolower((string)($poi['category'] ?? ''));
                $desc = mb_strtolower((string)($poi['description'] ?? ''));
                return str_contains($name, $qLower) || str_contains($cat, $qLower) || str_contains($desc, $qLower);
            }));
        }

        if ($category !== null && $category !== '') {
            $catLower = mb_strtolower($category);
            $pois = array_values(array_filter($pois, function ($poi) use ($catLower) {
                return mb_strtolower((string)($poi['category'] ?? '')) === $catLower;
            }));
        }

        if ($hasGeo) {
            $pois = array_values(array_filter($pois, function ($poi) use ($latF, $lonF, $radiusI) {
                $loc = $poi['location'] ?? null;
                if (!is_array($loc) || !isset($loc['lat'], $loc['lon'])) {
                    return false;
                }
                $d = $this->haversineMeters($latF, $lonF, (float)$loc['lat'], (float)$loc['lon']);
                return $d <= $radiusI;
            }));
        }

        $total = count($pois);
        $paged = array_slice($pois, $offset, $limit);

        return ResponseHelper::json($response, [
            'query' => [
                'q' => $q,
                'category' => $category,
                'lat' => $hasGeo ? $latF : null,
                'lon' => $hasGeo ? $lonF : null,
                'radius' => $hasGeo ? $radiusI : null,
                'limit' => $limit,
                'offset' => $offset
            ],
            'count' => $total,
            'results' => $paged
        ], 200);
    }

    public function getById(Request $request, Response $response, array $args): Response
    {
        $id = (string)($args['id'] ?? '');

        foreach ($this->loadPois() as $poi) {
            if (($poi['id'] ?? null) === $id) {
                return ResponseHelper::json($response, $poi, 200);
            }
        }

        return $this->error($response, 'not_found', 'POI not found', ['id' => $id], 404);
    }
}
