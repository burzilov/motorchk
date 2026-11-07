<?php
/** @var list<array{id: string, label: string, sources: list<string>}> $tabs */
/** @var string $activeFormId */
/** @var string $activeLabel */
/** @var list<array<string, mixed>> $items */
/** @var list<array{key: string, label: string}> $columns */
/** @var list<array{key: string, label: string, enabled: bool, order: int}> $editorRows */
/** @var string $filterMailStatus */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var string $notice */
/** @var string|null $columnsError */

$tabs = $tabs ?? [];
$activeFormId = $activeFormId ?? '';
$activeLabel = $activeLabel ?? '';
$items = $items ?? [];
$columns = $columns ?? [];
$editorRows = $editorRows ?? [];
$filterMailStatus = $filterMailStatus ?? '';
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 0);
$total = (int) ($total ?? 0);
$notice = $notice ?? '';
$columnsError = $columnsError ?? null;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$submissionUrl = static function (string $formId, string $mail, ?int $pageNumber = null): string {
    $query = ['form_id' => $formId];
    if ($mail !== '') {
        $query['mail_status'] = $mail;
    }
    if ($pageNumber !== null && $pageNumber > 1) {
        $query['page'] = $pageNumber;
    }

    return '/admin-panel/submissions?' . http_build_query($query);
};
$colspan = 3 + count($columns);
?>
<div class="mc-page">
    <div class="mc-head">
        <h1 class="mc-head__title">Заявки</h1>
        <p class="mc-head__lead">Отдельная таблица для каждой формы. Новые сверху.</p>
    </div>

    <?php if ($notice !== ''): ?>
        <p class="mc-note"><?= $e($notice) ?></p>
    <?php endif; ?>
    <?php if ($columnsError !== null && $columnsError !== ''): ?>
        <p class="mc-error-text"><?= $e($columnsError) ?></p>
    <?php endif; ?>

    <?php if ($tabs === []): ?>
        <p class="mc-note">Форм и заявок пока нет.</p>
    <?php else: ?>
        <nav class="mc-tabs" aria-label="Формы заявок">
            <?php foreach ($tabs as $tab): ?>
                <a
                    href="<?= $e($submissionUrl($tab['id'], $filterMailStatus)) ?>"
                    class="mc-tabs__tab"<?= $tab['id'] === $activeFormId ? ' aria-current="page"' : '' ?>
                >
                    <?= $e($tab['label']) ?>
                    <?php if ($tab['label'] !== $tab['id']): ?>
                        <span class="mc-tabs__id">(<?= $e($tab['id']) ?>)</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="get" action="/admin-panel/submissions" class="mc-filter">
            <input type="hidden" name="form_id" value="<?= $e($activeFormId) ?>">
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

        <p class="mc-muted">Всего <?= $total ?></p>

        <div class="mc-table-wrap">
            <table class="mc-table">
                <thead>
                    <tr>
                        <th scope="col">Дата</th>
                        <?php foreach ($columns as $column): ?>
                            <th scope="col"><?= $e($column['label']) ?></th>
                        <?php endforeach; ?>
                        <th scope="col">Почта</th>
                        <th scope="col"><span class="visually-hidden">Открыть</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($items === []): ?>
                        <tr>
                            <td colspan="<?= $colspan ?>" class="mc-table__empty"><?= $filterMailStatus !== '' ? 'Нет заявок с этим статусом' : 'Заявок пока нет' ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $fid = (string) ($item['form_id'] ?? $activeFormId);
                            $sid = (string) ($item['id'] ?? '');
                            $status = (string) ($item['mail_status'] ?? '');
                            $statusClass = match ($status) {
                                'sent' => 'mc-status--ok',
                                'failed' => 'mc-status--bad',
                                default => 'mc-status--idle',
                            };
                            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
                            ?>
                            <tr>
                                <td class="mc-table__date num"><?= $e((string) ($item['created_at'] ?? '')) ?></td>
                                <?php foreach ($columns as $column): ?>
                                    <?php
                                    $key = $column['key'];
                                    $text = array_key_exists($key, $payload)
                                        ? SubmissionColumns::format($payload[$key])
                                        : SubmissionColumns::EMPTY_MARK;
                                    ?>
                                    <td><?= $e($text) ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <span class="mc-status <?= $e($statusClass) ?>">
                                        <?= $e($status) ?>
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

        <?php if ($pages > 1): ?>
            <nav class="mc-actions" aria-label="Страницы">
                <?php if ($page > 1): ?>
                    <a class="mc-btn" href="<?= $e($submissionUrl($activeFormId, $filterMailStatus, $page - 1)) ?>">Назад</a>
                <?php endif; ?>
                <span class="mc-muted">Страница <?= $page ?> из <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a class="mc-btn" href="<?= $e($submissionUrl($activeFormId, $filterMailStatus, $page + 1)) ?>">Дальше</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

        <section class="mc-sheet">
            <h2 class="mc-sheet__title">Столбцы: <?= $e($activeLabel) ?></h2>
            <p class="mc-muted">Настройка общая для сайта и применяется и к старым заявкам. Сами заявки не изменяются. Пустое поле в строке показывается как «<?= $e(SubmissionColumns::EMPTY_MARK) ?>».</p>
            <?php if ($editorRows === []): ?>
                <p class="mc-note">Полей пока нет: в заявках нет данных, и плагин не задал столбцы. В таблице остаются дата, почта и ссылка.</p>
            <?php else: ?>
                <form method="post" action="/admin-panel/submissions/columns">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="form_id" value="<?= $e($activeFormId) ?>">
                    <input type="hidden" name="mail_status" value="<?= $e($filterMailStatus) ?>">
                    <div class="mc-table-wrap">
                        <table class="mc-table">
                            <thead>
                                <tr>
                                    <th scope="col">В таблице</th>
                                    <th scope="col">Ключ</th>
                                    <th scope="col">Подпись</th>
                                    <th scope="col">Порядок</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($editorRows as $index => $row): ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="columns[<?= (int) $index ?>][key]" value="<?= $e($row['key']) ?>">
                                            <label class="mc-check">
                                                <input type="checkbox" name="columns[<?= (int) $index ?>][enabled]" value="1"<?= $row['enabled'] ? ' checked' : '' ?>>
                                                <span class="visually-hidden">Показать <?= $e($row['key']) ?></span>
                                            </label>
                                        </td>
                                        <td><code><?= $e($row['key']) ?></code></td>
                                        <td>
                                            <input class="mc-input" type="text" name="columns[<?= (int) $index ?>][label]" value="<?= $e($row['label']) ?>" maxlength="<?= (int) SubmissionColumns::LABEL_MAX ?>">
                                        </td>
                                        <td>
                                            <input class="mc-input" type="number" name="columns[<?= (int) $index ?>][order]" value="<?= (int) $row['order'] ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mc-actions">
                        <button type="submit" class="mc-btn mc-btn--primary">Сохранить столбцы</button>
                    </div>
                </form>
            <?php endif; ?>
            <form method="post" action="/admin-panel/submissions/columns">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_id" value="<?= $e($activeFormId) ?>">
                <input type="hidden" name="mail_status" value="<?= $e($filterMailStatus) ?>">
                <input type="hidden" name="reset" value="1">
                <button type="submit" class="mc-btn">Сбросить столбцы</button>
            </form>
        </section>
    <?php endif; ?>
</div>
