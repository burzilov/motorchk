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
    private ?PluginManager $pluginManager = null;
    private string $currentPath = '/';

    public function __construct(private array $config)
    {
        $this->cache = new Cache($config['cache_path']);
        $this->renderer = new MarkdownRenderer();
        $this->pageLoader = new PageLoader($config, $this->cache, $this->renderer);
        $this->pageTree = new PageTree($config, $this->cache, $this->pageLoader, $this->renderer);
        $this->menuWriter = new MenuWriter($config, $this->cache);
        $this->pageWriter = new PageWriter($config, $this->cache, $this->pageLoader, $this->pageTree, $this->renderer, $this->menuWriter);
        $this->menuEditor = new MenuEditor($this->pageTree, $this->menuWriter);
        $this->menuBuilder = new MenuBuilder($config, $this->cache, $this->pageLoader, $this->pageTree, $this->menuWriter);
    }

    public function dispatchAdmin(string $requestUri): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = $this->normalizePath($requestUri);
        $this->currentPath = $path;
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

        // служба i18n (если есть): файлы переводов about.en.md не показываются как отдельные страницы.
        // Хуки сайта (request, url, …) в админке не работают.
        SiteLocale::init($this->plugins(), false);

        if (preg_match('#^/plugins/([a-z0-9-]+)(/.*)?$#', $path, $m)) {
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $this->requireCsrf();
            }
            $this->dispatchPlugin($m[1], $m[2] ?? '/', $method);
            return;
        }

        if ($path === '/system/update' && $method === 'POST') {
            $this->requireCsrf();
            $this->handleCoreUpdate();
            return;
        }

        if ($path === '/system/check-update' && $method === 'POST') {
            $this->requireCsrf();
            $this->handleCheckUpdate();
            return;
        }

        if ($path === '/system/theme' && $method === 'POST') {
            $this->requireCsrf();
            $this->handleSaveTheme();
            return;
        }

        if ($path === '/system/smtp' && $method === 'POST') {
            $this->requireCsrf();
            $this->handleSaveSmtp();
            return;
        }

        if ($path === '/system/smtp/test' && $method === 'POST') {
            $this->requireCsrf();
            $this->handleTestSmtp();
            return;
        }

        if ($path === '/system/password' && $method === 'POST') {
            $this->requireCsrf();
            $this->handleChangePassword();
            return;
        }

        if ($path === '/system' && $method === 'GET') {
            $this->renderSystemPage();
            return;
        }

        if ($path === '/submissions' && $method === 'GET') {
            $this->renderSubmissionsList();
            return;
        }

        if (preg_match('#^/submissions/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
            $this->renderSubmissionView($m[1], $m[2]);
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
                $location = trim((string) ($_GET['location'] ?? MenuWriter::DEFAULT_LOCATION));
                $this->render('menu/edit.php', $this->menuEditor->viewData($location));
                return;
            }
            if ($method === 'POST') {
                $this->requireCsrf();
                $this->handleMenuPost();
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

        $toasts = [];
        $toastCleanParams = [];

        if ($checkError !== null && $checkError !== '') {
            $toasts[] = [
                'message' => 'Не удалось проверить обновления: ' . $checkError,
                'type' => 'info',
            ];
        }

        if (isset($_GET['updated'])) {
            $toasts[] = [
                'message' => 'Ядро обновлено до v' . (string) $_GET['updated'] . '.',
                'type' => 'success',
            ];
            $toastCleanParams[] = 'updated';
        }
        if (isset($_GET['error'])) {
            $toasts[] = [
                'message' => (string) $_GET['error'],
                'type' => 'error',
            ];
            $toastCleanParams[] = 'error';
        }
        if (isset($_GET['checked']) && !isset($_GET['error']) && ($checkError === null || $checkError === '')) {
            $toasts[] = [
                'message' => 'Проверка обновлений выполнена.',
                'type' => 'info',
            ];
            $toastCleanParams[] = 'checked';
        }
        if (isset($_GET['smtp'])) {
            $toasts[] = [
                'message' => 'Настройки SMTP сохранены.',
                'type' => 'success',
            ];
            $toastCleanParams[] = 'smtp';
        }
        if (isset($_GET['smtp_error'])) {
            $toasts[] = [
                'message' => (string) $_GET['smtp_error'],
                'type' => 'error',
            ];
            $toastCleanParams[] = 'smtp_error';
        }
        if (isset($_GET['smtp_test'])) {
            $toasts[] = [
                'message' => 'SMTP-соединение успешно установлено.',
                'type' => 'success',
            ];
            $toastCleanParams[] = 'smtp_test';
        }
        if (isset($_GET['smtp_test_error'])) {
            $toasts[] = [
                'message' => 'Проверка SMTP не удалась: ' . (string) $_GET['smtp_test_error'],
                'type' => 'error',
            ];
            $toastCleanParams[] = 'smtp_test_error';
        }
        if (isset($_GET['password'])) {
            $toasts[] = [
                'message' => 'Пароль администратора обновлён.',
                'type' => 'success',
            ];
            $toastCleanParams[] = 'password';
        }
        if (isset($_GET['password_error'])) {
            $toasts[] = [
                'message' => (string) $_GET['password_error'],
                'type' => 'error',
            ];
            $toastCleanParams[] = 'password_error';
        }
        if (isset($_GET['theme'])) {
            $toasts[] = [
                'message' => 'Тема сохранена.',
                'type' => 'success',
            ];
            $toastCleanParams[] = 'theme';
        }

        $this->render('system.php', [
            'currentVersion' => Version::current(),
            'update' => $update,
            'capabilities' => $updater->capabilities(),
            'meta' => SiteMeta::load($this->config),
            'themes' => $this->listThemes(),
            'toasts' => $toasts,
            'toastCleanParams' => $toastCleanParams,
            'smtp' => (new Mailer($this->config))->settingsFromEnv(),
            'smtpPasswordSet' => Env::get('SMTP_PASSWORD', '') !== '',
            'plugins' => $this->systemPlugins(),
            'pluginProtection' => $this->plugins()->protectionStatus(),
            'pluginsPath' => $this->plugins()->pluginsPath(),
            'pluginDataPath' => $this->plugins()->dataRoot(),
        ]);
    }

    /**
     * Плагины для страницы «Система»: загружаем валидные, чтобы показать ошибки загрузки и install-хуков.
     *
     * @return array<string, array<string, mixed>>
     */
    private function systemPlugins(): array
    {
        $manager = $this->plugins();
        $manager->bootAll();

        return $manager->discover();
    }

    private function plugins(): PluginManager
    {
        return $this->pluginManager ??= new PluginManager($this->config);
    }

    private function dispatchPlugin(string $id, string $path, string $method): void
    {
        $manager = $this->plugins();
        $context = $manager->boot($id);
        if ($context === null) {
            http_response_code(404);
            $info = $manager->discover()[$id] ?? null;
            $reason = $info !== null ? implode('; ', $info['errors']) : 'плагин не установлен';
            $this->renderPluginContent(
                '<div class="rounded-xl bg-white p-6 shadow-sm"><h1 class="mb-2 text-lg font-semibold">Плагин недоступен</h1>'
                . '<p class="text-sm text-slate-600">' . htmlspecialchars($id . ': ' . $reason) . '. Подробности — на странице «Система».</p></div>',
                ['title' => 'Плагин недоступен']
            );
            return;
        }

        $context->setAdminRenderer(function (string $html, array $options): void {
            $this->renderPluginContent($html, $options);
        });

        try {
            $status = $manager->handle($context, 'admin', $method, $path, false);
        } catch (Throwable $e) {
            error_log('motorchk plugin ' . $id . ': ' . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(500);
            }
            if ($this->wantsJson()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                return;
            }
            echo '<p>Ошибка плагина ' . htmlspecialchars($id) . ': ' . htmlspecialchars($e->getMessage()) . '</p>';
            return;
        }

        if ($status !== 0) {
            http_response_code($status);
            if ($this->wantsJson()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'error' => $status === 405 ? 'Method not allowed' : 'Not found']);
                return;
            }
            echo $status === 405 ? 'Method not allowed' : 'Not found';
        }
    }

    /**
     * HTML плагина внутри layout админки.
     *
     * @param array{title?: string, fullscreen?: bool, styles?: list<string>, scripts?: list<string>, status?: int} $options
     */
    private function renderPluginContent(string $html, array $options): void
    {
        if (isset($options['status'])) {
            http_response_code((int) $options['status']);
        }
        header('Content-Type: text/html; charset=UTF-8');
        $this->render('', [
            'pluginHtml' => $html,
            'pageTitle' => (string) ($options['title'] ?? ''),
            'layoutFullscreen' => !empty($options['fullscreen']),
            'layoutStyles' => self::sameOriginUrls($options['styles'] ?? []),
            'layoutScripts' => self::sameOriginUrls($options['scripts'] ?? []),
            'layoutScriptsAfterAlpine' => self::sameOriginUrls($options['scripts_after_alpine'] ?? []),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function sameOriginUrls(mixed $urls): array
    {
        $out = [];
        foreach ((array) $urls as $url) {
            $url = (string) $url;
            if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !preg_match('/[\s"\'<>\\\\]/', $url)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    private function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        return str_contains($accept, 'application/json') || str_contains($type, 'application/json');
    }

    private function handleSaveSmtp(): void
    {
        $passwordInput = (string) ($_POST['smtp_password'] ?? '');
        $updates = [
            'SMTP_HOST' => trim((string) ($_POST['smtp_host'] ?? '')),
            'SMTP_PORT' => trim((string) ($_POST['smtp_port'] ?? '587')),
            'SMTP_ENCRYPTION' => strtolower(trim((string) ($_POST['smtp_encryption'] ?? ''))),
            'SMTP_USERNAME' => trim((string) ($_POST['smtp_username'] ?? '')),
            'SMTP_FROM' => trim((string) ($_POST['smtp_from'] ?? '')),
            'SMTP_FROM_NAME' => trim((string) ($_POST['smtp_from_name'] ?? '')),
            'SMTP_TO' => trim((string) ($_POST['smtp_to'] ?? '')),
        ];

        if (!in_array($updates['SMTP_ENCRYPTION'], ['', 'tls', 'ssl'], true)) {
            $this->redirect('/admin-panel/system?smtp_error=' . rawurlencode('Некорректное шифрование SMTP'));
            return;
        }

        if ($passwordInput !== '') {
            $updates['SMTP_PASSWORD'] = $passwordInput;
        }

        try {
            $writer = new EnvWriter($this->config['site_path'] . '/.env');
            $writer->merge($updates);
            $this->redirect('/admin-panel/system?smtp=1');
        } catch (Throwable $e) {
            $this->redirect('/admin-panel/system?smtp_error=' . rawurlencode($e->getMessage()));
        }
    }

    private function handleTestSmtp(): void
    {
        try {
            (new Mailer($this->config))->testConnection();
            $this->redirect('/admin-panel/system?smtp_test=1');
        } catch (Throwable $e) {
            $this->redirect('/admin-panel/system?smtp_test_error=' . rawurlencode($e->getMessage()));
        }
    }

    private function handleChangePassword(): void
    {
        $current = (string) ($_POST['current_password'] ?? '');
        $next = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $hash = (string) Env::get('ADMIN_PASSWORD_HASH', '');

        if ($hash === '' || !password_verify($current, $hash)) {
            $this->redirect('/admin-panel/system?password_error=' . rawurlencode('Неверный текущий пароль'));
            return;
        }
        if (strlen($next) < 8) {
            $this->redirect('/admin-panel/system?password_error=' . rawurlencode('Новый пароль не короче 8 символов'));
            return;
        }
        if ($next !== $confirm) {
            $this->redirect('/admin-panel/system?password_error=' . rawurlencode('Пароли не совпадают'));
            return;
        }

        $newHash = password_hash($next, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($newHash === false) {
            $this->redirect('/admin-panel/system?password_error=' . rawurlencode('Не удалось создать хэш пароля'));
            return;
        }

        try {
            $writer = new EnvWriter($this->config['site_path'] . '/.env');
            $writer->merge(['ADMIN_PASSWORD_HASH' => $newHash]);
            $this->redirect('/admin-panel/system?password=1');
        } catch (Throwable $e) {
            $this->redirect('/admin-panel/system?password_error=' . rawurlencode($e->getMessage()));
        }
    }

    private function renderSubmissionsList(): void
    {
        $formId = trim((string) ($_GET['form_id'] ?? ''));
        $mailStatus = trim((string) ($_GET['mail_status'] ?? ''));
        $store = new SubmissionStore($this->config);
        $repo = new FormRepository($this->config);

        $this->render('submissions/index.php', [
            'items' => $store->list($formId !== '' ? $formId : null, $mailStatus !== '' ? $mailStatus : null),
            'forms' => $repo->listMeta(),
            'filterFormId' => $formId,
            'filterMailStatus' => $mailStatus,
        ]);
    }

    private function renderSubmissionView(string $formId, string $id): void
    {
        $item = (new SubmissionStore($this->config))->get($formId, $id);
        if ($item === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $formLabel = $formId;
        try {
            $formLabel = (new FormRepository($this->config))->get($formId)['label'];
        } catch (Throwable) {
        }

        $this->render('submissions/view.php', [
            'item' => $item,
            'formLabel' => $formLabel,
        ]);
    }

    private function handleCheckUpdate(): void
    {
        $updater = new Updater($this->config);
        try {
            $updater->checkForUpdate(true);
            $this->redirect('/admin-panel/system?checked=1');
        } catch (Throwable $e) {
            $this->redirect('/admin-panel/system?error=' . rawurlencode($e->getMessage()));
        }
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

    private function handleSaveTheme(): void
    {
        $theme = trim((string) ($_POST['theme'] ?? ''));
        if (!in_array($theme, $this->listThemes(), true)) {
            $this->redirect('/admin-panel/system?error=' . rawurlencode('Тема не найдена'));
            return;
        }

        try {
            SiteMeta::setTheme($this->config, $theme);
            $this->cache->flush();
            $this->redirect('/admin-panel/system?theme=' . rawurlencode($theme));
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
        ];

        try {
            if (!in_array($data['template'], $this->listTemplates(), true)) {
                throw new InvalidArgumentException('Шаблон не найден');
            }
            $this->pageWriter->save($slug, $data, null);
            $newSlug = $slug === 'index'
                ? 'index'
                : Slugifier::buildFullSlug($data['parent'], $data['leaf_slug']);

            if ($this->isHtmx()) {
                $this->htmxToast('Сохранено');
                return;
            }

            $this->redirect(AdminUrl::page($newSlug));
        } catch (Throwable $e) {
            if ($this->isHtmx()) {
                $this->htmxToast($e->getMessage(), 'error');
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

    private function handleMenuPost(): void
    {
        $action = trim((string) ($_POST['action'] ?? 'save_items'));
        $location = trim((string) ($_POST['location'] ?? MenuWriter::DEFAULT_LOCATION));

        try {
            switch ($action) {
                case 'create_location':
                    $this->menuEditor->createLocation(
                        trim((string) ($_POST['new_id'] ?? '')),
                        trim((string) ($_POST['new_label'] ?? ''))
                    );
                    $location = trim((string) ($_POST['new_id'] ?? $location));
                    break;

                case 'update_location':
                    $newId = trim((string) ($_POST['new_id'] ?? ''));
                    $this->menuEditor->updateLocation(
                        $location,
                        trim((string) ($_POST['label'] ?? '')),
                        $newId !== '' && $newId !== $location ? $newId : null
                    );
                    if ($newId !== '' && $newId !== $location) {
                        $location = $newId;
                    }
                    break;

                case 'delete_location':
                    $this->menuEditor->deleteLocation($location);
                    $location = MenuWriter::DEFAULT_LOCATION;
                    break;

                case 'save_items':
                default:
                    $itemsRaw = $_POST['items_json'] ?? '[]';
                    if (!is_string($itemsRaw)) {
                        throw new InvalidArgumentException('Некорректные данные меню');
                    }
                    $items = json_decode($itemsRaw, true);
                    if (!is_array($items)) {
                        throw new InvalidArgumentException('Некорректный JSON пунктов меню');
                    }
                    $this->menuEditor->saveItems($location, $items);
                    break;
            }

            $view = $this->menuEditor->viewData($location);
            if ($this->isHtmx()) {
                $this->htmxToast('Сохранено');
                $this->renderPartial('menu/edit.php', $view, '#menu-editor');
                return;
            }
            $this->redirect('/admin-panel/menu?location=' . rawurlencode($location));
        } catch (Throwable $e) {
            $view = $this->menuEditor->viewData($location);
            if ($this->isHtmx()) {
                $this->htmxToast($e->getMessage(), 'error');
                $this->renderPartial('menu/edit.php', $view, '#menu-editor');
                return;
            }
            http_response_code(400);
            echo htmlspecialchars($e->getMessage());
        }
    }

    private function listTemplates(): array
    {
        $themePath = $this->activeThemePath();
        if ($themePath === null) {
            return [];
        }

        $files = glob($themePath . '/*.php') ?: [];
        $templates = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $templates[] = $name;
        }
        sort($templates);

        return $templates;
    }

    private function listThemes(): array
    {
        $files = glob($this->config['themes_path'] . '/*/default.php') ?: [];
        $themes = [];
        foreach ($files as $file) {
            $themes[] = basename(dirname($file));
        }
        sort($themes);

        return $themes;
    }

    private function activeThemePath(): ?string
    {
        $theme = SiteMeta::getTheme($this->config);
        $path = $this->config['themes_path'] . '/' . $theme;
        if (is_file($path . '/default.php')) {
            return $path;
        }

        $fallback = $this->config['themes_path'] . '/example';

        return is_file($fallback . '/default.php') ? $fallback : null;
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

    private function htmxToast(string $message, string $type = 'success'): void
    {
        $payload = json_encode(
            [
                'mc:toast' => [
                    'message' => $message,
                    'type' => $type,
                ],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        header('HX-Trigger: ' . $payload);
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

        $pluginMenu = [];
        if ($withLayout && !empty($this->config['installed']) && $this->isAuthenticated()) {
            foreach ($this->plugins()->adminMenu() as $item) {
                $item['active'] = str_starts_with($this->currentPath . '/', '/plugins/' . $item['id'] . '/');
                $pluginMenu[] = $item;
            }
        }

        if ($template === '') {
            $content = (string) ($data['pluginHtml'] ?? '');
        } else {
            ob_start();
            require $adminTemplates . '/' . $template;
            $content = ob_get_clean();
        }

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
