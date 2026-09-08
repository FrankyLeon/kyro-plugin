<?php
/**
 * Transaction logging and wallet management for ScorpioPlay.
 */

// Create transaction log table on plugin activation and ensure schema upgrades on init
register_activation_hook( SCP_PLUGIN_DIR . 'scorpioplay-core.php', 'scp_create_transaction_table' );
add_action( 'init', 'scp_upgrade_transaction_table' );

function scp_create_transaction_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        txn_id varchar(100) NOT NULL,
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
        updated_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY txn_id (txn_id),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    $wpdb->query( $sql );
}

function scp_upgrade_transaction_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';
    $exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

    if ( $exists !== $table_name ) {
        scp_create_transaction_table();
        return;
    }

    $columns = $wpdb->get_results( "SHOW COLUMNS FROM $table_name" );
    if ( ! $columns ) {
        return;
    }

    $existing = wp_list_pluck( $columns, 'Field' );
    if ( ! in_array( 'gateway_txn_id', $existing, true ) ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN gateway_txn_id varchar(150) DEFAULT NULL" );
    }
    if ( ! in_array( 'scp_txn_id', $existing, true ) ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN scp_txn_id varchar(150) DEFAULT NULL" );
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
 * Normalize a datetime value to the API format `Y-m-d H:i:s`.
 *
 * @param mixed $value Raw datetime from REST, admin form, or ISO input.
 * @return string
 */
function scp_normalize_api_datetime( $value ) {
    if ( $value === null || $value === '' ) {
        return '';
    }

    $value = trim( str_replace( 'T', ' ', (string) $value ) );
    $ts    = strtotime( $value );
    if ( $ts === false ) {
        return sanitize_text_field( $value );
    }

    return wp_date( 'Y-m-d H:i:s', $ts );
}

/**
 * Default start/end window used by the transaction list UI (last 24 hours).
 *
 * @return array{startTime:string,endTime:string}
 */
function scp_transaction_default_times() {
    $end_ts = current_time( 'timestamp' );
    return [
        'startTime' => wp_date( 'Y-m-d H:i:s', $end_ts - DAY_IN_SECONDS ),
        'endTime'   => wp_date( 'Y-m-d H:i:s', $end_ts ),
    ];
}

/**
 * Map API transType integers (and string aliases) to Bet / Win / Refund labels.
 *
 * @param mixed $trans_type
 * @return string
 */
function scp_transaction_type_label( $trans_type ) {
    if ( is_string( $trans_type ) && ! is_numeric( $trans_type ) ) {
        $normalized = strtolower( trim( $trans_type ) );
        $aliases    = [
            'bet'     => 'Bet',
            'wager'   => 'Bet',
            'win'     => 'Win',
            'payout'  => 'Win',
            'refund'  => 'Refund',
            'cancel'  => 'Refund',
            'rollback'=> 'Refund',
            'bonus'   => 'Bonus',
        ];
        return $aliases[ $normalized ] ?? ucfirst( $normalized );
    }

    $map = [
        1 => 'Bet',
        2 => 'Win',
        3 => 'Refund',
        4 => 'Bonus',
        5 => 'Rollback',
    ];
    $code = (int) $trans_type;
    return $map[ $code ] ?? ( $code ? 'Type ' . $code : '' );
}

/**
 * Map one /v1/transaction/list row to the fields used by the history UI.
 *
 * @param array $item
 * @return array
 */
function scp_map_api_transaction( $item ) {
    if ( ! is_array( $item ) ) {
        return [];
    }

    $amount   = isset( $item['amount'] ) ? (float) $item['amount'] : 0;
    $balance  = isset( $item['balance'] ) ? (float) $item['balance'] : 0;
    $type     = scp_transaction_type_label( $item['transType'] ?? '' );
    $success  = ! empty( $item['success'] );
    $player   = (string) ( $item['playerExternalId'] ?? '' );
    $code     = $item['playerCode'] ?? '';

    if ( $type === 'Bet' || $type === 'Rollback' ) {
        $pre_balance = $balance + $amount;
    } elseif ( $type === 'Win' || $type === 'Refund' || $type === 'Bonus' ) {
        $pre_balance = $balance - $amount;
    } else {
        $pre_balance = isset( $item['preBalance'] ) ? (float) $item['preBalance'] : $balance;
    }

    $player_label = $player;
    if ( $code !== '' && $code !== null ) {
        $player_label = $player !== '' ? $player . ' @' . $code : '@' . $code;
    }

    return [
        'transId'          => (string) ( $item['transId'] ?? $item['id'] ?? '' ),
        'id'               => (string) ( $item['transId'] ?? $item['id'] ?? '' ),
        'transType'        => $item['transType'] ?? '',
        'type'             => $type,
        'playerCode'       => $code,
        'playerExternalId' => $player,
        'player'           => $player_label,
        'roundId'          => (string) ( $item['roundId'] ?? '' ),
        'round'            => (string) ( $item['roundId'] ?? '' ),
        'providerId'       => $item['providerId'] ?? '',
        'providerName'     => (string) ( $item['providerName'] ?? '' ),
        'provider'         => (string) ( $item['providerName'] ?? '' ),
        'gameCode'         => (string) ( $item['gameCode'] ?? '' ),
        'gameName'         => (string) ( $item['gameName'] ?? '' ),
        'game'             => (string) ( $item['gameName'] ?? '' ),
        'amount'           => $amount,
        'balance'          => $balance,
        'preBalance'       => $pre_balance,
        'currentBalance'   => $balance,
        'success'          => $success,
        'status'           => $success ? 'Success' : 'Failed',
        'createdAt'        => (string) ( $item['createdAt'] ?? '' ),
    ];
}

/**
 * Fetch and map paginated game transactions from ScorpioPlay.
 *
 * @param array $args
 * @return array{success:bool,message?:string,total:int,offset:int,count:int,list:array}
 */
function scp_fetch_transaction_list( $args = [] ) {
    $defaults = scp_transaction_default_times();
    $start    = scp_normalize_api_datetime( $args['startTime'] ?? '' ) ?: $defaults['startTime'];
    $end      = scp_normalize_api_datetime( $args['endTime'] ?? '' ) ?: $defaults['endTime'];
    $offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
    $limit    = (int) ( $args['limit'] ?? 10 );
    if ( $limit < 1 ) {
        $limit = 10;
    }
    if ( $limit > 200 ) {
        $limit = 200;
    }

    $params = [
        'startTime' => $start,
        'endTime'   => $end,
        'offset'    => $offset,
        'limit'     => $limit,
    ];

    foreach ( [ 'playerExternalId', 'roundId', 'transType', 'operator' ] as $optional ) {
        if ( ! empty( $args[ $optional ] ) ) {
            $params[ $optional ] = $args[ $optional ];
        }
    }

    $api    = new SCP_API_Client();
    $result = $api->transaction_list( $params );

    if ( empty( $result['success'] ) ) {
        return [
            'success' => false,
            'message' => $result['message'] ?? 'Unable to fetch transactions',
            'total'   => 0,
            'offset'  => $offset,
            'count'   => 0,
            'list'    => [],
        ];
    }

    $data = is_array( $result['data'] ?? null ) ? $result['data'] : [];
    $raw  = $data['list'] ?? $data['items'] ?? $data['transactions'] ?? [];
    if ( ! is_array( $raw ) ) {
        $raw = [];
    }

    $list = [];
    foreach ( $raw as $item ) {
        $mapped = scp_map_api_transaction( $item );
        if ( $mapped ) {
            $list[] = $mapped;
        }
    }

    return [
        'success' => true,
        'total'   => (int) ( $data['total'] ?? count( $list ) ),
        'offset'  => (int) ( $data['offset'] ?? $offset ),
        'count'   => (int) ( $data['count'] ?? count( $list ) ),
        'list'    => $list,
    ];
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