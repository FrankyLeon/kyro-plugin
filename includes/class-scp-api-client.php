<?php
class SCP_API_Client {

    private $base_url = 'https://new-api.scorpioplay.com';
    private $api_key = '936d16bc-94b6-4323-b0c4-44ab0011a033';
    private $player_token; // can be set per request

    public function __construct() {
        $this->base_url = untrailingslashit( (string) get_option( 'scp_api_base_url', '' ) ) ?: $this->base_url;
        $saved_key      = (string) get_option( 'scp_api_key', '' );
        $this->api_key  = $saved_key !== '' ? $saved_key : $this->api_key;
    }

    public function request( $endpoint, $method = 'POST', $body = array(), $extra_headers = array() ) {
        $method  = strtoupper( $method );
        $url     = $this->base_url . $endpoint;
        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Accept'        => 'application/json',
        );
        if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $headers['Content-Type'] = 'application/json';
        }

        $args = array(
            'method'  => $method,
            'headers' => array_merge( $headers, $extra_headers ),
            'timeout' => 30,
        );

        if ( ! empty( $body ) && $method === 'GET' ) {
            $url = $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $body, '', '&', PHP_QUERY_RFC3986 );
        } elseif ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'API Request Error', $response->get_error_message() );
            return array( 'success' => false, 'message' => $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );
        $parsed = json_decode( $raw, true );
        $body   = is_array( $parsed ) ? $parsed : array();

        if ( $status >= 400 ) {
            $msg = $this->extract_error_message( $body, $status, $raw );
            $this->log_error( "API Error $status", $msg, $body );
            return array( 'success' => false, 'status' => $status, 'message' => $msg, 'data' => $body );
        }

        if ( ! array_key_exists( 'success', $body ) ) {
            $body['success'] = true;
        }

        return $body;
    }

    public function set_player_token( $token ) {
        $this->player_token = $token;
    }

    public function deposit( $userId, $amount, $currency, $txn_id ) {
        return $this->transfer( 'deposit', $userId, $amount, $currency, $txn_id, '/v1/player/wallet/deposit' );
    }

    public function withdraw( $userId, $amount, $currency, $txn_id ) {
        return $this->transfer( 'withdraw', $userId, $amount, $currency, $txn_id, '/v1/player/wallet/withdraw' );
    }

    private function transfer( $type, $userId, $amount, $currency, $txn_id, $endpoint ) {
        $existing = function_exists( 'scp_get_transaction' ) ? scp_get_transaction( $txn_id ) : null;
        $meta     = function_exists( 'scp_decode_transaction_response' )
            ? scp_decode_transaction_response( $existing )
            : array();

        scp_log_transaction( $txn_id, $userId, $type, $amount, $currency, 'pending', $meta );

        $response = $this->request( $endpoint, 'POST', array(
            'playerExternalId' => $userId,
            'currency'         => $currency,
            'amount'           => $amount,
        ) );

        $meta['api'] = $response;
        $status      = ! empty( $response['success'] ) ? 'completed' : 'failed';
        $data        = is_array( $response['data'] ?? null ) ? $response['data'] : array();
        $scp_txn_id  = $data['transaction_id'] ?? $data['transactionId'] ?? '';

        scp_log_transaction( $txn_id, $userId, $type, $amount, $currency, $status, $meta, '', $scp_txn_id );

        return $response;
    }

    public function get_provider_list() {
        return $this->request( '/v1/provider/list', 'GET' );
    }

    public function get_provider_settings( $provider_id = '' ) {
        $endpoint = '/v1/provider/settings';
        if ( ! empty( $provider_id ) ) {
            $endpoint .= '?providerId=' . rawurlencode( $provider_id );
        }
        return $this->request( $endpoint, 'GET' );
    }

    public function get_game_list( $provider_id ) {
        return $this->request( '/v1/game/list/' . rawurlencode( $provider_id ), 'GET' );
    }

    public function launch_game( $playerExternalId, $providerId, $gameCode, $language = 'en', $currency = 'USD', $rtp = 0, $returnUrl = '' ) {
        $payload = array(
            'playerExternalId' => (string) $playerExternalId,
            'providerId'       => (int) $providerId,
            'gameCode'         => (string) $gameCode,
            'language'         => (string) ( $language ?: 'en' ),
            'currency'         => (string) ( $currency ?: 'USD' ),
            'rtp'              => is_numeric( $rtp ) ? (int) $rtp : 0,
        );
        if ( ! empty( $returnUrl ) ) {
            $payload['returnUrl'] = $returnUrl;
        }
        return $this->request( '/v1/game/launch', 'POST', $payload );
    }

    public function kick_game( $playerExternalId, $sessionId = '', $providerId = '', $gameCode = '', $reason = '' ) {
        $payload = [ 'playerExternalId' => $playerExternalId ];
        if ( ! empty( $sessionId ) ) {
            $payload['game_session_id'] = $sessionId;
        }
        if ( ! empty( $providerId ) ) {
            $payload['providerId'] = $providerId;
        }
        if ( ! empty( $gameCode ) ) {
            $payload['game_code'] = $gameCode;
        }
        if ( ! empty( $reason ) ) {
            $payload['reason'] = $reason;
        }
        return $this->request( '/v1/game/kick', 'POST', $payload );
    }

    public function wallet_transactions( $playerExternalId ) {
        return $this->request( '/v1/wallet/transactions', 'GET', [], [ 'playerExternalId' => $playerExternalId ] );
    }

    /**
     * GET /v1/transaction/list — startTime, endTime, offset, and limit are required by the API.
     *
     * @param array $params {
     *     @type string $startTime
     *     @type string $endTime
     *     @type int    $offset
     *     @type int    $limit
     *     @type string $playerExternalId
     *     @type string $roundId
     *     @type string $transType
     *     @type string $operator
     * }
     */
    public function transaction_list( $params = array() ) {
        if ( ! is_array( $params ) ) {
            $params = array( 'playerExternalId' => (string) $params );
        }

        $query = array();
        foreach ( $params as $key => $value ) {
            if ( $value === null || $value === '' ) {
                continue;
            }
            $query[ $key ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
        }

        $qs = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        return $this->request( '/v1/transaction/list?' . $qs, 'GET' );
    }

    public function support_contact( $playerExternalId, $subject, $message, $email = '' ) {
        $payload = [
            'playerExternalId' => $playerExternalId,
            'subject'          => $subject,
            'message'          => $message,
        ];
        if ( ! empty( $email ) ) {
            $payload['email'] = $email;
        }
        return $this->request( '/v1/support/contact', 'POST', $payload );
    }

    /**
     * Test authentication – e.g., ping or a simple endpoint.
     */
    public function test_connection() {
        return $this->request( 'auth/test', 'GET' ); // adjust to your API
    }

    private function extract_error_message( $body, $status, $raw = '' ) {
        foreach ( array( 'message', 'error', 'errorMessage', 'msg', 'detail' ) as $key ) {
            if ( ! isset( $body[ $key ] ) || $body[ $key ] === '' ) {
                continue;
            }
            if ( is_array( $body[ $key ] ) ) {
                $parts = array();
                array_walk_recursive( $body[ $key ], function( $item ) use ( &$parts ) {
                    if ( is_scalar( $item ) && $item !== '' ) {
                        $parts[] = (string) $item;
                    }
                } );
                if ( $parts ) {
                    return implode( '; ', $parts );
                }
                continue;
            }
            if ( is_scalar( $body[ $key ] ) ) {
                return (string) $body[ $key ];
            }
        }

        if ( $status ) {
            return 'Scorpio API HTTP ' . $status;
        }

        $raw = trim( wp_strip_all_tags( (string) $raw ) );
        return $raw !== '' ? substr( $raw, 0, 180 ) : 'Unknown API error';
    }

    private function log_error( $type, $message, $data = null ) {
        $log = "[$type] $message";
        if ( $data ) {
            $log .= ' | Data: ' . wp_json_encode( $data );
        }
        error_log( 'ScorpioPlay API: ' . $log );
    }
}