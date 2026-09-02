<?php

use Symfony\Component\Yaml\Yaml;

class FormRepository
{
    public function __construct(private array $config)
    {
    }

    /**
     * @return array{id: string, label: string, subject: string, success_message: string}
     */
    public function get(string $formId): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $formId)) {
            throw new InvalidArgumentException('Некорректный id формы');
        }

        $path = rtrim((string) $this->config['forms_path'], '/') . '/' . $formId . '.yaml';
        if (!is_file($path)) {
            throw new RuntimeException('Форма не найдена: ' . $formId);
        }

        $data = Yaml::parseFile($path);
        if (!is_array($data)) {
            throw new RuntimeException('Некорректный YAML формы: ' . $formId);
        }

        $id = (string) ($data['id'] ?? $formId);
        if ($id !== $formId) {
            throw new RuntimeException('id в YAML не совпадает с именем файла');
        }

        return [
            'id' => $id,
            'label' => (string) ($data['label'] ?? $id),
            'subject' => (string) ($data['subject'] ?? 'Заявка с сайта'),
            'success_message' => (string) ($data['success_message'] ?? 'Спасибо!'),
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function listMeta(): array
    {
        $dir = (string) ($this->config['forms_path'] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $items = [];
        foreach (glob($dir . '/*.yaml') ?: [] as $file) {
            $id = basename($file, '.yaml');
            try {
                $meta = $this->get($id);
                $items[] = ['id' => $meta['id'], 'label' => $meta['label']];
            } catch (Throwable) {
                $items[] = ['id' => $id, 'label' => $id];
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $items;
    }
}
