<?php if (!empty($errors)): ?>
    <div class="mc-alert mc-alert--error" role="alert">
        <?php foreach ($errors as $error): ?>
            <div><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
