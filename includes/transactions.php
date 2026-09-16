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
    $formats_by_key = array_combine( array_keys( $data ), $format );

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM $table_name WHERE txn_id = %s",
        $txn_id
    ) );

    if ( $existing ) {
        if ( $gateway_txn_id === '' || $gateway_txn_id === null ) {
            unset( $data['gateway_txn_id'], $formats_by_key['gateway_txn_id'] );
        }
        if ( $scp_txn_id === '' || $scp_txn_id === null ) {
            unset( $data['scp_txn_id'], $formats_by_key['scp_txn_id'] );
        }
        $wpdb->update( $table_name, $data, [ 'id' => $existing->id ], array_values( $formats_by_key ), [ '%d' ] );
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
function scp_scalar_string( $value ) {
    if ( $value === null || is_array( $value ) || is_object( $value ) || is_bool( $value ) ) {
        return '';
    }

    return trim( (string) $value );
}

function scp_admin_format_datetime( $value ) {
    $raw = scp_scalar_string( $value );
    if ( $raw === '' ) {
        return '';
    }

    $ts = strtotime( $raw );
    if ( $ts === false ) {
        return $raw;
    }

    return wp_date( 'd M Y g:i a', $ts );
}

function scp_parse_admin_datetime( $value ) {
    $value = trim( str_replace( 'T', ' ', scp_scalar_string( $value ) ) );
    if ( $value === '' ) {
        return null;
    }

    $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
    foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
        $dt = DateTimeImmutable::createFromFormat( $format, $value, $tz );
        if ( $dt instanceof DateTimeImmutable ) {
            return $dt;
        }
    }

    try {
        return new DateTimeImmutable( $value, $tz );
    } catch ( Exception $e ) {
        return null;
    }
}

function scp_normalize_api_datetime( $value ) {
    $dt = scp_parse_admin_datetime( $value );
    return $dt ? $dt->format( 'Y-m-d H:i:s' ) : '';
}

/**
 * Floor a datetime so default windows and cache keys stay stable across refreshes.
 *
 * @param DateTimeImmutable $dt
 * @param int                $minutes
 * @return DateTimeImmutable
 */
function scp_datetime_floor_minutes( $dt, $minutes = 5 ) {
    $minutes = max( 1, (int) $minutes );
    $tz      = $dt->getTimezone();
    $ts      = $dt->getTimestamp();
    $ts     -= $ts % ( $minutes * 60 );

    return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
}

/**
 * Default start/end window used by the transaction list UI (last 24 hours).
 *
 * @return array{startTime:string,endTime:string}
 */
function scp_transaction_default_times() {
    $end = scp_datetime_floor_minutes( new DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) ), 5 );
    return array(
        'startTime' => $end->sub( new DateInterval( 'P1D' ) )->format( 'Y-m-d H:i:s' ),
        'endTime'   => $end->format( 'Y-m-d H:i:s' ),
    );
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

    $amount  = is_numeric( $item['amount'] ?? null ) ? (float) $item['amount'] : 0;
    $balance = is_numeric( $item['balance'] ?? null ) ? (float) $item['balance'] : 0;
    $type    = scp_transaction_type_label( is_scalar( $item['transType'] ?? null ) ? $item['transType'] : '' );
    $success = ! empty( $item['success'] );
    $player  = scp_scalar_string( $item['playerExternalId'] ?? '' );
    $code    = scp_scalar_string( $item['playerCode'] ?? '' );

    if ( $type === 'Bet' || $type === 'Rollback' ) {
        $pre_balance = $balance + $amount;
    } elseif ( $type === 'Win' || $type === 'Refund' || $type === 'Bonus' ) {
        $pre_balance = $balance - $amount;
    } else {
        $pre_balance = is_numeric( $item['preBalance'] ?? null ) ? (float) $item['preBalance'] : $balance;
    }

    $player_label = $player;
    if ( $code !== '' ) {
        $player_label = $player !== '' ? $player . ' @' . $code : '@' . $code;
    }

    $id       = scp_scalar_string( $item['transId'] ?? $item['id'] ?? '' );
    $round    = scp_scalar_string( $item['roundId'] ?? '' );
    $provider = scp_scalar_string( $item['providerName'] ?? '' );
    $game     = scp_scalar_string( $item['gameName'] ?? '' );

    return [
        'transId'          => $id,
        'id'               => $id,
        'transType'        => scp_scalar_string( $item['transType'] ?? '' ),
        'type'             => $type,
        'playerCode'       => $code,
        'playerExternalId' => $player,
        'player'           => $player_label,
        'roundId'          => $round,
        'round'            => $round,
        'providerId'       => scp_scalar_string( $item['providerId'] ?? '' ),
        'providerName'     => $provider,
        'provider'         => $provider,
        'gameCode'         => scp_scalar_string( $item['gameCode'] ?? '' ),
        'gameName'         => $game,
        'game'             => $game,
        'amount'           => $amount,
        'balance'          => $balance,
        'preBalance'       => $pre_balance,
        'currentBalance'   => $balance,
        'success'          => $success,
        'status'           => $success ? 'Success' : 'Failed',
        'createdAt'        => scp_scalar_string( $item['createdAt'] ?? '' ),
    ];
}

