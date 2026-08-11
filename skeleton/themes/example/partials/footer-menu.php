<?php
$footerMenu = $menus['footer'] ?? [];
if ($footerMenu === []) {
    return;
}
?>
<nav class="flex flex-wrap gap-4 text-sm" aria-label="Меню подвала">
    <?php foreach ($footerMenu as $item): ?>
        <a
            href="<?= htmlspecialchars($item['url']) ?>"
            class="<?= !empty($item['active']) ? 'font-semibold text-slate-800' : 'text-slate-500 hover:text-slate-800' ?>"
            <?= !empty($item['external']) ? 'target="_blank" rel="noopener"' : '' ?>
        ><?= htmlspecialchars($item['label']) ?></a>
    <?php endforeach; ?>
</nav>
