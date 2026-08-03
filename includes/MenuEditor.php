<?php

class MenuEditor
{
    public function __construct(
        private PageTree $pageTree,
        private PageWriter $pageWriter,
        private MenuWriter $menuWriter,
        private Cache $cache,
    ) {
    }

    public function getParentOptions(): array
    {
        $options = [
            ['slug' => '', 'title' => 'Корень сайта'],
        ];

        foreach ($this->pageTree->getFlatNodesForSelect() as $node) {
            if ($node['slug'] === 'index') {
                continue;
            }
            $options[] = [
                'slug' => $node['slug'],
                'title' => $node['title'],
            ];
        }

        return $options;
    }

    public function getBranch(?string $parent): array
    {
        return $this->pageTree->getMenuBranch($parent);
    }

    public function save(array $orders, array $menuFlags, array $menuSlugs, array $external): void
    {
        foreach ($orders as $parent => $slugs) {
            if (!is_array($slugs)) {
                continue;
            }
            $parentKey = $parent === '__root__' ? null : (string) $parent;
            $this->pageWriter->updateSiblingOrder($parentKey, $slugs);
        }

        foreach ($menuSlugs as $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            $this->pageWriter->updateMenuVisibility($slug, !empty($menuFlags[$slug]));
        }

        $this->menuWriter->saveExternal($external);
        $this->cache->delete('menu:tree');
        $this->cache->delete('pages:tree');
    }
}
