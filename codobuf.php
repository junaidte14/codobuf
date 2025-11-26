<?php
/**
 * Plugin Name: User Fields for CodoBookings
 * Plugin URI:  https://codoplex.com
 * Description: Adds a drag & drop dynamic User Fields system for CodoBookings (global settings + calendar metabox).
 * Version:     1.0.0
 * Author:      Junaid Hassan / Codoplex
 * Text Domain: codobuf
 * Domain Path: /languages
 *
 * Single-file implementation. All functionality, settings and metabox code below.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * --------------------------
 * Configuration / Defaults
 * --------------------------
 */

/**
 * Filter: codobookings_user_fields_option_name
 * Allows other code to change the option name where global user fields are stored.
 */
function codobuf_get_option_name() {
    return apply_filters( 'codobookings_user_fields_option_name', 'codobookings_user_fields' );
}

/**
 * Default structure for a single field item.
 * Keys:
 *  - label (string)
 *  - name  (string, machine name / unique)
 *  - type  (text|number|textarea|select|radio|checkbox|file)
 *  - required (bool)
 *  - hint (string)
 *  - options (string) // comma separated for select/radio/checkbox
 */
function codobuf_user_field_default_item() {
    return [
        'label'    => '',
        'name'     => '',
        'type'     => 'text',
        'required' => false,
        'hint'     => '',
        'options'  => '',
    ];
}

/**
 * Filter: codobookings_user_fields_default
 * Allows other code to define default global fields.
 */
function codobuf_get_default_fields() {
    return apply_filters( 'codobookings_user_fields_default', [] );
}

/**
 * --------------------------
 * Register setting and settings tab
 * --------------------------
 */

/**
 * Hook into codobookings settings registration flow.
 * We'll register our option and a sanitize callback.
 */
add_action( 'admin_init', 'codobuf_register_settings' );
function codobuf_register_settings() {
    $option_name = codobuf_get_option_name();

    register_setting( 'codobookings_options', $option_name, [
        'type'              => 'string',
        'sanitize_callback' => 'codobuf_sanitize_option',
        'default'           => wp_json_encode( codobuf_get_default_fields() ),
    ] );

    /**
     * Add our settings tab via codobookings_get_settings_tabs filter.
     * If codobookings provides a hook to add tabs, this will attach.
     */
    add_filter( 'codobookings_settings_tabs', 'codobuf_add_settings_tab' );
}

/**
 * Add 'User Fields' tab to codobookings settings tabs list
 */
function codobuf_add_settings_tab( $tabs ) {
    $tabs['user_fields'] = [
        'label'    => __( 'User Fields', 'codobuf' ),
        'callback' => 'codobuf_render_settings_tab',
    ];
    return $tabs;
}

/**
 * Sanitize option (called by register_setting).
 * The stored format will be JSON-string of array-of-field-objects.
 */
function codobuf_sanitize_option( $raw ) {
    // may come in as array or JSON string (our form posts JSON string)
    if ( is_array( $raw ) ) {
        $data = $raw;
    } else {
        $data = json_decode( wp_unslash( $raw ), true );
        if ( ! is_array( $data ) ) {
            // If invalid, return previous value (don't corrupt data).
            return get_option( codobuf_get_option_name(), wp_json_encode( codobuf_get_default_fields() ) );
        }
    }

    $clean = [];
    foreach ( $data as $idx => $item ) {
        if ( ! is_array( $item ) ) continue;

        $field = codobuf_user_field_default_item();

        $field['label']    = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : $field['label'];
        // name must be sanitized and unique; if empty, generate from label
        $raw_name = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
        if ( empty( $raw_name ) && ! empty( $field['label'] ) ) {
            $raw_name = sanitize_title( $field['label'] );
        }
        // ensure machine name only contains a-z0-9_ and starts with letter
        $field['name'] = preg_replace( '/[^a-z0-9_]/', '_', strtolower( trim( $raw_name ) ) );
        if ( ! preg_match( '/^[a-z]/', $field['name'] ) ) {
            $field['name'] = 'f_' . $field['name'];
        }

        $field['type']     = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : $field['type'];
        $allowed_types     = [ 'text', 'number', 'textarea', 'select', 'radio', 'checkbox', 'file' ];
        if ( ! in_array( $field['type'], $allowed_types, true ) ) {
            $field['type'] = 'text';
        }

        $field['required'] = ! empty( $item['required'] ) ? true : false;
        $field['hint']     = isset( $item['hint'] ) ? sanitize_text_field( $item['hint'] ) : '';

        // options only for select/radio/checkbox — store as CSV string (sanitized)
        $field['options'] = '';
        if ( in_array( $field['type'], [ 'select', 'radio', 'checkbox' ], true ) && ! empty( $item['options'] ) ) {
            // explode + sanitize each option
            $parts = preg_split( '/\r\n|[\r\n]|,/', $item['options'] );
            $parts = array_map( 'trim', $parts );
            $parts = array_filter( $parts, 'strlen' );
            $parts = array_map( 'sanitize_text_field', $parts );
            $field['options'] = implode( ',', $parts );
        }

        // ensure uniqueness of names within saved array by suffixing when duplicates.
        $original_name = $field['name'];
        $counter = 1;
        while ( codobuf_name_exists_in_array( $field['name'], $clean ) ) {
            $field['name'] = $original_name . '_' . $counter;
            $counter++;
        }

        $clean[] = $field;
    }

    // Allow further filtering before encoding/storing
    $clean = apply_filters( 'codobookings_user_fields_sanitized', $clean );

    // Fire action after sanitization (receivers can react)
    do_action( 'codobookings_user_fields_saved', $clean );

    return wp_json_encode( $clean );
}

