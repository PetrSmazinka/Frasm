<?php

declare(strict_types=1);

namespace Core\Validation;

use Closure;
use Core\DB\DB;
use Core\Exceptions\CoreException;
use Core\Exceptions\ValidationException;
use Core\I18n\Lang;
use DateTimeImmutable;

/**
 * @file Validator.php
 * @brief Declarative input validation for forms and API payloads.
 */

/**
 * @class Validator
 * @brief Validates an input array against per-field rule sets and produces field error messages.
 *
 * Usage:
 * @code
 * $data = Validator::make($request->input(), [
 *     'email' => 'required|email|max:255|unique:users,email',
 *     'temp'  => ['required', 'numeric', 'between:-40,125'],
 *     'code'  => ['required', 'regex:/^[A-Z]{2}|[0-9]{4}$/'],   // arrays allow '|' inside rules
 *     'name'  => ['required', fn($value) => $value === 'root' ? 'Reserved name.' : null],
 * ])->validate();
 * @endcode
 *
 * Semantics:
 *  - Fields are addressed with dot notation for nested input ('sensor.temp').
 *  - Empty values (null, '', []) only run the implicit rules (required, required_if, required_with, accepted);
 *    all other rules are skipped, so optional fields need no extra marker. `nullable` is accepted for readability.
 *  - `sometimes` skips the field entirely when it is absent from the input.
 *  - Validation of a field stops at its first failing rule (one message per field).
 *  - Closure rules receive ($value, $field, $data) and return an error message or null.
 *  - Messages resolve from: custom messages ('field.rule' or 'rule') → Lang 'validation.<rule>' → built-in English.
 */
class Validator
{
    /**
     * @var list<string> Rules evaluated even when the value is empty.
     */
    protected const IMPLICIT_RULES = ['required', 'required_if', 'required_with', 'accepted'];

    /**
     * @var list<string> Rules that switch size rules to numeric comparison.
     */
    protected const NUMERIC_RULES = ['numeric', 'integer'];

    /**
     * @var array<string, string|array<string, string>> Built-in English messages.
     */
    protected const DEFAULT_MESSAGES = [
        'required'      => 'The :attribute field is required.',
        'required_if'   => 'The :attribute field is required when :other is :value.',
        'required_with' => 'The :attribute field is required when :other is present.',
        'accepted'      => 'The :attribute must be accepted.',
        'string'        => 'The :attribute must be a string.',
        'integer'       => 'The :attribute must be an integer.',
        'numeric'       => 'The :attribute must be a number.',
        'boolean'       => 'The :attribute field must be true or false.',
        'array'         => 'The :attribute must be an array.',
        'email'         => 'The :attribute must be a valid email address.',
        'url'           => 'The :attribute must be a valid URL.',
        'ip'            => 'The :attribute must be a valid IP address.',
        'ipv4'          => 'The :attribute must be a valid IPv4 address.',
        'ipv6'          => 'The :attribute must be a valid IPv6 address.',
        'uuid'          => 'The :attribute must be a valid UUID.',
        'alpha'         => 'The :attribute may only contain letters.',
        'alpha_num'     => 'The :attribute may only contain letters and numbers.',
        'alpha_dash'    => 'The :attribute may only contain letters, numbers, dashes and underscores.',
        'regex'         => 'The :attribute format is invalid.',
        'not_regex'     => 'The :attribute format is invalid.',
        'in'            => 'The selected :attribute is invalid.',
        'not_in'        => 'The selected :attribute is invalid.',
        'digits'        => 'The :attribute must be :digits digits.',
        'date'          => 'The :attribute is not a valid date.',
        'date_format'   => 'The :attribute does not match the format :format.',
        'before'        => 'The :attribute must be a date before :date.',
        'after'         => 'The :attribute must be a date after :date.',
        'same'          => 'The :attribute and :other must match.',
        'different'     => 'The :attribute and :other must be different.',
        'confirmed'     => 'The :attribute confirmation does not match.',
        'unique'        => 'The :attribute has already been taken.',
        'exists'        => 'The selected :attribute is invalid.',
        'min'           => [
            'numeric' => 'The :attribute must be at least :min.',
            'string'  => 'The :attribute must be at least :min characters.',
            'array'   => 'The :attribute must have at least :min items.',
        ],
        'max'           => [
            'numeric' => 'The :attribute may not be greater than :max.',
            'string'  => 'The :attribute may not be greater than :max characters.',
            'array'   => 'The :attribute may not have more than :max items.',
        ],
        'between'       => [
            'numeric' => 'The :attribute must be between :min and :max.',
            'string'  => 'The :attribute must be between :min and :max characters.',
            'array'   => 'The :attribute must have between :min and :max items.',
        ],
        'size'          => [
            'numeric' => 'The :attribute must be :size.',
            'string'  => 'The :attribute must be :size characters.',
            'array'   => 'The :attribute must contain :size items.',
        ],
    ];

