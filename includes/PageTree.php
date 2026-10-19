<?php

class PageTree
{
    public function __construct(
        private array $config,
        private Cache $cache,
        private PageLoader $pageLoader,
        private MarkdownRenderer $renderer,
    ) {
    }

    public function getTree(): array
    {
        $cached = $this->cache->get('pages:tree');
        if (is_array($cached)) {
            return $this->withoutTranslationsTree($cached);
        }

        $tree = $this->buildTree();
        $this->cache->set('pages:tree', $tree);

        return $this->withoutTranslationsTree($tree);
    }

    public function getChildren(?string $parent, bool $withTranslations = false): array
    {
        $tree = $this->getFlatNodes($withTranslations);
        $children = [];

        foreach ($tree as $node) {
            $nodeParent = $node['parent'] ?? null;
            if ($nodeParent === $parent || ($parent === null && ($nodeParent === null || $nodeParent === ''))) {
                $children[] = $node;
            }
        }

        usort($children, fn(array $a, array $b) => ($a['order'] <=> $b['order']) ?: strcmp($a['title'], $b['title']));

        return $children;
    }

    public function countDescendants(string $slug): int
    {
        $count = 0;
        foreach ($this->collectDescendantSlugs($slug) as $_) {
            $count++;
        }

        return $count;
    }

    public function collectDescendantSlugs(string $slug): array
    {
        $descendants = [];
        $children = $this->getChildren($slug);

        foreach ($children as $child) {
            $descendants[] = $child['slug'];
            $descendants = array_merge($descendants, $this->collectDescendantSlugs($child['slug']));
        }

        return $descendants;
    }

    public function getAllSlugs(): array
    {
        return array_map(fn(array $node) => $node['slug'], $this->getFlatNodes());
    }

    public function getPublishedPages(): array
    {
        $pages = [];
        foreach ($this->getFlatNodes() as $node) {
            if (!empty($node['published'])) {
                $pages[] = $node;
            }
        }

        return $pages;
    }

    public function getFlatNodesForSelect(): array
    {
        $nodes = $this->getFlatNodes();
        usort($nodes, fn(array $a, array $b) => strcmp($a['slug'], $b['slug']));

        return $nodes;
    }

    public function invalidateCache(): void
    {
        $this->cache->delete('pages:tree');
    }

    private function buildTree(): array
    {
        return $this->buildBranch(null);
    }

    private function buildBranch(?string $parent): array
    {
        $branch = [];
        foreach ($this->getChildren($parent, true) as $node) {
            $node['children'] = $this->buildBranch($node['slug']);
            $branch[] = $node;
        }

        return $branch;
    }

    /**
     * Файлы переводов (about.en.md) не страницы, пока активна служба i18n. В кеше 'pages:tree'
     * дерево хранится полным, переводы убираются при чтении — PageWriter сбрасывает тот же ключ.
     */
    private function withoutTranslationsTree(array $tree): array
    {
        $skip = SiteLocale::translationLocales();
        if ($skip === []) {
            return $tree;
        }
        $out = [];
        foreach ($tree as $node) {
            if (PageLoader::translationLocale((string) ($node['slug'] ?? ''), $skip) !== null) {
                continue;
            }
            $node['children'] = $this->withoutTranslationsTree($node['children'] ?? []);
            $out[] = $node;
        }

        return $out;
    }

    private function getFlatNodes(bool $withTranslations = false): array
    {
        $skip = $withTranslations ? [] : SiteLocale::translationLocales();
        $nodes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->config['content_path'], FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $basename = $file->getBasename('.md');
            if (str_starts_with($basename, '_')) {
                continue;
            }

            $parsed = $this->renderer->parseFile($file->getPathname());
            $slug = $this->pageLoader->pathToSlug($file->getPathname());
            $frontMatter = $parsed['front_matter'];

            if ($skip !== [] && PageLoader::translationLocale($slug, $skip) !== null) {
                continue;
            }

            $nodes[] = [
                'slug' => $slug,
                'title' => $frontMatter['title'] ?? $slug,
                'parent' => Slugifier::parentFromSlug($slug),
                'order' => (int) ($frontMatter['order'] ?? 0),
                'published' => array_key_exists('published', $frontMatter) ? (bool) $frontMatter['published'] : true,
                'template' => $frontMatter['template'] ?? 'default',
            ];
        }

        return $nodes;
    }
}
