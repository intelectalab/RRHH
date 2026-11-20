<?php

namespace App\Modules\Platform\Application\Settings;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\PlatformSettings;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * SAS-13 · Configuración global del SaaS: valores con que nacen las organizaciones nuevas, países habilitados,
 * plazos de la consola y de las copias. Cada clave tiene su validación; la fila de platform_settings, si existe,
 * manda sobre el valor por defecto de config/platform.php. Cada cambio queda en la auditoría de la consola.
 */
final class GlobalSettings
{
    /** @var array<string, array{type: string, rules: list<string>, item?: string}> */
    public const DEFINITIONS = [
        'tenants.enabled_countries' => ['type' => 'list', 'rules' => ['array', 'min:1'], 'item' => 'country'],
        'tenants.defaults' => ['type' => 'map', 'rules' => ['array'], 'item' => 'tenant_defaults'],
        'tenants.default_parameters' => ['type' => 'map', 'rules' => ['array'], 'item' => 'parameter'],
        'tenants.retention_days_after_cancel' => ['type' => 'map', 'rules' => ['array', 'min:1'], 'item' => 'days'],
        'modules.read_only_transition_days' => ['type' => 'integer', 'rules' => ['integer', 'min:0', 'max:365']],
        'subscriptions.trial_grace_days' => ['type' => 'integer', 'rules' => ['integer', 'min:0', 'max:90']],
        'support.max_session_minutes' => ['type' => 'integer', 'rules' => ['integer', 'min:5', 'max:480']],
        'backups.retention_days' => ['type' => 'integer', 'rules' => ['integer', 'min:1', 'max:3650']],
        'backups.test_restore_every_days' => ['type' => 'integer', 'rules' => ['integer', 'min:1', 'max:90']],
    ];

    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly ConsoleAudit $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = DB::table('platform_settings')->get(['key', 'updated_at'])->keyBy('key');
        $defaults = (array) config('platform.settings', []);
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $out[] = [
                'key' => $key,
                'type' => $def['type'],
                'value' => $this->settings->value($key),
                'default' => $defaults[$key] ?? null,
                'customized' => isset($rows[$key]),
                'description' => (string) trans('platform.settings.keys.'.str_replace('.', '_', $key)),
                'updated_at' => isset($rows[$key]) ? (string) $rows[$key]->updated_at : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $key, mixed $value, PlatformUser $actor): array
    {
        if (! isset(self::DEFINITIONS[$key])) {
            throw new AyniException('not_found', 404, (string) trans('platform.settings.unknown_key'));
        }
        $value = $this->validate($key, $value);
        $before = $this->settings->value($key);
        $exists = DB::table('platform_settings')->where('key', $key)->exists();
        $payload = json_encode($value, JSON_THROW_ON_ERROR);
        if ($exists) {
            DB::table('platform_settings')->where('key', $key)->update(['value' => $payload]);
        } else {
            DB::table('platform_settings')->insert(['key' => $key, 'value' => $payload]);
        }
        $this->audit->record('settings.updated', $actor, 'platform_setting', null, ['key' => $key, 'value' => $before], ['key' => $key, 'value' => $value]);

        foreach ($this->all() as $setting) {
            if ($setting['key'] === $key) {
                return $setting;
            }
        }

        return [];
    }

    private function validate(string $key, mixed $value): mixed
    {
        $def = self::DEFINITIONS[$key];
        $v = Validator::make(['value' => $value], ['value' => ['required', ...$def['rules']]]);
        if ($v->fails()) {
            throw ValidationException::withMessages(['value' => $v->errors()->all()]);
        }
        $fail = fn (): never => throw ValidationException::withMessages(['value' => [(string) trans('platform.settings.invalid_value')]]);
        $countries = fn (): array => DB::table('countries')->pluck('code')->map(fn ($c): string => (string) $c)->all();

        switch ($def['item'] ?? null) {
            case 'country':
                $list = array_values(array_unique(array_map('strval', (array) $value)));
                if (array_diff($list, $countries()) !== []) {
                    $fail();
                }

                return $list;
            case 'days':
                foreach ((array) $value as $country => $days) {
                    if (($country !== 'default' && ! in_array($country, $countries(), true)) || ! is_int($days) || $days < 0 || $days > 3650) {
                        $fail();
                    }
                }

                return $value;
            case 'tenant_defaults':
                $currencies = DB::table('currencies')->pluck('code')->map(fn ($c): string => (string) $c)->all();
                foreach ((array) $value as $country => $d) {
                    if (! in_array($country, $countries(), true) || ! is_array($d)
                        || ! in_array($d['currency'] ?? null, $currencies, true)
                        || ! in_array($d['timezone'] ?? null, timezone_identifiers_list(), true)
                        || ! in_array($d['locale'] ?? null, ['es', 'en', 'pt'], true)) {
                        $fail();
                    }
                }

                return $value;
            case 'parameter':
                $codes = DB::table('parameter_definitions')->pluck('code')->map(fn ($c): string => (string) $c)->all();
                if (array_diff(array_keys((array) $value), $codes) !== []) {
                    $fail();
                }

                return $value === [] ? (object) [] : $value;
        }

        return $value;
    }
}
