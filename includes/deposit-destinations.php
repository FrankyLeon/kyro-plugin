<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', 'scp_register_deposit_destinations_setting' );
add_action( 'rest_api_init', 'scp_register_deposit_destinations_route' );

function scp_register_deposit_destinations_setting() {
    register_setting(
        'scp_deposit_destinations_group',
        'scp_deposit_destinations',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'scp_sanitize_deposit_destinations',
            'default'           => array(),
        )
    );
}

function scp_register_deposit_destinations_route() {
    register_rest_route(
        'scp/v1',
        '/wallet/deposit-destinations',
        array(
            'methods'             => 'GET',
            'callback'            => 'scp_rest_wallet_deposit_destinations',
            'permission_callback' => 'scp_rest_permission_logged_in',
        )
    );

    register_rest_route(
        'scp/v1',
        '/wallet/withdraw-destinations',
        array(
            'methods'             => 'GET',
            'callback'            => 'scp_rest_wallet_withdraw_destinations',
            'permission_callback' => 'scp_rest_permission_logged_in',
        )
    );
}

function scp_default_deposit_destinations() {
    $bank_instructions = implode(
        "\n",
        array(
            'Deposit only from one account registered to this player.',
            'Transfers from a different account will not be eligible for withdrawal.',
            'Include your player name (username) in the transfer description.',
        )
    );

    return array(
        'bank'   => array(
            'enabled'        => 1,
            'label'          => 'Khan Bank',
            'bank_name'      => 'Khan Bank',
            'account_number' => '',
            'account_holder' => '',
            'instructions'   => $bank_instructions,
        ),
        'crypto' => array(
            'enabled' => 1,
            'label'   => 'USDT BEP20',
            'USDT'    => array(
                'label'             => 'USDT.BEP20',
                'standard'          => 'BEP-20',
                'network'           => 'BNB Smart Chain',
                'address'           => '',
                'mode'              => 'live',
                'rpc_url'           => 'https://bsc-dataseed.binance.org',
                'contract'          => '0x55d398326f99059fF775485246999027B3197955',
                'decimals'          => 18,
                'min_confirmations' => 3,
                'private_key'      => '',
            ),
            'USDC'    => array(
                'label'    => 'USDC.ERC20',
                'standard' => 'ERC-20',
                'network'  => 'Ethereum',
                'address'  => '',
            ),
            'ETH'     => array(
                'label'    => 'ETH',
                'standard' => 'ERC-20',
                'network'  => 'Ethereum',
                'address'  => '',
            ),
            'BTC'     => array(
                'label'    => 'BTC',
                'standard' => 'Bitcoin',
                'network'  => 'Bitcoin',
                'address'  => '',
            ),
        ),
        'paypal' => array(
            'enabled'      => 1,
            'label'        => 'PayPal',
            'email'        => '',
            'instructions' => 'Send the deposit to this PayPal account, then confirm in the wallet.',
        ),
        'card'   => array(
            'enabled'      => 1,
            'label'        => 'Credit / Debit',
            'instructions' => 'Card payments are processed with Stripe. Configure publishable and secret keys under ScorpioPlay → Settings.',
        ),
    );
}

