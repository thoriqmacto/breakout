<?php

namespace App\Services;

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
        $this->bearer = (string) ($cfg['bearer'] ?? '');
        $this->defaults = $cfg['defaults'] ?? [];

        $this->http = new Client([
            'base_uri' => rtrim((string) ($cfg['base_url'] ?? ''), '/') . '/',
            'headers' => [
                'accept' => 'application/json',
                'origin' => 'https://stockbit.com',
                'referer' => 'https://stockbit.com/',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari',
            ],
            'http_errors' => false,
            'timeout' => 30,
        ]);
    }

    public function marketDetectors(
        string $symbol,
        string $from,
        string $to,
        ?string $transactionType = null,
        ?string $marketBoard = null,
        ?string $investorType = null,
        ?int $limit = null
    ): array {
        $q = [
            'from' => $from,
            'to'   => $to,
            'transaction_type' => $transactionType ?: ($this->defaults['transaction_type'] ?? ''),
            'market_board'     => $marketBoard     ?: ($this->defaults['market_board'] ?? ''),
            'investor_type'    => $investorType    ?: ($this->defaults['investor_type'] ?? ''),
            'limit'            => $limit ?? ($this->defaults['limit'] ?? 25),
        ];

        $headers = ['authorization' => 'Bearer ' . $this->bearer];

        try {
            $res = $this->http->get("marketdetectors/{$symbol}", [
                'headers' => $headers,
                'query'   => $q,
            ]);
        } catch (RequestException $e) {
            return ['error' => 'network_error', 'message' => $e->getMessage()];
        }

        $status = $res->getStatusCode();
        $body   = (string) $res->getBody();
        $json   = json_decode($body, true);

        if ($status === 401) {
            return ['error' => 'unauthorized', 'message' => 'JWT expired or invalid (401). Replace STOCKBIT_BEARER.'];
        }
        if ($status >= 400) {
            return ['error' => 'http_' . $status, 'message' => $body ?: 'HTTP error'];
        }

        return is_array($json) ? $json : ['raw' => $body];
    }

    public static function jwtExpiresAt(?string $jwt): ?\DateTimeImmutable
    {
        if (!$jwt || !str_contains($jwt, '.')) {
            return null;
        }

        [, $payloadB64] = explode('.', $jwt);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        if (!isset($payload['exp'])) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) $payload['exp']);
    }
}
