<?php

/**
 * Плагины сайта: public_html/plugins/{id}/plugin.json.
 *
 * Плагин в папке = включён. Невалидные и несовместимые плагины не загружаются;
 * причина видна на странице «Система». Ядро не выполняет код плагина, пока
 * к нему не пришёл запрос (админка /admin-panel/plugins/{id}/..., публичный
 * префикс /{prefix}/...) или пока не открыта «Система».
 */
class PluginManager
{
    public const ID_PATTERN = '/^[a-z0-9-]+$/';

    /** Первые сегменты URL, которые плагин не может занять. */
    public const RESERVED_PREFIXES = [
        'admin-panel', 'api', 'core', 'themes', 'content', 'config', 'cache',
        'submissions', 'plugins', 'plugin-data', 'plugin-assets', 'index.php',
    ];

    /** Разрешённые типы статики плагина (только из папки assets/ плагина). */
    public const ASSET_TYPES = [
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'wasm' => 'application/wasm',
        'txt' => 'text/plain; charset=UTF-8',
    ];

    public const STATE_FILE = '.plugin-state.json';

    private const DENY_HTACCESS = "# motorchk: служебная папка — прямой HTTP-доступ запрещён.\n"
        . "# Статика плагинов отдаётся ядром по /plugin-assets/{id}/...\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

    /** @var array<string, array<string, mixed>>|null */
    private ?array $plugins = null;

    /** @var array<string, PluginContext> */
    private array $booted = [];

    /** @var array<string, bool> */
    private array $autoloaded = [];

    public function __construct(private array $config)
    {
    }

    public function pluginsPath(): string
    {
        $path = (string) ($this->config['plugins_path'] ?? '');
        if ($path === '') {
            $path = rtrim((string) ($this->config['site_path'] ?? ''), '/') . '/plugins';
        }

        return rtrim($path, '/');
    }

    public function dataRoot(): string
    {
        $path = (string) ($this->config['plugin_data_path'] ?? '');
        if ($path === '') {
            $path = rtrim((string) ($this->config['site_path'] ?? ''), '/') . '/plugin-data';
        }

        return rtrim($path, '/');
    }

    /** Есть ли на сайте папка plugins/. Без неё ядро ведёт себя как до появления плагинов. */
    public function hasPluginsDir(): bool
    {
        return is_dir($this->pluginsPath());
    }

    /**
     * Все папки в plugins/ с результатом проверки (ключ — имя папки).
     *
     * @return array<string, array<string, mixed>>
     */
    public function discover(): array
    {
        if ($this->plugins !== null) {
            return $this->plugins;
        }

        $this->plugins = [];
        $root = $this->pluginsPath();
        if (!is_dir($root)) {
            return $this->plugins;
        }

        self::ensureDenyFile($root);

        $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs, SORT_STRING);
        foreach ($dirs as $dir) {
            $folder = basename($dir);
            $this->plugins[$folder] = $this->inspect($folder, $dir);
        }

        $this->detectConflicts();

