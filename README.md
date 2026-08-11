# motorchk CMS

File-based PHP CMS без базы данных. Этот репозиторий — **только ядро** (`public_html/core/` на сайте).

## Требования

- PHP 8.4+
- Apache с `mod_rewrite` (или аналог)
- Расширения: `json`, `mbstring`, `xml`; для self-update — `zip` и curl/`allow_url_fopen`

## Установка на LAMP

1. Скачайте release zip `motorchk-core-X.Y.Z.zip` из [Releases](https://github.com/burzilov/motorchk/releases).
2. Распакуйте содержимое в `public_html/core/`.
3. Откройте `/admin-panel/` — мастер установки скопирует skeleton (контент, тему, `.htaccess`, `index.php`) и создаст `.env`.
4. Войдите в админку.

Composer на сервере не нужен: `vendor/` уже внутри release zip.

## Обновление

В админке: **Система** — проверка GitHub Releases (`burzilov/motorchk`) и кнопка обновления. Контент, пользовательские темы и `.env` не затрагиваются; `themes/example/` заменяется версией из release.

Вручную: замените папку `core/` на новую версию и откройте сайт (миграции применятся при апдейте через админку; при ручной замене выполните логику из `install/migrate.php` при необходимости).

## Структура на сайте

```text
public_html/
├── core/          ← этот репозиторий / release zip
├── content/       ← сайт
├── themes/        ← темы сайта: {theme}/, PHP-шаблоны и assets внутри темы
├── config/site.php
├── index.php
├── .htaccess
└── .env
```

## Темы

Активная тема задаётся полем `theme` в `content/_meta.yaml`; администратор может переключить её на странице **Система**. Шаблон страницы задаётся front matter `template` и находится по пути `themes/{theme}/{template}.php`. Статика темы доступна только по `/themes/{theme}/assets/...`; PHP-шаблоны и `partials/` закрыты от HTTP.

`themes/example/` — поставляемая тема-пример. При каждом self-update она полностью заменяется версией из release, включая удаление файлов, которых больше нет в новой версии. Не используйте её для сайта и не вносите в неё изменения: создайте отдельную тему в `themes/{name}/`. Self-update не меняет пользовательские темы.

Служебный UI (баннер сессии админа, inline-edit) подключается из ядра: `site-chrome.css` и `core/includes/views/`. См. `themes/example/README.md`.

Breaking в 0.2.0: сайт должен использовать `themes/{name}/`; плоские `templates/*.php` и корневой `assets/` больше не поддерживаются.
