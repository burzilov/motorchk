<div class="mc-auth__panel" id="login-form">
    <h1 class="mc-auth__title">Вход в админку</h1>
    <?php if (!empty($error)): ?>
        <div class="mc-alert mc-alert--error" role="alert">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <form
        method="post"
        action="/admin-panel/login"
        hx-post="/admin-panel/login"
        hx-target="#login-form"
        hx-swap="outerHTML"
        class="mc-stack"
    >
        <?= Csrf::field() ?>
        <div class="mc-field">
            <label class="mc-label" for="username">Логин</label>
            <input id="username" name="username" type="text" required autocomplete="username" class="mc-input">
        </div>
        <div class="mc-field">
            <label class="mc-label" for="password">Пароль</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="mc-input">
        </div>
        <button type="submit" class="mc-btn mc-btn--primary mc-btn--block">
            Войти
        </button>
    </form>
</div>
