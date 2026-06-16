<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Polylang "Change language" dialog responsive override:
 *
 *  - cdcf_polylang_dialog_responsive_css()         — the pure CSS builder
 *  - cdcf_enqueue_polylang_dialog_responsive_css() — the admin_enqueue_scripts
 *    callback that only fires on the post-edit screen.
 */
final class PolylangDialogResponsiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function makeScreen(string $base): object
    {
        return new class($base) {
            public string $base;
            public function __construct(string $base)
            {
                $this->base = $base;
            }
        };
    }

    public function test_css_targets_polylang_dialog_and_uses_responsive_media_query(): void
    {
        $css = cdcf_polylang_dialog_responsive_css();

        // Scopes to Polylang's dialog wrapper, not jQuery UI dialogs at large.
        $this->assertStringContainsString('.pll-confirmation-modal.ui-dialog', $css);
        // Both orientations: narrow (portrait) and short (landscape) viewports.
        $this->assertStringContainsString('max-width: 782px', $css);
        $this->assertStringContainsString('max-height: 600px', $css);
        // Pins the dialog into the viewport so the buttons can't fall off-screen.
        $this->assertStringContainsString('position: fixed !important', $css);
        // The body scrolls so OK/Cancel stay reachable on tiny screens.
        $this->assertStringContainsString('overflow-y: auto !important', $css);
    }

    public function test_enqueues_inline_style_on_post_edit_screen(): void
    {
        Functions\when('get_current_screen')->justReturn($this->makeScreen('post'));

        $registered = [];
        $enqueued   = [];
        $inline     = [];
        Functions\when('wp_register_style')->alias(
            function (string $handle) use (&$registered): bool {
                $registered[] = $handle;
                return true;
            }
        );
        Functions\when('wp_enqueue_style')->alias(
            function (string $handle) use (&$enqueued): void {
                $enqueued[] = $handle;
            }
        );
        Functions\when('wp_add_inline_style')->alias(
            function (string $handle, string $data) use (&$inline): bool {
                $inline[] = [$handle, $data];
                return true;
            }
        );

        cdcf_enqueue_polylang_dialog_responsive_css();

        $this->assertSame(['cdcf-polylang-dialog-responsive'], $registered);
        $this->assertSame(['cdcf-polylang-dialog-responsive'], $enqueued);
        $this->assertCount(1, $inline);
        $this->assertSame('cdcf-polylang-dialog-responsive', $inline[0][0]);
        $this->assertSame(cdcf_polylang_dialog_responsive_css(), $inline[0][1]);
    }

    public function test_does_not_enqueue_off_the_post_edit_screen(): void
    {
        Functions\when('get_current_screen')->justReturn($this->makeScreen('dashboard'));

        $calls = 0;
        Functions\when('wp_register_style')->alias(function () use (&$calls): bool {
            $calls++;
            return true;
        });
        Functions\when('wp_enqueue_style')->alias(function () use (&$calls): void {
            $calls++;
        });
        Functions\when('wp_add_inline_style')->alias(function () use (&$calls): bool {
            $calls++;
            return true;
        });

        cdcf_enqueue_polylang_dialog_responsive_css();

        $this->assertSame(0, $calls);
    }

    public function test_does_not_enqueue_when_screen_unavailable(): void
    {
        // get_current_screen() can return null very early in the request.
        Functions\when('get_current_screen')->justReturn(null);

        $calls = 0;
        Functions\when('wp_register_style')->alias(function () use (&$calls): bool {
            $calls++;
            return true;
        });
        Functions\when('wp_enqueue_style')->alias(function () use (&$calls): void {
            $calls++;
        });
        Functions\when('wp_add_inline_style')->alias(function () use (&$calls): bool {
            $calls++;
            return true;
        });

        cdcf_enqueue_polylang_dialog_responsive_css();

        $this->assertSame(0, $calls);
    }
}
