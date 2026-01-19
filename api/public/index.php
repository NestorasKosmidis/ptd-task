<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use App\AboutController;
use App\PoiController;
use App\RouteComputeController;
use App\RoutesController;
use App\AuthRateLimitMiddleware;

$app = AppFactory::create();


$app->addRoutingMiddleware();


$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

$app->add(new AuthRateLimitMiddleware());

$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);

    $origin = $request->getHeaderLine('Origin');
    $allowed = ['http://localhost:8081', 'http://localhost:8082'];

    if (in_array($origin, $allowed, true)) {
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
    } else {
        $response = $response->withHeader('Access-Control-Allow-Origin', 'http://localhost:8081');
    }

    return $response
        ->withHeader('Vary', 'Origin')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
});

// About
$app->get('/about', new AboutController());

// POIs
$poiController = new PoiController();
$app->get('/pois', [$poiController, 'list']);
$app->get('/pois/{id}', [$poiController, 'getById']);

// Compute route (GraphHopper)
$app->post('/routes/compute', new RouteComputeController());

// Persisted routes
$routesController = new RoutesController();
$app->post('/routes', [$routesController, 'create']);
$app->get('/routes', [$routesController, 'list']);
$app->get('/routes/{id}', [$routesController, 'get']);
$app->put('/routes/{id}', [$routesController, 'put']);
$app->patch('/routes/{id}', [$routesController, 'patch']);
$app->delete('/routes/{id}', [$routesController, 'delete']);

$app->run();
