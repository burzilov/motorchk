# Архитектура

Ядро CMS живёт в `public_html/core/` и обновляется атомарно (замена каталога). Контент и тема сайта — снаружи ядра.

| Путь | Зона |
|------|------|
| `core/includes/`, `core/admin-panel/`, `core/vendor/` | ядро |
| `core/assets/` | CSS/JS админки, библиотек и chrome публичных страниц (`site-chrome.css`) |
| `core/skeleton/` | шаблон первичной установки |
| `content/`, `themes/`, `plugins/`, `media/` | сайт (`media/` — публичные файлы содержимого, [12-themes.md](12-themes.md)) |
| `.env`, `config/site.php`, `cache/`, `submissions/`, `plugin-data/` | сайт / runtime |

Публичный front controller: `public_html/index.php` → `core/includes/bootstrap.php`.  
Админка: rewrite `/admin-panel` → `core/admin-panel/index.php`.

Конфиг сайта задаёт пути (`content_path`, `themes_path`, `forms_path`, `submissions_path`, `core_path`, …); bootstrap подмешивает их после установки.

Плагины: `core/includes/PluginManager.php` читает `plugins/*/plugin.json`; `AdminRouter` передаёт `/admin-panel/plugins/{id}/…` плагину после проверки входа и CSRF, `Router` до Markdown-страниц обслуживает `/plugin-assets/{id}/…` и публичные префиксы плагинов. API — `PluginContext`, `PluginRequest`. Подробнее — [10-plugins.md](10-plugins.md).

Служебные файлы `/sitemap.xml`, `/robots.txt`, `/llms.txt` строит ядро из опубликованных страниц (`core/includes/SeoFiles.php`, настройки — `seo` в `config/site.php`). Подробнее — [13-seo.md](13-seo.md).

Формы заявок: meta в `content/forms/*.yaml`, разметка в теме через `Form::begin`/`end`, приём `POST /api/forms/submit`, хранение в `submissions/`. Подробнее — [09-forms.md](09-forms.md).

## Темы и шаблоны страниц

Визуальные темы расположены вне ядра: `themes/{theme}/`. Активная тема хранится в `content/_meta.yaml` в поле `theme`; skeleton создаёт поставляемую тему `example`.

```text
themes/example/
├── default.php
├── landing.php
├── partials/
└── assets/
    ├── css/
    ├── js/pages/
    ├── fonts/
    └── images/
```

Front matter `template` выбирает PHP-файл в активной теме: `themes/{theme}/{template}.php`. Отсутствующий шаблон — ошибка 500. Публична только статика по `/themes/{theme}/assets/...`; все остальные пути внутри `/themes/` возвращают 403.

Данные для шаблонов (меню, переменные, кеш страниц, картинки содержимого) — [12-themes.md](12-themes.md).

`themes/example/` — управляемая тема-пример. Self-update всегда целиком заменяет её версией из release; пользовательские темы не затрагиваются. Для сайта создайте отдельный каталог темы, поскольку ручные изменения в `example/` будут перезаписаны.

Служебный UI на публичных страницах (баннер админа, стили inline-edit) живёт в ядре: `/core/assets/css/site-chrome.css` и views `core/includes/views/{site-chrome,preview-banner}.php`. Тема подключает их явно; копировать эти стили в тему не нужно.

Breaking в 0.2.0: прежние `templates/*.php` и корневой `assets/` не поддерживаются.
