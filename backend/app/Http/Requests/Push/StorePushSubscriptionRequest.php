<?php

namespace App\Http\Requests\Push;

use App\Domain\Push\PushEndpoints;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Abonnement push de l'appareil (contrat du lot 8, §2.2), forme de
 * PushSubscription.toJSON() du navigateur. Tous les rôles.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    /** Longueurs décodées des clés de l'appareil (RFC 8291) : point P-256 non compressé, secret. */
    private const P256DH_BYTES = 65;

    private const AUTH_BYTES = 16;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['bail', 'required', 'string', 'max:500', 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
                if (! PushEndpoints::allowed($value)) {
                    $fail(__('push.endpoint_host'));
                }
            }],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['bail', 'required', 'string', 'max:255', self::base64UrlBytes(self::P256DH_BYTES)],
            'keys.auth' => ['bail', 'required', 'string', 'max:255', self::base64UrlBytes(self::AUTH_BYTES)],
            'content_encoding' => ['nullable', 'string', Rule::in(['aes128gcm', 'aesgcm'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('push.attributes');
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding: string}
     */
    public function subscription(): array
    {
        return [
            'endpoint' => (string) $this->validated('endpoint'),
            'keys' => [
                'p256dh' => (string) $this->validated('keys.p256dh'),
                'auth' => (string) $this->validated('keys.auth'),
            ],
            'content_encoding' => (string) ($this->validated('content_encoding') ?? 'aes128gcm'),
        ];
    }

    private static function base64UrlBytes(int $bytes): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($bytes): void {
            if (PushEndpoints::base64UrlLength($value) !== $bytes) {
                $fail(__('push.key_length', ['bytes' => $bytes]));
            }
        };
    }
}
