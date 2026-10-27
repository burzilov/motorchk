# Установка и обновление

## Установка

1. Распакуйте `motorchk-install-X.Y.Z.zip` из релиза в `public_html/`: в архиве `core/`, `.htaccess` и `index.php`
   (оба — копии `core/skeleton/.htaccess` и `core/skeleton/index.php`, кладутся сборкой `.github/build-release.sh`).
   Ничего вручную копировать или править не нужно: корневой `.htaccess` сразу направляет `/admin-panel` в ядро,
   а `index.php` до установки перенаправляет на мастер.
2. Откройте сайт, `/admin-panel/` или `/admin-panel/install` — откроется мастер.
3. Задайте логин/пароль админа.
4. Installer копирует отсутствующие файлы из `core/skeleton/` (уже существующие, в том числе `.htaccess` и `index.php`
   из установочного zip, не перезаписывает), пишет `.env` и `content/_meta.yaml`.

Артефакты релиза: `motorchk-install-X.Y.Z.zip` — для новой установки; `motorchk-core-X.Y.Z.zip` — только ядро
(папка `motorchk-core-X.Y.Z/` = `public_html/core/`), его скачивает обновление из админки. Установочный zip
не распаковывайте поверх работающего сайта: он заменит корневые `.htaccess` и `index.php`.

Fresh install получает поставляемую тему-пример `themes/example/`; шаблоны страниц лежат в корне темы, а её CSS/JS — в `themes/example/assets/`.

## Обновление из админки

1. После входа админка периодически запрашивает `https://api.github.com/repos/burzilov/motorchk/releases/latest`.
2. На странице **Система** можно применить обновление: скачивание zip → бэкап → замена `core/` → `install/migrate.php`.

Миграция полностью заменяет `themes/example/` версией из release, создаёт `media/` с защитным `.htaccess` (если их нет), обновляет `engine_version` и очищает кеш. Пользовательские темы в `themes/{name}/`, контент (включая `content/_menus.yaml`), плагины в `plugins/` и их данные в `plugin-data/` не изменяются. После обновления ядра проверьте на «Системе» совместимость плагинов (поле `core` в `plugin.json`).

Корневой `.htaccess` сайта обновление не меняет (его копирует только установка), и для обновлений ядра его править не нужно: закрытие `/core/` (снаружи только `/core/assets/`) задаёт `core/.htaccess`, который приходит и заменяется вместе с ядром. В корне остаются маршрутизация (`/admin-panel` → `core/admin-panel/index.php`, остальное → `index.php`) и защита папок сайта.

Кеш проверки: `cache/update-check.json`, TTL из `UPDATE_CHECK_TTL` (по умолчанию 21600 с).
На странице **Система** кнопка «Проверить обновления» сбрасывает кеш и запрашивает GitHub API сразу.
