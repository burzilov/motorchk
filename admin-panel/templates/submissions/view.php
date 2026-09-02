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
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">Заявка</h1>
        <a href="/admin-panel/submissions" class="text-sm text-sky-700 hover:underline">← К списку</a>
    </div>

    <section class="rounded-xl bg-white p-6 shadow-sm space-y-2 text-sm">
        <p><span class="text-slate-500">Форма:</span> <?= htmlspecialchars((string) $formLabel) ?> <code class="text-xs"><?= htmlspecialchars((string) ($item['form_id'] ?? '')) ?></code></p>
        <p><span class="text-slate-500">ID:</span> <?= htmlspecialchars((string) ($item['id'] ?? '')) ?></p>
        <p><span class="text-slate-500">Дата:</span> <?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?></p>
        <p><span class="text-slate-500">IP:</span> <?= htmlspecialchars((string) ($item['ip'] ?? '')) ?></p>
        <p>
            <span class="text-slate-500">Почта:</span>
            <?= htmlspecialchars((string) ($item['mail_status'] ?? '')) ?>
            <?php if (!empty($item['mail_error'])): ?>
                — <span class="text-red-700"><?= htmlspecialchars((string) $item['mail_error']) ?></span>
            <?php endif; ?>
        </p>
    </section>

    <section class="rounded-xl bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-lg font-semibold">Данные</h2>
        <dl class="space-y-3 text-sm">
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
                <div class="grid gap-1 border-b border-slate-100 pb-3 md:grid-cols-[12rem_1fr]">
                    <dt class="font-medium text-slate-700"><?= htmlspecialchars($label) ?></dt>
                    <dd class="whitespace-pre-wrap text-slate-900"><?= htmlspecialchars($text) ?></dd>
                </div>
            <?php endforeach; ?>
            <?php if ($payload === []): ?>
                <p class="text-slate-500">Пусто</p>
            <?php endif; ?>
        </dl>
    </section>
</div>
