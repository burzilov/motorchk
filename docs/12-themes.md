# Темы: меню, картинки содержимого, кеш страниц

Тема — каталог `themes/{name}/` с PHP-шаблонами страниц (`{template}.php`) и статикой в `assets/`
(структура — [01-architecture.md](01-architecture.md#темы-и-шаблоны-страниц)). Ядро не навязывает теме
CSS-фреймворк: публичные страницы получают данные, разметку пишет тема.

## Переменные шаблона

| Переменная | Что это |
|------------|---------|
| `$slug`, `$frontMatter`, `$blocks` | страница: slug, front matter, HTML блоков `## block: …` |
| `$title`, `$description`, `$ogTitle`, `$ogDescription`, `$ogImage` | для `<head>`; `og_title`/`og_description` необязательны — без них берутся `title`/`description` |
| `$menu`, `$menus` | меню страницы (`menu_location` или `main`) и все меню по id |
| `$themeAssetsUrl` | `/themes/{name}/assets` |
| `$locale`, `$defaultLocale`, `$contentLocale`, `$alternates`, `$i18n` | язык ([11-i18n.md](11-i18n.md)) |
| `$scripts`, `$canEdit`, `$config` | скрипты страницы, inline-edit, конфиг сайта |
| `$plugins` | `PluginManager` или `null` (нет папки plugins/): службы плагинов — `$plugins?->service('customizer')`, объект или `null` ([10-plugins.md](10-plugins.md)) |

## Меню

Пункт меню (`MenuBuilder::build()`, уже отфильтрован хуками `url` и `menu`):

```php
[
    'label' => 'Наколенники',
    'slug' => 'equipment/pads',   // null для url-пунктов
    'url' => '/equipment/pads',   // с языковым префиксом, если активна служба i18n
    'external' => false,
    'active' => true,             // открыта страница пункта или страница внутри его раздела
    'current' => false,           // открыта ровно страница пункта
    'broken' => false,            // только в админке
    'children' => [ /* до 3 уровней */ ],
]
```

`active` и `current` считаются и для внутренних url-пунктов (`/customizer/` активен на
`/customizer/ultra`); `/` (`index`) активен только на главной.

### Нейтральная разметка ядра

```php
<?php MenuRenderer::render($menus['main'] ?? [], ['class' => 'nav']); ?>
<?= MenuRenderer::html($menu ?? [], ['class' => 'footer-menu', 'depth' => 1]) ?>
```

```html
<ul class="nav">
  <li class="nav__item is-active has-children">
    <a class="nav__link" href="/equipment" >Экипировка</a>
    <ul class="nav__sub">
      <li class="nav__item is-active"><a class="nav__link" href="/equipment/pads" aria-current="page">Щитки</a></li>
    </ul>
  </li>
  <li class="nav__item"><a class="nav__link" href="https://vk.com/…" target="_blank" rel="noopener">ВКонтакте</a></li>
</ul>
```

- `class` — корневой класс (по умолчанию `menu`), от него строятся `__item`, `__link`, `__sub`;
- `depth` — сколько уровней выводить (1–3, по умолчанию все);
- состояния: `is-active` у `<li>`, `aria-current="page"` у ссылки текущей страницы, `has-children`.

Без классов CSS-фреймворков и без JS: выпадающие подменю, бургер и т. п. — в CSS и JS темы.
Нужна совсем другая разметка — обойдите `$menu` / `$menus` в шаблоне сами; так сделана тема-пример
(Tailwind и Alpine.js в `themes/example/partials/menu.php`).

## Картинки и файлы содержимого: `media/`

`public_html/media/` — публичная папка для файлов, на которые ссылается содержимое страниц
(фото моделей, PDF, ролики). Apache отдаёт их по `/media/…` напрямую, без PHP.

```markdown
![Щитки P5](/media/products/p5-1.webp)
```

```php
<img src="<?= htmlspecialchars(Media::url('products/p5-1.webp')) ?>" alt="">
```

- `Media::url($relative)` — адрес файла; имена — латиница, цифры, `_`, `-`, `.` (не первым символом), иначе исключение (`..`, пробелы, кириллица).
- `Media::path($config, $relative)` — путь на диске или `null`.
- `media/.htaccess` (из `core/skeleton/media/`): отдаются только `jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`, `svg`,
  `ico`, `mp4`, `webm`, `mp3`, `ogg`, `pdf`; остальное — 403; PHP и CGI не исполняются, листинга нет;
  `nosniff` и CSP-песочница для SVG (при `mod_headers`), кеширование на месяц (при `mod_expires`).
  Свои правила — правьте этот файл: ядро создаёт его, только если файла нет.
- Папку и `.htaccess` создаёт установка и `install/migrate.php` при обновлении ядра; загрузки через админку пока нет —
  файлы кладутся по SFTP/SSH (владелец — пользователь веб-сервера).
- `media` зарезервировано: плагин не может занять префикс `/media`, страницу `content/media.md` не создавайте.
- Статика оформления (логотипы, фоны, иконки) остаётся в `themes/{name}/assets/`; в `media/` — то, что относится к содержимому
  и не должно пропасть при смене темы.

## Кеш страниц

Страницы, дерево страниц и меню кешируются в `cache/`. Ядро сверяет кеш с файлами:

- страница (и её перевод `*.en.md`) — по mtime и размеру своего `.md`;
- дерево страниц и меню — по отпечатку всех `.md` и `.yaml` в `content/` (`PageLoader::contentStamp()`):
  правка, новый или удалённый файл сбрасывают их.

Поэтому правки `content/` файлами (редактор, `git pull`, SFTP) видны сразу, без очистки кеша.
