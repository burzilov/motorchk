<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — motorchk</title>
    <meta name="description" content="Вход в админку сайта на motorchk">
    <link rel="icon" href="/core/assets/favicon.svg" type="image/svg+xml">
    <link href="/core/assets/css/admin.css" rel="stylesheet">
    <script src="/core/assets/js/htmx.min.js" defer></script>
</head>
<body class="mc-auth">
    <main class="mc-auth__main">
        <p class="mc-brand mc-brand--auth"><span class="mc-brand__square" aria-hidden="true"></span>motorchk</p>
        <?php require __DIR__ . '/login-form.php'; ?>
    </main>
</body>
</html>
