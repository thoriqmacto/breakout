<?php

namespace App\Support;

use App\Services\StockbitExodusClient;
use Illuminate\Support\Carbon;
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
        $decoded = json_decode($contents, true);
        $bearer = is_array($decoded) ? ($decoded['bearer'] ?? null) : null;

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
        Storage::disk(self::DISK)->put(
            self::PATH,
            json_encode(['bearer' => $bearer], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
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
}
