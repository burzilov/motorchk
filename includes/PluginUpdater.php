<?php

/**
 * Обновление плагинов из GitHub Releases — по той же схеме, что и ядро (Updater):
 * releases/latest → архив <id>-X.Y.Z.zip → проверка → резервная копия → атомарная замена папки → onInstall.
 *
 * Подписей нет: обновление — это чужой PHP-код, который выполнится на сервере. Указывайте в plugin.json
 * "repository" только для репозиториев, которым доверяете. Код из архива не выполняется до проверки,
 * новые плагины этим классом не устанавливаются (только обновление уже установленных).
 */
class PluginUpdater
{
    public const CACHE_FILE = 'plugin-updates.json';
    public const ALLOWED_HOSTS = [
        'api.github.com',
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];
    public const MAX_REDIRECTS = 5;
    public const MAX_API_BYTES = 2 * 1024 * 1024;
    public const MAX_ZIP_BYTES = 20 * 1024 * 1024;
    public const MAX_UNPACKED_BYTES = 64 * 1024 * 1024;
    public const MAX_FILES = 3000;
    public const KEEP_BACKUPS = 3;
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/';

    private PluginManager $manager;

    /** @var callable(string, array<int, string>, int, int): array{status: int, body: string, headers: array<string, string>} */
    private $fetcher;

    /** Проверка по сети в этом запросе уже упала — остальные плагины не ждём (только авто-проверка). */
    private bool $networkFailed = false;

    /**
     * @param callable|null $fetcher function (string $url, array $headers, int $maxBytes, int $timeout): array{status, body, headers}
     *                               — один HTTP GET без следования редиректам (редиректы обрабатывает этот класс). Для тестов.
     */
    public function __construct(private array $config, ?PluginManager $manager = null, ?callable $fetcher = null)
    {
        $this->manager = $manager ?? new PluginManager($config);
        $this->fetcher = $fetcher ?? [self::class, 'httpRequest'];
    }

    // ---------- проверка ----------

    /** "owner/repo" или https://github.com/owner/repo(.git) → "owner/repo"; null — некорректно. */
    public static function normalizeRepository(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('#^https://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#', $value, $m)) {
            $value = $m[1];
        }
        if (!preg_match('#^([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9._-]{1,100})$#', $value, $m)) {
            return null;
        }
        if ($m[2] === '.' || $m[2] === '..' || str_ends_with($m[2], '.git')) {
            return null;
        }