/**
 * Helper: check name exists in an array of fields
 */
function codobuf_name_exists_in_array( $name, $arr ) {
    foreach ( $arr as $item ) {
        if ( isset( $item['name'] ) && $item['name'] === $name ) return true;
    }
    return false;
}

/**
 * --------------------------
 * Settings tab renderer (UI)
 * --------------------------
 */

/**
 * Renders HTML for the User Fields tab.
 * Uses a repeater + jQuery UI sortable for drag & drop.
 */
function codobuf_render_settings_tab() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $option_name = codobuf_get_option_name();
    $stored      = get_option( $option_name, wp_json_encode( codobuf_get_default_fields() ) );
    $fields      = json_decode( $stored, true );
    if ( ! is_array( $fields ) ) $fields = [];

    // Nonce for JS actions that might be added later
    $nonce = wp_create_nonce( 'codobuf_admin_nonce' );

    ?>
    <h2><?php esc_html_e( 'User Fields', 'codobuf' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Define custom user fields used by CodoBookings. Drag to reorder. Names are auto-generated from the label but can be edited.', 'codobuf' ); ?></p>

    <table class="form-table">
        <tr>
            <th><?php esc_html_e( 'Fields editor', 'codobuf' ); ?></th>
            <td>
                <div id="codobuf-fields-editor" style="max-width:900px;">

                    <ul id="codobuf-fields-list" class="codobuf-sortable" style="list-style:none; margin:0; padding:0;">
                        <?php foreach ( $fields as $index => $f ) : ?>
                            <li class="codobuf-field-item" data-index="<?php echo esc_attr( $index ); ?>" style="border:1px solid #ddd; padding:12px; margin-bottom:8px; background:#fff;">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <span class="dashicons dashicons-menu"></span>
                                    <strong class="codobuf-field-preview"><?php echo esc_html( $f['label'] ?: __( 'Untitled', 'codobuf' ) ); ?></strong>
                                    <small style="color:#666; margin-left:6px;"><?php echo esc_html( '[' . $f['type'] . ']' ); ?></small>
                                    <a href="#" class="codobuf-toggle-edit" style="margin-left:auto;"><?php esc_html_e( 'Edit', 'codobuf' ); ?></a>
                                    <a href="#" class="codobuf-remove-field" style="margin-left:8px; color:#a00;"><?php esc_html_e( 'Remove', 'codobuf' ); ?></a>
                                </div>

                                <div class="codobuf-field-settings" style="margin-top:8px;">
                                    <table class="form-table" style="margin:0;">
                                        <tr>
                                            <td style="width:220px;">
                                                <label><?php esc_html_e( 'Label', 'codobuf' ); ?></label><br>
                                                <input type="text" class="codobuf-field-label regular-text" value="<?php echo esc_attr( $f['label'] ); ?>">
                                            </td>
                                            <td>
                                                <label><?php esc_html_e( 'Name (unique)', 'codobuf' ); ?></label><br>
                                                <input type="text" class="codobuf-field-name regular-text" value="<?php echo esc_attr( $f['name'] ); ?>">
                                                <p class="description"><?php esc_html_e( 'Only lowercase letters, numbers and underscores. Auto-generated from label.', 'codobuf' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <label><?php esc_html_e( 'Type', 'codobuf' ); ?></label><br>
                                                <select class="codobuf-field-type">
                                                    <?php
                                                    $types = [ 'text' => 'Text', 'number' => 'Number', 'textarea' => 'Textarea', 'select' => 'Select', 'radio' => 'Radio', 'checkbox' => 'Checkbox', 'file' => 'File' ];
                                                    foreach ( $types as $key => $label ) :
                                                    ?>
                                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <label><?php esc_html_e( 'Required', 'codobuf' ); ?></label><br>
                                                <label><input type="checkbox" class="codobuf-field-required" <?php checked( $f['required'], true ); ?>> <?php esc_html_e( 'Yes', 'codobuf' ); ?></label>
                                            </td>
                                        </tr>
                                        <tr class="codobuf-options-row" <?php if ( ! in_array( $f['type'], [ 'select', 'radio', 'checkbox' ], true ) ) echo 'style="display:none;"'; ?>>
                                            <td colspan="2">
                                                <label><?php esc_html_e( 'Options (comma or newline separated)', 'codobuf' ); ?></label><br>
                                                <textarea class="codobuf-field-options" rows="3"><?php echo esc_textarea( str_replace( ',', "\n", $f['options'] ) ); ?></textarea>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2">
                                                <label><?php esc_html_e( 'Hint / placeholder', 'codobuf' ); ?></label><br>
                                                <input type="text" class="codobuf-field-hint regular-text" value="<?php echo esc_attr( $f['hint'] ); ?>">
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="margin-bottom:10px;">
                        <button type="button" id="codobuf-add-field" class="button"><?php esc_html_e( 'Add Field', 'codobuf' ); ?></button>
                        <span class="description" style="margin-left:10px;"><?php esc_html_e( 'Use the editor to add, edit, reorder or remove fields.', 'codobuf' ); ?></span>
                    </div>
                    <input type="hidden" id="codobuf-fields-json" name="<?php echo esc_attr( codobuf_get_option_name() ); ?>" value="<?php echo esc_attr( wp_json_encode( $fields ) ); ?>">

                </div>
            </td>
        </tr>
    </table>

    <?php
    // Note: the form submit (options.php) is handled by the parent settings page.
    // We need to enqueue scripts to make the editor work.
    codobuf_print_admin_js_css( $nonce );
}

/**
 * Enqueue/print the admin JS/CSS for the fields editor.
 * We keep everything inlined to remain single-file. Uses jQuery UI sortable (bundled with WP).
 */
function codobuf_print_admin_js_css( $nonce ) {
    // Only allow admin users
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Ensure jQuery UI sortable is available
    wp_enqueue_script( 'jquery-ui-sortable' );
    // Use WP's built-in dashicons
    wp_enqueue_style( 'dashicons' );

    // Inline styles
    ?>
    <style>
    .codobuf-field-settings { display:block; }
    .codobuf-field-item .codobuf-field-settings { margin-top:10px; }
    .codobuf-sortable li { cursor: move; }
    .codobuf-field-settings .form-table td{
        vertical-align: top;
    }
    </style>
    <script>
    (function($){
        'use strict';

        function updateHidden() {
            var arr = [];
            $('#codobuf-fields-list .codobuf-field-item').each(function(){
                var $li = $(this);
                var label = $li.find('.codobuf-field-label').val() || '';
                var name  = $li.find('.codobuf-field-name').val() || '';
                var type  = $li.find('.codobuf-field-type').val() || 'text';
                var required = $li.find('.codobuf-field-required').is(':checked') ? 1 : 0;
                var hint = $li.find('.codobuf-field-hint').val() || '';
                var options = '';
                if ($li.find('.codobuf-field-options').length) {
                    // join by comma, but keep newline entries treated as separate options
                    var raw = $li.find('.codobuf-field-options').val() || '';
                    // normalize new lines to comma
                    options = raw.replace(/\r\n/g, '\n').split(/[\n,]+/).map(function(s){ return s.trim(); }).filter(Boolean).join(',');
                }
                arr.push({
                    label: label,
                    name: name,
                    type: type,
                    required: required,
                    hint: hint,
                    options: options
                });
            });
            $('#codobuf-fields-json').val( JSON.stringify(arr) );
        }

        function makeNameFromLabel(label){
            if(!label) return '';
            var n = label.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g,'');
            if(!/^[a-z]/.test(n)) n = 'f_' + n;
            return n;
        }

        $(function(){
            $('#codobuf-fields-list').sortable({
                axis: 'y',
                handle: '.dashicons-menu',
                update: updateHidden
            });

            // Toggle edit
            $(document).on('click', '.codobuf-toggle-edit', function(e){
                e.preventDefault();
                var $li = $(this).closest('.codobuf-field-item');
                $li.find('.codobuf-field-settings').toggle();
            });

            // Remove
            $(document).on('click', '.codobuf-remove-field', function(e){
                e.preventDefault();
                if (!confirm('<?php echo esc_js( __( 'Remove this field?', 'codobuf' ) ); ?>')) return;
                $(this).closest('.codobuf-field-item').remove();
                updateHidden();
            });

            // Add new
            $('#codobuf-add-field').on('click', function(){
                var idx = Date.now();
                var tpl = '' +
                '<li class="codobuf-field-item" data-index="'+idx+'" style="border:1px solid #ddd; padding:12px; margin-bottom:8px; background:#fff;">' +
                    '<div style="display:flex; align-items:center; gap:12px;">' +
                        '<span class="dashicons dashicons-menu"></span>' +
                        '<strong class="codobuf-field-preview"><?php echo esc_js( __( 'Untitled', 'codobuf' ) ); ?></strong>' +
                        '<small style="color:#666; margin-left:6px;">[text]</small>' +
                        '<a href="#" class="codobuf-toggle-edit" style="margin-left:auto;"><?php echo esc_js( __( 'Edit', 'codobuf' ) ); ?></a>' +
                        '<a href="#" class="codobuf-remove-field" style="margin-left:8px; color:#a00;"><?php echo esc_js( __( 'Remove', 'codobuf' ) ); ?></a>' +
                    '</div>' +
                    '<div class="codobuf-field-settings" style="margin-top:8px;">' +
                        '<table class="form-table" style="margin:0;">' +
                            '<tr>' +
                                '<td style="width:220px;">' +
                                    '<label><?php echo esc_js( __( 'Label', 'codobuf' ) ); ?></label><br>' +
                                    '<input type="text" class="codobuf-field-label regular-text" value="">' +
                                '</td>' +
                                '<td>' +
                                    '<label><?php echo esc_js( __( 'Name (unique)', 'codobuf' ) ); ?></label><br>' +
                                    '<input type="text" class="codobuf-field-name regular-text" value="">' +
                                    '<p class="description"><?php echo esc_js( __( 'Only lowercase letters, numbers and underscores. Auto-generated from label.', 'codobuf' ) ); ?></p>' +
                                '</td>' +
                            '</tr>' +
                            '<tr>' +
                                '<td>' +
                                    '<label><?php echo esc_js( __( 'Type', 'codobuf' ) ); ?></label><br>' +
                                    '<select class="codobuf-field-type">' +
                                        '<option value="text">Text</option>' +
                                        '<option value="number">Number</option>' +
                                        '<option value="textarea">Textarea</option>' +
                                        '<option value="select">Select</option>' +
                                        '<option value="radio">Radio</option>' +
                                        '<option value="checkbox">Checkbox</option>' +
                                        '<option value="file">File</option>' +
                                    '</select>' +
                                '</td>' +
                                '<td>' +
                                    '<label><?php echo esc_js( __( 'Required', 'codobuf' ) ); ?></label><br>' +
                                    '<label><input type="checkbox" class="codobuf-field-required"> <?php echo esc_js( __( 'Yes', 'codobuf' ) ); ?></label>' +
                                '</td>' +
                            '</tr>' +
                            '<tr class="codobuf-options-row" style="display:none;">' +
                                '<td colspan="2">' +
                                    '<label><?php echo esc_js( __( 'Options (comma or newline separated)', 'codobuf' ) ); ?></label><br>' +
                                    '<textarea class="codobuf-field-options" rows="3"></textarea>' +
                                '</td>' +
                            '</tr>' +
                            '<tr>' +
                                '<td colspan="2">' +
                                    '<label><?php echo esc_js( __( 'Hint / placeholder', 'codobuf' ) ); ?></label><br>' +
                                    '<input type="text" class="codobuf-field-hint regular-text" value="">' +
                                '</td>' +
                            '</tr>' +
                        '</table>' +
                    '</div>' +
                '</li>';
                $('#codobuf-fields-list').append(tpl);
                updateHidden();
            });

            // Track manual edits to name field
            $(document).on('input', '.codobuf-field-name', function () {
                $(this).data('touched', true);
            });

            // Auto-update name field from label UNTIL user edits name manually
            $(document).on('input', '.codobuf-field-label', function () {
                var $li = $(this).closest('.codobuf-field-item');
                var label = $(this).val();

                // Update preview
                $li.find('.codobuf-field-preview').text(label || '<?php echo esc_js( __( 'Untitled', 'codobuf' ) ); ?>');

                var $name = $li.find('.codobuf-field-name');

                // Only auto-generate if user has NOT touched name manually
                if (!$name.data('touched')) {
                    $name.val(makeNameFromLabel(label));
                }

                updateHidden();
            });

            $(document).on('input change', '.codobuf-field-name, .codobuf-field-type, .codobuf-field-required, .codobuf-field-hint, .codobuf-field-options', function(){
                var $li = $(this).closest('.codobuf-field-item');
                // show/hide options row depending on type
                var type = $li.find('.codobuf-field-type').val();
                if ( ['select','radio','checkbox'].indexOf(type) !== -1 ) {
                    $li.find('.codobuf-options-row').show();
                } else {
                    $li.find('.codobuf-options-row').hide();
                }
                updateHidden();
            });

            // initialize visibility for existing items
            $('#codobuf-fields-list .codobuf-field-item').each(function(){
                var $li = $(this);
                var type = $li.find('.codobuf-field-type').val();
                if ( ['select','radio','checkbox'].indexOf(type) !== -1 ) {
                    $li.find('.codobuf-options-row').show();
                } else {
                    $li.find('.codobuf-options-row').hide();
                }
            });

            // Update hidden on page load once
            updateHidden();
        });
    })(jQuery);
    </script>
    <?php
}

