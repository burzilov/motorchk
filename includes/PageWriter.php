<?php

class PageWriter
{
    public function __construct(
        private array $config,
        private Cache $cache,
        private PageLoader $pageLoader,
        private PageTree $pageTree,
        private MarkdownRenderer $renderer,
        private ?MenuWriter $menuWriter = null,
    ) {
    }

    public function setMenuWriter(MenuWriter $menuWriter): void
    {
        $this->menuWriter = $menuWriter;
    }

    public function create(string $title, string $leafSlug, ?string $parent, string $template = 'default'): string
    {
        if (!Slugifier::isValidLeaf($leafSlug)) {
            throw new InvalidArgumentException('Некорректный slug');
        }

        $fullSlug = Slugifier::buildFullSlug($parent, $leafSlug);
        $filePath = $this->pageLoader->slugToPath($fullSlug);

        if (is_file($filePath)) {
            throw new InvalidArgumentException('Страница с таким slug уже существует');
        }

        $now = date('c');
        $frontMatter = [
            'title' => $title,
            'slug' => $fullSlug,
            'parent' => $parent,
            'order' => 0,
            'template' => $template,
            'published' => false,
            'description' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image' => '',
            'scripts' => [],
            'menu' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $rawBlocks = [
            'content' => "Текст страницы. Замените этот абзац.",
        ];

        FileWriter::write($filePath, $this->renderer->serialize($frontMatter, $rawBlocks));
        $this->invalidateAfterSave($fullSlug, false);

        return $fullSlug;
    }

    public function save(string $slug, array $data, ?string $body = null): void
    {
        $oldSlug = $slug;
        $leafSlug = $data['leaf_slug'] ?? Slugifier::leafFromSlug($slug);
        $parent = $data['parent'] ?? Slugifier::parentFromSlug($slug);

        if ($slug === 'index') {
            $newSlug = 'index';
            $parent = null;
        } else {
            if (!Slugifier::isValidLeaf($leafSlug)) {
                throw new InvalidArgumentException('Некорректный slug');
            }
            $newSlug = Slugifier::buildFullSlug($parent, $leafSlug);
        }

        $oldPath = $this->pageLoader->slugToPath($oldSlug);
        if (!is_file($oldPath)) {
            throw new RuntimeException('Страница не найдена');
        }

        $existing = $this->renderer->parseFile($oldPath);
        $frontMatter = $existing['front_matter'];
        $rawBlocks = $body === null
            ? $existing['blocks']
            : $this->renderer->parse($body)['blocks'];

        $frontMatter['title'] = $data['title'] ?? $frontMatter['title'] ?? $newSlug;
        $frontMatter['slug'] = $newSlug;
        $frontMatter['parent'] = $parent;
        $frontMatter['order'] = (int) ($data['order'] ?? $frontMatter['order'] ?? 0);
        $frontMatter['template'] = $data['template'] ?? $frontMatter['template'] ?? 'default';
        $frontMatter['published'] = !empty($data['published']);
        $frontMatter['description'] = $data['description'] ?? $frontMatter['description'] ?? '';
        $frontMatter['og_title'] = $data['og_title'] ?? $frontMatter['og_title'] ?? '';
        $frontMatter['og_description'] = $data['og_description'] ?? $frontMatter['og_description'] ?? '';
        $frontMatter['og_image'] = $data['og_image'] ?? $frontMatter['og_image'] ?? '';
        $frontMatter['scripts'] = PageScripts::normalize($data['scripts'] ?? $frontMatter['scripts'] ?? []);
        if (array_key_exists('menu', $data)) {
            $frontMatter['menu'] = !empty($data['menu']);
        }
        $frontMatter['updated_at'] = date('c');
        $frontMatter['created_at'] = $frontMatter['created_at'] ?? date('c');

        $originalSlug = $oldSlug;
        if ($newSlug !== $oldSlug) {
            $this->rename($oldSlug, $newSlug);
        }

        $filePath = $this->pageLoader->slugToPath($newSlug);
        FileWriter::write($filePath, $this->renderer->serialize($frontMatter, $rawBlocks));
        $this->invalidateAfterSave($newSlug, true, $originalSlug !== $newSlug ? $originalSlug : null);
    }

    public function saveBlock(string $slug, string $blockName, string $markdown): void
    {
        if (!preg_match('/^\w+$/', $blockName)) {
            throw new InvalidArgumentException('Некорректное имя блока');
        }

        $filePath = $this->pageLoader->slugToPath($slug);
        if (!is_file($filePath)) {
            throw new RuntimeException('Страница не найдена');
        }

        $existing = $this->renderer->parseFile($filePath);
        $frontMatter = $existing['front_matter'];
        $rawBlocks = $existing['blocks'];
        $rawBlocks[$blockName] = $markdown;
        $frontMatter['updated_at'] = date('c');

        FileWriter::write($filePath, $this->renderer->serialize($frontMatter, $rawBlocks));
        $this->invalidateAfterSave($slug, true);
    }

    public function rename(string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        $oldPath = $this->pageLoader->slugToPath($oldSlug);
        $newPath = $this->pageLoader->slugToPath($newSlug);

        if (!is_file($oldPath)) {
            throw new RuntimeException('Страница не найдена');
        }

        if (is_file($newPath)) {
            throw new InvalidArgumentException('Целевой slug уже занят');
        }

        $dir = dirname($newPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать директорию');
        }

        rename($oldPath, $newPath);

        $descendants = $this->pageTree->collectDescendantSlugs($oldSlug);
        foreach ($descendants as $childSlug) {
            $childNewSlug = preg_replace('#^' . preg_quote($oldSlug, '#') . '/#', $newSlug . '/', $childSlug);
            $childOldPath = $this->pageLoader->slugToPath($childSlug);
            $childNewPath = $this->pageLoader->slugToPath($childNewSlug);

            $childDir = dirname($childNewPath);
            if (!is_dir($childDir) && !mkdir($childDir, 0775, true) && !is_dir($childDir)) {
                throw new RuntimeException('Не удалось создать директорию');
            }

            rename($childOldPath, $childNewPath);

            $parsed = $this->renderer->parseFile($childNewPath);
            $fm = $parsed['front_matter'];
            $fm['slug'] = $childNewSlug;
            $fm['parent'] = Slugifier::parentFromSlug($childNewSlug);
            $fm['updated_at'] = date('c');
            FileWriter::write($childNewPath, $this->renderer->serialize($fm, $parsed['blocks']));

            $this->cache->delete('page:' . $childSlug);
            $this->cache->delete('page:' . $childNewSlug);
        }

        if ($this->menuWriter) {
            $this->menuWriter->renameSlug($oldSlug, $newSlug);
            foreach ($descendants as $childSlug) {
                $childNewSlug = preg_replace('#^' . preg_quote($oldSlug, '#') . '/#', $newSlug . '/', $childSlug);
                $this->menuWriter->renameSlug($childSlug, $childNewSlug);
            }
        }

        $this->cache->delete('page:' . $oldSlug);
        $this->cache->delete('page:' . $newSlug);
        $this->cache->delete('pages:tree');
        $this->cache->delete('menu:tree');
    }

    public function delete(string $slug): array
    {
        $deletedSlugs = array_merge([$slug], $this->pageTree->collectDescendantSlugs($slug));

        foreach (array_reverse($deletedSlugs) as $deleteSlug) {
            $path = $this->pageLoader->slugToPath($deleteSlug);
            if (is_file($path)) {
                unlink($path);
            }
            $this->cache->delete('page:' . $deleteSlug);
        }

        $this->cleanupEmptyDirs($this->config['content_path']);

        if ($this->menuWriter) {
            $this->menuWriter->removeSlugs($deletedSlugs);
        }

        $this->cache->delete('pages:tree');
        $this->cache->delete('menu:tree');

        return $deletedSlugs;
    }

    public function updateSiblingOrder(?string $parent, array $slugsInOrder): void
    {
        $position = 0;
        foreach ($slugsInOrder as $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }

            $nodeParent = Slugifier::parentFromSlug($slug);
            if ($nodeParent !== $parent) {
                continue;
            }

            $this->updatePageField($slug, 'order', $position * 10);
            $position++;
        }

        $this->cache->delete('pages:tree');
    }

