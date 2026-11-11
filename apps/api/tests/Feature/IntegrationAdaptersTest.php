<?php

use App\Shared\Integrations\Fake\FakeAiAdapter;
use App\Shared\Integrations\Fake\FakeBankingAdapter;
use App\Shared\Integrations\Fake\FakeBiometricAdapter;
use App\Shared\Integrations\Fake\FakeEmailAdapter;
use App\Shared\Integrations\Fake\FakeMessagingAdapter;
use App\Shared\Integrations\Fake\FakeSignatureAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\AiPort;
use App\Shared\Integrations\Ports\BankingPort;
use App\Shared\Integrations\Ports\BiometricPort;
use App\Shared\Integrations\Ports\EmailPort;
use App\Shared\Integrations\Ports\MessagingPort;
use App\Shared\Integrations\Ports\SignaturePort;
use App\Shared\Messaging\OutboundMessage;

beforeEach(function () {
    $this->recorder = new IntegrationCallRecorder;
    $this->recorder->clear();
});

test('fake email adapter sends message and records call', function () {
    $adapter = new FakeEmailAdapter($this->recorder);

    $messageId = $adapter->send('hr@empresa.pe', 'Bienvenida', 'Hola, bienvenido al equipo.');

    expect($messageId)->toStartWith('email_');

    $sent = $adapter->getSent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['to'])->toBe('hr@empresa.pe')
        ->and($sent[0]['subject'])->toBe('Bienvenida');

    $calls = $this->recorder->getRecentCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['provider'])->toBe('fake_email')
        ->and($calls[0]['operation'])->toBe('send')
        ->and($calls[0]['status'])->toBe('success')
        ->and($calls[0]['duration_ms'])->toBeFloat();
});

test('fake email adapter records failed call on exception', function () {
    $adapter = new FakeEmailAdapter($this->recorder);
    $adapter->failNext(new RuntimeException('SMTP timeout'));

    expect(fn () => $adapter->send('hr@empresa.pe', 'Test', 'Cuerpo'))
        ->toThrow(RuntimeException::class, 'SMTP timeout');

    $calls = $this->recorder->getRecentCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['status'])->toBe('failed')
        ->and($calls[0]['error'])->toBe('SMTP timeout');
});

test('fake signature adapter creates request and checks status', function () {
    $adapter = new FakeSignatureAdapter($this->recorder);

    $signers = [
        ['name' => 'María García', 'email' => 'maria@empresa.pe', 'order' => 1],
        ['name' => 'Carlos Ruiz', 'email' => 'carlos@empresa.pe', 'order' => 2],
    ];

    $requestId = $adapter->createSignatureRequest('doc-001', $signers);

    expect($requestId)->toStartWith('sig_');
    expect($adapter->checkStatus($requestId))->toBe('pending');

    $adapter->setStatus($requestId, 'completed');
    expect($adapter->checkStatus($requestId))->toBe('completed');

    $calls = $this->recorder->getRecentCalls();
    expect($calls)->toHaveCount(3)
        ->and($calls[0]['provider'])->toBe('fake_signature')
        ->and($calls[0]['operation'])->toBe('createSignatureRequest')
        ->and($calls[0]['status'])->toBe('success')
        ->and($calls[1]['operation'])->toBe('checkStatus')
        ->and($calls[2]['operation'])->toBe('checkStatus');
});

test('fake messaging adapter sends whatsapp message', function () {
    $adapter = new FakeMessagingAdapter($this->recorder);

    $message = new OutboundMessage('whatsapp', '+51987654321', 'Su recibo de pago está disponible.', 'ref-1');
    $messageId = $adapter->send($message);

    expect($messageId)->toStartWith('msg_')
        // La misma referencia no se envía dos veces (idempotencia del lado del proveedor).
        ->and($adapter->send($message))->toBe($messageId);

    $messages = $adapter->getMessages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['phone'])->toBe('+51987654321')
        ->and($messages[0]['message'])->toBe('Su recibo de pago está disponible.');
});

test('fake banking adapter processes payout', function () {
    $adapter = new FakeBankingAdapter($this->recorder);

    $items = [
        ['account' => '194-1234567-0-01', 'amount' => 2500.00, 'currency' => 'PEN', 'recipient' => 'Juan Pérez'],
        ['account' => '194-9876543-0-01', 'amount' => 1800.00, 'currency' => 'PEN', 'recipient' => 'Ana Torres'],
    ];

    $txId = $adapter->processPayout('batch-2026-01', 4300.00, $items);

    expect($txId)->toStartWith('tx_bank_');

    $payouts = $adapter->getPayouts();
    expect($payouts)->toHaveCount(1)
        ->and($payouts[$txId]['batch_id'])->toBe('batch-2026-01')
        ->and($payouts[$txId]['total_amount'])->toBe(4300.00);
});

