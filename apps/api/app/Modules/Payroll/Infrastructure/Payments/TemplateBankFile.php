<?php

namespace App\Modules\Payroll\Infrastructure\Payments;

use App\Shared\Payroll\BankFileFormat;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * DEC-5 · ADR 0020 · Formato de archivo bancario definido como datos (plantilla del paquete de país o de la
 * organización): agregar un banco es cargar su plantilla, sin programar.
 *
 * definition: separator (null = ancho fijo), line_ending (CRLF|LF), encoding, extension, mime, y tres listas de
 * campos (header, detail, trailer). Cada campo toma su valor de source (header.*, item.*, batch.count) o de value
 * (literal), y se da forma con width, pad (left|right), pad_char, decimals, implied_decimals y date_format.
 * account_rules valida las cuentas registradas antes de escribir: account_number_pattern, require_interbank y
 * currencies.
 */
final class TemplateBankFile implements BankFileFormat
{
    /** @param array<string, mixed> $definition */
    public function __construct(private readonly string $code, private readonly array $definition) {}

    public function code(): string
    {
        return $this->code;
    }

    public function extension(): string
    {
        return (string) ($this->definition['extension'] ?? 'txt');
    }

    public function mime(): string
    {
        return (string) ($this->definition['mime'] ?? 'text/plain');
    }

    /**
     * Cuentas que no cumplen las reglas de la plantilla (vacío si todas pasan).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{reference: string, name: string, rule: string}>
     */
    public function invalidAccounts(array $items): array
    {
        $rules = (array) ($this->definition['account_rules'] ?? []);
        $out = [];
        foreach ($items as $i) {
            $rule = null;
            $pattern = $rules['account_number_pattern'] ?? null;
            if (is_string($pattern) && @preg_match($pattern, (string) $i['account_number']) !== 1) {
                $rule = 'account_number_pattern';
            }
            if (($rules['require_interbank'] ?? false) === true && ($i['interbank_code'] ?? null) === null) {
                $rule = 'interbank_required';
            }
            $currencies = $rules['currencies'] ?? null;
            if (is_array($currencies) && ! in_array($i['currency'], $currencies, true)) {
                $rule = 'currency';
            }
            if ($rule !== null) {
                $out[] = ['reference' => (string) $i['reference'], 'name' => (string) $i['name'], 'rule' => $rule];
            }
        }

        return $out;
    }

    public function render(array $header, array $items): string
    {
        $eol = ($this->definition['line_ending'] ?? 'CRLF') === 'LF' ? "\n" : "\r\n";
        $context = ['header' => $header + ['count' => (string) count($items)]];
        $lines = [];
        if (isset($this->definition['header']) && is_array($this->definition['header']) && $this->definition['header'] !== []) {
            $lines[] = $this->line($this->definition['header'], $context);
        }
        foreach ($items as $n => $item) {
            $lines[] = $this->line((array) ($this->definition['detail'] ?? []), $context + ['item' => $item + ['sequence' => (string) ($n + 1)]]);
        }
        if (isset($this->definition['trailer']) && is_array($this->definition['trailer']) && $this->definition['trailer'] !== []) {
            $lines[] = $this->line($this->definition['trailer'], $context);
        }
        $content = implode($eol, $lines).$eol;
        $encoding = strtoupper((string) ($this->definition['encoding'] ?? 'UTF-8'));
        if ($encoding !== 'UTF-8') {
            $converted = @iconv('UTF-8', $encoding.'//TRANSLIT', $content);
            $content = $converted === false ? $content : $converted;
        }

        return $content;
    }

    /**
     * @param  array<int, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $context
     */
    private function line(array $fields, array $context): string
    {
        $separator = $this->definition['separator'] ?? null;
        $cells = [];
        foreach ($fields as $f) {
            $f = (array) $f;
            $cells[] = $this->cell($f, $context, $separator === null);
        }

        return $separator === null ? implode('', $cells) : implode((string) $separator, $cells);
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, array<string, mixed>>  $context
     */
    private function cell(array $f, array $context, bool $fixed): string
    {
        if (array_key_exists('value', $f)) {
            $value = (string) $f['value'];
        } else {
            [$scope, $key] = array_pad(explode('.', (string) ($f['source'] ?? ''), 2), 2, '');
            $raw = $context[$scope][$key] ?? null;
            $value = $raw === null ? '' : (string) $raw;
        }
        if (isset($f['decimals']) && is_numeric($value)) {
            $n = BigDecimal::of($value)->toScale((int) $f['decimals'], RoundingMode::HALF_UP);
            $value = ($f['implied_decimals'] ?? false) === true ? (string) $n->getUnscaledValue() : (string) $n;
        }
        if (isset($f['date_format']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            $value = strtr((string) $f['date_format'], ['Y' => $m[1], 'm' => $m[2], 'd' => $m[3]]);
        }
        if (isset($f['upper']) && $f['upper'] === true) {
            $value = mb_strtoupper($value);
        }
        $width = isset($f['width']) ? (int) $f['width'] : null;
        if ($width !== null) {
            $value = mb_substr($value, 0, $width);
            if ($fixed || ($f['pad'] ?? null) !== null) {
                $pad = (string) ($f['pad_char'] ?? ' ');
                $missing = $width - mb_strlen($value);
                if ($missing > 0) {
                    $fill = str_repeat($pad === '' ? ' ' : mb_substr($pad, 0, 1), $missing);
                    $value = ($f['pad'] ?? 'right') === 'left' ? $fill.$value : $value.$fill;
                }
            }
        }

        return $value;
    }
}
