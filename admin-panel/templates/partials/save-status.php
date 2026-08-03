<div class="rounded px-3 py-2 text-sm <?= !empty($error) ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700' ?>">
    <?= htmlspecialchars($message ?? '') ?>
</div>
