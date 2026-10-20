# Плагины

Плагин — папка `public_html/plugins/{id}/` рядом с `core/`. Она принадлежит сайту, как `themes/` и `content/`: self-update заменяет только `core/` и не трогает ни `plugins/`, ни данные плагинов.

**Установка** — распаковать архив плагина в `plugins/` (в архиве — папка `{id}/` с `plugin.json`). **Плагин в папке = включён.** Удаление — удалить папку `plugins/{id}/`; данные в `plugin-data/{id}/` остаются.

Невалидные и несовместимые плагины не загружаются. Список всех плагинов с причинами (версия, совместимость с ядром, запись в папку данных, конфликты префиксов) — на странице **Система**, только чтение.

Пример — [`examples/plugins/hello/`](examples/plugins/hello/): страница в админке, JSON API с CSRF, полноэкранный режим, публичная страница `/hello/`. Для пробы скопируйте папку в `public_html/plugins/hello/`.

## Структура

```text
public_html/
├── core/
├── plugins/                 ← код плагинов (закрыт от HTTP)
│   ├── .htaccess            ← создаёт ядро: Require all denied
│   └── hello/
│       ├── plugin.json      ← манифест
│       ├── plugin.php       ← точка входа (entry)
│       ├── lib/Hello/…      ← классы (autoload)
│       ├── templates/…      ← PHP-шаблоны плагина
│       └── assets/…         ← статика → /plugin-assets/hello/…
└── plugin-data/             ← данные плагинов (закрыто от HTTP)
    ├── .htaccess            ← создаёт ядро
    └── hello/
        ├── .plugin-state.json   ← установленная версия
        └── …
```

Пути задаются в `config/site.php`: `plugins_path`, `plugin_data_path` (по умолчанию `public_html/plugins` и `public_html/plugin-data`; в старых `site.php` ключей нет — действуют значения по умолчанию из bootstrap).

## plugin.json

```json
{
    "id": "hello",
    "name": "Hello — пример плагина",
    "version": "1.0.0",
    "core": "^0.4",
    "entry": "plugin.php",
    "description": "Необязательное описание",
    "admin": { "menu": "Hello" },
    "public": { "prefix": "hello" },
    "autoload": { "Hello\\": "lib/Hello" },
    "provides": [],
    "hooks": []
}
```

| Поле | Обязательное | Правила |
|------|--------------|---------|
| `id` | да | `^[a-z0-9-]+$`, совпадает с именем папки |
| `name` | да | строка до 100 символов |
| `version` | да | `X.Y.Z` (допускается `-beta.1`, `+build`) |
| `core` | да | ограничение версии ядра: `^0.3`, `~0.3.1`, `>=0.3.0 <0.4.0`, `0.3.0`, `*` |
| `entry` | нет | `.php` внутри плагина, по умолчанию `plugin.php` |
| `admin.menu` | нет | подпись пункта меню админки (до 40 символов) → `/admin-panel/plugins/{id}` |
| `public.prefix` | нет | первый сегмент URL сайта, который занимает плагин (`^[a-z0-9-]+$`) |
| `autoload` | нет | PSR-4: `{"Namespace\\": "папка/"}` внутри плагина |
| `provides` | нет | службы для других плагинов: `["i18n"]` (имена `^[a-z0-9-]+$`), см. «Службы» |
| `hooks` | нет | хуки сайта, на которые плагин подписывается: `request`, `url`, `menu`, `head`, `output` ([11-i18n.md](11-i18n.md)) |

`^0.3` означает `>=0.3.0 <0.4.0` (для `0.x` минорная версия считается несовместимой), `^1.2` — `>=1.2.0 <2.0.0`. Поэтому плагины с `"core": "^0.3"` на ядре 0.4 не загружаются: API 0.3 в 0.4 сохранён, поднимите ограничение до `">=0.3.0 <0.5.0"` или `"^0.4"`.

Зарезервированные префиксы: `admin-panel`, `api`, `core`, `themes`, `content`, `config`, `cache`, `submissions`, `plugins`, `plugin-data`, `plugin-assets`.

Конфликты:

