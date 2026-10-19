<?php

/**
 * Публичный запрос до маршрутизации — аргумент события «request» (только сайт, не админка).
 *
 *     $plugin->on('request', static function (SiteRequest $request): void {
 *         if (preg_match('#^/en(/.*)?$#', $request->path(), $m)) {
 *             $request->setLocale('en');
 *             $request->setPath($m[1] ?? '/');
 *         }
 *     });
 *
 * Новый путь видят все: формы, префиксы плагинов и Markdown-страницы.
 */
final class SiteRequest
{
    private string $path;

    private bool $handled = false;

    public function __construct(private string $method, private string $originalPath)
    {
        $this->path = $originalPath;
    }

    public function method(): string
    {
        return $this->method;
    }

    /** Путь, по которому ядро будет искать маршрут (после переписывания хуками). */
    public function path(): string
    {
        return $this->path;
    }

    /** Путь из адресной строки, до хуков. */
    public function originalPath(): string
    {
        return $this->originalPath;
    }

    public function setPath(string $path): void
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Путь должен начинаться с одного «/»: ' . $path);
        }
        $this->path = $path;
    }

    public function locale(): string
    {
        return SiteLocale::current();
    }

    /** Язык запроса; false — язык не из списка службы i18n (или службы нет и язык не основной). */
    public function setLocale(string $locale): bool
    {
        return SiteLocale::set($locale);
    }

    public function query(string $name, mixed $default = null): mixed
    {
        return $_GET[$name] ?? $default;
    }

    public function cookie(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Ответ отправлен хуком (например, редирект) — ядро дальше запрос не обрабатывает. */
    public function markHandled(): void
    {
        $this->handled = true;
    }

    public function isHandled(): bool
    {
        return $this->handled;
    }

    /** Редирект внутри сайта и завершение обработки запроса. */
    public function redirect(string $url, int $status = 302): void
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\r\n]/', $url)) {
            throw new InvalidArgumentException('Разрешены только относительные редиректы внутри сайта');
        }
        http_response_code($status);
        header('Location: ' . $url);
        $this->markHandled();
    }
}
