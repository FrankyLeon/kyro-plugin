<?php
/**
 * Transaction logging and wallet management for ScorpioPlay.
 */

// Create transaction log table on plugin activation and ensure schema upgrades on init
register_activation_hook( plugin_dir_path( dirname( __FILE__ ) ) . 'scorpioplay-core.php', 'scp_create_transaction_table' );
add_action( 'init', 'scp_upgrade_transaction_table' );

function scp_create_transaction_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        txn_id varchar(100) NOT NULL UNIQUE,
        user_id bigint(20) NOT NULL,
        user_login varchar(100),
        type varchar(20) NOT NULL,
        amount decimal(18,2) NOT NULL,
        currency varchar(3) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        gateway_txn_id varchar(150) DEFAULT NULL,
        scp_txn_id varchar(150) DEFAULT NULL,
        response longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY user_id (user_id),
        KEY txn_id (txn_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

function scp_upgrade_transaction_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';
    $columns = $wpdb->get_results( "SHOW COLUMNS FROM $table_name" );
    if ( ! $columns ) {
        return;
    }

    $existing = wp_list_pluck( $columns, 'Field' );
    if ( ! in_array( 'gateway_txn_id', $existing, true ) || ! in_array( 'scp_txn_id', $existing, true ) ) {
        scp_create_transaction_table();
    }
}

/**
 * Log a wallet transaction.
 *
 * @param string $txn_id Unique transaction ID
 * @param string $user_id External user ID from ScorpioPlay API
 * @param string $type 'deposit' or 'withdraw'
 * @param float $amount Amount in transaction
 * @param string $currency Currency code (USD, EUR, etc)
 * @param string $status 'pending', 'completed', or 'failed'
 * @param array $response API response data
 */
function scp_log_transaction( $txn_id, $user_id, $type, $amount, $currency, $status = 'pending', $response = null, $gateway_txn_id = '', $scp_txn_id = '' ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';

    $wp_user = get_user_by( 'login', $user_id );

    $data = [
        'txn_id'         => $txn_id,
        'user_id'        => $wp_user ? $wp_user->ID : 0,
        'user_login'     => $user_id,
        'type'           => $type,
        'amount'         => $amount,
        'currency'       => $currency,
        'status'         => $status,
        'gateway_txn_id' => $gateway_txn_id ?: null,
        'scp_txn_id'     => $scp_txn_id ?: null,
        'response'       => $response ? wp_json_encode( $response ) : null,
    ];

    $format = [ '%s', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ];

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM $table_name WHERE txn_id = %s",
        $txn_id
    ) );

    if ( $existing ) {
        $wpdb->update( $table_name, $data, [ 'id' => $existing->id ], $format, [ '%d' ] );
    } else {
        $wpdb->insert( $table_name, $data, $format );
    }

    error_log( "ScorpioPlay Transaction: $type $amount $currency (txn_id: $txn_id) - Status: $status" );
}

function scp_add_transaction( $wp_user_id, $player_external_id, $type, $amount, $currency = 'USD', $status = 'pending', $gateway_txn_id = '', $scp_txn_id = '', $response = null ) {
    $user = get_userdata( $wp_user_id );
    $player_login = $player_external_id ?: ( $user ? $user->user_login : '' );
    $txn_id = 'wp-' . strtolower( $type ) . '-' . uniqid();
    scp_log_transaction( $txn_id, $player_login, $type, $amount, $currency, $status, $response, $gateway_txn_id, $scp_txn_id );
    return $txn_id;
}

/**
 * Get player balance from API.
 *
 * @param string $user_id External user ID
 * @param string $currency Currency code (optional, e.g., 'USD')
 * @return array Balance data or error
 */
