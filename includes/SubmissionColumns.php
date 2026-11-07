<?php

/**
 * Правила столбцов таблицы заявок: ключи, подписи, пустое значение.
 * Настройки админки и столбцы по умолчанию плагина проходят через один и тот же разбор.
 */
final class SubmissionColumns
{
    /** Нет ключа в заявке или значение null. */
    public const EMPTY_MARK = '—';

    public const LABEL_MAX = 80;

    public const FORM_LABEL_MAX = 100;

    public static function isKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $key);
    }

    public static function label(string $label): string
    {
        return self::bounded($label, self::LABEL_MAX, 'Подпись столбца');
    }

    public static function formLabel(string $label): string
    {
        return self::bounded($label, self::FORM_LABEL_MAX, 'Подпись формы');
    }

    /**
     * Столбцы плагина или сохранённой настройки: ключ payload => подпись, порядок массива сохраняется.
     *
     * @param array<mixed> $columns
     * @return list<array{key: string, label: string}>
     */
    public static function fromMap(array $columns): array
    {
        if (array_is_list($columns)) {
            throw new InvalidArgumentException('Столбцы задаются ассоциативным массивом: ключ => подпись');
        }

        $out = [];
        foreach ($columns as $key => $label) {
            $key = (string) $key;
            if (!self::isKey($key)) {
                throw new InvalidArgumentException('Некорректный ключ столбца');
            }
            if (isset($out[$key])) {
                throw new InvalidArgumentException('Столбец «' . $key . '» указан дважды');
            }
            if (!is_string($label)) {
                throw new InvalidArgumentException('Подпись столбца должна быть строкой');
            }
            $out[$key] = self::label($label);
        }

        $list = [];
        foreach ($out as $key => $label) {
            $list[] = ['key' => $key, 'label' => $label];
        }

        return $list;
    }

    /**
     * Текст ячейки. Отсутствующий ключ сюда не передают — для него EMPTY_MARK.
     * false/true — «нет»/«да», список — через запятую, как на странице заявки.
     */
    public static function format(mixed $value): string
    {
        if ($value === null) {
            return self::EMPTY_MARK;
        }
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $part) {
                if (is_array($part) || is_object($part) || $part === null) {
                    continue;
                }
                $parts[] = is_bool($part) ? ($part ? 'да' : 'нет') : (string) $part;
            }
            $text = implode(', ', $parts);

            return $text === '' ? self::EMPTY_MARK : $text;
        }

        return self::EMPTY_MARK;
    }

    private static function bounded(string $label, int $max, string $what): string
    {
        if (!mb_check_encoding($label, 'UTF-8')) {
            throw new InvalidArgumentException($what . ': некорректная кодировка');
        }
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if ($label === '' || mb_strlen($label) > $max) {
            throw new InvalidArgumentException($what . ': от 1 до ' . $max . ' символов');
        }

        return $label;
    }
}
