<?php
/**
 * Sync WordPress users with ScorpioPlay players.
 */

// Hook after successful registration
add_action( 'user_register', 'scp_create_player_on_register', 10, 1 );

function scp_create_player_on_register( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) return;

    $api = new SCP_API_Client();

    // Determine if the WP user is an admin (map to operator) or a regular player
    $is_admin = in_array( 'administrator', (array) $user->roles, true );

    if ( $is_admin ) {
        // Create an operator on the platform for WP administrators
        $password = $_POST['pass1'] ?? wp_generate_password();

        $payload = [
            'operatorId' => strtolower( preg_replace( '/[^a-z0-9_]/', '_', $user->user_login ) ),
            'password'   => $password,
            'name'       => $user->display_name ?: $user->user_login,
            'ggrRate'    => 5,
            'level'      => 'operator',
        ];

        $result = $api->request( '/v1/operator/create', 'POST', $payload );

        if ( ! empty( $result['success'] ) && ! empty( $result['data']['id'] ) ) {
            update_user_meta( $user_id, 'scp_operator_id', $result['data']['id'] );
            if ( ! empty( $result['data']['token'] ) ) {
                update_user_meta( $user_id, 'scp_token', $result['data']['token'] );
            }
            scp_log_user_event( $user_id, 'Operator created', $result['data'] );
        } else {
            error_log( 'ScorpioPlay: Failed to create operator for WP user ' . $user_id . ' - ' . json_encode( $result ) );
            scp_log_user_event( $user_id, 'Operator creation failed', $result );
        }
    } else {
        // Create a player on the platform for non-admin WP users
        $payload = [
            'playerExternalId' => $user->user_login,
        ];

        $result = $api->request( '/v1/player/create', 'POST', $payload );

        if ( ! empty( $result['success'] ) && ! empty( $result['data']['playerCode'] ) ) {
            update_user_meta( $user_id, 'scp_player_code', $result['data']['playerCode'] );
            scp_log_user_event( $user_id, 'Player created', $result['data'] );
        } else {
            error_log( 'ScorpioPlay: Failed to create player for WP user ' . $user_id . ' - ' . json_encode( $result ) );
            scp_log_user_event( $user_id, 'Player creation failed', $result );
        }
    }
}

// On login, ensure token exists (for existing users or if token missing)
add_action( 'wp_login', 'scp_ensure_token_on_login', 10, 2 );

function scp_ensure_token_on_login( $user_login, $user ) {
    // Ensure platform records exist for this WP user on login.
    $api = new SCP_API_Client();

    $is_admin = in_array( 'administrator', (array) $user->roles, true );

    if ( $is_admin ) {
        return;
        $operator_id = get_user_meta( $user->ID, 'scp_operator_id', true );
        $token = get_user_meta( $user->ID, 'scp_token', true );

        if ( empty( $operator_id ) ) {
            // Create operator if not present (best-effort)
            $password = wp_generate_password();
            $payload = [
                'operatorId' => strtolower( preg_replace( '/[^a-z0-9_]/', '_', $user->user_login ) ),
                'password'   => $password,
                'name'       => $user->display_name ?: $user->user_login,
                'ggrRate'    => 5,
                'level'      => 'operator',
            ];

            $result = $api->request( '/v1/operator/create', 'POST', $payload );
            if ( ! empty( $result['success'] ) && ! empty( $result['data']['id'] ) ) {
                update_user_meta( $user->ID, 'scp_operator_id', $result['data']['id'] );
                if ( ! empty( $result['data']['token'] ) ) {
                    update_user_meta( $user->ID, 'scp_token', $result['data']['token'] );
                }
                scp_log_user_event( $user->ID, 'Operator created on login', $result['data'] );
            } else {
                error_log( 'ScorpioPlay: Failed to create operator on login for WP user ' . $user->ID . ' - ' . json_encode( $result ) );
                scp_log_user_event( $user->ID, 'Operator creation on login failed', $result );
            }
        } elseif ( empty( $token ) ) {
            // Operator exists but no token available; API doesn't provide public token endpoint.
            error_log( 'ScorpioPlay: Operator exists for WP user ' . $user->ID . ' but scp_token is missing.' );
            scp_log_user_event( $user->ID, 'Operator missing token on login' );
        }
    } else {
        $player_code = get_user_meta( $user->ID, 'scp_player_code', true );
        if ( empty( $player_code ) ) {
            $payload = [ 'playerExternalId' => $user->user_login ];
            $result = $api->request( '/v1/player/create', 'POST', $payload );
            if ( ! empty( $result['success'] ) && ! empty( $result['data']['playerCode'] ) ) {
                update_user_meta( $user->ID, 'scp_player_code', $result['data']['playerCode'] );
                scp_log_user_event( $user->ID, 'Player created on login', $result['data'] );
            } else {
                error_log( 'ScorpioPlay: Failed to create player on login for WP user ' . $user->ID . ' - ' . json_encode( $result ) );
                scp_log_user_event( $user->ID, 'Player creation on login failed', $result );
            }
        }
    }
}

