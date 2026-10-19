<?php
/** @var PluginContext $plugin */
/** @var string $greeting */
/** @var ?string $name */
?>
<link rel="stylesheet" href="<?= PluginContext::e($plugin->assetUrl('hello.css')) ?>">
<div class="hello-public" id="hello-public"
     data-api="<?= PluginContext::e($plugin->publicUrl('/api/echo')) ?>"
     data-csrf="<?= PluginContext::e($plugin->csrfToken()) ?>">
    <h1><?= PluginContext::e($greeting) ?><?= $name !== null ? ', ' . PluginContext::e($name) : '' ?></h1>
    <p>Эту страницу отдаёт плагин <strong>hello</strong> внутри шаблона активной темы.</p>
    <p><img src="<?= PluginContext::e($plugin->assetUrl('hello.svg')) ?>" alt="" width="48" height="48"></p>
</div>
