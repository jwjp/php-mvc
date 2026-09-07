<?php

namespace Controller;

use ReflectionMethod;
use Throwable;

abstract class RouterImpl
{
    protected array $environments;
    protected array $variables = [];
    protected mixed $result = null;
    protected ?Throwable $exception = null;

    private array $routes = [];
    private string $registeringMethod = '';
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(array $environments = [])
    {
        $this->environments = $environments;

        try {
            $this->variables = $this->parseRequestVariables();
            foreach (self::METHODS as $method) {
                $this->registeringMethod = $method;
                $this->{strtolower($method)}();
            }
            $this->registeringMethod = '';
            $this->middleware()->guard()->interceptor('before')->pipe();
            $this->dispatch();
            $this->interceptor('after');
        } catch (Throwable $exception) {
            $this->exception = $exception;
        }

        $this->filter();
        if ($this->exception !== null) {
            throw $this->exception;
        }
        $this->end();
    }

    private function parseRequestVariables(): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $_GET;
        }
        if (!in_array($method, self::METHODS, true)) {
            return [];
        }

        $maxBytes = (int) ($this->environments['APP']['MAX_BODY_BYTES'] ?? 1048576);
        if ($maxBytes < 1) {
            throw new \LogicException('APP.MAX_BODY_BYTES must be positive.');
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
            throw new HttpException('요청 본문이 너무 큽니다.', 413);
        }

        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'multipart/form-data') {
            if ($method !== 'POST') {
                throw new HttpException('multipart 요청에는 POST와 _method를 사용하세요.', 415);
            }
            $data = $_POST;
        } else {
            $rawBody = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
            if ($rawBody === false) {
                throw new \RuntimeException('Unable to read the request body.');
            }
            if (strlen($rawBody) > $maxBytes) {
                throw new HttpException('요청 본문이 너무 큽니다.', 413);
            }
            $data = [];
            if ($rawBody !== '') {
                if ($contentType === 'application/json') {
                    try {
                        $object = json_decode($rawBody, false, 64, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new HttpException('JSON 요청 본문이 올바르지 않습니다.');
                    }
                    if (!$object instanceof \stdClass) {
                        throw new HttpException('JSON 요청 본문은 객체여야 합니다.');
                    }
                    $data = (array) $object;
                } elseif ($contentType === 'application/x-www-form-urlencoded') {
                    parse_str($rawBody, $data);
                } else {
                    throw new HttpException('JSON 또는 form 형식의 요청 본문을 사용하세요.', 415);
                }
            }
        }

        if ($method === 'POST' && array_key_exists('_method', $data)) {
            if (!is_string($data['_method']) || !in_array(strtoupper($data['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
                throw new HttpException('_method는 PUT, PATCH, DELETE 중 하나여야 합니다.');
            }
            $_SERVER['REQUEST_METHOD'] = strtoupper($data['_method']);
        }
        unset($data['_method']);
        return $data;
    }

    protected function middleware(): static { return $this; }
    protected function guard(): static { return $this; }
    protected function interceptor($when): static { return $this; }
    protected function pipe(): static { return $this; }
    protected function filter(): static { return $this; }

    // These methods declare routes; request processing belongs in lifecycle hooks.
    protected function get(): static { return $this; }
    protected function post(): static { return $this; }
    protected function put(): static { return $this; }
    protected function patch(): static { return $this; }
    protected function delete(): static { return $this; }

    protected function execute(string $resource, string $class, string $function): static
    {
        if ($this->registeringMethod === '') {
            throw new \LogicException('Declare routes in get(), post(), put(), patch() or delete().');
        }
        $this->routes[] = [$this->registeringMethod, $this->normalizePath($resource), $class, $function];
        return $this;
    }

    private function dispatch(): void
    {
        $path = $this->normalizePath(explode('?', $_SERVER['REQUEST_URI'] ?? '/')[0]);
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = $requestMethod === 'HEAD' ? 'GET' : $requestMethod;
        $allowed = [];
        $match = null;

        foreach ($this->routes as [$routeMethod, $resource, $class, $function]) {
            $parameters = $this->matchRoute($resource, $path);
            if ($parameters === null) {
                continue;
            }
            $allowed[] = $routeMethod;
            if ($routeMethod === 'GET') {
                $allowed[] = 'HEAD';
            }
            if ($match === null && $routeMethod === $method) {
                $match = [$class, $function, $parameters];
            }
        }

        if ($allowed === []) {
            throw new HttpException('요청한 경로를 찾을 수 없습니다.', 404);
        }
        if ($requestMethod === 'OPTIONS' || $match === null) {
            header('Allow: ' . implode(', ', array_unique([...$allowed, 'OPTIONS'])));
            if ($requestMethod === 'OPTIONS') {
                $this->result = Response::html('', 204);
                return;
            }
            throw new HttpException('이 경로에서 지원하지 않는 HTTP 메서드입니다.', 405);
        }

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $this->variables['_csrf'] ?? null);
        }
        unset($this->variables['_csrf']);

        [$class, $function, $parameters] = $match;
        if (!is_subclass_of($class, Controller::class) || str_starts_with($function, '__') || !method_exists($class, $function)) {
            throw new \LogicException('Route must reference a Controller action.');
        }
        $action = new ReflectionMethod($class, $function);
        if (!$action->isPublic() || $action->isStatic() || $action->getNumberOfRequiredParameters() !== 0) {
            throw new \LogicException('Route action must be a public instance method without required arguments.');
        }
        $this->result = (new $class($this->environments, $this->variables, $parameters))->$function();
    }

    private function matchRoute(string $resource, string $path): ?array
    {
        $route = explode('/', trim($resource, '/'));
        $request = explode('/', trim($path, '/'));
        if (count($route) !== count($request)) {
            return null;
        }
        $parameters = [];
        foreach ($route as $index => $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)}$/', $segment, $matches)) {
                $value = rawurldecode($request[$index]);
                if ($value === '' || preg_match('#[\x00-\x1f\x7f/\\\\]#', $value)) {
                    throw new HttpException('경로 파라미터가 올바르지 않습니다.');
                }
                $parameters[$matches[1]] = $value;
            } elseif ($segment !== $request[$index]) {
                return null;
            }
        }
        return $parameters;
    }

    private function normalizePath(string $path): string
    {
        return '/' . trim(preg_replace('#/+#', '/', $path), '/');
    }

    protected function end(): void
    {
        ($this->result instanceof Response ? $this->result : Response::json($this->result))->send();
    }
}
