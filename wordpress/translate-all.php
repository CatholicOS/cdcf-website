<?php
/**
 * Bulk translate all published content using OpenAI.
 *
 * Called via:  wp eval-file /usr/local/bin/translate-all.php --allow-root
 *
 * Pass `overwrite` as a positional arg to re-translate translations that
 * already have content (default: skip non-empty translations):
 *   wp eval-file /usr/local/bin/translate-all.php overwrite --allow-root
 *
 * Requires:
 *   - Polylang active with languages configured
 *   - cdcf_openai_api_key WordPress option set
 *   - cdcf-headless theme active (provides cdcf_openai_translate())
 */

// ── Argument parsing ────────────────────────────────────────────────

$overwrite = ! empty( $args ) && in_array( 'overwrite', (array) $args, true );

if ( true === $overwrite ) {
    WP_CLI::log( 'Overwrite mode: existing non-empty translations WILL be re-translated.' );
}

// ── Pre-flight checks ───────────────────────────────────────────────

if ( ! function_exists( 'PLL' ) || ! function_exists( 'pll_default_language' ) ) {
    WP_CLI::warning( 'Polylang is not active. Skipping translations.' );
    return;
}

if ( ! function_exists( 'cdcf_openai_translate' ) ) {
    WP_CLI::warning( 'cdcf_openai_translate() not found. Is the cdcf-headless theme active?' );
    return;
}

$api_key = get_option( 'cdcf_openai_api_key' );
if ( ! $api_key ) {
    WP_CLI::warning( 'OpenAI API key not configured. Skipping translations.' );
    return;
}

// ── Configuration ───────────────────────────────────────────────────

$default_lang = pll_default_language( 'slug' );
$all_langs    = pll_languages_list( [ 'fields' => 'slug' ] );
$target_langs = array_values( array_filter( $all_langs, fn( $l ) => $l !== $default_lang ) );

if ( empty( $target_langs ) ) {
    WP_CLI::warning( 'No target languages found. Skipping translations.' );
    return;
}

$locale_names = defined( 'CDCF_LOCALE_NAMES' ) ? CDCF_LOCALE_NAMES : [
    'en' => 'English',
    'it' => 'Italian',
    'es' => 'Spanish',
    'fr' => 'French',
    'pt' => 'Portuguese',
    'de' => 'German',
];

$translatable_types = defined( 'CDCF_TRANSLATABLE_ACF_TYPES' )
    ? CDCF_TRANSLATABLE_ACF_TYPES
    : [ 'text', 'textarea', 'wysiwyg' ];

$source_name = $locale_names[ $default_lang ] ?? $default_lang;

WP_CLI::log( "Source language: {$source_name} ({$default_lang})" );
WP_CLI::log( 'Target languages: ' . implode( ', ', $target_langs ) );

// ── Ensure all published posts have the default language ────────────

$unassigned = get_posts( [
    'post_type'        => 'any',
    'post_status'      => 'publish',
    'numberposts'      => -1,
    'suppress_filters' => true,
] );

$assigned_count = 0;
foreach ( $unassigned as $post ) {
    $lang = pll_get_post_language( $post->ID, 'slug' );
    if ( ! $lang ) {
        pll_set_post_language( $post->ID, $default_lang );
        $assigned_count++;
    }
}

if ( $assigned_count > 0 ) {
    WP_CLI::log( "Assigned {$default_lang} to {$assigned_count} post(s) without a language." );
}

// ── Collect source posts ────────────────────────────────────────────

$post_types = array_keys( get_post_types( [ 'public' => true ] ) );

// Exclude attachment post type.
$post_types = array_filter( $post_types, fn( $pt ) => $pt !== 'attachment' );

$source_posts = get_posts( [
    'post_type'        => $post_types,
    'post_status'      => 'publish',
    'numberposts'      => -1,
    'lang'             => $default_lang,
] );

if ( empty( $source_posts ) ) {
    WP_CLI::warning( 'No published posts found in the default language.' );
    return;
}

WP_CLI::log( sprintf( "\nFound %d source post(s) to translate into %d language(s).\n", count( $source_posts ), count( $target_langs ) ) );

$stats = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 ];

// ── Helper: check if a translation post has any populated ACF fields ──

function cdcf_translation_has_content( $post_id, $translatable_types ) {
    if ( ! function_exists( 'get_field_objects' ) ) {
        return false;
    }
    $fields = get_field_objects( $post_id );
    if ( ! $fields ) {
        return false;
    }
    foreach ( $fields as $field ) {
        if (
            in_array( $field['type'], $translatable_types, true )
            && ! empty( $field['value'] )
        ) {
            return true;
        }
    }
    return false;
}

// ── Translate each post into each target language ───────────────────