- два плагина с одним префиксом — загружается первый по алфавиту, второй получает ошибку, первый — предупреждение;
- префикс совпадает со страницей `content/{prefix}.md` или папкой `content/{prefix}/` — плагин важнее, страница перекрыта (предупреждение на «Системе»);
- два плагина объявили одну службу в `provides` — служба за первым по алфавиту, второй получает предупреждение.

## Точка входа

Файл `entry` возвращает функцию, которой ядро передаёт `PluginContext`:

```php
<?php

return static function (PluginContext $plugin): void {
    $plugin->onInstall(static function (?string $from, string $to, PluginContext $plugin): void {
        // первая установка: $from === null; обновление: '1.0.0' → '1.1.0'
    });

    $plugin->adminRoute('GET', '/', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->render('admin', ['items' => $plugin->readJson('items.json', [])], ['title' => 'Мой плагин']);
    });

    $plugin->adminRoute('PUT', '/api/items/{id}', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->writeJson('items/' . $request->param('id') . '.json', $request->json() ?? []);
        $plugin->json(['ok' => true]);
    });

    $plugin->publicRoute('GET', '/', static function (PluginRequest $request, PluginContext $plugin): void {
        $plugin->renderPublic('page', [], ['title' => 'Страница плагина', 'template' => 'landing']);
    });
};
```

Классы объявляйте в autoload-папке, а не в точке входа: ядро может подключить entry больше одного раза за процесс.

Код плагина выполняется только когда он нужен: запрос к `/admin-panel/plugins/{id}/…` или к публичному префиксу `/{prefix}/…`, обращение к его службе (`provides`), срабатывание объявленного им хука (`hooks`; например, `request` — на каждой публичной странице) и открытие страницы **Система** (там загружаются все валидные плагины, чтобы показать ошибки загрузки и install-хуков). Остальные плагины на странице не загружаются — ядро только читает их `plugin.json`.

## Маршруты

| Метод | URL |
|-------|-----|
| `adminRoute($method, $pattern, $handler)` | `/admin-panel/plugins/{id}{pattern}` — только после входа |
| `publicRoute($method, $pattern, $handler, $options)` | `/{prefix}{pattern}` — нужен `public.prefix` |

- Методы: `GET` (обслуживает и `HEAD`), `POST`, `PUT`, `PATCH`, `DELETE`. Нет маршрута — 404, другой метод — 405 с `Allow`.
- Шаблон: `/`, `/items/{id}`, `/files/{path*}`. `{name}` — один сегмент `[A-Za-z0-9_.-]` (URL-кодирование допускается, `/` и `..` нет); `{name*}` — относительный путь без `..` и скрытых сегментов.
- Обработчик: `function (PluginRequest $request, PluginContext $plugin): void`.
- **CSRF.** В админке ядро проверяет токен на всех `POST`/`PUT`/`PATCH`/`DELETE` до вызова плагина — поле `_csrf` или заголовок `X-CSRF-Token` (`$plugin->csrfToken()`). Отключить нельзя. Неверный токен — `CsrfException`, `AdminRouter::dispatchAdmin()` отвечает `403` с текстом `CSRF validation failed` (запрос не обрывается `exit`, поэтому проверяется в тестах без отдельного процесса). На сайте проверка тоже включена по умолчанию (ответ `403` JSON); для конкретного маршрута: `publicRoute('POST', '/hook', $fn, ['csrf' => false])`.
- Исключение в обработчике: админка — 500 с текстом ошибки (JSON, если клиент шлёт `Accept`/`Content-Type: application/json`); сайт — общая страница 500, текст в `error_log`.

## PluginRequest

| Метод | Что возвращает |
|-------|----------------|
| `$request->method`, `$request->path`, `$request->params` | метод, путь относительно плагина, параметры шаблона |
| `param($name)` | параметр шаблона |
| `query($name, $default)` / `input($name, $default)` / `allInput()` | `$_GET` / `$_POST` |
| `file($name)` | элемент `$_FILES` или `null` |
| `header($name)` | заголовок запроса |
| `body()` / `json()` | сырое тело / JSON-массив или `null` |
| `wantsJson()`, `csrfToken()` | клиент ждёт JSON; токен из `X-CSRF-Token` или `_csrf` |

## PluginContext

