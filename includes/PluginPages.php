<?php

/**
 * Доступ плагина к страницам сайта: PluginContext::pages().
 * Частичное обновление front matter без затирания остальных полей и содержимого.
 */
final class PluginPages
{
    private PageLoader $loader;

    private PageWriter $writer;

    private PageTree $tree;

    public function __construct(
        private array $config,
        private PluginManager $plugins,
        private string $pluginId,
    ) {
        $cache = new Cache((string) ($config['cache_path'] ?? ''));
        $renderer = new MarkdownRenderer();
        $this->loader = new PageLoader($config, $cache, $renderer);
        $this->tree = new PageTree($config, $cache, $this->loader, $renderer);
        $menuWriter = new MenuWriter($config, $cache);
        $this->writer = new PageWriter($config, $cache, $this->loader, $this->tree, $renderer, $menuWriter);
    }

    /**
     * Загрузить страницу (включая неопубликованные) или null.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $slug): ?array
    {
        return $this->loader->loadBySlug($slug, false);
    }

    /**
     * Создать черновик страницы. Возвращает полный slug.
     */
    public function create(string $title, string $leafSlug, ?string $parent, string $template = 'default'): string
    {
        return $this->writer->create($title, $leafSlug, $parent, $template);
    }

    /**
     * Частично обновить front matter: меняются только переданные ключи.
     * Нельзя менять slug/parent/created_at (для переименования — save ядра).
     * Содержимое Markdown-блоков не затрагивается.
     *
     * @param array<string, mixed> $meta
     */
    public function updateMeta(string $slug, array $meta): void
    {
        $this->writer->updateMeta($slug, $meta);
    }

    public function delete(string $slug): array
    {
        return $this->writer->delete($slug);
    }

    /**
     * Непосредственные дочерние страницы (включая неопубликованные).
     *
     * @return list<array<string, mixed>>
     */
    public function children(string $parentSlug): array
    {
        $nodes = [];
        $locales = SiteLocale::locales();
        $extraLocales = array_values(array_filter(
            $locales,
            static fn(string $code): bool => $code !== SiteLocale::default()
        ));
        foreach ($this->tree->getFlatNodesForSelect() as $node) {
            $slug = (string) ($node['slug'] ?? '');
            if ($slug === '' || $slug === 'index') {
                continue;
            }
            // Переводы (about.en) — не отдельные страницы каталога
            if ($extraLocales !== [] && PageLoader::translationLocale($slug, $extraLocales) !== null) {
                continue;
            }
            if (str_contains(Slugifier::leafFromSlug($slug), '.')) {
                continue;
            }
            if (Slugifier::parentFromSlug($slug) !== $parentSlug) {
                continue;
            }
            $page = $this->load($slug);
            if ($page !== null) {
                $nodes[] = $page;
            }
        }

        usort($nodes, static function (array $a, array $b): int {
            $ao = (int) ($a['front_matter']['order'] ?? 0);
            $bo = (int) ($b['front_matter']['order'] ?? 0);
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }

            return strcmp((string) ($a['front_matter']['title'] ?? ''), (string) ($b['front_matter']['title'] ?? ''));
        });

        return $nodes;
    }
}