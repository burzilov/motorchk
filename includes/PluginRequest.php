<?php

/**
 * Запрос к маршруту плагина. Путь — относительно /admin-panel/plugins/{id} или /{prefix}.
 */
final class PluginRequest
{
    private ?string $rawBody = null;

    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $params = [],
        private array $query = [],
        private array $post = [],
        private array $files = [],
        private array $server = [],
        ?string $rawBody = null,
    ) {
        $this->rawBody = $rawBody;
    }

    /**
     * @param array<string, string> $params
     */
    public static function fromGlobals(string $method, string $path, array $params): self
    {
        return new self($method, $path, $params, $_GET, $_POST, $_FILES, $_SERVER);
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    public function query(string $name, mixed $default = null): mixed
    {
        return $this->query[$name] ?? $default;
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $this->post[$name] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allInput(): array
    {
        return $this->post;
    }

    /** Загруженный файл ($_FILES[$name]) или null. */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;

        return is_array($file) ? $file : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (strcasecmp($name, 'Content-Type') === 0) {
            return $this->server['CONTENT_TYPE'] ?? $this->server[$key] ?? null;
        }

        return $this->server[$key] ?? null;
    }

    public function body(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = (string) file_get_contents('php://input');
        }

        return $this->rawBody;
    }

    /**
     * Тело запроса как JSON-объект/массив; null — тело пустое или не JSON.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $data = json_decode($this->body(), true);

        return is_array($data) ? $data : null;
    }

    public function wantsJson(): bool
    {
        return str_contains(strtolower((string) $this->header('Accept')), 'application/json')
            || str_contains(strtolower((string) $this->header('Content-Type')), 'application/json');
    }

    public function csrfToken(): ?string
    {
        $token = $this->header('X-CSRF-Token') ?? $this->post['_csrf'] ?? null;

        return is_string($token) ? $token : null;
    }
}
