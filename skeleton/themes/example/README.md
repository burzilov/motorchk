# Тема-пример motorchk

`example` — поставляемая тема-пример. При каждом self-update ядро полностью заменяет каталог `themes/example/` его версией из release.

Не используйте её как основу рабочего сайта и не храните в ней собственные изменения: они будут безвозвратно перезаписаны при обновлении. Создайте отдельную тему в `themes/{name}/`, выберите её на странице **Система** и редактируйте только этот каталог.

## Обязательные подключения ядра

В `<head>` после CSS темы:

```php
<?php require $config['core_path'] . '/includes/views/site-chrome.php'; ?>
```

Баннер сессии админа / предпросмотра в начале `<body>`:

```php
<?php require $config['core_path'] . '/includes/views/preview-banner.php'; ?>
```

Скрипты inline-edit — как в `partials/scripts.php` темы-примера (turndown + inline-edit при `$canEdit`).
