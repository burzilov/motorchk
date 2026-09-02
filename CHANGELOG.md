# Changelog

## Unreleased

### Формы и почта

- SMTP-настройки и смена пароля админа на странице «Система» (`EnvWriter`, `.env`).
- Декларативные meta-формы в `content/forms/*.yaml`, HTML в теме через `Form::begin`/`end`, HMAC-контракт полей.
- `POST /api/forms/submit`, сохранение в `submissions/`, список заявок в админке.
- PHPMailer: отправка писем и проверка SMTP-соединения (handshake).
- Антиспам: CSRF, honeypot, timing, rate-limit 10/час.

### Меню

- Именованные меню в `content/_menus.yaml` (locations), API `$menu` / `$menus`.
- Конфиг: `menu_file` → `content/_menus.yaml`.
