<?php

use Symfony\Component\Yaml\Yaml;

class MenuWriter
{
    public function __construct(
        private array $config,
        private Cache $cache,
    ) {
    }

    public function loadExternal(): array
    {
        if (!is_file($this->config['menu_file'])) {
            return [];
        }

        $content = file_get_contents($this->config['menu_file']);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = Yaml::parse($content);
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['external']) && is_array($data['external'])) {
            return $this->sanitizeExternal($data['external']);
        }

        return [];
    }

    public function saveExternal(array $external): void
    {
        $yaml = Yaml::dump(
            ['external' => $this->sanitizeExternal($external)],
            4,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
        );
        FileWriter::write($this->config['menu_file'], $yaml);
        $this->cache->delete('menu:tree');
    }

    public function migrateLegacyIfNeeded(PageWriter $pageWriter, PageTree $pageTree): bool
    {
        if (!is_file($this->config['menu_file'])) {
            return false;
        }

        $content = file_get_contents($this->config['menu_file']);
        if ($content === false || trim($content) === '') {
            return false;
        }

        $data = Yaml::parse($content);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return false;
        }

        $legacyItems = $data['items'];
        $external = [];

        $this->extractExternal($legacyItems, $external);
        $this->applyLegacyOrders($legacyItems, null, $pageWriter);
        $this->applyLegacyMenuFlags($legacyItems, $pageTree, $pageWriter);

        $this->saveExternal($external);
        $pageTree->invalidateCache();
        $this->cache->delete('menu:tree');

        return true;
    }

    public function removeSlugs(array $slugs): void
    {
        // Внутреннее меню строится из страниц; в yaml остаются только внешние ссылки.
    }

    public function renameSlug(string $oldSlug, string $newSlug): void
    {
        // Внутреннее меню строится из страниц.
    }

    public function hasSlug(string $slug): bool
    {
        return false;
    }

    private function sanitizeExternal(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }

            $result[] = [
                'label' => $label,
                'url' => $url,
            ];
        }

        return $result;
    }

    private function extractExternal(array $items, array &$external): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['external'])) {
                $label = trim((string) ($item['label'] ?? ''));
                $url = trim((string) ($item['url'] ?? ''));
                if ($label !== '' && $url !== '') {
                    $external[] = [
                        'label' => $label,
                        'url' => $url,
                    ];
                }
                continue;
            }

            $this->extractExternal($item['children'] ?? [], $external);
        }
    }

    private function applyLegacyOrders(array $items, ?string $parent, PageWriter $pageWriter): void
    {
        $slugs = [];
        foreach ($items as $item) {
            if (!is_array($item) || !empty($item['external'])) {
                continue;
            }

            $slug = $item['slug'] ?? null;
            if (!is_string($slug) || $slug === '') {
                continue;
            }

            $slugs[] = $slug;
            $this->applyLegacyOrders($item['children'] ?? [], $slug, $pageWriter);
        }

        if ($slugs !== []) {
            $pageWriter->updateSiblingOrder($parent, $slugs);
        }
    }

    private function applyLegacyMenuFlags(array $items, PageTree $pageTree, PageWriter $pageWriter): void
    {
        $inMenu = [];
        $this->collectLegacySlugs($items, $inMenu);

        foreach ($pageTree->getAllSlugs() as $slug) {
            $pageWriter->updateMenuVisibility($slug, in_array($slug, $inMenu, true));
        }
    }

    private function collectLegacySlugs(array $items, array &$slugs): void
    {
        foreach ($items as $item) {
            if (!is_array($item) || !empty($item['external'])) {
                continue;
            }

            $slug = $item['slug'] ?? null;
            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }

            $this->collectLegacySlugs($item['children'] ?? [], $slugs);
        }
    }
}
