<?php

class Updater
{
    public const GITHUB_REPO = 'burzilov/motorchk';

    public function __construct(private array $config)
    {
    }

    public function checkForUpdate(bool $force = false): ?array
    {
        $cacheFile = rtrim($this->config['cache_path'], '/') . '/update-check.json';
        $ttl = (int) (Env::get('UPDATE_CHECK_TTL') ?: 21600);
        if ($ttl < 60) {
            $ttl = 21600;
        }

        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['checked_at']) && (time() - (int) $cached['checked_at']) < $ttl) {
                return $cached['update'] ?? null;
            }
        }

        $release = $this->fetchLatestRelease();
        if ($force && $release === null) {
            throw new RuntimeException('Не удалось получить данные с GitHub Releases API');
        }

        $update = null;

        if ($release !== null) {
            $latest = Version::normalizeTag($release['tag_name'] ?? '');
            $current = Version::current();
            if ($latest !== '' && Version::compare($latest, $current) > 0) {
                $update = [
                    'version' => $latest,
                    'tag' => $release['tag_name'],
                    'name' => $release['name'] ?? $release['tag_name'],
                    'url' => $release['html_url'] ?? '',
                    'zip_url' => $this->findZipAssetUrl($release, $latest),
                    'body' => $release['body'] ?? '',
                ];
            }
        }

        $payload = [
            'checked_at' => time(),
            'current' => Version::current(),
            'update' => $update,
        ];

        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cacheFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $update;
    }

    public function capabilities(): array
    {
        $corePath = $this->config['core_path'];
        $sitePath = $this->config['site_path'];
        $parent = dirname($corePath);

        return [
            'zip' => class_exists('ZipArchive'),
            'curl_or_fopen' => function_exists('curl_init') || ini_get('allow_url_fopen'),
            'core_writable' => is_writable($parent) && is_writable($corePath),
            'cache_writable' => is_dir($this->config['cache_path']) && is_writable($this->config['cache_path']),
        ];
    }

    public function apply(string $targetVersion): void
    {
        $caps = $this->capabilities();
        foreach ($caps as $ok) {
            if (!$ok) {
                throw new RuntimeException('Хостинг не готов к self-update (нужны ZipArchive, HTTP download, права на запись)');
            }
        }

        $targetVersion = Version::normalizeTag($targetVersion);
        $update = $this->checkForUpdate(true);
        if ($update === null || ($update['version'] ?? '') !== $targetVersion) {
            throw new RuntimeException('Указанная версия недоступна для обновления');
        }

        $zipUrl = $update['zip_url'] ?? null;
        if (!$zipUrl) {
            throw new RuntimeException('В релизе нет zip-артефакта motorchk-core-*.zip');
        }

        $cachePath = rtrim($this->config['cache_path'], '/');
        $tmpZip = $cachePath . '/core-update-' . $targetVersion . '.zip';
        $extractDir = $cachePath . '/core-extract-' . $targetVersion;
        $corePath = $this->config['core_path'];
        $coreNext = $corePath . '-next';
        $coreOld = $corePath . '-old';
        $backupZip = $cachePath . '/core-backup-' . Version::current() . '.zip';

        $this->download($zipUrl, $tmpZip);
        $this->validateZip($tmpZip, $targetVersion);
        $this->zipDirectory($corePath, $backupZip);

        $this->rmTree($extractDir);
        $this->rmTree($coreNext);
        if (is_dir($coreOld)) {
            $this->rmTree($coreOld);
        }

        $this->extractZip($tmpZip, $extractDir);
        $payloadRoot = $this->detectPayloadRoot($extractDir);
        rename($payloadRoot, $coreNext);

        if (!rename($corePath, $coreOld)) {
            $this->rmTree($coreNext);
            throw new RuntimeException('Не удалось переименовать текущий core/');
        }

        if (!rename($coreNext, $corePath)) {
            rename($coreOld, $corePath);
            throw new RuntimeException('Не удалось активировать новый core/');
        }

        try {
            require $corePath . '/install/migrate.php';
            motorchk_migrate($this->config, $targetVersion);
            $this->rmTree($coreOld);
        } catch (Throwable $e) {
            if (is_dir($corePath) && is_dir($coreOld)) {
                $this->rmTree($corePath);
                rename($coreOld, $corePath);
            }
            throw $e;
        } finally {
            @unlink($tmpZip);
            $this->rmTree($extractDir);
            if (is_dir($coreNext)) {
                $this->rmTree($coreNext);
            }
        }
    }

    private function fetchLatestRelease(): ?array
    {
        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $json = $this->httpGet($url, ['Accept: application/vnd.github+json', 'User-Agent: motorchk-cms']);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) && isset($data['tag_name']) ? $data : null;
    }

    private function findZipAssetUrl(array $release, string $version): ?string
    {
        $expected = 'motorchk-core-' . $version . '.zip';
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? '') === $expected && !empty($asset['browser_download_url'])) {
                return $asset['browser_download_url'];
            }
        }

        return null;
    }

    private function download(string $url, string $dest): void
    {
        $data = $this->httpGet($url, ['User-Agent: motorchk-cms', 'Accept: application/octet-stream']);
        if ($data === null || $data === '') {
            throw new RuntimeException('Не удалось скачать архив обновления');
        }
        if (file_put_contents($dest, $data) === false) {
            throw new RuntimeException('Не удалось сохранить архив обновления');
        }
    }

    private function httpGet(string $url, array $headers = []): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                return null;
            }
            return $body;
        }

        $headerLine = implode("\r\n", $headers);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headerLine,
                'timeout' => 120,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    private function validateZip(string $zipPath, string $expectedVersion): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Повреждённый zip обновления');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $hasVersion = false;
        $hasIncludes = false;
        $hasAdmin = false;
        $hasVendor = false;
        $versionValue = null;

        $zip = new ZipArchive();
        $zip->open($zipPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (preg_match('#(^|/)VERSION$#', $name)) {
                $hasVersion = true;
                $versionValue = trim((string) $zip->getFromIndex($i));
            }
            if (preg_match('#(^|/)includes/#', $name)) {
                $hasIncludes = true;
            }
            if (preg_match('#(^|/)admin-panel/#', $name)) {
                $hasAdmin = true;
            }
            if (preg_match('#(^|/)vendor/autoload\.php$#', $name)) {
                $hasVendor = true;
            }
        }
        $zip->close();

        if (!$hasVersion || !$hasIncludes || !$hasAdmin || !$hasVendor) {
            throw new RuntimeException('В архиве нет обязательных файлов ядра');
        }

        if (Version::normalizeTag((string) $versionValue) !== $expectedVersion) {
            throw new RuntimeException('VERSION в архиве не совпадает с выбранным релизом');
        }
    }

    private function extractZip(string $zipPath, string $dest): void
    {
        mkdir($dest, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Не удалось открыть zip');
        }
        if (!$zip->extractTo($dest)) {
            $zip->close();
            throw new RuntimeException('Не удалось распаковать zip');
        }
        $zip->close();
    }

    private function detectPayloadRoot(string $extractDir): string
    {
        if (is_file($extractDir . '/VERSION') && is_dir($extractDir . '/includes')) {
            return $extractDir;
        }

        $entries = array_values(array_filter(scandir($extractDir) ?: [], static fn($e) => $e !== '.' && $e !== '..'));
        if (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0])) {
            $inner = $extractDir . '/' . $entries[0];
            if (is_file($inner . '/VERSION')) {
                return $inner;
            }
        }

        throw new RuntimeException('Не удалось определить корень ядра в архиве');
    }

    private function zipDirectory(string $source, string $zipFile): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать бэкап core');
        }

        $source = rtrim($source, '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $path = $item->getPathname();
            $local = substr($path, strlen($source) + 1);
            if (str_starts_with($local, '.git')) {
                continue;
            }
            if ($item->isDir()) {
                $zip->addEmptyDir($local);
            } else {
                $zip->addFile($path, $local);
            }
        }

        $zip->close();
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