/**
 * Fetch and map paginated game transactions from ScorpioPlay.
 *
 * @param array $args
 * @return array{success:bool,message?:string,total:int,offset:int,count:int,list:array}
 */
function scp_api_is_rate_limited( $result ) {
    if ( ! is_array( $result ) ) {
        return false;
    }
    $status = (int) ( $result['status'] ?? 0 );
    $msg    = strtolower( scp_scalar_string( $result['message'] ?? '' ) );
    return $status === 429 || strpos( $msg, 'too many' ) !== false || strpos( $msg, 'rate limit' ) !== false;
}

function scp_txn_last_ok_get() {
    $cached = get_transient( 'scp_game_txn_last_ok' );
    if ( is_array( $cached ) && ! empty( $cached['success'] ) ) {
        return $cached;
    }

    $stored = get_option( 'scp_game_txn_last_ok', null );
    return ( is_array( $stored ) && ! empty( $stored['success'] ) ) ? $stored : null;
}

function scp_txn_last_ok_set( $payload ) {
    if ( ! is_array( $payload ) || empty( $payload['success'] ) ) {
        return;
    }
    set_transient( 'scp_game_txn_last_ok', $payload, 30 * MINUTE_IN_SECONDS );
    update_option( 'scp_game_txn_last_ok', $payload, false );
}

function scp_txn_stale_payload( $cache_key, $message = '' ) {
    $stale = get_transient( $cache_key . '_ok' );
    if ( ! is_array( $stale ) || empty( $stale['success'] ) ) {
        $stale = scp_txn_last_ok_get();
    }
    if ( ! is_array( $stale ) || empty( $stale['success'] ) ) {
        return null;
    }

    $stale['stale']   = true;
    $stale['message'] = $message !== '' ? $message : 'Scorpio is rate-limiting new requests. Showing the last cached snapshot.';
    return $stale;
}

