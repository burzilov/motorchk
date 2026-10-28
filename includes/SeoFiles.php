<?php

/**
 * Служебные файлы для поисковиков и LLM: /sitemap.xml, /robots.txt, /llms.txt (docs/13-seo.md).
 *
 * Строятся на лету из опубликованных Markdown-страниц. Настройки — ключ 'seo' в config/site.php
 * (необязательный, значения по умолчанию — SeoFiles::DEFAULTS). Плагины дополняют файлы
 * фильтрами «sitemap», «robots», «llms». Физический файл в корне сайта (robots.txt и т. п.)
 * Apache отдаёт сам, до ядра.
 */
class SeoFiles
{
    /** Адрес → вид файла. */
    public const PATHS = [
        '/sitemap.xml' => 'sitemap',
        '/robots.txt' => 'robots',
        '/llms.txt' => 'llms',
    ];

    public const DEFAULTS = [
        'sitemap' => true,
        'robots' => true,
        'llms' => true,
        // https://example.ru — адрес сайта в sitemap, robots и llms; пусто — схема и хост запроса
        'site_url' => '',
        // дополнительные правила Disallow в robots.txt: ['/private/', '/*?utm_']; ['/'] — закрыть весь сайт
        'robots_disallow' => [],
        // llms.txt: заголовок и описание; пусто — title и description главной страницы
        'site_name' => '',
        'llms_description' => '',
    ];

    /** Disallow ядра: админка, API, предпросмотр черновиков. */
    public const CORE_DISALLOW = ['/admin-panel', '/api/', '/*?preview='];

    /** Markdown после списка страниц в llms.txt: content/_llms.txt (необязательный; не .md — не страница сайта). */
    public const LLMS_EXTRA_FILE = '_llms.txt';

    public function __construct(
        private array $config,
        private PageLoader $pageLoader,
        private PageTree $pageTree,
        private ?PluginManager $plugins = null,
    ) {
    }

    public static function kind(string $path): ?string
    {
        return self::PATHS[$path] ?? null;
    }

    /** Настройки с значениями по умолчанию (config/site.php может не содержать 'seo' или часть ключей). */
    public static function settings(array $config): array
    {
        $seo = is_array($config['seo'] ?? null) ? $config['seo'] : [];
        $settings = array_replace(self::DEFAULTS, $seo);
        foreach (['sitemap', 'robots', 'llms'] as $key) {
            $settings[$key] = (bool) $settings[$key];
        }
        $settings['robots_disallow'] = array_values(array_filter(
            array_map(static fn(mixed $v): string => is_scalar($v) ? trim((string) $v) : '', (array) $settings['robots_disallow']),
            static fn(string $v): bool => $v !== '' && preg_match('/^[\/*][^\s#]*$/', $v) === 1
        ));
        foreach (['site_url', 'site_name', 'llms_description'] as $key) {
            $settings[$key] = is_scalar($settings[$key]) ? trim((string) $settings[$key]) : '';
        }

        return $settings;
    }

    public static function enabled(array $config, string $kind): bool
    {
        return isset(self::DEFAULTS[$kind]) && self::settings($config)[$kind] === true;
    }

    /** Ответ на GET/HEAD: заголовки и тело файла $kind. */
    public function respond(string $kind): void
    {
        [$type, $body] = match ($kind) {
            'sitemap' => ['application/xml; charset=UTF-8', $this->sitemap()],
            'robots' => ['text/plain; charset=UTF-8', $this->robots()],
            'llms' => ['text/plain; charset=UTF-8', $this->llms()],
            default => throw new InvalidArgumentException('Неизвестный файл: ' . $kind),
        };
        http_response_code(200);
        header('Content-Type: ' . $type);
        header('Cache-Control: public, max-age=3600');
        if ($kind === 'sitemap' || $kind === 'llms') {
            header('X-Robots-Tag: noindex');
        }
        echo $body;
    }

    /** Адрес сайта без завершающего «/»: seo.site_url или схема и хост запроса; '' — не определить. */
    public function baseUrl(): string
    {
        $url = rtrim(self::settings($this->config)['site_url'], '/');
        if ($url !== '' && preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+(/[^\s]*)?$#', $url)) {
            return $url;
        }
        $abs = SiteLocale::absolute('/');

        return str_starts_with($abs, 'http') ? rtrim($abs, '/') : '';
    }

    /** Абсолютный адрес для пути внутри сайта; абсолютные http(s) — как есть; другое — null. */
    public function absolute(string $url): ?string
    {
        if (preg_match('#^https?://#i', $url)) {
            return preg_match('/[\s"<>]/', $url) ? null : $url;
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\s"<>\\\\]/', $url)) {
            return null;
        }

        return $this->baseUrl() . $url;
    }

