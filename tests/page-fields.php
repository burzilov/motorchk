<?php

/**
 * Поля и панели редактора страницы, updateMeta (без PHPUnit): php tests/page-fields.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$corePath = dirname(__DIR__);
require_once $corePath . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ob_start();
Env::set('APP_SECRET', 'page-fields-test-secret');
$failures = 0;
$passed = 0;

function out(string $text): void
{
    fwrite(STDOUT, $text);
}

function check(bool $ok, string $name, string $details = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        out("  ok   {$name}\n");
        return;
    }
    $failures++;
    out("  FAIL {$name}" . ($details !== '' ? " — {$details}" : '') . "\n");
}

function pf_rm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            pf_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

function pf_config(string $corePath, string $site): array
{
    return [
        'site_path' => $site,
        'core_path' => $corePath,
        'base_path' => $site,
        'content_path' => $site . '/content',
        'cache_path' => $site . '/cache',
        'themes_path' => $site . '/themes',
        'forms_path' => $site . '/content/forms',
        'submissions_path' => $site . '/submissions',
        'admin_templates_path' => $corePath . '/admin-panel/templates',
        'plugins_path' => $site . '/plugins',
        'plugin_data_path' => $site . '/plugins-data',
        'installed' => true,
    ];
}

function pf_plugin(string $site, string $id, array $extra, string $entry): void
{
    $dir = $site . '/plugins/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('plugins');
    }
    $manifest = array_merge([
        'id' => $id,
        'name' => $id,
        'version' => '1.0.0',
        'core' => '>=0.5.0',
        'entry' => 'plugin.php',
    ], $extra);
    file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . '/plugin.php', $entry);
}

/**
 * @param array<string, string> $headers
 * @return array{0: int, 1: string}
 */
function pf_capture(callable $fn, string $method = 'GET', array $post = [], array $headers = []): array
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_SERVER['HTTP_HOST'] = 'page-fields.test';
    foreach ($headers as $name => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $_POST = $post;
    $_GET = [];
    $_FILES = [];
    http_response_code(200);
    ob_start();
    try {
        $fn();
    } finally {
        $body = (string) ob_get_clean();
    }

    return [http_response_code(), $body];
}

out("page-fields tests (core " . Version::current() . ")\n");

$site = sys_get_temp_dir() . '/motorchk-page-fields-' . bin2hex(random_bytes(4));
pf_rm($site);
if (!mkdir($site, 0775, true) && !is_dir($site)) {
    fwrite(STDERR, "cannot create temp site\n");
    exit(1);
}

