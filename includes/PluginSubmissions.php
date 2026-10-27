<?php

/**
 * Заявки плагина в разделе «Заявки» админки: PluginContext::submissions().
 * Тонкая обёртка над SubmissionStore — только создание и отметка об отправке письма.
 */
final class PluginSubmissions
{
    private const MAIL_STATUSES = ['sent', 'failed'];

    public function __construct(private SubmissionStore $store)
    {
    }

    /**
     * @param array<string, mixed> $payload поля заявки
     * @param list<array{name: string, type: string, required: bool, label: string}> $contract подписи полей для админки
     * @return array{id: string, path: string, data: array<string, mixed>} заявка со статусом письма «pending»
     */
    public function create(string $formId, array $payload, array $contract, string $ip): array
    {
        return $this->store->create($formId, $payload, $contract, $ip);
    }

    /** Статус письма по заявке: sent или failed (с текстом ошибки). */
    public function markMail(string $formId, string $id, string $status, ?string $error = null): void
    {
        if (!in_array($status, self::MAIL_STATUSES, true)) {
            throw new InvalidArgumentException('markMail(): статус sent или failed');
        }
        $this->store->updateMailStatus($formId, $id, $status, $status === 'failed' ? $error : null);
    }
}
