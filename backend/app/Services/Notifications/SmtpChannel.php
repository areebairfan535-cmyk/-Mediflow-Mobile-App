<?php
declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Email over SMTP, written by hand because §24 rules out Composer and PHP's
 * own mail() gives no way to authenticate to a modern provider.
 *
 * Deliberately plain: no attachments, no HTML alternative. A clinic
 * notification is two lines and a document number.
 */
final class SmtpChannel implements Channel
{
    private ?string $host;
    private int $port;
    private ?string $username;
    private ?string $password;
    private string $from;
    private string $fromName;
    private int $timeout;

    public function __construct()
    {
        $this->host     = env('MAIL_HOST');
        $this->port     = (int) (env('MAIL_PORT', '587') ?? 587);
        $this->username = env('MAIL_USERNAME');

        // Google shows an app password as four groups of four ("abcd efgh ijkl
        // mnop") and people paste it that way. The spaces are presentation, not
        // part of the secret, and leaving them in fails AUTH with a message
        // that says nothing about spaces.
        $password       = env('MAIL_PASSWORD');
        $this->password = $password === null
            ? null
            : (preg_replace('/\s+/', '', $password) ?? $password);
        $this->from     = (string) (env('MAIL_FROM', 'no-reply@mediflow.local'));
        $this->fromName = (string) (env('MAIL_FROM_NAME', env('APP_NAME', 'MediFlow')));
        $this->timeout  = (int) (env('MAIL_TIMEOUT', '15') ?? 15);
    }

    public function isConfigured(): bool
    {
        return $this->host !== null && $this->host !== '';
    }

    public function name(): string
    {
        return 'email';
    }

    public function send(array $notification): string
    {
        if (!$this->isConfigured()) {
            return self::SKIPPED;
        }

        $to = (string) ($notification['to_address'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            // A bad address is not a transport failure; retrying cannot fix it.
            return self::SKIPPED;
        }

        $socket = @stream_socket_client(
            sprintf('%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->timeout,
        );

        if ($socket === false) {
            throw new \RuntimeException("SMTP connect failed: $errstr ($errno)");
        }

        try {
            $this->expect($socket, '220');

            $this->command($socket, 'EHLO ' . $this->hostname(), '250');

            // STARTTLS on the submission port; 465 is implicit TLS and would
            // need a different scheme, so it is not offered here.
            if ($this->port === 587) {
                $this->command($socket, 'STARTTLS', '220');
                if (!@stream_socket_enable_crypto(
                    $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                )) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $this->command($socket, 'EHLO ' . $this->hostname(), '250');
            }

            if ($this->username !== null && $this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', '334');
                $this->command($socket, base64_encode($this->username), '334');
                $this->command($socket, base64_encode((string) $this->password), '235');
            }

            $this->command($socket, 'MAIL FROM:<' . $this->from . '>', '250');
            $this->command($socket, 'RCPT TO:<' . $to . '>', '250');
            $this->command($socket, 'DATA', '354');

            fwrite($socket, $this->message($to, $notification) . "\r\n.\r\n");
            $this->expect($socket, '250');

            $this->command($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }

        return self::SENT;
    }

    /** @param array<string,mixed> $notification */
    private function message(string $to, array $notification): string
    {
        $subject = (string) ($notification['title'] ?? 'Notification');
        $body    = (string) ($notification['body'] ?? '');

        $headers = [
            'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            // Never let a clinical reminder be answered into a black hole.
            'Auto-Submitted: auto-generated',
        ];

        // A line starting with '.' would end the DATA block early.
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private function hostname(): string
    {
        $url = (string) env('APP_URL', 'localhost');
        return parse_url($url, PHP_URL_HOST) ?: 'localhost';
    }

    /** @param resource $socket */
    private function command($socket, string $line, string $expect): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expect);
    }

    /** @param resource $socket */
    private function expect($socket, string $code): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // Multi-line replies mark every line but the last with '-'.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if (!str_starts_with(trim($response), $code)) {
            throw new \RuntimeException("SMTP expected $code, got: " . trim($response));
        }
    }
}
