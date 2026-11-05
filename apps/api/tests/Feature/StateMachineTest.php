<?php

namespace Tests\Feature;

use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\StateMachine\HasOptimisticLocking;
use App\Shared\StateMachine\StateMachine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantTestHelper;
use Tests\TestCase;

class SampleStatusStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'trial' => [
                'activate' => 'active',
                'cancel' => 'cancelled',
            ],
            'active' => [
                'suspend' => 'suspended',
            ],
            'suspended' => [
                'reactivate' => 'active',
            ],
        ];
    }
}

class TestTenantRecord extends Model
{
    use HasOptimisticLocking;

    protected $table = 'tenants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'status',
        'lock_version',
        'name',
        'slug',
    ];
}

class StateMachineTest extends TestCase
{
    use DatabaseTransactions;
    use TenantTestHelper;

    private SampleStatusStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new SampleStatusStateMachine;
    }

    public function test_valid_transition_changes_state_and_increments_lock_version(): void
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id,
            'name' => 'Empresa Activar',
            'slug' => 'empresa-activar-'.Str::random(6),
            'status' => 'trial',
            'lock_version' => 1,
            'country_code' => 'PE',
            'default_currency' => 'PEN',
            'default_locale' => 'es',
            'timezone' => 'America/Lima',
            'is_demo' => false,
            'is_partner_managed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var TestTenantRecord $record */
        $record = TestTenantRecord::findOrFail($id);
        $this->assertSame('trial', $record->status);
        $this->assertSame(1, $record->lock_version);

        $record->applyTransition('activate', $this->stateMachine);

        $this->assertSame('active', $record->status);
        $this->assertSame(2, $record->lock_version);

        // Verifica en base de datos
        $this->assertDatabaseHas('tenants', [
            'id' => $id,
            'status' => 'active',
            'lock_version' => 2,
        ]);
    }

    public function test_invalid_transition_throws_409_with_current_status_and_allowed_actions(): void
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id,
            'name' => 'Empresa Transición Inválida',
            'slug' => 'empresa-invalida-'.Str::random(6),
            'status' => 'trial',
            'lock_version' => 1,
            'country_code' => 'PE',
            'default_currency' => 'PEN',
            'default_locale' => 'es',
            'timezone' => 'America/Lima',
            'is_demo' => false,
            'is_partner_managed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var TestTenantRecord $record */
        $record = TestTenantRecord::findOrFail($id);

        try {
            // 'suspend' no es válida desde 'trial' (solo activate o cancel)
            $record->applyTransition('suspend', $this->stateMachine);
            $this->fail('Debió lanzar InvalidTransitionException');
        } catch (InvalidTransitionException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('invalid_transition', $e->getErrorCode());
            $this->assertSame('trial', $e->getMeta()['current_status']);
            $this->assertContains('activate', $e->getMeta()['allowed_actions']);
            $this->assertContains('cancel', $e->getMeta()['allowed_actions']);
            $this->assertNotContains('suspend', $e->getMeta()['allowed_actions']);
        }

        // El estado no debe haber cambiado en la base
        $this->assertDatabaseHas('tenants', [
            'id' => $id,
            'status' => 'trial',
            'lock_version' => 1,
        ]);
    }

    public function test_concurrent_edits_receive_version_conflict_409(): void
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id,
            'name' => 'Empresa Concurrencia',
            'slug' => 'empresa-concurrencia-'.Str::random(6),
            'status' => 'trial',
            'lock_version' => 1,
            'country_code' => 'PE',
            'default_currency' => 'PEN',
            'default_locale' => 'es',
            'timezone' => 'America/Lima',
            'is_demo' => false,
            'is_partner_managed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Instancia A y B leen el mismo registro en versión 1
        /** @var TestTenantRecord $recordA */
        $recordA = TestTenantRecord::findOrFail($id);
        /** @var TestTenantRecord $recordB */
        $recordB = TestTenantRecord::findOrFail($id);

        // A actualiza primero: pasa de versión 1 a 2
        $recordA->updateWithOptimisticLock(['name' => 'Nombre por A']);
        $this->assertSame(2, $recordA->lock_version);

        // B intenta actualizar teniendo versión 1 en memoria: debe fallar con VersionConflictException
        try {
            $recordB->updateWithOptimisticLock(['name' => 'Nombre por B']);
            $this->fail('Debió lanzar VersionConflictException');
        } catch (VersionConflictException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('version_conflict', $e->getErrorCode());
            $this->assertSame(1, $e->getMeta()['expected_version']);
            $this->assertSame(2, $e->getMeta()['actual_version']);
        }

        // El registro mantiene la modificación de A sin haber sido pisado por B
        $this->assertDatabaseHas('tenants', [
            'id' => $id,
            'name' => 'Nombre por A',
            'lock_version' => 2,
        ]);
    }
}

