<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationAlert;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Automation\SchedulerDispatcher;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Support\StockbitTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The rule these defend: the bearer goes in and never comes back out, and a
 * bulk job never starts on a token that cannot survive it.
 */
class StockbitTokenApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['stockbit.bearer' => '', 'automation.timezone' => 'Asia/Jakarta']);
        ScheduledTask::query()->delete();
    }

    /**
     * A syntactically real JWT with the given expiry. Unsigned -- nothing in
     * this system verifies the signature, it only reads `exp`.
     */
    private function jwt(?Carbon $expiresAt, string $tail = 'abcd'): string
    {
        $encode = static fn (array $claims): string => rtrim(strtr(base64_encode(
            (string) json_encode($claims)
        ), '+/', '-_'), '=');

        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode($expiresAt === null ? ['sub' => 'x'] : ['exp' => $expiresAt->getTimestamp()]);

        return $header.'.'.$payload.'.'.'sig'.$tail;
    }

    private function store(string $token): void
    {
        app(StockbitTokenResolver::class)->persist($token);
    }

    public function test_a_healthy_token_is_reported_without_revealing_it(): void
    {
        $token = $this->jwt(Carbon::now()->addDays(3), 'wxyz');
        $this->store($token);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/automation/stockbit-token')->assertOk();

        $this->assertSame(StockbitTokenHealth::HEALTHY, $response->json('data.status'));
        $this->assertTrue($response->json('data.configured'));
        $this->assertSame('****wxyz', $response->json('data.fingerprint'));
        $this->assertStringNotContainsString($token, $response->getContent());
    }

    public function test_a_missing_token_is_reported_as_missing(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/automation/stockbit-token')->assertOk();

        $this->assertSame(StockbitTokenHealth::MISSING, $response->json('data.status'));
        $this->assertFalse($response->json('data.configured'));
        $this->assertFalse($response->json('data.can_start_bulk_job'));
    }

    public function test_an_expired_token_is_reported_as_expired(): void
    {
        // The store drops an expired token on read, so the resolver reports
        // nothing rather than something unusable -- either way a bulk job must
        // not start.
        app(StockbitTokenStore::class)->put($this->jwt(Carbon::now()->subHour()));

        $status = app(StockbitTokenHealth::class)->status();

        $this->assertContains($status['status'], [StockbitTokenHealth::EXPIRED, StockbitTokenHealth::MISSING]);
        $this->assertFalse($status['can_start_bulk_job']);
    }

    public function test_an_expiring_soon_token_is_flagged_but_still_usable_above_the_minimum(): void
    {
        config(['automation.stockbit.warn_ttl_minutes' => 720, 'automation.stockbit.min_ttl_minutes' => 90]);

        $this->store($this->jwt(Carbon::now()->addHours(4)));

        $status = app(StockbitTokenHealth::class)->status();

        $this->assertSame(StockbitTokenHealth::EXPIRING_SOON, $status['status']);
        $this->assertTrue($status['can_start_bulk_job'], '4h is above the 90 minute floor.');
    }

    public function test_a_token_below_the_minimum_ttl_cannot_start_a_bulk_job(): void
    {
        config(['automation.stockbit.min_ttl_minutes' => 90]);

        $this->store($this->jwt(Carbon::now()->addMinutes(20)));

        $preflight = app(StockbitTokenHealth::class)->preflight();

        $this->assertFalse($preflight['ok']);
        $this->assertSame('token_ttl_too_short', $preflight['reason']);
    }

    public function test_a_token_without_an_expiry_claim_is_reported_as_unknown(): void
    {
        $this->store($this->jwt(null));

        $status = app(StockbitTokenHealth::class)->status();

        $this->assertSame(StockbitTokenHealth::UNKNOWN_EXPIRY, $status['status']);
        $this->assertTrue($status['configured']);
        $this->assertNull($status['expires_at']);
    }

    public function test_a_scheduled_stockbit_job_is_blocked_before_it_scrapes(): void
    {
        $task = ScheduledTask::query()->create([
            'name' => 'Daily',
            'slug' => 'daily',
            'command' => 'automation:ohlcv-daily',
            'parameters' => ['arguments' => [], 'options' => []],
            'cron_expression' => '0 16 * * *',
            'timezone' => 'Asia/Jakarta',
            'condition' => ScheduledTask::CONDITION_NONE,
            'enabled' => true,
        ]);

        // No token stored at all.
        app(SchedulerDispatcher::class)->dispatch(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        $run = ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->sole();

        $this->assertSame(ScheduledTaskRun::STATUS_BLOCKED_TOKEN, $run->status);
        $this->assertSame('token_missing', $run->skip_reason);
        $this->assertNull($run->exit_code, 'The scraper must never have been invoked.');
        $this->assertStringContainsString('Renew', (string) $run->error);

        // And the operator is told, persistently.
        $alert = AutomationAlert::query()->where('type', AutomationAlert::TYPE_STOCKBIT_TOKEN)->sole();
        $this->assertNull($alert->resolved_at);
        $this->assertSame(AutomationAlert::SEVERITY_CRITICAL, $alert->severity);
    }

    public function test_renewal_persists_through_the_existing_encrypted_store(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $token = $this->jwt(Carbon::now()->addDays(2), 'p0rt');

        $response = $this->putJson('/api/v1/automation/stockbit-token', ['token' => $token])->assertOk();

        $this->assertSame('****p0rt', $response->json('data.fingerprint'));
        $this->assertStringNotContainsString($token, $response->getContent());

        // Read back through the existing resolver, and confirm what landed on
        // disk is encrypted rather than the bearer in the clear.
        $this->assertSame($token, app(StockbitTokenResolver::class)->resolve());

        $raw = Storage::disk('local')->get('stockbit_token.json');
        $this->assertStringNotContainsString($token, (string) $raw);
        $this->assertStringContainsString('bearer_encrypted', (string) $raw);
    }

    public function test_renewal_accepts_a_pasted_bearer_prefix(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $token = $this->jwt(Carbon::now()->addDay());

        $this->putJson('/api/v1/automation/stockbit-token', ['token' => 'Bearer '.$token])->assertOk();

        $this->assertSame($token, app(StockbitTokenResolver::class)->resolve());
    }

    public function test_renewal_rejects_an_already_expired_token(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/automation/stockbit-token', [
            'token' => $this->jwt(Carbon::now()->subMinute()),
        ])->assertStatus(422);

        $this->assertNull(app(StockbitTokenResolver::class)->resolve());
    }

    public function test_renewal_rejects_something_that_is_not_a_jwt(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/automation/stockbit-token', [
            'token' => 'this-is-definitely-not-a-jwt-value',
        ])->assertStatus(422);
    }

    public function test_renewal_clears_an_open_reminder(): void
    {
        Sanctum::actingAs(User::factory()->create());

        AutomationAlert::query()->create([
            'type' => AutomationAlert::TYPE_STOCKBIT_TOKEN,
            'key' => 'renewal-required',
            'severity' => AutomationAlert::SEVERITY_CRITICAL,
            'title' => 'Stockbit token needs renewing',
            'message' => 'gone',
        ]);

        $this->putJson('/api/v1/automation/stockbit-token', [
            'token' => $this->jwt(Carbon::now()->addDays(5)),
        ])->assertOk();

        $this->assertNotNull(AutomationAlert::query()->sole()->resolved_at);
    }

    public function test_the_token_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/automation/stockbit-token')->assertStatus(401);
        $this->putJson('/api/v1/automation/stockbit-token', ['token' => 'x'])->assertStatus(401);
    }

    public function test_the_daily_reminder_does_not_duplicate_itself(): void
    {
        $this->artisan('automation:token-check')->assertExitCode(0);
        $this->artisan('automation:token-check')->assertExitCode(0);
        $this->artisan('automation:token-check')->assertExitCode(0);

        $this->assertSame(1, AutomationAlert::query()->count());
    }

    public function test_a_healthy_token_clears_the_reminder(): void
    {
        $this->artisan('automation:token-check');
        $this->assertSame(1, AutomationAlert::query()->open()->count());

        $this->store($this->jwt(Carbon::now()->addDays(10)));

        $this->artisan('automation:token-check')->assertExitCode(0);

        $this->assertSame(0, AutomationAlert::query()->open()->count());
    }
}
