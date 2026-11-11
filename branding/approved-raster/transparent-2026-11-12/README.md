# Черновики прозрачного логотипа Motorchk CMS (не закоммичено, не выложено)
Источник: /home/box/agent-data/motorchk-branding-approved-raster/delivery/*.png (= core assets/brand и branding/approved-raster/delivery в v0.8.0, sha256 совпадают).
Скрипты: extract2.py (снятие белого фона: светлая зона связана с краем + ушки ключа и counters букв; альфа = проекция на цвет ближайшей чистой заливки, цвет краёв = цвет заливки -> без ореола), build.py (сборка вариантов).
- motorchk-cms-stacked.png 1330x1377 — логин, прозрачный, цвета без изменений.
- motorchk-cms-horizontal.png 1656x378 — прозрачный, цвета без изменений (для светлых фонов).
- motorchk-cms-horizontal-on-dark.png — шапка: надпись Motorchk -> #f8f7f3 (--color-paper), CMS осветлён x2 в линейном свете, курица без изменений.
- motorchk-cms-horizontal-on-dark-outline.png — то же + тонкий контур #f8f7f3 вокруг курицы (~1 CSS px), чтобы чёрные лапки/контур не тонули.
Превью: preview/ (cmp-header-zoom.png, cmp-login.png, cmp-header-mobile.png, edges-zoom.png; страницы отрендерены из реальных шаблонов + admin.css v0.8.0 в Chrome).
