<?php

class EnvWriter
{
    public function __construct(private string $envPath)
    {
    }

    /**
     * @param array<string, string|null> $updates null values are skipped
     */
    public function merge(array $updates): void
    {
        $vars = $this->readAll();

        foreach ($updates as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException('Некорректный ключ .env: ' . $key);
            }
            $vars[$key] = $value;
            Env::set($key, $value);
        }

        $this->writeAll($vars);
    }

    /**
     * @return array<string, string>
     */
    private function readAll(): array
    {
        if (!is_file($this->envPath)) {
            return [];
        }

        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Не удалось прочитать .env');
        }

        $vars = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $pos));
            $value = trim(substr($trimmed, $pos + 1));
            $vars[$key] = $this->unquoteValue($value);
        }

        return $vars;
    }

    private function unquoteValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);
                if ($first === '"') {
                    return stripcslashes($inner);
                }

                return $inner;
            }
        }

        return $value;
    }

    /**
     * @param array<string, string> $vars
     */
    private function writeAll(array $vars): void
    {
        $lines = [];
        foreach ($vars as $key => $value) {
            $lines[] = $key . '=' . $this->escapeValue($value);
        }

        FileWriter::write($this->envPath, implode("\n", $lines) . "\n");
    }

    private function escapeValue(string $value): string
    {
        if ($value === '' || !preg_match('/[\s#\\\\"\'$]/', $value)) {
            return $value;
        }

        return '"' . addcslashes($value, "\\\"\n\r") . '"';
    }
}
