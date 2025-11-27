<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Option name filterable
 */
function codobuf_get_option_name() {
    return apply_filters( 'codobookings_user_fields_option_name', 'codobookings_user_fields' );
}

/**
 * Default structure for a field
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
 * Default fields (filterable)
 */
function codobuf_get_default_fields() {
    return apply_filters( 'codobookings_user_fields_default', [] );
}

/**
 * Sanitizer for saved/global fields - JSON encoded in option
 */
function codobuf_sanitize_fields_array( $raw ) {
    if ( is_array( $raw ) ) {
        $data = $raw;
    } else {
        $data = json_decode( wp_unslash( $raw ), true );
        if ( ! is_array( $data ) ) {
            return json_encode( codobuf_get_default_fields() );
        }
    }

    $clean = [];
    foreach ( $data as $item ) {
        if ( ! is_array( $item ) ) continue;

        $field = codobuf_user_field_default_item();

        $field['label'] = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
        $raw_name = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
        if ( empty( $raw_name ) && ! empty( $field['label'] ) ) {
            $raw_name = sanitize_title( $field['label'] );
        }
        $field['name'] = preg_replace( '/[^a-z0-9_]/', '_', strtolower( trim( $raw_name ) ) );
        if ( ! preg_match( '/^[a-z]/', $field['name'] ) ) {
            $field['name'] = 'f_' . $field['name'];
        }

        // allowed types: no 'file'
        $allowed_types = [ 'text', 'number', 'textarea', 'select', 'radio', 'checkbox' ];
        $field['type'] = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : $field['type'];
        if ( ! in_array( $field['type'], $allowed_types, true ) ) {
            $field['type'] = 'text';
        }

        $field['required'] = ! empty( $item['required'] ) ? true : false;
        $field['hint']     = isset( $item['hint'] ) ? sanitize_text_field( $item['hint'] ) : '';

        // options only for select and radio (checkbox has no options UI)
        $field['options'] = '';
        if ( in_array( $field['type'], [ 'select', 'radio' ], true ) && ! empty( $item['options'] ) ) {
            $parts = preg_split( '/\r\n|[\r\n]|,/', $item['options'] );
            $parts = array_map( 'trim', $parts );
            $parts = array_filter( $parts, 'strlen' );
            $parts = array_map( 'sanitize_text_field', $parts );
            $field['options'] = implode( ',', $parts );
        }

        // unique names within array
        $original_name = $field['name'];
        $counter = 1;
        while ( codobuf_name_exists_in_array( $field['name'], $clean ) ) {
            $field['name'] = $original_name . '_' . $counter;
            $counter++;
        }

        $clean[] = $field;
    }

    $clean = apply_filters( 'codobookings_user_fields_sanitized', $clean );

    do_action( 'codobookings_user_fields_saved', $clean );

    return wp_json_encode( $clean );
}

/**
 * Check if name exists
 */
function codobuf_name_exists_in_array( $name, $arr ) {
    foreach ( $arr as $item ) {
        if ( isset( $item['name'] ) && $item['name'] === $name ) return true;
    }
    return false;
}

/**
 * Get global fields (array)
 */
function codobuf_get_global_fields() {
    $raw = get_option( codobuf_get_option_name(), wp_json_encode( codobuf_get_default_fields() ) );
    $arr = json_decode( $raw, true );
    if ( ! is_array( $arr ) ) return [];
    return $arr;
}

/**
 * Get effective fields for a calendar post
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
 * Render HTML for a field in frontend forms.
 * NOTE: no inline CSS here, only HTML. Render is filterable.
 */
function codobuf_render_field_html( $field, $value = '' ) {
    $default = codobuf_user_field_default_item();
    $field = wp_parse_args( $field, $default );

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
    if ( $field['required'] ) $html .= ' <span class="codobuf-required-star" aria-hidden="true">*</span>';
    $html .= '</label>';

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
            // single boolean checkbox
            $html .= '<label><input type="checkbox" id="' . $name_attr . '" name="' . $name_attr . '" value="1"' . checked( $value, '1', false ) . $required . '> ' . $hint . '</label>';
            break;
        default:
            $html .= '<input type="text" id="' . $name_attr . '" name="' . $name_attr . '" value="' . esc_attr( $value ) . '" placeholder="' . $hint . '"' . $required . '>';
            break;
    }

    $html .= '</div>';

    return $html;
}

/**
 * Render user fields for a specific calendar post.
 */
