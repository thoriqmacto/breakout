<?php

namespace App\Services\Stockbit;

use App\Services\StockbitExodusClient;
use App\Support\StockbitTokenStore;
use Illuminate\Support\Facades\Cache;

class StockbitTokenResolver
{
    public const SOURCE_OPTION = 'option';
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_STORE = 'store';
    public const SOURCE_CACHE = 'cache';
    public const SOURCE_NONE = 'none';

    private const CACHE_KEY = 'stockbit.token';

    public function __construct(private readonly StockbitTokenStore $store)
    {
    }

    /**
     * Resolve a Stockbit bearer token using the documented priority order:
     * CLI option → env/config → file store → cache. Returns the first
     * non-empty, non-expired token, or null when none is available.
     */
    public function resolve(?string $cliToken = null): ?string
    {
        $optionToken = is_string($cliToken) ? trim($cliToken) : '';
        if ($optionToken !== '' && !$this->store->isExpired($optionToken)) {
            return $optionToken;
        }

        $configToken = trim((string) config('stockbit.bearer', ''));
        if ($configToken !== '' && !$this->store->isExpired($configToken)) {
            return $configToken;
        }

        $stored = $this->store->get();
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '' && !$this->store->isExpired($cached)) {
            return $cached;
        }

        return null;
    }

    /**
     * Resolve and report the source the token came from. Useful for status
     * commands and debugging — never logs the token itself.
     *
     * @return array{token: ?string, source: string}
     */
    public function resolveWithSource(?string $cliToken = null): array
    {
        $optionToken = is_string($cliToken) ? trim($cliToken) : '';
        if ($optionToken !== '' && !$this->store->isExpired($optionToken)) {
            return ['token' => $optionToken, 'source' => self::SOURCE_OPTION];
        }

        $configToken = trim((string) config('stockbit.bearer', ''));
        if ($configToken !== '' && !$this->store->isExpired($configToken)) {
            return ['token' => $configToken, 'source' => self::SOURCE_CONFIG];
        }

        $stored = $this->store->get();
        if (is_string($stored) && $stored !== '') {
            return ['token' => $stored, 'source' => self::SOURCE_STORE];
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '' && !$this->store->isExpired($cached)) {
            return ['token' => $cached, 'source' => self::SOURCE_CACHE];
        }

        return ['token' => null, 'source' => self::SOURCE_NONE];
    }

    /**
     * Persist a token to the file store and mirror it to cache so
     * subsequent process invocations can reuse it without disk I/O.
     */
    public function persist(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        $this->store->put($token);

        $expiresAt = StockbitExodusClient::jwtExpiresAt($token);
        if ($expiresAt !== null) {
            $ttlSeconds = $expiresAt->getTimestamp() - time();
            if ($ttlSeconds > 0) {
                Cache::put(self::CACHE_KEY, $token, $ttlSeconds);
            } else {
                Cache::forget(self::CACHE_KEY);
            }
        } else {
            Cache::put(self::CACHE_KEY, $token, 3600);
        }
    }

    public function forget(): void
    {
        $this->store->forget();
        Cache::forget(self::CACHE_KEY);
    }

    public function isExpired(?string $token): bool
    {
        return $this->store->isExpired($token);
    }

    public function expiresAt(?string $token): ?\Illuminate\Support\Carbon
    {
        return $this->store->expiresAt($token);
    }

    public function store(): StockbitTokenStore
    {
        return $this->store;
    }
}
