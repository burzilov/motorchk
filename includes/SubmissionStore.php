<?php

class SubmissionStore
{
    /** Размер страницы списка в админке. Потолка «только первые 200» больше нет: остальные страницы доступны. */
    public const PAGE_SIZE = 50;

    public function __construct(private array $config)
    {
    }

    private function root(): string
    {
        $path = (string) ($this->config['submissions_path'] ?? '');
        if ($path === '') {
            throw new RuntimeException('Не задан submissions_path');
        }
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Не удалось создать submissions/');
        }

        return rtrim($path, '/');
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array{name: string, type: string, required: bool, label: string}> $contract
     * @return array{id: string, path: string, data: array<string, mixed>}
     */
    public function create(string $formId, array $payload, array $contract, string $ip): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formId)) {
            throw new InvalidArgumentException('Некорректный form_id');
        }

        $uid = bin2hex(random_bytes(4));
        $stamp = date('Y-m-d_His');
        $id = $stamp . '_' . $uid;
        $dir = $this->root() . '/' . $formId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог заявок');
        }

        $path = $dir . '/' . $id . '.json';
        $data = [
            'id' => $id,
            'form_id' => $formId,
            'created_at' => date('c'),
            'ip' => $ip,
            'payload' => $payload,
            'contract' => $contract,
            'mail_status' => 'pending',
            'mail_error' => null,
        ];

        FileWriter::write($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['id' => $id, 'path' => $path, 'data' => $data];
    }

    public function updateMailStatus(string $formId, string $id, string $status, ?string $error = null): void
    {
        $data = $this->get($formId, $id);
        if ($data === null) {
            throw new RuntimeException('Заявка не найдена');
        }

        $data['mail_status'] = $status;
        $data['mail_error'] = $error;
        $path = $this->pathFor($formId, $id);
        FileWriter::write($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $formId, string $id): ?array
    {
        $path = $this->pathFor($formId, $id);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Первые $limit заявок, новые первыми. Лимит по умолчанию 200 сохранён для старых вызовов;
     * админка ходит в paginate() и показывает все страницы.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $formId = null, ?string $mailStatus = null, int $limit = 200): array
    {
        if ($limit < 1) {
            return [];
        }

        return $this->readMatches($formId, $mailStatus, $limit);
    }

    /**
     * Страница списка. Новые заявки первыми (по имени файла, в нём дата создания).
     * $mailStatus — sent, failed, pending или null (без фильтра).
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function paginate(?string $formId, ?string $mailStatus, int $page, int $perPage = self::PAGE_SIZE): array
    {
        if ($perPage < 1) {
            $perPage = self::PAGE_SIZE;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }
        if ($page < 1) {
            $page = 1;
        }

        $all = $this->readMatches($formId, $mailStatus, null);
        $total = count($all);
        if ($total === 0) {
            return [
                'items' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => $perPage,
                'pages' => 0,
            ];
        }

        $pages = (int) ceil($total / $perPage);
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($all, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * Id каталогов submissions/{id}, включая пустые (форма без заявок остаётся доступной).
     *
     * @return list<string>
     */
    public function formIds(): array
    {
        $root = $this->root();
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return [];
        }
        $ids = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
                continue;
            }
            $path = $root . '/' . $name;
            // Симлинк не открываем: вкладка не должна читать каталог вне submissions/.
            if (is_link($path) || !is_dir($path)) {
                continue;
            }
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $ids[] = $name;
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Ключи полей формы по сохранённым заявкам (новые первыми): сначала подпись из контракта, иначе сам ключ.
     * Тело заявки наружу не копируется.
     *
     * @return array<string, string>
     */
    public function fieldLabels(string $formId): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formId)) {
            return [];
        }

        $labels = [];
        $payloadKeys = [];
        foreach ($this->files($formId) as $file) {
            $data = $this->readJson($file);
            if ($data === null) {
                continue;
            }
            $contract = is_array($data['contract'] ?? null) ? $data['contract'] : [];
            foreach ($contract as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $name = isset($field['name']) && is_string($field['name']) ? $field['name'] : '';
                if (!SubmissionColumns::isKey($name) || isset($labels[$name])) {
                    continue;
                }
                $label = isset($field['label']) && is_string($field['label']) ? $field['label'] : '';
                try {
                    $labels[$name] = trim($label) !== '' ? SubmissionColumns::label($label) : $name;
                } catch (InvalidArgumentException) {
                    $labels[$name] = $name;
                }
            }
            $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
            foreach (array_keys($payload) as $name) {
                $name = (string) $name;
                if (!SubmissionColumns::isKey($name) || isset($payloadKeys[$name])) {
                    continue;
                }
                $payloadKeys[$name] = true;
            }
        }
        foreach (array_keys($payloadKeys) as $name) {
            if (!isset($labels[$name])) {
                $labels[$name] = $name;
            }
        }

        return $labels;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readMatches(?string $formId, ?string $mailStatus, ?int $limit): array
    {
        $items = [];
        foreach ($this->files($formId) as $file) {
            $data = $this->readJson($file);
            if ($data === null) {
                continue;
            }
            if ($mailStatus !== null && $mailStatus !== '' && ($data['mail_status'] ?? '') !== $mailStatus) {
                continue;
            }
            $items[] = $data;
            if ($limit !== null && count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return list<string> абсолютные пути, новые первыми
     */
    private function files(?string $formId): array
    {
        $root = $this->root();
        $files = [];

        if ($formId !== null && $formId !== '') {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formId)) {
                return [];
            }
            $dir = $root . '/' . $formId;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*.json') ?: [] as $file) {
                    $files[] = $file;
                }
            }
        } else {
            foreach (glob($root . '/*/*.json') ?: [] as $file) {
                $files[] = $file;
            }
        }

        rsort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $file): ?array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function pathFor(string $formId, string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new InvalidArgumentException('Некорректный идентификатор заявки');
        }

        return $this->root() . '/' . $formId . '/' . $id . '.json';
    }
}
