<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\LoginDocument;
use App\Modules\Identity\Domain\UserAccountStateMachine;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\BlindIndex;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * SEG-2 · RN-SEG-09 · Personal sin correo: ingreso con documento + código por mensaje, y
 * activación de la cuenta con un código que entrega RRHH o que llega por SMS/WhatsApp.
 *
 * Las respuestas públicas no revelan si el documento existe: pedir un código siempre «se envía».
 */
final class CodeSignIn
{
    /** Dígitos del código de ingreso (lo teclea el usuario desde el SMS). */
    private const LOGIN_DIGITS = 6;

    /** Dígitos del código de activación (lo dicta o entrega RRHH y vale más tiempo). */
    private const ACTIVATION_DIGITS = 8;

    public function __construct(
        private readonly MessageCodes $codes,
        private readonly OutboxWriter $outbox,
        private readonly ParameterResolver $parameters,
        private readonly AccountLockout $lockout,
        private readonly UserAccountStateMachine $machine,
        private readonly UserService $users,
        private readonly AuditLogger $audit,
    ) {}

    /** Parámetro seguridad.acceso_con_documento: la organización puede apagar el ingreso con documento. */
    public function documentAccessEnabled(): bool
    {
        return $this->parameters->value('seguridad.acceso_con_documento') !== false;
    }

    public function findByDocument(string $type, string $number): ?User
    {
        if (! $this->documentAccessEnabled()) {
            return null;
        }
        $identity = UserIdentity::query()->where('kind', 'national_id')
            ->where('identifier_hash', BlindIndex::for('national_id', LoginDocument::identifier($type, $number)))->first();

        /** @var User|null */
        return $identity === null ? null : User::query()->find($identity->user_id);
    }

    /**
     * Envía un código de ingreso al celular registrado (o al correo si la organización eligió ese canal).
     * No hace nada visible si el documento no existe, la cuenta no está activa o no hay a dónde enviarlo.
     */
    public function requestLoginCode(string $type, string $number): void
    {
        $user = $this->findByDocument($type, $number);
        if ($user === null || $user->status !== User::STATUS_ACTIVE || ($user->locked_until !== null && $user->locked_until->isFuture())) {
            return;
        }
        $channel = $this->channel();
        if (! $this->hasDestination($user, $channel) || $this->codes->recentlySent($user, 'otp_login')) {
            return;
        }
        DB::transaction(function () use ($user, $channel): void {
            $issued = $this->codes->issue($user, 'otp_login', $channel, self::LOGIN_DIGITS);
            $this->send($user, 'otp_login', $channel, $issued['code'], $this->codes->ttlMinutes());
            $this->lockout->activity($user, 'auth.login_code_sent');
        });
    }

    /**
     * Segundo paso del ingreso con código. Los fallos cuentan para el bloqueo de la cuenta (RN-SEG-07).
     */
    public function loginWithCode(string $type, string $number, string $code): ?User
    {
        $user = $this->findByDocument($type, $number);
        if ($user === null || $user->status !== User::STATUS_ACTIVE || ($user->locked_until !== null && $user->locked_until->isFuture())) {
            return null;
        }
        if (! $this->codes->consume($user, 'otp_login', $code)) {
            $this->lockout->registerFailure($user, 'auth.login_code_failed');

            return null;
        }
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now(), 'failed_attempts' => 0]);
        $this->markVerified($user);
        $this->lockout->activity($user, 'auth.login');

        return $user->refresh();
    }

    /**
     * RRHH emite el código de activación: se muestra una vez (in_app) o se envía por mensaje.
     *
     * @return array{code: string|null, channel: string, expires_at: string}
     */
    public function issueActivationCode(User $user, string $channel): array
    {
        if (! $this->machine->can($user->status, UserAccountStateMachine::ACTIVATE)) {
            throw new BusinessRuleViolatedException('activation_not_pending', (string) trans('identity.codes.activation_not_pending'));
        }
        if ($channel !== 'in_app' && ! $this->hasDestination($user, $channel)) {
            throw new BusinessRuleViolatedException('no_phone', (string) trans('identity.codes.no_phone'));
        }
        $hours = max(1, (int) config('identity.invitation_ttl_hours'));

        return DB::transaction(function () use ($user, $channel, $hours): array {
            $issued = $this->codes->issue($user, 'invite', $channel, self::ACTIVATION_DIGITS, $hours * 60);
            if ($channel !== 'in_app') {
                $this->send($user, 'invite', $channel, $issued['code'], $hours * 60);
            }
            // El código nunca va a la auditoría: solo el canal y la vigencia.
            $this->audit->record('user.activation_code_issued', 'user', $user->id, null, ['channel' => $channel, 'expires_at' => $issued['expires_at']->toIso8601String()]);

            return [
                'code' => $channel === 'in_app' ? $issued['code'] : null,
                'channel' => $channel,
                'expires_at' => $issued['expires_at']->toIso8601String(),
            ];
        });
    }

    /**
     * El colaborador activa su cuenta con documento + código y define su clave.
     */
    public function activateWithCode(string $type, string $number, string $code, string $password): ?User
    {
        $user = $this->findByDocument($type, $number);
        if ($user === null || ! $this->machine->can($user->status, UserAccountStateMachine::ACTIVATE)) {
            return null;
        }

        return DB::transaction(function () use ($user, $code, $password): ?User {
            // La clave se valida antes de gastar el código: un error de clave no lo consume.
            app(PasswordPolicy::class)->assertAcceptable($user, $password);
            if (! $this->codes->consume($user, 'invite', $code)) {
                return null;
            }
            $this->users->activate($user, $password);
            $this->markVerified($user);

            return $user->refresh();
        });
    }

    public function channel(): string
    {
        $channel = (string) ($this->parameters->value('seguridad.canal_otp') ?? 'sms');

        return in_array($channel, ['sms', 'whatsapp', 'email'], true) ? $channel : 'sms';
    }

    private function hasDestination(User $user, string $channel): bool
    {
        return UserIdentity::query()->where('user_id', $user->id)->where('kind', $channel === 'email' ? 'email' : 'phone')->exists();
    }

    private function send(User $user, string $purpose, string $channel, string $code, int $minutes): void
    {
        $event = $this->outbox->record(DomainEventTypes::USER_MESSAGE_CODE_ISSUED, 'user', $user->id, [
            'user_id' => $user->id,
            'purpose' => $purpose,
            'channel' => $channel,
            'code_enc' => Crypt::encryptString($code),
            'minutes' => $minutes,
        ]);
        $this->outbox->deliverSoon($event);
    }

    private function markVerified(User $user): void
    {
        UserIdentity::query()->where('user_id', $user->id)->whereIn('kind', ['national_id', 'phone'])
            ->whereNull('verified_at')->update(['verified_at' => now()]);
    }
}
