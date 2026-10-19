# Changelog

## Unreleased

### Плагины (0.3.0)

- Плагины в `public_html/plugins/{id}/` рядом с `core/`: установка распаковкой архива, плагин в папке включён, self-update их не трогает.
- Манифест `plugin.json` (`id`, `name`, `version`, `core`, `entry`, `admin.menu`, `public.prefix`, `autoload`); `PluginManager` проверяет его и ограничение версии ядра (`^0.3`, `>=0.3.0 <0.4.0`, …).
- Список плагинов с версией, совместимостью, записью в папку данных, ошибками и конфликтами — на странице «Система» (только чтение).
- API `PluginContext`/`PluginRequest`: маршруты админки и сайта, шаблоны в layout админки (в т.ч. полноэкранный) и в шаблоне активной темы, JSON/raw-ответы, CSRF, данные в `plugin-data/{id}/` через `FileWriter`, install/upgrade-хук с версиями, PSR-4 autoload.
- Админка: `/admin-panel/plugins/{id}/…` после входа, CSRF на `POST`/`PUT`/`PATCH`/`DELETE` (поле `_csrf` или `X-CSRF-Token`), пункты меню плагинов после встроенных, полноэкранный вариант layout, `title`/`styles`/`scripts` страницы.
- Сайт: публичный префикс плагина важнее Markdown-страниц; статика `/plugin-assets/{id}/…` только из `assets/` плагина с белым списком типов; `.htaccess` с запретом в `plugins/` и `plugin-data/` создаёт ядро, в `skeleton/.htaccess` — `RedirectMatch 403`.
- `PageScripts` принимает скрипты `/plugin-assets/{id}/…js`.
- Конфиг: `plugins_path`, `plugin_data_path`.
- Пример плагина `docs/examples/plugins/hello`, документация `docs/10-plugins.md`, тесты `php tests/plugins.php` (в release zip не входят).

### Формы и почта

- SMTP-настройки и смена пароля админа на странице «Система» (`EnvWriter`, `.env`).
- Декларативные meta-формы в `content/forms/*.yaml`, HTML в теме через `Form::begin`/`end`, HMAC-контракт полей.
- `POST /api/forms/submit`, сохранение в `submissions/`, список заявок в админке.
- PHPMailer: отправка писем и проверка SMTP-соединения (handshake).
- Антиспам: CSRF, honeypot, timing, rate-limit 10/час.

### Меню

- Именованные меню в `content/_menus.yaml` (locations), API `$menu` / `$menus`.
- Конфиг: `menu_file` → `content/_menus.yaml`.
