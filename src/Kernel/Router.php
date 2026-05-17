<?php

namespace App\Kernel;

class Router {
    private array $routes = [];

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $queryParams,
        private readonly array $bodyParams
    ){}

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function handleRequest(): void
    {
        $handler = $this->routes[$this->method][$this->path] ?? null;

        $request = array_merge($this->queryParams, $this->bodyParams);

        if ($handler) {
            call_user_func($handler, $request);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Not Found']);
        }
    }

}
