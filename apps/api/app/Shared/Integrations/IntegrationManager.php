<?php

namespace App\Shared\Integrations;

use App\Shared\Integrations\DevInbox\DevInboxEmailAdapter;
use App\Shared\Integrations\DevInbox\DevInboxMessagingAdapter;
use App\Shared\Integrations\Fake\FakeAiAdapter;
use App\Shared\Integrations\Fake\FakeBankingAdapter;
use App\Shared\Integrations\Fake\FakeBiometricAdapter;
use App\Shared\Integrations\Fake\FakeEmailAdapter;
use App\Shared\Integrations\Fake\FakeMessagingAdapter;
use App\Shared\Integrations\Fake\FakeSignatureAdapter;
use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\AiPort;
use App\Shared\Integrations\Ports\BankingPort;
use App\Shared\Integrations\Ports\BiometricPort;
use App\Shared\Integrations\Ports\EmailPort;
use App\Shared\Integrations\Ports\MessagingPort;
use App\Shared\Integrations\Ports\SignaturePort;
use RuntimeException;

final class IntegrationManager
{
    private ?EmailPort $emailAdapter = null;

    private ?SignaturePort $signatureAdapter = null;

    private ?MessagingPort $messagingAdapter = null;

    private ?BankingPort $bankingAdapter = null;

    private ?BiometricPort $biometricAdapter = null;

    private ?AiPort $aiAdapter = null;

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
}
