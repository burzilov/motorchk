# Тема-пример motorchk

`example` — поставляемая тема-пример. При каждом self-update ядро полностью заменяет каталог `themes/example/` его версией из release.

Не используйте её как основу рабочего сайта и не храните в ней собственные изменения: они будут безвозвратно перезаписаны при обновлении. Создайте отдельную тему в `themes/{name}/`, выберите её на странице **Система** и редактируйте только этот каталог.

## Меню (контракт для тем)

Роутер передаёт в шаблон:

| Переменная | Описание |
|------------|----------|
| `$menu` | Основное меню страницы: location из front matter `menu_location` или `main` |
| `$menus` | Все меню: `['main' => [...], 'footer' => [...], ...]` |

Сборка вручную:

```php
$items = (new MenuBuilder(...))->build($slug, 'ceo-lead');
// или из уже переданного массива:
$items = $menus['ceo-lead'] ?? [];
```

Формат пункта после `build`:

```php
[
    'label' => 'О проекте',
    'slug' => 'about',          // null для url/якоря
    'url' => '/about',          // или '#quiz', 'https://…'
    'external' => false,
    'active' => false,
    'broken' => false,
    'children' => [],
]
```

Рендер готовым хелпером:

```php
<?php MenuRenderer::render($menu ?? [], 'desktop'); ?>
<?php MenuRenderer::render($menus['footer'] ?? [], 'mobile'); ?>
```

Хранение: `content/_menus.yaml`. Редактор: `/admin-panel/menu`.

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
