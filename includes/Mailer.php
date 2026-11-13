<?php

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class Mailer
{
    private const HTML_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    public function __construct(private array $config = [])
    {
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, password: string, from: string, from_name: string, to: string, bcc: string}
     */
    public function settingsFromEnv(): array
    {
        return [
            'host' => (string) Env::get('SMTP_HOST', ''),
            'port' => (int) (Env::get('SMTP_PORT', '587') ?: 587),
            'encryption' => strtolower(trim((string) Env::get('SMTP_ENCRYPTION', 'tls'))),
            'username' => (string) Env::get('SMTP_USERNAME', ''),
            'password' => (string) Env::get('SMTP_PASSWORD', ''),
            'from' => (string) Env::get('SMTP_FROM', ''),
            'from_name' => (string) Env::get('SMTP_FROM_NAME', ''),
            'to' => (string) Env::get('SMTP_TO', ''),
            'bcc' => (string) Env::get('SMTP_BCC', ''),
        ];
    }

    /**
     * Адреса из SMTP_BCC: один адрес или несколько через запятую.
     * Пробелы по краям обрезаются, пустые фрагменты пропускаются, повторы без учёта регистра схлопываются.
     * Пустая строка и null — пустой список. Некорректный адрес — RuntimeException.
     *
     * @return list<string>
     */
    public static function parseBccList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $seen = [];
        $addresses = [];
        foreach (explode(',', $raw) as $part) {
            $address = trim($part);
            if ($address === '') {
                continue;
            }
            if (!self::isEmailAddress($address)) {
                throw new RuntimeException('Некорректный адрес SMTP_BCC: ' . $address);
            }
            $key = strtolower($address);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $addresses[] = $address;
        }

        return $addresses;
    }

    /**
     * @param array{host?: string, port?: int, encryption?: string, username?: string, password?: string, from?: string, from_name?: string, to?: string, bcc?: string}|null $override
     */
    public function testConnection(?array $override = null): void
    {
        $settings = array_merge($this->settingsFromEnv(), $override ?? []);
        $mail = $this->createMailer($settings);
        if (!$mail->smtpConnect()) {
            throw new RuntimeException('Не удалось установить SMTP-соединение');
        }
        $mail->smtpClose();
    }

    /**
     * Письмо через SMTP. Всегда multipart/alternative: HTML (isHTML) и текст.
     * Без ключа html аргумент $body — обычный текст: ядро собирает HTML, текстовая альтернатива равна $body
     * (или options['text'], если ключ передан).
     * options['html'] — готовый HTML: Body берётся как есть и не оборачивается.
     * Текстовая альтернатива готового HTML: options['text'], иначе непустой $body, иначе текст из HTML.
     *
     * @param array{host?: string, port?: int, encryption?: string, username?: string, password?: string, from?: string, from_name?: string, to?: string, bcc?: string}|null $override
     * @param string|null $replyTo заголовок Reply-To (например, email автора заявки)
     * @param array{html?: string, text?: string} $options
     */
    public function send(string $subject, string $body, ?string $to = null, ?array $override = null, ?string $replyTo = null, array $options = []): void
    {
        [$html, $text] = self::resolveParts($body, $options);

        $settings = array_merge($this->settingsFromEnv(), $override ?? []);
        $recipient = $to ?? $settings['to'];
        if ($recipient === '') {
            throw new RuntimeException('Не указан адрес получателя (SMTP_TO)');
        }
        if ($settings['from'] === '') {
            throw new RuntimeException('Не указан адрес отправителя (SMTP_FROM)');
        }

        $mail = $this->createMailer($settings);
        $mail->setFrom($settings['from'], $settings['from_name'] !== '' ? $settings['from_name'] : '');
        $mail->addAddress($recipient);
        if ($replyTo !== null && $replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;

        try {
            $this->appendBcc($mail, (string) ($settings['bcc'] ?? ''), $recipient);
            if (!$mail->send()) {
                throw new RuntimeException($mail->ErrorInfo !== '' ? $mail->ErrorInfo : 'Ошибка отправки письма');
            }
        } catch (PhpMailerException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Обычный текст → HTML: экранирование, пустая строка — абзац, одиночный перенос — br,
     * ссылки http:// и https:// кликабельны. Исходные переводы строк в тексте не меняются:
     * их кладёт в AltBody вызывающий код.
     */
    public static function textToHtml(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = preg_split('/(?<![A-Za-z0-9+.-])(https?:\/\/[^\s<>"\']+)/i', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            $parts = [$normalized];
        }

        $linked = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                [$url, $trail] = self::splitUrlTrail($part);
                if ($url === '') {
                    $linked .= self::escape($part);
                    continue;
                }
                $safe = self::escape($url);
                $linked .= '<a href="' . $safe . '" style="color:#0b57d0;">' . $safe . '</a>';
                $linked .= self::escape($trail);
                continue;
            }
            $linked .= self::escape($part);
        }

        $blocks = preg_split("/\n{2,}/", $linked, -1, PREG_SPLIT_NO_EMPTY);
        if ($blocks === false) {
            $blocks = [];
        }
        $paragraphs = [];
        foreach ($blocks as $block) {
            $paragraphs[] = '<p style="margin:0 0 1em;">' . str_replace("\n", "<br>\n", $block) . '</p>';
        }
        if ($paragraphs === []) {
            $paragraphs[] = '<p style="margin:0;"></p>';
        }

        return self::htmlDocument(implode("\n", $paragraphs));
    }

    /**
     * Текст из готового HTML: теги снимаются, переносы из br и блочных тегов сохраняются.
     */
    public static function htmlToText(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/<br\b[^>]*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(?:td|th)\s*>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<li\b[^>]*>/i', "\n- ", $html) ?? $html;
        $html = preg_replace('/<\/(?:p|div|h[1-6]|blockquote|pre)\s*>/i', "\n\n", $html) ?? $html;
        $html = preg_replace('/<\/(?:table|section|article|header|footer|ul|ol|tr|li)\s*>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        $lines = explode("\n", $text);
        $lines = array_map(static function (string $line): string {
            $line = preg_replace('/[ \t]+/u', ' ', $line) ?? $line;

            return trim($line);
        }, $lines);
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Обёртка сгенерированного HTML: UTF-8 и читаемый шрифт.
     * Готовый HTML из options['html'] этим методом не оборачивается.
     */
    public static function htmlDocument(string $innerHtml): string
    {
        return "<!DOCTYPE html>\n"
            . "<html>\n"
            . "<head>\n"
            . "<meta charset=\"UTF-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
            . "<title></title>\n"
            . "</head>\n"
            . '<body style="margin:0;padding:16px;background-color:#ffffff;color:#222222;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;">' . "\n"
            . $innerHtml . "\n"
            . "</body>\n"
            . "</html>\n";
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: string, 1: string}
     */
    private static function resolveParts(string $body, array $options): array
    {
        $html = self::optionString($options, 'html');
        $text = self::optionString($options, 'text');
        if ($html !== null) {
            if ($text === null) {
                $text = $body !== '' ? $body : self::htmlToText($html);
            }

            return [$html, $text];
        }
        if ($text === null) {
            $text = $body;
        }

        return [self::textToHtml($body), $text];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function optionString(array $options, string $key): ?string
    {
        if (!array_key_exists($key, $options)) {
            return null;
        }
        $value = $options[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException('send(): параметр ' . $key . ' должен быть строкой');
        }

        return $value;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, self::HTML_FLAGS, 'UTF-8');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitUrlTrail(string $url): array
    {
        $trail = '';
        while ($url !== '' && str_contains('.,;:!?', substr($url, -1))) {
            $trail = substr($url, -1) . $trail;
            $url = substr($url, 0, -1);
        }
        while ($url !== '' && str_ends_with($url, ')') && substr_count($url, ')') > substr_count($url, '(')) {
            $trail = ')' . $trail;
            $url = substr($url, 0, -1);
        }
        while ($url !== '' && str_ends_with($url, ']') && substr_count($url, ']') > substr_count($url, '[')) {
            $trail = ']' . $trail;
            $url = substr($url, 0, -1);
        }

        return [$url, $trail];
    }

    /**
     * @param array{host: string, port: int, encryption: string, username: string, password: string, from: string, from_name: string, to: string} $settings
     */
    private function createMailer(array $settings): PHPMailer
    {
        if ($settings['host'] === '') {
            throw new RuntimeException('Не указан SMTP_HOST');
        }

        $mail = $this->createPhpMailer();
        $mail->isSMTP();
        $mail->Host = $settings['host'];
        $mail->Port = $settings['port'] > 0 ? $settings['port'] : 587;
        $mail->SMTPAuth = $settings['username'] !== '' || $settings['password'] !== '';
        $mail->Username = $settings['username'];
        $mail->Password = $settings['password'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $encryption = $settings['encryption'];
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        return $mail;
    }

    /**
     * Точка подмены в тестах: боевой код создаёт обычный PHPMailer.
     */
    protected function createPhpMailer(): PHPMailer
    {
        return new MotorchkMailMessage(true);
    }

    private function appendBcc(PHPMailer $mail, string $raw, string $recipient): void
    {
        $skip = strtolower(trim($recipient));
        foreach (self::parseBccList($raw) as $address) {
            if ($skip !== '' && strtolower($address) === $skip) {
                continue;
            }
            $mail->addBCC($address);
        }
    }

    private static function isEmailAddress(string $value): bool
    {
        return !preg_match('/[\r\n,;]/', $value) && PHPMailer::validateAddress($value);
    }
}
