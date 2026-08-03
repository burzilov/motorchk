<?php foreach ($scripts ?? [] as $script): ?>
    <script src="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endforeach; ?>
<?php if (!empty($canEdit)): ?>
    <script src="/core/assets/js/turndown.min.js" defer></script>
    <script src="/core/assets/js/inline-edit.js" defer></script>
<?php endif; ?>