**Сведения:** `id()`, `name()`, `version()`, `manifest()`, `dir()` (код плагина), `dataDir()` (`plugin-data/{id}`, создаётся ядром), `prefix()`, `coreVersion()`, `config($key)`, `path('content_path' | 'themes_path' | 'site_path' | …)`, `isAdmin()`.

**URL:** `adminUrl('/api/x')`, `publicUrl('/x', $locale = null)`, `assetUrl('app.js')` → `/plugin-assets/{id}/app.js?v={version}`. На публичных страницах `publicUrl()` проходит через фильтр `url` (плагин i18n добавляет `/en/`); в админке — без изменений.

**Язык** ([11-i18n.md](11-i18n.md)): `locale()` — язык запроса, `defaultLocale()`, `locales()`, `localized(['ru' => …, 'en' => …])` — значение для текущего языка с откатом на основной, затем на первое непустое (то же, что `SiteLocale::localized()`; своей копии у службы `i18n` нет). Без службы `i18n` язык всегда `ru`.

**Службы:** `provide('i18n', $object)` — в точке входа, имя должно быть в `provides`; `service('i18n')` — объект или `null`, если службы нет. Владелец службы загружается при первом обращении, поэтому порядок загрузки не важен; вызывайте `service()` в обработчиках, а не в точке входа.

**Хуки сайта:** `on('request', fn (SiteRequest $r))` и `filter('url' | 'menu' | 'head' | 'output', fn ($value, array $context))` — только публичная часть, имя хука должно быть в `hooks`. Описание — [11-i18n.md](11-i18n.md).

**Ответы:**

| Метод | Назначение |
|-------|------------|
| `render($template, $data, $options)` | `templates/{template}.php` плагина внутри layout админки. Опции: `title`, `fullscreen` (рабочая область на всё окно под шапкой админки — для редакторов), `styles`, `scripts` (URL для `<head>`, только свой сайт; с 0.4 подключаются **до** Alpine.js), `scripts_after_alpine` (после Alpine.js, как `scripts` в 0.3), `status` |
| `renderPublic($template, $data, $options)` | шаблон плагина внутри шаблона **активной темы** (шапка, меню, подвал): HTML попадает в `$blocks['content']`. Опции: `title`, `description`, `og_*`, `template` (шаблон темы, по умолчанию `default`), `hero`, `menu_location`, `scripts` (`/plugin-assets/...js`, в конце страницы), `styles` (CSS в `<head>`), `head_scripts` (JS в `<head>`, до Alpine.js темы), `alternates` (hreflang: `true` — все языки сайта по тому же пути, `['en' => '/en/…']` — явно), `status`, `vars` (в шаблоне темы — `$pluginVars`). Inline-edit на таких страницах выключен. `styles` и `head_scripts` выводит `includes/views/site-chrome.php` — его подключают все темы |
| `partial($template, $data)` | шаблон плагина в строку |
| `json($data, $status)`, `jsonError($message, $status)` | JSON-ответ (`Cache-Control: no-store`) |
| `raw($body, $contentType, $status)` | ответ как есть, без layout |
| `redirect('/path')`, `notFound()` | редирект только внутри сайта; 404 |
| `sendFile($absolutePath, $options)` | файл из папки плагина или его `plugin-data` (nosniff, ETag/304, CSP для SVG; `download`, `cache`, `type`) |

В шаблонах плагина доступны переданные `$data` и `$plugin`; экранирование — `PluginContext::e($value)`. Tailwind-классы админки собраны только из шаблонов ядра — свои стили плагин подключает из `assets/` (`styles`).

**CSRF:** `csrfToken()`, `csrfField()`, `csrfValid($request)`.

**Данные** (относительные пути внутри `dataDir()`, без `..` и скрытых файлов): `dataPath($rel)`, `writeFile($rel, $content)` (через `FileWriter`, flock, папки создаются), `readFile($rel)`, `writeJson($rel, $data)`, `readJson($rel, $default)`, `readJsonStrict($rel, $default = [])`, `deleteFile($rel)`.

> `readJson()` возвращает `$default` и когда файла нет, и когда файл **повреждён** (не JSON). Если плагин после этого перезапишет файл, данные потеряются. Для важных данных используйте `readJsonStrict()`.

