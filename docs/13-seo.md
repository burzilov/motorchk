# sitemap.xml, robots.txt, llms.txt

С 0.7.0 ядро само отдаёт три служебных файла для поисковиков и LLM. Они строятся на лету из опубликованных Markdown-страниц, поэтому новая или снятая с публикации страница сразу видна в них без перегенерации.

| Адрес | Тип | Содержимое |
|-------|-----|------------|
| `/sitemap.xml` | `application/xml` | публичные страницы: абсолютные `<loc>`, `<lastmod>` — время изменения файла страницы; при мультиязычности — каждая языковая версия и `xhtml:link hreflang` (+ `x-default`) |
| `/robots.txt` | `text/plain` | `User-agent: *`, `Allow: /`, `Disallow:` для админки (`/admin-panel`), API (`/api/`) и предпросмотра (`/*?preview=`), свои правила, `Sitemap:` с абсолютным адресом |
| `/llms.txt` | `text/plain` (Markdown по [llmstxt.org](https://llmstxt.org/)) | `# название`, `> описание`, раздел «Страницы» — ссылки с `description` страниц, затем `content/_llms.txt`, если есть |

Отвечают только на `GET` и `HEAD` (другие методы — 405), с `Cache-Control: public, max-age=3600`; `sitemap.xml` и `llms.txt` — ещё с `X-Robots-Tag: noindex`. Маршруты ядра — в `Router::dispatchPublic()` до хука `request` и маршрутов плагинов, поэтому `/en/sitemap.xml` — 404, а не копия, и плагин i18n не ставит на этих адресах cookie и не делает редиректов. Правка корневого `.htaccess` не нужна: несуществующие файлы и так уходят в `index.php`.

**Физический файл важнее.** Если в корне сайта лежит свой `robots.txt` / `sitemap.xml` / `llms.txt`, Apache отдаёт его сам, до ядра. Чтобы перейти на файлы ядра, удалите (или переименуйте) свой файл.

## Какие страницы попадают

В `sitemap.xml` и `llms.txt` — опубликованные страницы `content/**/*.md`, кроме:

- `published: false` (черновики);
- `noindex: true` во front matter — страница ещё и отдаётся с `X-Robots-Tag: noindex`;
- `sitemap: false` во front matter — только убрать из sitemap и llms.txt, без noindex (например, «Спасибо за заявку»);
- служебных: имя файла или папки начинается с `_` (`_menus.yaml`, `_drafts/…`);
- страниц, чей адрес перекрыт публичным префиксом плагина (`/shop` при плагине с `"prefix": "shop"`): по этому адресу открывается плагин, а не страница;
- файлов переводов (`about.en.md`) — при активной службе i18n они идут как языковые версии оригинала, только если перевод опубликован и не помечен `noindex` / `sitemap: false`.

```yaml
---
title: Спасибо
sitemap: false   # нет в sitemap.xml и llms.txt
---
```

```yaml
---
title: Внутренняя страница
noindex: true    # нет в sitemap.xml и llms.txt + X-Robots-Tag: noindex
---
```

## Настройки

Ключ `seo` в `config/site.php` необязателен: без него всё включено со значениями по умолчанию. Можно указать только нужные ключи — остальные возьмутся из умолчаний (`SeoFiles::DEFAULTS`).

```php
return [
    // … пути сайта …
    'seo' => [
        'sitemap' => true,              // false — /sitemap.xml не отдаётся ядром (404), строки Sitemap: в robots.txt нет
        'robots' => true,               // false — /robots.txt не отдаётся ядром
        'llms' => true,                 // false — /llms.txt не отдаётся ядром
        'site_url' => 'https://example.ru', // адрес сайта в файлах; пусто — схема и хост запроса
        'robots_disallow' => ['/private/', '/*?utm_'], // дополнительные Disallow; ['/'] — закрыть весь сайт (тестовый домен)
        'site_name' => 'Студия «Пример»',  // заголовок llms.txt; пусто — title главной
        'llms_description' => 'Чем занимаемся и для кого.', // описание llms.txt; пусто — description главной
    ],
];
```

- `site_url` стоит задать, если сайт открывается по нескольким доменам или за прокси, который не передаёт `HTTPS=on` (см. [08-deploy-and-upgrade.md](08-deploy-and-upgrade.md)): иначе адреса берутся из `Host` запроса и `HTTPS`/порта 443.
- Правила `robots_disallow` начинаются с `/` или `*`, без пробелов и `#`; остальные отбрасываются. При `'/'` строка `Allow: /` не выводится, чтобы не перебить запрет.
- Выключенный файл ведёт себя как до 0.7.0: запрос идёт обычной маршрутизацией (обычно 404).

**Дополнение к llms.txt.** Произвольный Markdown (темы, контакты, как цитировать) положите в `content/_llms.txt` — он дописывается после списка страниц. Это не `.md`, поэтому отдельной страницей сайта он не становится, а `content/` закрыт от прямого HTTP.

## Фильтры плагинов

Плагин дополняет файлы фильтрами; имена нужно перечислить в `plugin.json` `"hooks"` (поддерживаются ядром с 0.7.0 — укажите `"core": ">=0.7.0"`):

```json
{ "id": "blog", "public": { "prefix": "blog" }, "hooks": ["sitemap", "robots", "llms"] }
```

| Фильтр | Значение | Что вернуть |
|--------|----------|-------------|
| `sitemap` | `list<array>` — записи до вывода | тот же список с добавленными/изменёнными записями |
| `robots` | `string` — готовый robots.txt | строку |
| `llms` | `string` — готовый llms.txt | строку |

`$context` у всех трёх: `base_url` (адрес сайта без `/` в конце), `default_locale`, `locales`; у `llms` ещё `pages` — список страниц ядра (`slug`, `path`, `title`, `description`, `lastmod`, `locales`).

Запись sitemap: `loc` — путь внутри сайта (`/blog/post`, станет абсолютным) или абсолютный `http(s)://`-адрес; необязательные `lastmod` (timestamp или `2026-10-28` / ISO 8601), `alternates` (`['ru' => '/blog/post', 'en' => '/en/blog/post']`, выводятся при двух и более), `changefreq`, `priority` (0…1). Записи без корректного `loc` и повторы `loc` отбрасываются.

```php
$plugin->filter('sitemap', static function (array $entries, array $ctx) use ($plugin): array {
    foreach (Blog::posts() as $post) {
        $entries[] = ['loc' => '/blog/' . $post->slug, 'lastmod' => $post->updatedAt];
    }
    return $entries;
});
$plugin->filter('robots', static fn(string $text): string => $text . "\nUser-agent: GPTBot\nDisallow: /blog/drafts/\n");
```

Как и у других фильтров, исключение в обработчике или значение другого типа игнорируются (ошибка — в `error_log`).

Тест — `php tests/seo.php`.
