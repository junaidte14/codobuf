<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register setting and settings tab
 */
add_action( 'admin_init', function() {
    $option_name = codobuf_get_option_name();

    register_setting( 'codobookings_options', $option_name, [
        'type'              => 'string',
        'sanitize_callback' => 'codobuf_sanitize_fields_array',
        'default'           => wp_json_encode( codobuf_get_default_fields() ),
    ] );

    add_filter( 'codobookings_settings_tabs', function( $tabs ) {
        $tabs['user_fields'] = [
            'label'    => __( 'User Fields', 'codobuf' ),
            'callback' => 'codobuf_render_settings_tab',
        ];
        return $tabs;
    } );
} );

/**
 * Render the settings tab UI
 */
function codobuf_render_settings_tab() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $option_name = codobuf_get_option_name();
    $stored      = get_option( $option_name, wp_json_encode( codobuf_get_default_fields() ) );
    $fields      = json_decode( $stored, true );
    if ( ! is_array( $fields ) ) $fields = [];

    // Render HTML but NO inline styles/scripts — classes only. JS/CSS provided by admin assets.
    ?>
    <h2><?php esc_html_e( 'User Fields', 'codobuf' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Define custom user fields used by CodoBookings. Drag to reorder. Names are auto-generated from the label but can be edited.', 'codobuf' ); ?></p>

    <table class="form-table">
        <tr>
            <th><?php esc_html_e( 'Fields editor', 'codobuf' ); ?></th>
            <td>
                <div id="codobuf-fields-editor" class="codobuf-editor" data-target-input="<?php echo esc_attr( codobuf_get_option_name() ); ?>">

                    <ul id="codobuf-fields-list" class="codobuf-sortable codobuf-fields-list">
                        <?php foreach ( $fields as $index => $f ) : ?>
                            <?php echo codobuf_admin_render_field_li( $f, $index ); ?>
                        <?php endforeach; ?>
                    </ul>

                    <div class="codobuf-editor-actions">
                        <button type="button" id="codobuf-add-field" class="button"><?php esc_html_e( 'Add Field', 'codobuf' ); ?></button>
                        <span class="description"><?php esc_html_e( 'Use the editor to add, edit, reorder or remove fields.', 'codobuf' ); ?></span>
                    </div>

                    <input type="hidden" id="codobuf-fields-json" name="<?php echo esc_attr( codobuf_get_option_name() ); ?>" value="<?php echo esc_attr( wp_json_encode( $fields ) ); ?>">
                </div>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Helper: render one field LI for admin lists (shared between settings and metabox)
 */
function codobuf_admin_render_field_li( $f, $index = 0, $open = false ) {
    // ensure field has defaults
    $field = wp_parse_args( (array) $f, codobuf_user_field_default_item() );

    // collapsed by default unless $open true
    $settings_class = $open ? 'codobuf-field-settings open' : 'codobuf-field-settings collapsed';

    // available types (no 'file')
    $types = [ 'text' => __( 'Text', 'codobuf' ), 'number' => __( 'Number', 'codobuf' ), 'textarea' => __( 'Textarea', 'codobuf' ), 'select' => __( 'Select', 'codobuf' ), 'radio' => __( 'Radio', 'codobuf' ), 'checkbox' => __( 'Checkbox', 'codobuf' ) ];

    ob_start();
    ?>
    <li class="codobuf-field-item" data-index="<?php echo esc_attr( $index ); ?>">
        <div class="codobuf-field-summary">
            <span class="dashicons dashicons-menu"></span>
            <strong class="codobuf-field-preview"><?php echo esc_html( $field['label'] ?: __( 'Untitled', 'codobuf' ) ); ?></strong>
            <small class="codobuf-field-type-label">[<?php echo esc_html( $field['type'] ); ?>]</small>
            <a href="#" class="codobuf-toggle-edit"><?php esc_html_e( 'Edit', 'codobuf' ); ?></a>
            <a href="#" class="codobuf-remove-field" aria-label="<?php esc_attr_e( 'Remove field', 'codobuf' ); ?>"><?php esc_html_e( 'Remove', 'codobuf' ); ?></a>
        </div>

        <div class="<?php echo esc_attr( $settings_class ); ?>">
            <table class="form-table codobuf-field-table">
                <tr>
                    <td class="codobuf-col-label">
                        <label><?php esc_html_e( 'Label', 'codobuf' ); ?></label><br>
                        <input type="text" class="codobuf-field-label regular-text" value="<?php echo esc_attr( $field['label'] ); ?>">
                    </td>
                    <td class="codobuf-col-name">
                        <label><?php esc_html_e( 'Name (unique)', 'codobuf' ); ?></label><br>
                        <input type="text" class="codobuf-field-name regular-text" value="<?php echo esc_attr( $field['name'] ); ?>">
                        <p class="description"><?php esc_html_e( 'Only lowercase letters, numbers and underscores. Auto-generated from label.', 'codobuf' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <td>
                        <label><?php esc_html_e( 'Type', 'codobuf' ); ?></label><br>
                        <select class="codobuf-field-type">
                            <?php foreach ( $types as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $field['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <label><?php esc_html_e( 'Required', 'codobuf' ); ?></label><br>
                        <label><input type="checkbox" class="codobuf-field-required" <?php checked( $field['required'], true ); ?>> <?php esc_html_e( 'Yes', 'codobuf' ); ?></label>
                    </td>
                </tr>

                <tr class="codobuf-options-row" <?php if ( ! in_array( $field['type'], [ 'select', 'radio' ], true ) ) echo 'style="display:none;"'; ?>>
                    <td colspan="2">
                        <label><?php esc_html_e( 'Options (comma or newline separated)', 'codobuf' ); ?></label><br>
                        <textarea class="codobuf-field-options" rows="3"><?php echo esc_textarea( str_replace( ',', "\n", $field['options'] ) ); ?></textarea>
                    </td>
                </tr>

                <tr>
                    <td colspan="2">
                        <label><?php esc_html_e( 'Hint / placeholder', 'codobuf' ); ?></label><br>
                        <input type="text" class="codobuf-field-hint regular-text" value="<?php echo esc_attr( $field['hint'] ); ?>">
                    </td>
                </tr>
            </table>
        </div>
    </li>
    <?php
    return ob_get_clean();
}
