# Changelog

## 0.7.0 — 2026-10-28

### Исправлено: флажки в заявках

- Снятый флажок сохранялся как `true`: старый `forms.js` отправлял для него пустую строку, а сервер считал любое
  значение отмеченным. Из-за этого обязательное согласие проходило без галочки. Теперь `FormSubmitHandler`
  нормализует флажок: `''`, `0`, `false`, `off`, `no`, `нет` и отсутствие поля — `false`; `1`, `true`, `on`, `yes`,
  `да` и другое непустое значение — `true`. Обязательный флажок без отметки — 422.
- `forms.js`: одиночный флажок отправляется как `true`/`false`, группа (`name="x[]"` или несколько с одним именем) —
  всегда списком. Отправка без JS поддерживается.
- Изменение контракта форм: после обновления страницы с формами нужно открыть заново (кэш и открытые формы со старой подписью не принимаются).
- Тест `tests/forms.php`, раздел «Флажки (checkbox)» в `docs/09-forms.md`. Уже сохранённые заявки не меняются.

### sitemap.xml, robots.txt и llms.txt в ядре

- Ядро отдаёт `/sitemap.xml`, `/robots.txt` и `/llms.txt`, построенные из опубликованных страниц
  (`includes/SeoFiles.php`). Без черновиков, страниц с `noindex: true` или `sitemap: false`, служебных `_…`,
  перекрытых префиксом плагина и файлов переводов. Абсолютные адреса, `lastmod` по времени изменения файла, при
  мультиязычности — языковые версии с `hreflang` и `x-default`.
- robots.txt: `Allow: /`, `Disallow` для `/admin-panel`, `/api/`, `/*?preview=`, свои правила, `Sitemap:`.
  llms.txt: название, описание, список страниц и дополнение из `content/_llms.txt`.
- Настройки — ключ `seo` в `config/site.php` (необязателен): `sitemap`/`robots`/`llms`, `site_url`,
  `robots_disallow`, `site_name`, `llms_description`. Фильтры плагинов `sitemap`, `robots`, `llms`.
- Новый флаг front matter `noindex: true` — страница отдаётся с `X-Robots-Tag: noindex` и не попадает в файлы.
- Физические `robots.txt`/`sitemap.xml`/`llms.txt` в корне сайта по-прежнему важнее: Apache отдаёт их до ядра.
  Корневой `.htaccess` не менялся. Тест `tests/seo.php`, документация `docs/13-seo.md`.

### Страница 404 темы

- Если в активной теме есть `404.php`, ядро показывает его для несуществующих и неопубликованных страниц —
  статус 404, `X-Robots-Tag: noindex`, `Cache-Control: no-store`. Для `POST`, `/api/…`, предпросмотра, маршрутов
  плагинов и тем без `404.php` — прежняя заглушка. В теме-примере есть образец. Плагин `theme-404` больше не нужен
  (продолжает работать, если стоит). Документация — «Страница 404» в `docs/12-themes.md`.

### Прочее

- Тестовые плагины и пример `hello` объявляют `"core": ">=0.5.0"` вместо `^0.5` — тесты снова проходят на 0.6+.
- Плагинам с фильтрами `sitemap`/`robots`/`llms` нужно `"core": ">=0.7.0"`; API 0.6 не менялся.

## 0.6.0 — 2026-10-28

### Обновление плагинов из админки

- Новый `PluginUpdater`: плагины с полем `repository` (`"owner/repo"` на GitHub) в `plugin.json` проверяют
  `releases/latest` своего репозитория и обновляются по схеме ядра: архив `{id}-X.Y.Z.zip` → проверка
  (одна папка `{id}/`, `id`/`version`/`repository` как у тега и плагина, `core` подходит к ядру; zip-slip,
  абсолютные пути, симлинки, лишние папки, размер и число файлов — отказ) → резервная копия
  `cache/plugin-backup-{id}-{версия}.zip` → замена `plugins/{id}/` через `.{id}.next`/`.{id}.old` →
  `onInstall`-миграции; если плагин не загрузился — откат папки. `plugin-data/` не трогается.
- Сеть: только https и хосты `api.github.com`, `github.com`, `objects.githubusercontent.com`,
  `release-assets.githubusercontent.com`, редиректы проверяются вручную (не больше 5), таймауты и лимит размера.
  Кеш `cache/plugin-updates.json`, TTL — `UPDATE_CHECK_TTL`, как у ядра.