foreach ( $source_posts as $source ) {
    $existing = pll_get_post_translations( $source->ID );

    foreach ( $target_langs as $lang ) {

        $translation_id = null;
        $is_update      = false;

        // Check if a translation already exists for this language.
        if ( isset( $existing[ $lang ] ) && $existing[ $lang ] != $source->ID ) {
            $trans_id = $existing[ $lang ];

            // If the existing translation already has content, skip it
            // (unless overwrite mode was requested — re-translates from source).
            if ( false === $overwrite && true === cdcf_translation_has_content( $trans_id, $translatable_types ) ) {
                $stats['skipped']++;
                continue;
            }

            // Existing translation is empty (or overwrite mode) — populate it.
            $translation_id = $trans_id;
            $is_update      = true;
        }

        $target_name = $locale_names[ $lang ] ?? $lang;
        $action      = $is_update ? 'updating' : 'creating';
        WP_CLI::log( "  [{$source->post_type}] \"{$source->post_title}\" → {$target_name} ({$action})..." );

        // 1. Collect translatable strings.
        $strings = [];
        if ( $source->post_title )   $strings['post_title']   = $source->post_title;
        if ( $source->post_content ) $strings['post_content'] = $source->post_content;
        if ( $source->post_excerpt ) $strings['post_excerpt'] = $source->post_excerpt;

        if ( function_exists( 'get_field_objects' ) ) {
            $fields = get_field_objects( $source->ID );
            if ( $fields ) {
                foreach ( $fields as $field ) {
                    if (
                        in_array( $field['type'], $translatable_types, true )
                        && ! empty( $field['value'] )
                        && is_string( $field['value'] )
                    ) {
                        $strings[ 'acf_' . $field['name'] ] = $field['value'];
                    }
                }
            }
        }

        // 2. Translate via OpenAI.
        $translated = [];
        if ( ! empty( $strings ) ) {
            $result = cdcf_openai_translate( $strings, $source_name, $target_name, $api_key );
            if ( is_wp_error( $result ) ) {
                WP_CLI::warning( '    ' . $result->get_error_message() );
                $stats['errors']++;
                continue;
            }
            $translated = $result;
        }

        // 3. Create or update the translation post.
        if ( $is_update ) {
            $update_data = [ 'ID' => $translation_id ];
            if ( isset( $translated['post_title'] ) )   $update_data['post_title']   = $translated['post_title'];
            if ( isset( $translated['post_content'] ) )  $update_data['post_content'] = wp_kses_post( $translated['post_content'] );
            if ( isset( $translated['post_excerpt'] ) )  $update_data['post_excerpt'] = $translated['post_excerpt'];
            wp_update_post( $update_data );
        } else {
            $new_post_data = [
                'post_type'    => $source->post_type,
                'post_title'   => $translated['post_title'] ?? $source->post_title,
                'post_content' => isset( $translated['post_content'] )
                    ? wp_kses_post( $translated['post_content'] )
                    : ( $source->post_content ?? '' ),
                'post_excerpt' => $translated['post_excerpt'] ?? ( $source->post_excerpt ?? '' ),
                'post_status'  => 'publish',
                'post_name'    => $source->post_name,
            ];

            $translation_id = wp_insert_post( $new_post_data );
            if ( is_wp_error( $translation_id ) ) {
                WP_CLI::warning( '    Failed to create post: ' . $translation_id->get_error_message() );
                $stats['errors']++;
                continue;
            }

            // Set language and link to the source post.
            pll_set_post_language( $translation_id, $lang );
            $translations          = pll_get_post_translations( $source->ID );
            $translations[ $lang ] = $translation_id;
            pll_save_post_translations( $translations );
        }

        // 4. Copy page template.
        $template = get_post_meta( $source->ID, '_wp_page_template', true );
        if ( $template && $template !== 'default' ) {
            update_post_meta( $translation_id, '_wp_page_template', $template );
        }

        // 5. Copy featured image.
        $thumbnail_id = get_post_thumbnail_id( $source->ID );
        if ( $thumbnail_id ) {
            set_post_thumbnail( $translation_id, $thumbnail_id );
        }

        // 6. Write translated ACF fields + copy non-translatable ones.
        if ( function_exists( 'get_field_objects' ) && function_exists( 'update_field' ) ) {
            $fields = get_field_objects( $source->ID );
            if ( $fields ) {
                foreach ( $fields as $field ) {
                    if ( in_array( $field['type'], $translatable_types, true ) ) {
                        $key = 'acf_' . $field['name'];
                        if ( isset( $translated[ $key ] ) ) {
                            update_field( $field['name'], $translated[ $key ], $translation_id );
                        }
                    } elseif ( ! empty( $field['value'] ) ) {
                        update_field( $field['name'], $field['value'], $translation_id );
                    }
                }
            }
        }

        if ( $is_update ) {
            WP_CLI::success( "    Updated ID {$translation_id}" );
            $stats['updated']++;
        } else {
            WP_CLI::success( "    Created ID {$translation_id}" );
            $stats['created']++;
        }
    }
}

// ── Summary ─────────────────────────────────────────────────────────

WP_CLI::log( '' );
WP_CLI::log( '=========================================' );
WP_CLI::log( sprintf(
    '  Created: %d | Updated: %d | Skipped: %d | Errors: %d',
    $stats['created'],
    $stats['updated'],
    $stats['skipped'],
    $stats['errors']
) );
WP_CLI::log( '=========================================' );
