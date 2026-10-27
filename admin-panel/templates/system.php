<?php
$caps = $capabilities ?? [];
$allReady = !in_array(false, $caps, true);
$smtp = $smtp ?? [];
$enc = (string) ($smtp['encryption'] ?? 'tls');
?>
<div class="mc-page">
    <div class="mc-head">
        <h1 class="mc-head__title">Система</h1>
    </div>

    <div class="mc-system">
    <nav class="mc-toc" aria-label="Разделы страницы">
        <a class="mc-toc__link" href="#system-theme">Тема сайта</a>
        <a class="mc-toc__link" href="#system-smtp">SMTP</a>
        <a class="mc-toc__link" href="#system-password">Пароль администратора</a>
        <a class="mc-toc__link" href="#system-plugins">Плагины</a>
        <a class="mc-toc__link" href="#system-update">Обновление ядра</a>
        <a class="mc-toc__link" href="#system-ready">Готовность к self-update</a>
    </nav>
    <div class="mc-system__sections">

    <section class="mc-sheet" id="system-theme">
        <h2 class="mc-sheet__title">Тема сайта</h2>
        <form method="post" action="/admin-panel/system/theme" class="mc-inline-form">
            <?= Csrf::field() ?>
            <label class="mc-field mc-label" for="theme">
                Активная тема
                <select id="theme" name="theme" class="mc-input">
                    <?php foreach ($themes as $theme): ?>
                        <option value="<?= htmlspecialchars($theme) ?>"<?= ($meta['theme'] ?? 'example') === $theme ? ' selected' : '' ?>>
                            <?= htmlspecialchars($theme) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="mc-btn mc-btn--primary">
                Сохранить тему
            </button>
        </form>
    </section>

    <section class="mc-sheet" id="system-smtp">
        <h2 class="mc-sheet__title">SMTP</h2>
        <p class="mc-sheet__lead">Параметры отправки писем с форм заявок. Секреты хранятся в <code>.env</code>.</p>
        <form method="post" action="/admin-panel/system/smtp" class="mc-stack">
            <?= Csrf::field() ?>
            <div class="mc-grid-2">
                <label class="mc-field mc-label">
                    Хост
                    <input name="smtp_host" type="text" value="<?= htmlspecialchars((string) ($smtp['host'] ?? '')) ?>" class="mc-input">
                </label>
                <label class="mc-field mc-label">
                    Порт
                    <input name="smtp_port" type="number" value="<?= htmlspecialchars((string) ($smtp['port'] ?? 587)) ?>" class="mc-input">
                </label>
                <label class="mc-field mc-label">
                    Шифрование
                    <select name="smtp_encryption" class="mc-input">
                        <option value="tls"<?= $enc === 'tls' ? ' selected' : '' ?>>TLS</option>
                        <option value="ssl"<?= $enc === 'ssl' ? ' selected' : '' ?>>SSL</option>
                        <option value=""<?= $enc === '' ? ' selected' : '' ?>>Нет</option>
                    </select>
                </label>
                <label class="mc-field mc-label">
                    Пользователь
                    <input name="smtp_username" type="text" value="<?= htmlspecialchars((string) ($smtp['username'] ?? '')) ?>" class="mc-input" autocomplete="off">
                </label>
                <label class="mc-field mc-label">
                    Пароль
                    <input name="smtp_password" type="password" value="" placeholder="<?= !empty($smtpPasswordSet) ? 'Оставьте пустым, чтобы не менять' : '' ?>" class="mc-input" autocomplete="new-password">
                </label>
                <label class="mc-field mc-label">
                    From (email)
                    <input name="smtp_from" type="email" value="<?= htmlspecialchars((string) ($smtp['from'] ?? '')) ?>" class="mc-input">
                </label>
                <label class="mc-field mc-label">
                    From (имя)
                    <input name="smtp_from_name" type="text" value="<?= htmlspecialchars((string) ($smtp['from_name'] ?? '')) ?>" class="mc-input">
                </label>
                <label class="mc-field mc-label">
                    To (получатель заявок)
                    <input name="smtp_to" type="email" value="<?= htmlspecialchars((string) ($smtp['to'] ?? '')) ?>" class="mc-input">
                </label>
            </div>
            <div class="mc-actions">
                <button type="submit" class="mc-btn mc-btn--primary">
                    Сохранить SMTP
                </button>
            </div>
        </form>
        <form method="post" action="/admin-panel/system/smtp/test" class="mc-after">
            <?= Csrf::field() ?>
            <button type="submit" class="mc-btn">
                Проверить соединение
            </button>
        </form>
    </section>

    <section class="mc-sheet" id="system-password">
        <h2 class="mc-sheet__title">Пароль администратора</h2>
        <form method="post" action="/admin-panel/system/password" class="mc-stack mc-narrow">
            <?= Csrf::field() ?>
            <label class="mc-field mc-label">
                Текущий пароль
                <input name="current_password" type="password" required class="mc-input" autocomplete="current-password">
            </label>
            <label class="mc-field mc-label">
                Новый пароль
                <input name="new_password" type="password" required minlength="8" class="mc-input" autocomplete="new-password">
            </label>
            <label class="mc-field mc-label">
                Подтверждение
                <input name="new_password_confirm" type="password" required minlength="8" class="mc-input" autocomplete="new-password">
            </label>
            <button type="submit" class="mc-btn mc-btn--primary mc-btn--fit">
                Сменить пароль
            </button>
        </form>
    </section>

    <section class="mc-sheet" id="system-plugins">
        <h2 class="mc-sheet__title">Плагины</h2>
        <p class="mc-sheet__lead">
            Плагин устанавливается распаковкой архива в <code><?= htmlspecialchars((string) ($pluginsPath ?? '')) ?>/{id}/</code>;
            плагин в папке включён. Данные плагинов — <code><?= htmlspecialchars((string) ($pluginDataPath ?? '')) ?>/{id}/</code>.
        </p>
        <?php $pluginList = $plugins ?? []; ?>
        <?php if ($pluginList === []): ?>
            <p class="mc-muted">Плагины не установлены.</p>
        <?php else: ?>
            <ul class="mc-plugins">
                <?php foreach ($pluginList as $pluginId => $pluginInfo): ?>
                    <?php $pluginOk = ($pluginInfo['status'] ?? '') === 'ok'; ?>
                    <li class="mc-plugin<?= $pluginOk ? '' : ' mc-plugin--error' ?>">
                        <div class="mc-plugin__head">
                            <span class="mc-plugin__name"><?= htmlspecialchars((string) $pluginInfo['name']) ?></span>
                            <span class="mc-plugin__id"><?= htmlspecialchars((string) $pluginId) ?><?= ($pluginInfo['version'] ?? '') !== '' ? ' v' . htmlspecialchars((string) $pluginInfo['version']) : '' ?></span>
                        </div>
                        <?php if (($pluginInfo['description'] ?? '') !== ''): ?>
                            <p class="mc-plugin__desc"><?= htmlspecialchars((string) $pluginInfo['description']) ?></p>
                        <?php endif; ?>
                        <ul class="mc-plugin__facts">
                            <li class="<?= $pluginOk ? 'mc-status--ok' : 'mc-status--bad' ?>">
                                <?= $pluginOk ? '✓ Включён' : '✗ Не загружен: ' . htmlspecialchars(implode('; ', $pluginInfo['errors'] ?? [])) ?>
                            </li>
                            <li>
                                Ядро: <code><?= htmlspecialchars(($pluginInfo['core'] ?? '') !== '' ? (string) $pluginInfo['core'] : '—') ?></code>
                                <?php if (($pluginInfo['compatible'] ?? null) === true): ?>
                                    <span class="mc-status--ok">✓ совместим с v<?= htmlspecialchars($currentVersion ?? '') ?></span>
                                <?php elseif (($pluginInfo['compatible'] ?? null) === false): ?>
                                    <span class="mc-status--bad">✗ несовместим с v<?= htmlspecialchars($currentVersion ?? '') ?></span>
                                <?php endif; ?>
                            </li>
                            <?php if (!empty($pluginInfo['installed_version'])): ?>
                                <li>Установленная версия данных: <code><?= htmlspecialchars((string) $pluginInfo['installed_version']) ?></code></li>
                            <?php endif; ?>
                            <?php if (!empty($pluginInfo['prefix'])): ?>
                                <li>Публичный адрес: <code>/<?= htmlspecialchars((string) $pluginInfo['prefix']) ?>/</code></li>
                            <?php endif; ?>
                            <?php if (!empty($pluginInfo['menu']) && $pluginOk): ?>
                                <li>Админка: <a class="mc-link" href="/admin-panel/plugins/<?= htmlspecialchars((string) $pluginId) ?>"><?= htmlspecialchars((string) $pluginInfo['menu']) ?></a></li>
                            <?php endif; ?>
                            <li class="<?= !empty($pluginInfo['data_writable']) ? 'mc-status--ok' : 'mc-status--bad' ?>">
                                <?= !empty($pluginInfo['data_writable']) ? '✓' : '✗' ?> Запись в <code><?= htmlspecialchars((string) $pluginInfo['data_dir']) ?></code>
                            </li>
                            <?php foreach ($pluginInfo['warnings'] ?? [] as $pluginWarning): ?>
                                <li class="mc-status--warn">⚠ <?= htmlspecialchars((string) $pluginWarning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php foreach (['plugins' => 'plugins/', 'data' => 'plugin-data/'] as $protKey => $protLabel): ?>
            <?php if (($pluginProtection[$protKey] ?? null) === false): ?>
                <p class="mc-after mc-status--bad">✗ В <code><?= $protLabel ?></code> нет защитного <code>.htaccess</code> (не удалось создать) — закройте папку от HTTP вручную.</p>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>

    <section class="mc-sheet" id="system-update">
        <div class="mc-sheet__head">
            <h2 class="mc-sheet__title">Обновление ядра</h2>
            <form method="post" action="/admin-panel/system/check-update">
                <?= Csrf::field() ?>
                <button type="submit" class="mc-btn">
                    Проверить обновления
                </button>
            </form>
        </div>
        <p class="mc-sheet__lead">
            Репозиторий: <code>burzilov/motorchk</code>
        </p>

        <?php if (!empty($update) && !empty($update['version'])): ?>
            <div class="mc-alert mc-alert--info">
                Доступна версия <strong>v<?= htmlspecialchars($update['version']) ?></strong>
                <?php if (!empty($update['url'])): ?>
                    — <a class="mc-link" href="<?= htmlspecialchars($update['url']) ?>" target="_blank" rel="noopener">релиз на GitHub</a>
                <?php endif; ?>
            </div>
            <?php if ($allReady && !empty($update['zip_url'])): ?>
                <form method="post" action="/admin-panel/system/update" onsubmit="return confirm('Обновить ядро до v<?= htmlspecialchars($update['version'], ENT_QUOTES) ?>? Контент и тема не изменятся.');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="version" value="<?= htmlspecialchars($update['version']) ?>">
                    <button type="submit" class="mc-btn mc-btn--primary">
                        Обновить до v<?= htmlspecialchars($update['version']) ?>
                    </button>
                </form>
            <?php elseif (empty($update['zip_url'])): ?>
                <p class="mc-status--warn">В релизе нет zip-артефакта <code>motorchk-core-*.zip</code>.</p>
            <?php else: ?>
                <p class="mc-status--warn">Self-update недоступен: проверьте готовность хостинга ниже.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="mc-sheet__lead">Установлена актуальная версия ядра.</p>
        <?php endif; ?>
    </section>

    <section class="mc-sheet" id="system-ready">
        <h2 class="mc-sheet__title">Готовность к self-update</h2>
        <ul class="mc-checklist">
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
                <li class="<?= $ok ? 'mc-status--ok' : 'mc-status--bad' ?>">
                    <?= $ok ? '✓' : '✗' ?> <?= htmlspecialchars($label) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    </div>
    </div>
</div>
