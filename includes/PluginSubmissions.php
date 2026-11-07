<?php

/**
 * Заявки плагина в разделе «Заявки» админки: PluginContext::submissions().
 * Создание, отметка письма и регистрация одной вкладки с столбцами по умолчанию.
 */
final class PluginSubmissions
{
    private const MAIL_STATUSES = ['sent', 'failed'];

    public function __construct(
        private SubmissionStore $store,
        private ?PluginManager $plugins = null,
        private ?string $pluginId = null,
    ) {
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

    /**
     * Одна вкладка «Заявок» для formId. Все заявки с этим id (разные изделия, старые и новые)
     * попадают в одну таблицу — отдельные вкладки на товар ядро не создаёт.
     * $columns — столбцы по умолчанию в порядке показа: ключ payload => подпись.
     * Админ может переименовать, скрыть и переставить их; сброс возвращает этот список.
     * В plugin.json нужно "submission_forms": true, иначе ядро не загрузит плагин на странице «Заявки».
     * Подпись и ключ не записываются в уже сохранённые заявки.
     *
     * @param array<string, string> $columns
     */
    public function registerForm(string $formId, string $label, array $columns): void
    {
        if ($this->plugins === null || $this->pluginId === null) {
            throw new LogicException('registerForm() вызывается из плагина');
        }
        $this->plugins->registerSubmissionForm($this->pluginId, $formId, $label, $columns);
    }
}
