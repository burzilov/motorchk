<?php
$item = $item ?? [];
$formLabel = $formLabel ?? '';
$payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
$contract = is_array($item['contract'] ?? null) ? $item['contract'] : [];
$labels = [];
foreach ($contract as $field) {
    if (is_array($field) && isset($field['name'])) {
        $labels[(string) $field['name']] = (string) ($field['label'] ?? $field['name']);
    }
}
?>
<div class="mc-page">
    <div class="mc-head">
        <h1 class="mc-head__title">Заявка</h1>
        <a href="/admin-panel/submissions" class="mc-link">← К списку</a>
    </div>

    <section class="mc-sheet mc-facts">
        <p><span class="mc-facts__key">Форма:</span> <?= htmlspecialchars((string) $formLabel) ?> <code><?= htmlspecialchars((string) ($item['form_id'] ?? '')) ?></code></p>
        <p><span class="mc-facts__key">ID:</span> <?= htmlspecialchars((string) ($item['id'] ?? '')) ?></p>
        <p><span class="mc-facts__key">Дата:</span> <?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?></p>
        <p><span class="mc-facts__key">IP:</span> <?= htmlspecialchars((string) ($item['ip'] ?? '')) ?></p>
        <p>
            <span class="mc-facts__key">Почта:</span>
            <?= htmlspecialchars((string) ($item['mail_status'] ?? '')) ?>
            <?php if (!empty($item['mail_error'])): ?>
                — <span class="mc-error-text"><?= htmlspecialchars((string) $item['mail_error']) ?></span>
            <?php endif; ?>
        </p>
    </section>

    <section class="mc-sheet">
        <h2 class="mc-sheet__title">Данные</h2>
        <dl class="mc-dl">
            <?php foreach ($payload as $name => $value): ?>
                <?php
                $label = $labels[$name] ?? (string) $name;
                if (is_array($value)) {
                    $text = implode(', ', array_map('strval', $value));
                } elseif (is_bool($value)) {
                    $text = $value ? 'да' : 'нет';
                } else {
                    $text = (string) $value;
                }
                ?>
                <div class="mc-dl__row">
                    <dt><?= htmlspecialchars($label) ?></dt>
                    <dd><?= htmlspecialchars($text) ?></dd>
                </div>
            <?php endforeach; ?>
            <?php if ($payload === []): ?>
                <p class="mc-muted">Пусто</p>
            <?php endif; ?>
        </dl>
    </section>
</div>
