<?php
/** @var list<array{message: string, type?: string}>|null $toasts */
/** @var list<string>|null $toastCleanParams */

if (empty($toasts) || !is_array($toasts)) {
    return;
}

$payload = [
    'items' => array_values(array_map(static function ($item) {
        if (!is_array($item)) {
            return ['message' => (string) $item, 'type' => 'success'];
        }
        return [
            'message' => (string) ($item['message'] ?? ''),
            'type' => (string) ($item['type'] ?? 'success'),
        ];
    }, $toasts)),
    'cleanParams' => array_values(array_map('strval', $toastCleanParams ?? [])),
];
?>
<script type="application/json" id="mc-toasts-boot"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
