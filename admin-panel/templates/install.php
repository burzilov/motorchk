<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка — motorchk</title>
    <meta name="description" content="Установка сайта на motorchk">
    <link rel="icon" href="/core/assets/favicon.svg" type="image/svg+xml">
    <link href="/core/assets/css/admin.css" rel="stylesheet">
</head>
<body class="mc-auth">
    <main class="mc-auth__main">
        <p class="mc-brand mc-brand--auth"><span class="mc-brand__square" aria-hidden="true"></span>motorchk</p>
        <div class="mc-auth__panel">
            <h1 class="mc-auth__title">Установка motorchk</h1>
            <p class="mc-auth__lead">Создайте учётную запись администратора. Ядро v<?= htmlspecialchars(Version::current()) ?>.</p>

            <?php if (!empty($errors)): ?>
                <div class="mc-alert mc-alert--error" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/admin-panel/install" class="mc-stack">
                <?= Csrf::field() ?>
                <div class="mc-field">
                    <label for="username" class="mc-label">Логин</label>
                    <input id="username" name="username" type="text" required autocomplete="username"
                           value="<?= htmlspecialchars($form['username'] ?? 'admin') ?>"
                           class="mc-input">
                </div>
                <div class="mc-field">
                    <label for="password" class="mc-label">Пароль</label>
                    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password"
                           class="mc-input" aria-describedby="password-hint">
                    <p class="mc-hint" id="password-hint">Не менее 8 символов</p>
                </div>
                <button type="submit" class="mc-btn mc-btn--primary mc-btn--block">
                    Установить
                </button>
            </form>
        </div>
    </main>
</body>
</html>