- «Система»: кнопка «Проверить обновления плагинов», у плагина — «Доступна vX.Y.Z», ссылка на релиз,
  «Обновить до vX.Y.Z» (POST + CSRF, с подтверждением), причины, если обновить нельзя, и ошибки проверки.
- Подписей нет: обновление выполняет код из релиза — указывайте `repository` только для доверенных репозиториев.
  Новые плагины из админки не устанавливаются — по-прежнему распаковкой архива в `plugins/`.
- Тест `tests/plugin-updates.php` (сеть подменена), документация — «Обновление плагинов» в `docs/10-plugins.md`.

## 0.5.6 — 2026-10-27

### Исправлено: кракозябры в уведомлениях админки

- Тосты после сохранения (страница, меню) показывали «Ð¡Ð¾Ñ…Ñ€Ð°Ð½ÐµÐ½Ð¾» вместо «Сохранено»: JSON в заголовке
  `HX-Trigger` уходил с сырым UTF-8 (`JSON_UNESCAPED_UNICODE`), а браузеры читают заголовки как latin1.
  Теперь значение собирает `AdminRouter::toastTriggerHeader()` — чистый ASCII, кириллица и эмодзи как `\uXXXX`,
  битый UTF-8 заменяется, CR/LF в заголовок не попадают. Это единственное место, где ядро выставляет `HX-Trigger`.
- Новый тест `tests/toast.php`: кириллица, эмодзи, кавычки, переводы строк и битый UTF-8 доходят до «браузера»
  (декодирование latin1 → `JSON.parse`) без искажений.

### Исходники CSS в репозитории ядра

- Сборка стилей перенесена в ядро: `scripts/build-css.mjs` и `scripts/css/{admin,app,site-chrome}.css`
  (раньше жили только в workspace). Артефакты (`assets/css/admin.css`, `assets/css/site-chrome.css`, CSS темы example)
  собираются байт-в-байт как в 0.5.5. Tailwind CLI по-прежнему берётся из `node_modules` workspace
  (`npm run build:css`, или `MOTORCHK_WORKSPACE=…`).
- `scripts/` — только для разработки и, как `tests/`, в `motorchk-core-X.Y.Z.zip` не попадает: состав и структура
  архива (`motorchk-core-X.Y.Z/` = `public_html/core/`) не изменились.

## 0.5.5 — 2026-10-27

### Новый дизайн админки

- Админка (`admin-panel/templates/*`) перерисована в строгой «сеточной» теме: бумажный фон, тёмный текст,
  ультрамариновый акцент, без скруглений и теней. Шрифт Golos Text подключён локально
  (`assets/css/fonts/`, лицензия OFL рядом), внешних запросов нет.
- Шаблоны: шапка и навигация (`mc-nav`), вход, мастер установки, страницы, заявки, «Система», меню. Ссылки, формы,
  имена полей, CSRF, htmx-атрибуты, маршруты и строки переводов не изменились.
- Узкие экраны: таблица заявок прокручивается внутри своего блока и не расширяет страницу на 320–414 px.
- `assets/css/admin.css` собирается из отдельного исходника `scripts/css/admin.css` (Tailwind + токены темы +
  компоненты `mc-*`). Набор утилит v0.5.4 сохранён safelist-ом, поэтому шаблоны плагинов выглядят как раньше,
  но в новой палитре. `tokens.css`/`base.css` встроены в `admin.css`.
- Тест `tests/plugins.php`: проверка пункта меню плагина обновлена под новые классы (`mc-nav__link--plugin`,
  `aria-current="page"`).

## 0.5.4 — 2026-10-27

### Установка без ручных шагов: `motorchk-install-X.Y.Z.zip`

- В релизе второй артефакт — `motorchk-install-X.Y.Z.zip`: распаковать в `public_html/`. Внутри `core/` и корневые
  `.htaccess` и `index.php` (копии из `core/skeleton/`). Мастер установки открывается на `/admin-panel/` сразу после
  распаковки. Раньше корневого `.htaccess` до установки не было, и `/admin-panel/` отвечал 404.
- `motorchk-core-X.Y.Z.zip` не изменился (папка `motorchk-core-X.Y.Z/` = `public_html/core/`): его по-прежнему скачивает
  обновление из админки, существующих сайтов и self-update это не касается.
