<?php

namespace Tests\Unit;

use App\Services\StockbitExodusClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class StockbitExodusClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('stockbit.bearer', 'test-bearer-token');
        config()->set('stockbit.base_url', 'https://example.test/api');
        config()->set('stockbit.defaults', [
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'market_board'     => 'MARKET_BOARD_REGULER',
            'investor_type'    => 'INVESTOR_TYPE_ALL',
            'limit'            => 25,
        ]);
    }

    private function makeClientWith(MockHandler $handler): StockbitExodusClient
    {
        $client = new StockbitExodusClient();

        $reflection = new \ReflectionProperty($client, 'http');
        $reflection->setAccessible(true);
        $reflection->setValue($client, new Client([
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
        ]));

        return $client;
    }

    public function test_market_detectors_returns_decoded_json(): void
    {
        $capturedRequest = null;
        $mock = new MockHandler([
            function ($request, array $options) use (&$capturedRequest) {
                $capturedRequest = $request;

                return new Response(200, [], json_encode([
                    'items' => [
                        ['date' => '2024-01-01', 'broker' => 'AA', 'buy_value' => 100, 'sell_value' => 40, 'net_value' => 60],
                    ],
                ]));
            },
        ]);

        $client = $this->makeClientWith($mock);

        $result = $client->marketDetectors('BBCA', '2024-01-01', '2024-01-05', limit: 50);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertNotNull($capturedRequest);
        parse_str($capturedRequest->getUri()->getQuery(), $query);

        $this->assertSame('BBCA', basename($capturedRequest->getUri()->getPath()));
        $this->assertSame('Bearer test-bearer-token', $capturedRequest->getHeaderLine('authorization'));
        $this->assertSame('TRANSACTION_TYPE_NET', $query['transaction_type']);
        $this->assertSame('MARKET_BOARD_REGULER', $query['market_board']);
        $this->assertSame('INVESTOR_TYPE_ALL', $query['investor_type']);
        $this->assertSame('50', $query['limit']);
        $this->assertArrayNotHasKey('page', $query);
    }

    public function test_market_detectors_includes_page_when_requested(): void
    {
        $capturedRequest = null;
        $mock = new MockHandler([
            function ($request) use (&$capturedRequest) {
                $capturedRequest = $request;

                return new Response(200, [], json_encode(['items' => []]));
            },
        ]);

        $client = $this->makeClientWith($mock);

        $client->marketDetectors('BBNI', '2024-02-01', '2024-02-05', page: 3);

        $this->assertNotNull($capturedRequest);
        parse_str($capturedRequest->getUri()->getQuery(), $query);

        $this->assertSame('3', $query['page']);
    }

    public function test_market_detectors_returns_error_on_unauthorized(): void
    {
        $mock = new MockHandler([
            new Response(401, [], 'Unauthorized'),
        ]);

        $client = $this->makeClientWith($mock);

        $result = $client->marketDetectors('BBRI', '2024-01-01', '2024-01-05');

        $this->assertSame('unauthorized', $result['error']);
        $this->assertStringContainsString('Replace STOCKBIT_BEARER', $result['message']);
    }

    public function test_market_detectors_returns_error_on_network_exception(): void
    {
        $mock = new MockHandler([
            function () {
                throw new RequestException('boom', new Request('GET', 'marketdetectors/FAIL'));
            },
        ]);

        $client = $this->makeClientWith($mock);

        $result = $client->marketDetectors('FAIL', '2024-01-01', '2024-01-02');

        $this->assertSame('network_error', $result['error']);
        $this->assertStringContainsString('boom', $result['message']);
    }

    public function test_ticker_profile_returns_decoded_json(): void
    {
        $capturedRequest = null;
        $mock = new MockHandler([
            function ($request) use (&$capturedRequest) {
                $capturedRequest = $request;

                return new Response(200, [], json_encode([
                    'code' => 'BBCA',
                    'name' => 'Bank Central Asia Tbk',
                ]));
            },
        ]);

        $client = $this->makeClientWith($mock);

        $result = $client->tickerProfile('BBCA');

        $this->assertIsArray($result);
        $this->assertSame('BBCA', $result['code']);
        $this->assertNotNull($capturedRequest);
        $this->assertSame('Bearer test-bearer-token', $capturedRequest->getHeaderLine('authorization'));
        $this->assertSame('/emitten/BBCA/profile', $capturedRequest->getUri()->getPath());
    }

    public function test_jwt_expires_at_handles_valid_and_invalid_tokens(): void
    {
        $future = time() + 3600;
        $payload = base64_encode(json_encode(['exp' => $future]));
        $jwt = 'abc.' . rtrim(strtr($payload, '+/', '-_'), '=') . '.def';

        $this->assertInstanceOf(\DateTimeImmutable::class, StockbitExodusClient::jwtExpiresAt($jwt));
        $this->assertNull(StockbitExodusClient::jwtExpiresAt(null));
        $this->assertNull(StockbitExodusClient::jwtExpiresAt('invalid'));
    }
}
