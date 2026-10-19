<?php

/**
 * Файл данных плагина повреждён или не читается (PluginContext::readJsonStrict()).
 * Сообщение называет файл относительно сайта: plugin-data/{id}/{путь}. Файл при этом не меняется.
 */
class PluginDataException extends RuntimeException
{
    public function __construct(string $message, private string $relativePath, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Путь внутри dataDir() плагина, как передан в readJsonStrict(). */
    public function relativePath(): string
    {
        return $this->relativePath;
    }
}
