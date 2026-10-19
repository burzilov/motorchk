<?php

class PageLoader
{
    public function __construct(
        private array $config,
        private Cache $cache,
        private MarkdownRenderer $renderer,
    ) {
    }

    /**
     * Файл страницы. С $locale — файл перевода рядом с оригиналом: about.md → about.en.md,
     * index.md → index.en.md, about/team.md → about/team.en.md (docs/11-i18n.md).
     */
    public function slugToPath(string $slug, ?string $locale = null): string
    {
        $suffix = $locale !== null && $locale !== '' ? '.' . $locale : '';
        $slug = trim($slug, '/');
        if ($slug === '' || $slug === 'index') {
            return $this->config['content_path'] . '/index' . $suffix . '.md';
        }

        return $this->config['content_path'] . '/' . $slug . $suffix . '.md';
    }

    /** Код языка, если slug указывает на файл перевода (about.en при языках ['en']), иначе null. */
    public static function translationLocale(string $slug, array $locales): ?string
    {
        if ($locales === [] || !preg_match('/\.([a-z]{2,3}(?:-[a-z0-9]{2,8})?)$/', $slug, $m)) {
            return null;
        }

        return in_array($m[1], $locales, true) ? $m[1] : null;
    }

    public function pathToSlug(string $filePath): string
    {
        $contentPath = rtrim($this->config['content_path'], '/') . '/';
        $relative = str_replace($contentPath, '', $filePath);
        $relative = preg_replace('/\.md$/', '', $relative) ?? $relative;

        return $relative === 'index' ? 'index' : $relative;
    }

    public function load(string $filePath, ?string $slug = null): array
    {
        $parsed = $this->renderer->parseFile($filePath);
        $rawBlocks = $parsed['blocks'];
        $htmlBlocks = $this->renderer->renderBlocks($rawBlocks);

        return [
            'front_matter' => $this->normalizeFrontMatter($parsed['front_matter'], $filePath, $slug),
            'blocks' => $htmlBlocks,
            'raw_blocks' => $rawBlocks,
        ];
    }

    public function loadBySlug(string $slug, bool $requirePublished = true, ?string $locale = null): ?array
    {
        if ($locale !== null && $locale !== '') {
            return $this->loadTranslation($slug, $locale, $requirePublished);
        }

        $cacheKey = 'page:' . $slug;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            if ($requirePublished && empty($cached['front_matter']['published'])) {
                return null;
            }

            return $cached;
        }

        $filePath = $this->slugToPath($slug);
        if (!is_file($filePath)) {
            return null;
        }

        $page = $this->load($filePath);
        $this->cache->set($cacheKey, $page);

        if ($requirePublished && empty($page['front_matter']['published'])) {
            return null;
        }

        return $page;
    }

    public function exists(string $slug, ?string $locale = null): bool
    {
        return is_file($this->slugToPath($slug, $locale));
    }

    public function isPublished(string $slug): bool
    {
        $page = $this->loadBySlug($slug, false);

        return $page !== null && !empty($page['front_matter']['published']);
    }

    /**
     * Перевод страницы. Файлы переводов правятся вне админки, поэтому кеш сверяется с mtime файла.
     */
    private function loadTranslation(string $slug, string $locale, bool $requirePublished): ?array
    {
        $filePath = $this->slugToPath($slug, $locale);
        $mtime = @filemtime($filePath);
        if ($mtime === false) {
            return null;
        }
        $cacheKey = 'page:' . $slug . '@' . $locale;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && ($cached['mtime'] ?? null) === $mtime && is_array($cached['page'] ?? null)) {
            $page = $cached['page'];
        } else {
            $page = $this->load($filePath, trim($slug, '/') === '' ? 'index' : trim($slug, '/'));
            $page['front_matter']['locale'] = $locale;
            $this->cache->set($cacheKey, ['mtime' => $mtime, 'page' => $page]);
        }

        if ($requirePublished && empty($page['front_matter']['published'])) {
            return null;
        }

        return $page;
    }

    private function normalizeFrontMatter(array $frontMatter, string $filePath, ?string $slug = null): array
    {
        $slug ??= $this->pathToSlug($filePath);
        $frontMatter['slug'] = $slug;
        $frontMatter['parent'] = Slugifier::parentFromSlug($slug);
        $frontMatter['published'] = array_key_exists('published', $frontMatter)
            ? (bool) $frontMatter['published']
            : true;
        $frontMatter['template'] = $frontMatter['template'] ?? 'default';
        $frontMatter['order'] = (int) ($frontMatter['order'] ?? 0);
        $frontMatter['scripts'] = PageScripts::normalize($frontMatter['scripts'] ?? []);

        return $frontMatter;
    }
}
