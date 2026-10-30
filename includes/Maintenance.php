<?php

/**
 * Режим технического обслуживания (docs/14-maintenance.md).
 *
 * Настройки — в content/_meta.yaml (SiteMeta): maintenance_enabled, maintenance_hash,
 * maintenance_epoch. Открытый секрет нигде не хранится; при выводе в админке — только сессия.
 *
 * Гейт вызывается из Router::dispatchPublic до SEO-файлов, хуков плагинов, API форм
 * и плагин-роутов. Админка обслуживается отдельным входом (core/admin-panel/index.php)
 * и всегда доступна. Статику, которую Apache отдаёт напрямую минуя PHP (assets тем,
 * media/), закрывает флаг-файл content/.maintenance: свежие установки получают
 * сниппет 503 в skeleton/.htaccess готовым, существующие — добавляют его вручную
 * (docs/14-maintenance.md). /core/assets/ не блокируется: эти файлы нужны админке;
 * PHP-страницы гейт закрывает независимо от сниппета.
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
     * на статику тем (assets темы внутри themes/) и media/. PHP-страницы гейт закрывает
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

    /**
     * Гейт: вернёт true, если запрос полностью обработан (503, редирект или его собственная
     * страница), ложь — продолжить обычную маршрутизацию.
     */
    public static function gate(array $config, string $path, string $method): bool
    {
        if (!self::enabled($config)) {
            return false;
        }

        // Собственная страница-форма проверяет пароль ещё до общего запрета
        if ($path === '/maintenance' && $method === 'POST') {
            self::handlePasswordSubmit($config);
            return true;
        }

        $key = $_GET['maintenance_key'] ?? null;
        if (is_string($key) && $key !== '') {
            if (self::secretMatches($config, $key)) {
                self::grant($config);
                // GET/HEAD — редирект с удалением секрета из адреса; другие методы — без редиректа
                if (in_array($method, ['GET', 'HEAD'], true)) {
                    self::redirectWithoutKey($method);
                }
                return false; // разрешение выдано, запрос продолжается
            }
            self::failAttempt($config, 'Ключ недействителен.');
            return true;
        }

        if (self::allowed($config)) {
            return false;
        }

        self::respond($config, null);
        return true;
    }

    /** Разрешение текущей сессии c учётом эпохи секрета. */
    public static function allowed(array $config): bool
    {
        $grant = $_SESSION['maintenance_grant'] ?? null;
        if (!is_array($grant) || empty($grant['ok'])) {
            return false;
        }

        return hash_equals(
            SiteMeta::maintenanceEpoch($config),
            is_string($grant['epoch'] ?? null) ? $grant['epoch'] : ''
        );
    }

    /** Проверка пароля/ключа без лимита; для формы и админ-действий нужен отдельный вызов failAttempt. */
    public static function secretMatches(array $config, string $secret): bool
    {
        $hash = SiteMeta::maintenanceHash($config);
        return $hash !== '' && password_verify($secret, $hash);
    }

    /** Ограничение попыток (существующий RateLimiter); ключ — только по IP внутри окна. */
    public static function attemptAllowed(array $config, string $ip): bool
    {
        return (new RateLimiter($config['cache_path'], 10, 3600))
            ->hit('maintenance-' . hash('sha256', $ip));
    }

    /**
     * Выдать разрешение текущей сессии. Эпоха берётся в момент выдачи: смена
     * секрета или повторное включение режима инвалидируют ранние разрешения.
     */
    public static function grant(array $config): void
    {
        $_SESSION['maintenance_grant'] = ['ok' => true, 'epoch' => SiteMeta::maintenanceEpoch($config)];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }

    private static function redirectWithoutKey(string $method): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $clean = self::stripKey($uri);
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('Location: ' . self::sameOriginUrl($clean), true, 302);
    }

    /**
     * Убирает maintenance_key из query-строки, сохраняя остальные параметры и путь.
     * Пустой '?' также удаляется. Секрета в ответе не остаётся.
     */
    public static function stripKey(string $uri): string
    {
        $parts = explode('?', $uri, 2);
        $path = $parts[0];
        if (count($parts) === 1) {
            return $uri;
        }

        $keptParams = [];
        foreach (explode('&', $parts[1]) as $param) {
            if ($param === '' || str_starts_with($param, 'maintenance_key=')) {
                continue;
            }
            $keptParams[] = $param;
        }

        return $path . ($keptParams === [] ? '' : '?' . implode('&', $keptParams));
    }

    /** Абсолютный адрес того же origin: только путь и query из REQUEST_URI, пользовательские '?'x нет. */
    private static function sameOriginUrl(string $pathAndQuery): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '' || !preg_match('/^[a-zA-Z0-9.\-\[\]:]+$/', $host)) {
            // Неизвестный Host: относительный редирект остаётся на том же origin
            return $pathAndQuery;
        }

        return $scheme . '://' . $host . $pathAndQuery;
    }

    /** Страница 503: форма пароля и сообщение об ошибке; секрета и ключа в HTML — нет. */
    public static function respond(array $config, ?string $error): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('Retry-After: 3600');
        self::echoPage($config, $error);
    }

    /** Ответ формы пароля: верно — редирект, неверно — снова 503, без раскрытия причины/значения. */
    public static function handlePasswordSubmit(array $config): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            self::respond($config, 'Сессия устарела. Обновите страницу и попробуйте снова.');
            return;
        }

        $secret = (string) ($_POST['maintenance_secret'] ?? '');
        if ($secret === '') {
            self::respond($config, 'Введите ключ или пароль.');
            return;
        }

        if (!self::attemptAllowed($config, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))) {
            self::respond($config, 'Слишком много попыток. Попробуйте позже.');
            return;
        }

        if (!self::secretMatches($config, $secret)) {
            self::respond($config, 'Неверный пароль.');
            return;
        }

        self::grant($config);
        $target = self::stripKey((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        if ($target === '/maintenance' || !str_starts_with($target, '/')) {
            $target = '/';
        }
        header('Location: ' . self::sameOriginUrl($target), true, 302);
    }

    /**
     * Форма обслуживания: POST на текущий адрес (/maintenance); при неверной попытке
     * страница снова 503. Ответ отсылается прежде обычного запрета сайтов.
     */
    private static function failAttempt(array $config, string $message): void
    {
        // Неверный ключ в URL — попытка перебора по адресной строке
        self::attemptAllowed($config, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        self::respond($config, $message);
    }

    private static function echoPage(array $config, ?string $error): void
    {
        // Страница не зависит от темы, контента и внешних ресурсов (docs/14-maintenance.md)
        $csrf = Csrf::field();
        $errorHtml = $error !== null
            ? '<p class="mc-maint__error" role="alert">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
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
  .mc-maint__error { margin: 0 0 1rem; color: #b3261e; font-size: .95rem; }
  .mc-maint label { display: block; font-size: .9rem; color: #55606c; margin-bottom: .4rem; }
  .mc-maint input { width: 100%; padding: .6rem .75rem; border: 1px solid #cfd6dd; border-radius: 8px;
                    font-size: 1rem; margin-bottom: 1rem; }
  .mc-maint button { width: 100%; padding: .6rem .75rem; border: 0; border-radius: 8px;
                     background: #1668dc; color: #fff; font-size: 1rem; cursor: pointer; }
  .mc-maint button:hover { background: #0f57bd; }
  .mc-maint__admin { margin-top: 1rem; font-size: .8rem; color: #96a0ab; }
  .mc-maint__admin a { color: inherit; }
</style>
</head>
<body>
  <main class="mc-maint">
    <h1 class="mc-maint__title">$title</h1>
    <p class="mc-maint__text">Работы идут недолго. Вернитесь позже или введите ключ доступа.</p>
    $errorHtml
    <form method="post" action="/maintenance">
      $csrf
      <label for="maintenance_secret">Ключ или пароль</label>
      <input id="maintenance_secret" name="maintenance_secret" type="password"
             autocomplete="off" autofocus required>
      <button type="submit">Войти</button>
    </form>
    <p class="mc-maint__admin">Администратору: <a href="/admin-panel/">админ-панель</a></p>
  </main>
</body>
</html>
HTML;
    }
}