/**
 * Append an event to user's ScorpioPlay history (stored in user meta `scp_history`).
 */
function scp_log_user_event( $user_id, $message, $data = null ) {
    $hist = get_user_meta( $user_id, 'scp_history', true );
    if ( ! is_array( $hist ) ) {
        $hist = [];
    }
    $hist[] = [
        'time'    => current_time( 'mysql' ),
        'message' => $message,
        'data'    => $data,
    ];
    update_user_meta( $user_id, 'scp_history', $hist );
}

function scp_get_user_history_html( $user_id ) {
    $hist = get_user_meta( $user_id, 'scp_history', true );
    if ( empty( $hist ) || ! is_array( $hist ) ) {
        return '<em>No history</em>';
    }
    $out = '<ul class="scp-history-list">';
    foreach ( array_reverse( $hist ) as $h ) {
        $time = esc_html( $h['time'] ?? '' );
        $msg  = esc_html( $h['message'] ?? '' );
        $data = '';
        if ( ! empty( $h['data'] ) ) {
            $data = '<pre style="white-space:pre-wrap">' . esc_html( wp_json_encode( $h['data'], JSON_PRETTY_PRINT ) ) . '</pre>';
        }
        $out .= "<li><strong>$time</strong>: $msg $data</li>";
    }
    $out .= '</ul>';
    return $out;
}

function scp_get_player_info_by_external_id( $player_external_id ) {
    if ( empty( $player_external_id ) ) {
        return [ 'success' => false, 'message' => 'Missing playerExternalId' ];
    }

    $api = new SCP_API_Client();
    $endpoint = '/v1/player/info?playerExternalId=' . rawurlencode( $player_external_id );
    $result = $api->request( $endpoint, 'GET' );
    return $result;
}

function scp_sync_player_info_for_user( $user ) {
    if ( ! $user || ! isset( $user->user_login ) ) {
        return [ 'player_code' => '', 'balance' => [] ];
    }

    $player_code = get_user_meta( $user->ID, 'scp_player_code', true );
    $balance = get_user_meta( $user->ID, 'scp_player_balance', true );

    if ( ! empty( $player_code ) && ! empty( $balance ) ) {
        return [ 'player_code' => $player_code, 'balance' => is_array( $balance ) ? $balance : [] ];
    }

    $result = scp_get_player_info_by_external_id( $user->user_login );
    if ( ! empty( $result['success'] ) && ! empty( $result['data'] ) ) {
        if ( ! empty( $result['data']['playerCode'] ) ) {
            $player_code = $result['data']['playerCode'];
            update_user_meta( $user->ID, 'scp_player_code', $player_code );
        }

        if ( ! empty( $result['data']['balance'] ) && is_array( $result['data']['balance'] ) ) {
            $balance = $result['data']['balance'];
            update_user_meta( $user->ID, 'scp_player_balance', $balance );
        }
    }

    return [ 'player_code' => $player_code ?: '', 'balance' => is_array( $balance ) ? $balance : [] ];
}

function scp_format_player_balance( $balance ) {
    if ( empty( $balance ) || ! is_array( $balance ) ) {
        return 'N/A';
    }

    $lines = [];
    foreach ( $balance as $entry ) {
        if ( is_array( $entry ) && isset( $entry['currency'], $entry['amount'] ) ) {
            $lines[] = esc_html( $entry['currency'] . ': ' . $entry['amount'] );
        }
    }

    return ! empty( $lines ) ? implode( '<br />', $lines ) : 'N/A';
}

