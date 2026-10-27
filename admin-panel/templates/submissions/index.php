<?php
$filterFormId = $filterFormId ?? '';
$filterMailStatus = $filterMailStatus ?? '';
$items = $items ?? [];
$forms = $forms ?? [];
?>
<div class="mc-page">
    <div class="mc-head">
        <h1 class="mc-head__title">Заявки</h1>
    </div>

    <form method="get" action="/admin-panel/submissions" class="mc-filter">
        <label class="mc-field mc-label">
            Форма
            <select name="form_id" class="mc-input">
                <option value="">Все</option>
                <?php foreach ($forms as $form): ?>
                    <option value="<?= htmlspecialchars($form['id']) ?>"<?= $filterFormId === $form['id'] ? ' selected' : '' ?>>
                        <?= htmlspecialchars($form['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="mc-field mc-label">
            Почта
            <select name="mail_status" class="mc-input">
                <option value="">Все</option>
                <option value="sent"<?= $filterMailStatus === 'sent' ? ' selected' : '' ?>>sent</option>
                <option value="failed"<?= $filterMailStatus === 'failed' ? ' selected' : '' ?>>failed</option>
                <option value="pending"<?= $filterMailStatus === 'pending' ? ' selected' : '' ?>>pending</option>
            </select>
        </label>
        <button type="submit" class="mc-btn mc-btn--primary">Фильтр</button>
    </form>

    <div class="mc-table-wrap">
        <table class="mc-table">
            <thead>
                <tr>
                    <th scope="col">Дата</th>
                    <th scope="col">Форма</th>
                    <th scope="col">Почта</th>
                    <th scope="col"><span class="visually-hidden">Открыть</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($items === []): ?>
                    <tr>
                        <td colspan="4" class="mc-table__empty">Заявок пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $fid = (string) ($item['form_id'] ?? '');
                        $sid = (string) ($item['id'] ?? '');
                        $status = (string) ($item['mail_status'] ?? '');
                        ?>
                        <tr>
                            <td class="mc-table__date num"><?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($fid) ?></td>
                            <td>
                                <span class="mc-status <?= $status === 'sent' ? 'mc-status--ok' : ($status === 'failed' ? 'mc-status--bad' : 'mc-status--idle') ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td class="mc-table__end">
                                <a class="mc-link" href="/admin-panel/submissions/<?= rawurlencode($fid) ?>/<?= rawurlencode($sid) ?>">Открыть</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
