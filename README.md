# motorchk CMS

File-based PHP CMS без базы данных. Этот репозиторий — **только ядро** (`public_html/core/` на сайте).

## Требования

- PHP 8.3+ (рекомендуется актуальная исправленная версия PHP)
- Apache с `mod_rewrite` (или аналог)
- Расширения: `json`, `mbstring`, `xml`; для self-update — `zip` и curl/`allow_url_fopen`

## Установка на LAMP

1. Скачайте `motorchk-install-X.Y.Z.zip` из [Releases](https://github.com/burzilov/motorchk/releases).
2. Распакуйте его в корень сайта `public_html/` — появятся `core/`, `.htaccess` и `index.php`.
3. Откройте сайт (или `/admin-panel/`) — откроется мастер установки: задайте логин и пароль админа.
   Мастер скопирует недостающее из `core/skeleton/` (контент, тему-пример, `media/`) и создаст `.env`.
4. Войдите в админку.

`motorchk-core-X.Y.Z.zip` из того же релиза — только ядро (папка = `public_html/core/`) для обновления;
его скачивает обновление из админки. Установочный zip не распаковывайте поверх работающего сайта —
он заменит корневые `.htaccess` и `index.php`.

Composer на сервере не нужен: `vendor/` уже внутри release zip.

## Обновление

В админке: **Система** — проверка GitHub Releases (`burzilov/motorchk`) и кнопка обновления. Контент, пользовательские темы, плагины (`plugins/`, `plugin-data/`) и `.env` не затрагиваются; `themes/example/` заменяется версией из release.

Вручную: замените папку `core/` содержимым `motorchk-core-X.Y.Z.zip` и откройте сайт (миграции применятся при апдейте через админку; при ручной замене выполните логику из `install/migrate.php` при необходимости).

## Структура на сайте

```text
public_html/
├── core/          ← этот репозиторий / release zip
├── content/       ← сайт (в т.ч. content/forms/*.yaml)
├── submissions/   ← заявки с форм (закрыто от HTTP)
├── themes/        ← темы сайта: {theme}/, PHP-шаблоны и assets внутри темы
├── plugins/       ← плагины: {id}/plugin.json (закрыто от HTTP)
├── plugin-data/   ← данные плагинов (закрыто от HTTP)
├── config/site.php
├── index.php
├── .htaccess
└── .env
```

## Плагины

Плагин устанавливается распаковкой архива в `public_html/plugins/{id}/`; плагин в папке включён. Плагины добавляют страницы и JSON API в админку (`/admin-panel/plugins/{id}/`), публичные адреса (`/{prefix}/`) и статику (`/plugin-assets/{id}/`). Self-update ядра не трогает `plugins/` и `plugin-data/`. Подробнее — [docs/10-plugins.md](docs/10-plugins.md), пример — [docs/examples/plugins/hello](docs/examples/plugins/hello/).

## Темы

Активная тема задаётся полем `theme` в `content/_meta.yaml`; администратор может переключить её на странице **Система**. Шаблон страницы задаётся front matter `template` и находится по пути `themes/{theme}/{template}.php`. Статика темы доступна только по `/themes/{theme}/assets/...`; PHP-шаблоны и `partials/` закрыты от HTTP.

`themes/example/` — поставляемая тема-пример. При каждом self-update она полностью заменяется версией из release, включая удаление файлов, которых больше нет в новой версии. Не используйте её для сайта и не вносите в неё изменения: создайте отдельную тему в `themes/{name}/`. Self-update не меняет пользовательские темы.

Служебный UI (баннер сессии админа, inline-edit) подключается из ядра: `site-chrome.css` и `core/includes/views/`. См. `themes/example/README.md`.

Breaking в 0.2.0: сайт должен использовать `themes/{name}/`; плоские `templates/*.php` и корневой `assets/` больше не поддерживаются.

## Лицензия

[MIT](LICENSE) © 2026 Ivan Burzilov.
