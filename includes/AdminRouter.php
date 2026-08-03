<?php

class AdminRouter
{
    private Cache $cache;
    private MarkdownRenderer $renderer;
    private PageLoader $pageLoader;
    private PageTree $pageTree;
    private PageWriter $pageWriter;
    private MenuBuilder $menuBuilder;
    private MenuWriter $menuWriter;
    private MenuEditor $menuEditor;

    public function __construct(private array $config)
    {
        $this->cache = new Cache($config['cache_path']);
        $this->renderer = new MarkdownRenderer();
        $this->pageLoader = new PageLoader($config, $this->cache, $this->renderer);
        $this->pageTree = new PageTree($config, $this->cache, $this->pageLoader, $this->renderer);
        $this->menuWriter = new MenuWriter($config, $this->cache);
        $this->pageWriter = new PageWriter($config, $this->cache, $this->pageLoader, $this->pageTree, $this->renderer, $this->menuWriter);
        $this->menuEditor = new MenuEditor($this->pageTree, $this->pageWriter, $this->menuWriter, $this->cache);
        $this->menuBuilder = new MenuBuilder($config, $this->cache, $this->pageLoader, $this->pageTree, $this->menuWriter);
    }

    public function dispatchAdmin(string $requestUri): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = $this->normalizePath($requestUri);
        $installed = !empty($this->config['installed']);

        if (!$installed) {
            if ($path === '/install') {
                if ($method === 'POST') {
                    $this->handleInstall();
                } else {
                    $installer = new Installer($this->config);
                    $this->render('install.php', [
                        'errors' => $installer->requirements(),
                        'form' => ['username' => 'admin'],
                    ], false);
                }
                return;
            }
            $this->redirect('/admin-panel/install');
            return;
        }

        if ($path === '/install') {
            $this->redirect('/admin-panel/pages');
            return;
        }

        if ($path === '' || $path === '/') {
            $this->redirect('/admin-panel/pages');
            return;
        }

        if ($path === '/login') {
            if ($method === 'POST') {
                $this->handleLogin();
            } else {
                if ($this->isAuthenticated()) {
                    $this->redirect('/admin-panel/pages');
                    return;
                }
                $this->render('login.php', ['error' => null], false);
            }
            return;
        }

        if (!$this->isAuthenticated()) {
            $this->redirect('/admin-panel/login');
            return;
        }

        if ($path === '/system/update' && $method === 'POST') {
            $this->requireCsrf();
            $this->handleCoreUpdate();
            return;
        }

        if ($path === '/system' && $method === 'GET') {
            $this->renderSystemPage();
            return;
        }

        if ($path === '/logout' && $method === 'POST') {
            $this->requireCsrf();
            session_destroy();
            $this->htmxRedirect('/admin-panel/login');
            return;
        }

        if ($path === '/pages' && $method === 'GET') {
            $this->render('pages/index.php', [
                'tree' => $this->pageTree->getTree(),
            ]);
            return;
        }

        if ($path === '/pages/create' && $method === 'GET') {
            $form = [];
            $parentPrefill = trim((string) ($_GET['parent'] ?? ''));
            if ($parentPrefill !== '' && $parentPrefill !== 'index') {
                $validParents = array_column($this->pageTree->getFlatNodesForSelect(), 'slug');
                if (in_array($parentPrefill, $validParents, true)) {
                    $form['parent'] = $parentPrefill;
                }
            }

            $this->render('pages/create.php', [
                'parents' => $this->pageTree->getFlatNodesForSelect(),
                'templates' => $this->listTemplates(),
                'form' => $form,
                'errors' => [],
            ]);
            return;
        }

        if ($path === '/pages' && $method === 'POST') {
            $this->requireCsrf();
            $this->handleCreatePage();
            return;
        }

        if ($path === '/menu') {
            if ($method === 'GET') {
                $this->menuWriter->migrateLegacyIfNeeded($this->pageWriter, $this->pageTree);
                $parent = trim((string) ($_GET['parent'] ?? ''));
                $parent = $parent === '' ? null : $parent;
                $this->render('menu/edit.php', $this->menuEditorViewData($parent));
                return;
            }
            if ($method === 'POST') {
                $this->requireCsrf();
                $this->handleSaveMenu();
                return;
            }
        }