function scp_get_player_balance( $user_id, $currency = null ) {
    $api = new SCP_API_Client();
    $response = $api->request( '/v1/player/info', 'GET', [], [
        'playerExternalId' => $user_id,
    ] );

    if ( empty( $response['success'] ) ) {
        return [ 'success' => false, 'message' => 'Failed to fetch balance' ];
    }

    if ( empty( $response['data']['balance'] ) ) {
        return [ 'success' => false, 'message' => 'No balance data' ];
    }

    $balances = $response['data']['balance'];

    // Filter by currency if specified
    if ( $currency ) {
        $filtered = array_filter( $balances, function( $b ) use ( $currency ) {
            return strtoupper( $b['currency'] ) === strtoupper( $currency );
        } );
        return [ 'success' => true, 'balances' => array_values( $filtered ) ];
    }

    return [ 'success' => true, 'balances' => $balances ];
}

/**
 * Get player balance for current logged-in user via AJAX.
 */
add_action( 'wp_ajax_scp_get_balance', 'scp_ajax_get_balance' );
add_action( 'wp_ajax_nopriv_scp_get_balance', 'scp_ajax_get_balance' );

function scp_ajax_get_balance() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in' ] );
    }

    $user = wp_get_current_user();
    $balance = scp_get_player_balance( $user->user_login );

    if ( $balance['success'] ) {
        wp_send_json_success( $balance['balances'] );
    } else {
        wp_send_json_error( [ 'message' => $balance['message'] ] );
    }
}

/**
 * Get transaction history for a user.
 *
 * @param int $wp_user_id WordPress user ID
 * @param int $limit Number of transactions to retrieve
 * @return array Transaction records
 */
function scp_get_user_transactions( $wp_user_id, $limit = 50 ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
        $wp_user_id,
        $limit
    ) );
}

/**
 * Get transaction by ID.
 *
 * @param string $txn_id Transaction ID
 * @return object|null Transaction record
 */
function scp_get_transaction( $txn_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table_name WHERE txn_id = %s",
        $txn_id
    ) );
}

/**
 * Example: Process a player deposit via checkout.
 * Call this after payment is confirmed in your checkout system.
 *
 * @param int $wp_user_id WordPress user ID
 * @param float $amount Amount to deposit
 * @param string $currency Currency code
 * @param string $order_id External order ID for txn_id
 * @param string $player_external_id Optional player external ID (if not using WP user)
 */
function scp_process_player_deposit( $wp_user_id, $amount, $currency = 'USD', $order_id = null, $player_external_id = '' ) {
    if ( empty( $player_external_id ) ) {
        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            return [ 'success' => false, 'message' => 'User not found' ];
        }
        $player_external_id = $user->user_login;
    }

    if ( ! $order_id ) {
        $order_id = 'deposit_' . $wp_user_id . '_' . time();
    }

    $api = new SCP_API_Client();
    return $api->deposit( $player_external_id, $amount, $currency, $order_id );
}

/**
 * Example: Process a player withdrawal.
 * Call this after withdrawal request is approved in admin panel.
 *
 * @param int $wp_user_id WordPress user ID
 * @param float $amount Amount to withdraw
 * @param string $currency Currency code
 * @param string $withdrawal_id External withdrawal ID for txn_id
 * @param string $player_external_id Optional player external ID (if not using WP user)
 */
function scp_process_player_withdrawal( $wp_user_id, $amount, $currency = 'USD', $withdrawal_id = null, $player_external_id = '' ) {
    if ( empty( $player_external_id ) ) {
        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            return [ 'success' => false, 'message' => 'User not found' ];
        }
        $player_external_id = $user->user_login;
    }

    if ( ! $withdrawal_id ) {
        $withdrawal_id = 'withdraw_' . $wp_user_id . '_' . time();
    }

    $api = new SCP_API_Client();
    return $api->withdraw( $player_external_id, $amount, $currency, $withdrawal_id );
}

function scp_normalize_wallet_type( $type ) {
    $type = strtolower( (string) $type );
    if ( in_array( $type, array( 'withdraw', 'withdrawal' ), true ) ) {
        return 'withdraw';
    }

    return 'deposit';
}

