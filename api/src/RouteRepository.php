<?php
namespace App;

final class RouteRepository
{
    private string $file = '/var/www/data/routes.json';

    public function all(): array
    {
        if (!file_exists($this->file)) return [];
        $data = json_decode((string)file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }

    public function saveAll(array $routes): void
    {
        file_put_contents($this->file, json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $r) {
            if (($r['id'] ?? null) === $id) return $r;
        }
        return null;
    }

    public function insert(array $route): array
    {
        $routes = $this->all();
        $routes[] = $route;
        $this->saveAll($routes);
        return $route;
    }

    public function replace(string $id, array $route): ?array
    {
        $routes = $this->all();
        foreach ($routes as $i => $r) {
            if (($r['id'] ?? null) === $id) {
                $routes[$i] = $route;
                $this->saveAll($routes);
                return $route;
            }
        }
        return null;
    }

    public function delete(string $id): bool
    {
        $routes = $this->all();
        $before = count($routes);
        $routes = array_values(array_filter($routes, fn($r) => (($r['id'] ?? null) !== $id)));
        if (count($routes) === $before) return false;
        $this->saveAll($routes);
        return true;
    }
}
