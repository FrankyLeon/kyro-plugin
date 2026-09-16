<?php
/**
 * Plugin Name: ScorpioPlay Core
 * Description: Integrates WordPress with ScorpioPlay API for game platform.
 * Version: 1.0.0
 * Author: Candy
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SCP_PLUGIN_DIR . 'includes/env.php';
scp_load_dotenv();

// Autoload classes (optional, we'll include manually for now)
require_once SCP_PLUGIN_DIR . 'includes/class-scp-api-client.php';
require_once SCP_PLUGIN_DIR . 'includes/admin-settings.php';
require_once SCP_PLUGIN_DIR . 'includes/user-sync.php';

require_once SCP_PLUGIN_DIR . 'includes/games.php';
require_once SCP_PLUGIN_DIR . 'includes/wallet.php';

require_once SCP_PLUGIN_DIR . 'includes/transactions.php';
require_once SCP_PLUGIN_DIR . 'includes/api-endpoints.php';
require_once SCP_PLUGIN_DIR . 'includes/deposit-destinations.php';

$scp_optional = array(
    'includes/lib/class-scp-keccak.php',
    'includes/lib/class-scp-eth-crypto.php',
    'includes/class-scp-bep20-wallet.php',
);
foreach ( $scp_optional as $scp_file ) {
    $scp_path = SCP_PLUGIN_DIR . $scp_file;
    if ( file_exists( $scp_path ) ) {
        require_once $scp_path;
    }
}

add_action( 'wp_enqueue_scripts', 'scp_enqueue_frontend_scripts' );
function scp_enqueue_frontend_scripts() {
    $key = get_option( 'scp_stripe_publishable_key', '' );
    if ( $key === '' ) {
        return;
    }
    wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
    wp_add_inline_script( 'stripe-js', 'const stripe = Stripe("' . esc_js( $key ) . '");' );
}