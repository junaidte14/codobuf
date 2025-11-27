<?php
/**
 * Plugin Name: User Fields for CodoBookings
 * Plugin URI:  https://codoplex.com
 * Description: Adds a drag & drop dynamic User Fields system for CodoBookings (global settings + calendar metabox).
 * Version:     1.0.0
 * Author:      Junaid Hassan / Codoplex
 * Text Domain: codobuf
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* --------------------------
 * Constants
 * -------------------------- */
if ( ! defined( 'CODOBUF_PLUGIN_DIR' ) ) {
    define( 'CODOBUF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'CODOBUF_PLUGIN_URL' ) ) {
    define( 'CODOBUF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'CODOBUF_PLUGIN_VERSION' ) ) {
    define( 'CODOBUF_PLUGIN_VERSION', '1.0.0' );
}

/* --------------------------
 * Includes
 * -------------------------- */
require_once CODOBUF_PLUGIN_DIR . 'includes/common.php';
require_once CODOBUF_PLUGIN_DIR . 'admin/settings.php';
require_once CODOBUF_PLUGIN_DIR . 'admin/metabox.php';

/* --------------------------
 * Admin asset enqueue
 * -------------------------- */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    // Load assets only on relevant screens: codobookings settings page & codo_calendar edit screens
    $load = false;

    // Settings page: settings_page_codobookings_settings
    if ( $hook === 'codobookings_page_codobookings_settings' ) {
        $load = true;
    }

    // Post edit screens for codo_calendar post type
    if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
        $screen = get_current_screen();
        if ( $screen && $screen->post_type === 'codo_calendar' ) {
            $load = true;
        }
    }

    if ( ! $load ) return;

    // Enqueue jQuery UI sortable (bundled)
    wp_enqueue_script( 'jquery-ui-sortable' );

    // Enqueue our admin assets
    wp_register_script(
        'codobuf-fields-editor',
        CODOBUF_PLUGIN_URL . 'admin/assets/js/fields-editor.js',
        [ 'jquery', 'jquery-ui-sortable' ],
        CODOBUF_PLUGIN_VERSION,
        true
    );

    wp_localize_script(
        'codobuf-fields-editor',
        'codobufEditor',
        [
            'i18n' => [
                'untitled'      => __( 'Untitled', 'codobuf' ),
                'remove_confirm'=> __( 'Remove this field?', 'codobuf' ),
            ],
            'nonce' => wp_create_nonce( 'codobuf_admin_nonce' ),
        ]
    );

    wp_enqueue_script( 'codobuf-fields-editor' );

    wp_enqueue_style(
        'codobuf-fields-editor',
        CODOBUF_PLUGIN_URL . 'admin/assets/css/fields-editor.css',
        [],
        CODOBUF_PLUGIN_VERSION
    );

    // Dashicons
    wp_enqueue_style( 'dashicons' );
}, 10, 1 );

/**
 * Frontend asset enqueue
 */
add_action( 'wp_enqueue_scripts', function() {
    // Only load on pages with the calendar shortcode/block
    if ( ! is_singular() && ! is_page() ) {
        return;
    }
    
    wp_enqueue_script(
        'codobuf-frontend-integration',
        CODOBUF_PLUGIN_URL . 'assets/js/frontend-integration.js',
        [ 'jquery' ],
        CODOBUF_PLUGIN_VERSION,
        true
    );
} );

add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'codobuf-frontend',
        CODOBUF_PLUGIN_URL . 'assets/css/frontend.css',
        [],
        CODOBUF_PLUGIN_VERSION
    );
} );

/* --------------------------
 * Load translations
 * -------------------------- */
add_action( 'init', function() {
    load_plugin_textdomain( 'codobuf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});