try {
    $skeleton = $corePath . '/skeleton';
    foreach (['content', 'themes', 'config', 'media'] as $dir) {
        $src = $skeleton . '/' . $dir;
        $dst = $site . '/' . $dir;
        if (!is_dir($src)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0775, true);
                }
                copy($item->getPathname(), $target);
            }
        }
    }
    mkdir($site . '/cache', 0775, true);
    mkdir($site . '/plugins', 0775, true);
    mkdir($site . '/plugins-data', 0775, true);
    file_put_contents($site . '/cache/update-check.json', '{"checked_at":"2099-01-01T00:00:00+00:00","latest":null}');

    $config = pf_config($corePath, $site);

    // --- unit: matches / reserved ---
    check(PageFields::isReserved('title'), 'reserved title');
    check(!PageFields::isReserved('customizer'), 'customizer not reserved');
    check(
        PageFields::matches(['template' => ['landing']], ['template' => 'landing'], 'x'),
        'show_when template match'
    );
    check(
        !PageFields::matches(['template' => ['landing']], ['template' => 'default'], 'x'),
        'show_when template miss'
    );
    check(
        PageFields::matches(['section' => ['equipment/accessories']], ['parent' => 'equipment/accessories'], 'equipment/accessories/ng1'),
        'show_when section by parent'
    );

    // --- updateMeta preserves unknown keys and content ---
    $cache = new Cache($site . '/cache');
    $renderer = new MarkdownRenderer();
    $loader = new PageLoader($config, $cache, $renderer);
    $tree = new PageTree($config, $cache, $loader, $renderer);
    $writer = new PageWriter($config, $cache, $loader, $tree, $renderer, new MenuWriter($config, $cache));

    $slug = $writer->create('Demo', 'demo', null, 'default');
    $path = $loader->slugToPath($slug);
    $parsed = $renderer->parseFile($path);
    $parsed['front_matter']['images'] = ['media/products/demo-1.webp'];
    $parsed['front_matter']['group'] = 2;
    $parsed['front_matter']['order'] = 40;
    $parsed['front_matter']['customizer'] = '/customizer/x/y';
    $parsed['blocks']['content'] = "Блок контента.";
    FileWriter::write($path, $renderer->serialize($parsed['front_matter'], $parsed['blocks']));

    $writer->updateMeta($slug, [
        'images' => ['media/products/demo-2.webp', 'media/products/demo-1.webp'],
        'group' => 3,
    ]);
    $after = $renderer->parseFile($path);
    check(($after['front_matter']['order'] ?? null) === 40, 'updateMeta keeps order');
    check(($after['front_matter']['customizer'] ?? null) === '/customizer/x/y', 'updateMeta keeps customizer');
    check(($after['front_matter']['group'] ?? null) === 3, 'updateMeta updates group');
    check(($after['blocks']['content'] ?? '') === 'Блок контента.', 'updateMeta keeps body');
    check(($after['front_matter']['images'][0] ?? '') === 'media/products/demo-2.webp', 'updateMeta updates images');

    $writer->save($slug, [
        'title' => 'Demo 2',
        'leaf_slug' => 'demo',
        'parent' => null,
        'description' => '',
        'published' => true,
        'template' => 'default',
        'og_title' => '',
        'og_description' => '',
        'og_image' => '',
        'scripts' => [],
        // order omitted on purpose
        'extra' => ['customizer' => '/customizer/a/b'],
    ], null);
    $saved = $renderer->parseFile($path);
    check(($saved['front_matter']['order'] ?? null) === 40, 'save without order keeps order');
    check(($saved['front_matter']['customizer'] ?? null) === '/customizer/a/b', 'save extra writes plugin field');
    check(($saved['front_matter']['images'][0] ?? '') === 'media/products/demo-2.webp', 'save keeps unknown images');
    check(!empty($saved['front_matter']['published']), 'save publishes');

    $denied = false;
    try {
        $writer->updateMeta($slug, ['slug' => 'other']);
    } catch (InvalidArgumentException $e) {
        $denied = true;
    }
    check($denied, 'updateMeta rejects slug change');

    // --- plugin registration + editor render/save ---
    pf_plugin($site, 'fields-demo', [
        'page_fields' => true,
        'admin' => ['menu' => 'Fields'],
    ], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->registerPageField('customizer', [
        'type' => 'url',
        'label' => ['ru' => 'Ссылка на конструктор', 'en' => 'Customizer link'],
        'placement' => 'aside',
        'show_when' => ['template' => ['default']],
    ]);
    $plugin->registerPageField('featured', [
        'type' => 'checkbox',
        'label' => 'В избранном',
        'default' => false,
    ]);
    $plugin->registerPagePanel('demo_panel', [
        'label' => 'Демо-панель',
        'placement' => 'main',
        'render' => static function (array $page, PluginContext $p): string {
            return '<p class="demo-panel">panel:' . PluginContext::e($page['front_matter']['slug'] ?? '') . '</p>';
        },
    ]);
    $plugin->adminRoute('GET', '/', static function (PluginRequest $r, PluginContext $p): void {
        $p->pages()->updateMeta('demo', ['group' => 9]);
        $p->json(['ok' => true]);
    });
};
PHP);

    $plugins = new PluginManager($config);
    check(!empty($plugins->discover()['fields-demo']['page_fields']), 'manifest page_fields');
    $plugins->bootPageFields();
    $ctx = $plugins->pageFieldPluginContext('fields-demo');
    check($ctx instanceof PluginContext, 'boot page fields plugin');
    $fields = $plugins->pageFieldsFor(['template' => 'default'], 'demo');
    $keys = array_map(static fn(array $f): string => $f['key'], $fields);
    check(in_array('customizer', $keys, true) && in_array('featured', $keys, true), 'fields registered');
    $panels = $plugins->pagePanelsFor(['template' => 'default'], 'demo');
    check(count($panels) === 1 && $panels[0]['id'] === 'demo_panel', 'panel registered');

    pf_plugin($site, 'fields-other', [
        'page_fields' => true,
    ], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->registerPageField('customizer', [
        'type' => 'text',
        'label' => 'Other',
    ]);
};
PHP);
    $plugins2 = new PluginManager($config);
    $plugins2->bootPageFields();
    $other = $plugins2->discover()['fields-other'] ?? [];
    check(($other['status'] ?? '') !== 'ok', 'field key conflict fails second plugin');
    $owner = null;
    foreach ($plugins2->pageFieldsFor(['template' => 'default'], 'demo') as $field) {
        if ($field['key'] === 'customizer') {
            $owner = $field['plugin'];
        }
    }
    check($owner === 'fields-demo', 'customizer kept by first plugin');
    // Admin editor GET/POST
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['admin_authenticated'] = true;
    $token = Csrf::token();

    $router = new AdminRouter($config);
    [$getStatus, $getBody] = pf_capture(static function () use ($router): void {
        $router->dispatchAdmin('/admin-panel/pages/demo');
    });
    check($getStatus === 200, 'edit page status', (string) $getStatus);
    check(str_contains($getBody, 'page_field[customizer]'), 'edit shows customizer field');
    check(str_contains($getBody, 'demo-panel'), 'edit shows panel html');
    check(str_contains($getBody, 'В избранном') || str_contains($getBody, 'featured'), 'edit shows checkbox');

    [$postStatus] = pf_capture(static function () use ($router): void {
        $router->dispatchAdmin('/admin-panel/pages/demo');
    }, 'POST', [
        '_csrf' => $token,
        'title' => 'Demo 3',
        'slug' => 'demo',
        'parent' => '',
        'description' => '',
        'published' => '1',
        'template' => 'default',
        'og_title' => '',
        'og_description' => '',
        'og_image' => '',
        'scripts' => '',
        'page_field' => [
            'customizer' => '/customizer/series/item',
            'featured' => '1',
        ],
    ], ['HX-Request' => 'true']);
    check($postStatus === 200 || $postStatus === 204, 'save page status', (string) $postStatus);

    $saved2 = $renderer->parseFile($path);
    check(($saved2['front_matter']['customizer'] ?? '') === '/customizer/series/item', 'saved customizer from form');
    check(!empty($saved2['front_matter']['featured']), 'saved featured checkbox');
    check(($saved2['front_matter']['order'] ?? null) === 40, 'editor save keeps order');
    check(($saved2['front_matter']['images'][0] ?? '') === 'media/products/demo-2.webp', 'editor save keeps images');

    // pages() API from plugin route
    $plugins3 = new PluginManager($config);
    $boot = $plugins3->boot('fields-demo');
    check($boot instanceof PluginContext, 'boot for pages API');
    $boot->pages()->updateMeta('demo', ['group' => 11]);
    $saved3 = $renderer->parseFile($path);
    check(($saved3['front_matter']['group'] ?? null) === 11, 'PluginPages::updateMeta');

    // without page_fields flag
    pf_plugin($site, 'no-flag', [], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->registerPageField('x', ['type' => 'text', 'label' => 'X']);
};
PHP);
    $pluginsNoFlag = new PluginManager($config);
    $bootedNoFlag = $pluginsNoFlag->boot('no-flag');
    check($bootedNoFlag === null, 'registerPageField requires manifest flag');
    check(($pluginsNoFlag->discover()['no-flag']['status'] ?? '') !== 'ok', 'no-flag marked failed');
} finally {
    pf_rm($site);
}

out("\n{$passed} passed, {$failures} failed\n");
exit($failures > 0 ? 1 : 0);
