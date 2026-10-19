<?php

/**
 * API, которое ядро передаёт плагину. Точка входа плагина получает этот объект:
 *
 *     return static function (PluginContext $plugin): void {
 *         $plugin->adminRoute('GET', '/', fn(PluginRequest $r, PluginContext $p) => $p->render('admin'));
 *     };
 */
final class PluginContext
{
    /** @var list<array{area: string, method: string, regex: string, names: list<string>, pathParams: list<string>, handler: callable, options: array<string, mixed>}> */
    private array $routes = [];

    /** @var list<callable> */
    private array $installHooks = [];

    private ?Closure $adminRenderer = null;

    private ?Closure $publicRenderer = null;

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $info результат PluginManager::discover() для плагина
     */
    public function __construct(
        private array $config,
        private array $info,
        private PluginManager $manager,
    ) {
    }

    // ---------- сведения о плагине и сайте ----------

    public function id(): string
    {
        return (string) $this->info['id'];
    }

    public function name(): string
    {
        return (string) $this->info['name'];
    }

    public function version(): string
    {
        return (string) $this->info['version'];
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return (array) ($this->info['manifest'] ?? []);
    }

    /** Абсолютный путь к папке плагина (код, только чтение). */
    public function dir(): string
    {
        return (string) $this->info['dir'];
    }

    /** Абсолютный путь к данным плагина: plugin-data/{id}/ (создаётся ядром). */
    public function dataDir(): string
    {
        return (string) $this->info['data_dir'];
    }

    public function prefix(): ?string
    {
        return $this->info['prefix'] ?? null;
    }

    public function coreVersion(): string
    {
        return Version::current();
    }

    /** Значение конфига сайта (без аргумента — весь массив). */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    /** Путь из конфига: site_path, content_path, themes_path, cache_path, plugins_path, … */
    public function path(string $key): string
    {
        return rtrim((string) ($this->config[$key] ?? ''), '/');
    }

    public function isAdmin(): bool
    {
        return !empty($_SESSION['admin_authenticated']);
    }

    // ---------- URL ----------

    public function adminUrl(string $path = '/'): string
    {
        return '/admin-panel/plugins/' . $this->id() . self::joinPath($path);
    }

    public function publicUrl(string $path = '/'): string
    {
        $prefix = $this->prefix();
        if ($prefix === null) {
            throw new LogicException('У плагина нет public.prefix в plugin.json');
        }

        return '/' . $prefix . self::joinPath($path);
    }

    /** URL статики из assets/ плагина с ?v={версия} для кеширования. */
    public function assetUrl(string $path): string
    {
        $path = ltrim($path, '/');
        if (!PluginManager::isSafeRelativePath($path)) {
            throw new InvalidArgumentException('Некорректный путь статики: ' . $path);
        }

        return '/plugin-assets/' . $this->id() . '/' . $path . '?v=' . rawurlencode($this->version());
    }

    // ---------- регистрация ----------

    /**
     * Маршрут админки: /admin-panel/plugins/{id}{pattern}. Только после входа; CSRF на POST/PUT/PATCH/DELETE
     * проверяет ядро (поле _csrf или заголовок X-CSRF-Token).
     *
     * Шаблон: "/", "/items/{id}", "/files/{path*}" ({name} — [A-Za-z0-9_-]+, {name*} — путь).
     * Обработчик: function (PluginRequest $request, PluginContext $plugin): void
     */
    public function adminRoute(string $method, string $pattern, callable $handler): void
    {
        $this->addRoute('admin', $method, $pattern, $handler, []);
    }

    /**
     * Публичный маршрут: /{prefix}{pattern}. Требует public.prefix в plugin.json.
     * CSRF на небезопасных методах проверяется по умолчанию; отключить: ['csrf' => false].
     *
     * @param array{csrf?: bool} $options
     */
    public function publicRoute(string $method, string $pattern, callable $handler, array $options = []): void
    {
        if ($this->prefix() === null) {
            throw new LogicException('publicRoute требует public.prefix в plugin.json');
        }
        $this->addRoute('public', $method, $pattern, $handler, $options);
    }

