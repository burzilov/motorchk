<?php

use Symfony\Component\Yaml\Yaml;

class MenuWriter
{
    public const DEFAULT_LOCATION = 'main';

    public function __construct(
        private array $config,
        private Cache $cache,
    ) {
    }

    public function loadMenus(): array
    {
        $file = $this->config['menu_file'];
        if (!is_file($file)) {
            return $this->defaultMenus();
        }

        $content = file_get_contents($file);
        if ($content === false || trim($content) === '') {
            return $this->defaultMenus();
        }

        $data = Yaml::parse($content);
        if (!is_array($data) || !isset($data['menus']) || !is_array($data['menus'])) {
            return $this->defaultMenus();
        }

        $menus = $this->sanitizeMenus($data['menus']);
        if ($menus === [] || !isset($menus[self::DEFAULT_LOCATION])) {
            $menus = array_merge($this->defaultMenus(), $menus);
        }

        return $menus;
    }

    public function saveMenus(array $menus): void
    {
        $menus = $this->sanitizeMenus($menus);
        if (!isset($menus[self::DEFAULT_LOCATION])) {
            $menus = array_merge($this->defaultMenus(), $menus);
        }

        $yaml = Yaml::dump(
            ['menus' => $menus],
            6,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
        );
        FileWriter::write($this->config['menu_file'], $yaml);
        $this->invalidateAllMenuCaches();
    }

    public function listLocations(): array
    {
        $result = [];
        foreach ($this->loadMenus() as $id => $menu) {
            $result[] = [
                'id' => $id,
                'label' => $menu['label'],
            ];
        }

        return $result;
    }

    public function getLocationItems(string $id): array
    {
        $menus = $this->loadMenus();

        return $menus[$id]['items'] ?? [];
    }

    public function saveLocationItems(string $id, array $items): void
    {
        if (!$this->isValidLocationId($id)) {
            throw new InvalidArgumentException('Некорректный id меню');
        }

        $menus = $this->loadMenus();
        if (!isset($menus[$id])) {
            throw new InvalidArgumentException('Меню не найдено');
        }

        $menus[$id]['items'] = $this->sanitizeItems($items);
        $this->saveMenus($menus);
    }

    public function createLocation(string $id, string $label): void
    {
        $id = trim($id);
        $label = trim($label);

        if (!$this->isValidLocationId($id)) {
            throw new InvalidArgumentException('Id меню: латиница, цифры, дефис и подчёркивание; начинается с буквы');
        }
        if ($label === '') {
            throw new InvalidArgumentException('Укажите название меню');
        }

        $menus = $this->loadMenus();
        if (isset($menus[$id])) {
            throw new InvalidArgumentException('Меню с таким id уже существует');
        }

        $menus[$id] = [
            'label' => $label,
            'items' => [],
        ];
        $this->saveMenus($menus);
    }

    public function updateLocation(string $id, string $label, ?string $newId = null): void
    {
        $menus = $this->loadMenus();
        if (!isset($menus[$id])) {
            throw new InvalidArgumentException('Меню не найдено');
        }

        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException('Укажите название меню');
        }

        $menus[$id]['label'] = $label;

        if ($newId !== null && $newId !== $id) {
            if ($id === self::DEFAULT_LOCATION) {
                throw new InvalidArgumentException('Нельзя переименовать id меню main');
            }
            if (!$this->isValidLocationId($newId)) {
                throw new InvalidArgumentException('Некорректный новый id меню');
            }
            if (isset($menus[$newId])) {
                throw new InvalidArgumentException('Меню с таким id уже существует');
            }

            $menus[$newId] = $menus[$id];
            unset($menus[$id]);
            $this->cache->delete('menu:tree:' . $id);
        }

