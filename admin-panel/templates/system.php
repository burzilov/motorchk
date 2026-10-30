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
        <div class="mc-sheet__head">
            <h2 class="mc-sheet__title">Плагины</h2>
            <form method="post" action="/admin-panel/system/plugins/check-updates" data-update-check="system-plugins">
                <?= Csrf::field() ?>
                <button type="submit" class="mc-btn">
                    Проверить обновления плагинов
                </button>
            </form>
        </div>
        <p class="mc-sheet__lead">
            Плагин устанавливается распаковкой архива в <code><?= htmlspecialchars((string) ($pluginsPath ?? '')) ?>/{id}/</code>;
            плагин в папке включён. Данные плагинов — <code><?= htmlspecialchars((string) ($pluginDataPath ?? '')) ?>/{id}/</code>.
        </p>
        <p class="mc-sheet__lead">
            Обновления — из GitHub Releases репозитория, указанного в <code>plugin.json</code> (<code>repository</code>).
            Подписей нет: обновление — это чужой код, который выполнится на сервере, поэтому указывайте только репозитории, которым доверяете.
        </p>
        <?php
        $pluginUpdates ??= [];
        $pluginUpdatesReady = !in_array(false, $pluginUpdateCapabilities ?? [], true);
        ?>
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
                            <?php $pluginUpdate = $pluginUpdates[$pluginId] ?? null; ?>
                            <?php if ($pluginUpdate !== null && ($pluginUpdate['repository'] ?? null) !== null): ?>
                                <li>
                                    Обновления: <a class="mc-link" href="https://github.com/<?= htmlspecialchars((string) $pluginUpdate['repository']) ?>/releases" target="_blank" rel="noopener"><code><?= htmlspecialchars((string) $pluginUpdate['repository']) ?></code></a>
                                    <?php if (!empty($pluginUpdate['checked_at'])): ?>
                                        <span class="mc-muted">(проверено <?= htmlspecialchars(date('d.m.Y H:i', (int) $pluginUpdate['checked_at'])) ?>)</span>
                                    <?php endif; ?>
                                </li>
                                <?php if (($pluginUpdate['error'] ?? null) !== null): ?>
                                    <li class="mc-status--warn">⚠ Не удалось проверить обновления: <?= htmlspecialchars((string) $pluginUpdate['error']) ?></li>
                                <?php elseif (!empty($pluginUpdate['update'])): ?>
                                    <?php $pu = $pluginUpdate['update']; ?>
                                    <li>
                                        <span class="mc-tag mc-tag--draft">Доступна v<?= htmlspecialchars((string) $pu['version']) ?></span>
                                        <a class="mc-link" href="<?= htmlspecialchars((string) $pu['url']) ?>" target="_blank" rel="noopener">релиз на GitHub</a>
                                        <?php if (!empty($pu['core'])): ?>
                                            — требует ядро <code><?= htmlspecialchars((string) $pu['core']) ?></code>
                                        <?php endif; ?>
                                    </li>
                                    <?php if (($pu['problem'] ?? null) !== null): ?>
                                        <li class="mc-status--bad">✗ <?= htmlspecialchars((string) $pu['problem']) ?></li>
                                    <?php elseif (($pu['compatible'] ?? null) === false): ?>
                                        <li class="mc-status--bad">✗ Новая версия несовместима с ядром v<?= htmlspecialchars($currentVersion ?? '') ?> — сначала обновите ядро.</li>
                                    <?php elseif (!$pluginUpdatesReady): ?>
                                        <li class="mc-status--warn">⚠ Обновление из админки недоступно: нужны ZipArchive, HTTP и запись в plugins/ и cache/.</li>
                                    <?php elseif (!empty($pu['installable'])): ?>
                                        <li>
                                            <form method="post" action="/admin-panel/system/plugins/update" class="mc-inline-form" data-confirm-update="<?= htmlspecialchars('Обновить плагин «' . $pluginId . '» до v' . $pu['version'] . '? Будет выполнен код из релиза ' . $pluginUpdate['repository'] . '. Резервная копия текущей версии сохранится в cache/.', ENT_QUOTES, 'UTF-8') ?>">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="plugin" value="<?= htmlspecialchars((string) $pluginId) ?>">
                                                <input type="hidden" name="version" value="<?= htmlspecialchars((string) $pu['version']) ?>">
                                                <button type="submit" class="mc-btn mc-btn--primary">
                                                    Обновить до v<?= htmlspecialchars((string) $pu['version']) ?>
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                <?php elseif (!empty($pluginUpdate['checked_at'])): ?>
                                    <li class="mc-status--ok">✓ Установлена последняя версия</li>
                                <?php endif; ?>
                            <?php elseif ($pluginUpdate !== null && ($pluginUpdate['error'] ?? null) !== null): ?>
                                <li class="mc-status--warn">⚠ <?= htmlspecialchars((string) $pluginUpdate['error']) ?></li>
                            <?php else: ?>
                                <li class="mc-muted">Автообновление недоступно: в plugin.json нет <code>repository</code></li>
                            <?php endif; ?>
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

    <section class="mc-sheet" id="system-maintenance">
        <h2 class="mc-sheet__title">Техническое обслуживание</h2>
        <?php
        $maintenance = (array) ($maintenance ?? []);
        $maintenanceEnabled = !empty($maintenance['enabled']);
        $maintenanceSecretSet = !empty($maintenance['secretSet']);
        $maintenanceViewSecret = $maintenance['viewSecret'] ?? null;
        ?>
        <p class="mc-sheet__lead">
            <?php if ($maintenanceEnabled): ?>
                <span class="mc-status--bad">Сайт закрыт: посетители видят страницу «Закрыто на техобслуживание» (HTTP 503).</span>
            <?php else: ?>
                <span class="mc-status--ok">Сайт открыт для всех.</span>
            <?php endif; ?>
            <?php if ($maintenanceSecretSet): ?>
                Секрет установлен и хранится только как хэш — повторно показать или восстановить его нельзя.
            <?php else: ?>
                Секрет не задан: на закрытый сайт можно попасть только из админки.
            <?php endif; ?>
        </p>
        <p class="mc-sheet__lead">
            При включённом режиме посетители видят страницу обслуживания, а доступ можно получить
            по паролю или секретной ссылке <code>/?maintenance_key=…</code>. Разрешение сохраняется
            в сессии; при смене секрета или повторном включении режима ранее выданные ссылки и
            сессии перестают действовать. Эту ссылку передавайте как пароль: её обладатель получает доступ,
            а сам URL может попасть в историю браузера и логи сервера.
        </p>

        <form method="post" action="/admin-panel/system/maintenance" class="mc-inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <label class="mc-inline-form__check">
                <input type="checkbox" name="maintenance_enabled" value="1"<?= $maintenanceEnabled ? ' checked' : '' ?>>
                Закрыть сайт на техобслуживание
            </label>
            <input type="password" name="maintenance_secret" class="mc-input" autocomplete="new-password"
                   placeholder="Новый секрет (оставьте пустым, чтобы не менять)">
            <button type="submit" class="mc-btn mc-btn--primary">Сохранить</button>
        </form>

        <form method="post" action="/admin-panel/system/maintenance" class="mc-inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="generate">
            <button type="submit" class="mc-btn">Сгенерировать случайный секрет</button>
        </form>

        <?php if ($maintenanceSecretSet): ?>
            <form method="post" action="/admin-panel/system/maintenance" class="mc-inline-form"
                  data-confirm-update="Удалить секрет? Вход по паролю и секретной ссылке станет невозможен до установки нового, восстановить нельзя.">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="clear_secret">
                <button type="submit" class="mc-btn mc-btn--danger">Удалить секрет…</button>
            </form>
        <?php endif; ?>

        <?php if (is_string($maintenanceViewSecret) && $maintenanceViewSecret !== ''): ?>
            <?php // Секрет в HTML — только один раз после установки, до копирования (docs/14-maintenance.md) ?>
            <div class="mc-after mc-alert mc-alert--info" id="maintenance-secret-box"
                 data-maintenance-secret="<?= htmlspecialchars($maintenanceViewSecret, ENT_QUOTES, 'UTF-8') ?>">
                <p>Ссылка для входа (одноразовый показ, после копирования его снова не видно):</p>
                <p><code id="maintenance-secret-link"></code></p>
                <div class="mc-actions">
                    <button type="button" class="mc-btn mc-btn--primary" data-maintenance-copy>Скопировать ссылку</button>
                    <button type="button" class="mc-btn" data-maintenance-hide>Скрыть</button>
                </div>
                <p>Храните её как пароль: доступ получит любой, кто знает секрет.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="mc-sheet" id="system-update">
        <div class="mc-sheet__head">
            <h2 class="mc-sheet__title">Обновление ядра</h2>
            <form method="post" action="/admin-panel/system/check-update" data-update-check="system-update">
                <?= Csrf::field() ?>
                <button type="submit" class="mc-btn">
                    Проверить обновления ядра
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
                <form method="post" action="/admin-panel/system/update" data-confirm-update="<?= htmlspecialchars('Обновить ядро до v' . $update['version'] . '? Тема example будет заменена; пользовательские темы и контент сохранятся.', ENT_QUOTES, 'UTF-8') ?>">
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
<dialog id="update-confirm" class="mc-confirm" aria-labelledby="update-confirm-title" aria-describedby="update-confirm-text">
    <h2 class="mc-modal__title" id="update-confirm-title">Подтвердите обновление</h2>
    <p class="mc-modal__text" id="update-confirm-text"></p>
    <div class="mc-modal__actions">
        <button type="button" class="mc-btn" data-confirm-cancel>Отмена</button>
        <button type="button" class="mc-btn mc-btn--primary" data-confirm-accept>Обновить</button>
    </div>
</dialog>
<noscript><style>form[data-confirm-update] { display: none; }</style><p class="mc-status--warn">Для подтверждения обновления требуется JavaScript.</p></noscript>