/**
 * --------------------------
 * Metabox for codo_calendar post type
 * --------------------------
 */

/**
 * Add the metabox on add_meta_boxes (priority default).
 */
add_action( 'add_meta_boxes', 'codobuf_add_calendar_metabox' );
function codobuf_add_calendar_metabox() {
    $post_type = 'codo_calendar';
    if ( post_type_exists( $post_type ) ) {
        add_meta_box(
            'codobuf_calendar_user_fields',
            __( 'User Fields (Calendar)', 'codobuf' ),
            'codobuf_render_calendar_metabox',
            $post_type,
            'side',
            'default'
        );
    }
}

/**
 * Render metabox: choice radio (global / none / custom) + custom editor when chosen.
 */
function codobuf_render_calendar_metabox( $post ) {
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

    $meta = get_post_meta( $post->ID, '_codobookings_user_fields', true );
    if ( ! is_array( $meta ) ) {
        // Expected keys: mode => 'global'|'none'|'custom' ; custom_fields => JSON string (array)
        $meta = [
            'mode' => 'global',
            'custom_fields' => wp_json_encode( codobuf_get_default_fields() ),
        ];
    }

    wp_nonce_field( 'codobuf_calendar_metabox_nonce', 'codobuf_calendar_nonce' );

    $mode = isset( $meta['mode'] ) ? $meta['mode'] : 'global';
    $custom_json = isset( $meta['custom_fields'] ) ? $meta['custom_fields'] : wp_json_encode( codobuf_get_default_fields() );
    $custom_arr  = json_decode( $custom_json, true );
    if ( ! is_array( $custom_arr ) ) $custom_arr = [];

    ?>
    <p>
        <label><input type="radio" name="codobuf_fields_mode" value="global" <?php checked( $mode, 'global' ); ?>> <?php esc_html_e( 'Use Global User Fields (from plugin settings)', 'codobuf' ); ?></label><br>
        <label><input type="radio" name="codobuf_fields_mode" value="none" <?php checked( $mode, 'none' ); ?>> <?php esc_html_e( 'No User Fields', 'codobuf' ); ?></label><br>
        <label><input type="radio" name="codobuf_fields_mode" value="custom" <?php checked( $mode, 'custom' ); ?>> <?php esc_html_e( 'Custom for this calendar', 'codobuf' ); ?></label>
    </p>

    <div id="codobuf-calendar-custom-editor" style="<?php echo ( $mode === 'custom' ) ? '' : 'display:none;'; ?>">
        <p class="description"><?php esc_html_e( 'Define custom fields for this calendar. These override the global fields for bookings attached to this calendar.', 'codobuf' ); ?></p>

        <div id="codobuf-calendar-fields-wrapper">
            <button type="button" class="button" id="codobuf-calendar-add-field"><?php esc_html_e( 'Add Field', 'codobuf' ); ?></button>
            <ul id="codobuf-calendar-fields-list" class="codobuf-sortable" style="list-style:none; margin:0; padding:0; margin-top:8px;">
                <?php foreach ( $custom_arr as $cidx => $f ) : ?>
                    <li style="border:1px solid #ddd; padding:10px; margin-bottom:8px; background:#fff;">
                        <div style="display:flex; gap:8px; align-items:center;">
                            <span class="dashicons dashicons-menu"></span>
                            <strong><?php echo esc_html( $f['label'] ?: __( 'Untitled', 'codobuf' ) ); ?></strong>
                            <a href="#" class="codobuf-calendar-remove" style="margin-left:auto; color:#a00;"><?php esc_html_e( 'Remove', 'codobuf' ); ?></a>
                        </div>
                        <div style="margin-top:8px;">
                            <p><label><?php esc_html_e( 'Label', 'codobuf' ); ?> <input type="text" class="widefat codobuf-calendar-field-label" value="<?php echo esc_attr( $f['label'] ); ?>"></label></p>
                            <p><label><?php esc_html_e( 'Name (machine)', 'codobuf' ); ?> <input type="text" class="widefat codobuf-calendar-field-name" value="<?php echo esc_attr( $f['name'] ); ?>"></label></p>
                            <p>
                                <label><?php esc_html_e( 'Type', 'codobuf' ); ?>
                                <select class="codobuf-calendar-field-type">
                                    <?php
                                    $types = [ 'text' => 'Text', 'number' => 'Number', 'textarea' => 'Textarea', 'select' => 'Select', 'radio' => 'Radio', 'checkbox' => 'Checkbox', 'file' => 'File' ];
                                    foreach ( $types as $key => $label ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                            </p>
                            <p><label><?php esc_html_e( 'Options (comma/newline)', 'codobuf' ); ?> <textarea class="widefat codobuf-calendar-field-options" rows="2"><?php echo esc_textarea( str_replace( ',', "\n", $f['options'] ) ); ?></textarea></label></p>
                            <p><label><input type="checkbox" class="codobuf-calendar-field-required" <?php checked( $f['required'], true ); ?>> <?php esc_html_e( 'Required', 'codobuf' ); ?></label></p>
                            <p><label><?php esc_html_e( 'Hint', 'codobuf' ); ?> <input type="text" class="widefat codobuf-calendar-field-hint" value="<?php echo esc_attr( $f['hint'] ); ?>"></label></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <input type="hidden" name="codobuf_calendar_custom_fields" id="codobuf_calendar_custom_fields" value="<?php echo esc_attr( wp_json_encode( $custom_arr ) ); ?>">
        </div>
    </div>

    <script>
    (function($){
        $(function(){
            function updateCalendarHidden(){
                var arr = [];
                $('#codobuf-calendar-fields-list > li').each(function(){
                    var $li = $(this);
                    var label = $li.find('.codobuf-calendar-field-label').val() || '';
                    var name  = $li.find('.codobuf-calendar-field-name').val() || '';
                    var type  = $li.find('.codobuf-calendar-field-type').val() || 'text';
                    var options = '';
                    if ($li.find('.codobuf-calendar-field-options').length) {
                        var raw = $li.find('.codobuf-calendar-field-options').val() || '';
                        options = raw.replace(/\r\n/g,'\n').split(/[\n,]+/).map(function(s){ return s.trim(); }).filter(Boolean).join(',');
                    }
                    var required = $li.find('.codobuf-calendar-field-required').is(':checked') ? 1 : 0;
                    var hint = $li.find('.codobuf-calendar-field-hint').val() || '';
                    arr.push({ label: label, name: name, type: type, options: options, required: required, hint: hint });
                });
                $('#codobuf_calendar_custom_fields').val(JSON.stringify(arr));
            }

            $('#codobuf-calendar-fields-list').sortable({ axis: 'y', handle: '.dashicons-menu', update: updateCalendarHidden });

            $('#codobuf-calendar-add-field').on('click', function(e){
                e.preventDefault();
                var item = '<li style="border:1px solid #ddd; padding:10px; margin-bottom:8px; background:#fff;">'
                    + '<div style="display:flex; gap:8px; align-items:center;">'
                    + '<span class="dashicons dashicons-menu"></span>'
                    + '<strong>Untitled</strong>'
                    + '<a href="#" class="codobuf-calendar-remove" style="margin-left:auto; color:#a00;"><?php echo esc_js( __( 'Remove', 'codobuf' ) ); ?></a>'
                    + '</div>'
                    + '<div style="margin-top:8px;">'
                    + '<p><label><?php echo esc_js( __( 'Label', 'codobuf' ) ); ?> <input type="text" class="widefat codobuf-calendar-field-label" value=""></label></p>'
                    + '<p><label><?php echo esc_js( __( 'Name (machine)', 'codobuf' ) ); ?> <input type="text" class="widefat codobuf-calendar-field-name" value=""></label></p>'
                    + '<p><label><?php echo esc_js( __( 'Type', 'codobuf' ) ); ?>'
                    + '<select class="codobuf-calendar-field-type">'
                    + '<option value="text">Text</option><option value="number">Number</option><option value="textarea">Textarea</option><option value="select">Select</option><option value="radio">Radio</option><option value="checkbox">Checkbox</option><option value="file">File</option>'
                    + '</select></label></p>'
                    + '<p><label><?php echo esc_js( __( 'Options (comma/newline)', 'codobuf' ) ); ?> <textarea class="widefat codobuf-calendar-field-options" rows="2"></textarea></label></p>'
                    + '<p><label><input type="checkbox" class="codobuf-calendar-field-required"> <?php echo esc_js( __( 'Required', 'codobuf' ) ); ?></label></p>'
                    + '<p><label><?php echo esc_js( __( 'Hint', 'codobuf' ) ); ?> <input type="text" class="widefat codobuf-calendar-field-hint" value=""></label></p>'
                    + '</div>'
                    + '</li>';
                $('#codobuf-calendar-fields-list').append(item);
                updateCalendarHidden();
            });

            $(document).on('click', '.codobuf-calendar-remove', function(e){
                e.preventDefault();
                if(!confirm('<?php echo esc_js( __( 'Remove this field?', 'codobuf' ) ); ?>')) return;
                $(this).closest('li').remove();
                updateCalendarHidden();
            });

            $(document).on('input change', '#codobuf-calendar-fields-list input, #codobuf-calendar-fields-list select, #codobuf-calendar-fields-list textarea', function(){ updateCalendarHidden(); });

            // show/hide editor when mode changes
            $('input[name="codobuf_fields_mode"]').on('change', function(){
                if ( $(this).val() === 'custom' ) {
                    $('#codobuf-calendar-custom-editor').show();
                } else {
                    $('#codobuf-calendar-custom-editor').hide();
                }
            });

            // initial update
            updateCalendarHidden();
        });
    })(jQuery);
    </script>

    <?php
}

/**
 * Save metabox data
 */
add_action( 'save_post', 'codobuf_save_calendar_metabox', 10, 2 );
function codobuf_save_calendar_metabox( $post_id, $post ) {
    // Only run on our post type
    if ( $post->post_type !== 'codo_calendar' ) return;

    // Verify nonce
    if ( empty( $_POST['codobuf_calendar_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['codobuf_calendar_nonce'] ), 'codobuf_calendar_metabox_nonce' ) ) {
        return;
    }

    // Capability check
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $mode = isset( $_POST['codobuf_fields_mode'] ) ? sanitize_key( wp_unslash( $_POST['codobuf_fields_mode'] ) ) : 'global';
    if ( ! in_array( $mode, [ 'global', 'none', 'custom' ], true ) ) $mode = 'global';

    $custom_raw = isset( $_POST['codobuf_calendar_custom_fields'] ) ? wp_unslash( $_POST['codobuf_calendar_custom_fields'] ) : '';
    $custom_arr = json_decode( $custom_raw, true );
    if ( ! is_array( $custom_arr ) ) $custom_arr = [];

    // Sanitize each custom field using similar rules to option sanitizer but simpler
    $clean = [];
    foreach ( $custom_arr as $item ) {
        if ( ! is_array( $item ) ) continue;
        $field = codobuf_user_field_default_item();
        $field['label'] = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
        $raw_name = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
        if ( empty( $raw_name ) && ! empty( $field['label'] ) ) {
            $raw_name = sanitize_title( $field['label'] );
        }
        $field['name'] = preg_replace( '/[^a-z0-9_]/', '_', strtolower( trim( $raw_name ) ) );
        if ( ! preg_match( '/^[a-z]/', $field['name'] ) ) $field['name'] = 'f_' . $field['name'];
        $field['type'] = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'text';
        $field['required'] = ! empty( $item['required'] ) ? true : false;
        $field['hint'] = isset( $item['hint'] ) ? sanitize_text_field( $item['hint'] ) : '';
        $opts = '';
        if ( isset( $item['options'] ) ) {
            $parts = preg_split( '/\r\n|[\r\n]|,/', $item['options'] );
            $parts = array_map( 'trim', $parts );
            $parts = array_filter( $parts, 'strlen' );
            $parts = array_map( 'sanitize_text_field', $parts );
            $opts = implode( ',', $parts );
        }
        $field['options'] = $opts;

        // ensure uniqueness within array
        $original_name = $field['name'];
        $counter = 1;
        while ( codobuf_name_exists_in_array( $field['name'], $clean ) ) {
            $field['name'] = $original_name . '_' . $counter;
            $counter++;
        }

        $clean[] = $field;
    }

    // Save meta as array with keys
    $meta = [
        'mode' => $mode,
        'custom_fields' => wp_json_encode( $clean ),
    ];

    update_post_meta( $post_id, '_codobookings_user_fields', $meta );
}

/**
 * --------------------------
 * Utility functions for runtime retrieval
 * --------------------------
 */

/**
 * Retrieve global fields as array.
 * Returns array of field arrays.
 */
function codobuf_get_global_fields() {
    $option_name = codobuf_get_option_name();
    $raw = get_option( $option_name, wp_json_encode( codobuf_get_default_fields() ) );
    $arr = json_decode( $raw, true );
    if ( ! is_array( $arr ) ) return [];
    return $arr;
}

/**
 * Retrieve effective fields for a calendar post (post ID)
 * - If calendar meta mode = global => return global fields
 * - If none => return []
 * - If custom => return custom fields array
 */
function codobuf_get_fields_for_calendar( $post_id ) {
    $meta = get_post_meta( $post_id, '_codobookings_user_fields', true );
    if ( ! is_array( $meta ) ) return codobuf_get_global_fields();

    $mode = isset( $meta['mode'] ) ? $meta['mode'] : 'global';
    if ( $mode === 'none' ) return [];
    if ( $mode === 'custom' ) {
        $custom = isset( $meta['custom_fields'] ) ? json_decode( $meta['custom_fields'], true ) : [];
        if ( is_array( $custom ) ) return $custom;
        return [];
    }
    return codobuf_get_global_fields();
}

/**
 * Template helper: render a single field's HTML fragment (for front-end forms).
 * Note: This helper is intentionally lightweight and filterable. You may
 * override or extend rendering via 'codobookings_user_field_render' filter.
 */
function codobuf_render_field_html( $field, $value = '' ) {
    $default = codobuf_user_field_default_item();
    $field = wp_parse_args( $field, $default );

    // Allow filter to supply full HTML
    $filtered = apply_filters( 'codobookings_user_field_render', null, $field, $value );
    if ( ! is_null( $filtered ) ) {
        return $filtered;
    }

    $name_attr = esc_attr( 'codobuf_' . $field['name'] );
    $label     = esc_html( $field['label'] );
    $hint      = esc_attr( $field['hint'] );
    $required  = $field['required'] ? ' required' : '';
    $html = '<div class="codobuf-field codobuf-field-' . esc_attr( $field['type'] ) . '">';
    $html .= '<label for="' . $name_attr . '">' . $label;
    if ( $field['required'] ) $html .= ' <span aria-hidden="true" style="color:#a00;">*</span>';
    $html .= '</label><br>';

    switch ( $field['type'] ) {
        case 'number':
            $html .= '<input type="number" id="' . $name_attr . '" name="' . $name_attr . '" value="' . esc_attr( $value ) . '" placeholder="' . $hint . '"' . $required . '>';
            break;
        case 'textarea':
            $html .= '<textarea id="' . $name_attr . '" name="' . $name_attr . '" placeholder="' . $hint . '"' . $required . '>' . esc_textarea( $value ) . '</textarea>';
            break;
        case 'select':
            $opts = explode( ',', $field['options'] );
            $html .= '<select id="' . $name_attr . '" name="' . $name_attr . '"' . $required . '>';
            foreach ( $opts as $opt ) {
                $opt = trim( $opt );
                if ( $opt === '' ) continue;
                $html .= '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
            }
            $html .= '</select>';
            break;
        case 'radio':
            $opts = explode( ',', $field['options'] );
            foreach ( $opts as $opt ) {
                $opt = trim( $opt );
                if ( $opt === '' ) continue;
                $id = $name_attr . '_' . sanitize_title( $opt );
                $html .= '<label><input type="radio" id="' . $id . '" name="' . $name_attr . '" value="' . esc_attr( $opt ) . '"' . checked( $value, $opt, false ) . $required . '> ' . esc_html( $opt ) . '</label><br>';
            }
            break;
        case 'checkbox':
            $opts = explode( ',', $field['options'] );
            if ( count( $opts ) <= 1 ) {
                // single checkbox (boolean)
                $html .= '<label><input type="checkbox" id="' . $name_attr . '" name="' . $name_attr . '" value="1"' . checked( $value, '1', false ) . $required . '> ' . $hint . '</label>';
            } else {
                // multiple checkboxes
                foreach ( $opts as $opt ) {
                    $opt = trim( $opt );
                    if ( $opt === '' ) continue;
                    $id = $name_attr . '_' . sanitize_title( $opt );
                    $checked = '';
                    if ( is_array( $value ) && in_array( $opt, $value, true ) ) $checked = ' checked';
                    $html .= '<label><input type="checkbox" id="' . $id . '" name="' . $name_attr . '[]' . '" value="' . esc_attr( $opt ) . '"' . $checked . $required . '> ' . esc_html( $opt ) . '</label><br>';
                }
            }
            break;
        case 'file':
            $html .= '<input type="file" id="' . $name_attr . '" name="' . $name_attr . '"' . $required . '>';
            break;
        default:
            $html .= '<input type="text" id="' . $name_attr . '" name="' . $name_attr . '" value="' . esc_attr( $value ) . '" placeholder="' . $hint . '"' . $required . '>';
            break;
    }

    $html .= '</div>';

    return $html;
}

/**
 * --------------------------
 * Internationalization load
 * --------------------------
 */
add_action( 'init', 'codobuf_load_textdomain' );
function codobuf_load_textdomain() {
    load_plugin_textdomain( 'codobuf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * --------------------------
 * Developer notes & hooks
 * --------------------------
 *
 * Filters and actions provided:
 * - codobookings_user_fields_option_name (filter) : change option name
 * - codobookings_user_fields_default (filter) : default fields array
 * - codobookings_user_fields_sanitized (filter) : array after sanitization (before storing)
 * - codobookings_user_fields_saved (action) : fired after sanitization (before storing)
 * - codobookings_user_field_render (filter) : if returns non-null, it will be used as full HTML for a field
 *
 * Usage examples:
 * - To get global fields: codobuf_get_global_fields()
 * - To get effective calendar fields: codobuf_get_fields_for_calendar( $post_id )
 * - To render fields in a template, loop fields and call codobuf_render_field_html( $field, $value )
 *
 * Security:
 * - All admin saves are capability-checked and nonced.
 * - Input is sanitized thoroughly.
 *
 */

