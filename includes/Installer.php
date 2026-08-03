<?php

class Installer
{
    public function __construct(private array $config)
    {
    }

    public function requirements(): array
    {
        $sitePath = $this->config['site_path'];
        $issues = [];

        if (PHP_VERSION_ID < 80400) {
            $issues[] = 'Требуется PHP 8.4+, сейчас ' . PHP_VERSION;
        }

        foreach (['json', 'mbstring', 'xml'] as $ext) {
            if (!extension_loaded($ext)) {
                $issues[] = "Не установлено расширение PHP: {$ext}";
            }
        }

        if (!is_dir($sitePath) || !is_writable($sitePath)) {
            $issues[] = "Нет прав на запись в {$sitePath}";
        }

        $vendorAutoload = $this->config['core_path'] . '/vendor/autoload.php';
        if (!is_file($vendorAutoload)) {
            $issues[] = 'Отсутствует core/vendor — используйте release zip или выполните composer install';
        }

        return $issues;
    }

    public function install(string $username, string $password, string $appSecret = ''): void
    {
        $issues = $this->requirements();
        if ($issues !== []) {
            throw new RuntimeException(implode("\n", $issues));
        }

        $username = trim($username);
        if ($username === '' || strlen($password) < 8) {
            throw new InvalidArgumentException('Укажите логин и пароль не короче 8 символов');
        }

        $sitePath = $this->config['site_path'];
        $skeleton = $this->config['skeleton_path'];

        $this->copySkeleton($skeleton, $sitePath);

        $secret = $appSecret !== '' ? $appSecret : bin2hex(random_bytes(16));
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash === false) {
            throw new RuntimeException('Не удалось создать хэш пароля');
        }

        $env = "APP_SECRET={$secret}\n"
            . "ADMIN_USERNAME={$username}\n"
            . "ADMIN_PASSWORD_HASH={$hash}\n"
            . "UPDATE_CHECK_TTL=21600\n";

        if (file_put_contents($sitePath . '/.env', $env) === false) {
            throw new RuntimeException('Не удалось записать .env');
        }

        foreach (['content', 'cache', 'templates', 'assets', 'config'] as $dir) {
            $path = $sitePath . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }

        SiteMeta::setEngineVersion($this->config, Version::current());
    }

    private function copySkeleton(string $from, string $to): void
    {
        if (!is_dir($from)) {
            throw new RuntimeException('Не найден skeleton ядра');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $target = $to . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }

            if (is_file($target)) {
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("Не удалось скопировать {$relative}");
            }
        }
    }
}