- Сборка обоих zip вынесена в `.github/build-release.sh`, workflow публикует оба.
- Установщик по-прежнему не перезаписывает существующие файлы: `.htaccess` и `index.php` из установочного zip остаются.
- README и docs/08: шаги установки. Тест в `tests/themes.php`: артефакты релиза, выбор zip обновлением, установщик
  не перезаписывает существующие файлы и копирует скрытые файлы skeleton.

## 0.5.3 — 2026-10-27

### `/core/` закрывает само ядро: `core/.htaccess`

- Правило «из `/core/` снаружи только `assets/`, остальное — 404» (`<If "-z %{ENV:REDIRECT_STATUS}">` +
  `RedirectMatch 404 ^/core/(?!assets/)`) перенесено из корневого `.htaccess` в `core/.htaccess`. Файл входит в release zip
  и заменяется при любом обновлении ядра (self-update, ручная замена `core/`) — корневой `.htaccess` править не нужно.
- `skeleton/.htaccess`: убраны блок 0.5.2 и семь строк `RedirectMatch 403 ^/core/…` (их перекрывает 404 из `core/.htaccess`).
- Существующим сайтам ничего делать не нужно: правила для `/core/` в старом корневом `.htaccess` (0.5.2 и раньше)
  с `core/.htaccess` не конфликтуют, их можно оставить или удалить.
- Тест в `tests/themes.php` проверяет `core/.htaccess` и отсутствие правил `/core/` в skeleton.

## 0.5.2 — 2026-10-27

### Безопасность: из `/core/` снаружи доступна только `assets/`

- `skeleton/.htaccess`: прямой запрос к `/core/…`, кроме `/core/assets/…`, получает 404 —
  `<If "-z %{ENV:REDIRECT_STATUS}">` с `RedirectMatch 404 ^/core/(?!assets/)`. Раньше отдавались `VERSION`, `README.md`,
  `CHANGELOG.md`, `composer.json`, `composer.lock` (версии зависимостей) и открывался `/core/admin-panel/index.php` напрямую.
- Условие по `REDIRECT_STATUS` обязательно: `/admin-panel/…` внутренне переписывается в `core/admin-panel/index.php`,
  и голый `RedirectMatch` срабатывал бы и на этом внутреннем переходе — админка отвечала бы 404.
- **При обновлении:** корневой `.htaccess` сайта не меняют ни обновление из админки, ни `install/migrate.php` —
  перенесите блок из `core/skeleton/.htaccess` вручную ([docs/08-deploy-and-upgrade.md](docs/08-deploy-and-upgrade.md)).
- Тесты в `tests/themes.php`: правило в skeleton, какие пути закрыты и открыты, все ссылки ядра на `/core/` ведут в `assets/`.

## 0.5.0 — 2026-10-27

Выпускается тегом `v0.5.1`: `release.sh` повышает patch от `VERSION` (так же, как 0.4.0 → `v0.4.1`).

### API плагинов: формы, почта, заявки

- `PluginContext` получил тонкие методы, чтобы плагины не обращались к `Mailer`, `RateLimiter`, `SubmissionStore`, `Form`, `SiteLocale` и `Env` напрямую: `absoluteUrl()`, `honeypotName()`, `rateLimit($key, $limit, $windowSec)` (ключ отдельный для каждого плагина), `mail($subject, $body, $to, $replyTo = null)` (получатель обязателен, без подстановки `SMTP_TO`), `submissions()->create()` / `markMail($formId, $id, 'sent' | 'failed', $error)` (новый класс `PluginSubmissions`), `env($key)`. Описание — [docs/10-plugins.md](docs/10-plugins.md), тесты в `tests/plugins.php`.
- `Mailer::send()` — необязательный пятый аргумент `$replyTo` (заголовок Reply-To).
- Плагины, которые используют новые методы, указывают `"core": "^0.5"`.
- **Важно при обновлении:** для `0.x` ограничение `^0.4` означает `>=0.4.0 <0.5.0`, поэтому плагины с `"core": "^0.4"` на ядре 0.5 не загрузятся. Поднимите ограничение до `"^0.5"` (или `">=0.4.0 <0.6.0"`, если новые методы не нужны). Пример `docs/examples/plugins/hello` и тестовые плагины — `^0.5`.

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
