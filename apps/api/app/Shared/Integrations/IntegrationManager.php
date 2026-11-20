<?php

namespace App\Shared\Integrations;

use App\Shared\Integrations\DevInbox\DevInboxEmailAdapter;
use App\Shared\Integrations\DevInbox\DevInboxMessagingAdapter;
use App\Shared\Integrations\Fake\FakeAiAdapter;
use App\Shared\Integrations\Fake\FakeBackupStorageAdapter;
use App\Shared\Integrations\Fake\FakeBankingAdapter;
use App\Shared\Integrations\Fake\FakeBiometricAdapter;
use App\Shared\Integrations\Fake\FakeEmailAdapter;
use App\Shared\Integrations\Fake\FakeExchangeRatesAdapter;
use App\Shared\Integrations\Fake\FakeMessagingAdapter;
use App\Shared\Integrations\Fake\FakePushAdapter;
use App\Shared\Integrations\Fake\FakeSignatureAdapter;
use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\AiPort;
use App\Shared\Integrations\Ports\BackupStoragePort;
use App\Shared\Integrations\Ports\BankingPort;
use App\Shared\Integrations\Ports\BiometricPort;
use App\Shared\Integrations\Ports\EmailPort;
use App\Shared\Integrations\Ports\ExchangeRatesPort;
use App\Shared\Integrations\Ports\MessagingPort;
use App\Shared\Integrations\Ports\PushPort;
use App\Shared\Integrations\Ports\SignaturePort;
use RuntimeException;

final class IntegrationManager
{
    private ?EmailPort $emailAdapter = null;

    private ?SignaturePort $signatureAdapter = null;

    private ?MessagingPort $messagingAdapter = null;

    /** @var array<string, MessagingPort> */
    private array $messagingProviders = [];

    private ?BankingPort $bankingAdapter = null;

    private ?BiometricPort $biometricAdapter = null;

    private ?AiPort $aiAdapter = null;

    private ?ExchangeRatesPort $exchangeRatesAdapter = null;

    private ?BackupStoragePort $backupsAdapter = null;

    private ?PushPort $pushAdapter = null;

    public function __construct(
        private readonly IntegrationCallRecorder $recorder,
    ) {}

    public function email(): EmailPort
    {
        if ($this->emailAdapter !== null) {
            return $this->emailAdapter;
        }

        if (! app()->isProduction()) {
            $this->emailAdapter = $this->devInbox() ? new DevInboxEmailAdapter($this->recorder) : new FakeEmailAdapter($this->recorder);

            return $this->emailAdapter;
        }

        throw new RuntimeException('No live Email provider configured for production.');
    }

    public function signature(): SignaturePort
    {
        if ($this->signatureAdapter !== null) {
            return $this->signatureAdapter;
        }

        if (! app()->isProduction()) {
            $this->signatureAdapter = new FakeSignatureAdapter($this->recorder);

            return $this->signatureAdapter;
        }

        throw new RuntimeException('No live Signature provider configured for production.');
    }

    public function messaging(): MessagingPort
    {
        if ($this->messagingAdapter !== null) {
            return $this->messagingAdapter;
        }

        if (! app()->isProduction()) {
            $this->messagingAdapter = $this->devInbox() ? new DevInboxMessagingAdapter($this->recorder) : new FakeMessagingAdapter($this->recorder);

            return $this->messagingAdapter;
        }

        throw new RuntimeException('No live Messaging provider configured for production.');
    }

    /**
     * NOT-5 · Adaptador del proveedor que la organización configuró para el canal (message_channels).
     * Un proveedor nuevo se registra en config('integrations.messaging_providers') sin tocar a quien
     * envía; sin proveedor configurado se usa el adaptador por defecto (falso fuera de producción).
     */
    public function messagingFor(?string $provider): MessagingPort
    {
        if ($provider === null || $provider === '') {
            return $this->messaging();
        }
        if (isset($this->messagingProviders[$provider])) {
            return $this->messagingProviders[$provider];
        }
        /** @var array<string, class-string<MessagingPort>> $registered */
        $registered = (array) config('integrations.messaging_providers', []);
        if (isset($registered[$provider])) {
            /** @var MessagingPort $adapter */
            $adapter = app($registered[$provider]);

            return $this->messagingProviders[$provider] = $adapter;
        }

        return $this->messaging();
    }

    public function setMessagingProvider(string $provider, MessagingPort $adapter): void
    {
        $this->messagingProviders[$provider] = $adapter;
    }

    public function banking(): BankingPort
    {
        if ($this->bankingAdapter !== null) {
            return $this->bankingAdapter;
        }

        if (! app()->isProduction()) {
            $this->bankingAdapter = new FakeBankingAdapter($this->recorder);

            return $this->bankingAdapter;
        }

        throw new RuntimeException('No live Banking provider configured for production.');
    }

