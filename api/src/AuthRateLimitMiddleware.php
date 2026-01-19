<?php
namespace App;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class AuthRateLimitMiddleware
{
    private string $usersFile = '/var/www/data/users.json';
    private string $rateFile  = '/var/www/data/ratelimit.json';

    public function __invoke(Request $request, $handler): Response
    {
        // Allow CORS preflight requests
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $handler->handle($request);
        }
        // 1) Read API key
        $apiKey = $request->getHeaderLine('X-API-Key');
        if ($apiKey === '') {
            return ResponseHelper::json(new \Slim\Psr7\Response(), [
                'code' => 'unauthorized',
                'message' => 'Missing X-API-Key header.',
                'details' => null
            ], 401);
        }

        $users = $this->loadUsers();
        $user = null;
        foreach ($users as $u) {
            if (($u['apiKey'] ?? null) === $apiKey) {
                $user = $u;
                break;
            }
        }

        if ($user === null) {
            return ResponseHelper::json(new \Slim\Psr7\Response(), [
                'code' => 'unauthorized',
                'message' => 'Invalid API key.',
                'details' => null
            ], 401);
        }

        $userId = (string)$user['userId'];

        // 2) Rate limit
        $limitPerMinute = (int)($user['rate']['limitPerMinute'] ?? 60);
        $blockMinutes   = (int)($user['rate']['blockMinutes'] ?? 3);

        $now = time();
        $bucket = (int)floor($now / 60);

        $state = $this->loadRateState();

        if (!isset($state[$userId])) {
            $state[$userId] = [
                'bucket' => $bucket,
                'count' => 0,
                'blockedUntil' => 0
            ];
        }

        // If blocked
        if ((int)$state[$userId]['blockedUntil'] > $now) {
            $retry = (int)$state[$userId]['blockedUntil'] - $now;
            $resp = ResponseHelper::json(new \Slim\Psr7\Response(), [
                'code' => 'rate_limited',
                'message' => 'Rate limit exceeded. Try later.',
                'details' => ['retryAfterSeconds' => $retry]
            ], 429);
            return $resp->withHeader('Retry-After', (string)$retry);
        }

        // New minute bucket
        if ((int)$state[$userId]['bucket'] !== $bucket) {
            $state[$userId]['bucket'] = $bucket;
            $state[$userId]['count'] = 0;
        }

        $state[$userId]['count'] = (int)$state[$userId]['count'] + 1;

        if ((int)$state[$userId]['count'] > $limitPerMinute) {
            $state[$userId]['blockedUntil'] = $now + ($blockMinutes * 60);
            $this->saveRateState($state);

            $resp = ResponseHelper::json(new \Slim\Psr7\Response(), [
                'code' => 'rate_limited',
                'message' => 'Rate limit exceeded. Blocked temporarily.',
                'details' => ['blockedForMinutes' => $blockMinutes]
            ], 429);

            return $resp->withHeader('Retry-After', (string)($blockMinutes * 60));
        }

        $this->saveRateState($state);

        // 3) Attach auth context to request for later use (ownerId default)
        $request = $request->withAttribute('authUser', [
            'userId' => $userId,
            'name' => (string)($user['name'] ?? $userId)
        ]);

        return $handler->handle($request);
    }

    private function loadUsers(): array
    {
        if (!file_exists($this->usersFile)) return [];
        $data = json_decode((string)file_get_contents($this->usersFile), true);
        return is_array($data) ? $data : [];
    }

    private function loadRateState(): array
    {
        if (!file_exists($this->rateFile)) return [];
        $data = json_decode((string)file_get_contents($this->rateFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveRateState(array $state): void
    {
        file_put_contents($this->rateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
