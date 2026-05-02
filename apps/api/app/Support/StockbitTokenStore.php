<?php

namespace App\Support;

use App\Services\StockbitExodusClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class StockbitTokenStore
{
    private const DISK = 'local';
    private const PATH = 'stockbit_token.json';

    public function get(): ?string
    {
        if (!Storage::disk(self::DISK)->exists(self::PATH)) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get(self::PATH);
        $decoded = json_decode((string) $contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        $bearer = $this->extractBearer($decoded);

        if (!is_string($bearer) || $bearer === '') {
            return null;
        }

        if ($this->isExpired($bearer)) {
            $this->forget();

            return null;
        }

        return $bearer;
    }

    public function put(string $bearer): void
    {
        $payload = [
            'v' => 2,
            'bearer_encrypted' => Crypt::encryptString($bearer),
        ];

        Storage::disk(self::DISK)->put(
            self::PATH,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function forget(): void
    {
        Storage::disk(self::DISK)->delete(self::PATH);
    }

    public function expiresAt(?string $bearer): ?Carbon
    {
        $expiresAt = StockbitExodusClient::jwtExpiresAt($bearer);

        return $expiresAt ? Carbon::instance($expiresAt) : null;
    }

    public function isExpired(?string $bearer): bool
    {
        $expiresAt = $this->expiresAt($bearer);

        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function extractBearer(array $decoded): ?string
    {
        $encrypted = $decoded['bearer_encrypted'] ?? null;
        if (is_string($encrypted) && $encrypted !== '') {
            try {
                return Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                return null;
            }
        }

        $legacy = $decoded['bearer'] ?? null;
        if (!is_string($legacy) || $legacy === '') {
            return null;
        }

        try {
            return Crypt::decryptString($legacy);
        } catch (DecryptException) {
            return $legacy;
        }
    }
}