        $this->saveMenus($menus);
    }

    public function deleteLocation(string $id): void
    {
        if ($id === self::DEFAULT_LOCATION) {
            throw new InvalidArgumentException('Нельзя удалить меню main');
        }

        $menus = $this->loadMenus();
        if (!isset($menus[$id])) {
            throw new InvalidArgumentException('Меню не найдено');
        }

        unset($menus[$id]);
        $this->saveMenus($menus);
        $this->cache->delete('menu:tree:' . $id);
    }

    public function hasSlug(string $slug): bool
    {
        foreach ($this->loadMenus() as $menu) {
            if ($this->itemsContainSlug($menu['items'], $slug)) {
                return true;
            }
        }

        return false;
    }

    public function removeSlugs(array $slugs): void
    {
        if ($slugs === []) {
            return;
        }

        $slugSet = array_fill_keys($slugs, true);
        $menus = $this->loadMenus();
        $changed = false;

        foreach ($menus as $id => $menu) {
            $filtered = $this->filterItemsBySlugs($menu['items'], $slugSet);
            if ($filtered !== $menu['items']) {
                $menus[$id]['items'] = $filtered;
                $changed = true;
            }
        }

        if ($changed) {
            $this->saveMenus($menus);
        }
    }

    public function renameSlug(string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        $menus = $this->loadMenus();
        $changed = false;

        foreach ($menus as $id => $menu) {
            $renamed = $this->renameSlugInItems($menu['items'], $oldSlug, $newSlug);
            if ($renamed !== $menu['items']) {
                $menus[$id]['items'] = $renamed;
                $changed = true;
            }
        }

        if ($changed) {
            $this->saveMenus($menus);
        }
    }

    public function invalidateAllMenuCaches(): void
    {
        $this->cache->invalidate('menu:tree*');
        $this->cache->delete('menu:tree');
    }

    public function isValidLocationId(string $id): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_-]*$/', $id);
    }

    private function defaultMenus(): array
    {
        return [
            self::DEFAULT_LOCATION => [
                'label' => 'Основное',
                'items' => [],
            ],
        ];
    }

    private function sanitizeMenus(array $menus): array
    {
        $result = [];

        foreach ($menus as $id => $menu) {
            if (!is_string($id) || !$this->isValidLocationId($id) || !is_array($menu)) {
                continue;
            }

            $label = trim((string) ($menu['label'] ?? $id));
            if ($label === '') {
                $label = $id;
            }

            $result[$id] = [
                'label' => $label,
                'items' => $this->sanitizeItems($menu['items'] ?? []),
            ];
        }

        return $result;
    }

    private function sanitizeItems(array $items, int $depth = 1): array
    {
        if ($depth > 3) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $slug = $item['slug'] ?? null;
            $url = trim((string) ($item['url'] ?? ''));
            $external = !empty($item['external']);
            $children = $this->sanitizeItems($item['children'] ?? [], $depth + 1);

            if (is_string($slug)) {
                $slug = trim($slug);
                if ($slug === '') {
                    $slug = null;
                }
            } else {
                $slug = null;
            }

            if ($slug !== null) {
                $node = [
                    'label' => $label,
                    'slug' => $slug,
                ];
            } elseif ($url !== '') {
                $node = [
                    'label' => $label !== '' ? $label : $url,
                    'url' => $url,
                ];
                if ($external || $this->looksExternal($url)) {
                    $node['external'] = true;
                }
            } else {
                continue;
            }

            if ($children !== []) {
                $node['children'] = $children;
            }

            $result[] = $node;
        }

        return $result;
    }

    private function looksExternal(string $url): bool
    {
        return (bool) preg_match('#^(https?:)?//#i', $url);
    }

    private function itemsContainSlug(array $items, string $slug): bool
    {
        foreach ($items as $item) {
            if (($item['slug'] ?? null) === $slug) {
                return true;
            }
            if ($this->itemsContainSlug($item['children'] ?? [], $slug)) {
                return true;
            }
        }

        return false;
    }

    private function filterItemsBySlugs(array $items, array $slugSet): array
    {
        $result = [];

        foreach ($items as $item) {
            $slug = $item['slug'] ?? null;
            if (is_string($slug) && isset($slugSet[$slug])) {
                continue;
            }

            $children = $this->filterItemsBySlugs($item['children'] ?? [], $slugSet);
            if ($children !== []) {
                $item['children'] = $children;
            } else {
                unset($item['children']);
            }

            $result[] = $item;
        }

        return $result;
    }

    private function renameSlugInItems(array $items, string $oldSlug, string $newSlug): array
    {
        $result = [];

        foreach ($items as $item) {
            if (($item['slug'] ?? null) === $oldSlug) {
                $item['slug'] = $newSlug;
            }
            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->renameSlugInItems($item['children'], $oldSlug, $newSlug);
            }
            $result[] = $item;
        }

        return $result;
    }
}
