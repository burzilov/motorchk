<?php

/**
 * Язык текущего публичного запроса (состояние на запрос, как Csrf/Form).
 *
 * Без службы «i18n» (LocaleProvider) язык всегда FALLBACK, переводы не ищутся,
 * адреса не меняются — сайт ведёт себя как до 0.4.0.
 */
final class SiteLocale
{
    public const FALLBACK = 'ru';

    /** Код языка: ru, en, pt-br, zh-hant. */
    public const PATTERN = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/';

    private static ?LocaleProvider $provider = null;

    private static ?string $current = null;

    private static ?PluginManager $filters = null;

    /** Сбросить состояние (начало запроса, тесты). */
    public static function reset(): void
    {
        self::$provider = null;
        self::$current = null;
        self::$filters = null;
    }

    /**
     * Найти службу i18n. $publicFilters — включить фильтр «url» плагинов (только сайт).
     */
    public static function init(?PluginManager $manager, bool $publicFilters): void
    {
        self::reset();
        if ($manager === null || !$manager->hasPluginsDir()) {
            return;
        }
        $service = $manager->service('i18n');
        if ($service instanceof LocaleProvider) {
            self::$provider = $service;
        }
        if ($publicFilters) {
            self::$filters = $manager;
        }
    }

    public static function isActive(): bool
    {
        return self::$provider !== null;
    }

    public static function provider(): ?LocaleProvider
    {
        return self::$provider;
    }

    public static function default(): string
    {
        if (self::$provider === null) {
            return self::FALLBACK;
        }
        $default = strtolower(trim(self::$provider->defaultLocale()));

        return preg_match(self::PATTERN, $default) ? $default : self::FALLBACK;
    }

    /** @return list<string> языки сайта, первым — язык по умолчанию */
    public static function locales(): array
    {
        $default = self::default();
        $out = [$default];
        foreach (self::$provider?->locales() ?? [] as $locale) {
            $locale = strtolower(trim((string) $locale));
            if (preg_match(self::PATTERN, $locale) && !in_array($locale, $out, true)) {
                $out[] = $locale;
            }
        }

        return $out;
    }

    /** Языки переводов (все, кроме основного). Пусто, если службы i18n нет. */
    public static function translationLocales(): array
    {
        return self::isActive() ? array_values(array_slice(self::locales(), 1)) : [];
    }

    public static function current(): string
    {
        return self::$current ?? self::default();
    }

    public static function isDefault(): bool
    {
        return self::current() === self::default();
    }

    /**
     * Значение на языке из массива ['ru' => …, 'en' => …]: $locale (по умолчанию текущий),
     * затем основной, затем первое непустое; null, если непустых нет.
     *
     * @param array<string, mixed> $values
     */
    public static function localized(array $values, ?string $locale = null): mixed
    {
        foreach ([$locale ?? self::current(), self::default()] as $key) {
            if (isset($values[$key]) && $values[$key] !== '') {
                return $values[$key];
            }
        }
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Установить язык запроса. Только языки из locales(). */
    public static function set(string $locale): bool
    {
        $locale = strtolower(trim($locale));
        if (!in_array($locale, self::locales(), true)) {
            return false;
        }
        self::$current = $locale;

        return true;
    }

    /**
     * Адрес внутри сайта на языке $locale (по умолчанию — текущем) через фильтр «url» плагинов.
     * Без фильтров (админка, нет плагинов) — $path как есть.
     *
     * @param string $source кто строит ссылку: theme, menu, plugin, alternate, canonical
     */
    public static function url(string $path, ?string $locale = null, string $source = 'theme'): string
    {
        if (self::$filters === null) {
            return $path;
        }
        $url = self::$filters->filter('url', $path, ['locale' => $locale ?? self::current(), 'source' => $source]);

        return is_string($url) ? $url : $path;
    }

    /** Абсолютный адрес для hreflang/canonical (схема и хост текущего запроса). */
    public static function absolute(string $url): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) || !str_starts_with($url, '/')) {
            return $url;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        return ($https ? 'https' : 'http') . '://' . $host . $url;
    }
}
