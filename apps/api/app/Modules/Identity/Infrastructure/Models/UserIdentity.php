<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Shared\Security\BlindIndex;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identificador de ingreso (correo, documento, teléfono). Se busca por índice ciego
 * y se guarda cifrado; nunca en claro.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $kind
 * @property string $identifier_hash
 * @property string $identifier_enc
 * @property bool $is_primary
 */
class UserIdentity extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'user_identities';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['identifier_hash', 'identifier_enc'];

    protected $casts = [
        'verified_at' => 'datetime',
        'is_primary' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array{kind: string, identifier_hash: string, identifier_enc: string}
     */
    public static function attributesFor(string $kind, string $identifier): array
    {
        return [
            'kind' => $kind,
            'identifier_hash' => BlindIndex::for($kind, $identifier),
            'identifier_enc' => FieldCipher::encrypt(BlindIndex::normalize($kind, $identifier)),
        ];
    }

    public function identifier(): string
    {
        return FieldCipher::decrypt($this->identifier_enc);
    }
}
