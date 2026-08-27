<?php

namespace Tests\Unit\Services\Automation;

use App\Services\Automation\OutputSanitizer;
use Tests\TestCase;

/**
 * Captured Artisan output is served to a browser and stored indefinitely, so
 * it has to be both bounded and free of anything credential-shaped.
 */
class OutputSanitizerTest extends TestCase
{
    private OutputSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new OutputSanitizer;
    }

    public function test_a_bearer_header_is_redacted(): void
    {
        $output = $this->sanitizer->redact(
            'GET /x with authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjF9.signature'
        );

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $output);
        $this->assertStringContainsString('[redacted]', $output);
    }

    public function test_a_bare_jwt_is_redacted(): void
    {
        $output = $this->sanitizer->redact('token is eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjE3MH0.abc123 ok');

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $output);
    }

    public function test_key_value_credentials_are_redacted(): void
    {
        foreach ([
            'token=abcdef123456',
            'secret: hunter2',
            'password = swordfish',
            'api_key=xyz',
        ] as $line) {
            $this->assertStringContainsString('[redacted]', $this->sanitizer->redact($line));
        }
    }

    public function test_ordinary_output_is_left_alone(): void
    {
        $output = 'Fetching historical BBCA 2026-08-28 → 2026-08-28';

        $this->assertSame($output, $this->sanitizer->redact($output));
    }

    public function test_output_is_truncated_from_the_front_keeping_the_tail(): void
    {
        config(['automation.max_output_length' => 100]);

        $long = str_repeat('a', 500).'THE FAILURE IS HERE';

        $result = (string) $this->sanitizer->sanitize($long);

        // The tail is where a failing run says why, so that is the half kept.
        $this->assertStringContainsString('THE FAILURE IS HERE', $result);
        $this->assertStringContainsString('earlier characters omitted', $result);
        $this->assertLessThan(mb_strlen($long), mb_strlen($result));
    }

    public function test_short_output_is_returned_unchanged(): void
    {
        config(['automation.max_output_length' => 20000]);

        $this->assertSame('done', $this->sanitizer->sanitize('done'));
        $this->assertNull($this->sanitizer->sanitize(null));
    }
}
