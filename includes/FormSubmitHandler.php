<?php

class FormSubmitHandler
{
    private const MIN_SECONDS = 2;

    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, message?: string, errors?: array<string, string>}
     */
    public function handle(array $input, string $ip): array
    {
        $formId = trim((string) ($input['form_id'] ?? ''));
        $csrf = isset($input['_csrf']) ? (string) $input['_csrf'] : null;
        $contractToken = (string) ($input['_form_contract'] ?? '');
        $honeypot = (string) ($input[Form::honeypotName()] ?? '');
        $formTs = (int) ($input['form_ts'] ?? 0);

        if ($formId === '') {
            return $this->fail(400, ['_form' => 'Не указана форма']);
        }

        if (!Csrf::validate($csrf)) {
            return $this->fail(400, ['_form' => 'Неверный CSRF-токен']);
        }

        if ($honeypot !== '') {
            return $this->fail(400, ['_form' => 'Запрос отклонён']);
        }

        if ($formTs <= 0 || (time() - $formTs) < self::MIN_SECONDS) {
            return $this->fail(400, ['_form' => 'Слишком быстрая отправка, попробуйте ещё раз']);
        }

        if ((time() - $formTs) > 86400) {
            return $this->fail(400, ['_form' => 'Форма устарела, обновите страницу']);
        }

        try {
            $meta = (new FormRepository($this->config))->get($formId);
        } catch (Throwable) {
            return $this->fail(404, ['_form' => 'Форма не найдена']);
        }

        try {
            $contract = Form::verifyContract($contractToken);
        } catch (Throwable) {
            return $this->fail(400, ['_form' => 'Некорректный контракт формы']);
        }

        if ($contract['form_id'] !== $formId) {
            return $this->fail(400, ['_form' => 'Контракт не соответствует форме']);
        }

        $limiter = new RateLimiter($this->config['cache_path'], 10, 3600);
        $rateKey = 'form_' . $formId . '_' . hash('sha256', $ip);
        if (!$limiter->hit($rateKey)) {
            return [
                'ok' => false,
                'status' => 429,
                'errors' => ['_form' => 'Слишком много заявок, попробуйте позже'],
            ];
        }

        $errors = [];
        $payload = [];
        foreach ($contract['fields'] as $field) {
            $name = $field['name'];
            $raw = $input[$name] ?? ($input[$name . '[]'] ?? null);
            $value = $this->normalizeValue($raw, $field['type']);

            if ($field['required'] && $this->isEmpty($value)) {
                $errors[$name] = 'Обязательное поле';
                continue;
            }

            if (!$this->isEmpty($value)) {
                $typeError = $this->validateType($value, $field['type']);
                if ($typeError !== null) {
                    $errors[$name] = $typeError;
                    continue;
                }
            }

            $payload[$name] = $value;
        }

        if ($errors !== []) {
            return $this->fail(422, $errors);
        }

        $store = new SubmissionStore($this->config);
        $created = $store->create($formId, $payload, $contract['fields'], $ip);

        $subject = $this->renderSubject($meta['subject'], $payload);
        $body = $this->renderBody($meta['label'], $contract['fields'], $payload, $created['id']);

        try {
            (new Mailer($this->config))->send($subject, $body);
            $store->updateMailStatus($formId, $created['id'], 'sent', null);
        } catch (Throwable $e) {
            $store->updateMailStatus($formId, $created['id'], 'failed', $e->getMessage());
        }

        return [
            'ok' => true,
            'status' => 200,
            'message' => $meta['success_message'],
        ];
    }

    /**
     * @param array<string, string> $errors
     * @return array{ok: bool, status: int, errors: array<string, string>}
     */
    private function fail(int $status, array $errors): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'errors' => $errors,
        ];
    }

    /** Значения одиночного флажка, которые считаются «не отмечен» (старый forms.js шлёт '' для снятого флажка). */
    private const CHECKBOX_OFF = ['', '0', 'false', 'off', 'no', 'нет'];

    /** Значения одиночного флажка, которые считаются «отмечен» (value по умолчанию — «on»). */
    private const CHECKBOX_ON = ['1', 'true', 'on', 'yes', 'да'];

    private function normalizeValue(mixed $raw, string $type): mixed
    {
        if ($raw === null) {
            return $type === 'checkbox' ? false : '';
        }

        if (is_array($raw)) {
            $values = [];
            foreach ($raw as $item) {
                if (is_scalar($item)) {
                    $values[] = trim((string) $item);
                }
            }

            return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
        }

        if ($type === 'checkbox') {
            return self::normalizeCheckbox($raw);
        }

        return trim((string) $raw);
    }

    /**
     * Одиночный флажок: true/false. Снятый ('', '0', 'false', 'off', 'no', 'нет', JSON false) — false,
     * отмеченный (JSON true, 'on', '1', 'true', 'yes', 'да') — true. Другое непустое значение
     * (value="…" у флажка из группы, отправленной без JS) сохраняется строкой, как раньше.
     */
    public static function normalizeCheckbox(mixed $raw): bool|string
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return $raw != 0;
        }
        if (!is_scalar($raw)) {
            return false;
        }

        $str = trim((string) $raw);
        $lower = mb_strtolower($str, 'UTF-8');
        if (in_array($lower, self::CHECKBOX_OFF, true)) {
            return false;
        }
        if (in_array($lower, self::CHECKBOX_ON, true)) {
            return true;
        }

        return $str;
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }
        if (is_bool($value)) {
            return $value === false;
        }

        return $value === null || $value === '';
    }

    private function validateType(mixed $value, string $type): ?string
    {
        if ($type === 'email') {
            $email = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'Некорректный email';
            }
        }

        if ($type === 'tel') {
            $tel = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            if (!preg_match('/^[0-9+\s()\-]{5,32}$/', $tel)) {
                return 'Некорректный телефон';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderSubject(string $template, array $payload): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function (array $m) use ($payload): string {
            $val = $payload[$m[1]] ?? '';
            if (is_array($val)) {
                return implode(', ', array_map('strval', $val));
            }
            if (is_bool($val)) {
                return $val ? 'да' : 'нет';
            }

            return (string) $val;
        }, $template);
    }

    /**
     * @param list<array{name: string, type: string, required: bool, label: string}> $fields
     * @param array<string, mixed> $payload
     */
    private function renderBody(string $formLabel, array $fields, array $payload, string $submissionId): string
    {
        $lines = [
            'Форма: ' . $formLabel,
            'Заявка: ' . $submissionId,
            '---',
        ];

        foreach ($fields as $field) {
            $name = $field['name'];
            $val = $payload[$name] ?? '';
            if (is_array($val)) {
                $text = implode(', ', array_map('strval', $val));
            } elseif (is_bool($val)) {
                $text = $val ? 'да' : 'нет';
            } else {
                $text = (string) $val;
            }
            $lines[] = $field['label'] . ': ' . $text;
        }

        return implode("\n", $lines);
    }
}
