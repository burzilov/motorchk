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
    'active' => false,          // страница пункта или раздел, в котором открыта страница
    'current' => false,         // ровно страница пункта (для aria-current="page")
    'broken' => false,
    'children' => [],
]
```

Тема-пример рисует меню сама из этих данных (Tailwind и Alpine.js): `partials/menu.php`.
Нейтральная разметка без классов фреймворков — хелпером ядра:

```php
<?php MenuRenderer::render($menus['footer'] ?? [], ['class' => 'footer-menu']); ?>
<?= MenuRenderer::html($menu ?? [], ['class' => 'nav', 'depth' => 2]) ?>
```

Разметка, классы и состояния — `core/docs/12-themes.md`.

Хранение: `content/_menus.yaml`. Редактор: `/admin-panel/menu`.

## Формы заявок

Meta: `content/forms/{id}.yaml`. В шаблоне:

```php
<?php Form::begin('contact'); ?>
  <!-- HTML полей -->
<?php Form::end(); ?>
```

Пример: `partials/contact-form.php`. Скрипт отправки: `/core/assets/js/forms.js` (подключён в `partials/scripts.php`). Документация: `core/docs/09-forms.md`.

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