function scp_fetch_transaction_list( $args = [] ) {
    $start = scp_parse_admin_datetime( $args['startTime'] ?? '' );
    $end   = scp_parse_admin_datetime( $args['endTime'] ?? '' );
    $now   = scp_datetime_floor_minutes( new DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) ), 5 );
    if ( ! $end || $end > $now ) {
        $end = $now;
    } else {
        $end = scp_datetime_floor_minutes( $end, 5 );
    }
    if ( ! $start ) {
        $start = $end->sub( new DateInterval( 'P1D' ) );
    } else {
        $start = scp_datetime_floor_minutes( $start, 5 );
    }
    if ( $start >= $end ) {
        $start = $end->sub( new DateInterval( 'P1D' ) );
    }
    $max_start = $end->sub( new DateInterval( 'P1D' ) );
    if ( $start < $max_start ) {
        $start = $max_start;
    }

    $offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
    $limit  = (int) ( $args['limit'] ?? 10 );
    if ( $limit < 1 ) {
        $limit = 10;
    }
    if ( $limit > 100 ) {
        $limit = 100;
    }

    $fail = function( $message, $status = 0 ) use ( $offset ) {
        return [
            'success' => false,
            'message' => $message,
            'status'  => (int) $status,
            'total'   => 0,
            'offset'  => $offset,
            'count'   => 0,
            'list'    => [],
        ];
    };

    $params = [
        'startTime' => $start->format( 'Y-m-d H:i:s' ),
        'endTime'   => $end->format( 'Y-m-d H:i:s' ),
        'offset'    => $offset,
        'limit'     => $limit,
    ];

    foreach ( [ 'playerExternalId', 'roundId', 'transType', 'operator' ] as $optional ) {
        if ( ! empty( $args[ $optional ] ) ) {
            $params[ $optional ] = scp_scalar_string( $args[ $optional ] );
        }
    }

    $cache_key = 'scp_txn_' . md5( wp_json_encode( $params ) );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) && ! empty( $cached['success'] ) ) {
        return $cached;
    }

    $rate_msg = 'Too many requests, please try again later.';
    if ( get_transient( 'scp_txn_rate_backoff' ) ) {
        $stale = scp_txn_stale_payload( $cache_key );
        return $stale ? $stale : $fail( $rate_msg, 429 );
    }

    if ( get_transient( 'scp_txn_inflight' ) ) {
        $stale = scp_txn_stale_payload( $cache_key, 'A game-play request is already in flight. Showing the last cached snapshot.' );
        return $stale ? $stale : $fail( $rate_msg, 429 );
    }

    set_transient( 'scp_txn_inflight', 1, 25 );

    try {
        $api    = new SCP_API_Client();
        $result = $api->transaction_list( $params );
    } catch ( Throwable $e ) {
        delete_transient( 'scp_txn_inflight' );
        return $fail( $e->getMessage() );
    }

    delete_transient( 'scp_txn_inflight' );

    if ( ! is_array( $result ) ) {
        return $fail( 'Unable to fetch transactions' );
    }

    if ( scp_api_is_rate_limited( $result ) ) {
        set_transient( 'scp_txn_rate_backoff', 1, 2 * MINUTE_IN_SECONDS );
        $stale = scp_txn_stale_payload( $cache_key, scp_scalar_string( $result['message'] ?? '' ) ?: $rate_msg );
        return $stale ? $stale : $fail( $rate_msg, 429 );
    }

    if ( empty( $result['success'] ) ) {
        return $fail( scp_scalar_string( $result['message'] ?? '' ) ?: 'Unable to fetch transactions', $result['status'] ?? 0 );
    }

    $data = is_array( $result['data'] ?? null ) ? $result['data'] : $result;
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

    $payload = [
        'success' => true,
        'total'   => (int) ( $data['total'] ?? count( $list ) ),
        'offset'  => (int) ( $data['offset'] ?? $offset ),
        'count'   => (int) ( $data['count'] ?? count( $list ) ),
        'list'    => $list,
    ];

    set_transient( $cache_key, $payload, 3 * MINUTE_IN_SECONDS );
    set_transient( $cache_key . '_ok', $payload, 30 * MINUTE_IN_SECONDS );
    scp_txn_last_ok_set( $payload );
    delete_transient( 'scp_txn_rate_backoff' );

    return $payload;
}