function codobuf_render_user_fields_for_calendar( $calendar_id ) {
    $fields = codobuf_get_fields_for_calendar( $calendar_id );
    if ( empty( $fields ) ) return '';

    ob_start();
    echo '<div class="codobuf-user-fields-wrapper">';
    foreach ( $fields as $field ) {
        echo codobuf_render_field_html( $field );
    }
    echo '</div>';

    return ob_get_clean();
}

add_action( 'codobookings_before_calendar', function( $calendar_id ) {
    $meta = get_post_meta( $calendar_id, '_codobookings_user_fields', true );
    $position = isset( $meta['position'] ) ? $meta['position'] : 'before';

    // Only render here if position is BEFORE
    if ( $position === 'before' ) {
        echo codobuf_render_user_fields_for_calendar( $calendar_id );
    }
});

add_action( 'codobookings_after_calendar', function( $calendar_id ) {
    $meta = get_post_meta( $calendar_id, '_codobookings_user_fields', true );
    $position = isset( $meta['position'] ) ? $meta['position'] : 'before';

    // Only render here if position is AFTER
    if ( $position === 'after' ) {
        echo codobuf_render_user_fields_for_calendar( $calendar_id );
    }
});

/**
 * Capture user fields data before booking is inserted
 */
add_filter( 'codobookings_before_booking_insert', 'codobuf_capture_user_fields', 10, 1 );
function codobuf_capture_user_fields( $booking_data ) {

    if ( empty( $booking_data['calendar_id'] ) ) {
        return $booking_data;
    }

    $calendar_id = absint( $booking_data['calendar_id'] );
    $fields = codobuf_get_fields_for_calendar( $calendar_id );
    
    if ( empty( $fields ) ) {
        return $booking_data;
    }

    $saved = [];

    foreach ( $fields as $field ) {
        $key = 'codobuf_' . $field['name'];
        $raw = null;

        // Check multiple sources for the data
        if ( isset( $booking_data[ $key ] ) ) {
            $raw = $booking_data[ $key ];
        } elseif ( isset( $_POST[ $key ] ) ) {
            $raw = wp_unslash( $_POST[ $key ] );
        } elseif ( isset( $_REQUEST[ $key ] ) ) {
            $raw = wp_unslash( $_REQUEST[ $key ] );
        }

        if ( $raw === null ) {
            // Handle unchecked checkboxes
            if ( $field['type'] === 'checkbox' ) {
                $saved[ $field['name'] ] = 0;
            }
            continue;
        }

        // Sanitize based on field type
        switch ( $field['type'] ) {
            case 'number':
                $saved[ $field['name'] ] = floatval( $raw );
                break;
            case 'checkbox':
                $saved[ $field['name'] ] = ( $raw === '1' || $raw === 1 || $raw === true ) ? 1 : 0;
                break;
            default:
                $saved[ $field['name'] ] = sanitize_text_field( $raw );
        }
    }

    $booking_data['user_fields_data'] = $saved;

    return $booking_data;
}

/**
 * Store user fields meta after booking is created
 */
add_action( 'codobookings_after_ajax_create_booking', 'codobuf_store_user_fields_meta', 10, 2 );
function codobuf_store_user_fields_meta( $booking_id, $booking_data ) {

    $saved = isset( $booking_data['user_fields_data'] ) ? $booking_data['user_fields_data'] : [];

    if ( ! empty( $saved ) ) {
        update_post_meta( $booking_id, '_codobuf_user_fields', wp_json_encode( $saved ) );
    }
}

add_action( 'add_meta_boxes_codo_booking', 'codobuf_add_booking_user_fields_metabox' );
function codobuf_add_booking_user_fields_metabox() {
    add_meta_box(
        'codobuf-booking-fields',
        __('User Fields Data', 'codobuf'),
        'codobuf_render_booking_user_fields_metabox',
        'codo_booking',
        'normal',
        'default'
    );
}

function codobuf_render_booking_user_fields_metabox( $post ) {

    $json = get_post_meta( $post->ID, '_codobuf_user_fields', true );
    
    $data = json_decode( $json, true );

    if ( empty( $data ) ) {
        echo '<p>No user field data saved for this booking.</p>';
        return;
    }

    echo '<table class="widefat striped"><tbody>';

    foreach ( $data as $name => $value ) {
        $label = $name; // fallback

        // Try to detect original label (optional improvement)
        echo '<tr>
                <th style="width:200px;">' . esc_html( ucwords( str_replace('_',' ', $name ) ) ) . '</th>
                <td>' . esc_html( $value ) . '</td>
              </tr>';
    }

    echo '</tbody></table>';
}