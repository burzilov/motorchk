<?php

class Router
{
    private Cache $cache;
    private MarkdownRenderer $renderer;
    private PageLoader $pageLoader;
    private PageTree $pageTree;
    private MenuBuilder $menuBuilder;

    public function __construct(private array $config)
    {
        $this->cache = new Cache($config['cache_path']);
        $this->renderer = new MarkdownRenderer();
        $this->pageLoader = new PageLoader($config, $this->cache, $this->renderer);
        $this->pageTree = new PageTree($config, $this->cache, $this->pageLoader, $this->renderer);
        $menuWriter = new MenuWriter($config, $this->cache);
        $this->menuBuilder = new MenuBuilder($config, $this->cache, $this->pageLoader, $this->pageTree, $menuWriter);
    }

    public function dispatchPublic(string $requestUri): void
    {
        $slug = $this->normalizeSlug($requestUri);
        $filePath = $this->pageLoader->slugToPath($slug);
        $isPreview = ($_GET['preview'] ?? '') === '1';

        if (!is_file($filePath)) {
            $this->render404();
            return;
        }

        if ($isPreview) {
            if (empty($_SESSION['admin_authenticated'])) {
                $this->render404();
                return;
            }

            $page = $this->pageLoader->loadBySlug($slug, false);
            if ($page === null) {
                $this->render404();
                return;
            }

            header('X-Robots-Tag: noindex');
            $menu = $this->menuBuilder->build($slug, true);
            $this->renderPage($page, $menu, $slug, true);
            return;
        }

        $page = $this->pageLoader->loadBySlug($slug, true);
        if ($page === null) {
            $this->render404();
            return;
        }

        $menu = $this->menuBuilder->build($slug, true);
        $this->renderPage($page, $menu, $slug, false);
    }

    private function normalizeSlug(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        $path = trim($path, '/');

        return $path === '' ? 'index' : $path;
    }

    private function renderPage(array $page, array $menu, string $slug, bool $isPreview = false): void
    {
        $frontMatter = $page['front_matter'];
        $blocks = $page['blocks'];
        $template = $frontMatter['template'] ?? 'default';
        $templateFile = $this->config['templates_path'] . '/' . $template . '.php';

        if (!is_file($templateFile)) {
            $templateFile = $this->config['templates_path'] . '/default.php';
        }

        $title = $frontMatter['title'] ?? 'motorchk';
        $description = $frontMatter['description'] ?? '';
        $ogTitle = $frontMatter['og_title'] ?: $title;
        $ogDescription = $frontMatter['og_description'] ?: $description;
        $ogImage = $frontMatter['og_image'] ?? '';
        $scripts = PageScripts::normalize($frontMatter['scripts'] ?? []);
        $pagePublished = !empty($frontMatter['published']);
        $adminEditUrl = AdminUrl::page($slug);
        $canEdit = !empty($_SESSION['admin_authenticated']);
        $inlineEditSaveUrl = $canEdit ? AdminUrl::pageBlocks($slug) : '';
        $inlineEditCsrf = $canEdit ? Csrf::token() : '';

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');

        require $templateFile;
    }

    private function render404(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>404</title></head><body><h1>Страница не найдена</h1></body></html>';
    }
}