    /**
     * Публичные страницы: опубликованные, без noindex: true / sitemap: false, не служебные
     * (имя файла или папки на «_»), не перекрытые префиксом плагина. Переводы — в 'locales'.
     *
     * @return list<array{slug: string, path: string, title: string, description: string, lastmod: ?int, locales: array<string, array{path: string, lastmod: ?int}>}>
     */
    public function pages(): array
    {
        $pages = [];
        $default = SiteLocale::default();
        $nodes = $this->pageTree->getPublishedPages();
        usort($nodes, static fn(array $a, array $b): int => [$a['slug'] === 'index' ? 0 : 1, $a['slug']] <=> [$b['slug'] === 'index' ? 0 : 1, $b['slug']]);
        foreach ($nodes as $node) {
            $slug = (string) $node['slug'];
            if (!$this->isPublicSlug($slug)) {
                continue;
            }
            $page = $this->pageLoader->loadBySlug($slug, true);
            if ($page === null || self::excluded($page['front_matter'])) {
                continue;
            }
            $path = $slug === 'index' ? '/' : '/' . implode('/', array_map('rawurlencode', explode('/', $slug)));
            $locales = [];
            if (SiteLocale::isActive()) {
                foreach (SiteLocale::locales() as $locale) {
                    if ($locale === $default) {
                        $locales[$locale] = ['path' => SiteLocale::url($path, $locale, 'alternate'), 'lastmod' => self::mtime($this->pageLoader->slugToPath($slug))];
                        continue;
                    }
                    $translation = $this->pageLoader->loadBySlug($slug, true, $locale);
                    if ($translation !== null && !self::excluded($translation['front_matter'])) {
                        $locales[$locale] = ['path' => SiteLocale::url($path, $locale, 'alternate'), 'lastmod' => self::mtime($this->pageLoader->slugToPath($slug, $locale))];
                    }
                }
            }
            $fm = $page['front_matter'];
            $pages[] = [
                'slug' => $slug,
                'path' => $path,
                'title' => trim((string) (is_scalar($fm['title'] ?? null) ? $fm['title'] : $slug)),
                'description' => trim((string) (is_scalar($fm['description'] ?? null) ? $fm['description'] : '')),
                'lastmod' => self::mtime($this->pageLoader->slugToPath($slug)),
                'locales' => count($locales) > 1 ? $locales : [],
            ];
        }

        return $pages;
    }

    /**
     * Записи sitemap до фильтра «sitemap». Плагин добавляет свои: ['loc' => '/blog/post',
     * 'lastmod' => '2026-10-28' | timestamp, 'alternates' => ['en' => '/en/blog/post'], 'changefreq' => …, 'priority' => …].
     *
     * @return list<array<string, mixed>>
     */
    public function sitemapEntries(): array
    {
        $entries = [];
        foreach ($this->pages() as $page) {
            if ($page['locales'] === []) {
                $entries[] = ['loc' => $page['path'], 'lastmod' => $page['lastmod'], 'alternates' => [], 'slug' => $page['slug']];
                continue;
            }
            $alternates = array_map(static fn(array $l): string => $l['path'], $page['locales']);
            foreach ($page['locales'] as $locale => $version) {
                $entries[] = ['loc' => $version['path'], 'lastmod' => $version['lastmod'], 'alternates' => $alternates, 'slug' => $page['slug'], 'locale' => $locale];
            }
        }

        return $entries;
    }

