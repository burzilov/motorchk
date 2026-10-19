<?php

use Hello\GreetingStore;

/*
 * Точка входа плагина. Ядро вызывает возвращённую функцию и передаёт PluginContext.
 * Классы объявляйте в autoload-папке (lib/), а не здесь: точку входа ядро может подключить
 * больше одного раза за процесс (например, в тестах).
 */
return static function (PluginContext $plugin): void {
    // Идемпотентный хук: первая установка ($from === null) и обновления версии.
    $plugin->onInstall(static function (?string $from, string $to, PluginContext $plugin): void {
        $store = new GreetingStore($plugin);
        if ($store->load() === null) {
            $store->save('Привет из плагина hello!');
        }
    });

    // ---------- админка: /admin-panel/plugins/hello/... ----------

    $plugin->adminRoute('GET', '/', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->render('admin', [
            'greeting' => (new GreetingStore($plugin))->load(),
        ], [
            'title' => 'Hello',
            'styles' => [$plugin->assetUrl('hello.css')],
            'scripts' => [$plugin->assetUrl('hello-admin.js')],
        ]);
    });

    $plugin->adminRoute('GET', '/fullscreen', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->render('fullscreen', [], [
            'title' => 'Hello — полноэкранный режим',
            'fullscreen' => true,
            'styles' => [$plugin->assetUrl('hello.css')],
        ]);
    });

    $plugin->adminRoute('GET', '/api/greeting', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->json(['ok' => true] + (new GreetingStore($plugin))->loadWithMeta());
    });

    // CSRF для PUT проверяет ядро (заголовок X-CSRF-Token), здесь только валидация данных.
    $plugin->adminRoute('PUT', '/api/greeting', static function (PluginRequest $request, PluginContext $plugin): void {
        $body = $request->json();
        $text = is_array($body) ? trim((string) ($body['greeting'] ?? '')) : '';
        if ($text === '' || mb_strlen($text) > 200) {
            $plugin->jsonError('Приветствие — от 1 до 200 символов', 422);
            return;
        }
        (new GreetingStore($plugin))->save($text);
        $plugin->json(['ok' => true] + (new GreetingStore($plugin))->loadWithMeta());
    });

    // ---------- сайт: /hello/... (public.prefix в plugin.json) ----------

    $plugin->publicRoute('GET', '/', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->renderPublic('public', [
            'greeting' => (new GreetingStore($plugin))->load() ?? 'Привет!',
            'name' => null,
        ], [
            'title' => 'Hello',
            'template' => 'landing',
            'scripts' => [$plugin->assetUrl('hello-public.js')],
        ]);
    });

    $plugin->publicRoute('GET', '/to/{name}', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->renderPublic('public', [
            'greeting' => (new GreetingStore($plugin))->load() ?? 'Привет!',
            'name' => $request->param('name'),
        ], [
            'title' => 'Hello, ' . $request->param('name'),
            'template' => 'landing',
        ]);
    });

    // Сырой JSON без темы.
    $plugin->publicRoute('GET', '/api/greeting', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->json(['ok' => true, 'greeting' => (new GreetingStore($plugin))->load()]);
    });

    // Публичный POST: ядро проверяет CSRF по умолчанию (токен из страницы /hello/).
    $plugin->publicRoute('POST', '/api/echo', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->json(['ok' => true, 'echo' => mb_substr((string) (($request->json() ?? [])['text'] ?? ''), 0, 200)]);
    });
};