add_action( 'show_user_profile', 'scp_show_user_profile_fields' );
add_action( 'edit_user_profile', 'scp_show_user_profile_fields' );

function scp_show_user_profile_fields( $user ) {
    if ( empty( $user->ID ) ) {
        return;
    }

    $sync = scp_sync_player_info_for_user( $user );
    $player_code = $sync['player_code'];
    $balance = $sync['balance'];

    if ( ! is_array( $balance ) ) {
        $balance = [];
    }

    $balance_html = 'N/A';
    if ( ! empty( $balance ) ) {
        $balance_lines = [];
        foreach ( $balance as $entry ) {
            if ( is_array( $entry ) && isset( $entry['currency'], $entry['amount'] ) ) {
                $balance_lines[] = esc_html( $entry['currency'] . ': ' . $entry['amount'] );
            }
        }
        if ( ! empty( $balance_lines ) ) {
            $balance_html = implode( '<br />', $balance_lines );
        }
    }
    ?>
    <h2>ScorpioPlay Player Data</h2>
    <table class="form-table">
        <tr>
            <th><label for="scp_player_code">Player Code</label></th>
            <td>
                <input type="text" name="scp_player_code" id="scp_player_code" value="<?php echo esc_attr( $player_code ?: '' ); ?>" class="regular-text" disabled="disabled" />
                <p class="description">The ScorpioPlay player code stored for this user.</p>
            </td>
        </tr>
        <tr>
            <th><label>Balance</label></th>
            <td>
                <div style="padding:8px; background:#f7f7f7; border:1px solid #ddd; max-width:420px; white-space:pre-wrap;"><?php echo $balance_html; ?></div>
                <p class="description">The latest ScorpioPlay balance for this user.</p>
            </td>
        </tr>
    </table>
    <?php
}


/**
 * AJAX: Create operator for a given WP user ID.
 */
add_action( 'wp_ajax_scp_create_operator', 'scp_ajax_create_operator' );
function scp_ajax_create_operator() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'scp_ajax_nonce', 'nonce' );
    $uid = isset( $_POST['uid'] ) ? intval( $_POST['uid'] ) : 0;
    if ( ! $uid ) wp_send_json_error( 'Invalid user id' );
    $user = get_userdata( $uid );
    if ( ! $user ) wp_send_json_error( 'User not found' );

    $api = new SCP_API_Client();
    $password = wp_generate_password();
    $payload = [
        'operatorId' => strtolower( preg_replace( '/[^a-z0-9_]/', '_', $user->user_login ) ),
        'password'   => $password,
        'name'       => $user->display_name ?: $user->user_login,
        'ggrRate'    => 5,
        'level'      => 'operator',
    ];
    $result = $api->request( '/v1/operator/create', 'POST', $payload );
    if ( empty( $result['success'] ) || empty( $result['data']['id'] ) ) {
        scp_log_user_event( $uid, 'Operator creation (AJAX) failed', $result );
        wp_send_json_error( $result );
    }
    update_user_meta( $uid, 'scp_operator_id', $result['data']['id'] );
    if ( ! empty( $result['data']['token'] ) ) {
        update_user_meta( $uid, 'scp_token', $result['data']['token'] );
    }
    scp_log_user_event( $uid, 'Operator created (AJAX)', $result['data'] );
    $history_html = scp_get_user_history_html( $uid );
    wp_send_json_success( [ 'operator_id' => $result['data']['id'], 'token' => $result['data']['token'] ?? '', 'history_html' => $history_html ] );
}

/**
 * AJAX: Create player for a given WP user ID.
 */