function scp_summarize_game_transactions( $list, $api_total = 0 ) {
    $games      = array();
    $providers  = array();
    $player_wager = array();
    $players    = array();
    $rounds     = array();
    $stats      = array(
        'sampled'       => 0,
        'api_total'     => (int) $api_total,
        'bets_count'    => 0,
        'bets_sum'      => 0.0,
        'wins_count'    => 0,
        'wins_sum'      => 0.0,
        'refunds_count' => 0,
        'refunds_sum'   => 0.0,
        'success'       => 0,
        'failed'        => 0,
        'hold'          => 0.0,
        'rtp'           => null,
        'avg_bet'       => 0.0,
        'player_count'  => 0,
        'round_count'   => 0,
        'games'         => array(),
        'providers'     => array(),
        'players_top'   => array(),
    );

    if ( ! is_array( $list ) ) {
        return $stats;
    }

    foreach ( $list as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $stats['sampled']++;
        $type   = scp_scalar_string( $row['type'] ?? '' );
        $amount = (float) ( $row['amount'] ?? 0 );
        $ok     = ! empty( $row['success'] ) || ( ( $row['status'] ?? '' ) === 'Success' );
        if ( $ok ) {
            $stats['success']++;
        } else {
            $stats['failed']++;
        }

        $player = scp_scalar_string( $row['playerExternalId'] ?? $row['player'] ?? '' );
        if ( $player !== '' ) {
            $players[ $player ] = true;
        }
        $round = scp_scalar_string( $row['round'] ?? $row['roundId'] ?? '' );
        if ( $round !== '' ) {
            $rounds[ $round ] = true;
        }

        $game = scp_scalar_string( $row['game'] ?? $row['gameName'] ?? '' );
        if ( $game === '' ) {
            $game = 'Unknown game';
        }
        $provider = scp_scalar_string( $row['provider'] ?? $row['providerName'] ?? '' ) ?: 'Unknown';
        if ( ! isset( $games[ $game ] ) ) {
            $games[ $game ] = array( 'name' => $game, 'provider' => $provider, 'bets' => 0, 'wagered' => 0.0, 'wins' => 0.0 );
        }
        if ( ! isset( $providers[ $provider ] ) ) {
            $providers[ $provider ] = array( 'name' => $provider, 'bets' => 0, 'wagered' => 0.0 );
        }

        if ( $type === 'Bet' ) {
            $stats['bets_count']++;
            $stats['bets_sum'] += $amount;
            $games[ $game ]['bets']++;
            $games[ $game ]['wagered'] += $amount;
            $providers[ $provider ]['bets']++;
            $providers[ $provider ]['wagered'] += $amount;
            if ( $player !== '' ) {
                if ( ! isset( $player_wager[ $player ] ) ) {
                    $player_wager[ $player ] = 0.0;
                }
                $player_wager[ $player ] += $amount;
            }
        } elseif ( $type === 'Win' || $type === 'Bonus' ) {
            $stats['wins_count']++;
            $stats['wins_sum'] += $amount;
            $games[ $game ]['wins'] += $amount;
        } elseif ( $type === 'Refund' || $type === 'Rollback' ) {
            $stats['refunds_count']++;
            $stats['refunds_sum'] += $amount;
        }
    }

    $stats['hold']         = $stats['bets_sum'] - $stats['wins_sum'] - $stats['refunds_sum'];
    $stats['avg_bet']      = $stats['bets_count'] > 0 ? $stats['bets_sum'] / $stats['bets_count'] : 0.0;
    $stats['rtp']          = $stats['bets_sum'] > 0 ? ( $stats['wins_sum'] / $stats['bets_sum'] ) * 100 : null;
    $stats['player_count'] = count( $players );
    $stats['round_count']  = count( $rounds );

    uasort( $games, function( $a, $b ) {
        return $b['wagered'] <=> $a['wagered'];
    } );
    uasort( $providers, function( $a, $b ) {
        return $b['wagered'] <=> $a['wagered'];
    } );
    arsort( $player_wager );

    $stats['games']       = array_slice( array_values( $games ), 0, 5 );
    $stats['providers']   = array_slice( array_values( $providers ), 0, 5 );
    $top_players          = array();
    foreach ( array_slice( $player_wager, 0, 5, true ) as $name => $wagered ) {
        $top_players[] = array( 'name' => $name, 'wagered' => $wagered );
    }
    $stats['players_top'] = $top_players;

    return $stats;
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

function scp_get_transaction_by_gateway_id( $gateway_txn_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';
    $gateway_txn_id = sanitize_text_field( $gateway_txn_id );
    if ( $gateway_txn_id === '' ) {
        return null;
    }

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table_name WHERE gateway_txn_id = %s",
        $gateway_txn_id
    ) );
}

function scp_get_transaction_row_by_id( $id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scp_transactions';

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        absint( $id )
    ) );
}

function scp_decode_transaction_response( $row ) {
    if ( empty( $row ) ) {
        return array();
    }

    $raw = is_array( $row ) ? ( $row['response'] ?? '' ) : ( $row->response ?? '' );
    if ( is_array( $raw ) ) {
        return $raw;
    }

    $decoded = json_decode( (string) $raw, true );
    return is_array( $decoded ) ? $decoded : array();
}

