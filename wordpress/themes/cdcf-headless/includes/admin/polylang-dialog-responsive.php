<?php
/**
 * Make Polylang's "Change language" confirmation dialog usable on mobile.
 *
 * When an editor changes the language of a non-empty post, Polylang pops a
 * confirmation ("Are you sure you want to change the language of the current
 * content?"). It is rendered as a jQuery UI dialog — element `#pll-dialog`
 * inside a `.ui-dialog.pll-confirmation-modal` wrapper — which jQuery UI sizes
 * with a fixed pixel width and positions absolutely against the document.
 *
 * On a phone that geometry pushes the OK/Cancel button pane below the fold,
 * and because the dialog is absolutely positioned it cannot be scrolled into
 * view. Rotating to landscape just trades the too-narrow width for a too-short
 * height — the buttons still land off-screen. The user is then stuck with no
 * way to confirm or cancel.
 *
 * Polylang is a third-party plugin we don't vendor, so we can't patch its JS.
 * Instead we enqueue a small admin stylesheet (inline, no asset file) that, on
 * small viewports, pins the dialog into the centre of the screen, caps its
 * size to the viewport, and lets its body scroll so the action buttons are
 * always reachable.
 *
 * Extracted into its own file (and split into a pure CSS-builder + an enqueue
 * callback) so both halves can be unit-tested with Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

/**
 * The responsive override CSS for Polylang's confirmation dialog.
 *
 * Kept as a pure function (no WP calls) so a test can assert on its content
 * without standing up the enqueue machinery.
 */
function cdcf_polylang_dialog_responsive_css(): string
{
    // Two triggers: narrow viewports (portrait phones, WordPress's own
    // 782px admin breakpoint) and short viewports (landscape phones), so the
    // fix applies in both orientations. Desktop screens clear both bounds and
    // keep jQuery UI's default centred dialog untouched.
    //
    // !important throughout: jQuery UI writes width/height/top/left as inline
    // styles on these same elements, and inline styles win without it.
    return <<<CSS
@media screen and (max-width: 782px), screen and (max-height: 600px) {
    .pll-confirmation-modal.ui-dialog {
        position: fixed !important;
        top: 50% !important;
        left: 50% !important;
        right: auto !important;
        bottom: auto !important;
        transform: translate(-50%, -50%) !important;
        width: calc(100vw - 2rem) !important;
        max-width: 360px !important;
        height: auto !important;
        max-height: calc(100vh - 2rem) !important;
        margin: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
    }

    .pll-confirmation-modal .ui-dialog-content {
        width: auto !important;
        height: auto !important;
        max-height: none !important;
        flex: 1 1 auto !important;
        overflow-y: auto !important;
        box-sizing: border-box !important;
    }

    .pll-confirmation-modal .ui-dialog-buttonpane {
        flex: 0 0 auto !important;
        margin-top: 0 !important;
    }

    /* Keep OK/Cancel on one wrapping row and give them a real tap target. */
    .pll-confirmation-modal .ui-dialog-buttonpane .ui-dialog-buttonset {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 0.5rem !important;
        justify-content: flex-end !important;
        float: none !important;
    }

    .pll-confirmation-modal .ui-dialog-buttonpane .ui-dialog-buttonset button {
        float: none !important;
        margin: 0 !important;
        min-height: 40px !important;
    }
}
CSS;
}

/**
 * Enqueue the responsive override on the post-edit screens where Polylang's
 * dialog can appear (post.php / post-new.php — both report screen base
 * `post`, in the classic and block editors alike).
 *
 * Hooked on `admin_enqueue_scripts`.
 */
function cdcf_enqueue_polylang_dialog_responsive_css(): void
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen === null || $screen->base !== 'post') {
        return;
    }

    $handle = 'cdcf-polylang-dialog-responsive';
    // A src-less handle is the canonical carrier for wp_add_inline_style:
    // no physical asset file, no URL/path to resolve in the theme.
    wp_register_style($handle, false, [], '1.0.0');
    wp_enqueue_style($handle);
    wp_add_inline_style($handle, cdcf_polylang_dialog_responsive_css());
}