`readJsonStrict($rel, ?array $default = [])` (с 0.4.0):

- файла нет — `$default`;
- файл не читается, не JSON или на верхнем уровне не объект/массив (`"text"`, `42`, `null`, пустой файл) — исключение `PluginDataException` (наследник `RuntimeException`) с сообщением вида «Файл plugin-data/{id}/items.json повреждён (не JSON: Syntax error). Файл не изменён — восстановите его из резервной копии.»; `relativePath()` — путь, переданный в метод;
- ничего не записывает. Если исключение не перехвачено, маршрут получит 500, а файл останется как есть.

```php
try {
    $items = $plugin->readJsonStrict('items.json');   // [] при первом запуске
} catch (PluginDataException $e) {
    $plugin->jsonError($e->getMessage(), 500);        // не перезаписываем файл
    return;
}
```

**Установка/обновление:** `onInstall(fn (?string $from, string $to, PluginContext $plugin))`. Хук вызывается при первой загрузке новой версии плагина (под блокировкой файла), затем версия записывается в `plugin-data/{id}/.plugin-state.json`. Хук должен быть идемпотентным; если он бросил исключение, версия не записывается, плагин не загружается, ошибка видна на «Системе», при следующем запросе хук повторится.

## Alpine.js в плагинах

Alpine.js ядра запускается сразу после загрузки своего скрипта (`defer`). Компоненты регистрируйте до старта — в обработчике `alpine:init`:

```js
document.addEventListener('alpine:init', () => {
    Alpine.data('myWidget', () => ({ open: false }));
});
```

Для этого скрипт должен выполниться раньше Alpine: в админке — опция `scripts` у `render()` (с 0.4 они подключаются до Alpine), на сайте — `head_scripts` у `renderPublic()` (выводятся в `site-chrome.php`, который тема подключает до Alpine). Скрипты, которым на момент загрузки нужен уже запущенный `window.Alpine`, подключайте через `scripts_after_alpine` (админка) или `scripts` (сайт, конец страницы).

## Статика и безопасность

- Статика отдаётся ядром (`index.php`) по `/plugin-assets/{id}/{путь}` **только** из `plugins/{id}/assets/` и только типов `js, mjs, css, png, jpg, jpeg, gif, webp, avif, svg, ico, woff, woff2, ttf, wasm, txt`. PHP, JSON, скрытые файлы, `..` и файлы невалидных плагинов — 404. Заголовки: `X-Content-Type-Options: nosniff`, ETag/304, для SVG — запрещающий CSP; с `?v={версия плагина}` — `immutable` на год, иначе час.
- Прямой HTTP к `plugins/` и `plugin-data/` закрыт файлами `.htaccess` (`Require all denied`), которые ядро создаёт при первом обращении к папкам, и правилами `RedirectMatch 403` в корневом `.htaccess` новых установок (`core/skeleton/.htaccess`). Корневой `.htaccess` существующих сайтов self-update не меняет — защиту обеспечивают `.htaccess` внутри папок; при желании добавьте правила вручную:

  ```apache
  RedirectMatch 403 ^/plugins/
  RedirectMatch 403 ^/plugin-data/
  ```

- Если ядро не смогло создать `.htaccess` (нет прав), «Система» показывает предупреждение.
- **Nginx** `.htaccess` не читает — закройте `/plugins/` и `/plugin-data/` в конфиге сервера.
- Плагин — доверенный PHP-код с полными правами процесса; ставьте только свои или проверенные плагины.

## Без плагинов

Если папки `plugins/` нет, ядро ведёт себя как раньше: публичный роутер не перехватывает ни `/plugin-assets/`, ни префиксы, меню админки без новых пунктов, на «Системе» — «Плагины не установлены».

## Тесты

```bash
php tests/plugins.php
php tests/i18n.php
```

`plugins.php` создаёт временный сайт из skeleton, копирует пример `hello` и набор заведомо сломанных плагинов и проверяет обнаружение, валидацию, install-хуки, маршруты админки и сайта, CSRF, статику и поведение без `plugins/`. `i18n.php` — службы, хуки, язык запроса, переводы страниц и hreflang на тестовых плагинах. Папка `tests/` в release zip не попадает.