add_action( 'wp_ajax_scp_create_player', 'scp_ajax_create_player' );
function scp_ajax_create_player() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'scp_ajax_nonce', 'nonce' );
    $uid = isset( $_POST['uid'] ) ? intval( $_POST['uid'] ) : 0;
    if ( ! $uid ) wp_send_json_error( 'Invalid user id' );
    $user = get_userdata( $uid );
    if ( ! $user ) wp_send_json_error( 'User not found' );

    $api = new SCP_API_Client();
    $payload = [ 'playerExternalId' => $user->user_login ];
    $result = $api->request( '/v1/player/create', 'POST', $payload );
    if ( empty( $result['success'] ) || empty( $result['data']['playerCode'] ) ) {
        scp_log_user_event( $uid, 'Player creation (AJAX) failed', $result );
        wp_send_json_error( $result );
    }
    update_user_meta( $uid, 'scp_player_code', $result['data']['playerCode'] );
    scp_log_user_event( $uid, 'Player created (AJAX)', $result['data'] );
    $history_html = scp_get_user_history_html( $uid );
    wp_send_json_success( [ 'player_code' => $result['data']['playerCode'], 'history_html' => $history_html ] );
}


/**
 * AJAX: Load user sync row data for the edit modal.
 */
add_action( 'wp_ajax_scp_get_user_sync_row_data', 'scp_ajax_get_user_sync_row_data' );
function scp_ajax_get_user_sync_row_data() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'scp_ajax_nonce', 'nonce' );
    $uid = isset( $_POST['uid'] ) ? intval( $_POST['uid'] ) : 0;
    if ( ! $uid ) {
        wp_send_json_error( 'Invalid user id' );
    }

    $user = get_userdata( $uid );
    if ( ! $user ) {
        wp_send_json_error( 'User not found' );
    }

    $operator_id = get_user_meta( $uid, 'scp_operator_id', true );
    $player_code = get_user_meta( $uid, 'scp_player_code', true );
    $balance = get_user_meta( $uid, 'scp_player_balance', true );
    $token = get_user_meta( $uid, 'scp_token', true );

    if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
        $sync = scp_sync_player_info_for_user( $user );
        $player_code = $sync['player_code'];
        $balance = $sync['balance'];
    }

    $operator_info = [];
    if ( in_array( 'administrator', (array) $user->roles, true ) ) {
        $api = new SCP_API_Client();
        $result = $api->request( '/v1/operator/info', 'GET' );
        if ( ! empty( $result['success'] ) && ! empty( $result['data'] ) ) {
            $operator_info = $result['data'];
        } else {
            $operator_info = [ 'error' => $result['message'] ?? 'Unable to fetch operator info' ];
        }
    }

    wp_send_json_success( [
        'uid' => $uid,
        'user_login' => $user->user_login,
        'display_name' => $user->display_name,
        'roles' => implode( ',', (array) $user->roles ),
        'operator_id' => $operator_id,
        'player_code' => $player_code,
        'balance' => is_array( $balance ) ? $balance : [],
        'scp_token' => $token,
        'operator_info' => wp_json_encode( $operator_info, JSON_PRETTY_PRINT ),
    ] );
}

/**
 * AJAX: Save a single mapping from the edit modal.
 */
add_action( 'wp_ajax_scp_save_user_mapping', 'scp_ajax_save_user_mapping' );
function scp_ajax_save_user_mapping() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'scp_ajax_nonce', 'nonce' );

    $uid = isset( $_POST['uid'] ) ? intval( $_POST['uid'] ) : 0;
    if ( ! $uid ) {
        wp_send_json_error( 'Invalid user id' );
    }

    $operator_id = isset( $_POST['operator_id'] ) ? sanitize_text_field( wp_unslash( $_POST['operator_id'] ) ) : '';
    $player_code = isset( $_POST['player_code'] ) ? sanitize_text_field( wp_unslash( $_POST['player_code'] ) ) : '';
    $token = isset( $_POST['scp_token'] ) ? sanitize_text_field( wp_unslash( $_POST['scp_token'] ) ) : '';

    update_user_meta( $uid, 'scp_operator_id', $operator_id );
    update_user_meta( $uid, 'scp_player_code', $player_code );
    update_user_meta( $uid, 'scp_token', $token );

    scp_log_user_event( $uid, 'User mapping updated via modal', [
        'operator_id' => $operator_id,
        'player_code' => $player_code,
        'scp_token' => $token,
    ] );

    wp_send_json_success( 'Saved' );
}

