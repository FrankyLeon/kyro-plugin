<?php
add_shortcode( 'scp_wallet', 'scp_wallet_shortcode' );

function scp_wallet_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="' . wp_login_url() . '">log in</a> to view your wallet.</p>';
    }
    $user_id = get_current_user_id();
    $token   = get_user_meta( $user_id, 'scp_token', true );
    $player_id = get_user_meta( $user_id, 'scp_player_id', true );

    // Enqueue Stripe.js if you're using Stripe (only on this page)
    wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );

    ob_start();
    ?>
    <div id="scp-wallet">
        <h2>Wallet</h2>
        <p>Balance: <span id="scp-balance">Loading...</span></p>
        <button id="scp-refresh-balance">Refresh</button>

        <hr>
        <h3>Deposit</h3>
        <form id="scp-deposit-form">
            <input type="number" id="deposit-amount" placeholder="Amount (USD)" step="0.01" min="1" required>
            <button type="submit">Deposit with Stripe</button>
        </form>
        <div id="stripe-payment-element"></div> <!-- For Stripe Elements -->

        <hr>
        <h3>Withdrawal</h3>
        <form id="scp-withdraw-form">
            <input type="number" id="withdraw-amount" placeholder="Amount (USD)" step="0.01" min="1" required>
            <textarea id="withdraw-details" placeholder="Bank / Crypto details"></textarea>
            <button type="submit">Request Withdrawal</button>
        </form>
        <div id="scp-messages"></div>
    </div>

    <script>
    const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
    const stripe = Stripe('<?php echo esc_js( get_option('scp_stripe_publishable_key', '') ); ?>');
    let stripeElements, stripePaymentElement;

    function updateBalance() {
        jQuery.post(ajaxurl, { action: 'scp_get_balance' }, function(res) {
            if ( res.success ) {
                jQuery('#scp-balance').text( res.data.balance + ' ' + (res.data.currency || 'USD') );
            } else {
                jQuery('#scp-balance').text( 'Error' );
            }
        });
    }
    updateBalance();

    jQuery('#scp-refresh-balance').on('click', updateBalance);

    // Deposit
    jQuery('#scp-deposit-form').on('submit', function(e) {
        e.preventDefault();
        const amount = jQuery('#deposit-amount').val();
        jQuery.post(ajaxurl, { action: 'scp_init_deposit', amount }, function(res) {
            if ( res.success ) {
                // For Stripe, we need to confirm the payment
                stripe.confirmCardPayment(res.data.client_secret).then(function(result) {
                    if (result.error) {
                        jQuery('#scp-messages').html('<p class="error">'+result.error.message+'</p>');
                    } else {
                        // Payment succeeded, call finalize deposit
                        jQuery.post(ajaxurl, { action: 'scp_finalize_deposit', payment_intent_id: result.paymentIntent.id }, function(finalRes) {
                            if ( finalRes.success ) {
                                jQuery('#scp-messages').html('<p class="success">Deposit successful. Balance updated.</p>');
                                updateBalance();
                            } else {
                                jQuery('#scp-messages').html('<p class="error">Deposit failed: '+finalRes.data.message+'</p>');
                            }
                        });
                    }
                });
            } else {
                jQuery('#scp-messages').html('<p class="error">'+res.data.message+'</p>');
            }
        });
    });

    // Withdrawal
    jQuery('#scp-withdraw-form').on('submit', function(e) {
        e.preventDefault();
        const amount = jQuery('#withdraw-amount').val();
        const details = jQuery('#withdraw-details').val();
        jQuery.post(ajaxurl, { action: 'scp_request_withdrawal', amount, details }, function(res) {
            if ( res.success ) {
                jQuery('#scp-messages').html('<p class="success">Withdrawal request submitted. Awaiting approval.</p>');
            } else {
                jQuery('#scp-messages').html('<p class="error">'+res.data.message+'</p>');
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

add_action( 'wp_ajax_scp_init_deposit', 'scp_ajax_init_deposit' );
function scp_ajax_init_deposit() {
    if ( ! is_user_logged_in() ) wp_send_json_error(['message'=>'Login required']);
    $amount = floatval( $_POST['amount'] ?? 0 );
    if ( $amount <= 0 ) wp_send_json_error(['message'=>'Invalid amount']);

    // Create a Stripe PaymentIntent
    \Stripe\Stripe::setApiKey( get_option( 'scp_stripe_secret_key', '' ) );
    try {
        $intent = \Stripe\PaymentIntent::create([
            'amount'   => $amount * 100, // cents
            'currency' => 'usd',
            'metadata' => [
                'user_id' => get_current_user_id(),
            ],
        ]);
        wp_send_json_success( [ 'client_secret' => $intent->client_secret ] );
    } catch ( \Exception $e ) {
        wp_send_json_error( [ 'message' => $e->getMessage() ] );
    }
}

add_action( 'wp_ajax_scp_finalize_deposit', 'scp_ajax_finalize_deposit' );
function scp_ajax_finalize_deposit() {
    if ( ! is_user_logged_in() ) wp_send_json_error(['message'=>'Login required']);
    $payment_intent_id = sanitize_text_field( $_POST['payment_intent_id'] ?? '' );
    if ( empty( $payment_intent_id ) ) wp_send_json_error(['message'=>'No payment ID']);

    \Stripe\Stripe::setApiKey( get_option( 'scp_stripe_secret_key', '' ) );
    try {
        $intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
        if ( $intent->status !== 'succeeded' ) wp_send_json_error(['message'=>'Payment not confirmed']);

        $user_id    = get_current_user_id();
        $amount     = $intent->amount / 100;
        $player_id  = wp_get_current_user()->user_login;
        $tx_id      = 'wp-dep-' . uniqid();

        $api = new SCP_API_Client();
        $result = $api->deposit( $player_id, $amount, 'USD', $tx_id );
        if ( ! $result['success'] ) {
            error_log( 'ScorpioPlay deposit failed: ' . json_encode( $result ) );
            wp_send_json_error( [ 'message' => 'Deposit to game wallet failed. Support will contact you.' ] );
        }

        if ( ! empty( $result['data']['transaction_id'] ) ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'scp_transactions',
                [ 'scp_txn_id' => $result['data']['transaction_id'] ],
                [ 'txn_id' => $tx_id ]
            );
        }

        // Clear balance cache
        delete_transient( 'scp_balance_' . $user_id );
        wp_send_json_success( [ 'message' => 'Deposit successful' ] );
    } catch ( \Exception $e ) {
        wp_send_json_error( [ 'message' => $e->getMessage() ] );
    }
}

add_action( 'wp_ajax_scp_request_withdrawal', 'scp_ajax_request_withdrawal' );
function scp_ajax_request_withdrawal() {
    if ( ! is_user_logged_in() ) wp_send_json_error(['message'=>'Login required']);
    $amount  = floatval( $_POST['amount'] ?? 0 );
    $details = sanitize_textarea_field( $_POST['details'] ?? '' );
    if ( $amount <= 0 ) wp_send_json_error(['message'=>'Invalid amount']);

    $user_id    = get_current_user_id();
    $player_id  = get_user_meta( $user_id, 'scp_player_id', true );
    $player_login = wp_get_current_user()->user_login ?: $player_id;

    $tx_id = scp_add_transaction( $user_id, $player_login, 'withdrawal', $amount, 'USD', 'pending', '', '', null );

    wp_send_json_success( [ 'message' => 'Request submitted', 'transaction_id' => $tx_id ] );
}