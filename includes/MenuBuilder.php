<?php

class MenuBuilder
{
    public function __construct(
        private array $config,
        private Cache $cache,
        private PageLoader $pageLoader,
        private PageTree $pageTree,
        private MenuWriter $menuWriter,
    ) {
    }

    public function build(string $currentSlug, string $menuId = MenuWriter::DEFAULT_LOCATION, bool $forPublic = true): array
    {
        $menus = $this->menuWriter->loadMenus();
        if (!isset($menus[$menuId])) {
            $menuId = MenuWriter::DEFAULT_LOCATION;
        }

        $cacheKey = 'menu:tree:' . $menuId;
        $cached = $this->cache->get($cacheKey);

        if (!is_array($cached)) {
            $cached = $this->loadRawItems($menus[$menuId]['items'] ?? []);
            $this->cache->set($cacheKey, $cached);
        }

        return $this->normalizeItems($cached, $currentSlug, $forPublic);
    }

    public function buildAll(string $currentSlug, bool $forPublic = true): array
    {
        $result = [];
        foreach ($this->menuWriter->loadMenus() as $id => $_menu) {
            $result[$id] = $this->build($currentSlug, $id, $forPublic);
        }

        return $result;
    }

    private function loadRawItems(array $items, int $depth = 1): array
    {
        if ($depth > 3) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $slug = $item['slug'] ?? null;
            if (is_string($slug)) {
                $slug = trim($slug);
                if ($slug === '') {
                    $slug = null;
                }
            } else {
                $slug = null;
            }

            $url = trim((string) ($item['url'] ?? ''));
            $external = !empty($item['external']);
            $label = trim((string) ($item['label'] ?? ''));

            if ($slug !== null) {
                if ($label === '' && $this->pageLoader->exists($slug)) {
                    $page = $this->pageLoader->loadBySlug($slug, false);
                    $label = (string) ($page['front_matter']['title'] ?? $slug);
                }
                if ($label === '') {
                    $label = $slug;
                }
                $url = $slug === 'index' ? '/' : '/' . $slug;
                $external = false;
            } elseif ($url !== '') {
                if ($label === '') {
                    $label = $url;
                }
                if (!$external && $this->looksExternal($url)) {
                    $external = true;
                }
            } else {
                continue;
            }

            $result[] = [
                'label' => $label,
                'slug' => $slug,
                'url' => $url,
                'external' => $external,
                'children' => $this->loadRawItems($item['children'] ?? [], $depth + 1),
            ];
        }

        return $result;
    }

    private function normalizeItems(array $items, string $currentSlug, bool $forPublic): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $slug = $item['slug'] ?? null;
            $external = !empty($item['external']);
            $url = $item['url'] ?? '';

            $node = [
                'label' => $item['label'] ?? '',
                'slug' => $slug,
                'url' => $url,
                'external' => $external,
                'active' => $this->isActive($slug, $currentSlug),
                'broken' => false,
                'children' => $this->normalizeItems($item['children'] ?? [], $currentSlug, $forPublic),
            ];

            if (!$external && $slug !== null && $slug !== '') {
                if (!$this->pageLoader->exists($slug) || !$this->pageLoader->isPublished($slug)) {
                    $node['broken'] = true;
                    if ($forPublic) {
                        continue;
                    }
                }
            }

            $normalized[] = $node;
        }

        return $normalized;
    }

    private function isActive(?string $itemSlug, string $currentSlug): bool
    {
        if ($itemSlug === null || $itemSlug === '') {
            return false;
        }

        if ($itemSlug === $currentSlug) {
            return true;
        }

        if ($currentSlug !== 'index' && str_starts_with($currentSlug, $itemSlug . '/')) {
            return true;
        }

        return false;
    }

    private function looksExternal(string $url): bool
    {
        return (bool) preg_match('#^(https?:)?//#i', $url);
    }
}
