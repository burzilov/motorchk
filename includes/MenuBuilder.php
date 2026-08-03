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

    public function build(string $currentSlug, bool $forPublic = true): array
    {
        $cacheKey = 'menu:tree';
        $cached = $this->cache->get($cacheKey);

        if (!is_array($cached)) {
            $cached = $this->loadRawItems();
            $this->cache->set($cacheKey, $cached);
        }

        return $this->normalizeItems($cached, $currentSlug, $forPublic);
    }

    private function loadRawItems(): array
    {
        $internal = $this->buildInternalItems(null, 1);
        $external = $this->wrapExternalItems($this->menuWriter->loadExternal());

        return array_merge($internal, $external);
    }

    private function buildInternalItems(?string $parent, int $depth): array
    {
        if ($depth > 3) {
            return [];
        }

        $items = [];
        foreach ($this->pageTree->getChildren($parent) as $page) {
            if (empty($page['published']) || empty($page['menu'])) {
                continue;
            }

            $slug = $page['slug'];
            $items[] = [
                'label' => $page['title'],
                'slug' => $slug,
                'url' => $slug === 'index' ? '/' : '/' . $slug,
                'external' => false,
                'children' => $this->buildInternalItems($slug, $depth + 1),
            ];
        }

        return $items;
    }

    private function wrapExternalItems(array $external): array
    {
        $items = [];
        foreach ($external as $link) {
            $items[] = [
                'label' => $link['label'],
                'slug' => null,
                'url' => $link['url'],
                'external' => true,
                'children' => [],
            ];
        }

        return $items;
    }

    private function normalizeItems(array $items, string $currentSlug, bool $forPublic): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $slug = $item['slug'] ?? null;
            $external = !empty($item['external']);
            $url = $item['url'] ?? ($slug === 'index' ? '/' : '/' . $slug);

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
}
