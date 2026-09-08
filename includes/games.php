<?php
/**
 * Custom Post Type for ScorpioPlay Games
 */

add_action( 'init', 'scp_register_game_cpt' );

function scp_register_game_cpt() {
    $labels = [
        'name'                  => 'Games',
        'singular_name'         => 'Game',
        'menu_name'             => 'Games',
        'name_admin_bar'        => 'Game',
        'add_new'               => 'Add New',
        'add_new_item'          => 'Add New Game',
        'new_item'              => 'New Game',
        'edit_item'             => 'Edit Game',
        'view_item'             => 'View Game',
        'all_items'             => 'All Games',
        'search_items'          => 'Search Games',
        'not_found'             => 'No games found.',
        'not_found_in_trash'    => 'No games found in Trash.',
    ];
    $args = [
        'labels'        => $labels,
        'public'        => true,  // visible on frontend (optional, we use shortcode)
        'has_archive'   => false,
        'show_ui'       => true,  // allow admin to manage
        'supports'      => [ 'title', 'thumbnail', 'custom-fields' ],
        'menu_icon'     => 'dashicons-games',
        'show_in_menu'  => false, // we add a submenu later
    ];
    register_post_type( 'scp_game', $args );

    // Register meta fields
    register_post_meta( 'scp_game', 'scp_game_id', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
        'description'   => 'ScorpioPlay game ID',
    ]);
    register_post_meta( 'scp_game', 'scp_game_enabled', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'boolean',
        'default'       => true,
    ]);
    register_post_meta( 'scp_game', 'scp_game_category', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
    ]);
    register_post_meta( 'scp_game', 'scp_game_provider_id', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'integer',
    ]);
    register_post_meta( 'scp_game', 'scp_game_provider_name', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
    ]);
    register_post_meta( 'scp_game', 'scp_game_code', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
    ]);
    register_post_meta( 'scp_game', 'scp_game_type', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
    ]);
    register_post_meta( 'scp_game', 'scp_game_in_maintenance', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'boolean',
        'default'       => false,
    ]);
}


add_shortcode( 'scp_game_lobby', 'scp_game_lobby_shortcode' );

function scp_game_lobby_shortcode() {
    ob_start();
    ?>
    <div id="scp-game-lobby" class="scp-game-grid">
        <?php
        $games = get_posts( [
            'post_type'      => 'scp_game',
            'meta_key'       => 'scp_game_enabled',
            'meta_value'     => '1',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        if ( empty( $games ) ) {
            echo '<p>No games available.</p>';
        } else {
            foreach ( $games as $game ) {
                $game_id = get_post_meta( $game->ID, 'scp_game_id', true );
                $thumb   = get_the_post_thumbnail( $game->ID, 'medium' );
                ?>
                <div class="scp-game-item" data-game-id="<?php echo esc_attr( $game_id ); ?>" data-provider-id="<?php echo esc_attr( get_post_meta( $game->ID, 'scp_game_provider_id', true ) ); ?>">
                    <?php if ( $thumb ) echo $thumb; ?>
                    <h3><?php echo esc_html( $game->post_title ); ?></h3>
                    <button class="scp-play-btn">Play</button>
                </div>
                <?php
            }
        }
        ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const lobby = document.getElementById('scp-game-lobby');
        if ( ! lobby ) return;

        lobby.addEventListener('click', function(e) {
            const btn = e.target.closest('.scp-play-btn');
            if ( ! btn ) return;

            const item = btn.closest('.scp-game-item');
            const gameId = item.dataset.gameId;

            // Show loading or disable button
            btn.textContent = 'Loading...';
            btn.disabled = true;

            // Request launch URL via AJAX
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'scp_launch_game',
                    provider_id: item.dataset.providerId || '',
                    game_id: gameId,
                })
            })
            .then(response => response.json())
            .then(data => {
                if ( data.success && data.url ) {
                    // Open game in new tab
                    window.open( data.url, '_blank' );
                } else {
                    alert( 'Failed to launch game: ' + ( data.message || 'Unknown error' ) );
                }
            })
            .catch(err => {
                console.error( err );
                alert( 'Network error.' );
            })
            .finally(() => {
                btn.textContent = 'Play';
                btn.disabled = false;
            });
        });
    });
    </script>

    <style>
    .scp-game-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
    }
    .scp-game-item {
        border: 1px solid #ccc;
        padding: 15px;
        text-align: center;
        width: 200px;
    }
    .scp-game-item img {
        max-width: 100%;
        height: auto;
    }
    .scp-play-btn {
        cursor: pointer;
        background: #0073aa;
        color: #fff;
        border: none;
        padding: 8px 16px;
        margin-top: 10px;
    }
    </style>
    <?php
    return ob_get_clean();
}

add_action( 'wp_ajax_scp_launch_game', 'scp_ajax_launch_game' );
add_action( 'wp_ajax_nopriv_scp_launch_game', 'scp_ajax_launch_game' ); // allow for non-logged in? Probably require login.

function scp_ajax_launch_game() {
    $user = wp_get_current_user();
    if ( empty( $user->user_login ) ) {
        wp_send_json_error( array( 'message' => 'User must be logged in to launch a game.' ) );
    }

    $provider_id = sanitize_text_field( $_POST['provider_id'] ?? '' );
    if ( empty( $provider_id ) ) {
        wp_send_json_error( array( 'message' => 'No providerId provided.' ) );
    }

    $game_id = sanitize_text_field( $_POST['game_id'] ?? '' );
    if ( empty( $game_id ) ) {
        wp_send_json_error( array( 'message' => 'No GameID provided.' ) );
    }

    $game_language = sanitize_text_field( $_POST['game_language'] ?? 'en' );
    $currency = sanitize_text_field( $_POST['currency'] ?? 'USD' );
    $rtp = sanitize_text_field( $_POST['rtp'] ?? 0 );

    $api = new SCP_API_Client();

    $result = $api->request( '/v1/game/launch', 'POST', array(
        'playerExternalId' => $user->user_login,
        'providerId'      => $provider_id,
        'gameCode'        => $game_id,
        'language'        => $game_language,
        'currency'        => $currency,
        'rtp'             => $rtp,
    ));

    if ( $result['success'] && ! empty( $result['data']['url'] ) ) {
        wp_send_json_success( [ 'url' => $result['data']['url'] ] );
    } else {
        wp_send_json_error( [ 'message' => $result['message'] ?? 'Could not obtain game URL.' ] );
    }
}