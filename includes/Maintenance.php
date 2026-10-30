<?php

/**
 * Режим технического обслуживания (docs/14-maintenance.md).
 *
 * При включённом режиме (галочка «Техническое обслуживание» на «Системе») все PHP-страницы
 * отвечают 503 самодостаточной страницей «Закрыто на техобслуживание» — без темы, контента
 * и внешних ресурсов. Доступ остаётся только у залогиненного администратора: админ-панель
 * работает как обычно (отдельный вход core/admin-panel/index.php, не проходит через гейт),
 * а её сессия — та же, что и у публичных страниц, поэтому проверки allowed() достаточно.
 *
 * Секретных ссылок и паролей нет: вход — только через админ-панель.
 *
 * Статику, которую Apache отдаёт напрямую минуя PHP (assets темы, media/), закрывает
 * флаг-файл content/.maintenance: свежие установки получают сниппет 503 в skeleton/.htaccess
 * готовым, существующие — добавляют его вручную (docs/14-maintenance.md). /core/assets/
 * не блокируется: эти файлы нужны админке; PHP-страницы гейт закрывает независимо от сниппета.
 */
class Maintenance
{
    /** Путь к флаг-файлу для корневого .htaccess (статика непосредственно Apache). */
    public static function flagPath(array $config): string
    {
        return rtrim((string) ($config['content_path'] ?? ''), '/') . '/.maintenance';
    }

    /**
     * Флаг для сниппета корневого .htaccess: если файл есть, Apache отвечает 503
     * на статику тем (assets внутри themes/) и media/. PHP-страницы гейт закрывает
     * независимо от флага; если флаг создать не удалось, статика просто остаётся
     * доступной (docs/14-maintenance.md). /core/assets/ флаг не закрывает:
     * эти файлы нужны админке, которая остаётся доступной.
     */
    public static function writeFlag(array $config): void
    {
        $path = self::flagPath($config);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return;
        }
        @file_put_contents($path, 'maintenance ' . date('c') . "\n");
    }

    public static function removeFlag(array $config): void
    {
        @unlink(self::flagPath($config));
    }

    public static function enabled(array $config): bool
    {
        return SiteMeta::isMaintenanceEnabled($config);
    }

    /** Доступ к сайту при включённом режиме — только у залогиненного администратора. */
    public static function allowed(): bool
    {
        return !empty($_SESSION['admin_authenticated']);
    }

    /**
     * Гейт: вернёт true, если запрос полностью обработан (503), ложь —
     * продолжить обычную маршрутизацию. Вызывается первым шагом dispatchPublic —
     * раньше SEO-файлов, языковых префиксов, хуков плагинов и API форм.
     */
    public static function gate(array $config, string $path, string $method): bool
    {
        if (!self::enabled($config) || self::allowed()) {
            return false;
        }

        self::respond();
        return true;
    }

    /** Страница 503: «Закрыто на техобслуживание» и ссылка в админ-панель. */
    public static function respond(): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        header('Retry-After: 3600');
        self::echoPage();
    }

    private static function echoPage(): void
    {
        $title = 'Закрыто на техобслуживание';

        echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center;
         font: 16px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif;
         background: #f5f6f8; color: #1c2430; padding: 2rem; }
  .mc-maint { width: 100%; max-width: 24rem; background: #fff; border: 1px solid #e2e6ea;
              border-radius: 12px; padding: 2rem; }
  .mc-maint__title { margin: 0 0 .5rem; font-size: 1.25rem; }
  .mc-maint__text { margin: 0 0 1.25rem; color: #55606c; font-size: .95rem; }
  .mc-maint__admin { margin-top: 1rem; font-size: .8rem; color: #96a0ab; }
  .mc-maint__admin a { color: inherit; }
</style>
</head>
<body>
  <main class="mc-maint">
    <h1 class="mc-maint__title">$title</h1>
    <p class="mc-maint__text">Работы идут недолго. Вернитесь позже.</p>
    <p class="mc-maint__admin">Администратору: <a href="/admin-panel/">админ-панель</a></p>
  </main>
</body>
</html>
HTML;
    }
}
