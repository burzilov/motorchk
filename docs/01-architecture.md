# Архитектура

Ядро CMS живёт в `public_html/core/` и обновляется атомарно (замена каталога). Контент и тема сайта — снаружи ядра.

| Путь | Зона |
|------|------|
| `core/includes/`, `core/admin-panel/`, `core/vendor/` | ядро |
| `core/assets/` | JS/CSS админки и библиотек движка |
| `core/skeleton/` | шаблон первичной установки |
| `content/`, `themes/` | сайт |
| `.env`, `config/site.php`, `cache/` | сайт / runtime |

Публичный front controller: `public_html/index.php` → `core/includes/bootstrap.php`.  
Админка: rewrite `/admin-panel` → `core/admin-panel/index.php`.

Конфиг сайта задаёт пути (`content_path`, `themes_path`, `core_path`, …); bootstrap подмешивает их после установки.

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

`themes/example/` — управляемая тема-пример. Self-update всегда целиком заменяет её версией из release; пользовательские темы не затрагиваются. Для сайта создайте отдельный каталог темы, поскольку ручные изменения в `example/` будут перезаписаны.

Breaking в 0.2.0: прежние `templates/*.php` и корневой `assets/` не поддерживаются.