        return $m[1] . '/' . $m[2];
    }

    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        return in_array(strtolower((string) ($parts['host'] ?? '')), self::ALLOWED_HOSTS, true);
    }

    /**
     * Статус обновлений всех установленных плагинов (ключ — id). Плагины без "repository" — с repository = null.
     * $force = false: из кеша (TTL как у ядра, UPDATE_CHECK_TTL), по сети — только устаревшие записи.
     *
     * @return array<string, array<string, mixed>>
     */
    public function checkAll(bool $force = false): array
    {
        $result = [];
        foreach ($this->manager->discover() as $id => $info) {
            if (!isset($info['manifest'])) {
                continue;
            }
            $result[$id] = $this->status((string) $id, $force);
        }

        return $result;
    }

    /** Доступное обновление плагина или null. $force — без кеша, ошибка сети/релиза → исключение. */
    public function checkForUpdate(string $id, bool $force = false): ?array
    {
        $status = $this->status($id, $force);
        if ($force && $status['error'] !== null) {
            throw new RuntimeException($status['error']);
        }

        return $status['update'];
    }

    /**
     * @return array{id: string, current: string, repository: ?string, checked_at: ?int, update: ?array, error: ?string}
     */
    public function status(string $id, bool $force = false): array
    {
        $info = $this->manager->discover()[$id] ?? null;
        if ($info === null || !isset($info['manifest'])) {
            throw new RuntimeException('Плагин «' . $id . '» не установлен');
        }
        $current = (string) ($info['manifest']['version'] ?? '');
        $rawRepo = $info['manifest']['repository'] ?? null;
        $status = [
            'id' => $id,
            'current' => $current,
            'repository' => null,
            'checked_at' => null,
            'update' => null,
            'error' => null,
        ];
        if ($rawRepo === null || $rawRepo === '') {
            return $status;
        }
        $repo = self::normalizeRepository($rawRepo);
        if ($repo === null) {
            $status['error'] = 'Некорректное поле repository в plugin.json (нужно "owner/repo" на github.com)';

            return $status;
        }
        $status['repository'] = $repo;
        if (!preg_match(self::VERSION_PATTERN, $current)) {
            $status['error'] = 'Некорректная текущая версия плагина';

            return $status;
        }

        $cache = $this->readCache();
        $cached = $cache[$id] ?? null;
        if (!$force && is_array($cached)
            && ($cached['current'] ?? null) === $current
            && ($cached['repository'] ?? null) === $repo
            && ($cached['core'] ?? null) === Version::current()
            && (time() - (int) ($cached['checked_at'] ?? 0)) < self::ttl()
        ) {
            return array_merge($status, [
                'checked_at' => (int) $cached['checked_at'],
                'update' => is_array($cached['update'] ?? null) ? $cached['update'] : null,
                'error' => is_string($cached['error'] ?? null) ? $cached['error'] : null,
            ]);
        }

        if (!$force && $this->networkFailed) {
            $status['error'] = 'Проверка отложена: GitHub недоступен';

            return $status;
        }

        try {
            $status['update'] = $this->fetchUpdate($id, $repo, $current);
        } catch (Throwable $e) {
            $status['error'] = $e->getMessage();
            if ($e instanceof PluginUpdaterNetworkException) {
                $this->networkFailed = true;
            }
        }
        $status['checked_at'] = time();

        $cache = $this->readCache();
        $cache[$id] = [
            'checked_at' => $status['checked_at'],
            'current' => $current,
            'core' => Version::current(),
            'repository' => $repo,
            'update' => $status['update'],
            'error' => $status['error'],
        ];
        $this->writeCache($cache);

        return $status;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUpdate(string $id, string $repo, string $current): ?array
    {
        $response = $this->get('https://api.github.com/repos/' . $repo . '/releases/latest', ['Accept: application/vnd.github+json'], self::MAX_API_BYTES, 15);
        if ($response['status'] === 404) {
            throw new RuntimeException('В репозитории ' . $repo . ' нет опубликованных релизов');
        }
        if ($response['status'] !== 200) {
            throw new PluginUpdaterNetworkException('GitHub API ответил HTTP ' . $response['status'] . ($response['status'] === 403 ? ' (лимит запросов?)' : ''));
        }
        $release = json_decode($response['body'], true);
        if (!is_array($release) || !is_string($release['tag_name'] ?? null)) {
            throw new RuntimeException('GitHub вернул некорректный ответ о релизе');
        }

        $tag = $release['tag_name'];
        $latest = Version::normalizeTag($tag);
        if (!preg_match(self::VERSION_PATTERN, $latest)) {
            throw new RuntimeException('Тег последнего релиза «' . $tag . '» — не версия X.Y.Z');
        }
        if (Version::compare($latest, $current) <= 0) {
            return null;
        }

        $assetName = $id . '-' . $latest . '.zip';
        $zipUrl = self::findAsset($release, $assetName, $repo);
        $problem = $zipUrl === null ? 'В релизе нет архива ' . $assetName : null;

        // ограничение "core" новой версии — из plugin.json по тегу; окончательно проверяется в архиве при установке
        $core = null;
        $compatible = null;
        try {
            $manifest = $this->fetchManifest($repo, $tag);
            if ($manifest !== null) {
                if (($manifest['id'] ?? null) !== $id) {
                    $problem = 'plugin.json в релизе — другого плагина (id «' . (is_string($manifest['id'] ?? null) ? $manifest['id'] : '?') . '»)';
                } elseif (($manifest['version'] ?? null) !== $latest) {
                    $problem = 'Версия в plugin.json релиза не совпадает с тегом ' . $tag;
                }
                if (is_string($manifest['core'] ?? null) && trim($manifest['core']) !== '') {
                    $core = trim($manifest['core']);
                    $compatible = PluginManager::satisfies(Version::current(), $core);
                    if ($compatible === null) {
                        $problem ??= 'Не удалось разобрать ограничение core «' . $core . '»';
                    }
                }
            }
        } catch (Throwable) {
            // совместимость неизвестна — проверим по архиву
        }

        $url = is_string($release['html_url'] ?? null) && str_starts_with($release['html_url'], 'https://github.com/' . $repo . '/')
            ? $release['html_url']
            : 'https://github.com/' . $repo . '/releases';

        return [
            'version' => $latest,
            'tag' => $tag,
            'name' => is_string($release['name'] ?? null) && $release['name'] !== '' ? $release['name'] : $tag,
            'url' => $url,
            'zip_url' => $zipUrl,
            'asset' => $assetName,
            'core' => $core,
            'compatible' => $compatible,
            'problem' => $problem,
            'installable' => $zipUrl !== null && $problem === null && $compatible !== false,
            'body' => mb_substr(is_string($release['body'] ?? null) ? $release['body'] : '', 0, 4000),
        ];
    }

    private static function findAsset(array $release, string $assetName, string $repo): ?string
    {
        $prefix = 'https://github.com/' . $repo . '/releases/download/';
        foreach ($release['assets'] ?? [] as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== $assetName) {
                continue;
            }
            $url = $asset['browser_download_url'] ?? null;
            if (is_string($url) && str_starts_with($url, $prefix) && str_ends_with($url, '/' . $assetName)) {
                return $url;
            }
        }

        return null;
    }

    private function fetchManifest(string $repo, string $tag): ?array
    {
        $response = $this->get(
            'https://api.github.com/repos/' . $repo . '/contents/plugin.json?ref=' . rawurlencode($tag),
            ['Accept: application/vnd.github+json'],
            self::MAX_API_BYTES,
            15
        );
        if ($response['status'] !== 200) {
            return null;
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || ($data['encoding'] ?? null) !== 'base64' || !is_string($data['content'] ?? null)) {
            return null;
        }
        $json = base64_decode(str_replace(["\n", "\r"], '', $data['content']), true);
        $manifest = is_string($json) ? json_decode($json, true) : null;

        return is_array($manifest) && !array_is_list($manifest) ? $manifest : null;
    }

    // ---------- установка обновления ----------

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        $plugins = $this->manager->pluginsPath();
        $cache = rtrim((string) $this->config['cache_path'], '/');

        return [
            'zip' => class_exists('ZipArchive'),
            'http' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
            'plugins_writable' => is_dir($plugins) && is_writable($plugins),
            'cache_writable' => is_dir($cache) && is_writable($cache),
        ];
    }

    /**
     * Обновить установленный плагин до $targetVersion (последний релиз его "repository").
     *
     * @return array{id: string, from: string, to: string, backup: string}
     */
    public function apply(string $id, string $targetVersion): array
    {
        if (!preg_match(PluginManager::ID_PATTERN, $id)) {
            throw new RuntimeException('Некорректный id плагина');
        }
        $targetVersion = Version::normalizeTag($targetVersion);
        foreach ($this->capabilities() as $name => $ok) {
            if (!$ok) {
                throw new RuntimeException('Хостинг не готов к обновлению плагинов (ZipArchive, HTTP, права на plugins/ и cache/): ' . $name);
            }
        }

        $root = $this->manager->pluginsPath();
        $dir = $root . '/' . $id;
        if (!is_dir($dir) || !isset($this->manager->discover()[$id]['manifest'])) {
            throw new RuntimeException('Плагин «' . $id . '» не установлен');
        }
        if (is_link($dir)) {
            throw new RuntimeException('Папка плагина — символическая ссылка; обновите плагин вручную');
        }
        if (file_exists($dir . '/.git')) {
            throw new RuntimeException('Папка плагина — рабочая копия git; обновите её через git');
        }

        $cachePath = rtrim((string) $this->config['cache_path'], '/');
        $lock = fopen($cachePath . '/plugin-update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Обновление плагина уже выполняется');
        }

        $tmpZip = $cachePath . '/plugin-update-' . $id . '-' . $targetVersion . '.zip';
        $staging = $root . '/.' . $id . '.extract';
        $next = $root . '/.' . $id . '.next';
        $old = $root . '/.' . $id . '.old';

        try {
            $status = $this->status($id, true);
            if ($status['error'] !== null) {
                throw new RuntimeException($status['error']);
            }
            $update = $status['update'];
            if ($update === null || ($update['version'] ?? '') !== $targetVersion) {
                throw new RuntimeException('Версия ' . $targetVersion . ' недоступна для обновления плагина «' . $id . '»');
            }
            if (empty($update['zip_url'])) {
                throw new RuntimeException('В релизе нет архива ' . $id . '-' . $targetVersion . '.zip');
            }
            if (($update['problem'] ?? null) !== null) {
                throw new RuntimeException((string) $update['problem']);
            }
            if (($update['compatible'] ?? null) === false) {
                throw new RuntimeException('Версия ' . $targetVersion . ' требует ядро ' . $update['core'] . ', установлено ' . Version::current());
            }
            $from = $status['current'];

            $this->download((string) $update['zip_url'], $tmpZip);
            $this->validateZip($tmpZip, $id, $targetVersion, (string) $status['repository']);

            foreach ([$staging, $next, $old] as $path) {
                $this->rmTree($path);
            }
            $this->extract($tmpZip, $staging, $id);
            if (!rename($staging . '/' . $id, $next)) {
                throw new RuntimeException('Не удалось подготовить новую версию плагина');
            }

            $backup = $cachePath . '/plugin-backup-' . $id . '-' . $from . '.zip';
            $this->zipDirectory($dir, $id, $backup);

            if (!rename($dir, $old)) {
                throw new RuntimeException('Не удалось переместить текущую версию плагина');
            }
            if (!rename($next, $dir)) {
                rename($old, $dir);
                throw new RuntimeException('Не удалось активировать новую версию плагина');
            }

            try {
                $this->activate($id, $targetVersion);
            } catch (Throwable $e) {
                $this->rmTree($dir);
                rename($old, $dir);
                throw new RuntimeException('Обновление плагина «' . $id . '» отменено, возвращена v' . $from . ': ' . $e->getMessage(), 0, $e);
            }

            $this->rmTree($old);
            $this->pruneBackups($id, $cachePath);
            if (class_exists('Cache')) {
                (new Cache($cachePath))->flush();
            }
            $cache = $this->readCache();
            $cache[$id] = [
                'checked_at' => time(),
                'current' => $targetVersion,
                'core' => Version::current(),
                'repository' => $status['repository'],
                'update' => null,
                'error' => null,
            ];
            $this->writeCache($cache);

            return ['id' => $id, 'from' => $from, 'to' => $targetVersion, 'backup' => $backup];
        } finally {
            @unlink($tmpZip);
            $this->rmTree($staging);
            $this->rmTree($next);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Новая версия на месте: проверка манифеста ядром и загрузка (onInstall-миграции). Исключение → откат. */
    private function activate(string $id, string $version): void
    {
        $manager = new PluginManager($this->config);
        $info = $manager->discover()[$id] ?? null;
        if ($info === null || $info['status'] !== 'ok') {
            throw new RuntimeException($info !== null ? implode('; ', $info['errors']) : 'плагин не найден после замены');
        }
        if ($info['version'] !== $version) {
            throw new RuntimeException('после замены версия ' . $info['version'] . ' вместо ' . $version);
        }
        if ($manager->boot($id) === null) {
            $errors = $manager->discover()[$id]['errors'] ?? [];
            throw new RuntimeException(implode('; ', $errors) ?: 'плагин не загрузился');
        }
    }

    /**
     * Проверка архива до распаковки: ровно одна папка <id>/ с plugin.json, id/версия/repository совпадают,
     * ядро подходит; нет абсолютных путей, «..», симлинков, шифрования; ограничены размер и число файлов.
     *
     * @return array<string, mixed> plugin.json из архива
     */
    public function validateZip(string $zipPath, string $id, string $version, string $repository): array
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Архив не найден');
        }
        if (filesize($zipPath) > self::MAX_ZIP_BYTES) {
            throw new RuntimeException('Архив больше ' . (self::MAX_ZIP_BYTES >> 20) . ' МБ');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Некорректный zip-архив');
        }
        try {
            $count = $zip->numFiles;
            if ($count < 1 || $count > self::MAX_FILES) {
                throw new RuntimeException('В архиве ' . $count . ' файлов (допустимо 1–' . self::MAX_FILES . ')');
            }
            $prefix = $id . '/';
            $total = 0;
            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new RuntimeException('Не удалось прочитать запись архива #' . $i);
                }
                $name = (string) $stat['name'];
                if (!self::isSafeEntryName($name)) {
                    throw new RuntimeException('Недопустимый путь в архиве: ' . self::printable($name));
                }
                if (!str_starts_with($name, $prefix)) {
                    throw new RuntimeException('В архиве файл вне папки ' . $prefix . ': ' . self::printable($name));
                }
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('Зашифрованные файлы в архиве не поддерживаются');
                }
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $type = ($attr >> 16) & 0170000;
                    if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                        throw new RuntimeException('Символические ссылки и спецфайлы в архиве запрещены: ' . self::printable($name));
                    }
                }
                $total += (int) $stat['size'];
                if ($total > self::MAX_UNPACKED_BYTES) {
                    throw new RuntimeException('Распакованный архив больше ' . (self::MAX_UNPACKED_BYTES >> 20) . ' МБ');
                }
            }

            $raw = $zip->getFromName($prefix . 'plugin.json');
            if (!is_string($raw)) {
                throw new RuntimeException('В архиве нет ' . $prefix . 'plugin.json');
            }
            $manifest = json_decode($raw, true);
            if (!is_array($manifest) || array_is_list($manifest)) {
                throw new RuntimeException('plugin.json в архиве — некорректный JSON-объект');
            }
            if (($manifest['id'] ?? null) !== $id) {
                throw new RuntimeException('id в plugin.json архива не «' . $id . '»');
            }
            if (($manifest['version'] ?? null) !== $version) {
                throw new RuntimeException('Версия в plugin.json архива не ' . $version);
            }
            if (self::normalizeRepository($manifest['repository'] ?? null) !== $repository) {
                throw new RuntimeException('repository в plugin.json архива не ' . $repository);
            }
            $core = is_string($manifest['core'] ?? null) ? trim($manifest['core']) : '';
            $compatible = $core !== '' ? PluginManager::satisfies(Version::current(), $core) : null;
            if ($compatible !== true) {
                throw new RuntimeException('Версия ' . $version . ' несовместима с ядром ' . Version::current() . ' (core «' . $core . '»)');
            }
            $entry = $manifest['entry'] ?? 'plugin.php';
            if (!is_string($entry) || !self::isSafeEntryName($entry) || $zip->locateName($prefix . $entry) === false) {
                throw new RuntimeException('В архиве нет точки входа плагина');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    private static function isSafeEntryName(string $name): bool
    {
        if ($name === '' || strlen($name) > 1024 || str_contains($name, "\0") || str_contains($name, '\\')) {
            return false;
        }
        if ($name[0] === '/' || preg_match('#^[A-Za-z]:#', $name) || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return false;
        }
        $segments = explode('/', str_ends_with($name, '/') ? substr($name, 0, -1) : $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function printable(string $name): string
    {
        return mb_substr(preg_replace('/[^\x20-\x7E\x{80}-\x{10FFFF}]/u', '?', $name) ?? '?', 0, 200);
    }

    private function extract(string $zipPath, string $staging, string $id): void
    {
        if (!mkdir($staging, 0775, true) && !is_dir($staging)) {
            throw new RuntimeException('Не удалось создать ' . $staging);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true || !$zip->extractTo($staging)) {
            throw new RuntimeException('Не удалось распаковать архив');
        }
        $zip->close();

        $entries = array_values(array_diff(scandir($staging) ?: [], ['.', '..']));
        if ($entries !== [$id] || !is_dir($staging . '/' . $id) || is_link($staging . '/' . $id)) {
            throw new RuntimeException('После распаковки ожидалась ровно одна папка ' . $id . '/');
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('После распаковки найдена символическая ссылка');
            }
        }
    }

    private function download(string $url, string $target): void
    {
        $response = $this->get($url, ['Accept: application/octet-stream'], self::MAX_ZIP_BYTES, 120);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Не удалось скачать архив: HTTP ' . $response['status']);
        }
        if (file_put_contents($target, $response['body']) === false) {
            throw new RuntimeException('Не удалось сохранить архив в cache/');
        }
    }

    // ---------- HTTP ----------

    /**
     * GET с ручными редиректами: только https и хосты из ALLOWED_HOSTS, не больше MAX_REDIRECTS.
     *
     * @param array<int, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function get(string $url, array $headers, int $maxBytes, int $timeout): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!self::isAllowedUrl($url)) {
                throw new RuntimeException('Запрещённый адрес загрузки: ' . (parse_url($url, PHP_URL_HOST) ?: $url));
            }
            try {
                $response = ($this->fetcher)($url, array_merge($headers, ['User-Agent: motorchk-cms']), $maxBytes, $timeout);
            } catch (PluginUpdaterNetworkException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new PluginUpdaterNetworkException('Ошибка сети: ' . $e->getMessage(), 0, $e);
            }
            $status = (int) ($response['status'] ?? 0);
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = (string) ($response['headers']['location'] ?? '');
                if ($location === '') {
                    throw new RuntimeException('Редирект без адреса');
                }
                if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
                    $location = 'https://' . parse_url($url, PHP_URL_HOST) . $location;
                }
                $url = $location;
                continue;
            }
            if (strlen((string) ($response['body'] ?? '')) > $maxBytes) {
                throw new RuntimeException('Ответ больше допустимого размера');
            }

            return ['status' => $status, 'body' => (string) ($response['body'] ?? ''), 'headers' => (array) ($response['headers'] ?? [])];
        }

        throw new RuntimeException('Слишком много редиректов');
    }

    /**
     * Один GET без следования редиректам.
     *
     * @param array<int, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public static function httpRequest(string $url, array $headers, int $maxBytes, int $timeout): array
    {
        if (function_exists('curl_init')) {
            $body = '';
            $responseHeaders = [];
            $tooBig = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooBig = true;

                        return -1;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    $pos = strpos($line, ':');
                    if ($pos !== false) {
                        $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                    }

                    return strlen($line);
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
            } elseif (defined('CURLOPT_PROTOCOLS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            if ($tooBig) {
                throw new RuntimeException('Ответ больше ' . (int) ($maxBytes >> 20) . ' МБ');
            }
            if ($ok === false) {
                throw new RuntimeException($error !== '' ? $error : 'запрос не выполнен');
            }

            return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
                'follow_location' => 0,
                'max_redirects' => 1,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $fp = @fopen($url, 'rb', false, $context);
        if ($fp === false) {
            throw new RuntimeException('запрос не выполнен');
        }
        $body = stream_get_contents($fp, $maxBytes + 1);
        $meta = stream_get_meta_data($fp);
        fclose($fp);
        if (!is_string($body)) {
            throw new RuntimeException('не удалось прочитать ответ');
        }
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('Ответ больше ' . (int) ($maxBytes >> 20) . ' МБ');
        }
        $status = 0;
        $responseHeaders = [];
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (($pos = strpos((string) $line, ':')) !== false) {
                $responseHeaders[strtolower(trim(substr((string) $line, 0, $pos)))] = trim(substr((string) $line, $pos + 1));
            }
        }

        return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
    }

    // ---------- кеш и файлы ----------

    private static function ttl(): int
    {
        $ttl = (int) (Env::get('UPDATE_CHECK_TTL') ?: 21600);

        return $ttl < 60 ? 21600 : $ttl;
    }

    private function cacheFile(): string
    {
        return rtrim((string) $this->config['cache_path'], '/') . '/' . self::CACHE_FILE;
    }

    /** @return array<string, array<string, mixed>> */
    private function readCache(): array
    {
        $file = $this->cacheFile();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && is_array($data['plugins'] ?? null) ? $data['plugins'] : [];
    }

    private function writeCache(array $plugins): void
    {
        try {
            FileWriter::write($this->cacheFile(), json_encode(['plugins' => $plugins], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            error_log('motorchk plugin updates cache: ' . $e->getMessage());
        }
    }

    private function zipDirectory(string $source, string $prefix, string $target): void
    {
        @unlink($target);
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать резервную копию ' . basename($target));
        }
        $zip->addEmptyDir($prefix);
        $base = strlen(rtrim($source, '/')) + 1;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            if ($file->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), $base));
            if ($file->isDir()) {
                $zip->addEmptyDir($prefix . '/' . $relative);
            } else {
                $zip->addFile($file->getPathname(), $prefix . '/' . $relative);
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Не удалось записать резервную копию ' . basename($target));
        }
    }

    private function pruneBackups(string $id, string $cachePath): void
    {
        $files = glob($cachePath . '/plugin-backup-' . $id . '-*.zip') ?: [];
        $files = array_filter($files, static fn(string $f): bool => (bool) preg_match('/^plugin-backup-' . preg_quote($id, '/') . '-\d+\.\d+\.\d+[^\/]*\.zip$/', basename($f)));
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, self::KEEP_BACKUPS) as $file) {
            @unlink($file);
        }
    }

    private function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $this->rmTree($path . '/' . $item);
        }
        @rmdir($path);
    }
}

class PluginUpdaterNetworkException extends RuntimeException
{
}
