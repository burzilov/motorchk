<?php
$showPreview = !empty($isPreview);
$showEditHint = !empty($canEdit);
if (!$showPreview && !$showEditHint) {
    return;
}
$adminUrl = htmlspecialchars($adminEditUrl ?? AdminUrl::page($slug ?? 'index'));
?>
<?php if ($showPreview): ?>
    <div class="motorchk-banner motorchk-banner--preview">
        <div class="motorchk-banner__inner">
            <span class="motorchk-banner__title">Предпросмотр</span>
            <?php if (empty($pagePublished)): ?>
                <span class="motorchk-banner__badge">Черновик</span>
            <?php endif; ?>
            <?php if ($showEditHint): ?>
                <span class="motorchk-banner__hint">Двойной клик по блоку — редактирование</span>
            <?php endif; ?>
            <a href="<?= $adminUrl ?>" class="motorchk-banner__link">В админку</a>
        </div>
    </div>
<?php elseif ($showEditHint): ?>
    <div class="motorchk-banner motorchk-banner--edit">
        <div class="motorchk-banner__inner">
            <span class="motorchk-banner__title">Редактирование</span>
            <span class="motorchk-banner__hint">Двойной клик по блоку</span>
            <a href="<?= $adminUrl ?>" class="motorchk-banner__link">В админку</a>
        </div>
    </div>
<?php endif; ?>