        return $this->plugins;
    }

    /**
     * Валидные плагины (status = ok).
     *
     * @return array<string, array<string, mixed>>
     */
    public function enabled(): array
    {
        return array_filter($this->discover(), static fn(array $p): bool => $p['status'] === 'ok');
    }

    /**
     * Пункты меню админки от валидных плагинов.
     *
     * @return list<array{id: string, label: string, url: string}>
     */
    public function adminMenu(): array
    {
        $items = [];
        foreach ($this->enabled() as $id => $plugin) {
            if (!empty($plugin['menu'])) {
                $items[] = [
                    'id' => $id,
                    'label' => (string) $plugin['menu'],
                    'url' => '/admin-panel/plugins/' . $id,
                ];
            }
        }

        return $items;
    }

    /** id плагина, занявшего первый сегмент URL, или null. */
    public function prefixOwner(string $segment): ?string
    {
        if ($segment === '' || !preg_match(self::ID_PATTERN, $segment)) {
            return null;
        }
        foreach ($this->enabled() as $id => $plugin) {
            if (($plugin['prefix'] ?? null) === $segment) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Состояние защитных .htaccess в plugins/ и plugin-data/.
     *
     * @return array{plugins: ?bool, data: ?bool}
     */
    public function protectionStatus(): array
    {
        $plugins = $this->pluginsPath();
        $data = $this->dataRoot();

        return [
            'plugins' => is_dir($plugins) ? is_file($plugins . '/.htaccess') : null,
            'data' => is_dir($data) ? is_file($data . '/.htaccess') : null,
        ];
    }

    /** Загрузить плагин (точка входа + install/upgrade). null — плагин недоступен. */
    public function boot(string $id): ?PluginContext
    {
        if (isset($this->booted[$id])) {
            return $this->booted[$id];
        }

        $info = $this->discover()[$id] ?? null;
        if ($info === null || $info['status'] !== 'ok') {
            return null;
        }

        $context = new PluginContext($this->config, $info, $this);
        try {
            $this->registerAutoload($info);
            $entry = (string) $info['entry_path'];
            $register = (static function (string $__file) {
                return require $__file;
            })($entry);

            if ($register instanceof Closure) {
                $register($context);
            } elseif (is_object($register) && method_exists($register, 'register')) {
                $register->register($context);
            } else {
                throw new RuntimeException('Точка входа должна вернуть function (PluginContext $plugin): void');
            }

            $this->runInstall($context, $info);
        } catch (Throwable $e) {
            $this->markFailed($id, 'Ошибка загрузки: ' . $e->getMessage());
            error_log('motorchk plugin ' . $id . ': ' . $e->getMessage());

            return null;
        }

        return $this->booted[$id] = $context;
    }

    /**
     * Загрузить все валидные плагины (страница «Система»: ошибки загрузки становятся видны).
     *
     * @return array<string, PluginContext>
     */
    public function bootAll(): array
    {
        foreach (array_keys($this->enabled()) as $id) {
            $this->boot($id);
        }

        return $this->booted;
    }

    /**
     * Выполнить маршрут плагина.
     *
     * @return int 0 — обработано; 403 — CSRF; 404 — нет маршрута; 405 — метод не поддерживается
     */
    public function handle(PluginContext $context, string $area, string $method, string $path, bool $enforceCsrf): int
    {
        $method = strtoupper($method);
        $match = $context->matchRoute($area, $method === 'HEAD' ? 'GET' : $method, $path);
        if ($match === null) {
            return 404;
        }
        if (isset($match['allowed'])) {
            header('Allow: ' . implode(', ', $match['allowed']));

            return 405;
        }

        $request = PluginRequest::fromGlobals($method, $path, $match['params']);

        $unsafe = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($enforceCsrf && $unsafe && ($match['options']['csrf'] ?? true) !== false) {
            if (!Csrf::validate($request->csrfToken())) {
                return 403;
            }
        }

        ($match['handler'])($request, $context);

        return 0;
    }

    /**
     * Найти файл статики: "{id}/{path}" → путь внутри plugins/{id}/assets/.
     *
     * @return array{path: string, type: string, plugin: string, version: string}|null
     */
    public function resolveAsset(string $rest): ?array
    {
        $segment = '[A-Za-z0-9_-][A-Za-z0-9_.-]*';
        if (!preg_match('#^([a-z0-9-]+)/((?:' . $segment . '/)*' . $segment . ')$#', $rest, $m)) {
            return null;
        }

        $plugin = $this->enabled()[$m[1]] ?? null;
        if ($plugin === null) {
            return null;
        }

        $ext = strtolower(pathinfo($m[2], PATHINFO_EXTENSION));
        if (!isset(self::ASSET_TYPES[$ext])) {
            return null;
        }

        $base = realpath($plugin['dir'] . '/assets');
        if ($base === false) {
            return null;
        }
        $file = realpath($base . '/' . $m[2]);
        if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }

        return [
            'path' => $file,
            'type' => self::ASSET_TYPES[$ext],
            'plugin' => $m[1],
            'version' => (string) $plugin['version'],
        ];
    }

    /** Отдать статику плагина. Возвращает HTTP-статус. */
    public function serveAsset(string $rest): int
    {
        $asset = $this->resolveAsset($rest);
        if ($asset === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Not found';

            return 404;
        }

        $immutable = isset($_GET['v']) && (string) $_GET['v'] === $asset['version'];

        return self::sendFile($asset['path'], $asset['type'], [
            'cache' => $immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
        ]);
    }

    /**
     * Отдать файл с безопасными заголовками (nosniff, CSP для SVG, ETag/304).
     *
     * @param array{cache?: string, download?: string} $options
     */
    public static function sendFile(string $path, string $contentType, array $options = []): int
    {
        $stat = @stat($path);
        if ($stat === false) {
            http_response_code(404);
            echo 'Not found';

            return 404;
        }

        $etag = '"' . dechex((int) $stat['mtime']) . '-' . dechex((int) $stat['size']) . '"';
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($options['cache'] ?? 'private, no-cache'));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) $stat['mtime']) . ' GMT');
        if (str_starts_with($contentType, 'image/svg')) {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        }
        if (!empty($options['download'])) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $options['download']);
            header('Content-Disposition: attachment; filename="' . $name . '"');
        }

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);

            return 304;
        }

        http_response_code(200);
        header('Content-Length: ' . (int) $stat['size']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            readfile($path);
        }

        return 200;
    }

    /**
     * Проверить ограничение версии ядра: ">=0.3.0 <0.4.0", "^0.3", "~0.3.1", "0.3.0", "*".
     * null — ограничение не разобрано.
     */
    public static function satisfies(string $version, string $constraint): ?bool
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return null;
        }
        if ($constraint === '*') {
            return true;
        }

        $constraint = preg_replace('/(>=|<=|!=|==|>|<|=|\^|~)\s+/', '$1', $constraint) ?? $constraint;
        $parts = preg_split('/[\s,]+/', $constraint, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return null;
        }

        $version = Version::normalizeTag($version);
        $ok = true;
        foreach ($parts as $part) {
            if (!preg_match('/^(\^|~|>=|<=|!=|==|>|<|=)?v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $part, $m)) {
                return null;
            }
            $op = $m[1] !== '' ? $m[1] : '=';
            $major = (int) $m[2];
            $minorGiven = isset($m[3]) && $m[3] !== '';
            $minor = $minorGiven ? (int) $m[3] : 0;
            $patch = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
            $base = $major . '.' . $minor . '.' . $patch;

            switch ($op) {
                case '^':
                    $upper = $major > 0 ? ($major + 1) . '.0.0' : ($minorGiven ? '0.' . ($minor + 1) . '.0' : '1.0.0');
                    $ok = $ok && version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
                    break;
                case '~':
                    $upper = $minorGiven ? $major . '.' . ($minor + 1) . '.0' : ($major + 1) . '.0.0';
                    $ok = $ok && version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
                    break;
                case '=':
                case '==':
                    $ok = $ok && version_compare($version, $base, '==');
                    break;
                default:
                    $ok = $ok && version_compare($version, $base, $op);
            }
        }

        return $ok;
    }

    /** Записать .htaccess с запретом доступа, если его нет. */
    public static function ensureDenyFile(string $dir): bool
    {
        $file = rtrim($dir, '/') . '/.htaccess';
        if (is_file($file)) {
            return true;
        }

        return @file_put_contents($file, self::DENY_HTACCESS) !== false;
    }

    /** Безопасный относительный путь: сегменты без точки в начале, без "..". */
    public static function isSafeRelativePath(string $path): bool
    {
        return (bool) preg_match('#^(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*$#', $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(string $folder, string $dir): array
    {
        $info = [
            'id' => $folder,
            'dir' => $dir,
            'name' => $folder,
            'version' => '',
            'core' => '',
            'description' => '',
            'entry' => '',
            'entry_path' => '',
            'menu' => null,
            'prefix' => null,
            'autoload' => [],
            'compatible' => null,
            'data_dir' => $this->dataRoot() . '/' . $folder,
            'data_writable' => false,
            'installed_version' => null,
            'status' => 'ok',
            'errors' => [],
            'warnings' => [],
        ];
        $info['data_writable'] = self::isWritablePath($info['data_dir']);
        $info['installed_version'] = self::readState($info['data_dir'] . '/' . self::STATE_FILE)['version'] ?? null;

        if (!preg_match(self::ID_PATTERN, $folder)) {
            return self::fail($info, 'Имя папки должно соответствовать ^[a-z0-9-]+$');
        }

        $manifestFile = $dir . '/plugin.json';
        if (!is_file($manifestFile)) {
            return self::fail($info, 'Нет plugin.json');
        }
        $raw = file_get_contents($manifestFile);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || array_is_list($manifest)) {
            return self::fail($info, 'plugin.json — некорректный JSON-объект');
        }
        $info['manifest'] = $manifest;

        $id = $manifest['id'] ?? null;
        if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
            return self::fail($info, 'id должен соответствовать ^[a-z0-9-]+$');
        }
        if ($id !== $folder) {
            return self::fail($info, 'id «' . $id . '» не совпадает с именем папки «' . $folder . '»');
        }

        $name = $manifest['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100) {
            return self::fail($info, 'name — обязательная строка до 100 символов');
        }
        $info['name'] = trim($name);

        $version = $manifest['version'] ?? null;
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            return self::fail($info, 'version должен быть в формате X.Y.Z');
        }
        $info['version'] = $version;

        if (isset($manifest['description']) && is_string($manifest['description'])) {
            $info['description'] = trim($manifest['description']);
        }

        $core = $manifest['core'] ?? null;
        if (!is_string($core) || trim($core) === '') {
            return self::fail($info, 'core — обязательное ограничение версии ядра (например "^0.3")');
        }
        $info['core'] = trim($core);
        $compatible = self::satisfies(Version::current(), $info['core']);
        if ($compatible === null) {
            return self::fail($info, 'Не удалось разобрать ограничение core «' . $info['core'] . '»');
        }
        $info['compatible'] = $compatible;

        $entry = $manifest['entry'] ?? 'plugin.php';
        if (!is_string($entry) || !self::isSafeRelativePath($entry) || !str_ends_with($entry, '.php')) {
            return self::fail($info, 'entry должен быть относительным путём к .php внутри плагина');
        }
        $entryPath = realpath($dir . '/' . $entry);
        $realDir = realpath($dir);
        if ($entryPath === false || $realDir === false || !str_starts_with($entryPath, $realDir . DIRECTORY_SEPARATOR) || !is_file($entryPath)) {
            return self::fail($info, 'Файл точки входа «' . $entry . '» не найден');
        }
        $info['entry'] = $entry;
        $info['entry_path'] = $entryPath;
        $info['dir'] = $realDir;

        if (isset($manifest['admin'])) {
            $menu = is_array($manifest['admin']) ? ($manifest['admin']['menu'] ?? null) : null;
            if (!is_array($manifest['admin']) || ($menu !== null && (!is_string($menu) || trim($menu) === '' || mb_strlen($menu) > 40))) {
                return self::fail($info, 'admin.menu — строка до 40 символов');
            }
            $info['menu'] = $menu !== null ? trim($menu) : null;
        }

        if (isset($manifest['public'])) {
            $prefix = is_array($manifest['public']) ? ($manifest['public']['prefix'] ?? null) : null;
            if (!is_string($prefix) || !preg_match(self::ID_PATTERN, $prefix)) {
                return self::fail($info, 'public.prefix должен соответствовать ^[a-z0-9-]+$');
            }
            if (in_array($prefix, self::RESERVED_PREFIXES, true)) {
                return self::fail($info, 'Префикс /' . $prefix . '/ зарезервирован ядром');
            }
            $info['prefix'] = $prefix;
        }

        if (isset($manifest['autoload'])) {
            if (!is_array($manifest['autoload']) || ($manifest['autoload'] !== [] && array_is_list($manifest['autoload']))) {
                return self::fail($info, 'autoload — объект {"Namespace\\\\": "lib/"}');
            }
            foreach ($manifest['autoload'] as $ns => $rel) {
                if (!is_string($ns) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\$/', $ns)) {
                    return self::fail($info, 'autoload: некорректный namespace «' . $ns . '» (должен заканчиваться на \\)');
                }
                $rel = is_string($rel) ? rtrim($rel, '/') : '';
                $path = $rel !== '' && self::isSafeRelativePath($rel) ? realpath($realDir . '/' . $rel) : false;
                if ($path === false || !is_dir($path) || !str_starts_with($path, $realDir . DIRECTORY_SEPARATOR)) {
                    return self::fail($info, 'autoload: папка «' . $rel . '» не найдена внутри плагина');
                }
                $info['autoload'][$ns] = $path;
            }
        }

        if (!$compatible) {
            return self::fail($info, 'Несовместим: требуется ядро ' . $info['core'] . ', установлено ' . Version::current());
        }

        return $info;
    }

    private function detectConflicts(): void
    {
        $owners = [];
        $contentPath = rtrim((string) ($this->config['content_path'] ?? ''), '/');
        foreach ($this->plugins as $id => $plugin) {
            if ($plugin['status'] !== 'ok' || $plugin['prefix'] === null) {
                continue;
            }
            $prefix = $plugin['prefix'];
            if (isset($owners[$prefix])) {
                $this->plugins[$id] = self::fail($plugin, 'Префикс /' . $prefix . '/ уже занят плагином «' . $owners[$prefix] . '»');
                $this->plugins[$owners[$prefix]]['warnings'][] = 'Префикс /' . $prefix . '/ также заявлен плагином «' . $id . '» (он отключён)';
                continue;
            }
            $owners[$prefix] = $id;
            if ($contentPath !== '' && (is_file($contentPath . '/' . $prefix . '.md') || is_dir($contentPath . '/' . $prefix))) {
                $this->plugins[$id]['warnings'][] = 'Страницы /' . $prefix . ' и /' . $prefix . '/... из content/ перекрыты плагином';
            }
        }
    }

    private function markFailed(string $id, string $reason): void
    {
        if (isset($this->plugins[$id])) {
            $this->plugins[$id] = self::fail($this->plugins[$id], $reason);
        }
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private static function fail(array $info, string $reason): array
    {
        $info['status'] = 'error';
        $info['errors'][] = $reason;

        return $info;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function registerAutoload(array $info): void
    {
        $id = (string) $info['id'];
        if (isset($this->autoloaded[$id]) || $info['autoload'] === []) {
            return;
        }
        $map = $info['autoload'];
        spl_autoload_register(static function (string $class) use ($map): void {
            foreach ($map as $ns => $dir) {
                if (!str_starts_with($class, $ns)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($ns)));
                if (!preg_match('#^[A-Za-z0-9_/]+$#', $relative)) {
                    return;
                }
                $file = $dir . '/' . $relative . '.php';
                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        });
        $this->autoloaded[$id] = true;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function runInstall(PluginContext $context, array $info): void
    {
        $dataDir = (string) $info['data_dir'];
        $root = $this->dataRoot();
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Не удалось создать ' . $root);
        }
        self::ensureDenyFile($root);
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new RuntimeException('Не удалось создать ' . $dataDir);
        }

        $stateFile = $dataDir . '/' . self::STATE_FILE;
        $to = (string) $info['version'];
        if ((self::readState($stateFile)['version'] ?? null) === $to) {
            return;
        }

        $lock = fopen($dataDir . '/.plugin-state.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Не удалось открыть lock-файл в ' . $dataDir);
        }
        try {
            flock($lock, LOCK_EX);
            $state = self::readState($stateFile);
            $from = isset($state['version']) && is_string($state['version']) ? $state['version'] : null;
            if ($from === $to) {
                return;
            }
            foreach ($context->installHooks() as $hook) {
                $hook($from, $to, $context);
            }
            FileWriter::write($stateFile, json_encode([
                'version' => $to,
                'previous_version' => $from,
                'installed_at' => $state['installed_at'] ?? date('c'),
                'updated_at' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (isset($this->plugins[$info['id']])) {
                $this->plugins[$info['id']]['installed_version'] = $to;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function readState(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    private static function isWritablePath(string $path): bool
    {
        $probe = $path;
        while (!is_dir($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return false;
            }
            $probe = $parent;
        }

        return is_writable($probe);
    }
}
