<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\PlatformSettingRepository;

/**
 * The deployment's own settings (§21).
 *
 * One value per installation, changed from the admin panel rather than by a
 * deploy. Everything here is safe to read on a screen — the payment gateway's
 * NAME is a setting, its secret key is not and stays in the environment.
 *
 * Each setting declares its shape below, and only declared keys can be written.
 * A whitelist rather than free key/value: an admin panel that can write any key
 * it likes is one typo away from a setting the code reads under a slightly
 * different name and never finds.
 */
final class PlatformSettings
{
    /**
     * key => [label, type, choices|null, default, help]
     *
     * @var array<string, array{0:string,1:string,2:?list<string>,3:string,4:string}>
     */
    public const DEFINED = [
        'platform_name' => [
            'Platform name', 'string', null, 'MediFlow',
            'Shown in the apps and on documents.',
        ],
        'support_email' => [
            'Support email', 'email', null, '',
            'Where clinics are told to write when something is wrong.',
        ],
        'signup_open' => [
            'Clinics may sign themselves up', 'bool', null, 'true',
            'Turn off to make new clinics invitation-only.',
        ],
        'default_country' => [
            'Default country for new clinics', 'country', null, 'PK',
            'Decides their currency, tax rate and date format until they change it.',
        ],
        'payment_gateway' => [
            'Payment gateway', 'choice', ['', 'paypal', 'stub'], '',
            'Empty means no online payment: patients pay at the clinic. '
            . '"stub" approves everything and is for development only.',
        ],
        'payment_mode' => [
            'Payment mode', 'choice', ['sandbox', 'live'], 'sandbox',
            'Live takes real money. Sandbox does not.',
        ],
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /**
     * One setting, falling back to the environment and then to its default.
     *
     * The environment is checked before the default so a deployment can still
     * pin a value the panel has never been used to set — which is how every
     * install behaves until an administrator opens the screen for the first
     * time.
     */
    public static function get(string $key): string
    {
        $definition = self::DEFINED[$key] ?? null;
        if ($definition === null) {
            return '';
        }

        [, $type, $choices, $default] = $definition;

        $stored = self::all()[$key] ?? null;

        if ($stored !== null) {
            // Empty is a real answer for a setting whose list offers it —
            // "— none —" on the gateway is how an admin turns online payment
            // off, and treating that as "unset" sent it straight back to
            // whatever the environment said. For everything else an empty box
            // means "I did not choose", so the default still applies.
            $emptyIsAChoice = $type === 'choice' && $choices !== null && in_array('', $choices, true);

            if ($stored !== '' || $emptyIsAChoice) {
                return $stored;
            }
        }

        $fromEnv = (string) env(strtoupper($key), '');

        return $fromEnv !== '' ? $fromEnv : $default;
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,string> everything stored, keyed by setting */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // The repository answers with an empty map before migration 012 has
        // run, so callers get env and defaults — exactly how the app behaved
        // before this table existed.
        return self::$cache = (new PlatformSettingRepository())->all();
    }

    /**
     * Everything the settings screen needs: the value, and what it is allowed
     * to be. Sent together so the panel never has to hardcode the choices.
     *
     * @return list<array<string,mixed>>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::DEFINED as $key => [$label, $type, $choices, $default, $help]) {
            $out[] = [
                'key'     => $key,
                'label'   => $label,
                'type'    => $type,
                'choices' => $choices,
                'value'   => self::get($key),
                'default' => $default,
                'help'    => $help,
            ];
        }
        return $out;
    }

    /**
     * Write the settings the panel sent. Unknown keys are ignored rather than
     * rejected, so a newer panel talking to an older API degrades to saving
     * what this version understands.
     *
     * @param array<string,mixed> $values
     * @return list<string> the keys actually written
     */
    public static function put(array $values, ?int $actorId): array
    {
        $written = [];

        foreach ($values as $key => $value) {
            if (!isset(self::DEFINED[$key])) {
                continue;
            }

            $value = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);

            [, $type, $choices] = self::DEFINED[$key];
            if ($type === 'choice' && $choices !== null && !in_array($value, $choices, true)) {
                continue;
            }

            (new PlatformSettingRepository())->put($key, $value, $actorId);
            $written[] = $key;
        }

        self::$cache = null;

        return $written;
    }

    /** Used by tests, and after a write inside the same request. */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
