<?php
namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RoutesController
{
    public function __construct(
        private RouteRepository $repo = new RouteRepository()
    ) {}

    private function error(Response $response, string $code, string $message, array $details = null, int $status = 400): Response
    {
        return ResponseHelper::json($response, [
            'code' => $code,
            'message' => $message,
            'details' => $details
        ], $status);
    }

    private function readJson(Request $request): ?array
    {
        $body = (string)$request->getBody();
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    private function normalizePublic($v): ?bool
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v;
        $s = strtolower((string)$v);
        if ($s === 'true' || $s === '1') return true;
        if ($s === 'false' || $s === '0') return false;
        return null;
    }

    private function validateLineString($geom): bool
    {
        if (!is_array($geom)) return false;
        if (($geom['type'] ?? null) !== 'LineString') return false;
        $coords = $geom['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) return false;

        foreach ($coords as $pt) {
            if (!is_array($pt) || count($pt) < 2) return false;
            if (!is_numeric($pt[0]) || !is_numeric($pt[1])) return false; // lon,lat
        }
        return true;
    }

    // GET /routes  -> RouteListResponse {count, results}
    public function list(Request $request, Response $response): Response
    {
        $p = $request->getQueryParams();

        $public = $this->normalizePublic($p['public'] ?? null);
        if (($p['public'] ?? null) !== null && $public === null) {
            return $this->error($response, 'invalid_request', 'public must be a boolean.', ['public' => $p['public']], 400);
        }

        $ownerId = isset($p['ownerId']) ? trim((string)$p['ownerId']) : null;

        $limit = isset($p['limit']) ? (int)$p['limit'] : 50;
        $offset = isset($p['offset']) ? (int)$p['offset'] : 0;

        if ($limit < 1 || $limit > 500) {
            return $this->error($response, 'invalid_request', 'Invalid limit. Must be 1..500.', ['limit' => $limit], 400);
        }
        if ($offset < 0) {
            return $this->error($response, 'invalid_request', 'Invalid offset. Must be >= 0.', ['offset' => $offset], 400);
        }

        $routes = $this->repo->all();

        // Filters per OpenAPI
        if ($public !== null) {
            $routes = array_values(array_filter($routes, fn($r) => (bool)($r['public'] ?? false) === $public));
        }
        if ($ownerId !== null && $ownerId !== '') {
            $routes = array_values(array_filter($routes, fn($r) => (string)($r['ownerId'] ?? '') === $ownerId));
        }

        $total = count($routes);
        $paged = array_slice($routes, $offset, $limit);

        return ResponseHelper::json($response, [
            'count' => $total,
            'results' => $paged
        ], 200);
    }

    // POST /routes -> RoutePersistRequest -> 201 Route
    public function create(Request $request, Response $response): Response
    {
        $payload = $this->readJson($request);
        if ($payload === null) {
            return $this->error($response, 'invalid_request', 'Invalid JSON body.', null, 400);
        }

        $name = isset($payload['name']) ? trim((string)$payload['name']) : '';
        $public = $payload['public'] ?? null;
        $vehicle = array_key_exists('vehicle', $payload) ? $payload['vehicle'] : null;
        $ownerId = array_key_exists('ownerId', $payload) ? $payload['ownerId'] : null;
        $poiSequence = $payload['poiSequence'] ?? [];
        $geometry = $payload['geometry'] ?? null;
        $encodedPolyline = array_key_exists('encodedPolyline', $payload) ? $payload['encodedPolyline'] : null;

        if ($name === '') {
            return $this->error($response, 'invalid_request', 'name is required.', null, 400);
        }

        if (!is_bool($public)) {
            return $this->error($response, 'invalid_request', 'public is required and must be boolean.', ['public' => $public], 400);
        }

        if (!$this->validateLineString($geometry)) {
            return $this->error($response, 'invalid_request', 'geometry is required and must be a GeoJSON LineString.', null, 400);
        }

        if ($vehicle !== null && !is_string($vehicle)) {
            return $this->error($response, 'invalid_request', 'vehicle must be string or null.', ['vehicle' => $vehicle], 400);
        }

        if ($ownerId !== null && !is_string($ownerId)) {
            return $this->error($response, 'invalid_request', 'ownerId must be string or null.', ['ownerId' => $ownerId], 400);
        }

        if (!is_array($poiSequence)) {
            return $this->error($response, 'invalid_request', 'poiSequence must be array if provided.', null, 400);
        }

        if ($encodedPolyline !== null && !is_string($encodedPolyline)) {
            return $this->error($response, 'invalid_request', 'encodedPolyline must be string or null.', ['encodedPolyline' => $encodedPolyline], 400);
        }

        $id = 'route_' . bin2hex(random_bytes(8));
        $now = gmdate('c');

        $route = [
            'id' => $id,
            'name' => $name,
            'public' => $public,
            'vehicle' => $vehicle,
            'ownerId' => $ownerId,
            'poiSequence' => $poiSequence,
            'geometry' => $geometry,
            'encodedPolyline' => $encodedPolyline,
            'createdAt' => $now,
            'updatedAt' => $now
        ];

        $this->repo->insert($route);
        return ResponseHelper::json($response, $route, 201);
    }

    // GET /routes/{id} -> Route
    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (string)($args['id'] ?? '');
        $route = $this->repo->find($id);
        if ($route === null) {
            return $this->error($response, 'not_found', 'Route not found', ['id' => $id], 404);
        }
        return ResponseHelper::json($response, $route, 200);
    }

    // PUT /routes/{id} -> RouteUpdateRequest -> 200 Route
    public function put(Request $request, Response $response, array $args): Response
    {
        $id = (string)($args['id'] ?? '');
        $existing = $this->repo->find($id);
        if ($existing === null) {
            return $this->error($response, 'not_found', 'Route not found', ['id' => $id], 404);
        }

        $payload = $this->readJson($request);
        if ($payload === null) {
            return $this->error($response, 'invalid_request', 'Invalid JSON body.', null, 400);
        }

        $name = isset($payload['name']) ? trim((string)$payload['name']) : '';
        $public = $payload['public'] ?? null;
        $vehicle = array_key_exists('vehicle', $payload) ? $payload['vehicle'] : null;
        $poiSequence = $payload['poiSequence'] ?? [];
        $geometry = $payload['geometry'] ?? null;
        $encodedPolyline = array_key_exists('encodedPolyline', $payload) ? $payload['encodedPolyline'] : null;

        if ($name === '' || !is_bool($public) || !$this->validateLineString($geometry)) {
            return $this->error($response, 'invalid_request', 'PUT requires name, public, geometry.', null, 400);
        }

        $updated = $existing;
        $updated['name'] = $name;
        $updated['public'] = $public;
        $updated['vehicle'] = $vehicle;
        $updated['poiSequence'] = is_array($poiSequence) ? $poiSequence : [];
        $updated['geometry'] = $geometry;
        $updated['encodedPolyline'] = $encodedPolyline;
        $updated['updatedAt'] = gmdate('c');

        $this->repo->replace($id, $updated);
        return ResponseHelper::json($response, $updated, 200);
    }

    // PATCH /routes/{id} -> RoutePatchRequest -> 200 Route
    public function patch(Request $request, Response $response, array $args): Response
    {
        $id = (string)($args['id'] ?? '');
        $existing = $this->repo->find($id);
        if ($existing === null) {
            return $this->error($response, 'not_found', 'Route not found', ['id' => $id], 404);
        }

        $payload = $this->readJson($request);
        if ($payload === null) {
            return $this->error($response, 'invalid_request', 'Invalid JSON body.', null, 400);
        }

        $updated = $existing;

        if (array_key_exists('name', $payload)) {
            $name = trim((string)$payload['name']);
            if ($name === '') return $this->error($response, 'invalid_request', 'name cannot be empty.', null, 400);
            $updated['name'] = $name;
        }

        if (array_key_exists('public', $payload)) {
            if (!is_bool($payload['public'])) return $this->error($response, 'invalid_request', 'public must be boolean.', null, 400);
            $updated['public'] = $payload['public'];
        }

        if (array_key_exists('vehicle', $payload)) {
            if ($payload['vehicle'] !== null && !is_string($payload['vehicle'])) {
                return $this->error($response, 'invalid_request', 'vehicle must be string or null.', null, 400);
            }
            $updated['vehicle'] = $payload['vehicle'];
        }

        if (array_key_exists('poiSequence', $payload)) {
            if (!is_array($payload['poiSequence'])) return $this->error($response, 'invalid_request', 'poiSequence must be array.', null, 400);
            $updated['poiSequence'] = $payload['poiSequence'];
        }

        if (array_key_exists('geometry', $payload)) {
            if (!$this->validateLineString($payload['geometry'])) {
                return $this->error($response, 'invalid_request', 'geometry must be a GeoJSON LineString.', null, 400);
            }
            $updated['geometry'] = $payload['geometry'];
        }

        if (array_key_exists('encodedPolyline', $payload)) {
            if ($payload['encodedPolyline'] !== null && !is_string($payload['encodedPolyline'])) {
                return $this->error($response, 'invalid_request', 'encodedPolyline must be string or null.', null, 400);
            }
            $updated['encodedPolyline'] = $payload['encodedPolyline'];
        }

        $updated['updatedAt'] = gmdate('c');

        $this->repo->replace($id, $updated);
        return ResponseHelper::json($response, $updated, 200);
    }

    // DELETE /routes/{id} (optional)
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (string)($args['id'] ?? '');
        $ok = $this->repo->delete($id);
        if (!$ok) {
            return $this->error($response, 'not_found', 'Route not found', ['id' => $id], 404);
        }
        // 204 No Content
        return $response->withStatus(204);
    }
}
