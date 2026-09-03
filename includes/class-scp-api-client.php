<?php
class SCP_API_Client {

    private $base_url = 'https://new-api.scorpioplay.com';
    private $api_key = '936d16bc-94b6-4323-b0c4-44ab0011a033';
    private $player_token; // can be set per request

    public function __construct() {
        $this->base_url = get_option( 'scp_api_base_url', '' );
        $this->api_key = get_option( 'scp_api_key', '' );
    }

    public function request( $endpoint, $method = 'POST', $body = array(), $extra_headers = array() ) {
        $url  = $this->base_url . $endpoint;
        $args = array(
            'method'  => $method,
            'headers' => array_merge( array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ), $extra_headers ),
            'timeout' => 30,
        );

        if ( ! empty( $body ) && in_array( $method, array('POST', 'PUT', 'PATCH') ) ) {
            $args['body'] = json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'API Request Error', $response->get_error_message() );
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status >= 400 ) {
            $msg = isset( $body['message'] ) ? $body['message'] : 'Unknown API error';
            $this->log_error( "API Error $status", $msg, $body );
            return [ 'success' => false, 'status' => $status, 'message' => $msg, 'data' => $body ];
        }

        return $body;
    }

    public function set_player_token( $token ) {
        $this->player_token = $token;
    }

    public function deposit( $userId, $amount, $currency, $txn_id ) {
        // Log transaction locally before API call
        scp_log_transaction( $txn_id, $userId, 'deposit', $amount, $currency, 'pending' );

        $response = $this->request( '/v1/player/wallet/deposit', 'POST', array(
            'playerExternalId' => $userId,
            'currency'         => $currency,
            'amount'           => $amount,
        ));

        // Update transaction status based on response
        if ( ! empty( $response['success'] ) ) {
            scp_log_transaction( $txn_id, $userId, 'deposit', $amount, $currency, 'completed', $response );
        } else {
            scp_log_transaction( $txn_id, $userId, 'deposit', $amount, $currency, 'failed', $response );
        }

        return $response;
    }

    public function withdraw( $userId, $amount, $currency, $txn_id ) {
        // Log transaction locally before API call
        scp_log_transaction( $txn_id, $userId, 'withdraw', $amount, $currency, 'pending' );

        $response = $this->request( '/v1/player/wallet/withdraw', 'POST', array(
            'playerExternalId' => $userId,
            'currency'         => $currency,
            'amount'           => $amount,
        ));

        // Update transaction status based on response
        if ( ! empty( $response['success'] ) ) {
            scp_log_transaction( $txn_id, $userId, 'withdraw', $amount, $currency, 'completed', $response );
        } else {
            scp_log_transaction( $txn_id, $userId, 'withdraw', $amount, $currency, 'failed', $response );
        }

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
            'playerExternalId' => $playerExternalId,
            'providerId'       => $providerId,
            'gameCode'         => $gameCode,
            'language'         => $language,
            'currency'         => $currency,
            'rtp'              => $rtp,
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

    public function transaction_list( $playerExternalId = '' ) {
        $endpoint = '/v1/transaction/list';
        if ( ! empty( $playerExternalId ) ) {
            $endpoint .= '?playerExternalId=' . rawurlencode( $playerExternalId );
        }
        return $this->request( $endpoint, 'GET' );
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

    private function log_error( $type, $message, $data = null ) {
        $log = "[$type] $message";
        if ( $data ) {
            $log .= ' | Data: ' . wp_json_encode( $data );
        }
        error_log( 'ScorpioPlay API: ' . $log );
    }
}