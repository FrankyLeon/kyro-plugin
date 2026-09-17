<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCP_BEP20_Wallet {
    const CHAIN_ID       = 56;
    const USDT_CONTRACT  = '0x55d398326f99059ff775485246999027b3197955';
    const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    const DEFAULT_RPC   = 'https://bsc-dataseed.binance.org';
    const TESTNET_RPC    = 'https://bsc-testnet-rpc.publicnode.com';
    const DECIMALS      = 18;
    const GAS_LIMIT      = '0x186a0';

    public static function mode() {
        return 'live';
    }

    public static function is_simulate() {
        return false;
    }

    public static function fake_tx_hash( $seed = '' ) {
        return '0x' . hash( 'sha256', 'scp-bep20-test|' . $seed . '|' . microtime( true ) . '|' . wp_generate_password( 16, false ) );
    }

    public static function settings() {
        $dest    = function_exists( 'scp_get_deposit_destinations' ) ? scp_get_deposit_destinations() : array();
        $usdt    = isset( $dest['crypto']['USDT'] ) && is_array( $dest['crypto']['USDT'] ) ? $dest['crypto']['USDT'] : array();
        $address = self::normalize_address( $usdt['address'] ?? '' );
        $private = scp_bep20_get_private_key();
        if ( $address === '' && $private !== '' && class_exists( 'SCP_Secp256k1' ) && SCP_Eth_Math::available() ) {
            $address = self::normalize_address( SCP_Secp256k1::private_to_address( $private ) );
        }

        $rpc      = ! empty( $usdt['rpc_url'] ) ? $usdt['rpc_url'] : self::DEFAULT_RPC;
        $contract  = ! empty( $usdt['contract'] ) ? $usdt['contract'] : self::USDT_CONTRACT;
        $chain_id  = self::CHAIN_ID;
        $label     = 'BNB Smart Chain';

        $env_rpc      = function_exists( 'scp_env' ) ? scp_env( 'SCP_BEP20_RPC_URL', '' ) : '';
        $env_contract  = function_exists( 'scp_env' ) ? scp_env( 'SCP_BEP20_USDT_CONTRACT', '' ) : '';
        $env_chain     = function_exists( 'scp_env' ) ? scp_env( 'SCP_BEP20_CHAIN_ID', '' ) : '';

        if ( $env_rpc !== '' ) {
            $rpc = $env_rpc;
        }
        if ( $env_contract !== '' ) {
            $contract = $env_contract;
        }
        if ( $env_chain !== '' && absint( $env_chain ) > 0 ) {
            $chain_id = absint( $env_chain );
        }

        return array(
            'mode'              => 'live',
            'simulate'          => false,
            'network_label'     => $label,
            'chain_id'          => (int) $chain_id,
            'address'           => $address,
            'rpc_url'           => esc_url_raw( $rpc ) ?: self::DEFAULT_RPC,
            'contract'          => self::normalize_address( $contract ) ?: self::USDT_CONTRACT,
            'decimals'          => max( 0, (int) ( $usdt['decimals'] ?? self::DECIMALS ) ),
            'min_confirmations' => max( 0, (int) ( $usdt['min_confirmations'] ?? 3 ) ),
            'private_key'      => $private,
        );
    }

    public static function is_ready_to_send() {
        $s = self::settings();
        if ( $s['address'] === '' ) {
            return false;
        }
        if ( ! empty( $s['simulate'] ) ) {
            return true;
        }
        return $s['private_key'] !== '' && SCP_Eth_Math::available();
    }

    public static function is_ready_to_verify() {
        $s = self::settings();
        return $s['address'] !== '';
    }

    public static function transfer_usdt( $destination, $amount ) {
        $s           = self::settings();
        $destination = self::normalize_address( $destination );
        $amount      = (float) $amount;

        if ( $s['address'] === '' ) {
            return self::fail( 'BEP-20 wallet address is not configured.' );
        }
        if ( ! self::is_address( $destination ) ) {
            return self::fail( 'A valid destination BEP-20 address is required.' );
        }
        if ( strtolower( $destination ) === strtolower( $s['address'] ) ) {
            return self::fail( 'Destination cannot be the operator wallet.' );
        }
        if ( $amount <= 0 ) {
            return self::fail( 'Withdrawal amount must be greater than zero.' );
        }

        if ( ! empty( $s['simulate'] ) ) {
            return array(
                'success'   => true,
                'txHash'    => self::fake_tx_hash( $destination . '|' . $amount ),
                'from'      => $s['address'],
                'to'        => $destination,
                'amount'    => $amount,
                'network'   => $s['network_label'],
                'mode'      => 'test',
                'simulated' => true,
            );
        }

        if ( ! SCP_Eth_Math::available() ) {
            return self::fail( 'PHP GMP or BCMath is required to send BEP-20 USDT.' );
        }

        if ( $s['private_key'] === '' ) {
            return self::fail( 'BEP-20 private key is not configured. Add it under Deposit Destinations.' );
        }

        $derived = SCP_Secp256k1::private_to_address( $s['private_key'] );
        if ( $derived && strtolower( $derived ) !== strtolower( $s['address'] ) ) {
            return self::fail( 'BEP-20 private key does not match the registered USDT wallet address.' );
        }

        $wei = self::to_token_units( $amount, $s['decimals'] );
        if ( $wei === '0' ) {
            return self::fail( 'Amount is too small for USDT decimals.' );
        }

        $from     = $s['address'];
        $contract = $s['contract'];
        $data     = self::encode_transfer( $destination, $wei );

        $balance = self::token_balance( $from );
        if ( empty( $balance['success'] ) ) {
            return $balance;
        }
        if ( SCP_Eth_Math::cmp( $balance['raw'], $wei ) < 0 ) {
            return self::fail( 'Operator USDT balance is too low to send this amount.' );
        }

        $nonce = self::rpc( 'eth_getTransactionCount', array( $from, 'pending' ) );
        if ( empty( $nonce['success'] ) ) {
            return $nonce;
        }

        $gas_price = self::rpc( 'eth_gasPrice' );
        if ( empty( $gas_price['success'] ) ) {
            return $gas_price;
        }

        $raw = SCP_Eth_Tx::sign_legacy(
            $nonce['result'],
            $gas_price['result'],
            self::GAS_LIMIT,
            $contract,
            '0x0',
            $data,
            $s['private_key'],
            (int) $s['chain_id']
        );
        if ( $raw === '' ) {
            return self::fail( 'Unable to sign the BEP-20 transfer.' );
        }

        $sent = self::rpc( 'eth_sendRawTransaction', array( $raw ) );
        if ( empty( $sent['success'] ) ) {
            return $sent;
        }

        $tx_hash = self::normalize_tx_hash( $sent['result'] );
        if ( $tx_hash === '' ) {
            return self::fail( 'BSC node did not return a transaction hash.' );
        }

        $receipt = self::wait_for_receipt( $tx_hash );
        if ( empty( $receipt['success'] ) ) {
            return array(
                'success' => false,
                'message' => $receipt['message'] ?? 'BEP-20 transfer was submitted but is not confirmed yet.',
                'txHash'  => $tx_hash,
            );
        }

        return array(
            'success'     => true,
            'txHash'      => $tx_hash,
            'destination' => $destination,
            'amount'     => $amount,
            'network'    => 'BEP-20',
            'asset'      => 'USDT',
        );
    }

    public static function verify_incoming_usdt( $tx_hash, $expected_from = '', $expected_amount = 0 ) {
        $s        = self::settings();
        $tx_hash  = self::normalize_tx_hash( $tx_hash );
        $from     = self::normalize_address( $expected_from );
        $expected = (float) $expected_amount;

        if ( $s['address'] === '' ) {
            return self::fail( 'Operator BEP-20 address is not configured.' );
        }

        if ( ! empty( $s['simulate'] ) ) {
            if ( $expected <= 0 ) {
                return self::fail( 'Deposit amount must be greater than zero.' );
            }
            if ( $tx_hash === '' ) {
                $tx_hash = self::fake_tx_hash( $from . '|' . $expected );
            }
            return array(
                'success'       => true,
                'txHash'        => $tx_hash,
                'from'          => $from ?: $s['address'],
                'to'            => $s['address'],
                'amount'        => $expected,
                'onChainAmount' => $expected,
                'network'       => $s['network_label'],
                'asset'         => 'USDT',
                'mode'          => 'test',
                'simulated'     => true,
            );
        }

        if ( $tx_hash === '' ) {
            return self::fail( 'A BNB Smart Chain transaction hash is required.' );
        }

        $used = scp_get_transaction_by_gateway_id( $tx_hash );
        if ( $used && $used->status === 'completed' ) {
            return self::fail( 'This transaction hash was already credited.' );
        }

        $receipt = self::rpc( 'eth_getTransactionReceipt', array( $tx_hash ) );
        if ( empty( $receipt['success'] ) ) {
            return $receipt;
        }
        $receipt_data = $receipt['result'];
        if ( ! is_array( $receipt_data ) ) {
            return self::fail( 'Transaction is not confirmed yet. Wait for BSC confirmations and try again.' );
        }
        $status = strtolower( (string) ( $receipt_data['status'] ?? '' ) );
        if ( $status !== '0x1' && $status !== '1' ) {
            return self::fail( 'On-chain transfer failed.' );
        }

        $block_hex = $receipt_data['blockNumber'] ?? '';
        if ( $s['min_confirmations'] > 0 ) {
            $latest = self::rpc( 'eth_blockNumber' );
            if ( empty( $latest['success'] ) ) {
                return $latest;
            }
            $conf = hexdec( $latest['result'] ) - hexdec( $block_hex ) + 1;
            if ( $conf < $s['min_confirmations'] ) {
                return self::fail( 'Waiting for more confirmations (' . $conf . '/' . $s['min_confirmations'] . ').' );
            }
        }

        $transfer = self::find_usdt_transfer( $receipt_data['logs'] ?? array(), $s['contract'], $s['address'] );
        if ( ! $transfer ) {
            return self::fail( 'No USDT BEP-20 transfer to the operator wallet was found in this transaction.' );
        }

        if ( $from && strtolower( $transfer['from'] ) !== strtolower( $from ) ) {
            return self::fail( 'Transaction sender does not match the provided destination address.' );
        }

        $on_chain_amount = (float) self::from_token_units( $transfer['amount'], $s['decimals'] );
        if ( $expected > 0 && $on_chain_amount + 0.00000001 < $expected ) {
            return self::fail( 'On-chain amount is less than the selected deposit amount.' );
        }

        return array(
            'success'       => true,
            'txHash'        => $tx_hash,
            'from'          => $transfer['from'],
            'to'            => $s['address'],
            'amount'        => $on_chain_amount,
            'onChainAmount' => $on_chain_amount,
            'network'       => 'BEP-20',
            'asset'         => 'USDT',
        );
    }

    public static function normalize_address( $address ) {
        $address = strtolower( trim( (string) $address ) );
        if ( $address === '' ) {
            return '';
        }
        if ( strpos( $address, '0x' ) !== 0 ) {
            $address = '0x' . $address;
        }
        return self::is_address( $address ) ? $address : '';
    }

    public static function normalize_tx_hash( $hash ) {
        $hash = strtolower( trim( (string) $hash ) );
        if ( $hash === '' ) {
            return '';
        }
        if ( strpos( $hash, '0x' ) !== 0 ) {
            $hash = '0x' . $hash;
        }
        return preg_match( '/^0x[0-9a-f]{64}$/', $hash ) ? $hash : '';
    }

    public static function is_address( $address ) {
        return (bool) preg_match( '/^0x[0-9a-f]{40}$/i', (string) $address );
    }

    public static function is_crypto_request( $method, $destination = '', $tx_hash = '' ) {
        $method = strtolower( (string) $method );
        if ( in_array( $method, array( 'crypto', 'usdt', 'bep20', 'bep-20' ), true ) ) {
            return true;
        }
        return $destination !== '' || $tx_hash !== '';
    }

    private static function encode_transfer( $to, $wei_dec ) {
        $to_pad  = str_pad( strtolower( preg_replace( '/^0x/i', '', $to ) ), 64, '0', STR_PAD_LEFT );
        $amt_hex = str_pad( SCP_Eth_Math::dec2hex( $wei_dec ), 64, '0', STR_PAD_LEFT );
        return '0xa9059cbb' . $to_pad . $amt_hex;
    }

    private static function token_balance( $address ) {
        $s    = self::settings();
        $data = '0x70a08231' . str_pad( strtolower( preg_replace( '/^0x/i', '', $address ) ), 64, '0', STR_PAD_LEFT );
        $call = self::rpc( 'eth_call', array(
            array(
                'to'   => $s['contract'],
                'data' => $data,
            ),
            'latest',
        ) );
        if ( empty( $call['success'] ) ) {
            return $call;
        }
        $raw = SCP_Eth_Math::hex2dec( $call['result'] );
        return array(
            'success' => true,
            'raw'     => $raw,
        );
    }

    private static function find_usdt_transfer( $logs, $contract, $operator ) {
        if ( ! is_array( $logs ) ) {
            return null;
        }
        $contract = strtolower( $contract );
        $operator = strtolower( $operator );
        foreach ( $logs as $log ) {
            if ( ! is_array( $log ) ) {
                continue;
            }
            $log_addr = strtolower( $log['address'] ?? '' );
            if ( $log_addr !== $contract ) {
                continue;
            }
            $topics = $log['topics'] ?? array();
            if ( empty( $topics[0] ) || strtolower( $topics[0] ) !== self::TRANSFER_TOPIC ) {
                continue;
            }
            $from = '0x' . substr( strtolower( $topics[1] ?? '' ), -40 );
            $to   = '0x' . substr( strtolower( $topics[2] ?? '' ), -40 );
            if ( $to !== $operator ) {
                continue;
            }
            $amount_hex = $log['data'] ?? '0x0';
            return array(
                'from'   => $from,
                'to'     => $to,
                'amount' => SCP_Eth_Math::hex2dec( $amount_hex ),
            );
        }
        return null;
    }

    private static function wait_for_receipt( $tx_hash, $tries = 20, $sleep_us = 1500000 ) {
        for ( $i = 0; $i < $tries; $i++ ) {
            $receipt = self::rpc( 'eth_getTransactionReceipt', array( $tx_hash ) );
            if ( empty( $receipt['success'] ) ) {
                return $receipt;
            }
            if ( is_array( $receipt['result'] ) ) {
                $status = strtolower( (string) ( $receipt['result']['status'] ?? '' ) );
                if ( $status !== '0x1' && $status !== '1' ) {
                    return self::fail( 'BEP-20 transfer failed on chain.' );
                }
                return array( 'success' => true, 'result' => $receipt['result'] );
            }
            usleep( $sleep_us );
        }
        return self::fail( 'Timed out waiting for the BEP-20 transfer to confirm.' );
    }

    private static function rpc( $method, $params = array() ) {
        $s        = self::settings();
        $response = wp_remote_post(
            $s['rpc_url'],
            array(
                'timeout' => 30,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode(
                    array(
                        'jsonrpc' => '2.0',
                        'id'      => 1,
                        'method'  => $method,
                        'params'  => $params,
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return self::fail( 'BSC RPC error: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return self::fail( 'Invalid response from BSC RPC.' );
        }
        if ( ! empty( $body['error']['message'] ) ) {
            return self::fail( 'BSC RPC: ' . $body['error']['message'] );
        }
        if ( ! array_key_exists( 'result', $body ) ) {
            return self::fail( 'BSC RPC returned no result for ' . $method . '.' );
        }

        return array(
            'success' => true,
            'result'  => $body['result'],
        );
    }

    public static function to_token_units( $amount, $decimals = 18 ) {
        $amount = trim( (string) $amount );
        if ( $amount === '' || ! is_numeric( $amount ) ) {
            return '0';
        }
        $neg = strpos( $amount, '-' ) === 0;
        if ( $neg ) {
            return '0';
        }
        if ( strpos( $amount, '.' ) === false ) {
            $frac = str_repeat( '0', $decimals );
            $int  = $amount;
        } else {
            list( $int, $frac ) = explode( '.', $amount, 2 );
            $frac = substr( str_pad( preg_replace( '/\D/', '', $frac ), $decimals, '0' ), 0, $decimals );
        }
        $int = preg_replace( '/\D/', '', $int );
        $raw = ltrim( $int . $frac, '0' );
        return $raw === '' ? '0' : $raw;
    }

    public static function from_token_units( $raw, $decimals = 18 ) {
        $raw = preg_replace( '/\D/', '', (string) $raw );
        if ( $raw === '' ) {
            $raw = '0';
        }
        if ( strlen( $raw ) <= $decimals ) {
            $raw = str_pad( $raw, $decimals + 1, '0', STR_PAD_LEFT );
        }
        $int  = substr( $raw, 0, strlen( $raw ) - $decimals );
        $frac = rtrim( substr( $raw, -$decimals ), '0' );
        return $frac === '' ? $int : ( $int . '.' . $frac );
    }

    private static function fail( $message ) {
        return array(
            'success' => false,
            'message' => $message,
        );
    }
}

function scp_bep20_get_private_key() {
    if ( defined( 'SCP_BEP20_PRIVATE_KEY' ) && SCP_BEP20_PRIVATE_KEY ) {
        $from_const = scp_bep20_sanitize_private_key( SCP_BEP20_PRIVATE_KEY );
        if ( $from_const !== '' ) {
            return $from_const;
        }
    }

    $from_option = scp_bep20_decrypt_key( get_option( 'scp_bep20_private_key', '' ) );
    if ( $from_option !== '' ) {
        return $from_option;
    }

    $dest = function_exists( 'scp_get_deposit_destinations' ) ? scp_get_deposit_destinations() : array();
    return scp_bep20_decrypt_key( $dest['crypto']['USDT']['private_key'] ?? '' );
}

function scp_bep20_encrypt_key( $plain ) {
    $plain = scp_bep20_sanitize_private_key( $plain );
    if ( $plain === '' ) {
        return '';
    }
    $key = hash( 'sha256', wp_salt( 'auth' ) . '|scp-bep20', true );
    $iv  = random_bytes( 16 );
    $raw = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
    if ( $raw === false ) {
        return '';
    }
    return base64_encode( $iv . $raw );
}

function scp_bep20_decrypt_key( $stored ) {
    $stored = (string) $stored;
    if ( $stored === '' ) {
        return '';
    }
    $direct = scp_bep20_sanitize_private_key( $stored );
    if ( $direct !== '' ) {
        return $direct;
    }
    $bin = base64_decode( $stored, true );
    if ( $bin === false || strlen( $bin ) < 17 ) {
        return '';
    }
    $iv    = substr( $bin, 0, 16 );
    $raw   = substr( $bin, 16 );
    $key   = hash( 'sha256', wp_salt( 'auth' ) . '|scp-bep20', true );
    $plain = openssl_decrypt( $raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
    if ( $plain === false ) {
        return '';
    }
    return scp_bep20_sanitize_private_key( $plain );
}

function scp_bep20_sanitize_private_key( $value ) {
    $value = strtolower( preg_replace( '/^0x/i', '', preg_replace( '/\s+/', '', (string) $value ) ) );
    return preg_match( '/^[0-9a-f]{64}$/', $value ) ? $value : '';
}

function scp_sanitize_bep20_private_key_option( $value ) {
    $incoming = scp_bep20_sanitize_private_key( $value );
    if ( $incoming !== '' ) {
        return scp_bep20_encrypt_key( $incoming );
    }

    return get_option( 'scp_bep20_private_key', '' );
}

function scp_bep20_has_private_key() {
    return scp_bep20_get_private_key() !== '';
}
