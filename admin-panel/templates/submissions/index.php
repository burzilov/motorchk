<?php
$filterFormId = $filterFormId ?? '';
$filterMailStatus = $filterMailStatus ?? '';
$items = $items ?? [];
$forms = $forms ?? [];
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">Заявки</h1>
    </div>

    <form method="get" action="/admin-panel/submissions" class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm">
        <label class="block text-sm text-slate-700">
            Форма
            <select name="form_id" class="mt-1 block rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="">Все</option>
                <?php foreach ($forms as $form): ?>
                    <option value="<?= htmlspecialchars($form['id']) ?>"<?= $filterFormId === $form['id'] ? ' selected' : '' ?>>
                        <?= htmlspecialchars($form['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block text-sm text-slate-700">
            Почта
            <select name="mail_status" class="mt-1 block rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="">Все</option>
                <option value="sent"<?= $filterMailStatus === 'sent' ? ' selected' : '' ?>>sent</option>
                <option value="failed"<?= $filterMailStatus === 'failed' ? ' selected' : '' ?>>failed</option>
                <option value="pending"<?= $filterMailStatus === 'pending' ? ' selected' : '' ?>>pending</option>
            </select>
        </label>
        <button type="submit" class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">Фильтр</button>
    </form>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">Дата</th>
                    <th class="px-4 py-3 font-medium">Форма</th>
                    <th class="px-4 py-3 font-medium">Почта</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($items === []): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-500">Заявок пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $fid = (string) ($item['form_id'] ?? '');
                        $sid = (string) ($item['id'] ?? '');
                        $status = (string) ($item['mail_status'] ?? '');
                        ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($fid) ?></td>
                            <td class="px-4 py-3">
                                <span class="<?= $status === 'sent' ? 'text-emerald-700' : ($status === 'failed' ? 'text-red-700' : 'text-slate-600') ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a class="text-sky-700 hover:underline" href="/admin-panel/submissions/<?= rawurlencode($fid) ?>/<?= rawurlencode($sid) ?>">Открыть</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
