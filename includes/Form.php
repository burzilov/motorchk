<?php

class Form
{
    private const HONEYPOT_NAME = '_hp_company';
    private const IGNORED_NAMES = [
        '_csrf',
        '_form_contract',
        'form_ts',
        '_hp_company',
        'form_id',
    ];

    private const ALLOWED_TYPES = [
        'text',
        'email',
        'tel',
        'textarea',
        'select',
        'checkbox',
        'radio',
    ];

    private static ?array $config = null;
    private static ?string $activeFormId = null;
    private static bool $buffering = false;

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function begin(string $formId): void
    {
        if (self::$buffering) {
            throw new RuntimeException('Form::begin уже вызван');
        }
        if (self::$config === null) {
            throw new RuntimeException('Form::setConfig не вызван');
        }

        $repo = new FormRepository(self::$config);
        $repo->get($formId);

        self::$activeFormId = $formId;
        self::$buffering = true;

        $csrf = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');
        $hp = htmlspecialchars(self::HONEYPOT_NAME, ENT_QUOTES, 'UTF-8');
        $ts = (string) time();
        $fid = htmlspecialchars($formId, ENT_QUOTES, 'UTF-8');

        echo '<form method="post" action="/api/forms/submit" data-motorchk-form="' . $fid . '" novalidate>' . "\n";
        echo '<input type="hidden" name="form_id" value="' . $fid . '">' . "\n";
        echo '<input type="hidden" name="_csrf" value="' . $csrf . '">' . "\n";
        echo '<input type="hidden" name="form_ts" value="' . $ts . '">' . "\n";
        echo '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">' . "\n";
        echo '<label>Company <input type="text" name="' . $hp . '" value="" tabindex="-1" autocomplete="off"></label>' . "\n";
        echo '</div>' . "\n";

        ob_start();
    }

    public static function end(): void
    {
        if (!self::$buffering || self::$activeFormId === null) {
            throw new RuntimeException('Form::end без Form::begin');
        }

        $html = ob_get_clean();
        if ($html === false) {
            $html = '';
        }

        $fields = self::parseFields($html);
        $contract = self::signContract(self::$activeFormId, $fields);
        $token = htmlspecialchars($contract, ENT_QUOTES, 'UTF-8');

        echo $html;
        echo '<input type="hidden" name="_form_contract" value="' . $token . '">' . "\n";
        echo '</form>' . "\n";

        self::$buffering = false;
        self::$activeFormId = null;
    }