function scp_format_wallet_details( $meta ) {
    if ( ! is_array( $meta ) ) {
        return '';
    }

    $parts = array();
    $method = scp_scalar_string( $meta['method'] ?? $meta['payment_method'] ?? '' );
    if ( $method !== '' ) {
        $parts[] = strtoupper( $method );
    }
    foreach ( array( 'bankName', 'accountNumber', 'destination', 'paypalEmail', 'txHash' ) as $key ) {
        $value = scp_scalar_string( $meta[ $key ] ?? '' );
        if ( $value !== '' ) {
            $parts[] = $value;
        }
    }
    $from = scp_scalar_string( $meta['from'] ?? '' );
    if ( $from !== '' ) {
        $parts[] = 'from ' . $from;
    }

    return implode( ' · ', $parts );
}

function scp_wallet_request_types( $type ) {
    $type = strtolower( (string) $type );
    if ( $type === 'deposit' ) {
        return array( 'deposit' );
    }

    return array( 'withdraw', 'withdrawal' );
}

function scp_wallet_log_types( $type = '' ) {
    $type = strtolower( scp_scalar_string( $type ) );
    if ( $type === 'deposit' ) {
        return array( 'deposit' );
    }
    if ( $type === 'withdraw' || $type === 'withdrawal' ) {
        return array( 'withdraw', 'withdrawal' );
    }

    return array( 'deposit', 'withdraw', 'withdrawal' );
}

function scp_wallet_log_default_times() {
    $end_ts = current_time( 'timestamp' );
    return array(
        'startTime' => wp_date( 'Y-m-d H:i:s', $end_ts - ( 30 * DAY_IN_SECONDS ) ),
        'endTime'   => wp_date( 'Y-m-d H:i:s', $end_ts ),
    );
}

function scp_wallet_log_normalize_type( $type ) {
    $type = strtolower( scp_scalar_string( $type ) );
    return ( $type === 'deposit' ) ? 'deposit' : 'withdraw';
}

function scp_map_wallet_log_row( $row ) {
    if ( empty( $row ) ) {
        return array();
    }

    $meta    = scp_decode_transaction_response( $row );
    $type    = scp_wallet_log_normalize_type( $row->type ?? '' );
    $status  = strtolower( scp_scalar_string( $row->status ?? '' ) );
    $gateway = scp_scalar_string( $row->gateway_txn_id ?? '' );
    if ( $gateway === '' ) {
        $gateway = scp_scalar_string( $meta['txHash'] ?? '' );
    }

    return array(
        'id'         => (int) ( $row->id ?? 0 ),
        'txn_id'     => scp_scalar_string( $row->txn_id ?? '' ),
        'user_id'    => (int) ( $row->user_id ?? 0 ),
        'user_login' => scp_scalar_string( $row->user_login ?? '' ),
        'row'        => $row,
        'type'       => $type,
        'typeLabel'  => ( $type === 'deposit' ) ? 'Deposit' : 'Withdraw',
        'amount'     => (float) ( $row->amount ?? 0 ),
        'currency'   => scp_scalar_string( $row->currency ?? '' ) ?: 'USD',
        'status'     => $status,
        'statusLabel'=> $status !== '' ? ucfirst( $status ) : '',
        'details'    => scp_format_wallet_details( $meta ),
        'gateway'    => $gateway,
        'scp_txn_id' => scp_scalar_string( $row->scp_txn_id ?? '' ),
        'createdAt'  => scp_scalar_string( $row->created_at ?? '' ),
    );
}

