<?php
declare(strict_types=1);

namespace App\Services\Documents;

use DateTimeImmutable;
use DateTimeZone;

/**
 * How one market writes things down (§23).
 *
 * The countries table has carried date_format, timezone and currency_symbol
 * since the first migration, and OrganizationRepository::settings() resolves
 * them per clinic. Nothing read them: every document printed `d M Y` and
 * "UTC" regardless of market, so an American clinic's invoice said
 * "09 Sep 2026" where it should say "09/09/2026", and a Karachi clinic's
 * footer gave a timestamp five hours off the wall clock its staff were
 * looking at.
 *
 * A configurable field nothing reads is not configuration. This is the thing
 * that reads it.
 *
 * Built from a clinic's resolved settings, so a clinic's own override wins
 * over its market's default — which is what COALESCE in that query is for.
 */
final class Locale
{
    private function __construct(
        private readonly string $dateFormat,
        private readonly DateTimeZone $timezone,
        private readonly string $currencySymbol,
    ) {
    }

    /**
     * @param array<string,mixed> $clinic resolved organization settings
     */
    public static function forClinic(array $clinic): self
    {
        $format = trim((string) ($clinic['date_format'] ?? ''));

        // A market with no date format set falls back to the unambiguous one.
        // "d M Y" cannot be misread the way 09/10/2026 can — that is October
        // in London and September in New York, and an invoice date is not a
        // thing to be vague about.
        if ($format === '') {
            $format = 'd M Y';
        }

        $zone = trim((string) ($clinic['timezone'] ?? ''));
        try {
            $timezone = new DateTimeZone($zone === '' ? 'UTC' : $zone);
        } catch (\Throwable) {
            // A bad timezone in the database must not stop an invoice printing.
            $timezone = new DateTimeZone('UTC');
        }

        $symbol = trim((string) ($clinic['currency_symbol'] ?? ''));
        if ($symbol === '') {
            $symbol = strtoupper(trim((string) ($clinic['currency_code'] ?? '')));
        }

        return new self($format, $timezone, $symbol);
    }

    /** A date, written the way this market writes dates. */
    public function date(mixed $value): string
    {
        $moment = $this->moment($value);

        return $moment === null ? '—' : $moment->format($this->dateFormat);
    }

    /**
     * A date and time, in the clinic's own timezone.
     *
     * Stored timestamps are UTC. Printing them as UTC is correct and useless:
     * the person reading the page is standing in the clinic, and the question
     * they are answering is "was this before or after I left yesterday".
     */
    public function dateTime(mixed $value): string
    {
        $moment = $this->moment($value);

        return $moment === null ? '—' : $moment->format($this->dateFormat . ' H:i');
    }

    /** The abbreviation to print next to a local time, e.g. PKT. */
    public function timezoneLabel(): string
    {
        return (new DateTimeImmutable('now', $this->timezone))->format('T');
    }

    /**
     * An amount with its currency mark.
     *
     * The separators are a period and a comma, which is right for all four
     * markets this ships with (PK, US, GB, AE). A market that groups
     * differently — most of continental Europe — needs them added to the
     * countries table rather than guessed at here.
     */
    public function money(mixed $value): string
    {
        return trim($this->currencySymbol . ' ' . $this->amount($value));
    }

    /** The number alone, for a column that already has a currency heading. */
    public function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }

    public function currencySymbol(): string
    {
        return $this->currencySymbol;
    }

    private function moment(mixed $value): ?DateTimeImmutable
    {
        if (empty($value)) {
            return null;
        }

        try {
            // Stored values are UTC; read them as such before converting, or a
            // server in another zone would shift every date by its own offset.
            return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
                ->setTimezone($this->timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
