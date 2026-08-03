<?php

class FileWriter
{
    public static function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать директорию: ' . $dir);
        }

        $fp = fopen($path, 'c');
        if ($fp === false) {
            throw new RuntimeException('Не удалось открыть файл: ' . $path);
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('Не удалось заблокировать файл: ' . $path);
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $content);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }
}