function scp_decode_transaction_response( $tx ) {
    if ( is_array( $tx ) ) {
        $raw = $tx['response'] ?? '';
    } elseif ( is_object( $tx ) ) {
        $raw = $tx->response ?? '';
    } else {
        $raw = '';
    }

    $data = json_decode( (string) $raw, true );
    return is_array( $data ) ? $data : array();
}

function scp_sanitize_card_meta( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }

    $number = preg_replace( '/\D+/', '', (string) ( $raw['cardNumber'] ?? $raw['card_number'] ?? '' ) );

    return array(
        'cardholderName' => sanitize_text_field( $raw['cardholderName'] ?? $raw['cardholder_name'] ?? '' ),
        'last4'          => $number ? substr( $number, -4 ) : '',
        'expiry'         => sanitize_text_field( $raw['expiry'] ?? '' ),
    );
}

function scp_sanitize_withdraw_destination( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }

    $method = strtolower( sanitize_text_field( $raw['method'] ?? 'bank' ) );
    if ( ! in_array( $method, array( 'bank', 'crypto', 'paypal' ), true ) ) {
        $method = 'bank';
    }

    return array(
        'method'        => $method,
        'accountName'   => sanitize_text_field( $raw['accountName'] ?? $raw['account_name'] ?? '' ),
        'accountNumber' => sanitize_text_field( $raw['accountNumber'] ?? $raw['account_number'] ?? '' ),
        'bankName'      => sanitize_text_field( $raw['bankName'] ?? $raw['bank_name'] ?? '' ),
        'cryptoAddress' => sanitize_text_field( $raw['cryptoAddress'] ?? $raw['crypto_address'] ?? '' ),
        'paypalEmail'   => sanitize_email( $raw['paypalEmail'] ?? $raw['paypal_email'] ?? '' ),
    );
}

function scp_format_wallet_details( $meta ) {
    if ( ! is_array( $meta ) ) {
        return '';
    }

    $parts  = array();
    $method = $meta['method'] ?? $meta['payment_method'] ?? '';
    if ( $method ) {
        $parts[] = strtoupper( (string) $method );
    }

    $destination = isset( $meta['destination'] ) && is_array( $meta['destination'] ) ? $meta['destination'] : array();
    foreach ( array( 'bankName', 'accountName', 'accountNumber', 'cryptoAddress', 'paypalEmail' ) as $key ) {
        if ( ! empty( $destination[ $key ] ) ) {
            $parts[] = $destination[ $key ];
        }
    }

    if ( ! empty( $meta['paypal']['email'] ) ) {
        $parts[] = $meta['paypal']['email'];
    }
    if ( ! empty( $meta['crypto']['currency'] ) ) {
        $parts[] = $meta['crypto']['currency'];
    }
    if ( ! empty( $meta['card']['last4'] ) ) {
        $parts[] = '****' . $meta['card']['last4'];
    }

    return implode( ' · ', array_filter( array_map( 'strval', $parts ) ) );
}

function scp_player_login_for_transaction( $tx ) {
    if ( ! empty( $tx->user_login ) ) {
        return $tx->user_login;
    }

    $user = get_userdata( (int) $tx->user_id );
    return $user ? $user->user_login : '';
}

function scp_create_pending_wallet_request( $wp_user_id, $player_external_id, $type, $amount, $currency, $meta = array() ) {
    $type     = scp_normalize_wallet_type( $type );
    $currency = strtoupper( $currency ?: 'USD' );
    $txn_id   = 'wp-' . $type . '-' . uniqid();

    scp_log_transaction(
        $txn_id,
        $player_external_id,
        $type,
        $amount,
        $currency,
        'pending',
        is_array( $meta ) ? $meta : array()
    );

    return $txn_id;
}

