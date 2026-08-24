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
                'label'    => 'USDT.BEP20',
                'standard' => 'BEP-20',
                'network'  => 'BNB Smart Chain',
                'address'  => '',
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

    $networks = array();
    foreach ( array( 'USDT', 'USDC', 'ETH', 'BTC' ) as $coin ) {
        $row        = $d['crypto'][ $coin ];
        $networks[] = array(
            'id'       => $coin,
            'label'    => (string) ( $row['label'] ?? $coin ),
            'standard' => (string) ( $row['standard'] ?? '' ),
            'network'  => (string) ( $row['network'] ?? '' ),
            'address'  => (string) ( $row['address'] ?? '' ),
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
            'networks' => $networks,
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

function scp_render_deposit_destinations_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $d     = scp_get_deposit_destinations();
    $name  = 'scp_deposit_destinations';
    $coins = array( 'USDT', 'USDC', 'ETH', 'BTC' );
    ?>
    <div class="wrap">
        <h1>Deposit Destinations</h1>
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
