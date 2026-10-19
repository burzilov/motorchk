<?php

class Router
{
    private Cache $cache;
    private MarkdownRenderer $renderer;
    private PageLoader $pageLoader;
    private PageTree $pageTree;
    private MenuBuilder $menuBuilder;
    private ?PluginManager $pluginManager = null;

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
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($path === '/api/forms/submit') {
            if ($method !== 'POST') {
                $this->jsonResponse(405, ['ok' => false, 'errors' => ['_form' => 'Метод не поддерживается']]);
                return;
            }
            $this->handleFormSubmit();
            return;
        }

        if ($this->dispatchPlugins($path, $method)) {
            return;
        }

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
            $this->renderPage($page, $slug, true);
            return;
        }

        $page = $this->pageLoader->loadBySlug($slug, true);
        if ($page === null) {
            $this->render404();
            return;
        }

        $this->renderPage($page, $slug, false);
    }

    /**
     * Статика /plugin-assets/{id}/... и публичные префиксы плагинов (приоритет над Markdown-страницами).
     * Без папки plugins/ ничего не делает.
     */
    private function dispatchPlugins(string $path, string $method): bool
    {
        $manager = $this->pluginManager ??= new PluginManager($this->config);
        if (!$manager->hasPluginsDir()) {
            return false;
        }

        if (str_starts_with($path, '/plugin-assets/')) {
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                http_response_code(405);
                header('Allow: GET, HEAD');
                return true;
            }
            $manager->serveAsset(rawurldecode(substr($path, strlen('/plugin-assets/'))));
            return true;
        }

        $trimmed = trim($path, '/');
        $segment = explode('/', $trimmed, 2)[0];
        $owner = $manager->prefixOwner($segment);
        if ($owner === null) {
            return false;
        }

        $relative = '/' . (explode('/', $trimmed, 2)[1] ?? '');
        $context = $manager->boot($owner);
        if ($context === null) {
            $this->render500('Плагин временно недоступен');
            return true;
        }

        $context->setPublicRenderer(function (string $html, array $options) use ($trimmed): void {
            $this->renderPluginPage($html, $options, $trimmed === '' ? 'index' : $trimmed);
        });

        try {
            $status = $manager->handle($context, 'public', $method, $relative, true);
        } catch (Throwable $e) {
            error_log('motorchk plugin ' . $owner . ': ' . $e->getMessage());
            if (!headers_sent()) {
                $this->render500('Ошибка плагина');
            }
            return true;
        }

        if ($status === 403) {
            $this->jsonResponse(403, ['ok' => false, 'error' => 'CSRF validation failed']);
        } elseif ($status === 405) {
            http_response_code(405);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Method not allowed';
        } elseif ($status === 404) {
            $this->render404();
        }

        return true;
    }

    /**
     * HTML плагина в шаблоне активной темы ($blocks['content']).
     *
     * @param array<string, mixed> $options
     */
    private function renderPluginPage(string $html, array $options, string $slug): void
    {
        $page = [
            'front_matter' => [
                'title' => (string) ($options['title'] ?? 'motorchk'),
                'description' => (string) ($options['description'] ?? ''),
                'template' => (string) ($options['template'] ?? 'default'),
                'og_title' => (string) ($options['og_title'] ?? ''),
                'og_description' => (string) ($options['og_description'] ?? ''),
                'og_image' => (string) ($options['og_image'] ?? ''),
                'scripts' => $options['scripts'] ?? [],
                'published' => true,
                'menu_location' => (string) ($options['menu_location'] ?? MenuWriter::DEFAULT_LOCATION),
            ],
            'blocks' => [
                'hero' => (string) ($options['hero'] ?? ''),
                'content' => $html,
            ],
        ];

        $this->renderPage($page, $slug, false, [
            'editable' => false,
            'status' => (int) ($options['status'] ?? 200),
            'vars' => (array) ($options['vars'] ?? []),
        ]);
    }

    private function handleFormSubmit(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $input = $_POST;
        if (str_contains(strtolower($contentType), 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $input = is_array($decoded) ? $decoded : [];
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $result = (new FormSubmitHandler($this->config))->handle($input, $ip);
        $status = (int) ($result['status'] ?? 500);
        unset($result['status']);
        $this->jsonResponse($status, $result);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function normalizeSlug(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        $path = trim($path, '/');

        return $path === '' ? 'index' : $path;
    }

    /**
     * @param array{editable?: bool, status?: int, vars?: array<string, mixed>} $options
     */
    private function renderPage(array $page, string $slug, bool $isPreview = false, array $options = []): void
    {
        $frontMatter = $page['front_matter'];
        $blocks = $page['blocks'];
        $themeName = SiteMeta::getTheme($this->config);
        $themePath = $this->config['themes_path'] . '/' . $themeName;
        if (!is_dir($themePath)) {
            $themeName = 'example';
            $themePath = $this->config['themes_path'] . '/' . $themeName;
        }

        if (!is_dir($themePath)) {
            $this->render500('Тема по умолчанию не найдена');
            return;
        }

        $templateName = (string) ($frontMatter['template'] ?? 'default');
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $templateName)) {
            $this->render500('Некорректное имя шаблона страницы');
            return;
        }
        $templateFile = $themePath . '/' . $templateName . '.php';
        if (!is_file($templateFile)) {
            $this->render500('Шаблон страницы не найден');
            return;
        }
        $themeAssetsUrl = '/themes/' . $themeName . '/assets';

        $menus = $this->menuBuilder->buildAll($slug, true);
        $menuId = trim((string) ($frontMatter['menu_location'] ?? MenuWriter::DEFAULT_LOCATION));
        if ($menuId === '' || !array_key_exists($menuId, $menus)) {
            $menuId = MenuWriter::DEFAULT_LOCATION;
        }
        $menu = $menus[$menuId] ?? $menus[MenuWriter::DEFAULT_LOCATION] ?? [];

        $title = $frontMatter['title'] ?? 'motorchk';
        $description = $frontMatter['description'] ?? '';
        $ogTitle = $frontMatter['og_title'] ?: $title;
        $ogDescription = $frontMatter['og_description'] ?: $description;
        $ogImage = $frontMatter['og_image'] ?? '';
        $scripts = PageScripts::normalize($frontMatter['scripts'] ?? []);
        $pagePublished = !empty($frontMatter['published']);
        $adminEditUrl = AdminUrl::page($slug);
        $canEdit = ($options['editable'] ?? true) && !empty($_SESSION['admin_authenticated']);
        $pluginVars = $options['vars'] ?? [];
        $inlineEditSaveUrl = $canEdit ? AdminUrl::pageBlocks($slug) : '';
        $inlineEditCsrf = $canEdit ? Csrf::token() : '';
        $config = $this->config;
        Form::setConfig($this->config);

        http_response_code((int) ($options['status'] ?? 200));
        header('Content-Type: text/html; charset=UTF-8');

        require $templateFile;
    }

    private function render404(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>404</title></head><body><h1>Страница не найдена</h1></body></html>';
    }

    private function render500(string $message): void
    {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>500</title></head><body><h1>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</h1></body></html>';
    }
}
