<?php

/**
 * Столбцы таблиц заявок, заданные администратором. Один файл на весь сайт,
 * не внутри content/forms (там только публичные YAML-формы) и не внутри заявок:
 * настройка применяется и к старым записям, сами submissions/*.json не меняются.
 */
final class SubmissionColumnStore
{
    public const FILE_NAME = '_submission-columns.json';

    public function __construct(private array $config)
    {
    }

    public function path(): string
    {
        $content = rtrim((string) ($this->config['content_path'] ?? ''), '/');
        if ($content === '') {
            $forms = rtrim((string) ($this->config['forms_path'] ?? ''), '/');
            if ($forms !== '' && basename($forms) === 'forms') {
                $content = dirname($forms);
            }
        }
        if ($content === '' || $content === '.' || $content === '/') {
            throw new RuntimeException('Не задан content_path для настроек столбцов заявок');
        }

        $path = $content . '/' . self::FILE_NAME;
        $forms = rtrim((string) ($this->config['forms_path'] ?? ''), '/');
        if ($forms !== '' && ($path === $forms || str_starts_with($path, $forms . '/'))) {
            throw new RuntimeException('Настройки столбцов нельзя хранить в каталоге форм');
        }

        return $path;
    }

    /**
     * Сохранённые столбцы формы или null, если админ ещё не настраивал её
     * (тогда действуют столбцы по умолчанию: плагин или только дата/почта/открыть).
     *
     * @return list<array{key: string, label: string}>|null
     */
    public function get(string $formId): ?array
    {
        self::assertFormId($formId);
        $all = $this->read();

        return $all[$formId] ?? null;
    }

    /**
     * @param list<array{key: string, label: string}> $columns уже проверенный список; пустой список — явный отказ от столбцов данных
     */
    public function save(string $formId, array $columns): void
    {
        self::assertFormId($formId);
        $normalized = [];
        $seen = [];
        foreach ($columns as $column) {
            if (!isset($column['key'], $column['label']) || !is_string($column['key']) || !is_string($column['label'])) {
                throw new InvalidArgumentException('Некорректный столбец');
            }
            if (!SubmissionColumns::isKey($column['key']) || isset($seen[$column['key']])) {
                throw new InvalidArgumentException('Некорректный ключ столбца');
            }
            $seen[$column['key']] = true;
            $normalized[] = [
                'key' => $column['key'],
                'label' => SubmissionColumns::label($column['label']),
            ];
        }

        $all = $this->read();
        $all[$formId] = $normalized;
        $this->write($all);
    }

    /** Удалить настройку формы. Повторный сброс ничего не пишет, если записи и так нет. */
    public function reset(string $formId): void
    {
        self::assertFormId($formId);
        $all = $this->read();
        if (!array_key_exists($formId, $all)) {
            return;
        }
        unset($all[$formId]);
        $this->write($all);
    }

    public static function assertFormId(string $formId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formId)) {
            throw new InvalidArgumentException('Некорректный id формы');
        }
    }

    /**
     * @return array<string, list<array{key: string, label: string}>>
     */
    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(self::FILE_NAME . ' не читается. Файл не изменён.');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(self::FILE_NAME . ' повреждён (не JSON). Файл не изменён.');
        }
        // json_decode('{}') даёт [] — пустой объект, не список форм. [] в файле — порча.
        if ($data === [] && str_starts_with(ltrim($raw), '{')) {
            return [];
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException(self::FILE_NAME . ' повреждён (ожидается объект). Файл не изменён.');
        }

        $out = [];
        foreach ($data as $formId => $columns) {
            $formId = (string) $formId;
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formId) || !is_array($columns) || !array_is_list($columns)) {
                throw new RuntimeException(self::FILE_NAME . ' повреждён (запись формы). Файл не изменён.');
            }
            $norm = [];
            foreach ($columns as $column) {
                if (
                    !is_array($column)
                    || !isset($column['key'], $column['label'])
                    || !is_string($column['key'])
                    || !is_string($column['label'])
                    || !SubmissionColumns::isKey($column['key'])
                ) {
                    throw new RuntimeException(self::FILE_NAME . ' повреждён (столбец). Файл не изменён.');
                }
                $norm[] = [
                    'key' => $column['key'],
                    'label' => SubmissionColumns::label($column['label']),
                ];
            }
            $out[$formId] = $norm;
        }

        return $out;
    }

    /**
     * @param array<string, list<array{key: string, label: string}>> $data
     */
    private function write(array $data): void
    {
        $path = $this->path();
        $payload = $data === [] ? new stdClass() : $data;
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        FileWriter::write($path, $json);
    }
}
