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
            $page = $this->loadCached('page:' . $slug . '@' . $locale, $this->slugToPath($slug, $locale), trim($slug, '/') === '' ? 'index' : trim($slug, '/'));
            if ($page !== null) {
                $page['front_matter']['locale'] = $locale;
            }
        } else {
            $page = $this->loadCached('page:' . $slug, $this->slugToPath($slug), null);
        }

        if ($page === null || ($requirePublished && empty($page['front_matter']['published']))) {
            return null;
        }

        return $page;
    }

    /**
     * Отпечаток файла для сверки кеша: «mtime:размер»; null — файла нет.
     */
    public static function fileStamp(string $path): ?string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? null : $stat['mtime'] . ':' . $stat['size'];
    }

    /**
     * Отпечаток всего содержимого: пути, mtime и размеры файлов .md и .yaml в content/.
     * Меняется при правке, добавлении и удалении файла — в том числе вне админки.
     */
    public function contentStamp(): string
    {
        $root = rtrim($this->config['content_path'], '/');
        $parts = [];
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['md', 'yaml'], true)) {
                    continue;
                }
                $parts[] = substr($file->getPathname(), strlen($root)) . '|' . $file->getMTime() . ':' . $file->getSize();
            }
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
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
     * Страница из кеша, если отпечаток файла (mtime и размер) не изменился, иначе — с диска.
     * Так правки файлов вне админки видны сразу.
     */
    private function loadCached(string $cacheKey, string $filePath, ?string $slug): ?array
    {
        $stamp = self::fileStamp($filePath);
        if ($stamp === null) {
            return null;
        }
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp && is_array($cached['page'] ?? null)) {
            return $cached['page'];
        }
        $page = $this->load($filePath, $slug);
        $this->cache->set($cacheKey, ['stamp' => $stamp, 'page' => $page]);

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
