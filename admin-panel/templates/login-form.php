<div class="mx-auto max-w-md rounded-xl bg-white p-8 shadow-sm w-full" id="login-form">
    <h1 class="mb-6 text-2xl font-bold">Вход в админку</h1>
    <?php if (!empty($error)): ?>
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <form
        method="post"
        action="/admin-panel/login"
        hx-post="/admin-panel/login"
        hx-target="#login-form"
        hx-swap="outerHTML"
        class="space-y-4"
    >
        <?= Csrf::field() ?>
        <div>
            <label class="mb-1 block text-sm font-medium" for="username">Логин</label>
            <input id="username" name="username" type="text" required class="w-full rounded border border-slate-300 px-3 py-2">
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium" for="password">Пароль</label>
            <input id="password" name="password" type="password" required class="w-full rounded border border-slate-300 px-3 py-2">
        </div>
        <button type="submit" class="w-full rounded bg-sky-600 px-4 py-2 font-medium text-white hover:bg-sky-700">
            Войти
        </button>
    </form>
</div>
