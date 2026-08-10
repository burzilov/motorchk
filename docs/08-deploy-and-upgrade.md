# Установка и обновление

## Установка

1. Положите release zip в `public_html/core/`.
2. Откройте `/admin-panel/install` (или `/admin-panel/` — редирект).
3. Задайте логин/пароль админа.
4. Installer копирует отсутствующие файлы из `core/skeleton/`, пишет `.env` и `content/_meta.yaml`.

Fresh install получает поставляемую тему-пример `themes/example/`; шаблоны страниц лежат в корне темы, а её CSS/JS — в `themes/example/assets/`.

## Обновление из админки

1. После входа админка периодически запрашивает `https://api.github.com/repos/burzilov/motorchk/releases/latest`.
2. На странице **Система** можно применить обновление: скачивание zip → бэкап → замена `core/` → `install/migrate.php`.

Миграция полностью заменяет `themes/example/` версией из release, удаляет старые `templates/` и корневой `assets/`, обновляет `engine_version`, очищает кеш и идемпотентно заменяет старый запрет `/templates/` правилами, которые открывают только `/themes/{theme}/assets/**`. Пользовательские темы в `themes/{name}/` не изменяются. После обновления плоские `templates/*.php` и корневой `assets/` больше не работают.

Кеш проверки: `cache/update-check.json`, TTL из `UPDATE_CHECK_TTL` (по умолчанию 21600 с).
На странице **Система** кнопка «Проверить обновления» сбрасывает кеш и запрашивает GitHub API сразу.

## Релиз

См. [README](../README.md). Релизный скрипт лежит в корне локального workspace (`./release.sh`), не в git ядра. CI: `.github/workflows/release.yml` только на тегах `v*.*.*`.
