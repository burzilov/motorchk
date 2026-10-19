# Changelog

## Unreleased

### Мультиязычность и точки расширения (0.4.0)

- Службы плагинов: `provides` в `plugin.json`, `PluginContext::provide()` / `service()` (владелец загружается при первом обращении, нет службы — `null`).
- Хуки сайта: событие `request` до маршрутизации (`SiteRequest`: переписать путь, задать язык, ответить редиректом) и фильтры `url`, `menu`, `head`, `output`; объявляются в `plugin.json` `hooks`, в админке не работают.
- Язык запроса в ядре: `SiteLocale`, интерфейс `LocaleProvider` для службы `i18n`; `PluginContext::locale()`, `defaultLocale()`, `locales()`, `localized()`, `publicUrl($path, $locale)`.
- Переводы Markdown-страниц `about.en.md` рядом с `about.md`: показ по языку с откатом на оригинал (canonical), hreflang только для существующих переводов, `<html lang>` по языку содержимого, подписи меню из заголовков переводов. Только при активной службе `i18n`.
- Переменные темы `$locale`, `$defaultLocale`, `$contentLocale`, `$alternates`, `$i18n`; тема-пример выводит `<html lang>` из `$contentLocale`.
- `renderPublic()`: опции `styles`, `head_scripts` (в `<head>` до Alpine.js) и `alternates`.
- Админка: скрипты плагина (`scripts`) подключаются до Alpine.js — компоненты регистрируются в `alpine:init`; прежний порядок — `scripts_after_alpine`.
- Без плагина со службой `i18n` вывод сайта и админки не меняется.
- Документация `docs/11-i18n.md`, тесты `php tests/i18n.php`; пример `hello` требует `^0.4`.
- Плагины с `"core": "^0.3"` на 0.4 не загружаются (правило `^` для 0.x) — поднимите ограничение.

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