test('fake biometric adapter returns simulated punches', function () {
    $adapter = new FakeBiometricAdapter($this->recorder);

    $simulatedPunches = [
        ['device_id' => 'turnstile-01', 'user_id' => 'emp-100', 'timestamp' => '2026-01-15T08:02:00Z', 'type' => 'in'],
        ['device_id' => 'turnstile-01', 'user_id' => 'emp-100', 'timestamp' => '2026-01-15T17:30:00Z', 'type' => 'out'],
    ];

    $adapter->setSimulatedPunches($simulatedPunches);

    $result = $adapter->pullPunches('turnstile-01');

    expect($result)->toHaveKey('punches')
        ->and($result['punches'])->toHaveCount(2)
        ->and($result['punches'][0]['type'])->toBe('in')
        ->and($result['next_cursor'])->toBeNull();

    $calls = $this->recorder->getRecentCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['provider'])->toBe('fake_biometric')
        ->and($calls[0]['operation'])->toBe('pullPunches');
});

test('fake ai adapter returns simulated response', function () {
    $adapter = new FakeAiAdapter($this->recorder);
    $adapter->setResponse('El empleado ha tenido buen desempeño durante el trimestre.');

    $response = $adapter->complete('Evalúa el desempeño del empleado Juan Pérez.');

    expect($response)->toBe('El empleado ha tenido buen desempeño durante el trimestre.');

    $calls = $this->recorder->getRecentCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['provider'])->toBe('fake_ai')
        ->and($calls[0]['operation'])->toBe('complete')
        ->and($calls[0]['status'])->toBe('success');
});

test('integration manager uses fake adapters outside production', function () {
    $recorder = app(IntegrationCallRecorder::class);
    $manager = app(IntegrationManager::class);

    // In testing env (non-production), all adapters should be fakes
    expect($manager->email())->toBeInstanceOf(EmailPort::class)
        ->and($manager->email()->isFake())->toBeTrue();

    expect($manager->signature())->toBeInstanceOf(SignaturePort::class)
        ->and($manager->signature()->isFake())->toBeTrue();

    expect($manager->messaging())->toBeInstanceOf(MessagingPort::class)
        ->and($manager->messaging()->isFake())->toBeTrue();

    expect($manager->banking())->toBeInstanceOf(BankingPort::class)
        ->and($manager->banking()->isFake())->toBeTrue();

    expect($manager->biometric())->toBeInstanceOf(BiometricPort::class)
        ->and($manager->biometric()->isFake())->toBeTrue();

    expect($manager->ai())->toBeInstanceOf(AiPort::class)
        ->and($manager->ai()->isFake())->toBeTrue();
});

test('integration call recorder logs calls with duration provider and status', function () {
    $this->recorder->record('stripe', 'charge', 142.5, 'success', null, ['amount' => 100]);
    $this->recorder->record('sendgrid', 'send_email', 85.3, 'failed', 'Connection timeout');

    $calls = $this->recorder->getRecentCalls();

    expect($calls)->toHaveCount(2);

    expect($calls[0])->toHaveKeys(['provider', 'operation', 'duration_ms', 'status', 'error', 'metadata', 'recorded_at'])
        ->and($calls[0]['provider'])->toBe('stripe')
        ->and($calls[0]['operation'])->toBe('charge')
        ->and($calls[0]['duration_ms'])->toBe(142.5)
        ->and($calls[0]['status'])->toBe('success')
        ->and($calls[0]['error'])->toBeNull();

    expect($calls[1]['provider'])->toBe('sendgrid')
        ->and($calls[1]['status'])->toBe('failed')
        ->and($calls[1]['error'])->toBe('Connection timeout');
});

test('all fake adapters implement their port interface', function () {
    $recorder = new IntegrationCallRecorder;

    expect(new FakeEmailAdapter($recorder))->toBeInstanceOf(EmailPort::class);
    expect(new FakeSignatureAdapter($recorder))->toBeInstanceOf(SignaturePort::class);
    expect(new FakeMessagingAdapter($recorder))->toBeInstanceOf(MessagingPort::class);
    expect(new FakeBankingAdapter($recorder))->toBeInstanceOf(BankingPort::class);
    expect(new FakeBiometricAdapter($recorder))->toBeInstanceOf(BiometricPort::class);
    expect(new FakeAiAdapter($recorder))->toBeInstanceOf(AiPort::class);
});

test('integration manager returns same adapter instance across calls', function () {
    $manager = app(IntegrationManager::class);

    // Should return the same singleton-scoped adapter
    $email1 = $manager->email();
    $email2 = $manager->email();

    expect($email1)->toBe($email2);
});

test('fake email adapter clear resets state', function () {
    $adapter = new FakeEmailAdapter($this->recorder);
    $adapter->send('a@empresa.pe', 'Sub', 'Body');

    expect($adapter->getSent())->toHaveCount(1);

    $adapter->clear();

    expect($adapter->getSent())->toHaveCount(0);
});