    public function updateMenuVisibility(string $slug, bool $inMenu): void
    {
        $this->updatePageField($slug, 'menu', $inMenu);
        $this->cache->delete('pages:tree');
        $this->cache->delete('menu:tree');
    }

    private function updatePageField(string $slug, string $field, mixed $value): void
    {
        $path = $this->pageLoader->slugToPath($slug);
        if (!is_file($path)) {
            return;
        }

        $parsed = $this->renderer->parseFile($path);
        $frontMatter = $parsed['front_matter'];
        $frontMatter[$field] = $value;
        $frontMatter['updated_at'] = date('c');
        FileWriter::write($path, $this->renderer->serialize($frontMatter, $parsed['blocks']));
        $this->cache->delete('page:' . $slug);
    }

    private function invalidateAfterSave(string $slug, bool $checkMenu, ?string $oldSlug = null): void
    {
        $this->cache->delete('page:' . $slug);
        if ($oldSlug) {
            $this->cache->delete('page:' . $oldSlug);
        }
        $this->cache->delete('pages:tree');
        $this->cache->delete('menu:tree');
    }

    private function cleanupEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->cleanupEmptyDirs($path);
                if ($this->isDirEmpty($path)) {
                    rmdir($path);
                }
            }
        }
    }

    private function isDirEmpty(string $dir): bool
    {
        $items = scandir($dir);

        return $items !== false && count($items) <= 2;
    }
}
