<?php
$caps = $capabilities ?? [];
$allReady = !in_array(false, $caps, true);
$smtp = $smtp ?? [];
$enc = (string) ($smtp['encryption'] ?? 'tls');
?>
<div class="space-y-6">
    <h1 class="text-2xl font-bold">Система</h1>

    <section class="rounded-xl bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold">Тема сайта</h2>
        <form method="post" action="/admin-panel/system/theme" class="flex flex-wrap items-end gap-3">
            <?= Csrf::field() ?>
            <label class="block text-sm text-slate-700" for="theme">
                Активная тема
                <select id="theme" name="theme" class="mt-1 block rounded border border-slate-300 px-3 py-2 text-sm">
                    <?php foreach ($themes as $theme): ?>
                        <option value="<?= htmlspecialchars($theme) ?>"<?= ($meta['theme'] ?? 'example') === $theme ? ' selected' : '' ?>>
                            <?= htmlspecialchars($theme) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
                Сохранить тему
            </button>
        </form>
    </section>

    <section class="rounded-xl bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold">SMTP</h2>
        <p class="mb-4 text-sm text-slate-600">Параметры отправки писем с форм заявок. Секреты хранятся в <code>.env</code>.</p>
        <form method="post" action="/admin-panel/system/smtp" class="space-y-4">
            <?= Csrf::field() ?>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm text-slate-700">
                    Хост
                    <input name="smtp_host" type="text" value="<?= htmlspecialchars((string) ($smtp['host'] ?? '')) ?>" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block text-sm text-slate-700">
                    Порт
                    <input name="smtp_port" type="number" value="<?= htmlspecialchars((string) ($smtp['port'] ?? 587)) ?>" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block text-sm text-slate-700">
                    Шифрование
                    <select name="smtp_encryption" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="tls"<?= $enc === 'tls' ? ' selected' : '' ?>>TLS</option>
                        <option value="ssl"<?= $enc === 'ssl' ? ' selected' : '' ?>>SSL</option>
                        <option value=""<?= $enc === '' ? ' selected' : '' ?>>Нет</option>
                    </select>
                </label>
                <label class="block text-sm text-slate-700">
                    Пользователь
                    <input name="smtp_username" type="text" value="<?= htmlspecialchars((string) ($smtp['username'] ?? '')) ?>" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" autocomplete="off">
                </label>
                <label class="block text-sm text-slate-700">
                    Пароль
                    <input name="smtp_password" type="password" value="" placeholder="<?= !empty($smtpPasswordSet) ? 'Оставьте пустым, чтобы не менять' : '' ?>" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" autocomplete="new-password">
                </label>
                <label class="block text-sm text-slate-700">
                    From (email)
                    <input name="smtp_from" type="email" value="<?= htmlspecialchars((string) ($smtp['from'] ?? '')) ?>" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block text-sm text-slate-700">
                    From (имя)
                    <input name="smtp_from_name" type="text" value="<?= htmlspecialchars((string) ($smtp['from_name'] ?? '')) ?>" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block text-sm text-slate-700">
                    To (получатель заявок)
                    <input name="smtp_to" type="email" value="<?= htmlspecialchars((string) ($smtp['to'] ?? '')) ?>" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
            </div>
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
                    Сохранить SMTP
                </button>
            </div>
        </form>
        <form method="post" action="/admin-panel/system/smtp/test" class="mt-3">
            <?= Csrf::field() ?>
            <button type="submit" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                Проверить соединение
            </button>
        </form>
    </section>

    <section class="rounded-xl bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold">Пароль администратора</h2>
        <form method="post" action="/admin-panel/system/password" class="grid max-w-md gap-3">
            <?= Csrf::field() ?>
            <label class="block text-sm text-slate-700">
                Текущий пароль
                <input name="current_password" type="password" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" autocomplete="current-password">
            </label>
            <label class="block text-sm text-slate-700">
                Новый пароль
                <input name="new_password" type="password" required minlength="8" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" autocomplete="new-password">
            </label>
            <label class="block text-sm text-slate-700">
                Подтверждение
                <input name="new_password_confirm" type="password" required minlength="8" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" autocomplete="new-password">
            </label>
            <button type="submit" class="w-fit rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
                Сменить пароль
            </button>
        </form>
    </section>

    <section class="rounded-xl bg-white p-6 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">Обновление ядра</h2>
            <form method="post" action="/admin-panel/system/check-update">
                <?= Csrf::field() ?>
                <button type="submit" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                    Проверить обновления
                </button>
            </form>
        </div>
        <p class="mb-4 text-sm text-slate-600">
            Репозиторий: <code>burzilov/motorchk</code>
        </p>

        <?php if (!empty($update) && !empty($update['version'])): ?>
            <div class="mb-4 rounded border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                Доступна версия <strong>v<?= htmlspecialchars($update['version']) ?></strong>
                <?php if (!empty($update['url'])): ?>
                    — <a class="underline" href="<?= htmlspecialchars($update['url']) ?>" target="_blank" rel="noopener">релиз на GitHub</a>
                <?php endif; ?>
            </div>
            <?php if ($allReady && !empty($update['zip_url'])): ?>
                <form method="post" action="/admin-panel/system/update" onsubmit="return confirm('Обновить ядро до v<?= htmlspecialchars($update['version'], ENT_QUOTES) ?>? Контент и тема не изменятся.');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="version" value="<?= htmlspecialchars($update['version']) ?>">
                    <button type="submit" class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
                        Обновить до v<?= htmlspecialchars($update['version']) ?>
                    </button>
                </form>
            <?php elseif (empty($update['zip_url'])): ?>
                <p class="text-sm text-amber-800">В релизе нет zip-артефакта <code>motorchk-core-*.zip</code>.</p>
            <?php else: ?>
                <p class="text-sm text-amber-800">Self-update недоступен: проверьте готовность хостинга ниже.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="mb-4 text-sm text-slate-600">Установлена актуальная версия ядра.</p>
        <?php endif; ?>
    </section>

    <section class="rounded-xl bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold">Готовность к self-update</h2>
        <ul class="space-y-2 text-sm">
            <?php
            $labels = [
                'zip' => 'Расширение ZipArchive',
                'curl_or_fopen' => 'HTTP-загрузки (curl или allow_url_fopen)',
                'core_writable' => 'Права на замену public_html/core',
                'cache_writable' => 'Права на запись в cache/',
                'content_writable' => 'Права на запись в content/ (_meta.yaml)',
            ];
            foreach ($labels as $key => $label):
                $ok = !empty($caps[$key]);
                ?>
                <li class="<?= $ok ? 'text-emerald-700' : 'text-red-700' ?>">
                    <?= $ok ? '✓' : '✗' ?> <?= htmlspecialchars($label) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
