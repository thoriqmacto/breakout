<?php

namespace Tests\Feature;

use App\Services\Stockbit\BrowserTokenExtractor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * An unwritable profile says who owns it, not how to share it.
 *
 * The message used to advise a shared group and `chmod g+rwxs`. That does not
 * work: setgid propagates the group to new files and never the mode, and
 * Chromium writes its cookies and session state owner-only -- so a second user
 * in the group can create files in the directory and still not read the ones
 * carrying the session. Worse, it reads as a working recipe, so the login
 * looks configured and silently reuses nothing.
 *
 * A profile belongs to one Unix user, and the instruction that helps is which
 * user that is.
 *
 * The message is built directly rather than provoked through an unwritable
 * directory: these tests run as root, and no directory mode refuses root, so
 * that route would only ever skip.
 */
class BrowserProfileOwnershipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/breakout-profile-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root);

        parent::tearDown();
    }

    private function message(string $path): string
    {
        $method = new ReflectionMethod(BrowserTokenExtractor::class, 'profileOwnershipMessage');

        return (string) $method->invoke(app(BrowserTokenExtractor::class), $path);
    }

    public function test_it_names_the_owner_and_the_command_to_run_as_them(): void
    {
        // A profile owned by someone other than the caller -- the production
        // shape, where the scheduler owns it and a person runs the command.
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0 || ! @chown($this->root, 1)) {
            $this->markTestSkipped('Cannot give the directory a different owner here.');
        }

        $message = $this->message($this->root);

        $this->assertStringContainsString($this->root, $message);
        $this->assertStringContainsString('sudo -u', $message);
        $this->assertStringContainsString('browser:token', $message);
        $this->assertStringContainsString('one Unix user', $message);
    }

    /**
     * The advice that does not work must not come back, in either branch.
     */
    public function test_it_never_recommends_a_shared_group(): void
    {
        $message = $this->message($this->root);

        $this->assertStringNotContainsString('g+rwxs', $message);
        $this->assertStringNotContainsString('shared group', $message);
        $this->assertStringContainsString('one Unix user', $message);
    }
}
