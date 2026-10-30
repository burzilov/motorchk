<?php

class Router
{
    /** Шаблон темы для 404 публичных страниц: themes/{тема}/404.php. */
    private const NOT_FOUND_TEMPLATE = '404';

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
        SiteLocale::reset();

        // Режим обслуживания: до SEO-файлов, хуков плагинов, API форм и плагин-роутов (docs/14-maintenance.md)
        if (Maintenance::gate($this->config, $path, $method)) {
            return;
        }

        // /sitemap.xml, /robots.txt, /llms.txt — до хука «request»: без языковых префиксов и редиректов
        $seoKind = SeoFiles::kind($path);
        if ($seoKind !== null && SeoFiles::enabled($this->config, $seoKind)) {
            $this->dispatchSeo($seoKind, $method);
            return;
        }

        if (!str_starts_with($path, '/plugin-assets/')) {
            $path = $this->beforeRouting($path, $method);
            if ($path === null) {
                return;
            }
        }

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

        $slug = $this->normalizeSlug($path);
        if (PageLoader::translationLocale($slug, SiteLocale::translationLocales()) !== null) {
            // about.en.md — перевод, а не отдельная страница /about.en
            $this->renderNotFound($path, $method);
            return;
        }
        $filePath = $this->pageLoader->slugToPath($slug);
        $isPreview = ($_GET['preview'] ?? '') === '1';
        $translateTo = SiteLocale::isActive() && !SiteLocale::isDefault() ? SiteLocale::current() : null;

        if (!is_file($filePath)) {
            $this->renderNotFound($path, $method);
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
            if ($translateTo !== null) {
                $page = $this->pageLoader->loadBySlug($slug, false, $translateTo) ?? $page;
            }

            header('X-Robots-Tag: noindex');
            $this->renderPage($page, $slug, true, ['i18n' => $this->pageLocaleMeta($slug, $page)]);
            return;
        }

        $page = $this->pageLoader->loadBySlug($slug, true);
        if ($page === null) {
            $this->renderNotFound($path, $method);
            return;
        }
        if ($translateTo !== null) {
            $page = $this->pageLoader->loadBySlug($slug, true, $translateTo) ?? $page;
        }

