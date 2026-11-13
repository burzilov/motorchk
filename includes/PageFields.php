<?php

/**
 * Поля и панели редактора страницы, регистрируемые плагинами.
 * Не знает о конкретных плагинах: только контракт типов, условий показа и валидации.
 */
final class PageFields
{
    public const TYPES = ['text', 'textarea', 'number', 'url', 'select', 'checkbox'];

    public const PLACEMENTS = ['main', 'aside'];

    /** Ключи front matter, которые плагины не могут регистрировать или перезаписывать через поля. */
    public const RESERVED = [
        'title',
        'slug',
        'parent',
        'order',
        'template',
        'published',
        'description',
        'og_title',
        'og_description',
        'og_image',
        'scripts',
        'created_at',
        'updated_at',
        'menu',
        'locale',
    ];

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public static function normalizeField(string $plugin, string $key, array $spec): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException('registerPageField(): ключ «' . $key . '» — только [a-z][a-z0-9_]*');
        }
        if (in_array($key, self::RESERVED, true)) {
            throw new InvalidArgumentException('registerPageField(): ключ «' . $key . '» зарезервирован ядром');
        }

        $type = (string) ($spec['type'] ?? 'text');
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('registerPageField(): неизвестный тип «' . $type . '»');
        }

        $label = self::normalizeLabel($spec['label'] ?? $key, 'label');
        $hint = array_key_exists('hint', $spec) ? self::normalizeLabel($spec['hint'], 'hint') : null;
        $placement = (string) ($spec['placement'] ?? 'aside');
        if (!in_array($placement, self::PLACEMENTS, true)) {
            throw new InvalidArgumentException('registerPageField(): placement — main или aside');
        }

        $options = [];
        if ($type === 'select') {
            $raw = $spec['options'] ?? null;
            if (!is_array($raw) || $raw === []) {
                throw new InvalidArgumentException('registerPageField(): для select нужны options');
            }
            foreach ($raw as $optValue => $optLabel) {
                if (!is_string($optValue) && !is_int($optValue)) {
                    throw new InvalidArgumentException('registerPageField(): ключ option должен быть строкой');
                }
                $options[(string) $optValue] = self::normalizeLabel($optLabel, 'option');
            }
        }

        $validate = $spec['validate'] ?? null;
        if ($validate !== null && !is_callable($validate)) {
            throw new InvalidArgumentException('registerPageField(): validate должен быть callable');
        }

        return [
            'key' => $key,
            'plugin' => $plugin,
            'type' => $type,
            'label' => $label,
            'hint' => $hint,
            'placement' => $placement,
            'options' => $options,
            'default' => $spec['default'] ?? ($type === 'checkbox' ? false : ''),
            'show_when' => self::normalizeShowWhen($spec['show_when'] ?? null),
            'validate' => $validate,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public static function normalizePanel(string $plugin, string $id, array $spec): array
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $id)) {
            throw new InvalidArgumentException('registerPagePanel(): id «' . $id . '» — только [a-z][a-z0-9_-]*');
        }

        $label = self::normalizeLabel($spec['label'] ?? $id, 'label');
        $placement = (string) ($spec['placement'] ?? 'main');
        if (!in_array($placement, self::PLACEMENTS, true)) {
            throw new InvalidArgumentException('registerPagePanel(): placement — main или aside');
        }

        $render = $spec['render'] ?? null;
        if (!is_callable($render)) {
            throw new InvalidArgumentException('registerPagePanel(): нужен callable render');
        }

        return [
            'id' => $id,
            'plugin' => $plugin,
            'label' => $label,
            'placement' => $placement,
            'show_when' => self::normalizeShowWhen($spec['show_when'] ?? null),
            'render' => $render,
        ];
    }

    /**
     * @param mixed $showWhen
     * @return array{template?: list<string>, section?: list<string>}|null
     */
    public static function normalizeShowWhen(mixed $showWhen): ?array
    {
        if ($showWhen === null) {
            return null;
        }
        if (!is_array($showWhen)) {
            throw new InvalidArgumentException('show_when должен быть массивом');
        }

        $out = [];
        if (isset($showWhen['template'])) {
            $out['template'] = self::stringList($showWhen['template'], 'show_when.template');
        }
        if (isset($showWhen['section'])) {
            $out['section'] = self::stringList($showWhen['section'], 'show_when.section');
        }
        if ($out === []) {
            return null;
        }

        return $out;
    }

    /**
     * @param array{template?: list<string>, section?: list<string>}|null $showWhen
     * @param array<string, mixed> $frontMatter
     */
    public static function matches(?array $showWhen, array $frontMatter, string $slug): bool
    {
        if ($showWhen === null) {
            return true;
        }

        if (isset($showWhen['template'])) {
            $template = (string) ($frontMatter['template'] ?? 'default');
            if (!in_array($template, $showWhen['template'], true)) {
                return false;
            }
        }

        if (isset($showWhen['section'])) {
            $parent = (string) ($frontMatter['parent'] ?? '');
            $ok = false;
            foreach ($showWhen['section'] as $section) {
                if ($slug === $section || $parent === $section || str_starts_with($slug, $section . '/')) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $frontMatter
     */
    public static function valueFromFrontMatter(array $field, array $frontMatter): mixed
    {
        $key = $field['key'];
        if (!array_key_exists($key, $frontMatter)) {
            return $field['default'];
        }
        $value = $frontMatter[$key];
        if ($field['type'] === 'checkbox') {
            return !empty($value);
        }
        if ($field['type'] === 'number') {
            return is_numeric($value) ? $value + 0 : $field['default'];
        }

        return is_scalar($value) || $value === null ? (string) ($value ?? '') : $field['default'];
    }

    /**
     * Прочитать значение поля из POST (поля формы page_field[{key}]).
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $post
     */
    public static function valueFromPost(array $field, array $post): mixed
    {
        $bag = is_array($post['page_field'] ?? null) ? $post['page_field'] : [];
        $key = $field['key'];

        if ($field['type'] === 'checkbox') {
            return !empty($bag[$key]);
        }

        if (!array_key_exists($key, $bag)) {
            return $field['default'];
        }

        $raw = $bag[$key];
        if (!is_scalar($raw) && $raw !== null) {
            throw new InvalidArgumentException('Некорректное значение поля «' . $key . '»');
        }

        $value = trim((string) $raw);

        return match ($field['type']) {
            'number' => $value === '' ? null : (is_numeric($value) ? $value + 0 : throw new InvalidArgumentException('Поле «' . $key . '» должно быть числом')),
            'url' => self::normalizeUrl($value, $key),
            'select' => self::normalizeSelect($value, $field),
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $page полная страница из PageLoader
     */
    public static function validateField(array $field, mixed $value, array $page): ?string
    {
        if ($field['type'] === 'url' && is_string($value) && $value !== '') {
            if (filter_var($value, FILTER_VALIDATE_URL) === false && !str_starts_with($value, '/')) {
                return 'Поле «' . self::labelText($field['label']) . '» — URL или путь от корня сайта';
            }
        }

        if ($field['validate'] !== null) {
            $error = ($field['validate'])($value, $page);
            if ($error !== null && $error !== '') {
                return (string) $error;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|string $label */
    public static function labelText(array|string $label, ?string $locale = null): string
    {
        if (is_string($label)) {
            return $label;
        }

        return (string) SiteLocale::localized($label, $locale);
    }

    /**
     * HTML одного поля редактора.
     *
     * @param array<string, mixed> $field
     */
    public static function renderField(array $field, mixed $value): string
    {
        $key = $field['key'];
        $id = 'page_field_' . $key;
        $name = 'page_field[' . $key . ']';
        $label = htmlspecialchars(self::labelText($field['label']), ENT_QUOTES, 'UTF-8');
        $hint = $field['hint'] !== null
            ? '<p class="mc-hint">' . htmlspecialchars(self::labelText($field['hint']), ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        if ($field['type'] === 'checkbox') {
            $checked = !empty($value) ? ' checked' : '';

            return '<label class="mc-check">'
                . '<input type="checkbox" id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="1"' . $checked . '>'
                . ' ' . $label
                . '</label>' . $hint;
        }

        $html = '<div><label class="mc-label" for="' . $id . '">' . $label . '</label>';

        if ($field['type'] === 'textarea') {
            $html .= '<textarea id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" rows="3" class="mc-input">'
                . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</textarea>';
        } elseif ($field['type'] === 'select') {
            $html .= '<select id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="mc-input">';
            foreach ($field['options'] as $optValue => $optLabel) {
                $selected = ((string) $value === (string) $optValue) ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars((string) $optValue, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>'
                    . htmlspecialchars(self::labelText($optLabel), ENT_QUOTES, 'UTF-8') . '</option>';
            }
            $html .= '</select>';
        } else {
            $inputType = match ($field['type']) {
                'number' => 'number',
                'url' => 'url',
                default => 'text',
            };
            $html .= '<input id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" type="' . $inputType . '" value="'
                . htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8') . '" class="mc-input">';
        }

        return $html . $hint . '</div>';
    }

    /**
     * @param array<string, mixed> $panel
     * @param array<string, mixed> $page
     */
    public static function renderPanel(array $panel, array $page, PluginContext $plugin): string
    {
        $label = htmlspecialchars(self::labelText($panel['label']), ENT_QUOTES, 'UTF-8');
        try {
            $body = (string) ($panel['render'])($page, $plugin);
        } catch (Throwable $e) {
            error_log('motorchk page panel ' . $panel['plugin'] . '/' . $panel['id'] . ': ' . $e->getMessage());
            $body = '<p class="mc-note">Панель временно недоступна.</p>';
        }

        return '<section class="mc-page-panel" data-plugin="' . htmlspecialchars($panel['plugin'], ENT_QUOTES, 'UTF-8')
            . '" data-panel="' . htmlspecialchars($panel['id'], ENT_QUOTES, 'UTF-8') . '">'
            . '<h2 class="mc-page-panel__title">' . $label . '</h2>'
            . '<div class="mc-page-panel__body">' . $body . '</div>'
            . '</section>';
    }

    public static function isReserved(string $key): bool
    {
        return in_array($key, self::RESERVED, true);
    }

    /** @return array<string, mixed>|string */
    private static function normalizeLabel(mixed $label, string $what): array|string
    {
        if (is_string($label)) {
            $label = trim($label);
            if ($label === '' || mb_strlen($label) > 120) {
                throw new InvalidArgumentException('registerPageField(): ' . $what . ' — строка до 120 символов');
            }

            return $label;
        }
        if (!is_array($label) || $label === [] || array_is_list($label)) {
            throw new InvalidArgumentException('registerPageField(): ' . $what . ' — строка или карта язык → строка');
        }
        $out = [];
        foreach ($label as $locale => $text) {
            if (!is_string($locale) || !preg_match('/^[a-z]{2}(?:-[a-zA-Z]+)?$/', $locale) || !is_string($text)) {
                throw new InvalidArgumentException('registerPageField(): некорректный ' . $what);
            }
            $text = trim($text);
            if ($text === '' || mb_strlen($text) > 120) {
                throw new InvalidArgumentException('registerPageField(): ' . $what . ' — строка до 120 символов');
            }
            $out[$locale] = $text;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $what): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException($what . ' — непустой список строк');
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException($what . ' — только непустые строки');
            }
            $out[] = trim($item);
        }

        return array_values(array_unique($out));
    }

    private static function normalizeUrl(string $value, string $key): string
    {
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, '/')) {
            return $value;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Поле «' . $key . '» — URL или путь от корня сайта');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function normalizeSelect(string $value, array $field): string
    {
        if (!array_key_exists($value, $field['options'])) {
            throw new InvalidArgumentException('Недопустимое значение поля «' . $field['key'] . '»');
        }

        return $value;
    }
}
