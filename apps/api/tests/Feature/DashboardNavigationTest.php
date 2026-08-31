<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the dashboard navigation against dead placeholders.
 *
 * The web app has no test runner of its own -- CI runs eslint, tsc and the
 * Next build -- and none of those can fail on a link to a route that does not
 * exist, because a disabled placeholder is valid TypeScript. So the assertion
 * lives here, where it actually runs, rather than not existing at all.
 *
 * One `navigation` array feeds both the desktop sidebar and the mobile nav, so
 * checking that array covers both surfaces.
 */
class DashboardNavigationTest extends TestCase
{
    private function layout(): string
    {
        $path = base_path('../web/app/dashboard/layout.tsx');

        $this->assertFileExists($path, 'The dashboard layout moved; update this guard.');

        return (string) file_get_contents($path);
    }

    public function test_the_navigation_has_no_reports_placeholder(): void
    {
        $layout = $this->layout();

        $this->assertStringNotContainsString('/dashboard/reports', $layout);
        $this->assertStringNotContainsString('"Reports"', $layout);
    }

    public function test_the_icon_the_reports_placeholder_used_is_not_left_imported(): void
    {
        $this->assertStringNotContainsString('FileText', $this->layout());
    }

    public function test_the_navigation_still_lists_the_routes_that_exist(): void
    {
        $layout = $this->layout();

        // A guard that only asserts an absence would pass on an empty file.
        foreach (['/dashboard/execution', '/dashboard/assets', '/dashboard/portfolio'] as $href) {
            $this->assertStringContainsString($href, $layout);
        }
    }
}