    /**
     * @var array<string, list<string>> Collected error messages.
     */
    protected array $errors = [];

    /**
     * @var bool Whether validation has already run.
     */
    protected bool $validated = false;

    /**
     * @brief Validator constructor.
     *
     * @param array<string, mixed> $data Input data.
     * @param array<string, string|list<string|Closure>> $rules Rules keyed by field (dot notation).
     * @param array<string, string> $messages Custom messages keyed by 'field.rule' or 'rule'.
     * @param array<string, string> $attributes Human readable field names keyed by field.
     */
    public function __construct(
        protected array $data,
        protected array $rules,
        protected array $messages = [],
        protected array $attributes = []
    ) {
    }

    /**
     * @brief Creates a validator instance.
     *
     * @param array<string, mixed> $data Input data.
     * @param array<string, string|list<string|Closure>> $rules Rules keyed by field.
     * @param array<string, string> $messages Custom messages.
     * @param array<string, string> $attributes Human readable field names.
     * @return static
     */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): static
    {
        return new static($data, $rules, $messages, $attributes);
    }

    /**
     * @brief Runs validation and returns whether all rules passed.
     *
     * @return bool
     * @throws CoreException On malformed rule definitions.
     */
    public function passes(): bool
    {
        if (!$this->validated) {
            $this->run();
            $this->validated = true;
        }

        return $this->errors === [];
    }

    /**
     * @brief Runs validation and returns whether any rule failed.
     *
     * @return bool
     * @throws CoreException On malformed rule definitions.
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @brief Returns collected error messages.
     *
     * @return array<string, list<string>> Field => messages.
     */
    public function errors(): array
    {
        $this->passes();
        return $this->errors;
    }

    /**
     * @brief Validates and returns only the fields covered by rules.
     *
     * @return array<string, mixed> Validated data (nested structure preserved for dot-notated fields).
     * @throws ValidationException If validation fails.
     * @throws CoreException On malformed rule definitions.
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        $validated = [];
        foreach (array_keys($this->rules) as $field) {
            if ($this->hasValue($field)) {
                $this->setValue($validated, $field, $this->getValue($field));
            }
        }

        return $validated;
    }

    /**
     * @brief Evaluates all fields.
     *
     * @return void
     * @throws CoreException On malformed rule definitions.
     */
    protected function run(): void
    {
        $this->errors = [];

        foreach ($this->rules as $field => $fieldRules) {
            $field = (string)$field;
            $ruleList = $this->parseRules($fieldRules);
            $ruleNames = array_map(fn(array $rule): string => $rule[0], array_filter($ruleList, fn(array $rule): bool => is_string($rule[0])));

            if (in_array('sometimes', $ruleNames, true) && !$this->hasValue($field)) {
                continue;
            }

            $value = $this->getValue($field);
            $isEmpty = $this->isEmpty($value);
            $numeric = array_intersect($ruleNames, self::NUMERIC_RULES) !== [];

            foreach ($ruleList as [$rule, $parameters]) {
                if ($rule instanceof Closure) {
                    if ($isEmpty) {
                        continue;
                    }
                    $message = $rule($value, $field, $this->data);
                    if (is_string($message) && $message !== '') {
                        $this->errors[$field][] = $message;
                        break;
                    }
                    continue;
                }

                if ($rule === 'nullable' || $rule === 'sometimes' || $rule === 'bail') {
                    continue;
                }

                if ($isEmpty && !in_array($rule, self::IMPLICIT_RULES, true)) {
                    continue;
                }

                $method = 'validate' . str_replace('_', '', ucwords($rule, '_'));
                if (!method_exists($this, $method)) {
                    throw new CoreException("Unknown validation rule '{$rule}' for field '{$field}'.");
                }

                if (!$this->{$method}($field, $value, $parameters, $numeric)) {
                    $this->errors[$field][] = $this->message($field, $rule, $parameters, $value, $numeric);
                    break;
                }
            }
        }
    }

    /**
     * @brief Normalizes a rule definition into [name|Closure, parameters] pairs.
     *
     * @param mixed $fieldRules Pipe-delimited string or list of rules.
     * @return list<array{0: string|Closure, 1: list<string>}>
     * @throws CoreException On invalid definitions.
     */
    protected function parseRules(mixed $fieldRules): array
    {
        $items = is_string($fieldRules) ? explode('|', $fieldRules) : (array)$fieldRules;
        $parsed = [];

        foreach ($items as $item) {
            if ($item instanceof Closure) {
                $parsed[] = [$item, []];
                continue;
            }

            if (!is_string($item) || trim($item) === '') {
                throw new CoreException('Validation rules must be non-empty strings or closures.');
            }

            [$name, $parameterString] = str_contains($item, ':') ? explode(':', $item, 2) : [$item, null];
            $name = strtolower(trim($name));

            if ($parameterString === null) {
                $parameters = [];
            } elseif (in_array($name, ['regex', 'not_regex', 'date_format'], true)) {
                $parameters = [$parameterString];
            } else {
                $parameters = array_map('trim', explode(',', $parameterString));
            }

            $parsed[] = [$name, $parameters];
        }

        return $parsed;
    }

    /**
     * @brief Checks whether a value counts as empty.
     *
     * @param mixed $value Value.
     * @return bool
     */
    protected function isEmpty(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);
    }

    /**
     * @brief Reads a value using dot notation (exact keys take precedence).
     *
     * @param string $field Field name.
     * @return mixed
     */
    protected function getValue(string $field): mixed
    {
        if (array_key_exists($field, $this->data)) {
            return $this->data[$field];
        }

        $current = $this->data;
        foreach (explode('.', $field) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @brief Checks whether a field is present in the input (even when empty).
     *
     * @param string $field Field name.
     * @return bool
     */
    protected function hasValue(string $field): bool
    {
        if (array_key_exists($field, $this->data)) {
            return true;
        }

        $current = $this->data;
        foreach (explode('.', $field) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @brief Writes a value into a nested array using dot notation.
     *
     * @param array<string, mixed> $target Target array.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @return void
     */
    protected function setValue(array &$target, string $field, mixed $value): void
    {
        if (array_key_exists($field, $this->data)) {
            $target[$field] = $value;
            return;
        }

        $current = &$target;
        foreach (explode('.', $field) as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }
        $current = $value;
    }

    /**
     * @brief Builds the error message for a failed rule.
     *
     * @param string $field Field name.
     * @param string $rule Rule name.
     * @param list<string> $parameters Rule parameters.
     * @param mixed $value Field value.
     * @param bool $numeric Whether size rules compare numerically.
     * @return string
     */
    protected function message(string $field, string $rule, array $parameters, mixed $value, bool $numeric): string
    {
        $sizeType = $this->sizeType($value, $numeric);
        $template = $this->messages["{$field}.{$rule}"]
            ?? $this->messages[$rule]
            ?? $this->translate("validation.{$rule}.{$sizeType}")
            ?? $this->translate("validation.{$rule}");

        if ($template === null) {
            $default = self::DEFAULT_MESSAGES[$rule] ?? 'The :attribute field is invalid.';
            $template = is_array($default) ? $default[$sizeType] : $default;
        }

        $replacements = [':attribute' => $this->attributeName($field)];

        switch ($rule) {
            case 'min':
            case 'max':
            case 'size':
            case 'digits':
                $replacements[':' . $rule] = $parameters[0] ?? '';
                break;
            case 'between':
                $replacements[':min'] = $parameters[0] ?? '';
                $replacements[':max'] = $parameters[1] ?? '';
                break;
            case 'in':
            case 'not_in':
                $replacements[':values'] = implode(', ', $parameters);
                break;
            case 'same':
            case 'different':
            case 'required_with':
                $replacements[':other'] = $this->attributeName($parameters[0] ?? '');
                break;
            case 'required_if':
                $replacements[':other'] = $this->attributeName($parameters[0] ?? '');
                $replacements[':value'] = $parameters[1] ?? '';
                break;
            case 'date_format':
                $replacements[':format'] = $parameters[0] ?? '';
                break;
            case 'before':
            case 'after':
                $replacements[':date'] = isset($parameters[0]) && $this->hasValue($parameters[0])
                    ? $this->attributeName($parameters[0])
                    : ($parameters[0] ?? '');
                break;
        }

        // Longest placeholders first so ':attribute' is not broken by shorter keys
        uksort($replacements, fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return strtr($template, $replacements);
    }

    /**
     * @brief Looks up a translation, returning null when it does not exist.
     *
     * @param string $key Translation key.
     * @return string|null
     */
    protected function translate(string $key): ?string
    {
        $line = Lang::get($key);
        return $line === $key ? null : $line;
    }

    /**
     * @brief Resolves the display name of a field.
     *
     * @param string $field Field name.
     * @return string
     */
    protected function attributeName(string $field): string
    {
        return $this->attributes[$field]
            ?? $this->translate("validation.attributes.{$field}")
            ?? str_replace(['_', '.'], ' ', $field);
    }

    /**
     * @brief Determines how size rules measure a value.
     *
     * @param mixed $value Field value.
     * @param bool $numeric Whether a numeric/integer rule is present.
     * @return string 'numeric', 'array' or 'string'.
     */
    protected function sizeType(mixed $value, bool $numeric): string
    {
        if (is_array($value)) {
            return 'array';
        }

        if (is_int($value) || is_float($value) || ($numeric && is_numeric($value))) {
            return 'numeric';
        }

        return 'string';
    }

    /**
     * @brief Measures a value for size rules.
     *
     * @param mixed $value Field value.
     * @param bool $numeric Whether a numeric/integer rule is present.
     * @return float|int|null Size, or null when the value cannot be measured.
     */
    protected function sizeOf(mixed $value, bool $numeric): float|int|null
    {
        return match ($this->sizeType($value, $numeric)) {
            'array' => count($value),
            'numeric' => is_numeric($value) ? $value + 0 : null,
            default => is_scalar($value) ? mb_strlen((string)$value) : null,
        };
    }

    /**
     * @brief Parses a numeric rule parameter.
     *
     * @param list<string> $parameters Rule parameters.
     * @param int $index Parameter index.
     * @param string $rule Rule name (for error messages).
     * @return float|int
     * @throws CoreException If the parameter is missing or not numeric.
     */
    protected function numericParameter(array $parameters, int $index, string $rule): float|int
    {
        $parameter = $parameters[$index] ?? null;
        if ($parameter === null || !is_numeric($parameter)) {
            throw new CoreException("Validation rule '{$rule}' requires numeric parameter #" . ($index + 1) . '.');
        }

        return $parameter + 0;
    }

    /**
     * @brief Validates an SQL identifier used by unique/exists rules.
     *
     * @param string|null $identifier Table or column name.
     * @param string $rule Rule name.
     * @return string Quoted identifier.
     * @throws CoreException If the identifier is not a plain name.
     */
    protected function sqlIdentifier(?string $identifier, string $rule): string
    {
        if ($identifier === null || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier)) {
            throw new CoreException("Validation rule '{$rule}' has an invalid table/column name.");
        }

        return '`' . $identifier . '`';
    }

    /**
     * @brief Converts a value or a referenced field into a timestamp.
     *
     * @param string $reference Field name or date string.
     * @return int|null
     */
    protected function timestampOf(string $reference): ?int
    {
        $value = $this->hasValue($reference) ? $this->getValue($reference) : $reference;
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @brief Rule 'required': value must not be empty.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateRequired(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return !$this->isEmpty($value);
    }

    /**
     * @brief Rule 'required_if:other,value': required when another field equals the value.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateRequiredIf(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        $other = $this->getValue($parameters[0] ?? '');
        $expected = array_slice($parameters, 1);

        if (is_scalar($other) && in_array((string)(is_bool($other) ? (int)$other : $other), $expected, true)) {
            return !$this->isEmpty($value);
        }

        return true;
    }

    /**
     * @brief Rule 'required_with:other': required when another field is present and not empty.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateRequiredWith(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        foreach ($parameters as $other) {
            if (!$this->isEmpty($this->getValue($other))) {
                return !$this->isEmpty($value);
            }
        }

        return true;
    }

    /**
     * @brief Rule 'accepted': yes/on/1/true (checkbox consent).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateAccepted(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return in_array($value, ['yes', 'on', '1', 1, true, 'true'], true);
    }

    /**
     * @brief Rule 'string'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateString(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_string($value);
    }

    /**
     * @brief Rule 'integer' (native int or integer string).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateInteger(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false);
    }

    /**
     * @brief Rule 'numeric' (int, float or numeric string).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateNumeric(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value);
    }

    /**
     * @brief Rule 'boolean' (true, false, 1, 0, '1', '0', 'true', 'false').
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateBoolean(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true);
    }

    /**
     * @brief Rule 'array'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateArray(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_array($value);
    }

    /**
     * @brief Rule 'email'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateEmail(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false;
    }

    /**
     * @brief Rule 'url' (http/https only, prevents javascript: and similar schemes).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateUrl(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * @brief Rule 'ip'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateIp(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @brief Rule 'ipv4'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateIpv4(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * @brief Rule 'ipv6'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateIpv6(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * @brief Rule 'uuid'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateUuid(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    /**
     * @brief Rule 'alpha' (Unicode letters).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateAlpha(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_string($value) && preg_match('/^[\pL\pM]+$/uD', $value) === 1;
    }

    /**
     * @brief Rule 'alpha_num' (Unicode letters and digits).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateAlphaNum(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[\pL\pM\pN]+$/uD', (string)$value) === 1;
    }

    /**
     * @brief Rule 'alpha_dash' (letters, digits, '-' and '_').
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateAlphaDash(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[\pL\pM\pN_-]+$/uD', (string)$value) === 1;
    }

    /**
     * @brief Rule 'regex:/pattern/'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     * @throws CoreException If the pattern is invalid.
     */
    protected function validateRegex(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }

        $result = @preg_match($parameters[0] ?? '', (string)$value);
        if ($result === false) {
            throw new CoreException("Validation rule 'regex' for field '{$field}' has an invalid pattern.");
        }

        return $result === 1;
    }

    /**
     * @brief Rule 'not_regex:/pattern/'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     * @throws CoreException If the pattern is invalid.
     */
    protected function validateNotRegex(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }

        $result = @preg_match($parameters[0] ?? '', (string)$value);
        if ($result === false) {
            throw new CoreException("Validation rule 'not_regex' for field '{$field}' has an invalid pattern.");
        }

        return $result === 0;
    }

    /**
     * @brief Rule 'in:a,b,c' (strict string comparison).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateIn(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_scalar($value) && in_array((string)(is_bool($value) ? (int)$value : $value), $parameters, true);
    }

    /**
     * @brief Rule 'not_in:a,b,c'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateNotIn(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_scalar($value) && !in_array((string)(is_bool($value) ? (int)$value : $value), $parameters, true);
    }

    /**
     * @brief Rule 'min:n' (number value, string length or item count).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateMin(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        $size = $this->sizeOf($value, $numeric);
        return $size !== null && $size >= $this->numericParameter($parameters, 0, 'min');
    }

    /**
     * @brief Rule 'max:n' (number value, string length or item count).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateMax(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        $size = $this->sizeOf($value, $numeric);
        return $size !== null && $size <= $this->numericParameter($parameters, 0, 'max');
    }

    /**
     * @brief Rule 'between:min,max' (inclusive).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateBetween(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        $size = $this->sizeOf($value, $numeric);
        return $size !== null
            && $size >= $this->numericParameter($parameters, 0, 'between')
            && $size <= $this->numericParameter($parameters, 1, 'between');
    }

    /**
     * @brief Rule 'size:n' (exact number, length or count).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateSize(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        $size = $this->sizeOf($value, $numeric);
        return $size !== null && $size == $this->numericParameter($parameters, 0, 'size');
    }

    /**
     * @brief Rule 'digits:n' (exactly n decimal digits).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateDigits(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        $length = (int)$this->numericParameter($parameters, 0, 'digits');
        return (is_string($value) || is_int($value)) && preg_match('/^\d{' . $length . '}$/D', (string)$value) === 1;
    }

    /**
     * @brief Rule 'date' (any strtotime-parsable date).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateDate(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return is_string($value) && strtotime($value) !== false;
    }

    /**
     * @brief Rule 'date_format:Y-m-d' (strict format match).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateDateFormat(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        if (!is_string($value) || !isset($parameters[0])) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $parameters[0], $value);
        return $date !== false && $date->format($parameters[0]) === $value;
    }

    /**
     * @brief Rule 'before:field|date'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateBefore(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        $own = is_string($value) ? strtotime($value) : false;
        $limit = $this->timestampOf($parameters[0] ?? '');
        return $own !== false && $limit !== null && $own < $limit;
    }

    /**
     * @brief Rule 'after:field|date'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateAfter(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        $own = is_string($value) ? strtotime($value) : false;
        $limit = $this->timestampOf($parameters[0] ?? '');
        return $own !== false && $limit !== null && $own > $limit;
    }

    /**
     * @brief Rule 'same:other'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateSame(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return $value === $this->getValue($parameters[0] ?? '');
    }

    /**
     * @brief Rule 'different:other'.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateDifferent(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return $value !== $this->getValue($parameters[0] ?? '');
    }

    /**
     * @brief Rule 'confirmed': '<field>_confirmation' must match.
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     */
    protected function validateConfirmed(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        return $value === $this->getValue($field . '_confirmation');
    }

    /**
     * @brief Rule 'unique:table,column[,ignoreValue[,ignoreColumn=id]]' (single indexed lookup).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     * @throws CoreException On invalid identifiers.
     */
    protected function validateUnique(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        $table = $this->sqlIdentifier($parameters[0] ?? null, 'unique');
        $column = $this->sqlIdentifier($parameters[1] ?? $field, 'unique');
        $sql = "SELECT 1 FROM {$table} WHERE {$column} = ?";
        $bindings = [$value];

        if (isset($parameters[2]) && $parameters[2] !== '' && strtolower($parameters[2]) !== 'null') {
            $ignoreColumn = $this->sqlIdentifier($parameters[3] ?? 'id', 'unique');
            $sql .= " AND {$ignoreColumn} <> ?";
            $bindings[] = $parameters[2];
        }

        return DB::getInstance()->selectValue($sql . ' LIMIT 1', $bindings) === null;
    }

    /**
     * @brief Rule 'exists:table,column' (single indexed lookup).
     * @param string $field Field name.
     * @param mixed $value Value.
     * @param list<string> $parameters Parameters.
     * @param bool $numeric Numeric context.
     * @return bool
     * @throws CoreException On invalid identifiers.
     */
    protected function validateExists(string $field, mixed $value, array $parameters, bool $numeric): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        $table = $this->sqlIdentifier($parameters[0] ?? null, 'exists');
        $column = $this->sqlIdentifier($parameters[1] ?? $field, 'exists');

        return DB::getInstance()->selectValue("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1", [$value]) !== null;
    }
}
