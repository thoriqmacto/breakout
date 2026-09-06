<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards how the web app is allowed to call this API.
 *
 * The web app has no test runner of its own -- CI runs eslint, tsc and the
 * Next build -- and none of those can fail on a fetch that is perfectly valid
 * TypeScript and wrong at runtime. So the assertion lives here, where it runs,
 * following the same reasoning as DashboardNavigationTest.
 *
 * It exists because of a real failure. The headless-login card was written
 * with `credentials: "include"`, as though this API authenticated with a
 * session cookie. It does not -- it takes a bearer token -- and worse, a
 * credentialed request whose response carries no
 * `Access-Control-Allow-Credentials` header is rejected by the browser before
 * the response is ever delivered. `ConfigureCors` never sets that header, so
 * the card failed with an opaque "Failed to fetch" that looked like a server
 * or network problem and was neither.
 */
class FrontendApiCallConventionTest extends TestCase
{
    /**
     * @return array<string, string> file path => contents
     */
    private function clientSources(): array
    {
        $root = realpath(base_path('../web'));

        $this->assertNotFalse($root, 'The web app moved; update this guard.');

        $sources = [];

        foreach (['components', 'lib', 'app'] as $directory) {
            $path = $root.'/'.$directory;

            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                    continue;
                }

                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        $this->assertNotEmpty($sources, 'No web sources were found; update this guard.');

        return $sources;
    }

    /**
     * Credentialed requests need a header the CORS middleware does not send.
     *
     * If credential support is ever added deliberately, this test should be
     * updated alongside it rather than deleted -- the point is that the two
     * sides agree, not that cookies are forbidden forever.
     */
    public function test_no_client_fetch_asks_the_browser_to_send_credentials(): void
    {
        $middleware = (string) file_get_contents(
            app_path('Http/Middleware/ConfigureCors.php'),
        );

        $this->assertStringNotContainsString(
            'Access-Control-Allow-Credentials',
            $middleware,
            'Credential support was added to CORS; this guard needs revisiting.',
        );

        foreach ($this->clientSources() as $path => $contents) {
            $this->assertStringNotContainsString(
                'credentials: "include"',
                $contents,
                sprintf(
                    '%s asks the browser to send credentials, but ConfigureCors never returns '
                    .'Access-Control-Allow-Credentials, so the request fails CORS. This API '
                    .'authenticates with a bearer token: send an Authorization header instead.',
                    basename($path),
                ),
            );
        }
    }

    /**
     * A POST to an authenticated endpoint has to carry the bearer.
     *
     * Checked on the login card specifically because it is the one that got
     * this wrong, and because its failure mode -- a CORS error rather than a
     * 401 -- gives no hint that authentication was the missing piece.
     */
    public function test_the_browser_login_card_sends_an_authorization_header(): void
    {
        $path = base_path('../web/components/browser-login-card.tsx');

        $this->assertFileExists($path, 'The headless-login card moved; update this guard.');

        $card = (string) file_get_contents($path);

        $this->assertStringContainsString('browser-login', $card);
        $this->assertStringContainsString(
            'Authorization: `Bearer ${accessToken}`',
            $card,
            'The headless-login card must send the bearer token, like every other call.',
        );
        $this->assertStringContainsString(
            'useAuth()',
            $card,
            'The card should take its token from the auth provider rather than inventing one.',
        );
    }
}
