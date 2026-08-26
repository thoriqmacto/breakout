<?php

namespace Tests\Unit;

use App\Services\GoogleDriveOAuthClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleDriveOAuthClassifierTest extends TestCase
{
    private GoogleDriveOAuthClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new GoogleDriveOAuthClassifier;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function errorProvider(): array
    {
        return [
            'dead refresh token' => [
                'Google Drive OAuth failed: invalid_grant (Token has been expired or revoked.)',
                GoogleDriveOAuthClassifier::RENEW_REQUIRED,
                'renew_required',
            ],
            // Google answers a wrong client secret with
            // {"error":"invalid_client","error_description":"Unauthorized"}.
            'wrong client secret' => [
                'Google Drive OAuth failed: invalid_client (Unauthorized)',
                GoogleDriveOAuthClassifier::INVALID_CLIENT,
                'unknown',
            ],
            'bare unauthorized' => [
                'Google Drive OAuth failed: Unauthorized',
                GoogleDriveOAuthClassifier::INVALID_CLIENT,
                'unknown',
            ],
            'api not enabled' => [
                'Google Drive API has not been used in project 123 before or it is disabled.',
                GoogleDriveOAuthClassifier::API_DISABLED,
                'valid',
            ],
            'missing scope' => [
                'Request had insufficient authentication scopes. insufficientPermissions',
                GoogleDriveOAuthClassifier::SCOPE_ERROR,
                'valid',
            ],
            'folder gone' => [
                'File not found: 1a2b3c. notFound',
                GoogleDriveOAuthClassifier::DRIVE_ERROR,
                'valid',
            ],
            'no network' => [
                'cURL error 6: Could not resolve host: oauth2.googleapis.com',
                GoogleDriveOAuthClassifier::UNREACHABLE,
                'unknown',
            ],
            'credential not set' => [
                'The gdrive disk requires GOOGLE_DRIVE_REFRESH_TOKEN.',
                GoogleDriveOAuthClassifier::NOT_CONFIGURED,
                'not_configured',
            ],
            'something else entirely' => [
                'Something nobody has seen before',
                GoogleDriveOAuthClassifier::UNKNOWN_ERROR,
                'unknown',
            ],
        ];
    }

    #[DataProvider('errorProvider')]
    public function test_it_classifies_google_errors(
        string $error,
        string $expectedStatus,
        string $expectedTokenStatus,
    ): void {
        $verdict = $this->classifier->classify($error);

        $this->assertSame($expectedStatus, $verdict['status']);
        $this->assertSame($expectedTokenStatus, $verdict['refresh_token_status']);
        $this->assertNotSame('', $verdict['message']);
        $this->assertNotEmpty($verdict['guidance'], 'A failure must come with something to do about it.');
    }

    /**
     * A network fault is the case most easily mistaken for a dead token. The
     * remedy is completely different, so it must never say renew.
     */
    public function test_a_network_failure_does_not_blame_the_refresh_token(): void
    {
        $verdict = $this->classifier->classify('cURL error 28: Connection timed out after 30000 ms');

        $this->assertSame('unknown', $verdict['refresh_token_status']);
        $this->assertNotSame(GoogleDriveOAuthClassifier::RENEW_REQUIRED, $verdict['status']);
        $this->assertStringNotContainsStringIgnoringCase('regenerate', implode(' ', $verdict['guidance']));
    }

    /**
     * The mistake this class exists to prevent: sending the operator to mint a
     * new refresh token when the client secret is what is wrong.
     */
    public function test_a_rejected_client_does_not_advise_regenerating_the_token(): void
    {
        $verdict = $this->classifier->classify('invalid_client (Unauthorized)');
        $guidance = implode(' ', $verdict['guidance']);

        $this->assertStringContainsString('GOOGLE_DRIVE_CLIENT_SECRET', $guidance);
        $this->assertStringNotContainsString('GOOGLE_DRIVE_REFRESH_TOKEN', $guidance);
    }

    public function test_invalid_grant_wins_over_a_generic_permission_word(): void
    {
        // Google's rejection of a dead token can carry both signals at once.
        $verdict = $this->classifier->classify('403 Forbidden: invalid_grant');

        $this->assertSame(GoogleDriveOAuthClassifier::RENEW_REQUIRED, $verdict['status']);
    }

    public function test_healthy_carries_no_guidance(): void
    {
        $verdict = $this->classifier->healthy();

        $this->assertSame(GoogleDriveOAuthClassifier::HEALTHY, $verdict['status']);
        $this->assertSame('valid', $verdict['refresh_token_status']);
        $this->assertSame([], $verdict['guidance']);
    }
}