        if (SeoFiles::noindex($page['front_matter'])) {
            header('X-Robots-Tag: noindex');
        }
        $this->renderPage($page, $slug, false, ['i18n' => $this->pageLocaleMeta($slug, $page)]);
    }

    /** /sitemap.xml, /robots.txt, /llms.txt из опубликованных страниц (SeoFiles, docs/13-seo.md). */
    private function dispatchSeo(string $kind, string $method): void
    {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return;
        }
        $manager = $this->manager();
        $plugins = null;
        if ($manager->hasPluginsDir()) {
            SiteLocale::init($manager, true);
            $plugins = $manager;
        }
        (new SeoFiles($this->config, $this->pageLoader, $this->pageTree, $plugins))->respond($kind);
    }

    /**
     * Служба i18n и событие «request» до маршрутизации. Возвращает путь для маршрутизации
     * или null, если хук сам ответил (редирект). Без plugins/ — путь как есть.
     */
    private function beforeRouting(string $path, string $method): ?string
    {
        $manager = $this->manager();
        if (!$manager->hasPluginsDir()) {
            return $path;
        }
        SiteLocale::init($manager, true);
        if (!$manager->declaresHook('request')) {
            return $path;
        }
        $request = new SiteRequest($method, $path);
        $manager->dispatch('request', $request);

        return $request->isHandled() ? null : $request->path();
    }

    private function manager(): PluginManager
    {
        return $this->pluginManager ??= new PluginManager($this->config);
    }

    /**
     * Язык содержимого, hreflang и canonical Markdown-страницы. Без службы i18n — пусто.
     *
     * @return array{content_locale?: string, alternates?: array<string, string>, canonical?: ?string}
     */
    private function pageLocaleMeta(string $slug, array $page): array
    {
        if (!SiteLocale::isActive()) {
            return [];
        }
        $default = SiteLocale::default();
        $contentLocale = (string) ($page['front_matter']['locale'] ?? $default);
        $path = $slug === 'index' ? '/' : '/' . $slug;
        $alternates = [];
        foreach (SiteLocale::locales() as $locale) {
            if ($locale === $default || $this->pageLoader->loadBySlug($slug, true, $locale) !== null) {
                $alternates[$locale] = SiteLocale::url($path, $locale, 'alternate');
            }
        }

        return [
            'content_locale' => $contentLocale,
            'alternates' => count($alternates) > 1 ? $alternates : [],
            // перевода нет — показан оригинал, поисковикам указываем его адрес
            'canonical' => $contentLocale !== SiteLocale::current() ? SiteLocale::url($path, $contentLocale, 'canonical') : null,
        ];
    }

    /**
     * Статика /plugin-assets/{id}/... и публичные префиксы плагинов (приоритет над Markdown-страницами).
     * Без папки plugins/ ничего не делает.
     */
    private function dispatchPlugins(string $path, string $method): bool
    {
        $manager = $this->manager();
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

        $context->setPublicRenderer(function (string $html, array $options) use ($trimmed, $path): void {
            $this->renderPluginPage($html, $options, $trimmed === '' ? 'index' : $trimmed, $path);
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
    private function renderPluginPage(string $html, array $options, string $slug, string $path = '/'): void
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
            'styles' => $options['styles'] ?? [],
            'head_scripts' => $options['head_scripts'] ?? [],
            'i18n' => $this->pluginLocaleMeta($options['alternates'] ?? false, $path),
        ]);
    }

    /**
     * hreflang страницы плагина: true — все языки сайта по тому же пути, массив — явно.
     *
     * @return array{content_locale?: string, alternates?: array<string, string>}
     */
    private function pluginLocaleMeta(mixed $alternates, string $path): array
    {
        if (!SiteLocale::isActive()) {
            return [];
        }
        $out = [];
        if ($alternates === true) {
            foreach (SiteLocale::locales() as $locale) {
                $out[$locale] = SiteLocale::url($path, $locale, 'alternate');
            }
        } elseif (is_array($alternates)) {
            foreach ($alternates as $locale => $url) {
                if (in_array($locale, SiteLocale::locales(), true) && self::isSiteUrl((string) $url)) {
                    $out[$locale] = (string) $url;
                }
            }
        }

        return ['content_locale' => SiteLocale::current(), 'alternates' => count($out) > 1 ? $out : []];
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

        $locale = SiteLocale::current();
        $defaultLocale = SiteLocale::default();
        $i18nMeta = (array) ($options['i18n'] ?? []);
        $contentLocale = (string) ($i18nMeta['content_locale'] ?? $locale);
        $alternates = (array) ($i18nMeta['alternates'] ?? []);
        $i18n = SiteLocale::provider();
        $manager = $this->pluginManager !== null && $this->pluginManager->hasPluginsDir() ? $this->pluginManager : null;
        $hookContext = ['slug' => $slug, 'locale' => $locale, 'content_locale' => $contentLocale, 'preview' => $isPreview];
        // службы плагинов для темы: $plugins?->service('customizer') — объект или null
        $plugins = $manager ?? ($this->manager()->hasPluginsDir() ? $this->manager() : null);

        $this->menuBuilder->setLocale(SiteLocale::isActive() && $locale !== $defaultLocale ? $locale : null);
        $menus = $this->menuBuilder->buildAll($slug, true);
        if ($manager !== null) {
            $menus = $this->filterMenus($manager, $menus, $hookContext);
        }
        $menuId = trim((string) ($frontMatter['menu_location'] ?? MenuWriter::DEFAULT_LOCATION));
        if ($menuId === '' || !array_key_exists($menuId, $menus)) {
            $menuId = MenuWriter::DEFAULT_LOCATION;
        }
        $menu = $menus[$menuId] ?? $menus[MenuWriter::DEFAULT_LOCATION] ?? [];

        $title = $frontMatter['title'] ?? 'motorchk';
        $description = $frontMatter['description'] ?? '';
        $ogTitle = ($frontMatter['og_title'] ?? '') ?: $title;
        $ogDescription = ($frontMatter['og_description'] ?? '') ?: $description;
        $ogImage = $frontMatter['og_image'] ?? '';
        $scripts = PageScripts::normalize($frontMatter['scripts'] ?? []);
        $pagePublished = !empty($frontMatter['published']);
        $adminEditUrl = AdminUrl::page($slug);
        // inline-edit пишет в оригинал страницы — на других языках выключен
        $canEdit = ($options['editable'] ?? true) && !empty($_SESSION['admin_authenticated']) && $locale === $defaultLocale;
        $pluginVars = $options['vars'] ?? [];
        $inlineEditSaveUrl = $canEdit ? AdminUrl::pageBlocks($slug) : '';
        $inlineEditCsrf = $canEdit ? Csrf::token() : '';
        $config = $this->config;
        Form::setConfig($this->config);
        $siteHead = $this->siteHead($options, $alternates, $i18nMeta['canonical'] ?? null);
        if ($manager !== null) {
            $siteHead = (string) $manager->filter('head', $siteHead, $hookContext);
        }

        http_response_code((int) ($options['status'] ?? 200));
        header('Content-Type: text/html; charset=UTF-8');

        $buffer = SiteLocale::isActive() || ($manager !== null && $manager->declaresHook('output'));
        if (!$buffer) {
            require $templateFile;
            return;
        }

        ob_start();
        try {
            require $templateFile;
        } catch (Throwable $e) {
            echo (string) ob_get_clean();
            throw $e;
        }
        $html = (string) ob_get_clean();
        if (SiteLocale::isActive()) {
            // темы до 0.4.0 пишут <html lang="ru"> — язык страницы ставит ядро
            $html = preg_replace('/(<html\b[^>]*?\blang=")[^"]*(")/i', '${1}' . $contentLocale . '${2}', $html, 1) ?? $html;
        }
        if ($manager !== null) {
            $html = (string) $manager->filter('output', $html, $hookContext + ['status' => http_response_code()]);
        }
        echo $html;
    }

    /**
     * Фильтр «url» для внутренних ссылок меню и фильтр «menu» для всех меню.
     *
     * @param array<string, list<array<string, mixed>>> $menus
     * @param array<string, mixed> $context
     */
    private function filterMenus(PluginManager $manager, array $menus, array $context): array
    {
        if ($manager->declaresHook('url')) {
            $localize = static function (array $items) use (&$localize): array {
                foreach ($items as $i => $item) {
                    if (empty($item['external']) && str_starts_with((string) ($item['url'] ?? ''), '/')) {
                        $items[$i]['url'] = SiteLocale::url((string) $item['url'], null, 'menu');
                    }
                    $items[$i]['children'] = $localize($item['children'] ?? []);
                }

                return $items;
            };
            foreach ($menus as $id => $items) {
                $menus[$id] = $localize($items);
            }
        }

        return (array) $manager->filter('menu', $menus, $context);
    }

    /**
     * Дополнения <head> (выводит includes/views/site-chrome.php): CSS и head_scripts страницы
     * плагина, canonical и hreflang. Пустая строка — вывод темы как до 0.4.0.
     *
     * @param array<string, mixed> $options
     * @param array<string, string> $alternates
     */
    private function siteHead(array $options, array $alternates, ?string $canonical): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $lines = [];
        foreach ((array) ($options['styles'] ?? []) as $url) {
            if (self::isSiteUrl((string) $url)) {
                $lines[] = '<link href="' . $e((string) $url) . '" rel="stylesheet">';
            }
        }
        foreach (PageScripts::normalize($options['head_scripts'] ?? []) as $url) {
            $lines[] = '<script src="' . $e($url) . '" defer></script>';
        }
        if (is_string($canonical) && self::isSiteUrl($canonical)) {
            $lines[] = '<link rel="canonical" href="' . $e(SiteLocale::absolute($canonical)) . '">';
        }
        foreach ($alternates as $locale => $url) {
            $lines[] = '<link rel="alternate" hreflang="' . $e((string) $locale) . '" href="' . $e(SiteLocale::absolute((string) $url)) . '">';
        }
        $default = SiteLocale::default();
        if ($alternates !== [] && isset($alternates[$default])) {
            $lines[] = '<link rel="alternate" hreflang="x-default" href="' . $e(SiteLocale::absolute($alternates[$default])) . '">';
        }

        return $lines === [] ? '' : '    ' . implode("\n    ", $lines) . "\n";
    }

    /** Ссылка внутри сайта: /путь без //, пробелов и кавычек. */
    private static function isSiteUrl(string $url): bool
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//') && !preg_match('/[\s"\'<>\\\\]/', $url);
    }

    /**
     * 404 публичной страницы: шаблон 404.php активной темы, если он есть (docs/12-themes.md), иначе заглушка ядра.
     * Только GET/HEAD и не /api/…; предпросмотр и маршруты плагинов отвечают заглушкой, как раньше.
     */
    private function renderNotFound(string $path, string $method): void
    {
        if (!in_array($method, ['GET', 'HEAD'], true) || str_starts_with($path, '/api/') || ($_GET['preview'] ?? '') === '1'
            || !$this->themeHasTemplate(self::NOT_FOUND_TEMPLATE)) {
            $this->render404();
            return;
        }

        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');
        $page = [
            'front_matter' => [
                'title' => 'Страница не найдена',
                'description' => '',
                'template' => self::NOT_FOUND_TEMPLATE,
                'published' => true,
            ],
            'blocks' => ['hero' => '', 'content' => ''],
        ];
        $this->renderPage($page, self::NOT_FOUND_TEMPLATE, false, [
            'editable' => false,
            'status' => 404,
            'vars' => ['notFound' => true, 'requestPath' => $path],
        ]);
    }

    private function themeHasTemplate(string $template): bool
    {
        $themesPath = (string) ($this->config['themes_path'] ?? '');
        $theme = SiteMeta::getTheme($this->config);
        if (!is_dir($themesPath . '/' . $theme)) {
            $theme = 'example';
        }

        return is_file($themesPath . '/' . $theme . '/' . $template . '.php');
    }

    private function render404(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="' . htmlspecialchars(SiteLocale::current(), ENT_QUOTES, 'UTF-8') . '"><head><meta charset="UTF-8"><title>404</title></head><body><h1>Страница не найдена</h1></body></html>';
    }

    private function render500(string $message): void
    {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="' . htmlspecialchars(SiteLocale::current(), ENT_QUOTES, 'UTF-8') . '"><head><meta charset="UTF-8"><title>500</title></head><body><h1>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</h1></body></html>';
    }
}
