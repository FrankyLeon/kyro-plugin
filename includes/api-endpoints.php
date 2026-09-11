<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'scp_register_rest_routes' );
function scp_register_rest_routes() {
    register_rest_route( 'scp/v1', '/auth/login', [
        'methods'             => 'POST',
        'callback'            => 'scp_rest_auth_login',
        'permission_callback' => '__return_true',
        'args'                => [
            'email'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'username' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'password' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/auth/register', [
        'methods'             => 'POST',
        'callback'            => 'scp_rest_auth_register',
        'permission_callback' => '__return_true',
        'args'                => [
            'email'       => [ 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
            'password'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'displayName' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'avatarUrl'   => [ 'required' => false, 'sanitize_callback' => 'esc_url_raw' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/provider/list', [
        'methods'             => 'GET',
        'callback'            => 'scp_rest_provider_list',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'scp/v1', '/provider/settings', [
        'methods'             => 'GET',
        'callback'            => 'scp_rest_provider_settings',
        'permission_callback' => '__return_true',
        'args'                => [
            'provider_id' => [
                'required' => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/game/list/(?P<provider_id>[\w\-]+)', [
        'methods'             => 'GET',
        'callback'            => 'scp_rest_game_list',
        'permission_callback' => '__return_true',
        'args'                => [
            'provider_id' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/game/catalog', [
        'methods'             => 'GET',
        'callback'            => 'scp_rest_game_catalog',
        'permission_callback' => '__return_true',
        'args'                => [
            'q'        => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'provider' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'sort'     => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'offset'   => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'limit'    => [ 'required' => false, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    // Slug-based launch — matches FE client: POST /games/{slug}/launch { returnUrl, playerExternalId }.
    register_rest_route( 'scp/v1', '/games/(?P<slug>[\w\-]+)/launch', [
        'methods'             => 'POST',
        'callback'            => 'scp_rest_game_launch_by_slug',
        'permission_callback' => '__return_true',
        'args'                => [
            'slug'             => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'playerExternalId' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'player_id'        => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'returnUrl'        => [ 'required' => false, 'sanitize_callback' => 'esc_url_raw' ],
            'return_url'       => [ 'required' => false, 'sanitize_callback' => 'esc_url_raw' ],
            'language'         => [ 'required' => false, 'default' => 'en', 'sanitize_callback' => 'sanitize_text_field' ],
            'currency'         => [ 'required' => false, 'default' => 'USD', 'sanitize_callback' => 'sanitize_text_field' ],
            'rtp'              => [ 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    // Explicit launch — providerId + gameCode in body (legacy / direct API clients).
    register_rest_route( 'scp/v1', '/game/launch', [
        'methods'             => 'POST',
        'callback'            => 'scp_rest_game_launch',
        'permission_callback' => '__return_true',
        'args'                => [
            'providerId'       => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'provider_id'      => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'gameCode'         => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'game_code'        => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'playerExternalId' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'player_id'        => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'language'         => [ 'required' => false, 'default' => 'en', 'sanitize_callback' => 'sanitize_text_field' ],
            'currency'         => [ 'required' => false, 'default' => 'USD', 'sanitize_callback' => 'sanitize_text_field' ],
            'returnUrl'        => [ 'required' => false, 'sanitize_callback' => 'esc_url_raw' ],
            'return_url'       => [ 'required' => false, 'sanitize_callback' => 'esc_url_raw' ],
            'rtp'              => [ 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/game/kick', [
        'methods'             => 'POST',
        'callback'            => 'scp_rest_game_kick',
        'permission_callback' => '__return_true',
        'args'                => [
            'game_session_id' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'provider_id'     => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'game_code'       => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'reason'          => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'player_id'       => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/wallet/deposit', [
        'methods'             => 'POST',
        'callback'            => 'scp_rest_wallet_deposit',
        'permission_callback' => 'scp_rest_permission_logged_in',
        'args'                => [
            'amount'       => [ 'required' => false, 'sanitize_callback' => 'floatval' ],
            'amountCents'  => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'amount_cents' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'currency'     => [ 'required' => false, 'default' => 'USD', 'sanitize_callback' => 'sanitize_text_field' ],
            'order_id'     => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'player_id'    => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'method'       => [ 'required' => false, 'default' => 'card', 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/player/balance', [
        'methods'             => 'GET',
        'callback'            => 'scp_rest_player_balance',
        'permission_callback' => 'scp_rest_permission_logged_in',
        'args'                => [
            'playerExternalId' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'player_id'        => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/wallet/withdraw', [
        'methods'             => 'POST',
        'callback'            => 'scp_rest_wallet_withdraw',
        'permission_callback' => 'scp_rest_permission_logged_in',
        'args'                => [
            'amount'         => [ 'required' => true, 'sanitize_callback' => 'floatval' ],
            'currency'       => [ 'required' => false, 'default' => 'USD', 'sanitize_callback' => 'sanitize_text_field' ],
            'withdrawal_id'  => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'player_id'      => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'method'         => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/wallet/transactions', [
        'methods'             => 'GET',
        'callback'            => 'scp_rest_wallet_transactions',
        'permission_callback' => 'scp_rest_permission_logged_in',
        'args'                => [
            'limit'     => [ 'required' => false, 'default' => 50, 'sanitize_callback' => 'absint' ],
            'player_id' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/transaction/list', [
        'methods'             => 'GET',
        'callback'            => 'scp_rest_transaction_list',
        'permission_callback' => 'scp_rest_permission_logged_in',
        'args'                => [
            'user_id'   => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'player_id' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'limit'     => [ 'required' => false, 'default' => 100, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    register_rest_route( 'scp/v1', '/support/contact', [
        'methods'             => 'POST',
        'callback'            => 'scp_rest_support_contact',
        'permission_callback' => '__return_true',
        'args'                => [
            'subject'   => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'message'   => [ 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
            'email'     => [ 'required' => false, 'sanitize_callback' => 'sanitize_email' ],
            'player_id' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );
}

function scp_rest_permission_logged_in() {
    if ( is_user_logged_in() ) {
        return true;
    }

    $authorization = '';
    if ( function_exists( 'getallheaders' ) ) {
        $headers = getallheaders();
        if ( is_array( $headers ) ) {
            foreach ( array( 'Authorization', 'authorization' ) as $header_name ) {
                if ( ! empty( $headers[ $header_name ] ) ) {
                    $authorization = $headers[ $header_name ];
                    break;
                }
            }
        }
    }

    if ( empty( $authorization ) && ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
        $authorization = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
    }

    if ( preg_match( '/Bearer\s+(.+)/i', $authorization, $matches ) ) {
        $token = trim( $matches[1] );
        $user_id = scp_get_user_id_by_token( $token );
        if ( $user_id ) {
            wp_set_current_user( $user_id );
            return true;
        }
    }

    return false;
}

function scp_get_user_id_by_token( $token ) {
    if ( empty( $token ) ) {
        return 0;
    }

    $users = get_users( array(
        'meta_key'   => 'scp_api_token',
        'meta_value' => $token,
        'number'     => 1,
        'fields'     => array( 'ID' ),
    ) );

    if ( ! empty( $users ) ) {
        $first_user = reset( $users );
        return (int) $first_user->ID;
    }

    return 0;
}

function scp_issue_user_token( $user_id ) {
    $token = get_user_meta( $user_id, 'scp_api_token', true );
    if ( ! empty( $token ) ) {
        return $token;
    }

    $token = wp_generate_password( 32, false );
    update_user_meta( $user_id, 'scp_api_token', $token );
    return $token;
}

/**
 * Pick primary balance entry from Scorpio balance array.
 *
 * @param array  $balances Balance entries with currency/amount.
 * @param string $preferred Preferred currency code.
 * @return array{currency:string,amount:float,balanceCents:int}
 */
function scp_pick_primary_balance( $balances, $preferred = 'USD' ) {
    $normalized = [];
    if ( is_array( $balances ) ) {
        foreach ( $balances as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['currency'] ) ) {
                continue;
            }
            $currency = strtoupper( sanitize_text_field( $entry['currency'] ) );
            $amount   = floatval( $entry['amount'] ?? 0 );
            if ( $currency === '' || ! is_finite( $amount ) ) {
                continue;
            }
            $normalized[] = [
                'currency'     => $currency,
                'amount'       => $amount,
                'balanceCents' => (int) round( $amount * 100 ),
            ];
        }
    }

    if ( empty( $normalized ) ) {
        return [
            'currency'     => strtoupper( $preferred ?: 'USD' ),
            'amount'       => 0.0,
            'balanceCents' => 0,
        ];
    }

    $preferred = strtoupper( $preferred ?: 'USD' );
    foreach ( $normalized as $entry ) {
        if ( $entry['currency'] === $preferred ) {
            return $entry;
        }
    }

    return $normalized[0];
}

/**
 * Build FE-facing balance payload from Scorpio player info data.
 *
 * @param array  $data Scorpio /v1/player/info data object.
 * @param string $preferred_currency Preferred currency.
 * @return array
 */
function scp_build_balance_payload( $data, $preferred_currency = 'USD' ) {
    $balances = [];
    if ( ! empty( $data['balance'] ) && is_array( $data['balance'] ) ) {
        foreach ( $data['balance'] as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['currency'] ) ) {
                continue;
            }
            $balances[] = [
                'currency' => strtoupper( sanitize_text_field( $entry['currency'] ) ),
                'amount'   => floatval( $entry['amount'] ?? 0 ),
            ];
        }
    }

    $primary = scp_pick_primary_balance( $balances, $preferred_currency );

    return [
        'playerCode'   => isset( $data['playerCode'] ) ? intval( $data['playerCode'] ) : 0,
        'balanceCents' => $primary['balanceCents'],
        'currency'     => $primary['currency'],
        'balances'     => $balances,
    ];
}

/**
 * Fetch live balance for a player external ID from Scorpio.
 *
 * @param string $player_external_id Player external ID.
 * @param string $preferred_currency Preferred currency.
 * @return array{success:bool,message?:string,data?:array}
 */
function scp_fetch_player_balance_payload( $player_external_id, $preferred_currency = 'USD' ) {
    if ( empty( $player_external_id ) ) {
        return [ 'success' => false, 'message' => 'Player ID is required.' ];
    }

    $result = scp_get_player_info_by_external_id( $player_external_id );
    if ( empty( $result['success'] ) ) {
        return [
            'success' => false,
            'message' => $result['message'] ?? 'Failed to fetch balance',
        ];
    }

    $payload = scp_build_balance_payload( $result['data'] ?? [], $preferred_currency );
    return [ 'success' => true, 'data' => $payload ];
}

function scp_auth_user_payload( $user, $balance_payload = null ) {
    $avatar_url = get_user_meta( $user->ID, 'avatar_url', true );
    if ( empty( $avatar_url ) ) {
        $avatar_url = get_user_meta( $user->ID, 'scp_avatar_url', true );
    }

    $balance_cents = 0;
    $currency = 'USD';

    if ( is_array( $balance_payload ) ) {
        $balance_cents = intval( $balance_payload['balanceCents'] ?? 0 );
        $currency = ! empty( $balance_payload['currency'] )
            ? strtoupper( $balance_payload['currency'] )
            : 'USD';
    } else {
        $cached = get_user_meta( $user->ID, 'scp_player_balance', true );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            $primary = scp_pick_primary_balance( $cached, 'USD' );
            $balance_cents = $primary['balanceCents'];
            $currency = $primary['currency'];
        }
    }

    return array(
        'id'           => (string) $user->ID,
        'email'        => $user->user_email,
        'displayName'  => $user->display_name ?: $user->user_login,
        'username'     => $user->user_login,
        'avatarUrl'    => $avatar_url ?: '',
        'balanceCents' => $balance_cents,
        'currency'     => $currency,
        'ownedGameIds' => array(),
        'createdAt'    => $user->user_registered,
    );
}

function scp_rest_auth_login( $request ) {
    $email = $request->get_param( 'email' );
    $username = $request->get_param( 'username' );
    $password = $request->get_param( 'password' );

    $identifier = trim( (string) ( $email ?: $username ) );
    if ( empty( $identifier ) || empty( $password ) ) {
        return new WP_Error( 'scp_auth_missing_credentials', 'Email and password are required.', array( 'status' => 400 ) );
    }

    $user = null;
    if ( is_email( $identifier ) ) {
        $user = get_user_by( 'email', $identifier );
    }

    if ( ! $user ) {
        $user = get_user_by( 'login', $identifier );
    }

    if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
        return new WP_Error( 'scp_auth_invalid_credentials', 'Invalid email or password.', array( 'status' => 401 ) );
    }

    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true );

    $token = scp_issue_user_token( $user->ID );

    return rest_ensure_response( array(
        'success' => true,
        'data'    => array(
            'user'  => scp_auth_user_payload( $user ),
            'token' => $token,
        ),
    ) );
}

function scp_rest_auth_register( $request ) {
    $email = $request->get_param( 'email' );
    $password = $request->get_param( 'password' );
    $display_name = $request->get_param( 'displayName' );
    $avatar_url = $request->get_param( 'avatarUrl' );

    if ( empty( $email ) || empty( $password ) ) {
        return new WP_Error( 'scp_auth_missing_fields', 'Email and password are required.', array( 'status' => 400 ) );
    }

    if ( email_exists( $email ) ) {
        return new WP_Error( 'scp_auth_email_exists', 'An account with that email already exists.', array( 'status' => 409 ) );
    }

    $base_username = sanitize_user( current( explode( '@', $email ) ) );
    if ( empty( $base_username ) ) {
        $base_username = 'player';
    }

    $username = $base_username;
    $counter = 1;
    while ( username_exists( $username ) ) {
        $username = $base_username . $counter;
        $counter++;
    }

    $display_name = trim( (string) $display_name );
    if ( empty( $display_name ) ) {
        $display_name = $base_username;
    }

    $user_id = wp_create_user( $username, $password, $email );
    if ( is_wp_error( $user_id ) ) {
        return new WP_Error( 'scp_auth_create_user_failed', $user_id->get_error_message(), array( 'status' => 400 ) );
    }

    wp_update_user( array(
        'ID'           => $user_id,
        'display_name' => $display_name,
        'nickname'     => $display_name,
    ) );

    if ( ! empty( $avatar_url ) ) {
        update_user_meta( $user_id, 'avatar_url', $avatar_url );
    }

    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true );

    $token = scp_issue_user_token( $user_id );
    $user = get_userdata( $user_id );

    do_action( 'scp_user_registered', $user_id, $user );

    return rest_ensure_response( array(
        'success' => true,
        'data'    => array(
            'user'  => scp_auth_user_payload( $user ),
            'token' => $token,
        ),
    ) );
}

function scp_rest_provider_list( $request ) {
    $api = new SCP_API_Client();
    $result = $api->request( '/v1/provider/list', 'GET' );
    if ( empty( $result['success'] ) ) {
        return new WP_Error( 'scp_provider_list_error', $result['message'] ?? 'Unable to fetch providers', [ 'status' => 500 ] );
    }
    return rest_ensure_response( $result['data'] ?? [] );
}

function scp_rest_provider_settings( $request ) {
    $provider_id = $request->get_param( 'provider_id' );
    $endpoint = '/v1/provider/settings';
    if ( ! empty( $provider_id ) ) {
        $endpoint .= '?providerId=' . rawurlencode( $provider_id );
    }

    $api = new SCP_API_Client();
    $result = $api->request( $endpoint, 'GET' );
    if ( empty( $result['success'] ) ) {
        return new WP_Error( 'scp_provider_settings_error', $result['message'] ?? 'Unable to fetch provider settings', [ 'status' => 500 ] );
    }
    return rest_ensure_response( $result['data'] ?? [] );
}

function scp_rest_game_list( $request ) {
    $provider_id = $request->get_param( 'provider_id' );
    error_log( 'DEBUG scp_rest_game_list called with provider_id: ' . var_export( $provider_id, true ) );
    if ( empty( $provider_id ) ) {
        return new WP_Error( 'scp_game_list_missing_provider', 'Provider ID is required.', [ 'status' => 400 ] );
    }

    $games = get_posts( [
        'post_type'      => 'scp_game',
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'   => 'scp_game_provider_id',
                'value' => $provider_id,
            ],
        ],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $response = [];
    foreach ( $games as $game ) {
        $response[] = scp_format_lobby_game( $game );
    }

    return rest_ensure_response( $response );
}

function scp_launch_game_code( $game ) {
    $code = get_post_meta( $game->ID, 'scp_game_code', true );
    if ( $code !== '' && $code !== false ) {
        return (string) $code;
    }

    $game_id     = (string) get_post_meta( $game->ID, 'scp_game_id', true );
    $provider_id = (string) get_post_meta( $game->ID, 'scp_game_provider_id', true );
    $prefix      = $provider_id . '_';
    if ( $provider_id !== '' && strpos( $game_id, $prefix ) === 0 ) {
        return substr( $game_id, strlen( $prefix ) );
    }

    $separator = strpos( $game_id, '_' );
    return false === $separator ? $game_id : substr( $game_id, $separator + 1 );
}

function scp_format_lobby_game( $game ) {
    $enabled     = get_post_meta( $game->ID, 'scp_game_enabled', true );
    $provider_id = get_post_meta( $game->ID, 'scp_game_provider_id', true ) ?: '';
    $game_id     = get_post_meta( $game->ID, 'scp_game_id', true ) ?: '';
    $game_code   = scp_launch_game_code( $game );

    return [
        'gameID'        => $game_id,
        'gameCode'      => $game_code,
        'gameName'      => $game->post_title,
        'gameImage'     => get_the_post_thumbnail_url( $game->ID, 'full' ) ?: '',
        'gameType'      => get_post_meta( $game->ID, 'scp_game_type', true ) ?: '',
        'inMaintenance' => (bool) get_post_meta( $game->ID, 'scp_game_in_maintenance', true ),
        'status'        => $enabled ? '1' : '0',
        'providerId'    => $provider_id,
        'providerName'  => get_post_meta( $game->ID, 'scp_game_provider_name', true ) ?: '',
        'description'   => $game->post_content ?: '',
    ];
}

function scp_rest_game_catalog( $request ) {
    $search   = trim( (string) $request->get_param( 'q' ) );
    $provider = trim( (string) $request->get_param( 'provider' ) );
    $sort     = sanitize_text_field( (string) $request->get_param( 'sort' ) );
    $offset   = max( 0, absint( $request->get_param( 'offset' ) ) );
    $limit    = absint( $request->get_param( 'limit' ) );

    if ( $limit < 1 ) {
        $limit = 70;
    }
    $limit = min( 200, $limit );

    $meta_query = [
        'relation' => 'AND',
        [
            'key'   => 'scp_game_enabled',
            'value' => '1',
        ],
    ];

    if ( $provider !== '' ) {
        $meta_query[] = is_numeric( $provider )
            ? [
                'key'   => 'scp_game_provider_id',
                'value' => $provider,
            ]
            : [
                'key'     => 'scp_game_provider_name',
                'value'   => $provider,
                'compare' => '=',
            ];
    }

    $args = [
        'post_type'           => 'scp_game',
        'post_status'         => 'publish',
        'posts_per_page'      => $limit,
        'offset'              => $offset,
        'ignore_sticky_posts' => true,
        'meta_query'          => $meta_query,
    ];

    if ( $search !== '' ) {
        $args['s'] = $search;
    }

    if ( $sort === 'za' ) {
        $args['orderby'] = 'title';
        $args['order']   = 'DESC';
    } else {
        $args['orderby'] = 'title';
        $args['order']   = 'ASC';
    }

    $query = new WP_Query( $args );

    $items = [];
    foreach ( $query->posts as $game ) {
        $items[] = scp_format_lobby_game( $game );
    }

    return rest_ensure_response( [
        'items' => $items,
        'total' => (int) $query->found_posts,
    ] );
}

/**
 * Resolve a request param from registered REST args, then JSON body, then aliases.
 *
 * @param WP_REST_Request $request
 * @param string[]        $keys Preferred key first.
 * @return mixed|null
 */
function scp_rest_param( $request, array $keys ) {
    $json = $request->get_json_params();
    if ( ! is_array( $json ) ) {
        $json = [];
    }

    foreach ( $keys as $key ) {
        $value = $request->get_param( $key );
        if ( $value !== null && $value !== '' ) {
            return $value;
        }
        if ( array_key_exists( $key, $json ) && $json[ $key ] !== null && $json[ $key ] !== '' ) {
            return $json[ $key ];
        }
    }

    return null;
}

/**
 * Parse FE game slug `{providerId}-{gameCode}` (e.g. 16-16-619 → provider 16, code 16-619).
 *
 * @param string $slug
 * @return array{provider_id:string,game_code:string}|WP_Error
 */
function scp_parse_game_slug( $slug ) {
    $slug = trim( (string) $slug );
    $dash = strpos( $slug, '-' );
    if ( $dash === false || $dash < 1 ) {
        return new WP_Error( 'scp_game_invalid_slug', 'Game not found.', [ 'status' => 404 ] );
    }

    $provider_id = substr( $slug, 0, $dash );
    $game_code   = rawurldecode( substr( $slug, $dash + 1 ) );

    if ( $provider_id === '' || $game_code === '' || ! ctype_digit( $provider_id ) || (int) $provider_id < 1 ) {
        return new WP_Error( 'scp_game_invalid_slug', 'Game not found.', [ 'status' => 404 ] );
    }

    foreach ( array( '-', '_' ) as $separator ) {
        $prefix = $provider_id . $separator;
        if ( strpos( $game_code, $prefix ) === 0 && strlen( $game_code ) > strlen( $prefix ) ) {
            $game_code = substr( $game_code, strlen( $prefix ) );
        }
    }

    if ( $game_code === '' ) {
        return new WP_Error( 'scp_game_invalid_slug', 'Game not found.', [ 'status' => 404 ] );
    }

    return [
        'provider_id' => $provider_id,
        'game_code'   => $game_code,
    ];
}

function scp_rest_game_launch_by_slug( $request ) {
    $parsed = scp_parse_game_slug( $request->get_param( 'slug' ) );
    if ( is_wp_error( $parsed ) ) {
        return $parsed;
    }

    $request->set_param( 'providerId', $parsed['provider_id'] );
    $request->set_param( 'gameCode', $parsed['game_code'] );

    return scp_rest_game_launch( $request );
}

function scp_rest_game_launch( $request ) {
    $player_id = scp_rest_param( $request, [ 'playerExternalId', 'player_id' ] );
    if ( empty( $player_id ) && is_user_logged_in() ) {
        $user = wp_get_current_user();
        $player_id = $user->user_login;
    }

    if ( empty( $player_id ) ) {
        return new WP_Error( 'scp_game_launch_missing_user', 'Player ID is required.', [ 'status' => 403 ] );
    }

    $provider_id = scp_rest_param( $request, [ 'providerId', 'provider_id' ] );
    $game_code   = scp_rest_param( $request, [ 'gameCode', 'game_code' ] );

    if ( empty( $provider_id ) || empty( $game_code ) ) {
        return new WP_Error(
            'scp_game_launch_missing_params',
            'Missing parameter(s): providerId, gameCode',
            [ 'status' => 400 ]
        );
    }

    $language   = scp_rest_param( $request, [ 'language' ] ) ?: 'en';
    $currency   = scp_rest_param( $request, [ 'currency' ] ) ?: 'USD';
    $rtp        = scp_rest_param( $request, [ 'rtp' ] );
    if ( $rtp === null || $rtp === '' ) {
        $rtp = 0;
    }
    $return_url = scp_rest_param( $request, [ 'returnUrl', 'return_url' ] );

    $api = new SCP_API_Client();
    $result = $api->launch_game(
        $player_id,
        $provider_id,
        $game_code,
        $language,
        $currency,
        $rtp,
        $return_url ?: ''
    );
    if ( empty( $result['success'] ) ) {
        return new WP_Error( 'scp_game_launch_error', $result['message'] ?? 'Unable to launch game', [ 'status' => 500 ] );
    }

    $data = $result['data'] ?? [];
    $launch_url = $data['url'] ?? $data['gameUrl'] ?? '';
    if ( empty( $launch_url ) ) {
        return new WP_Error( 'scp_game_launch_no_url', 'Game launch succeeded but no URL was returned.', [ 'status' => 500 ] );
    }

    // Flat response for FE mapper (avoid nesting under `data`, which parseApiResponse unwraps).
    return rest_ensure_response( [
        'url'     => $launch_url,
        'gameUrl' => $launch_url,
    ] );
}

function scp_rest_game_kick( $request ) {
    $player_id = $request->get_param( 'player_id' );
    if ( empty( $player_id ) && is_user_logged_in() ) {
        $user = wp_get_current_user();
        $player_id = $user->user_login;
    }

    if ( empty( $player_id ) ) {
        return new WP_Error( 'scp_game_kick_missing_user', 'Player ID is required.', [ 'status' => 403 ] );
    }

    $payload = [ 'playerExternalId' => $player_id ];
    foreach ( [ 'game_session_id', 'provider_id', 'game_code', 'reason' ] as $field ) {
        $value = $request->get_param( $field );
        if ( ! empty( $value ) ) {
            $payload[ $field ] = $value;
        }
    }

    $api = new SCP_API_Client();
    $result = $api->request( '/v1/game/kick', 'POST', $payload );
    if ( empty( $result['success'] ) ) {
        return new WP_Error( 'scp_game_kick_error', $result['message'] ?? 'Unable to kick game', [ 'status' => 500 ] );
    }

    return rest_ensure_response( $result['data'] ?? [ 'message' => 'Game kicked successfully.' ] );
}

function scp_rest_resolve_deposit_amount( $request ) {
    $amount = floatval( $request->get_param( 'amount' ) );
    if ( $amount > 0 ) {
        return $amount;
    }

    $amount_cents = absint( $request->get_param( 'amountCents' ) );
    if ( $amount_cents <= 0 ) {
        $amount_cents = absint( $request->get_param( 'amount_cents' ) );
    }

    if ( $amount_cents > 0 ) {
        return $amount_cents / 100;
    }

    return 0.0;
}

function scp_rest_json_param( $request, $key, $default = null ) {
    $json = $request->get_json_params();
    if ( is_array( $json ) && array_key_exists( $key, $json ) ) {
        return $json[ $key ];
    }

    $value = $request->get_param( $key );
    return $value !== null ? $value : $default;
}

function scp_rest_wallet_deposit( $request ) {
    $player_id = $request->get_param( 'player_id' );
    $user_id = 0;
    $user = null;

    if ( empty( $player_id ) && is_user_logged_in() ) {
        $user = wp_get_current_user();
        $user_id = $user->ID;
        $player_id = $user->user_login;
    } elseif ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        $user_id = $user->ID;
        if ( empty( $player_id ) ) {
            $player_id = $user->user_login;
        }
    }

    if ( empty( $player_id ) ) {
        return new WP_Error( 'scp_wallet_deposit_missing_player', 'Player ID is required.', [ 'status' => 403 ] );
    }

    $amount   = scp_rest_resolve_deposit_amount( $request );
    $currency = strtoupper( $request->get_param( 'currency' ) ?: 'USD' );
    $method   = strtolower( sanitize_text_field( $request->get_param( 'method' ) ?: 'bank' ) );
    if ( ! in_array( $method, [ 'bank', 'card', 'paypal', 'crypto' ], true ) ) {
        $method = 'bank';
    }

    if ( $amount <= 0 ) {
        return new WP_Error( 'scp_wallet_deposit_invalid_amount', 'Deposit amount must be greater than zero.', [ 'status' => 400 ] );
    }

    $destinations = scp_get_deposit_destinations();
    if ( empty( $destinations[ $method ]['enabled'] ) ) {
        return new WP_Error( 'scp_wallet_deposit_method_disabled', 'This deposit method is not available.', [ 'status' => 400 ] );
    }

    $meta = [
        'method'         => $method,
        'payment_method' => $method,
        'source'         => 'fe',
    ];

    if ( $method === 'card' ) {
        $meta['card'] = scp_sanitize_card_meta( scp_rest_json_param( $request, 'card', [] ) );
        if ( empty( $meta['card']['cardholderName'] ) || empty( $meta['card']['last4'] ) ) {
            return new WP_Error( 'scp_wallet_deposit_card_required', 'Enter cardholder name and card number.', [ 'status' => 400 ] );
        }
    }

    if ( $method === 'paypal' ) {
        $paypal = scp_rest_json_param( $request, 'paypal', [] );
        $email  = is_array( $paypal ) ? sanitize_email( $paypal['email'] ?? '' ) : '';
        if ( $email === '' && $user ) {
            $email = $user->user_email;
        }
        if ( $email === '' ) {
            return new WP_Error( 'scp_wallet_deposit_paypal_required', 'Enter your PayPal email.', [ 'status' => 400 ] );
        }
        $meta['paypal'] = [ 'email' => $email ];
    }

    if ( $method === 'crypto' ) {
        $crypto   = scp_rest_json_param( $request, 'crypto', [] );
        $currency_code = is_array( $crypto ) ? strtoupper( sanitize_text_field( $crypto['currency'] ?? 'USDT' ) ) : 'USDT';
        $meta['crypto'] = [ 'currency' => $currency_code ];
    }

    $order_id = scp_create_pending_wallet_request( $user_id, $player_id, 'deposit', $amount, $currency, $meta );

    $balance_result  = scp_fetch_player_balance_payload( $player_id, $currency );
    $balance_payload = ! empty( $balance_result['success'] )
        ? $balance_result['data']
        : scp_build_balance_payload( [
            'balance' => [
                [
                    'currency' => $currency,
                    'amount'   => 0,
                ],
            ],
            'playerCode' => 0,
        ], $currency );

    if ( $user_id > 0 && ! empty( $balance_payload['balances'] ) ) {
        update_user_meta( $user_id, 'scp_player_balance', $balance_payload['balances'] );
        if ( ! empty( $balance_payload['playerCode'] ) ) {
            update_user_meta( $user_id, 'scp_player_code', $balance_payload['playerCode'] );
        }
    }

    if ( ! $user && $user_id > 0 ) {
        $user = get_userdata( $user_id );
    }

    $amount_cents = (int) round( $amount * 100 );
    $transaction = [
        'id'          => $order_id,
        'userId'      => $user ? (string) $user->ID : (string) $user_id,
        'amountCents' => $amount_cents,
        'priceCents'  => $amount_cents,
        'currency'    => $currency,
        'method'      => $method,
        'status'      => 'pending',
        'createdAt'   => current_time( 'c' ),
        'reference'   => $order_id,
        'txn_id'      => $order_id,
    ];

    $user_payload = $user
        ? scp_auth_user_payload( $user, $balance_payload )
        : [
            'id'           => (string) $user_id,
            'email'        => '',
            'displayName'  => $player_id,
            'username'     => $player_id,
            'avatarUrl'    => '',
            'balanceCents' => $balance_payload['balanceCents'],
            'currency'     => $balance_payload['currency'],
            'ownedGameIds' => [],
            'createdAt'    => current_time( 'c' ),
        ];

    return rest_ensure_response( [
        'user'        => $user_payload,
        'transaction' => $transaction,
    ] );
}

function scp_rest_player_balance( $request ) {
    $player_id = $request->get_param( 'playerExternalId' );
    if ( empty( $player_id ) ) {
        $player_id = $request->get_param( 'player_id' );
    }

    $user = null;
    if ( empty( $player_id ) && is_user_logged_in() ) {
        $user = wp_get_current_user();
        $player_id = $user->user_login;
    } elseif ( is_user_logged_in() ) {
        $user = wp_get_current_user();
    }

    if ( empty( $player_id ) ) {
        return new WP_Error( 'scp_player_balance_missing_player', 'Player ID is required.', [ 'status' => 403 ] );
    }

    $result = scp_fetch_player_balance_payload( $player_id, 'USD' );
    if ( empty( $result['success'] ) ) {
        return new WP_Error(
            'scp_player_balance_error',
            $result['message'] ?? 'Unable to fetch balance',
            [ 'status' => 500 ]
        );
    }

    $payload = $result['data'];

    if ( $user && ! empty( $payload['balances'] ) ) {
        update_user_meta( $user->ID, 'scp_player_balance', $payload['balances'] );
        if ( ! empty( $payload['playerCode'] ) ) {
            update_user_meta( $user->ID, 'scp_player_code', $payload['playerCode'] );
        }
    }

    return rest_ensure_response( $payload );
}

function scp_rest_wallet_withdraw( $request ) {
    $player_id = $request->get_param( 'player_id' );
    $user_id = 0;
    $user = null;

    if ( empty( $player_id ) && is_user_logged_in() ) {
        $user = wp_get_current_user();
        $user_id = $user->ID;
        $player_id = $user->user_login;
    } elseif ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        $user_id = $user->ID;
        if ( empty( $player_id ) ) {
            $player_id = $user->user_login;
        }
    }

    if ( empty( $player_id ) ) {
        return new WP_Error( 'scp_wallet_withdraw_missing_player', 'Player ID is required.', [ 'status' => 403 ] );
    }

    $amount      = floatval( $request->get_param( 'amount' ) );
    $currency    = strtoupper( $request->get_param( 'currency' ) ?: 'USD' );
    $destination = scp_sanitize_withdraw_destination( scp_rest_json_param( $request, 'destination', [] ) );
    $method      = strtolower( sanitize_text_field( $request->get_param( 'method' ) ?: ( $destination['method'] ?? 'bank' ) ) );
    if ( ! in_array( $method, [ 'bank', 'crypto', 'paypal' ], true ) ) {
        $method = 'bank';
    }
    $destination['method'] = $method;

    if ( $amount <= 0 ) {
        return new WP_Error( 'scp_wallet_withdraw_invalid_amount', 'Withdrawal amount must be greater than zero.', [ 'status' => 400 ] );
    }

    $withdraw_methods = scp_get_withdraw_methods();
    if ( empty( $withdraw_methods[ $method ]['enabled'] ) ) {
        return new WP_Error( 'scp_wallet_withdraw_method_disabled', 'This withdrawal method is not available.', [ 'status' => 400 ] );
    }

    if ( $method === 'bank' && ( $destination['accountName'] === '' || $destination['accountNumber'] === '' ) ) {
        return new WP_Error( 'scp_wallet_withdraw_bank_required', 'Enter your account name and number.', [ 'status' => 400 ] );
    }
    if ( $method === 'crypto' && $destination['cryptoAddress'] === '' ) {
        return new WP_Error( 'scp_wallet_withdraw_crypto_required', 'Enter your crypto payout address.', [ 'status' => 400 ] );
    }
    if ( $method === 'paypal' ) {
        if ( $destination['paypalEmail'] === '' && $user ) {
            $destination['paypalEmail'] = $user->user_email;
        }
        if ( $destination['paypalEmail'] === '' ) {
            return new WP_Error( 'scp_wallet_withdraw_paypal_required', 'Enter your PayPal email.', [ 'status' => 400 ] );
        }
    }

    $balance_check = scp_get_player_balance( $player_id, $currency );
    if ( ! empty( $balance_check['success'] ) && ! empty( $balance_check['balances'][0]['amount'] ) ) {
        $available = floatval( $balance_check['balances'][0]['amount'] );
        if ( $amount > $available ) {
            return new WP_Error( 'scp_wallet_withdraw_exceeds_balance', 'Amount exceeds your available balance.', [ 'status' => 400 ] );
        }
    }

    $meta = [
        'method'         => $method,
        'payment_method' => $method,
        'destination'    => $destination,
        'source'         => 'fe',
    ];

    $txn_id = scp_create_pending_wallet_request( $user_id, $player_id, 'withdraw', $amount, $currency, $meta );

    $balance_result  = scp_fetch_player_balance_payload( $player_id, $currency );
    $balance_payload = ! empty( $balance_result['success'] )
        ? $balance_result['data']
        : scp_build_balance_payload( [
            'balance'    => [ [ 'currency' => $currency, 'amount' => 0 ] ],
            'playerCode' => 0,
        ], $currency );

    $balance = floatval( $balance_payload['balances'][0]['amount'] ?? 0 );

    return rest_ensure_response( [
        'currency'       => $currency,
        'balance'        => $balance,
        'withdrawAmount' => $amount,
        'status'         => 'pending',
        'txn_id'         => $txn_id,
        'reference'      => $txn_id,
        'method'         => $method,
    ] );
}

function scp_rest_wallet_transactions( $request ) {
    $player_id = $request->get_param( 'player_id' );
    $user_id   = 0;

    if ( empty( $player_id ) && is_user_logged_in() ) {
        $user = wp_get_current_user();
        $user_id = $user->ID;
        $player_id = $user->user_login;
    } elseif ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        $user_id = $user->ID;
    }

    $limit = absint( $request->get_param( 'limit' ) ) ?: 50;

    if ( $user_id > 0 ) {
        $rows = scp_get_user_transactions( $user_id, $limit );
        $transactions = array_map( 'scp_format_transaction_for_rest', $rows );
        return rest_ensure_response( [ 'transactions' => $transactions ] );
    }

    return rest_ensure_response( [ 'transactions' => [] ] );
}

function scp_rest_transaction_list( $request ) {
    $player_id = $request->get_param( 'player_id' );
    $user_id   = absint( $request->get_param( 'user_id' ) );
    $limit     = absint( $request->get_param( 'limit' ) );

    if ( $user_id === 0 && is_user_logged_in() ) {
        $user_id = get_current_user_id();
    }

    if ( $user_id === 0 && empty( $player_id ) ) {
        return new WP_Error( 'scp_transaction_list_missing_user', 'User ID or Player ID is required.', [ 'status' => 403 ] );
    }

    if ( $user_id > 0 ) {
        $transactions = scp_get_user_transactions( $user_id, $limit ?: 100 );
        return rest_ensure_response( $transactions );
    }

    return rest_ensure_response( [ 'message' => 'No transactions available.' ] );
}

function scp_rest_support_contact( $request ) {
    $subject = $request->get_param( 'subject' );
    $message = $request->get_param( 'message' );
    $email   = sanitize_email( $request->get_param( 'email' ) );
    $player_id = $request->get_param( 'player_id' );

    if ( empty( $subject ) || empty( $message ) ) {
        return new WP_Error( 'scp_support_contact_invalid', 'Subject and message are required.', [ 'status' => 400 ] );
    }

    if ( empty( $player_id ) && is_user_logged_in() ) {
        $user = wp_get_current_user();
        $player_id = $user->user_login;
        if ( empty( $email ) ) {
            $email = $user->user_email;
        }
    }

    $payload = [
        'subject'          => $subject,
        'message'          => $message,
        'email'            => $email,
        'playerExternalId' => $player_id,
    ];

    $api = new SCP_API_Client();
    $result = $api->request( '/support/contact', 'POST', $payload );
    if ( empty( $result['success'] ) ) {
        return new WP_Error( 'scp_support_contact_error', $result['message'] ?? 'Unable to send support request.', [ 'status' => 500 ] );
    }

    return rest_ensure_response( $result['data'] ?? [ 'message' => 'Contact form submitted successfully.' ] );
}

function scp_api_ajax_response( $callback ) {
    $result = call_user_func( $callback );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }
    wp_send_json_success( $result );
}

add_action( 'wp_ajax_scp_provider_list', function() {
    scp_api_ajax_response( 'scp_rest_provider_list' );
} );
add_action( 'wp_ajax_nopriv_scp_provider_list', function() {
    scp_api_ajax_response( 'scp_rest_provider_list' );
} );
add_action( 'wp_ajax_scp_provider_settings', function() {
    scp_api_ajax_response( function() { return scp_rest_provider_settings( new WP_REST_Request( 'GET', '/scp/v1/provider/settings' ) ); } );
} );
add_action( 'wp_ajax_scp_game_list', function() {
    $provider_id = sanitize_text_field( $_REQUEST['provider_id'] ?? '' );
    $request = new WP_REST_Request( 'GET', '/scp/v1/game/list/' . rawurlencode( $provider_id ) );
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_game_list( $request ); } );
} );
add_action( 'wp_ajax_scp_game_launch', function() {
    $request = new WP_REST_Request( 'POST', '/scp/v1/game/launch' );
    $request->set_param( 'providerId', sanitize_text_field( $_POST['providerId'] ?? $_POST['provider_id'] ?? '' ) );
    $request->set_param( 'gameCode', sanitize_text_field( $_POST['gameCode'] ?? $_POST['game_code'] ?? '' ) );
    $request->set_param( 'playerExternalId', sanitize_text_field( $_POST['playerExternalId'] ?? $_POST['player_id'] ?? '' ) );
    $request->set_param( 'returnUrl', esc_url_raw( $_POST['returnUrl'] ?? $_POST['return_url'] ?? '' ) );
    foreach ( [ 'language', 'currency', 'rtp' ] as $param ) {
        $request->set_param( $param, sanitize_text_field( $_POST[ $param ] ?? '' ) );
    }
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_game_launch( $request ); } );
} );
add_action( 'wp_ajax_scp_game_kick', function() {
    $request = new WP_REST_Request( 'POST', '/scp/v1/game/kick' );
    foreach ( [ 'game_session_id', 'provider_id', 'game_code', 'reason' ] as $param ) {
        $request->set_param( $param, sanitize_text_field( $_POST[ $param ] ?? '' ) );
    }
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_game_kick( $request ); } );
} );
add_action( 'wp_ajax_scp_wallet_deposit', function() {
    $request = new WP_REST_Request( 'POST', '/scp/v1/wallet/deposit' );
    $request->set_param( 'amount', floatval( $_POST['amount'] ?? 0 ) );
    $request->set_param( 'amountCents', absint( $_POST['amountCents'] ?? $_POST['amount_cents'] ?? 0 ) );
    $request->set_param( 'currency', sanitize_text_field( $_POST['currency'] ?? 'USD' ) );
    $request->set_param( 'order_id', sanitize_text_field( $_POST['order_id'] ?? '' ) );
    $request->set_param( 'method', sanitize_text_field( $_POST['method'] ?? 'card' ) );
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_wallet_deposit( $request ); } );
} );
add_action( 'wp_ajax_scp_player_balance', function() {
    $request = new WP_REST_Request( 'GET', '/scp/v1/player/balance' );
    $request->set_param( 'playerExternalId', sanitize_text_field( $_REQUEST['playerExternalId'] ?? $_REQUEST['player_id'] ?? '' ) );
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_player_balance( $request ); } );
} );
add_action( 'wp_ajax_scp_wallet_withdraw', function() {
    $request = new WP_REST_Request( 'POST', '/scp/v1/wallet/withdraw' );
    $request->set_param( 'amount', floatval( $_POST['amount'] ?? 0 ) );
    $request->set_param( 'currency', sanitize_text_field( $_POST['currency'] ?? 'USD' ) );
    $request->set_param( 'withdrawal_id', sanitize_text_field( $_POST['withdrawal_id'] ?? '' ) );
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_wallet_withdraw( $request ); } );
} );
add_action( 'wp_ajax_scp_wallet_transactions', function() {
    $request = new WP_REST_Request( 'GET', '/scp/v1/wallet/transactions' );
    $request->set_param( 'limit', absint( $_REQUEST['limit'] ?? 50 ) );
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_wallet_transactions( $request ); } );
} );
add_action( 'wp_ajax_scp_transaction_list', function() {
    $request = new WP_REST_Request( 'GET', '/scp/v1/transaction/list' );
    $request->set_param( 'user_id', absint( $_REQUEST['user_id'] ?? 0 ) );
    $request->set_param( 'limit', absint( $_REQUEST['limit'] ?? 100 ) );
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_transaction_list( $request ); } );
} );
add_action( 'wp_ajax_scp_support_contact', function() {
    $request = new WP_REST_Request( 'POST', '/scp/v1/support/contact' );
    $request->set_param( 'subject', sanitize_text_field( $_POST['subject'] ?? '' ) );
    $request->set_param( 'message', sanitize_textarea_field( $_POST['message'] ?? '' ) );
    $request->set_param( 'email', sanitize_email( $_POST['email'] ?? '' ) );
    scp_api_ajax_response( function() use ( $request ) { return scp_rest_support_contact( $request ); } );
} );
