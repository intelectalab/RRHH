<?php

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Notifications\Application\Push\PushDevices;
use App\Modules\Notifications\Infrastructure\Models\PushDevice;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushDevice>
 */
class PushDeviceFactory extends Factory
{
    protected $model = PushDevice::class;

    public function definition(): array
    {
        $endpoint = 'https://push.example.com/'.Str::random(24);

        return [
            'user_id' => function (): string {
                $id = (string) Str::uuid();
                DB::table('users')->insert(['id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'display_name' => $this->faker->name(), 'status' => 'active']);

                return $id;
            },
            'platform' => 'web',
            'token_enc' => FieldCipher::encrypt((string) json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 'a']])),
            'token_hash' => PushDevices::fingerprint($endpoint),
            'label' => 'Chrome',
        ];
    }
}