function scp_wallet_log_where( $args, $include_type = true, $include_status = true ) {
    global $wpdb;

    $where  = array();
    $params = array();
    $types  = scp_wallet_log_types( $include_type ? ( $args['walletType'] ?? '' ) : '' );
    $in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
    $where[] = "type IN ($in)";
    $params  = array_merge( $params, $types );

    if ( $include_status ) {
        $status = strtolower( scp_scalar_string( $args['status'] ?? '' ) );
        if ( $status === 'failed' ) {
            $where[]  = 'status IN (%s,%s)';
            $params[] = 'failed';
            $params[] = 'rejected';
        } elseif ( $status !== '' ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }
    }

    $start = scp_normalize_api_datetime( $args['startTime'] ?? '' );
    if ( $start !== '' ) {
        $where[]  = 'created_at >= %s';
        $params[] = $start;
    }

    $end = scp_normalize_api_datetime( $args['endTime'] ?? '' );
    if ( $end !== '' ) {
        $where[]  = 'created_at <= %s';
        $params[] = $end;
    }

    $player = scp_scalar_string( $args['playerExternalId'] ?? '' );
    if ( $player !== '' ) {
        $like     = '%' . $wpdb->esc_like( $player ) . '%';
        $where[]  = '(user_login = %s OR user_login LIKE %s)';
        $params[] = $player;
        $params[] = $like;
    }

    return array( implode( ' AND ', $where ), $params );
}

function scp_fetch_wallet_transaction_log( $args = array() ) {
    global $wpdb;

    $defaults = scp_wallet_log_default_times();
    $offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
    $limit    = (int) ( $args['limit'] ?? 20 );
    if ( $limit < 1 ) {
        $limit = 20;
    }
    if ( $limit > 200 ) {
        $limit = 200;
    }

    $args['startTime'] = scp_normalize_api_datetime( $args['startTime'] ?? '' ) ?: $defaults['startTime'];
    $args['endTime']   = scp_normalize_api_datetime( $args['endTime'] ?? '' ) ?: $defaults['endTime'];

    $table = $wpdb->prefix . 'scp_transactions';
    list( $where, $params ) = scp_wallet_log_where( $args, true, true );
    $sql_where = $where !== '' ? $where : '1=1';

    $total_sql = "SELECT COUNT(*) FROM $table WHERE $sql_where";
    $total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, $params ) : $total_sql );

    $list_sql = "SELECT * FROM $table WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d";
    $rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $limit, $offset ) ) ) );
    if ( ! is_array( $rows ) ) {
        $rows = array();
    }

    $list = array();
    foreach ( $rows as $row ) {
        $mapped = scp_map_wallet_log_row( $row );
        if ( $mapped ) {
            $list[] = $mapped;
        }
    }

    list( $count_where, $count_params ) = scp_wallet_log_where( $args, false, false );
    $count_sql = "SELECT type, status, COUNT(*) AS n FROM $table WHERE $count_where GROUP BY type, status";
    $count_rows = $wpdb->get_results( $count_params ? $wpdb->prepare( $count_sql, $count_params ) : $count_sql );
    $counts = array(
        'pending_deposit'  => 0,
        'pending_withdraw' => 0,
        'completed'        => 0,
        'failed'           => 0,
        'all'             => 0,
    );
    if ( is_array( $count_rows ) ) {
        foreach ( $count_rows as $row ) {
            $n    = (int) $row->n;
            $kind = scp_wallet_log_normalize_type( $row->type );
            $st   = strtolower( scp_scalar_string( $row->status ) );
            $counts['all'] += $n;
            if ( $st === 'pending' && $kind === 'deposit' ) {
                $counts['pending_deposit'] += $n;
            } elseif ( $st === 'pending' && $kind === 'withdraw' ) {
                $counts['pending_withdraw'] += $n;
            } elseif ( $st === 'completed' ) {
                $counts['completed'] += $n;
            } elseif ( $st === 'failed' || $st === 'rejected' ) {
                $counts['failed'] += $n;
            }
        }
    }

    return array(
        'success' => true,
        'total'   => $total,
        'offset'  => $offset,
        'count'   => count( $list ),
        'list'    => $list,
        'counts'  => $counts,
    );
}

