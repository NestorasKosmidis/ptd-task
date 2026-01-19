<?php
namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AboutController
{
    public function __invoke(Request $request, Response $response): Response
    {
        $data = [
            'team' => [
                [
                    'id' => 'P2018020',
                    'name' => 'NESTORAS KOSMIDIS',
                    'role' => null
                ],

            ]
        ];

        return ResponseHelper::json($response, $data, 200);
    }
}
