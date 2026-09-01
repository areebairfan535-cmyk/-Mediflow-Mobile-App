<?php
declare(strict_types=1);

/**
 * Send one test email and say exactly what happened (§20).
 *
 *   php database/mail-test.php you@gmail.com
 *
 * The worker deliberately swallows delivery failures — a notification must
 * never break the thing it is describing — which makes "why did nothing
 * arrive?" hard to answer. This does the opposite: one message, one address,
 * and the SMTP error printed in full.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Services\Notifications\SmtpChannel;

$to = $argv[1] ?? '';

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php database/mail-test.php you@example.com\n");
    exit(1);
}

$channel = new SmtpChannel();

if (!$channel->isConfigured()) {
    fwrite(STDERR,
        "Email is not configured.\n\n"
        . "Set these in backend/.env, then run this again:\n"
        . "  MAIL_HOST=smtp.gmail.com\n"
        . "  MAIL_PORT=587\n"
        . "  MAIL_USERNAME=you@gmail.com\n"
        . "  MAIL_PASSWORD=<16-character app password, not your Gmail password>\n"
        . "  MAIL_FROM=you@gmail.com\n"
        . "  MAIL_FROM_NAME=MediFlow\n"
    );
    exit(1);
}

echo "Sending to $to via " . env('MAIL_HOST') . ':' . env('MAIL_PORT', '587') . " ...\n";

try {
    $result = $channel->send([
        'to_address' => $to,
        'title'      => 'MediFlow test message',
        'body'       => "This is a test from MediFlow.\n\n"
                      . "If you are reading it, password reset codes and appointment "
                      . "reminders will reach this address too.",
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, "\nFAILED: " . $e->getMessage() . "\n\n");

    // The three that account for almost every failure against Gmail.
    $hint = match (true) {
        str_contains($e->getMessage(), '535')        => 'Gmail rejected the credentials. MAIL_PASSWORD must be a 16-character App password (Google Account -> Security -> App passwords), not the account password, and 2-Step Verification has to be on for that menu to exist.',
        str_contains($e->getMessage(), 'STARTTLS')   => 'TLS could not start. Check MAIL_PORT=587 — port 465 is implicit TLS and is not supported here.',
        str_contains($e->getMessage(), 'connect')    => 'Could not reach the server. A firewall or your ISP may be blocking outbound port 587.',
        default                                      => null,
    };

    if ($hint !== null) {
        fwrite(STDERR, "  $hint\n");
    }
    exit(1);
}

echo match ($result) {
    SmtpChannel::SENT    => "SENT. Check the inbox (and the spam folder the first time).\n",
    SmtpChannel::SKIPPED => "SKIPPED — the address was refused before sending.\n",
    default              => "Result: $result\n",
};