    public function sitemap(): string
    {
        $entries = $this->sitemapEntries();
        if ($this->plugins !== null) {
            $entries = (array) $this->plugins->filter('sitemap', $entries, $this->hookContext());
        }
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $default = SiteLocale::default();
        $urls = [];
        $seen = [];
        $hasAlternates = false;
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['loc'] ?? null)) {
                continue;
            }
            $loc = $this->absolute($entry['loc']);
            if ($loc === null || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $xml = '  <url>' . "\n" . '    <loc>' . $e($loc) . '</loc>' . "\n";
            $lastmod = self::w3cDate($entry['lastmod'] ?? null);
            if ($lastmod !== null) {
                $xml .= '    <lastmod>' . $e($lastmod) . '</lastmod>' . "\n";
            }
            if (is_string($entry['changefreq'] ?? null) && in_array($entry['changefreq'], ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'], true)) {
                $xml .= '    <changefreq>' . $entry['changefreq'] . '</changefreq>' . "\n";
            }
            if (is_numeric($entry['priority'] ?? null) && (float) $entry['priority'] >= 0 && (float) $entry['priority'] <= 1) {
                $xml .= '    <priority>' . number_format((float) $entry['priority'], 1, '.', '') . '</priority>' . "\n";
            }
            $alternates = is_array($entry['alternates'] ?? null) ? $entry['alternates'] : [];
            $links = [];
            foreach ($alternates as $locale => $url) {
                $href = is_string($url) ? $this->absolute($url) : null;
                if ($href !== null && is_string($locale) && preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})?$/', $locale)) {
                    $links[$locale] = $href;
                }
            }
            if (count($links) > 1) {
                $hasAlternates = true;
                foreach ($links as $locale => $href) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="' . $e($locale) . '" href="' . $e($href) . '"/>' . "\n";
                }
                if (isset($links[$default])) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . $e($links[$default]) . '"/>' . "\n";
                }
            }
            $urls[] = $xml . '  </url>' . "\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ($hasAlternates ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '') . ">\n"
            . implode('', $urls)
            . "</urlset>\n";
    }

    public function robots(): string
    {
        $settings = self::settings($this->config);
        $disallow = array_values(array_unique(array_merge(self::CORE_DISALLOW, $settings['robots_disallow'])));
        $lines = ['User-agent: *'];
        // «Disallow: /» в настройках закрывает весь сайт: «Allow: /» перебил бы его
        if (!in_array('/', $disallow, true)) {
            $lines[] = 'Allow: /';
        }
        foreach ($disallow as $rule) {
            $lines[] = 'Disallow: ' . $rule;
        }
        $base = $this->baseUrl();
        if ($settings['sitemap'] && $base !== '') {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        }
        $text = implode("\n", $lines) . "\n";
        if ($this->plugins !== null) {
            $text = (string) $this->plugins->filter('robots', $text, $this->hookContext());
        }

        return $text;
    }

    public function llms(): string
    {
        $settings = self::settings($this->config);
        $pages = $this->pages();
        $home = null;
        foreach ($pages as $page) {
            if ($page['slug'] === 'index') {
                $home = $page;
            }
        }
        $index = $home ?? $this->homeFrontMatter();
        $name = $settings['site_name'] !== '' ? $settings['site_name'] : (string) ($index['title'] ?? '');
        if ($name === '') {
            $name = (string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?: 'Сайт');
        }
        $description = $settings['llms_description'] !== '' ? $settings['llms_description'] : (string) ($index['description'] ?? '');

        $oneLine = static fn(string $v): string => trim((string) preg_replace('/\s+/u', ' ', $v));
        $lines = ['# ' . $oneLine($name), ''];
        if ($description !== '') {
            $lines[] = '> ' . $oneLine($description);
            $lines[] = '';
        }
        if ($pages !== []) {
            $lines[] = '## Страницы';
            $lines[] = '';
            foreach ($pages as $page) {
                $title = str_replace(['[', ']'], ['(', ')'], $oneLine($page['title']));
                $line = '- [' . $title . '](' . $this->absolute($page['path']) . ')';
                if ($page['description'] !== '') {
                    $line .= ': ' . $oneLine($page['description']);
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }
        $extraFile = rtrim((string) $this->config['content_path'], '/') . '/' . self::LLMS_EXTRA_FILE;
        if (is_file($extraFile)) {
            $extra = trim((string) file_get_contents($extraFile));
            if ($extra !== '') {
                $lines[] = $extra;
                $lines[] = '';
            }
        }
        $text = implode("\n", $lines);
        if ($this->plugins !== null) {
            $text = (string) $this->plugins->filter('llms', $text, $this->hookContext() + ['pages' => $pages]);
        }

        return $text;
    }

    /** Страница не попадает в sitemap и llms.txt: noindex: true или sitemap: false во front matter. */
    public static function excluded(array $frontMatter): bool
    {
        $off = static fn(mixed $v): bool => $v === false || $v === 0 || (is_string($v) && in_array(strtolower(trim($v)), ['false', 'no', '0', 'off'], true));

        return self::noindex($frontMatter) || (array_key_exists('sitemap', $frontMatter) && $off($frontMatter['sitemap']));
    }

    /** noindex: true во front matter — страница закрыта от индексации (X-Robots-Tag: noindex). */
    public static function noindex(array $frontMatter): bool
    {
        $v = $frontMatter['noindex'] ?? null;

        return $v === true || $v === 1 || (is_string($v) && in_array(strtolower(trim($v)), ['true', 'yes', '1', 'on'], true));
    }

    private function isPublicSlug(string $slug): bool
    {
        if ($slug === '' || !preg_match('#^[^\s?\#]+$#u', $slug)) {
            return false;
        }
        foreach (explode('/', $slug) as $segment) {
            if ($segment === '' || str_starts_with($segment, '_') || str_starts_with($segment, '.')) {
                return false;
            }
        }
        if (PageLoader::translationLocale($slug, SiteLocale::translationLocales()) !== null) {
            return false;
        }
        $first = explode('/', $slug, 2)[0];
        if ($slug !== 'index' && $this->plugins !== null && $this->plugins->hasPluginsDir() && $this->plugins->prefixOwner($first) !== null) {
            return false; // адрес перекрыт маршрутом плагина
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function homeFrontMatter(): array
    {
        $page = $this->pageLoader->loadBySlug('index', false);

        return is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    }

    /** @return array<string, mixed> */
    private function hookContext(): array
    {
        return ['base_url' => $this->baseUrl(), 'default_locale' => SiteLocale::default(), 'locales' => SiteLocale::locales()];
    }

    private static function mtime(string $file): ?int
    {
        clearstatcache(true, $file);
        $time = @filemtime($file);

        return $time === false ? null : $time;
    }

    private static function w3cDate(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) {
            return date('c', $value);
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2}))?$/', $value)) {
            return $value;
        }

        return null;
    }
}
