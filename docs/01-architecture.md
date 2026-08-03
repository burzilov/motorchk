# Архитектура

Ядро CMS живёт в `public_html/core/` и обновляется атомарно (замена каталога). Контент и тема сайта — снаружи ядра.

| Путь | Зона |
|------|------|
| `core/includes/`, `core/admin-panel/`, `core/vendor/` | ядро |
| `core/assets/` | JS/CSS админки и библиотек движка |
| `core/skeleton/` | шаблон первичной установки |
| `content/`, `templates/`, `assets/` | сайт |
| `.env`, `config/site.php`, `cache/` | сайт / runtime |

Публичный front controller: `public_html/index.php` → `core/includes/bootstrap.php`.  
Админка: rewrite `/admin-panel` → `core/admin-panel/index.php`.

Конфиг сайта задаёт пути (`content_path`, `templates_path`, `core_path`, …); bootstrap подмешивает их после установки.