function scp_get_pending_wallet_requests( $type ) {
    global $wpdb;
    $table = $wpdb->prefix . 'scp_transactions';
    $type  = scp_normalize_wallet_type( $type );

    if ( $type === 'withdraw' ) {
        return $wpdb->get_results(
            "SELECT * FROM $table WHERE type IN ('withdraw','withdrawal') AND status='pending' ORDER BY created_at DESC"
        );
    }

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE type = %s AND status = 'pending' ORDER BY created_at DESC",
            'deposit'
        )
    );
}

function scp_approve_wallet_request( $id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'scp_transactions';
    $tx    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", absint( $id ) ) );

    if ( ! $tx ) {
        return array( 'success' => false, 'message' => 'Transaction not found.' );
    }
    if ( $tx->status !== 'pending' ) {
        return array( 'success' => false, 'message' => 'This request is no longer pending.' );
    }

    $player_login = scp_player_login_for_transaction( $tx );
    if ( $player_login === '' ) {
        return array( 'success' => false, 'message' => 'Player login is missing for this request.' );
    }

    $type     = scp_normalize_wallet_type( $tx->type );
    $currency = strtoupper( $tx->currency ?: 'USD' );
    $api      = new SCP_API_Client();

    if ( $type === 'deposit' ) {
        $result = $api->deposit( $player_login, (float) $tx->amount, $currency, $tx->txn_id );
    } else {
        $result = $api->withdraw( $player_login, (float) $tx->amount, $currency, $tx->txn_id );
    }

    if ( ! empty( $result['success'] ) ) {
        $scp_txn_id = $result['data']['transaction_id']
            ?? $result['data']['transactionId']
            ?? '';
        if ( $scp_txn_id ) {
            $wpdb->update(
                $table,
                array( 'scp_txn_id' => $scp_txn_id ),
                array( 'id' => $tx->id ),
                array( '%s' ),
                array( '%d' )
            );
        }
    }

    return $result;
}

function scp_reject_wallet_request( $id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'scp_transactions';
    $tx    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", absint( $id ) ) );

    if ( ! $tx ) {
        return array( 'success' => false, 'message' => 'Transaction not found.' );
    }
    if ( $tx->status !== 'pending' ) {
        return array( 'success' => false, 'message' => 'This request is no longer pending.' );
    }

    $meta           = scp_decode_transaction_response( $tx );
    $meta['rejected_at'] = current_time( 'mysql' );

    $updated = $wpdb->update(
        $table,
        array(
            'status'   => 'rejected',
            'response' => wp_json_encode( $meta ),
        ),
        array( 'id' => $tx->id ),
        array( '%s', '%s' ),
        array( '%d' )
    );

    return $updated === false
        ? array( 'success' => false, 'message' => 'Could not reject request.' )
        : array( 'success' => true );
}

function scp_format_transaction_for_rest( $row ) {
    $meta   = scp_decode_transaction_response( $row );
    $type   = scp_normalize_wallet_type( $row->type );
    $status = $row->status ?: 'pending';

    $created_mysql = $row->created_at ?? current_time( 'mysql' );
    $created_iso   = mysql2date( 'c', $created_mysql, false );

    return array(
        'id'          => $row->txn_id,
        'txn_id'      => $row->txn_id,
        'userId'      => (string) $row->user_id,
        'user_id'     => (string) $row->user_id,
        'type'        => $type,
        'amount'      => (float) $row->amount,
        'amountCents' => (int) round( (float) $row->amount * 100 ),
        'amount_cents'=> (int) round( (float) $row->amount * 100 ),
        'currency'    => strtoupper( $row->currency ?: 'USD' ),
        'status'      => $status,
        'method'      => $meta['method'] ?? $meta['payment_method'] ?? '',
        'createdAt'   => $created_iso,
        'created_at'  => $created_iso,
        'reference'   => $row->scp_txn_id ?: $row->txn_id,
    );
}