function scp_summarize_wallet_transactions( $args = array() ) {
    global $wpdb;

    $defaults = scp_wallet_log_default_times();
    $args['startTime'] = scp_normalize_api_datetime( $args['startTime'] ?? '' ) ?: $defaults['startTime'];
    $args['endTime']   = scp_normalize_api_datetime( $args['endTime'] ?? '' ) ?: $defaults['endTime'];

    $stats = array(
        'deposit_count'          => 0,
        'deposit_sum'            => 0.0,
        'withdraw_count'        => 0,
        'withdraw_sum'          => 0.0,
        'pending_deposit_count'  => 0,
        'pending_deposit_sum'    => 0.0,
        'pending_withdraw_count' => 0,
        'pending_withdraw_sum' => 0.0,
        'failed_count'          => 0,
        'failed_sum'            => 0.0,
        'completed_count'        => 0,
        'player_count'          => 0,
        'avg_deposit'           => 0.0,
        'avg_withdraw'         => 0.0,
        'net'                   => 0.0,
        'methods'               => array(),
        'depositors'            => array(),
        'withdrawers'          => array(),
        'counts'                => array(
            'pending_deposit'  => 0,
            'pending_withdraw' => 0,
            'completed'        => 0,
            'failed'           => 0,
            'all'              => 0,
        ),
    );

    $table = $wpdb->prefix . 'scp_transactions';
    list( $where, $params ) = scp_wallet_log_where( $args, false, false );
    $sql_where = $where !== '' ? $where : '1=1';

    $agg_sql  = "SELECT type, status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM $table WHERE $sql_where GROUP BY type, status";
    $agg_rows = $wpdb->get_results( $params ? $wpdb->prepare( $agg_sql, $params ) : $agg_sql );
    if ( is_array( $agg_rows ) ) {
        foreach ( $agg_rows as $row ) {
            $kind   = scp_wallet_log_normalize_type( $row->type );
            $status = strtolower( scp_scalar_string( $row->status ) );
            $n      = (int) $row->n;
            $sum    = (float) $row->total;
            $stats['counts']['all'] += $n;

            if ( $status === 'completed' ) {
                $stats['completed_count'] += $n;
                $stats['counts']['completed'] += $n;
                if ( $kind === 'deposit' ) {
                    $stats['deposit_count'] += $n;
                    $stats['deposit_sum']  += $sum;
                } else {
                    $stats['withdraw_count'] += $n;
                    $stats['withdraw_sum']  += $sum;
                }
            } elseif ( $status === 'pending' ) {
                if ( $kind === 'deposit' ) {
                    $stats['pending_deposit_count'] += $n;
                    $stats['pending_deposit_sum']  += $sum;
                    $stats['counts']['pending_deposit'] += $n;
                } else {
                    $stats['pending_withdraw_count'] += $n;
                    $stats['pending_withdraw_sum']  += $sum;
                    $stats['counts']['pending_withdraw'] += $n;
                }
            } else {
                $stats['failed_count'] += $n;
                $stats['failed_sum']  += $sum;
                $stats['counts']['failed'] += $n;
            }
        }
    }

    $stats['net']          = $stats['deposit_sum'] - $stats['withdraw_sum'];
    $stats['avg_deposit']  = $stats['deposit_count'] > 0 ? $stats['deposit_sum'] / $stats['deposit_count'] : 0.0;
    $stats['avg_withdraw'] = $stats['withdraw_count'] > 0 ? $stats['withdraw_sum'] / $stats['withdraw_count'] : 0.0;

    $player_sql = "SELECT COUNT(DISTINCT user_login) FROM $table WHERE $sql_where AND user_login <> ''";
    $stats['player_count'] = (int) $wpdb->get_var( $params ? $wpdb->prepare( $player_sql, $params ) : $player_sql );

    $dep_sql = "SELECT user_id, user_login, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
        FROM $table WHERE $sql_where AND type = 'deposit' AND status = 'completed'
        GROUP BY user_login, user_id ORDER BY total DESC LIMIT 5";
    $dep_rows = $wpdb->get_results( $params ? $wpdb->prepare( $dep_sql, $params ) : $dep_sql );
    if ( is_array( $dep_rows ) ) {
        foreach ( $dep_rows as $row ) {
            $stats['depositors'][] = array(
                'name'     => scp_scalar_string( $row->user_login ),
                'provider' => (int) $row->n . ' deposits',
                'total'    => (float) $row->total,
                'user_id'  => (int) $row->user_id,
            );
        }
    }

    $wd_sql = "SELECT user_id, user_login, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
        FROM $table WHERE $sql_where AND type IN ('withdraw','withdrawal') AND status = 'completed'
        GROUP BY user_login, user_id ORDER BY total DESC LIMIT 5";
    $wd_rows = $wpdb->get_results( $params ? $wpdb->prepare( $wd_sql, $params ) : $wd_sql );
    if ( is_array( $wd_rows ) ) {
        foreach ( $wd_rows as $row ) {
            $stats['withdrawers'][] = array(
                'name'     => scp_scalar_string( $row->user_login ),
                'provider' => (int) $row->n . ' withdrawals',
                'total'    => (float) $row->total,
                'user_id'  => (int) $row->user_id,
            );
        }
    }

    $method_sql  = "SELECT type, status, amount, response FROM $table WHERE $sql_where ORDER BY id DESC LIMIT 500";
    $method_rows = $wpdb->get_results( $params ? $wpdb->prepare( $method_sql, $params ) : $method_sql );
    $methods     = array();
    if ( is_array( $method_rows ) ) {
        foreach ( $method_rows as $row ) {
            $meta   = scp_decode_transaction_response( $row );
            $method = strtoupper( scp_scalar_string( $meta['method'] ?? $meta['payment_method'] ?? '' ) );
            if ( $method === '' ) {
                $method = 'UNSPECIFIED';
            }
            if ( ! isset( $methods[ $method ] ) ) {
                $methods[ $method ] = array( 'name' => $method, 'n' => 0, 'total' => 0.0 );
            }
            $methods[ $method ]['n']++;
            if ( strtolower( scp_scalar_string( $row->status ) ) === 'completed' ) {
                $methods[ $method ]['total'] += (float) $row->amount;
            }
        }
    }
    uasort( $methods, function( $a, $b ) {
        return $b['total'] <=> $a['total'];
    } );
    $stats['methods'] = array();
    foreach ( array_slice( $methods, 0, 5, true ) as $row ) {
        $stats['methods'][] = array(
            'name'     => $row['name'],
            'provider' => (int) $row['n'] . ' txns',
            'total'    => $row['total'],
        );
    }

    return $stats;
}

