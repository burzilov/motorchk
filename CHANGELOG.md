# Changelog

## 0.5.0 — 2026-10-27

Выпускается тегом `v0.5.1`: `release.sh` повышает patch от `VERSION` (так же, как 0.4.0 → `v0.4.1`).

### API плагинов: формы, почта, заявки

- `PluginContext` получил тонкие методы, чтобы плагины не обращались к `Mailer`, `RateLimiter`, `SubmissionStore`, `Form`, `SiteLocale` и `Env` напрямую: `absoluteUrl()`, `honeypotName()`, `rateLimit($key, $limit, $windowSec)` (ключ отдельный для каждого плагина), `mail($subject, $body, $to, $replyTo = null)` (получатель обязателен, без подстановки `SMTP_TO`), `submissions()->create()` / `markMail($formId, $id, 'sent' | 'failed', $error)` (новый класс `PluginSubmissions`), `env($key)`. Описание — [docs/10-plugins.md](docs/10-plugins.md), тесты в `tests/plugins.php`.
- `Mailer::send()` — необязательный пятый аргумент `$replyTo` (заголовок Reply-To).
- Плагины, которые используют новые методы, указывают `"core": "^0.5"`.

### Исправления

- «Заявки» → «Открыть» показывала пустую заявку («ID» — id плагина), если установлен плагин с пунктом меню в админке: цикл меню плагинов в `AdminRouter::render()` затирал переменную шаблона `$item`. Тест в `tests/plugins.php`.

## До 0.5.0

### Поддержка тем

- Страница без `og_title` / `og_description` в front matter больше не даёт предупреждений PHP: берутся `title` / `description`.
- Кеш страниц сверяется с файлами: страница и перевод — по mtime и размеру `.md`, дерево страниц и меню — по отпечатку всех `.md` и `.yaml` в `content/` (`PageLoader::contentStamp()`). Правки `content/` вне админки видны сразу. Меню на других языках теперь тоже кешируются. Старые записи кеша без отпечатка просто перечитываются.
- `MenuRenderer` без Tailwind и Alpine.js: `MenuRenderer::render($items, ['class' => 'nav', 'depth' => 2])` и `MenuRenderer::html()` выводят `<ul class="nav">` с `nav__item`, `nav__link`, `nav__sub`, `is-active`, `has-children` и `aria-current="page"`. Второй аргумент теперь массив опций — прежние `'desktop'` / `'mobile'` не поддерживаются; разметку с классами фреймворка тема строит сама из `$menu` / `$menus` (так сделана тема-пример, `partials/menu.php`).
- Пункт меню: новое поле `current` (ровно страница пункта); `active` и `current` считаются и для внутренних url-пунктов (`/customizer/` активен на `/customizer/ultra`).
- Публичная папка файлов содержимого `media/` → `/media/…`: `media/.htaccess` (только картинки, видео, аудио, PDF; без PHP и листинга; nosniff и CSP для SVG), `Media::url()`, `Media::path()`, конфиг `media_path`. Создаётся при установке и в `install/migrate.php`; префикс `media` зарезервирован.
- Переменная шаблона темы `$plugins` (`PluginManager` или `null`): тема берёт службы плагинов — `$plugins?->service('customizer')`.
- Документация для тем `docs/12-themes.md` (переменные шаблона, меню, `media/`, кеш), тесты `php tests/themes.php`.

### Чистка (0.4.2)

- `SiteLocale::localized($values, $locale = null)` — единственная реализация выбора значения по языку (язык → основной → первое непустое). `PluginContext::localized()` вызывает её; копия в службе плагина i18n убрана (i18n 0.1.3).
- CSRF в админке: `AdminRouter::requireCsrf()` бросает `CsrfException` вместо `exit`, `dispatchAdmin()` отвечает `403` `CSRF validation failed`. Ответ прежний, но запрос завершается обычным путём — тесты CSRF в `tests/plugins.php` идут в том же процессе, без подпроцессов.

### Мультиязычность и точки расширения (0.4.0)

- Службы плагинов: `provides` в `plugin.json`, `PluginContext::provide()` / `service()` (владелец загружается при первом обращении, нет службы — `null`).
- Хуки сайта: событие `request` до маршрутизации (`SiteRequest`: переписать путь, задать язык, ответить редиректом) и фильтры `url`, `menu`, `head`, `output`; объявляются в `plugin.json` `hooks`, в админке не работают.
- Язык запроса в ядре: `SiteLocale`, интерфейс `LocaleProvider` для службы `i18n`; `PluginContext::locale()`, `defaultLocale()`, `locales()`, `localized()`, `publicUrl($path, $locale)`.
- Переводы Markdown-страниц `about.en.md` рядом с `about.md`: показ по языку с откатом на оригинал (canonical), hreflang только для существующих переводов, `<html lang>` по языку содержимого, подписи меню из заголовков переводов. Только при активной службе `i18n`.
- Переменные темы `$locale`, `$defaultLocale`, `$contentLocale`, `$alternates`, `$i18n`; тема-пример выводит `<html lang>` из `$contentLocale`.
- `renderPublic()`: опции `styles`, `head_scripts` (в `<head>` до Alpine.js) и `alternates`.
- `PluginContext::readJsonStrict($rel, $default = [])`: нет файла — `$default`, повреждённый JSON или не объект/массив — `PluginDataException` с именем файла; ничего не перезаписывает. `readJson()` не изменился (по-прежнему молча возвращает `$default` для повреждённого файла).
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
