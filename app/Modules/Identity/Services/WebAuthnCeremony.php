<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\UserPasskey;
use App\Modules\Identity\Models\WebAuthnChallenge;
use Illuminate\Validation\ValidationException;
use Throwable;

class WebAuthnCeremony
{
    private ?int $signatureCounter = null;

    public function __construct(private readonly WebAuthnRelyingParty $relyingParty) {}

    /**
     * @return array{options: object, challenge: string}
     */
    public function enrollmentOptions(User $user): array
    {
        $server = $this->relyingParty->server();
        $options = $server->getCreateArgs(
            $user->id,
            $user->email,
            $user->name,
            60,
            'required',
            'preferred',
            null,
            $user->passkeys()->pluck('credential_id')->map(fn (string $id): string => base64_decode($id, true) ?: '')->all(),
        );

        return ['options' => $options, 'challenge' => $server->getChallenge()->getBinaryString()];
    }

    /**
     * @return array{options: object, challenge: string}
     */
    public function loginOptions(): array
    {
        $server = $this->relyingParty->server();
        $options = $server->getGetArgs([], 60, true, true, true, true, true, 'preferred');

        return ['options' => $options, 'challenge' => $server->getChallenge()->getBinaryString()];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyEnrollment(WebAuthnChallenge $challenge, array $payload): PasskeyData
    {
        try {
            $clientDataJson = $this->decodeRequired($payload, 'clientDataJSON');
            $this->relyingParty->assertClientDataOrigin($clientDataJson);
            $result = $this->relyingParty->server()->processCreate(
                $clientDataJson,
                $this->decodeRequired($payload, 'attestationObject'),
                $challenge->binaryChallenge(),
                false,
                true,
                false,
            );

            return new PasskeyData(
                base64_encode($result->credentialId),
                $result->credentialPublicKey,
                is_int($result->signatureCounter) ? $result->signatureCounter : null,
                $this->transports($payload['transports'] ?? null),
            );
        } catch (Throwable) {
            throw ValidationException::withMessages(['passkey' => __('identity.generated.t_60d18f15114728d1')]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyLogin(UserPasskey $passkey, WebAuthnChallenge $challenge, array $payload): void
    {
        try {
            $clientDataJson = $this->decodeRequired($payload, 'clientDataJSON');
            $this->relyingParty->assertClientDataOrigin($clientDataJson);
            $server = $this->relyingParty->server();
            $server->processGet(
                $clientDataJson,
                $this->decodeRequired($payload, 'authenticatorData'),
                $this->decodeRequired($payload, 'signature'),
                $passkey->credential_public_key,
                $challenge->binaryChallenge(),
                $passkey->signature_counter,
                false,
                true,
            );
            $this->signatureCounter = $server->getSignatureCounter();
        } catch (Throwable) {
            throw ValidationException::withMessages(['passkey' => __('identity.generated.t_0352497aa8179233')]);
        }
    }

    public function signatureCounter(): ?int
    {
        return $this->signatureCounter;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function credentialIdFromPayload(array $payload): string
    {
        return base64_encode($this->decodeRequired($payload, 'id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function userHandleFromPayload(array $payload): ?string
    {
        if (! isset($payload['userHandle']) || ! is_string($payload['userHandle']) || $payload['userHandle'] === '') {
            return null;
        }

        return $this->decodeRequired($payload, 'userHandle');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function decodeRequired(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw ValidationException::withMessages(['passkey' => __('identity.generated.t_e12cf689bb8cd42e')]);
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw ValidationException::withMessages(['passkey' => __('identity.generated.t_5f8519365a6860a8')]);
        }

        return $decoded;
    }

    /**
     * @return array<int, string>|null
     */
    private function transports(mixed $transports): ?array
    {
        if (! is_array($transports)) {
            return null;
        }

        return collect($transports)
            ->filter(fn (mixed $transport): bool => is_string($transport) && in_array($transport, ['usb', 'nfc', 'ble', 'hybrid', 'internal'], true))
            ->values()
            ->all();
    }
}
