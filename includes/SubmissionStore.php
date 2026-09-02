<?php

class SubmissionStore
{
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
     * @return list<array<string, mixed>>
     */
    public function list(?string $formId = null, ?string $mailStatus = null, int $limit = 200): array
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
        $items = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            if ($mailStatus !== null && $mailStatus !== '' && ($data['mail_status'] ?? '') !== $mailStatus) {
                continue;
            }
            $items[] = $data;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function pathFor(string $formId, string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new InvalidArgumentException('Некорректный идентификатор заявки');
        }

        return $this->root() . '/' . $formId . '/' . $id . '.json';
    }
}