    /**
     * @param list<array{name: string, type: string, required: bool, label: string}> $fields
     */
    public static function signContract(string $formId, array $fields): string
    {
        $payload = json_encode([
            'form_id' => $formId,
            'fields' => $fields,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $secret = (string) Env::get('APP_SECRET', '');
        if ($secret === '') {
            throw new RuntimeException('APP_SECRET не задан');
        }

        $body = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, $secret);

        return $body . '.' . $sig;
    }

    /**
     * @return array{form_id: string, fields: list<array{name: string, type: string, required: bool, label: string}>}
     */
    public static function verifyContract(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Некорректный контракт формы');
        }

        [$body, $sig] = $parts;
        $secret = (string) Env::get('APP_SECRET', '');
        if ($secret === '') {
            throw new RuntimeException('APP_SECRET не задан');
        }

        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $sig)) {
            throw new InvalidArgumentException('Подпись контракта формы недействительна');
        }

        $padded = strtr($body, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($padded, true);
        if ($json === false) {
            throw new InvalidArgumentException('Некорректный контракт формы');
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['form_id'], $data['fields']) || !is_array($data['fields'])) {
            throw new InvalidArgumentException('Некорректный контракт формы');
        }

        $fields = [];
        foreach ($data['fields'] as $field) {
            if (!is_array($field) || !isset($field['name'], $field['type'])) {
                continue;
            }
            $type = (string) $field['type'];
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                continue;
            }
            if ($type === 'checkbox' && (!array_key_exists('multiple', $field) || !is_bool($field['multiple']))) {
                throw new InvalidArgumentException('Некорректный контракт формы');
            }
            $verified = [
                'name' => (string) $field['name'],
                'type' => $type,
                'required' => !empty($field['required']),
                'label' => (string) ($field['label'] ?? $field['name']),
            ];
            if ($type === 'checkbox') {
                $verified['multiple'] = $field['multiple'];
            }
            $fields[] = $verified;
        }

        return [
            'form_id' => (string) $data['form_id'],
            'fields' => $fields,
        ];
    }

    public static function honeypotName(): string
    {
        return self::HONEYPOT_NAME;
    }

    /**
     * @return list<array{name: string, type: string, required: bool, label: string}>
     */
    public static function parseFields(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $wrapped = '<?xml encoding="UTF-8"><div id="motorchk-form-root">' . $html . '</div>';
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new DOMXPath($dom);
        $labelsByFor = [];
        foreach ($xpath->query('//label[@for]') ?: [] as $labelNode) {
            /** @var DOMElement $labelNode */
            $for = $labelNode->getAttribute('for');
            $labelsByFor[$for] = trim(preg_replace('/\s+/u', ' ', $labelNode->textContent) ?? '');
        }

        /** @var array<string, array{name: string, type: string, required: bool, label: string, multi: bool}> $map */
        $map = [];

        $register = static function (
            string $name,
            string $type,
            bool $required,
            string $label,
            bool $multi = false
        ) use (&$map): void {
            $name = self::normalizeFieldName($name);
            if ($name === '' || in_array($name, self::IGNORED_NAMES, true)) {
                return;
            }
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                return;
            }

            if (!isset($map[$name])) {
                $map[$name] = [
                    'name' => $name,
                    'type' => $type,
                    'required' => $required,
                    'label' => $label !== '' ? $label : $name,
                    'multi' => $multi,
                ];
                return;
            }

            $map[$name]['required'] = $map[$name]['required'] || $required;
            $map[$name]['multi'] = $map[$name]['multi'] || $multi || ($map[$name]['type'] === 'checkbox' && $type === 'checkbox');
            if ($map[$name]['label'] === $map[$name]['name'] && $label !== '') {
                $map[$name]['label'] = $label;
            }
        };

        foreach ($xpath->query('//input') ?: [] as $node) {
            /** @var DOMElement $node */
            $typeAttr = strtolower($node->getAttribute('type') ?: 'text');
            if (in_array($typeAttr, ['hidden', 'submit', 'button', 'reset', 'file', 'image', 'password'], true)) {
                continue;
            }

            $rawName = $node->getAttribute('name');
            if ($rawName === '') {
                continue;
            }
            $multi = str_ends_with($rawName, '[]');
            $name = self::normalizeFieldName($rawName);

            $type = match ($typeAttr) {
                'email' => 'email',
                'tel' => 'tel',
                'checkbox' => 'checkbox',
                'radio' => 'radio',
                default => 'text',
            };

            $label = $node->getAttribute('data-label');
            if ($label === '' && $node->hasAttribute('id') && isset($labelsByFor[$node->getAttribute('id')])) {
                $label = $labelsByFor[$node->getAttribute('id')];
            }
            if ($label === '') {
                $label = self::nearestLabelText($node);
            }

            $register($name, $type, $node->hasAttribute('required'), $label, $multi);
        }

        foreach ($xpath->query('//textarea') ?: [] as $node) {
            /** @var DOMElement $node */
            $rawName = $node->getAttribute('name');
            if ($rawName === '') {
                continue;
            }
            $name = self::normalizeFieldName($rawName);
            $label = $node->getAttribute('data-label');
            if ($label === '' && $node->hasAttribute('id') && isset($labelsByFor[$node->getAttribute('id')])) {
                $label = $labelsByFor[$node->getAttribute('id')];
            }
            if ($label === '') {
                $label = self::nearestLabelText($node);
            }
            $register($name, 'textarea', $node->hasAttribute('required'), $label, false);
        }

        foreach ($xpath->query('//select') ?: [] as $node) {
            /** @var DOMElement $node */
            $rawName = $node->getAttribute('name');
            if ($rawName === '') {
                continue;
            }
            $multi = $node->hasAttribute('multiple') || str_ends_with($rawName, '[]');
            $name = self::normalizeFieldName($rawName);
            $label = $node->getAttribute('data-label');
            if ($label === '' && $node->hasAttribute('id') && isset($labelsByFor[$node->getAttribute('id')])) {
                $label = $labelsByFor[$node->getAttribute('id')];
            }
            if ($label === '') {
                $label = self::nearestLabelText($node);
            }
            $register($name, 'select', $node->hasAttribute('required'), $label, $multi);
        }

        $fields = [];
        foreach ($map as $field) {
            $result = [
                'name' => $field['name'],
                'type' => $field['type'],
                'required' => $field['required'],
                'label' => $field['label'],
            ];
            if ($field['type'] === 'checkbox') {
                $result['multiple'] = $field['multi'];
            }
            $fields[] = $result;
        }

        return $fields;
    }

    private static function normalizeFieldName(string $name): string
    {
        $name = trim($name);
        if (str_ends_with($name, '[]')) {
            $name = substr($name, 0, -2);
        }

        return $name;
    }

    private static function nearestLabelText(DOMElement $node): string
    {
        $parent = $node->parentNode;
        while ($parent instanceof DOMElement) {
            if (strtolower($parent->tagName) === 'label') {
                return trim(preg_replace('/\s+/u', ' ', $parent->textContent) ?? '');
            }
            $parent = $parent->parentNode;
        }

        return '';
    }
}
