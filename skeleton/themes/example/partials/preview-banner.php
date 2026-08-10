<?php
$showPreview = !empty($isPreview);
$showEditHint = !empty($canEdit);
if (!$showPreview && !$showEditHint) {
    return;
}
$adminUrl = htmlspecialchars($adminEditUrl ?? AdminUrl::page($slug ?? 'index'));
?>
<?php if ($showPreview): ?>
    <div class="sticky top-0 z-50 border-b border-amber-200 bg-amber-50 text-amber-950">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
            <span class="font-medium">Предпросмотр</span>
            <?php if (empty($pagePublished)): ?>
                <span class="rounded-md bg-amber-100 px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset ring-amber-200/80">Черновик</span>
            <?php endif; ?>
            <?php if ($showEditHint): ?>
                <span class="text-amber-800/90">Двойной клик по блоку — редактирование</span>
            <?php endif; ?>
            <a href="<?= $adminUrl ?>" class="ml-auto text-amber-900 underline decoration-amber-300 underline-offset-2 hover:text-amber-700">В админку</a>
        </div>
    </div>
<?php elseif ($showEditHint): ?>
    <div class="sticky top-0 z-50 border-b border-sky-200 bg-sky-50 text-sky-950">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
            <span class="font-medium">Редактирование</span>
            <span class="text-sky-800/90">Двойной клик по блоку</span>
            <a href="<?= $adminUrl ?>" class="ml-auto text-sky-900 underline decoration-sky-300 underline-offset-2 hover:text-sky-700">В админку</a>
        </div>
    </div>
<?php endif; ?>