    /**
     * Хук установки/обновления: function (?string $from, string $to, PluginContext $plugin): void.
     * Вызывается один раз при первой загрузке новой версии (from = null — первая установка).
     * Должен быть идемпотентным. Установленная версия хранится в plugin-data/{id}/.plugin-state.json.
     */
    public function onInstall(callable $hook): void
    {
        $this->installHooks[] = $hook;
    }

    /** @internal */
    public function installHooks(): array
    {
        return $this->installHooks;
    }

    /**
     * @internal
     * @return array{handler: callable, params: array<string, string>, options: array<string, mixed>}|array{allowed: list<string>}|null
     */
    public function matchRoute(string $area, string $method, string $path): ?array
    {
        $path = self::normalizeRoutePath($path);
        $allowed = [];
        foreach ($this->routes as $route) {
            if ($route['area'] !== $area || !preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($route['names'] as $i => $name) {
                $value = rawurldecode($m[$i + 1]);
                $isPath = in_array($name, $route['pathParams'], true);
                if (str_contains($value, "\0") || ($isPath ? !PluginManager::isSafeRelativePath($value) : (str_contains($value, '/') || str_contains($value, '..')))) {
                    return null;
                }
                $params[$name] = $value;
            }

            return ['handler' => $route['handler'], 'params' => $params, 'options' => $route['options']];
        }

        return $allowed !== [] ? ['allowed' => array_values(array_unique($allowed))] : null;
    }

    /** @internal */
    public function setAdminRenderer(Closure $renderer): void
    {
        $this->adminRenderer = $renderer;
    }

    /** @internal */
    public function setPublicRenderer(Closure $renderer): void
    {
        $this->publicRenderer = $renderer;
    }

    // ---------- ответы ----------

    /**
     * Шаблон плагина (templates/{name}.php) внутри layout админки.
     *
     * @param array<string, mixed> $data переменные шаблона; $plugin доступен всегда
     * @param array{title?: string, fullscreen?: bool, styles?: list<string>, scripts?: list<string>, status?: int} $options
     *        fullscreen — редактор на всю ширину/высоту окна под шапкой админки;
     *        styles/scripts — URL (обычно $plugin->assetUrl(...)) для <head>
     */
    public function render(string $template, array $data = [], array $options = []): void
    {
        if ($this->adminRenderer === null) {
            throw new LogicException('render() доступен только в маршрутах админки');
        }
        $html = $this->partial($template, $data);
        ($this->adminRenderer)($html, $options);
    }

    /**
     * Шаблон плагина внутри шаблона активной темы (шапка, меню, подвал).
     * HTML попадает в $blocks['content'] шаблона темы.
     *
     * @param array{title?: string, description?: string, template?: string, scripts?: list<string>, hero?: string, menu_location?: string, status?: int, vars?: array<string, mixed>} $options
     *        template — шаблон темы (по умолчанию default); scripts — /plugin-assets/{id}/...js;
     *        vars — доступны шаблону темы как $pluginVars
     */
    public function renderPublic(string $template, array $data = [], array $options = []): void
    {
        if ($this->publicRenderer === null) {
            throw new LogicException('renderPublic() доступен только в публичных маршрутах');
        }
        $html = $this->partial($template, $data);
        ($this->publicRenderer)($html, $options);
    }

    /** Отрендерить templates/{name}.php плагина в строку. */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->templatePath($template);
        $__data = $data + ['plugin' => $this];

        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;

                return (string) ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $__data);
    }

    public function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function jsonError(string $message, int $status = 400, array $extra = []): void
    {
        $this->json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    /** Ответ как есть (без layout). */
    public function raw(string $body, string $contentType = 'text/plain; charset=UTF-8', int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        echo $body;
    }

    public function redirect(string $url, int $status = 302): void
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            throw new InvalidArgumentException('Разрешены только относительные редиректы внутри сайта');
        }
        http_response_code($status);
        header('Location: ' . $url);
    }

    public function notFound(string $message = 'Не найдено'): void
    {
        $this->raw($message, 'text/plain; charset=UTF-8', 404);
    }

    /**
     * Отдать файл из папки плагина или из его plugin-data (nosniff, ETag, CSP для SVG).
     *
     * @param array{cache?: string, download?: string, type?: string} $options
     */
    public function sendFile(string $absolutePath, array $options = []): int
    {
        $real = realpath($absolutePath);
        $roots = array_filter([realpath($this->dir()), realpath($this->dataDir())]);
        $inside = false;
        foreach ($roots as $root) {
            if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                $inside = true;
            }
        }
        if (!$inside || !is_file((string) $real)) {
            $this->notFound();

            return 404;
        }

        $ext = strtolower(pathinfo((string) $real, PATHINFO_EXTENSION));
        $type = $options['type'] ?? (PluginManager::ASSET_TYPES[$ext] ?? ($ext === 'json' ? 'application/json; charset=UTF-8' : 'application/octet-stream'));

        return PluginManager::sendFile((string) $real, $type, $options);
    }

    // ---------- CSRF ----------

    public function csrfToken(): string
    {
        return Csrf::token();
    }

    public function csrfField(): string
    {
        return Csrf::field();
    }

    public function csrfValid(PluginRequest $request): bool
    {
        return Csrf::validate($request->csrfToken());
    }

    // ---------- данные плагина (plugin-data/{id}/) ----------

    /** Абсолютный путь внутри dataDir(); относительный путь без "..", без скрытых сегментов. */
    public function dataPath(string $relative): string
    {
        $relative = ltrim($relative, '/');
        if (!PluginManager::isSafeRelativePath($relative)) {
            throw new InvalidArgumentException('Некорректный путь данных: ' . $relative);
        }

        return $this->dataDir() . '/' . $relative;
    }

    /** Атомарная по блокировке запись (FileWriter, flock); папки создаются. */
    public function writeFile(string $relative, string $content): void
    {
        FileWriter::write($this->dataPath($relative), $content);
    }

    public function readFile(string $relative): ?string
    {
        $path = $this->dataPath($relative);
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    public function writeJson(string $relative, mixed $data): void
    {
        $this->writeFile($relative, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    public function readJson(string $relative, mixed $default = null): mixed
    {
        $raw = $this->readFile($relative);
        if ($raw === null) {
            return $default;
        }
        $data = json_decode($raw, true);

        return $data === null && trim($raw) !== 'null' ? $default : $data;
    }

    public function deleteFile(string $relative): bool
    {
        $path = $this->dataPath($relative);

        return is_file($path) && unlink($path);
    }

    // ---------- helpers ----------

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function addRoute(string $area, string $method, string $pattern, callable $handler, array $options): void
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('Метод не поддерживается: ' . $method);
        }
        $pattern = self::normalizeRoutePath($pattern);
        $names = [];
        $pathParams = [];
        $regex = preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)(\\\\\*)?\\\\\}/',
            static function (array $m) use (&$names, &$pathParams): string {
                $names[] = $m[1];
                if (!empty($m[2])) {
                    $pathParams[] = $m[1];
                }

                return !empty($m[2]) ? '([A-Za-z0-9_.\/%-]+)' : '([A-Za-z0-9_%.-]+)';
            },
            preg_quote($pattern, '#')
        );
        $this->routes[] = [
            'area' => $area,
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'names' => $names,
            'pathParams' => $pathParams,
            'handler' => $handler,
            'options' => $options,
        ];
    }

    private static function normalizeRoutePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path;
    }

    private static function joinPath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : '/' . $path;
    }

    private function templatePath(string $template): string
    {
        $template = preg_replace('/\.php$/', '', trim($template, '/')) ?? '';
        if ($template === '' || !PluginManager::isSafeRelativePath($template . '.php')) {
            throw new InvalidArgumentException('Некорректное имя шаблона: ' . $template);
        }
        $base = realpath($this->dir() . '/templates');
        $file = $base !== false ? realpath($base . '/' . $template . '.php') : false;
        if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Шаблон плагина не найден: templates/' . $template . '.php');
        }

        return $file;
    }
}
