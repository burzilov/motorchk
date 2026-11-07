<?php

/**
 * Вкладки «Заявок» и столбцы одной формы.
 *
 * Форма попадает во вкладки, если есть хотя бы одно из:
 * YAML в content/forms, регистрация плагина, каталог в submissions/ (старые и «осиротевшие» id).
 * Один id — одна вкладка: плагин сам решает, складывать ли разные изделия в одну форму.
 */
final class SubmissionViews
{
    private SubmissionColumnStore $store;

    private SubmissionStore $submissions;

    private FormRepository $forms;

    public function __construct(private array $config, private PluginManager $plugins)
    {
        $this->store = new SubmissionColumnStore($config);
        $this->submissions = new SubmissionStore($config);
        $this->forms = new FormRepository($config);
    }

    /**
     * @return list<array{id: string, label: string, sources: list<string>}>
     */
    public function tabs(): array
    {
        /** @var array<string, array{id: string, label: string, sources: list<string>}> $byId */
        $byId = [];

        foreach ($this->forms->listMeta() as $meta) {
            $id = (string) $meta['id'];
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'label' => (string) ($meta['label'] !== '' ? $meta['label'] : $id),
                'sources' => ['yaml'],
            ];
        }

        foreach ($this->plugins->submissionForms() as $id => $form) {
            if (isset($byId[$id])) {
                $byId[$id]['label'] = $form['label'];
                $byId[$id]['sources'][] = 'plugin';
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'label' => $form['label'],
                'sources' => ['plugin'],
            ];
        }

        foreach ($this->submissions->formIds() as $id) {
            if (isset($byId[$id])) {
                $byId[$id]['sources'][] = 'submissions';
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'label' => $id,
                'sources' => ['submissions'],
            ];
        }

        uasort($byId, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return array_values($byId);
    }

    public function label(string $formId): string
    {
        foreach ($this->tabs() as $tab) {
            if ($tab['id'] === $formId) {
                return $tab['label'];
            }
        }

        return $formId;
    }

    /**
     * Столбцы данных между «Дата» и «Почта». Пустой список — только дата, почта и «Открыть».
     *
     * @return list<array{key: string, label: string}>
     */
    public function columns(string $formId): array
    {
        $saved = $this->store->get($formId);
        if ($saved !== null) {
            return $saved;
        }

        return $this->registeredColumns($formId);
    }

    /**
     * Поля, которые админ может включить: столбцы плагина, затем ключи из контрактов и payload заявок.
     * Подпись плагина не заменяется подписью из старой заявки.
     *
     * @return list<array{key: string, label: string}>
     */
    public function candidates(string $formId): array
    {
        $out = [];
        $seen = [];
        foreach ($this->registeredColumns($formId) as $column) {
            $out[] = $column;
            $seen[$column['key']] = true;
        }
        foreach ($this->submissions->fieldLabels($formId) as $key => $label) {
            if (isset($seen[$key])) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $label];
            $seen[$key] = true;
        }
        foreach ($this->store->get($formId) ?? [] as $column) {
            if (isset($seen[$column['key']])) {
                continue;
            }
            $out[] = $column;
            $seen[$column['key']] = true;
        }

        return $out;
    }

    /**
     * Строки формы настройки: включённые в своём порядке, остальные следом.
     *
     * @return list<array{key: string, label: string, enabled: bool, order: int}>
     */
    public function editor(string $formId): array
    {
        $active = [];
        foreach ($this->columns($formId) as $index => $column) {
            $active[$column['key']] = ['label' => $column['label'], 'order' => $index];
        }

        $rows = [];
        foreach ($this->candidates($formId) as $index => $candidate) {
            $key = $candidate['key'];
            $enabled = isset($active[$key]);
            $rows[] = [
                'key' => $key,
                'label' => $enabled ? $active[$key]['label'] : $candidate['label'],
                'enabled' => $enabled,
                'order' => $enabled ? $active[$key]['order'] : 1000 + $index,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['order'], $a['key']] <=> [$b['order'], $b['key']];
        });

        return $rows;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function registeredColumns(string $formId): array
    {
        $form = $this->plugins->submissionForms()[$formId] ?? null;
        if ($form === null) {
            return [];
        }

        return $form['columns'];
    }
}
