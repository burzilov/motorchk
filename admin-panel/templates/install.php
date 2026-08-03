<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка — motorchk</title>
    <link href="/core/assets/css/admin.css" rel="stylesheet">
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
    <div class="w-full max-w-md rounded-xl bg-white p-8 shadow-sm">
        <h1 class="mb-2 text-2xl font-bold">Установка motorchk</h1>
        <p class="mb-6 text-sm text-slate-600">Создайте учётную запись администратора. Ядро v<?= htmlspecialchars(Version::current()) ?>.</p>

        <?php if (!empty($errors)): ?>
            <div class="mb-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin-panel/install" class="space-y-4">
            <?= Csrf::field() ?>
            <div>
                <label for="username" class="mb-1 block text-sm font-medium">Логин</label>
                <input id="username" name="username" type="text" required
                       value="<?= htmlspecialchars($form['username'] ?? 'admin') ?>"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Пароль</label>
                <input id="password" name="password" type="password" required minlength="8"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-500">Не менее 8 символов</p>
            </div>
            <button type="submit" class="w-full rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
                Установить
            </button>
        </form>
    </div>
</body>
</html>
