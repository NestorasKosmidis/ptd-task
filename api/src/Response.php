<?php
namespace App;

use Psr\Http\Message\ResponseInterface as Response;

final class ResponseHelper
{
    public static function json(Response $response, array $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{"code":"server_error","message":"JSON encoding failed","details":null}';
            $status = 500;
        }

        $response->getBody()->write($payload);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
