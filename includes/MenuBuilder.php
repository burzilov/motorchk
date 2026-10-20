<?php

class MenuBuilder
{
    /** Язык перевода для подписей из заголовков страниц; null — основной (как до 0.4.0). */
    private ?string $locale = null;

    public function __construct(
        private array $config,
        private Cache $cache,
        private PageLoader $pageLoader,
        private PageTree $pageTree,
        private MenuWriter $menuWriter,
    ) {
    }

    /**
     * Подписи пунктов без label берутся из заголовка перевода страницы (about.en.md), если он есть.
     * Кеш меню — отдельно для каждого языка, сверяется с отпечатком content/ (PageLoader::contentStamp()).
     */
    public function setLocale(?string $locale): void
    {
        $this->locale = $locale !== '' ? $locale : null;
    }

    public function build(string $currentSlug, string $menuId = MenuWriter::DEFAULT_LOCATION, bool $forPublic = true): array
    {
        $menus = $this->menuWriter->loadMenus();
        if (!isset($menus[$menuId])) {
            $menuId = MenuWriter::DEFAULT_LOCATION;
        }

        // подписи берутся из заголовков страниц, пункты — из _menus.yaml: кеш сверяется с отпечатком content/
        $cacheKey = 'menu:tree:' . $menuId . ($this->locale !== null ? '@' . $this->locale : '');
        $stamp = $this->pageLoader->contentStamp();
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp && is_array($cached['items'] ?? null)) {
            $items = $cached['items'];
        } else {
            $items = $this->loadRawItems($menus[$menuId]['items'] ?? []);
            $this->cache->set($cacheKey, ['stamp' => $stamp, 'items' => $items]);
        }

        return $this->normalizeItems($items, $currentSlug, $forPublic);
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
                if ($label === '' && $this->locale !== null) {
                    $translated = $this->pageLoader->loadBySlug($slug, true, $this->locale);
                    $label = trim((string) ($translated['front_matter']['title'] ?? ''));
                }
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
                'active' => $this->isActive($this->target($slug, $url, $external), $currentSlug),
                'current' => $this->target($slug, $url, $external) === $currentSlug,
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

    /**
     * Страница, на которую ведёт пункт: slug или путь внутреннего url (/customizer/ → customizer,
     * / → index); для внешних ссылок и якорей — null.
     */
    private function target(?string $slug, string $url, bool $external): ?string
    {
        if ($slug !== null && $slug !== '') {
            return $slug;
        }
        if ($external || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === '' ? 'index' : $path;
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
