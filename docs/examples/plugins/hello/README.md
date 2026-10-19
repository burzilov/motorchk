# hello — пример плагина motorchk

Демонстрирует API плагинов ядра (см. [../../../10-plugins.md](../../../10-plugins.md)):

- пункт «Hello» в меню админки и страницу `/admin-panel/plugins/hello/`;
- JSON API `GET|PUT /admin-panel/plugins/hello/api/greeting` (CSRF через `X-CSRF-Token`);
- полноэкранный режим `/admin-panel/plugins/hello/fullscreen`;
- публичные страницы `/hello/` и `/hello/to/{name}` в шаблоне активной темы, сырой JSON `/hello/api/greeting`;
- публичный `POST /hello/api/echo` с CSRF;
- статику из `assets/` по `/plugin-assets/hello/...`;
- данные в `plugin-data/hello/greeting.json`, install-хук и autoload `Hello\` → `lib/Hello/`.

## Установка

Скопируйте папку `hello/` в `public_html/plugins/` (или распакуйте архив с ней) — плагин включится сразу.
Удаление: удалите `plugins/hello/` (данные остаются в `plugin-data/hello/`).
