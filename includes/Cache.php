<?php

class Cache
{
    public function __construct(private string $cachePath)
    {
        if (!is_dir($this->cachePath) && !mkdir($this->cachePath, 0775, true) && !is_dir($this->cachePath)) {
            throw new RuntimeException('Не удалось создать директорию кеша');
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->filePath($key);
        if (!is_file($file)) {
            return null;
        }

        $json = file_get_contents($file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('value', $data)) {
            return null;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value): void
    {
        $payload = [
            'key' => $key,
            'created_at' => time(),
            'value' => $value,
        ];

        FileWriter::write($this->filePath($key), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function delete(string $key): void
    {
        $file = $this->filePath($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    public function invalidate(string $pattern): void
    {
        if (!str_ends_with($pattern, '*')) {
            $this->delete($pattern);
            return;
        }

        $prefix = substr($pattern, 0, -1);
        foreach (glob($this->cachePath . '/*.json') ?: [] as $file) {
            $json = file_get_contents($file);
            if ($json === false) {
                continue;
            }
            $data = json_decode($json, true);
            if (!is_array($data) || !isset($data['key'])) {
                continue;
            }
            if (str_starts_with($data['key'], $prefix)) {
                unlink($file);
            }
        }
    }

    public function flush(): void
    {
        foreach (glob($this->cachePath . '/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    private function filePath(string $key): string
    {
        return $this->cachePath . '/' . hash('sha256', $key) . '.json';
    }
}
