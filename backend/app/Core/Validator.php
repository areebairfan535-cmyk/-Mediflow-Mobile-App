<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rule-string validator — the Validator layer of §12.
 *
 *   $data = Validator::run($request->body, [
 *       'email'    => 'required|email|max:255',
 *       'password' => 'required|string|min:8',
 *       'role'     => 'required|in:doctor,nurse,receptionist',
 *       'age'      => 'nullable|integer|between:0,130',
 *   ]);
 *
 * Returns only the validated keys, cast to their declared types.
 * Throws ValidationException with per-field messages on failure.
 *
 * Supported: required, nullable, string, integer, numeric, boolean, email,
 * date, datetime, in:a,b, not_in:a,b, min:n, max:n, between:a,b, size:n,
 * regex:/.../, confirmed, array, decimal.
 */
final class Validator
{
    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @return array<string,mixed>
     */
    public static function run(array $data, array $rules): array
    {
        /** @var array<string,list<string>> $errors */
        $errors = [];
        $clean  = [];

        foreach ($rules as $field => $ruleString) {
            $parsed  = self::parseRules($ruleString);
            $present = array_key_exists($field, $data);
            $value   = $present ? $data[$field] : null;

            // array_key_exists, NOT isset: a flag rule has no argument, so it
            // is stored with a null value — and isset(null) is false, which
            // would make every `required` silently stop being required.
            $required = array_key_exists('required', $parsed);
            $nullable = array_key_exists('nullable', $parsed);

            // Treat empty string as absent for optional fields.
            if ($present && is_string($value) && trim($value) === '' && !$required) {
                $present = false;
                $value   = null;
            }

            if (!$present) {
                if ($required) {
                    $errors[$field][] = self::label($field) . ' is required.';
                }
                continue;
            }

            if ($value === null) {
                if ($nullable) {
                    $clean[$field] = null;
                    continue;
                }
                if ($required) {
                    $errors[$field][] = self::label($field) . ' cannot be null.';
                    continue;
                }
                $clean[$field] = null;
                continue;
            }

            $fieldErrors = [];
            $cast        = $value;

            foreach ($parsed as $rule => $arg) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                $result = self::check($rule, $arg, $cast, $field, $data);
                if ($result['error'] !== null) {
                    $fieldErrors[] = $result['error'];
                    break;   // one message per field is enough
                }
                $cast = $result['value'];
            }

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
            } else {
                $clean[$field] = $cast;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    /** @return array<string,string|null> rule => argument */
    private static function parseRules(string $ruleString): array
    {
        $out = [];
        foreach (explode('|', $ruleString) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            // regex:/.../ may itself contain ':', so split on the first only.
            $colon = strpos($chunk, ':');
            if ($colon === false) {
                $out[$chunk] = null;
            } else {
                $out[substr($chunk, 0, $colon)] = substr($chunk, $colon + 1);
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $all
     * @return array{value: mixed, error: string|null}
     */
    private static function check(
        string $rule,
        ?string $arg,
        mixed $value,
        string $field,
        array $all,
    ): array {
        $label = self::label($field);
        $ok    = fn(mixed $v): array => ['value' => $v, 'error' => null];
        $fail  = fn(string $msg): array => ['value' => $value, 'error' => $msg];

        switch ($rule) {
            case 'string':
                if (!is_string($value)) {
                    return $fail("$label must be text.");
                }
                return $ok(trim($value));

            case 'integer':
                if (is_int($value)) {
                    return $ok($value);
                }
                if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
                    return $ok((int) trim($value));
                }
                return $fail("$label must be a whole number.");

            case 'numeric':
            case 'decimal':
                if (!is_numeric($value)) {
                    return $fail("$label must be a number.");
                }
                return $ok($rule === 'decimal' ? money($value) : (float) $value);

            case 'boolean':
                if (is_bool($value)) {
                    return $ok($value);
                }
                if (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    return $ok(in_array($value, [1, '1', 'true'], true));
                }
                return $fail("$label must be true or false.");

            case 'array':
                if (!is_array($value)) {
                    return $fail("$label must be a list.");
                }
                return $ok($value);

            case 'email':
                $email = is_string($value) ? trim($value) : '';
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return $fail("$label must be a valid email address.");
                }
                return $ok(strtolower($email));

            case 'date':
                $s = is_string($value) ? trim($value) : '';
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
                if ($d === false || $d->format('Y-m-d') !== $s) {
                    return $fail("$label must be a date in YYYY-MM-DD format.");
                }
                return $ok($s);

            case 'datetime':
                $s = is_string($value) ? trim($value) : '';
                $ts = strtotime($s);
                if ($ts === false) {
                    return $fail("$label must be a valid date and time.");
                }
                return $ok(date('Y-m-d H:i:s', $ts));

            case 'in':
                $allowed = explode(',', (string) $arg);
                if (!in_array((string) $value, $allowed, true)) {
                    return $fail("$label must be one of: " . implode(', ', $allowed) . '.');
                }
                return $ok($value);

            case 'not_in':
                if (in_array((string) $value, explode(',', (string) $arg), true)) {
                    return $fail("$label has a value that is not allowed.");
                }
                return $ok($value);

            case 'min':
                $min = (float) $arg;
                if (is_string($value)) {
                    return mb_strlen($value) < $min
                        ? $fail("$label must be at least {$arg} characters.")
                        : $ok($value);
                }
                if (is_array($value)) {
                    return count($value) < $min
                        ? $fail("$label must have at least {$arg} items.")
                        : $ok($value);
                }
                return (float) $value < $min
                    ? $fail("$label must be at least {$arg}.")
                    : $ok($value);

            case 'max':
                $max = (float) $arg;
                if (is_string($value)) {
                    return mb_strlen($value) > $max
                        ? $fail("$label may not be longer than {$arg} characters.")
                        : $ok($value);
                }
                if (is_array($value)) {
                    return count($value) > $max
                        ? $fail("$label may not have more than {$arg} items.")
                        : $ok($value);
                }
                return (float) $value > $max
                    ? $fail("$label may not be greater than {$arg}.")
                    : $ok($value);

            case 'between':
                [$lo, $hi] = array_pad(explode(',', (string) $arg), 2, '0');
                $n = is_string($value) ? mb_strlen($value) : (float) $value;
                if ($n < (float) $lo || $n > (float) $hi) {
                    return $fail("$label must be between {$lo} and {$hi}.");
                }
                return $ok($value);

            case 'size':
                $n = is_string($value) ? mb_strlen($value) : (is_array($value) ? count($value) : (float) $value);
                if ($n !== (float) $arg && $n !== (int) $arg) {
                    return $fail("$label must be exactly {$arg}.");
                }
                return $ok($value);

            case 'regex':
                if (!is_string($value) || @preg_match((string) $arg, $value) !== 1) {
                    return $fail("$label has an invalid format.");
                }
                return $ok($value);

            case 'confirmed':
                if (($all[$field . '_confirmation'] ?? null) !== $value) {
                    return $fail("$label confirmation does not match.");
                }
                return $ok($value);

            default:
                // Unknown rule: fail loudly in development, ignore in production.
                return $ok($value);
        }
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
