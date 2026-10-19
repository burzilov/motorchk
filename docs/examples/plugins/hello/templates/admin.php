<?php
/** @var PluginContext $plugin */
/** @var ?string $greeting */
?>
<div class="hello-card" id="hello-admin"
     data-api="<?= PluginContext::e($plugin->adminUrl('/api/greeting')) ?>"
     data-csrf="<?= PluginContext::e($plugin->csrfToken()) ?>">
    <h1>Hello</h1>
    <p>Пример плагина: данные лежат в <code><?= PluginContext::e($plugin->dataDir()) ?>/greeting.json</code>.</p>

    <form id="hello-form">
        <label>
            Приветствие
            <input type="text" name="greeting" maxlength="200" value="<?= PluginContext::e($greeting ?? '') ?>">
        </label>
        <button type="submit">Сохранить через JSON API</button>
    </form>
    <p class="hello-status" id="hello-status" aria-live="polite"></p>

    <p>
        <a href="<?= PluginContext::e($plugin->adminUrl('/fullscreen')) ?>">Полноэкранный режим</a> ·
        <a href="<?= PluginContext::e($plugin->publicUrl('/')) ?>" target="_blank" rel="noopener">Публичная страница</a>
    </p>
</div>