function scp_get_pending_wallet_requests( $type ) {
    global $wpdb;
    $table = $wpdb->prefix . 'scp_transactions';
    $types = scp_wallet_request_types( $type );
    $in    = implode( ',', array_map( function( $item ) use ( $wpdb ) {
        return $wpdb->prepare( '%s', $item );
    }, $types ) );

    return $wpdb->get_results(
        "SELECT * FROM $table WHERE status = 'pending' AND type IN ($in) ORDER BY id DESC LIMIT 200"
    );
}

function scp_approve_wallet_request( $id ) {
    $row = scp_get_transaction_row_by_id( $id );
    if ( ! $row ) {
        return array( 'success' => false, 'message' => 'Request not found.' );
    }
    if ( $row->status !== 'pending' ) {
        return array( 'success' => false, 'message' => 'Request is not pending.' );
    }

    $player = $row->user_login;
    $type   = strtolower( (string) $row->type );
    if ( $type === 'deposit' ) {
        $result = scp_process_player_deposit( (int) $row->user_id, (float) $row->amount, $row->currency ?: 'USD', $row->txn_id, $player );
    } else {
        $result = scp_process_player_withdrawal( (int) $row->user_id, (float) $row->amount, $row->currency ?: 'USD', $row->txn_id, $player );
    }

    if ( empty( $result['success'] ) ) {
        return array( 'success' => false, 'message' => $result['message'] ?? 'API call failed' );
    }

    return array( 'success' => true );
}

function scp_reject_wallet_request( $id ) {
    $row = scp_get_transaction_row_by_id( $id );
    if ( ! $row ) {
        return array( 'success' => false, 'message' => 'Request not found.' );
    }

    scp_log_transaction(
        $row->txn_id,
        $row->user_login,
        $row->type,
        $row->amount,
        $row->currency,
        'rejected',
        scp_decode_transaction_response( $row )
    );

    return array( 'success' => true );
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