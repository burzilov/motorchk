<?php

class RateLimiter
{
    public function __construct(
        private string $cachePath,
        private int $maxAttempts = 10,
        private int $windowSeconds = 3600,
    ) {
    }

    public function hit(string $key): bool
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) ?? 'key';
        $dir = rtrim($this->cachePath, '/') . '/rate-limit';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог rate-limit');
        }

        $path = $dir . '/' . $safe . '.json';
        $now = time();
        $cutoff = $now - $this->windowSeconds;
        $hits = [];

        if (is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                foreach ($decoded as $ts) {
                    if (is_int($ts) && $ts >= $cutoff) {
                        $hits[] = $ts;
                    }
                }
            }
        }

        if (count($hits) >= $this->maxAttempts) {
            return false;
        }

        $hits[] = $now;
        FileWriter::write($path, json_encode($hits, JSON_THROW_ON_ERROR));

        return true;
    }
}