    public function biometric(): BiometricPort
    {
        if ($this->biometricAdapter !== null) {
            return $this->biometricAdapter;
        }

        if (! app()->isProduction()) {
            $this->biometricAdapter = new FakeBiometricAdapter($this->recorder);

            return $this->biometricAdapter;
        }

        throw new RuntimeException('No live Biometric provider configured for production.');
    }

    public function ai(): AiPort
    {
        if ($this->aiAdapter !== null) {
            return $this->aiAdapter;
        }

        if (! app()->isProduction()) {
            $this->aiAdapter = new FakeAiAdapter($this->recorder);

            return $this->aiAdapter;
        }

        throw new RuntimeException('No live AI provider configured for production.');
    }

    /** CFG-7 · Fuente de tipos de cambio (sin proveedor real configurado: la falsa fuera de producción). */
    public function exchangeRates(): ExchangeRatesPort
    {
        if ($this->exchangeRatesAdapter !== null) {
            return $this->exchangeRatesAdapter;
        }

        if (! app()->isProduction()) {
            $this->exchangeRatesAdapter = new FakeExchangeRatesAdapter($this->recorder);

            return $this->exchangeRatesAdapter;
        }

        throw new RuntimeException('No live Exchange rates provider configured for production.');
    }

    /** SAS-12 · Almacenamiento de copias y entorno aislado de restauración (sin proveedor real: la falsa fuera de producción). */
    public function backups(): BackupStoragePort
    {
        if ($this->backupsAdapter !== null) {
            return $this->backupsAdapter;
        }

        if (! app()->isProduction()) {
            $this->backupsAdapter = new FakeBackupStorageAdapter($this->recorder);

            return $this->backupsAdapter;
        }

        throw new RuntimeException('No live Backup storage provider configured for production.');
    }

    /** NOT-4 · Push web (sin proveedor real todavía: la falsa fuera de producción). */
    public function push(): PushPort
    {
        if ($this->pushAdapter !== null) {
            return $this->pushAdapter;
        }

        if (! app()->isProduction()) {
            $this->pushAdapter = new FakePushAdapter($this->recorder);

            return $this->pushAdapter;
        }

        throw new RuntimeException('No live Push provider configured for production.');
    }

    public function setPushAdapter(PushPort $adapter): void
    {
        $this->pushAdapter = $adapter;
    }

    public function setBackupsAdapter(BackupStoragePort $adapter): void
    {
        $this->backupsAdapter = $adapter;
    }

    /**
     * SAS-9 · Proveedor de cada puerto para la página de estado (falso o real); un puerto sin proveedor en
     * producción aparece como no configurado.
     *
     * @return list<array{port: string, provider: string|null, fake: bool}>
     */
    public function status(): array
    {
        $out = [];
        foreach (['email' => fn () => $this->email(), 'messaging' => fn () => $this->messaging(), 'signature' => fn () => $this->signature(),
            'banking' => fn () => $this->banking(), 'biometric' => fn () => $this->biometric(), 'ai' => fn () => $this->ai(),
            'exchange_rates' => fn () => $this->exchangeRates(), 'backups' => fn () => $this->backups(), 'push' => fn () => $this->push()] as $port => $resolve) {
            try {
                $adapter = $resolve();
                $out[] = ['port' => $port, 'provider' => $adapter->providerName(), 'fake' => $adapter->isFake()];
            } catch (RuntimeException) {
                $out[] = ['port' => $port, 'provider' => null, 'fake' => false];
            }
        }

        return $out;
    }

    /** Desarrollo local: correo y mensajes al buzón de pruebas (config integrations.dev_inbox). */
    private function devInbox(): bool
    {
        return config('integrations.dev_inbox') === 'mailpit' && ! app()->runningUnitTests();
    }

    public function setEmailAdapter(EmailPort $adapter): void
    {
        $this->emailAdapter = $adapter;
    }

    public function setSignatureAdapter(SignaturePort $adapter): void
    {
        $this->signatureAdapter = $adapter;
    }

    public function setMessagingAdapter(MessagingPort $adapter): void
    {
        $this->messagingAdapter = $adapter;
    }

    public function setBankingAdapter(BankingPort $adapter): void
    {
        $this->bankingAdapter = $adapter;
    }

    public function setBiometricAdapter(BiometricPort $adapter): void
    {
        $this->biometricAdapter = $adapter;
    }

    public function setAiAdapter(AiPort $adapter): void
    {
        $this->aiAdapter = $adapter;
    }

    public function setExchangeRatesAdapter(ExchangeRatesPort $adapter): void
    {
        $this->exchangeRatesAdapter = $adapter;
    }
}
