<?php

class PageLoader
{
    public function __construct(
        private array $config,
        private Cache $cache,
        private MarkdownRenderer $renderer,
    ) {
    }

    public function slugToPath(string $slug): string
    {
        $slug = trim($slug, '/');
        if ($slug === '' || $slug === 'index') {
            return $this->config['content_path'] . '/index.md';
        }

        return $this->config['content_path'] . '/' . $slug . '.md';
    }

    public function pathToSlug(string $filePath): string
    {
        $contentPath = rtrim($this->config['content_path'], '/') . '/';
        $relative = str_replace($contentPath, '', $filePath);
        $relative = preg_replace('/\.md$/', '', $relative) ?? $relative;

        return $relative === 'index' ? 'index' : $relative;
    }

    public function load(string $filePath): array
    {
        $parsed = $this->renderer->parseFile($filePath);
        $rawBlocks = $parsed['blocks'];
        $htmlBlocks = $this->renderer->renderBlocks($rawBlocks);

        return [
            'front_matter' => $this->normalizeFrontMatter($parsed['front_matter'], $filePath),
            'blocks' => $htmlBlocks,
            'raw_blocks' => $rawBlocks,
        ];
    }

    public function loadBySlug(string $slug, bool $requirePublished = true): ?array
    {
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

    public function exists(string $slug): bool
    {
        return is_file($this->slugToPath($slug));
    }

    public function isPublished(string $slug): bool
    {
        $page = $this->loadBySlug($slug, false);

        return $page !== null && !empty($page['front_matter']['published']);
    }

    private function normalizeFrontMatter(array $frontMatter, string $filePath): array
    {
        $slug = $this->pathToSlug($filePath);
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
