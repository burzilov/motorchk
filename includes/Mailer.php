<?php

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class Mailer
{
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
     * @param array{host?: string, port?: int, encryption?: string, username?: string, password?: string, from?: string, from_name?: string, to?: string, bcc?: string}|null $override
     * @param string|null $replyTo заголовок Reply-To (например, email автора заявки)
     */
    public function send(string $subject, string $body, ?string $to = null, ?array $override = null, ?string $replyTo = null): void
    {
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
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;

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
        return new PHPMailer(true);
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