function scp_get_deposit_destinations() {
    $saved = get_option( 'scp_deposit_destinations', array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    return scp_merge_deposit_destinations( $saved );
}

function scp_merge_deposit_destinations( $saved ) {
    $defaults = scp_default_deposit_destinations();
    $coins    = array( 'USDT', 'USDC', 'ETH', 'BTC' );

    foreach ( array( 'bank', 'paypal', 'card' ) as $method ) {
        if ( isset( $saved[ $method ] ) && is_array( $saved[ $method ] ) ) {
            $defaults[ $method ] = array_merge( $defaults[ $method ], $saved[ $method ] );
        }
    }

    if ( isset( $saved['crypto'] ) && is_array( $saved['crypto'] ) ) {
        foreach ( array( 'enabled', 'label' ) as $key ) {
            if ( array_key_exists( $key, $saved['crypto'] ) ) {
                $defaults['crypto'][ $key ] = $saved['crypto'][ $key ];
            }
        }
        foreach ( $coins as $coin ) {
            if ( isset( $saved['crypto'][ $coin ] ) && is_array( $saved['crypto'][ $coin ] ) ) {
                $defaults['crypto'][ $coin ] = array_merge(
                    $defaults['crypto'][ $coin ],
                    $saved['crypto'][ $coin ]
                );
            }
        }
    }

    return $defaults;
}

function scp_sanitize_deposit_destinations( $input ) {
    $defaults = scp_default_deposit_destinations();
    if ( ! is_array( $input ) ) {
        $input = array();
    }

    $out = $defaults;

    foreach ( array( 'bank', 'crypto', 'paypal', 'card' ) as $method ) {
        $out[ $method ]['enabled'] = ! empty( $input[ $method ]['enabled'] ) ? 1 : 0;
        if ( isset( $input[ $method ]['label'] ) ) {
            $out[ $method ]['label'] = sanitize_text_field( $input[ $method ]['label'] );
        }
    }

    $out['bank']['bank_name']      = sanitize_text_field( $input['bank']['bank_name'] ?? '' );
    $out['bank']['account_number'] = sanitize_text_field( $input['bank']['account_number'] ?? '' );
    $out['bank']['account_holder'] = sanitize_text_field( $input['bank']['account_holder'] ?? '' );
    $out['bank']['instructions']   = sanitize_textarea_field( $input['bank']['instructions'] ?? '' );

    foreach ( array( 'USDT', 'USDC', 'ETH', 'BTC' ) as $coin ) {
        $row = isset( $input['crypto'][ $coin ] ) && is_array( $input['crypto'][ $coin ] )
            ? $input['crypto'][ $coin ]
            : array();
        $out['crypto'][ $coin ]['label']    = sanitize_text_field( $row['label'] ?? $defaults['crypto'][ $coin ]['label'] );
        $out['crypto'][ $coin ]['standard'] = sanitize_text_field( $row['standard'] ?? $defaults['crypto'][ $coin ]['standard'] );
        $out['crypto'][ $coin ]['network']  = sanitize_text_field( $row['network'] ?? $defaults['crypto'][ $coin ]['network'] );
        $out['crypto'][ $coin ]['address']  = sanitize_text_field( $row['address'] ?? '' );
    }

    $usdt = isset( $input['crypto']['USDT'] ) && is_array( $input['crypto']['USDT'] ) ? $input['crypto']['USDT'] : array();
    $out['crypto']['USDT']['mode'] = 'live';

    $out['crypto']['USDT']['rpc_url'] = esc_url_raw( $usdt['rpc_url'] ?? $defaults['crypto']['USDT']['rpc_url'] );
    if ( $out['crypto']['USDT']['rpc_url'] === '' ) {
        $out['crypto']['USDT']['rpc_url'] = $defaults['crypto']['USDT']['rpc_url'];
    }
    $out['crypto']['USDT']['contract'] = sanitize_text_field( $usdt['contract'] ?? $defaults['crypto']['USDT']['contract'] );
    $out['crypto']['USDT']['decimals']  = max( 0, absint( $usdt['decimals'] ?? $defaults['crypto']['USDT']['decimals'] ) );
    $out['crypto']['USDT']['min_confirmations'] = max( 0, absint( $usdt['min_confirmations'] ?? $defaults['crypto']['USDT']['min_confirmations'] ) );

    $saved       = get_option( 'scp_deposit_destinations', array() );
    $existing_pk = '';
    if ( is_array( $saved ) && ! empty( $saved['crypto']['USDT']['private_key'] ) ) {
        $existing_pk = $saved['crypto']['USDT']['private_key'];
    }
    $incoming_pk = function_exists( 'scp_bep20_sanitize_private_key' )
        ? scp_bep20_sanitize_private_key( $usdt['private_key'] ?? '' )
        : '';
    if ( $incoming_pk !== '' ) {
        $encrypted = scp_bep20_encrypt_key( $incoming_pk );
        $out['crypto']['USDT']['private_key'] = $encrypted;
        update_option( 'scp_bep20_private_key', $encrypted, false );
    } else {
        $out['crypto']['USDT']['private_key'] = $existing_pk;
    }

    $out['paypal']['email']        = sanitize_email( $input['paypal']['email'] ?? '' );
    $out['paypal']['instructions'] = sanitize_textarea_field( $input['paypal']['instructions'] ?? '' );
    $out['card']['instructions']   = sanitize_textarea_field( $input['card']['instructions'] ?? '' );

    return $out;
}

function scp_split_instruction_lines( $text ) {
    $lines = preg_split( '/\r\n|\r|\n/', (string) $text );
    if ( ! is_array( $lines ) ) {
        return array();
    }

    $lines = array_map( 'trim', $lines );
    return array_values( array_filter( $lines, 'strlen' ) );
}

function scp_format_deposit_destinations_payload() {
    $d = scp_get_deposit_destinations();

    $methods = array();
    foreach ( array( 'bank', 'crypto', 'card', 'paypal' ) as $id ) {
        $methods[] = array(
            'id'      => $id,
            'label'   => (string) ( $d[ $id ]['label'] ?? $id ),
            'enabled' => ! empty( $d[ $id ]['enabled'] ),
        );
    }

    $wallet = class_exists( 'SCP_BEP20_Wallet' ) ? SCP_BEP20_Wallet::settings() : array();

    $networks = array();
    foreach ( array( 'USDT', 'USDC', 'ETH', 'BTC' ) as $coin ) {
        $row     = $d['crypto'][ $coin ];
        $address = (string) ( $row['address'] ?? '' );
        if ( $coin === 'USDT' && ! empty( $wallet['address'] ) ) {
            $address = $wallet['address'];
        }
        $networks[] = array(
            'id'       => $coin,
            'label'    => (string) ( $row['label'] ?? $coin ),
            'standard' => (string) ( $row['standard'] ?? '' ),
            'network'  => (string) ( $row['network'] ?? '' ),
            'address'  => $address,
            'contract' => $coin === 'USDT' ? (string) ( $wallet['contract'] ?? '' ) : '',
            'chainId'  => $coin === 'USDT' ? (int) ( $wallet['chain_id'] ?? 56 ) : 0,
            'decimals' => $coin === 'USDT' ? (int) ( $wallet['decimals'] ?? 18 ) : 0,
        );
    }

    return array(
        'methods' => $methods,
        'bank'    => array(
            'bankName'      => (string) ( $d['bank']['bank_name'] ?? '' ),
            'accountNumber' => (string) ( $d['bank']['account_number'] ?? '' ),
            'accountHolder' => (string) ( $d['bank']['account_holder'] ?? '' ),
            'instructions'  => scp_split_instruction_lines( $d['bank']['instructions'] ?? '' ),
        ),
        'crypto'  => array(
            'networks'              => $networks,
            'contract'              => (string) ( $wallet['contract'] ?? '' ),
            'chainId'               => (int) ( $wallet['chain_id'] ?? 56 ),
            'decimals'              => (int) ( $wallet['decimals'] ?? 18 ),
            'payoutEnabled'         => class_exists( 'SCP_BEP20_Wallet' ) ? SCP_BEP20_Wallet::is_ready_to_send() : false,
            'depositVerifyEnabled' => class_exists( 'SCP_BEP20_Wallet' ) ? SCP_BEP20_Wallet::is_ready_to_verify() : false,
            'mode'                 => class_exists( 'SCP_BEP20_Wallet' ) ? SCP_BEP20_Wallet::mode() : 'live',
            'simulated'            => class_exists( 'SCP_BEP20_Wallet' ) && SCP_BEP20_Wallet::is_simulate(),
        ),
        'paypal'  => array(
            'email'        => (string) ( $d['paypal']['email'] ?? '' ),
            'instructions' => scp_split_instruction_lines( $d['paypal']['instructions'] ?? '' ),
        ),
        'card'    => array(
            'instructions' => scp_split_instruction_lines( $d['card']['instructions'] ?? '' ),
        ),
    );
}

function scp_rest_wallet_deposit_destinations() {
    return rest_ensure_response(
        array(
            'success' => true,
            'data'    => scp_format_deposit_destinations_payload(),
        )
    );
}

function scp_default_withdraw_banks() {
    return array(
        array( 'id' => 'khan', 'label' => 'Khan Bank' ),
        array( 'id' => 'golomt', 'label' => 'Golomt Bank' ),
        array( 'id' => 'tdb', 'label' => 'Trade and Development Bank' ),
        array( 'id' => 'xac', 'label' => 'XacBank' ),
        array( 'id' => 'state', 'label' => 'State Bank' ),
    );
}

function scp_format_withdraw_destinations_payload() {
    $d = scp_get_deposit_destinations();

    $crypto_label = (string) ( $d['crypto']['label'] ?? 'USDT BEP20' );
    $methods      = array(
        array(
            'id'      => 'bank',
            'label'   => (string) ( $d['bank']['label'] ?? 'Bank transfer' ),
            'enabled' => ! empty( $d['bank']['enabled'] ),
        ),
        array(
            'id'      => 'crypto',
            'label'   => $crypto_label !== '' ? $crypto_label : 'USDT BEP20',
            'enabled' => ! empty( $d['crypto']['enabled'] ),
        ),
        array(
            'id'      => 'paypal',
            'label'   => (string) ( $d['paypal']['label'] ?? 'PayPal' ),
            'enabled' => ! empty( $d['paypal']['enabled'] ),
        ),
    );

    $banks      = scp_default_withdraw_banks();
    $bank_name  = trim( (string) ( $d['bank']['bank_name'] ?? '' ) );
    if ( $bank_name !== '' ) {
        $matched = false;
        foreach ( $banks as $i => $bank ) {
            if ( strcasecmp( $bank['label'], $bank_name ) === 0 || strcasecmp( $bank['id'], $bank_name ) === 0 ) {
                $banks[ $i ]['label'] = $bank_name;
                $matched = true;
                break;
            }
        }
        if ( ! $matched ) {
            $banks[0]['label'] = $bank_name;
        }
    }

    $currencies = array( 'USD' );
    if ( ! empty( $d['bank']['enabled'] ) ) {
        $currencies[] = 'MNT';
    }
    if ( ! empty( $d['crypto']['enabled'] ) ) {
        $currencies[] = 'USDT';
    }

    return array(
        'methods'    => $methods,
        'banks'      => $banks,
        'currencies' => array_values( array_unique( $currencies ) ),
    );
}

function scp_rest_wallet_withdraw_destinations() {
    return rest_ensure_response(
        array(
            'success' => true,
            'data'    => scp_format_withdraw_destinations_payload(),
        )
    );
}

function scp_render_deposit_destinations_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $d     = scp_get_deposit_destinations();
    $name  = 'scp_deposit_destinations';
    $coins = array( 'USDT', 'USDC', 'ETH', 'BTC' );
    $payout_ready = class_exists( 'SCP_BEP20_Wallet' ) && SCP_BEP20_Wallet::is_ready_to_send();
    ?>
    <div class="wrap">
        <h1>Deposit Destinations</h1>
        <?php if ( ! $payout_ready ) : ?>
            <div class="notice notice-error">
                <p>
                    <strong>Crypto withdrawals are blocked.</strong>
                    The USDT address above is only for receiving deposits.
                    Paste the matching BEP-20 private key in the hot wallet section below, then save.
                </p>
            </div>
        <?php endif; ?>
        <p>
            These are the operator receiving details shown in the wallet deposit menu.
            Card checkout still uses Stripe keys from
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=scorpioplay' ) ); ?>">ScorpioPlay → Settings</a>.
        </p>

        <form method="post" action="options.php">
            <?php settings_fields( 'scp_deposit_destinations_group' ); ?>

            <h2>Bank transfer</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Enable</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[bank][enabled]" value="1" <?php checked( ! empty( $d['bank']['enabled'] ) ); ?> />
                            Show bank transfer in the deposit menu
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-bank-label">Menu label</label></th>
                    <td><input id="scp-bank-label" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[bank][label]" value="<?php echo esc_attr( $d['bank']['label'] ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-bank-name">Bank name</label></th>
                    <td><input id="scp-bank-name" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[bank][bank_name]" value="<?php echo esc_attr( $d['bank']['bank_name'] ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-bank-number">Account number</label></th>
                    <td><input id="scp-bank-number" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[bank][account_number]" value="<?php echo esc_attr( $d['bank']['account_number'] ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-bank-holder">Account holder</label></th>
                    <td><input id="scp-bank-holder" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[bank][account_holder]" value="<?php echo esc_attr( $d['bank']['account_holder'] ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-bank-instructions">Instructions</label></th>
                    <td>
                        <textarea id="scp-bank-instructions" class="large-text" rows="4" name="<?php echo esc_attr( $name ); ?>[bank][instructions]"><?php echo esc_textarea( $d['bank']['instructions'] ); ?></textarea>
                        <p class="description">One instruction per line. Shown above the copyable account number.</p>
                    </td>
                </tr>
            </table>

            <h2>Crypto wallets</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Enable</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[crypto][enabled]" value="1" <?php checked( ! empty( $d['crypto']['enabled'] ) ); ?> />
                            Show crypto in the deposit menu
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-crypto-label">Menu label</label></th>
                    <td><input id="scp-crypto-label" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[crypto][label]" value="<?php echo esc_attr( $d['crypto']['label'] ); ?>" /></td>
                </tr>
            </table>
            <table class="widefat striped" style="max-width: 960px;">
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Display label</th>
                        <th>Standard</th>
                        <th>Network</th>
                        <th>Wallet address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $coins as $coin ) : ?>
                        <?php $row = $d['crypto'][ $coin ]; ?>
                        <tr>
                            <td><strong><?php echo esc_html( $coin ); ?></strong></td>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[crypto][<?php echo esc_attr( $coin ); ?>][label]" value="<?php echo esc_attr( $row['label'] ); ?>" /></td>
                            <td><input type="text" class="small-text" name="<?php echo esc_attr( $name ); ?>[crypto][<?php echo esc_attr( $coin ); ?>][standard]" value="<?php echo esc_attr( $row['standard'] ); ?>" /></td>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[crypto][<?php echo esc_attr( $coin ); ?>][network]" value="<?php echo esc_attr( $row['network'] ); ?>" /></td>
                            <td><input type="text" class="large-text" name="<?php echo esc_attr( $name ); ?>[crypto][<?php echo esc_attr( $coin ); ?>][address]" value="<?php echo esc_attr( $row['address'] ); ?>" placeholder="0x… or bc1…" /></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $usdt_row = $d['crypto']['USDT'];
            $has_pk   = function_exists( 'scp_bep20_has_private_key' ) && scp_bep20_has_private_key();
            ?>
            <h2>BEP-20 hot wallet (USDT payouts)</h2>
            <p class="description" style="max-width: 960px;">
                The wallet address in the USDT row is public (players send to it).
                Withdrawals also need that wallet’s <strong>private key</strong> so the plugin can send USDT to the player.
                Keep a little BNB in the wallet for gas.
            </p>
            <?php if ( $has_pk ) : ?>
                <div class="notice notice-success inline"><p>Private key is saved. Crypto withdrawals can send on-chain.</p></div>
            <?php endif; ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="scp-usdt-rpc">BSC RPC URL</label></th>
                    <td>
                        <input id="scp-usdt-rpc" type="url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[crypto][USDT][rpc_url]" value="<?php echo esc_attr( $usdt_row['rpc_url'] ?? 'https://bsc-dataseed.binance.org' ); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-usdt-contract">USDT contract</label></th>
                    <td>
                        <input id="scp-usdt-contract" type="text" class="regular-text" value="0x55d398326f99059fF775485246999027B3197955" readonly />
                        <p class="description">Official Binance-Peg USDT on BNB Smart Chain. BscScan may label it BSC-USD / BUSD-T; the contract is still USDT.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-usdt-conf">Min confirmations</label></th>
                    <td>
                        <input id="scp-usdt-conf" type="number" class="small-text" min="0" name="<?php echo esc_attr( $name ); ?>[crypto][USDT][min_confirmations]" value="<?php echo esc_attr( (int) ( $usdt_row['min_confirmations'] ?? 3 ) ); ?>" />
                        <p class="description">Required BSC confirmations before a crypto deposit is credited.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-usdt-pk">Private key</label></th>
                    <td>
                        <input id="scp-usdt-pk" type="password" class="regular-text" name="<?php echo esc_attr( $name ); ?>[crypto][USDT][private_key]" value="" autocomplete="new-password" placeholder="<?php echo $has_pk ? 'Saved — leave blank to keep' : '0x… 64 hex chars'; ?>" />
                        <p class="description">
                            <?php echo $has_pk ? 'A private key is saved (encrypted). Leave blank to keep it.' : 'Required to send withdrawals. Never share this key.'; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2>PayPal</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Enable</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[paypal][enabled]" value="1" <?php checked( ! empty( $d['paypal']['enabled'] ) ); ?> />
                            Show PayPal in the deposit menu
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-paypal-label">Menu label</label></th>
                    <td><input id="scp-paypal-label" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[paypal][label]" value="<?php echo esc_attr( $d['paypal']['label'] ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-paypal-email">Receiving email</label></th>
                    <td>
                        <input id="scp-paypal-email" type="email" class="regular-text" name="<?php echo esc_attr( $name ); ?>[paypal][email]" value="<?php echo esc_attr( $d['paypal']['email'] ); ?>" />
                        <p class="description">Operator PayPal account players send funds to.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-paypal-instructions">Instructions</label></th>
                    <td><textarea id="scp-paypal-instructions" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[paypal][instructions]"><?php echo esc_textarea( $d['paypal']['instructions'] ); ?></textarea></td>
                </tr>
            </table>

            <h2>Card</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Enable</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[card][enabled]" value="1" <?php checked( ! empty( $d['card']['enabled'] ) ); ?> />
                            Show card in the deposit menu
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-card-label">Menu label</label></th>
                    <td><input id="scp-card-label" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[card][label]" value="<?php echo esc_attr( $d['card']['label'] ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="scp-card-instructions">Player instructions</label></th>
                    <td>
                        <textarea id="scp-card-instructions" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[card][instructions]"><?php echo esc_textarea( $d['card']['instructions'] ); ?></textarea>
                        <p class="description">Do not put secret Stripe keys here. Keys stay on the Settings page.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save destinations' ); ?>
        </form>
    </div>
    <?php
}

add_action( 'wp_ajax_scp_wallet_deposit_destinations', function() {
    $request = new WP_REST_Request( 'GET', '/scp/v1/wallet/deposit-destinations' );
    scp_api_ajax_response( function() use ( $request ) {
        return scp_rest_wallet_deposit_destinations( $request );
    } );
} );

add_action( 'wp_ajax_scp_wallet_withdraw_destinations', function() {
    $request = new WP_REST_Request( 'GET', '/scp/v1/wallet/withdraw-destinations' );
    scp_api_ajax_response( function() use ( $request ) {
        return scp_rest_wallet_withdraw_destinations( $request );
    } );
} );