        if ($method === 'POST' && ($blockSlug = AdminUrl::slugFromBlocksPath($path)) !== null) {
            $this->requireCsrf();
            $this->handleSaveBlock($blockSlug);
            return;
        }

        if (preg_match('#^/pages(?:/|$)#', $path)) {
            $slug = AdminUrl::slugFromPath($path);
            if ($slug === null) {
                http_response_code(404);
                echo 'Not found';
                return;
            }

            if ($method === 'GET') {
                $this->renderEditPage($slug);
                return;
            }
            if ($method === 'POST') {
                $this->requireCsrf();
                $this->handleSavePage($slug);
                return;
            }
            if ($method === 'DELETE') {
                $this->requireCsrf();
                $this->handleDeletePage($slug);
                return;
            }
        }

        http_response_code(404);
        echo 'Not found';
    }

    private function handleInstall(): void
    {
        $csrf = $_POST['_csrf'] ?? null;
        if (!Csrf::validate($csrf)) {
            $this->render('install.php', [
                'errors' => ['Неверный CSRF-токен'],
                'form' => [
                    'username' => trim((string) ($_POST['username'] ?? 'admin')),
                ],
            ], false);
            return;
        }

        $installer = new Installer($this->config);
        try {
            $installer->install(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['app_secret'] ?? '')
            );
            $this->redirect('/admin-panel/login');
        } catch (Throwable $e) {
            $this->render('install.php', [
                'errors' => array_values(array_unique(array_filter(array_merge(
                    $installer->requirements(),
                    [$e->getMessage()]
                )))),
                'form' => [
                    'username' => trim((string) ($_POST['username'] ?? 'admin')),
                ],
            ], false);
        }
    }

    private function renderSystemPage(): void
    {
        $updater = new Updater($this->config);
        $update = null;
        $checkError = null;
        try {
            $update = $updater->checkForUpdate();
        } catch (Throwable $e) {
            $checkError = $e->getMessage();
        }

        $this->render('system.php', [
            'currentVersion' => Version::current(),
            'update' => $update,
            'capabilities' => $updater->capabilities(),
            'checkError' => $checkError,
            'meta' => SiteMeta::load($this->config),
            'updateMessage' => $_GET['updated'] ?? null,
            'updateError' => $_GET['error'] ?? null,
        ]);
    }

    private function handleCoreUpdate(): void
    {
        $version = Version::normalizeTag((string) ($_POST['version'] ?? ''));
        $updater = new Updater($this->config);
        try {
            $updater->apply($version);
            $this->redirect('/admin-panel/system?updated=' . rawurlencode($version));
        } catch (Throwable $e) {
            $this->redirect('/admin-panel/system?error=' . rawurlencode($e->getMessage()));
        }
    }

    private function handleLogin(): void
    {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $csrf = $_POST['_csrf'] ?? null;

        if (!Csrf::validate($csrf)) {
            if ($this->isHtmx()) {
                $this->renderPartial('login-form.php', ['error' => 'Неверный CSRF-токен'], '#login-form');
            } else {
                $this->render('login.php', ['error' => 'Неверный CSRF-токен'], false);
            }
            return;
        }

        $expectedUser = Env::get('ADMIN_USERNAME', 'admin');
        $expectedHash = Env::get('ADMIN_PASSWORD_HASH', '');

        if ($username === $expectedUser && $expectedHash !== '' && password_verify($password, $expectedHash)) {
            $_SESSION['admin_authenticated'] = true;
            $this->htmxRedirect('/admin-panel/pages');
            return;
        }

        if ($this->isHtmx()) {
            $this->renderPartial('login-form.php', ['error' => 'Неверный пароль'], '#login-form');
        } else {
            $this->render('login.php', ['error' => 'Неверный пароль'], false);
        }
    }

    private function handleCreatePage(): void
    {
        $title = trim($_POST['title'] ?? '');
        $leafSlug = trim($_POST['slug'] ?? '');
        $parent = trim($_POST['parent'] ?? '');
        $parent = $parent === '' ? null : $parent;
        $template = trim($_POST['template'] ?? 'default');
        $published = !empty($_POST['published']);

        $errors = [];
        if ($title === '') {
            $errors[] = 'Укажите заголовок';
        }
        if ($leafSlug === '') {
            $leafSlug = Slugifier::slugify($title);
        }
        if (!Slugifier::isValidLeaf($leafSlug)) {
            $errors[] = 'Некорректный slug';
        }
        if (!in_array($template, $this->listTemplates(), true)) {
            $errors[] = 'Шаблон не найден';
        }

        if ($errors !== []) {
            $this->renderPartial('partials/form-errors.php', ['errors' => $errors], '#form-errors');
            return;
        }

        try {
            $slug = $this->pageWriter->create($title, $leafSlug, $parent, $template);
            $this->pageWriter->save($slug, [
                'title' => $title,
                'leaf_slug' => $leafSlug,
                'parent' => $parent,
                'description' => trim($_POST['description'] ?? ''),
                'published' => $published,
                'template' => $template,
                'og_title' => trim($_POST['og_title'] ?? ''),
                'og_description' => trim($_POST['og_description'] ?? ''),
                'og_image' => trim($_POST['og_image'] ?? ''),
                'scripts' => PageScripts::fromTextarea($_POST['scripts'] ?? ''),
                'order' => 0,
                'menu' => !empty($_POST['menu']),
            ], null);
            $this->htmxRedirect(AdminUrl::page($slug));
        } catch (Throwable $e) {
            $this->renderPartial('partials/form-errors.php', ['errors' => [$e->getMessage()]], '#form-errors');
        }
    }

    private function renderEditPage(string $slug): void
    {
        $page = $this->pageLoader->loadBySlug($slug, false);
        if ($page === null) {
            http_response_code(404);
            echo 'Страница не найдена';
            return;
        }

        $this->render('pages/edit.php', [
            'page' => $page,
            'leaf_slug' => Slugifier::leafFromSlug($slug),
            'parent' => Slugifier::parentFromSlug($slug),
            'parents' => $this->pageTree->getFlatNodesForSelect(),
            'templates' => $this->listTemplates(),
            'descendants' => $this->pageTree->countDescendants($slug),
            'is_index' => $slug === 'index',
        ]);
    }

    private function handleSavePage(string $slug): void
    {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'leaf_slug' => trim($_POST['slug'] ?? ''),
            'parent' => trim($_POST['parent'] ?? '') ?: null,
            'description' => trim($_POST['description'] ?? ''),
            'published' => !empty($_POST['published']),
            'template' => trim($_POST['template'] ?? 'default'),
            'og_title' => trim($_POST['og_title'] ?? ''),
            'og_description' => trim($_POST['og_description'] ?? ''),
            'og_image' => trim($_POST['og_image'] ?? ''),
            'scripts' => PageScripts::fromTextarea($_POST['scripts'] ?? ''),
            'order' => (int) ($_POST['order'] ?? 0),
            'menu' => !empty($_POST['menu']),
        ];

        try {
            $this->pageWriter->save($slug, $data, null);
            $newSlug = $slug === 'index'
                ? 'index'
                : Slugifier::buildFullSlug($data['parent'], $data['leaf_slug']);

            if ($this->isHtmx()) {
                $this->renderPartial('partials/save-status.php', ['message' => 'Сохранено'], '#save-status');
                return;
            }

            $this->redirect(AdminUrl::page($newSlug));
        } catch (Throwable $e) {
            if ($this->isHtmx()) {
                $this->renderPartial('partials/save-status.php', ['message' => $e->getMessage(), 'error' => true], '#save-status');
                return;
            }
            http_response_code(400);
            echo htmlspecialchars($e->getMessage());
        }
    }

    private function handleSaveBlock(string $slug): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $block = trim((string) ($_POST['block'] ?? ''));
        $markdown = (string) ($_POST['markdown'] ?? '');

        if ($block === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Не указан блок'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $this->pageWriter->saveBlock($slug, $block, $markdown);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function handleDeletePage(string $slug): void
    {
        if ($slug === 'index') {
            http_response_code(400);
            echo 'Нельзя удалить главную страницу';
            return;
        }

        try {
            $this->pageWriter->delete($slug);
            $this->htmxRedirect('/admin-panel/pages');
        } catch (Throwable $e) {
            http_response_code(400);
            echo htmlspecialchars($e->getMessage());
        }
    }

    private function handleSaveMenu(): void
    {
        $orders = $_POST['orders'] ?? [];
        $menuFlags = $_POST['menu'] ?? [];
        $menuSlugs = $_POST['menu_slugs'] ?? [];
        $external = $_POST['external'] ?? [];
        $parent = trim((string) ($_POST['parent'] ?? ''));
        $parent = $parent === '' ? null : $parent;

        if (!is_array($orders)) {
            $orders = [];
        }
        if (!is_array($menuFlags)) {
            $menuFlags = [];
        }
        if (!is_array($menuSlugs)) {
            $menuSlugs = [];
        }
        if (!is_array($external)) {
            $external = [];
        }

        try {
            $this->menuEditor->save($orders, $menuFlags, $menuSlugs, $external);
            if ($this->isHtmx()) {
                $this->renderPartial('menu/edit.php', array_merge(
                    $this->menuEditorViewData($parent),
                    ['saved' => true]
                ), '#menu-editor');
                return;
            }
            $this->redirect('/admin-panel/menu' . ($parent ? '?parent=' . rawurlencode($parent) : ''));
        } catch (Throwable $e) {
            if ($this->isHtmx()) {
                $this->renderPartial('menu/edit.php', array_merge(
                    $this->menuEditorViewData($parent),
                    ['error' => $e->getMessage()]
                ), '#menu-editor');
                return;
            }
            http_response_code(400);
            echo htmlspecialchars($e->getMessage());
        }
    }

    private function menuEditorViewData(?string $parent): array
    {
        return [
            'branch' => $this->menuEditor->getBranch($parent),
            'parent' => $parent ?? '',
            'parentOptions' => $this->menuEditor->getParentOptions(),
            'external' => $this->menuWriter->loadExternal(),
        ];
    }

    private function listTemplates(): array
    {
        $files = glob($this->config['templates_path'] . '/*.php') ?: [];
        $templates = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if ($name !== 'partials') {
                $templates[] = $name;
            }
        }
        sort($templates);

        return $templates !== [] ? $templates : ['default'];
    }

    private function normalizePath(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        $path = preg_replace('#^/admin-panel#', '', $path) ?? $path;
        $path = rtrim($path, '/') ?: '/';

        return $path === '' ? '/' : $path;
    }

    private function isAuthenticated(): bool
    {
        return !empty($_SESSION['admin_authenticated']);
    }

    private function requireCsrf(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if ($token === null && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['DELETE', 'PUT', 'PATCH'], true)) {
            parse_str(file_get_contents('php://input') ?: '', $input);
            $token = $input['_csrf'] ?? null;
        }

        if (!Csrf::validate($token)) {
            http_response_code(403);
            echo 'CSRF validation failed';
            exit;
        }
    }

    private function isHtmx(): bool
    {
        return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
    }

    private function htmxRedirect(string $url): void
    {
        if ($this->isHtmx()) {
            header('HX-Redirect: ' . $url);
            exit;
        }
        $this->redirect($url);
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    private function render(string $template, array $data = [], bool $withLayout = true): void
    {
        extract($data, EXTR_SKIP);
        $adminTemplates = $this->config['admin_templates_path'];

        $coreUpdate = null;
        if ($withLayout && !empty($this->config['installed']) && $this->isAuthenticated()) {
            try {
                $coreUpdate = (new Updater($this->config))->checkForUpdate();
            } catch (Throwable) {
                $coreUpdate = null;
            }
        }

        ob_start();
        require $adminTemplates . '/' . $template;
        $content = ob_get_clean();

        if ($withLayout && $template !== 'layout.php') {
            require $adminTemplates . '/layout.php';
            return;
        }

        echo $content;
    }

    private function renderPartial(string $template, array $data, string $target): void
    {
        extract($data, EXTR_SKIP);
        require $this->config['admin_templates_path'] . '/' . $template;
    }
}
