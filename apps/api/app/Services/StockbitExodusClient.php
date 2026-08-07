<?php

namespace App\Services;

use App\Services\Stockbit\StockbitTokenResolver;
use App\Support\StockbitTokenStore;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class StockbitExodusClient
{
    private Client $http;

    private string $bearer;

    private array $defaults;

    public function __construct()
    {
        $cfg = config('stockbit');
        $this->bearer = $this->resolveBearer((string) ($cfg['bearer'] ?? ''));
        $this->defaults = $cfg['defaults'] ?? [];

        $this->http = new Client([
            'base_uri' => rtrim((string) ($cfg['base_url'] ?? ''), '/').'/',
            'headers' => [
                'accept' => 'application/json',
                'origin' => 'https://stockbit.com',
                'referer' => 'https://stockbit.com/',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            ],
            'http_errors' => false,
            'timeout' => 30,
        ]);
    }

    public function setBearer(string $bearer): void
    {
        $this->bearer = $bearer;
    }

    private function resolveBearer(string $configBearer): string
    {
        try {
            /** @var StockbitTokenResolver $resolver */
            $resolver = app(StockbitTokenResolver::class);
            $resolved = $resolver->resolve();
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        } catch (\Throwable) {
            // fall through to legacy resolution
        }

        try {
            /** @var StockbitTokenStore $store */
            $store = app(StockbitTokenStore::class);
            $stored = $store->get();

            return $stored ?? $configBearer;
        } catch (\Throwable) {
            return $configBearer;
        }
    }

    public function marketDetectors(
        string $symbol,
        string $from,
        string $to,
        ?string $transactionType = null,
        ?string $marketBoard = null,
        ?string $investorType = null,
        ?int $limit = null,
        ?int $page = null
    ): array {
        $q = [
            'from' => $from,
            'to' => $to,
            'transaction_type' => $transactionType ?: ($this->defaults['transaction_type'] ?? ''),
            'market_board' => $marketBoard ?: ($this->defaults['market_board'] ?? ''),
            'investor_type' => $investorType ?: ($this->defaults['investor_type'] ?? ''),
            'limit' => $limit ?? ($this->defaults['limit'] ?? 25),
        ];

        if ($page !== null) {
            $q['page'] = $page;
        }

        $headers = ['authorization' => 'Bearer '.$this->bearer];

        try {
            $res = $this->http->get("/marketdetectors/{$symbol}", [
                'headers' => $headers,
                'query' => $q,
            ]);
        } catch (RequestException $e) {
            return ['error' => 'network_error', 'message' => $e->getMessage()];
        }

        $status = $res->getStatusCode();
        $body = (string) $res->getBody();
        $json = json_decode($body, true);

        if ($status === 401) {
            return ['error' => 'unauthorized', 'message' => 'JWT expired or invalid (401). Replace STOCKBIT_BEARER.'];
        }
        if ($status >= 400) {
            return ['error' => 'http_'.$status, 'message' => $body ?: 'HTTP error'];
        }

        return is_array($json) ? $json : ['raw' => $body];
    }

    public static function jwtExpiresAt(?string $jwt): ?\DateTimeImmutable
    {
        if (! $jwt || ! str_contains($jwt, '.')) {
            return null;
        }

        [, $payloadB64] = explode('.', $jwt);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        if (! isset($payload['exp'])) {
            return null;
        }

        return (new \DateTimeImmutable)->setTimestamp((int) $payload['exp']);
    }

    public function historicalSummary(
        string $symbol,
        string $period,
        string $startDate,
        string $endDate,
        ?int $limit = null,
        ?int $page = null
    ): array {
        $q = array_filter([
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => $limit,
            'page' => $page,
        ], static fn ($value) => $value !== null && $value !== '');

        $headers = ['authorization' => 'Bearer '.$this->bearer];

        try {
            $res = $this->http->get("/company-price-feed/historical/summary/{$symbol}", [
                'headers' => $headers,
                'query' => $q,
            ]);
        } catch (RequestException $e) {
            return ['error' => 'network_error', 'message' => $e->getMessage()];
        }

        $status = $res->getStatusCode();
        $body = (string) $res->getBody();
        $json = json_decode($body, true);

        if ($status === 401) {
            return ['error' => 'unauthorized', 'message' => 'JWT expired or invalid (401). Replace STOCKBIT_BEARER.'];
        }
        if ($status >= 400) {
            return ['error' => 'http_'.$status, 'message' => $body ?: 'HTTP error'];
        }

        return is_array($json) ? $json : ['raw' => $body];
    }

    public function tickerProfile(string $symbol): array
    {
        $headers = ['authorization' => 'Bearer '.$this->bearer];

        try {
            $res = $this->http->get("/emitten/{$symbol}/profile", [
                'headers' => $headers,
            ]);
        } catch (RequestException $e) {
            return ['error' => 'network_error', 'message' => $e->getMessage()];
        }

        $status = $res->getStatusCode();
        $body = (string) $res->getBody();
        $json = json_decode($body, true);

        if ($status === 401) {
            return ['error' => 'unauthorized', 'message' => 'JWT expired or invalid (401). Replace STOCKBIT_BEARER.'];
        }
        if ($status >= 400) {
            return ['error' => 'http_'.$status, 'message' => $body ?: 'HTTP error'];
        }

        return is_array($json) ? $json : ['raw' => $body];
    }

    public function watchlist($watchlistId, array $query = []): array
    {
        $headers = ['authorization' => 'Bearer '.$this->bearer];
        $q = array_filter($query, static fn ($value) => $value !== null && $value !== '');

        try {
            $res = $this->http->get("/watchlist/{$watchlistId}", [
                'headers' => $headers,
                'query' => $q,
            ]);
        } catch (RequestException $e) {
            return ['error' => 'network_error', 'message' => $e->getMessage()];
        }

        $status = $res->getStatusCode();
        $body = (string) $res->getBody();
        $json = json_decode($body, true);

        if ($status === 401) {
            return ['error' => 'unauthorized', 'message' => 'JWT expired or invalid (401). Replace STOCKBIT_BEARER.'];
        }
        if ($status >= 400) {
            return ['error' => 'http_'.$status, 'message' => $body ?: 'HTTP error'];
        }

        return is_array($json) ? $json : ['raw' => $body];
    }

    public function watchlistColumn($watchlistId, $itemId): array
    {
        $headers = ['authorization' => 'Bearer '.$this->bearer];
        $q = ['fitemid' => $itemId];

        try {
            $res = $this->http->get("/watchlist/{$watchlistId}/column", [
                'headers' => $headers,
                'query' => $q,
            ]);
        } catch (RequestException $e) {
            return ['error' => 'network_error', 'message' => $e->getMessage()];
        }

        $status = $res->getStatusCode();
        $body = (string) $res->getBody();
        $json = json_decode($body, true);

        if ($status === 401) {
            return ['error' => 'unauthorized', 'message' => 'JWT expired or invalid (401). Replace STOCKBIT_BEARER.'];
        }
        if ($status >= 400) {
            return ['error' => 'http_'.$status, 'message' => $body ?: 'HTTP error'];
        }

        return is_array($json) ? $json : ['raw' => $body];
    }
}
