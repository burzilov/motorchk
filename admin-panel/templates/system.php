<?php
$caps = $capabilities ?? [];
$allReady = !in_array(false, $caps, true);
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">Система</h1>
        <span class="rounded bg-slate-200 px-2 py-1 text-xs text-slate-700">ядро v<?= htmlspecialchars($currentVersion) ?></span>
    </div>

    <?php if (!empty($updateMessage)): ?>
        <div class="rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            Ядро обновлено до v<?= htmlspecialchars((string) $updateMessage) ?>.
        </div>
    <?php endif; ?>

    <?php if (!empty($updateError)): ?>
        <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?= htmlspecialchars((string) $updateError) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($checkError)): ?>
        <div class="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Не удалось проверить обновления: <?= htmlspecialchars($checkError) ?>
        </div>
    <?php endif; ?>

    <section class="rounded-xl bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold">Обновление ядра</h2>
        <p class="mb-4 text-sm text-slate-600">
            Репозиторий: <code>burzilov/motorchk</code>.
            Версия в контенте: <code><?= htmlspecialchars($meta['engine_version'] ?? '—') ?></code>
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
            <p class="text-sm text-slate-600">Установлена актуальная версия ядра.</p>
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
