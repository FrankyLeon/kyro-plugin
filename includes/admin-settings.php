<?php
add_action( 'admin_menu', 'scp_add_admin_menu' );
add_action( 'admin_init', 'scp_register_settings' );
add_action( 'add_meta_boxes', 'scp_add_game_meta_boxes' );
add_action( 'save_post_scp_game', 'scp_save_game_meta' );
add_action( 'restrict_manage_posts', 'scp_add_game_provider_filter' );
add_action( 'pre_get_posts', 'scp_apply_game_provider_filter' );

function scp_add_admin_menu() {
    add_menu_page(
        'ScorpioPlay',
        'ScorpioPlay',
        'manage_options',
        'scorpioplay',
        'scp_settings_page',
        'dashicons-games',
        30
    );

    // Add submenu to our main menu
    add_submenu_page(
        'scorpioplay',
        'Payment Methods',
        'Payment Methods',
        'manage_options',
        'scp-deposit-destinations',
        'scp_render_deposit_destinations_page'
    );

    add_submenu_page(
        'scorpioplay',
        'Game Manager',
        'Game Manager',
        'manage_options',
        'edit.php?post_type=scp_game' // redirects to the CPT list
    );

    add_submenu_page(
        'scorpioplay',
        'Sync Games',
        'Sync Games',
        'manage_options',
        'scp-sync-games',
        'scp_sync_games_page'
    );

    add_submenu_page(
        'scorpioplay',
        'Players',
        'Players',
        'manage_options',
        'scp-user-sync',
        'scp_render_user_mapping_page'
    );

    add_submenu_page(
        'scorpioplay',
        'Deposit Requests',
        'Deposits',
        'manage_options',
        'scp-deposits',
        'scp_deposits_page'
    );

    add_submenu_page(
        'scorpioplay',
        'Withdrawal Requests',
        'Withdrawals',
        'manage_options',
        'scp-withdrawals',
        'scp_withdrawals_page'
    );

    add_submenu_page(
        'scorpioplay',
        'Balance Manager',
        'Balance Manager',
        'manage_options',
        'scp-balance',
        'scp_balance_page'
    );

    add_submenu_page(
        'scorpioplay',
        'Transaction History',
        'Transaction History',
        'manage_options',
        'scp-logs',
        'scp_logs_page'
    );

    add_submenu_page(
        'options.php',
        'Wallet Log',
        'Wallet Log',
        'manage_options',
        'scp-wallet-log',
        'scp_wallet_log_legacy_page'
    );

    add_submenu_page(
        'options.php',
        'Game Play',
        'Game Play',
        'manage_options',
        'scp-game-log',
        'scp_game_log_legacy_page'
    );
}

function scp_register_settings() {
    register_setting( 'scp_settings_group', 'scp_api_base_url' );
    register_setting( 'scp_settings_group', 'scp_api_key' );
    register_setting( 'scp_settings_group', 'scp_stripe_publishable_key' );
    register_setting( 'scp_settings_group', 'scp_stripe_secret_key' );
}

function scp_settings_page() {
    ?>
    <div class="wrap">
        <h1>ScorpioPlay API Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'scp_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <th>API BaseURL</th>
                    <td><input type="text" name="scp_api_base_url" value="<?php echo esc_attr( get_option('scp_api_base_url', '') ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>API Key</th>
                    <td><input type="text" name="scp_api_key" value="<?php echo esc_attr( get_option('scp_api_key', '') ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Stripe Publishable Key</th>
                    <td><input type="text" name="scp_stripe_publishable_key" value="<?php echo esc_attr( get_option('scp_stripe_publishable_key', '') ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Stripe Secret Key</th>
                    <td><input type="password" name="scp_stripe_secret_key" value="<?php echo esc_attr( get_option('scp_stripe_secret_key', '') ); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <p class="description">
                Bank, crypto, PayPal, and the BEP-20 private key are managed on
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=scp-deposit-destinations' ) ); ?>">Deposit Destinations</a>.
            </p>
            <?php submit_button(); ?>
        </form>

        <hr>
        <h2>Test Connection</h2>
        <?php
        if ( isset($_POST['scp_test_connection']) && check_admin_referer('scp_test_connection_nonce') ) {
            $api = new SCP_API_Client();
            $result = $api->test_connection();
            if ( $result['success'] ) {
                echo '<div class="notice notice-success"><p>✅ API connection successful!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Connection failed: ' . esc_html( $result['message'] ) . '</p></div>';
            }
        }
        ?>
        <form method="post">
            <?php wp_nonce_field( 'scp_test_connection_nonce' ); ?>
            <input type="submit" name="scp_test_connection" value="Test Connection" class="button button-secondary" />
        </form>
    </div>
    <?php
}

function scp_add_game_meta_boxes() {
    // Remove default Custom Fields meta box
    remove_meta_box( 'postcustom', 'scp_game', 'normal' );
    
    add_meta_box(
        'scp_game_sync_info_box',
        'Game Information',
        'scp_render_sync_info_meta_box',
        'scp_game',
        'normal',
        'high'
    );

    add_meta_box(
        'scp_game_status_box',
        'Game Status',
        'scp_render_status_meta_box',
        'scp_game',
        'normal',
        'high'
    );
}

function scp_render_sync_info_meta_box( $post ) {
    $game_id       = get_post_meta( $post->ID, 'scp_game_id', true );
    $game_code     = get_post_meta( $post->ID, 'scp_game_code', true );
    $game_type     = get_post_meta( $post->ID, 'scp_game_type', true );
    $provider_id   = get_post_meta( $post->ID, 'scp_game_provider_id', true );
    $provider_name = get_post_meta( $post->ID, 'scp_game_provider_name', true );
    ?>
    <table class="form-table">
        <tr>
            <th scope="row"><label>Provider</label></th>
            <td>
                <strong><?php echo esc_html( $provider_name ?: $provider_id ?: 'Not set' ); ?></strong>
                <?php if ( ! empty( $provider_id ) && ! empty( $provider_name ) ) : ?>
                    <span style="color: #999;">(ID: <?php echo esc_html( $provider_id ); ?>)</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label>Game Code</label></th>
            <td>
                <code><?php echo esc_html( $game_code ?: 'Not set' ); ?></code>
            </td>
        </tr>
        <tr>
            <th scope="row"><label>Game ID</label></th>
            <td>
                <code><?php echo esc_html( $game_id ?: 'Not set' ); ?></code>
            </td>
        </tr>
        <tr>
            <th scope="row"><label>Game Type</label></th>
            <td>
                <strong><?php echo esc_html( $game_type ?: 'Not set' ); ?></strong>
            </td>
        </tr>
    </table>
    <p style="color: #666; font-size: 12px; margin-top: 10px;">
        <em>These fields are read-only and managed by the API sync. To update, re-sync games from the provider.</em>
    </p>
    <?php
}

function scp_render_status_meta_box( $post ) {
    wp_nonce_field( 'scp_save_game_meta', 'scp_game_nonce' );
    
    $enabled = get_post_meta( $post->ID, 'scp_game_enabled', true );
    ?>
    <table class="form-table">
        <tr>
            <th scope="row"><label for="scp_game_enabled">Display in Game Lobby</label></th>
            <td>
                <label>
                    <input type="checkbox" name="scp_game_enabled" id="scp_game_enabled" value="1" <?php checked( $enabled, 1 ); ?> />
                    <span style="margin-left: 8px;">Show this game in the frontend game lobby</span>
                </label>
            </td>
        </tr>
    </table>
    <?php
}

function scp_add_game_provider_filter() {
    $screen = get_current_screen();
    if ( ! $screen || 'scp_game' !== $screen->post_type ) {
        return;
    }

    $providers = [];
    $api = new SCP_API_Client();
    $providers_result = $api->request( '/v1/provider/list', 'GET' );
    if ( ! empty( $providers_result['success'] ) && ! empty( $providers_result['data'] ) && is_array( $providers_result['data'] ) ) {
        $providers = $providers_result['data'];
    }

    $selected = sanitize_text_field( $_GET['scp_game_provider'] ?? '' );
    echo '<select name="scp_game_provider" id="scp_game_provider" class="postform">';
    echo '<option value="">' . esc_html__( 'All Providers', 'scp' ) . '</option>';
    foreach ( $providers as $provider ) {
        $provider_id   = $provider['providerId'] ?? $provider['id'] ?? '';
        $provider_name = $provider['providerName'] ?? $provider['name'] ?? $provider_id;
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr( $provider_id ),
            selected( $selected, $provider_id, false ),
            esc_html( $provider_name )
        );
    }
    echo '</select>';
}

function scp_apply_game_provider_filter( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $post_type = $query->get( 'post_type' );
    if ( 'scp_game' !== $post_type ) {
        return;
    }

    $provider = sanitize_text_field( $_GET['scp_game_provider'] ?? '' );
    if ( '' === $provider ) {
        return;
    }

    $meta_query = $query->get( 'meta_query' );
    if ( ! is_array( $meta_query ) ) {
        $meta_query = [];
    }

    $meta_query[] = [
        'key'   => 'scp_game_provider_id',
        'value' => $provider,
    ];

    $query->set( 'meta_query', $meta_query );
}

function scp_save_game_meta( $post_id ) {
    // Verify nonce
    if ( ! isset( $_POST['scp_game_nonce'] ) || ! wp_verify_nonce( $_POST['scp_game_nonce'], 'scp_save_game_meta' ) ) {
        return;
    }

    // Verify user capability
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Save enabled/disabled status
    $enabled = isset( $_POST['scp_game_enabled'] ) ? 1 : 0;
    update_post_meta( $post_id, 'scp_game_enabled', $enabled );
}

add_action( 'wp_ajax_scp_sync_games', 'scp_ajax_sync_games' );
add_filter( 'manage_scp_game_posts_columns', 'scp_game_custom_columns' );
add_action( 'manage_scp_game_posts_custom_column', 'scp_game_custom_column_content', 10, 2 );
add_action( 'admin_action_scp_toggle_game', 'scp_handle_toggle_game' );

function scp_game_custom_columns( $columns ) {
    $new = [];
    foreach ( $columns as $key => $value ) {
        if ( $key == 'title' ) {
            $new['thumbnail'] = 'Thumbnail';
        }
        $new[$key] = $value;
    }
    $new['game_id']     = 'Game ID';
    $new['enabled']     = 'Enabled';
    $new['provider']    = 'Provider';
    return $new;
}

function scp_game_custom_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'thumbnail':
            echo get_the_post_thumbnail( $post_id, [50,50] );
            break;
        case 'game_id':
            echo esc_html( get_post_meta( $post_id, 'scp_game_id', true ) );
            break;
        case 'enabled':
            $enabled = get_post_meta( $post_id, 'scp_game_enabled', true );
            $nonce = wp_create_nonce( 'toggle_game_' . $post_id );
            $link  = admin_url( "admin.php?action=scp_toggle_game&post=$post_id&_wpnonce=$nonce" );
            echo $enabled 
                ? "<a href='$link' style='color:green;'>Yes</a>" 
                : "<a href='$link' style='color:red;'>No</a>";
            break;
        case 'provider':
            $provider_name = get_post_meta( $post_id, 'scp_game_provider_name', true );
            $provider_id   = get_post_meta( $post_id, 'scp_game_provider_id', true );
            if ( ! empty( $provider_name ) ) {
                echo esc_html( $provider_name ) . ' (' . esc_html( $provider_id ) . ')';
            } else {
                echo esc_html( $provider_id );
            }
            break;
    }
}

function scp_handle_toggle_game() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    $post_id = intval( $_GET['post'] );
    check_admin_referer( 'toggle_game_' . $post_id );
    $current = get_post_meta( $post_id, 'scp_game_enabled', true );
    update_post_meta( $post_id, 'scp_game_enabled', ! $current );
    wp_redirect( admin_url( 'edit.php?post_type=scp_game' ) );
    exit;
}

function scp_ajax_sync_games() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
    }

    check_ajax_referer( 'scp_sync_games', '_wpnonce' );

    $provider_id = sanitize_text_field( $_POST['provider_id'] ?? '' );
    if ( empty( $provider_id ) ) {
        wp_send_json_error( [ 'message' => 'Please select a provider to sync.' ] );
    }

    $result = scp_sync_games_by_provider( $provider_id );
    if ( empty( $result['success'] ) ) {
        wp_send_json_error( [ 'message' => $result['message'] ?? 'Sync failed.' ] );
    }

    wp_send_json_success( $result );
}

function scp_sync_games_by_provider( $provider_id ) {
    $api = new SCP_API_Client();

    $providers_result = $api->request( '/v1/provider/list', 'GET' );
    $provider_name = '';
    if ( ! empty( $providers_result['success'] ) && ! empty( $providers_result['data'] ) && is_array( $providers_result['data'] ) ) {
        foreach ( $providers_result['data'] as $provider ) {
            $prov_id = $provider['providerId'] ?? $provider['id'] ?? null;
            if ( (string) $prov_id === (string) $provider_id ) {
                $provider_name = $provider['providerName'] ?? $provider['name'] ?? '';
                break;
            }
        }
    }

    $game_result = $api->request( '/v1/game/list/' . rawurlencode( $provider_id ), 'GET' );

    if ( empty( $game_result['success'] ) || empty( $game_result['data'] ) || ! is_array( $game_result['data'] ) ) {
        return [
            'success' => false,
            'message' => $game_result['message'] ?? 'Failed to fetch games for provider ' . esc_html( $provider_id ) . '.',
            'payload' => $game_result,
        ];
    }

    $games = $game_result['data'];
    $synced = 0;
    foreach ( $games as $game ) {
        scp_sync_single_game( $game, $provider_id, $provider_name );
        $synced++;
    }

    return [
        'success'       => true,
        'message'       => 'Games synced successfully.',
        'synced'        => $synced,
        'provider_id'   => $provider_id,
        'provider_name' => $provider_name,
    ];
}

function scp_sync_games_page() {
    $api = new SCP_API_Client();
    $providers_result = $api->request( '/v1/provider/list', 'GET' );
    $provider_list = [];
    if ( ! empty( $providers_result['success'] ) && ! empty( $providers_result['data'] ) && is_array( $providers_result['data'] ) ) {
        $provider_list = $providers_result['data'];
    }

    $sync_notice = '';
    if ( isset( $_POST['scp_sync_now'] ) && check_admin_referer( 'scp_sync_games' ) ) {
        $selected_provider = sanitize_text_field( $_POST['provider_id'] ?? '' );
        if ( empty( $selected_provider ) ) {
            $sync_notice = '<div class="notice notice-error"><p>Please select a provider before syncing.</p></div>';
        } else {
            $result = scp_sync_games_by_provider( $selected_provider );
            if ( empty( $result['success'] ) ) {
                $sync_notice = '<div class="notice notice-error"><p>' . esc_html( $result['message'] ) . '</p></div>';
            } else {
                $provider_label = ! empty( $result['provider_name'] ) ? $result['provider_name'] . ' (' . esc_html( $result['provider_id'] ) . ')' : esc_html( $result['provider_id'] );
                $sync_notice = '<div class="notice notice-success"><p>✅ Games synced successfully for provider ' . $provider_label . '. Total games: ' . esc_html( $result['synced'] ) . '.</p></div>';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Sync Games from ScorpioPlay</h1>
        <?php echo $sync_notice; ?>

        <?php if ( empty( $provider_list ) ) : ?>
            <div class="notice notice-error"><p>Unable to load providers from ScorpioPlay. Please check the API settings and try again.</p></div>
        <?php endif; ?>

        <form id="scp-sync-form" method="post">
            <?php wp_nonce_field( 'scp_sync_games' ); ?>
            <p>Select a provider and then click the sync button below. The page will stay in place while synchronization runs.</p>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="scp-sync-provider">Provider</label></th>
                    <td>
                        <select name="provider_id" id="scp-sync-provider" class="regular-text">
                            <option value="">-- Select a provider --</option>
                            <?php foreach ( $provider_list as $provider ) :
                                $provider_id   = $provider['providerId'] ?? $provider['id'] ?? '';
                                $provider_name = $provider['providerName'] ?? $provider['name'] ?? ''; ?>
                                <option value="<?php echo esc_attr( $provider_id ); ?>"><?php echo esc_html( $provider_name ?: $provider_id ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="scp-sync-button" class="button button-primary">Sync Selected Provider</button>
                <input type="submit" name="scp_sync_now" value="Sync Selected Provider (fallback)" class="button button-secondary" />
            </p>

            <div id="scp-sync-progress-wrapper" style="display:none; margin-top:20px; max-width:600px;">
                <div style="background:#e5e5e5; height:16px; border-radius:4px; overflow:hidden;">
                    <div id="scp-sync-progress-bar" style="width:0%; height:16px; background:#0073aa; transition: width 0.3s ease;"></div>
                </div>
                <p id="scp-sync-progress-text" style="margin-top:8px;">Preparing sync…</p>
            </div>

            <div id="scp-sync-log" style="margin-top:20px; max-width:600px; white-space:pre-wrap; background:#fff; border:1px solid #ccd0d4; padding:12px; display:none;"></div>
        </form>
    </div>

    <script>
    (function(){
        var button = document.getElementById('scp-sync-button');
        var providerSelect = document.getElementById('scp-sync-provider');
        var progressWrapper = document.getElementById('scp-sync-progress-wrapper');
        var progressBar = document.getElementById('scp-sync-progress-bar');
        var progressText = document.getElementById('scp-sync-progress-text');
        var logArea = document.getElementById('scp-sync-log');
        var nonce = '<?php echo esc_js( wp_create_nonce( 'scp_sync_games' ) ); ?>';

        function setProgress(value, text) {
            progressWrapper.style.display = 'block';
            progressBar.style.width = value + '%';
            progressText.textContent = text;
        }

        function addLog(message) {
            logArea.style.display = 'block';
            logArea.textContent += message + '\n';
            logArea.scrollTop = logArea.scrollHeight;
        }

        function startIndeterminate() {
            var width = 5;
            setProgress(width, 'Syncing...');
            return setInterval(function(){
                width = width < 80 ? width + 5 : 20;
                progressBar.style.width = width + '%';
            }, 700);
        }

        if ( button ) {
            button.addEventListener('click', function(){
                var providerId = providerSelect.value;
                if ( ! providerId ) {
                    alert('Please select a provider first.');
                    return;
                }

                button.disabled = true;
                providerSelect.disabled = true;
                logArea.textContent = '';
                logArea.style.display = 'none';
                progressBar.style.width = '0%';
                progressWrapper.style.display = 'block';
                progressText.textContent = 'Starting sync...';

                var spinner = startIndeterminate();

                var body = new URLSearchParams();
                body.append('action', 'scp_sync_games');
                body.append('provider_id', providerId);
                body.append('_wpnonce', nonce);

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body.toString()
                }).then(function(response){
                    return response.json();
                }).then(function(data){
                    clearInterval(spinner);
                    if ( data.success ) {
                        setProgress(100, 'Sync complete');
                        addLog('Provider: ' + data.data.provider_name + ' (' + data.data.provider_id + ')');
                        addLog('Games processed: ' + data.data.synced);
                        addLog('Result: ' + data.data.message);
                    } else {
                        setProgress(100, 'Sync failed');
                        addLog('Error: ' + (data.data?.message || data.data || 'Unknown error'));
                    }
                }).catch(function(error){
                    clearInterval(spinner);
                    setProgress(100, 'Sync failed');
                    addLog('Request failed: ' + error.message);
                }).finally(function(){
                    button.disabled = false;
                    providerSelect.disabled = false;
                });
            });
        }
    })();
    </script>
    <?php
}

function scp_sync_single_game( $game_data, $provider_id = null, $provider_name = '' ) {
    $provider_id   = $provider_id ?? ( $game_data['providerId'] ?? null );
    $game_code     = $game_data['gameCode'] ?? $game_data['gameID'] ?? '';
    $game_name     = $game_data['gameName'] ?? '';
    $game_type     = $game_data['gameType'] ?? '';
    $provider_name = $provider_name ?: ( $game_data['providerName'] ?? '' );
    $game_desc     = $game_data['description'] ?? '';
    $thumbnail     = $game_data['gameImage'] ?? '';

    if ( empty( $provider_id ) || empty( $game_code ) ) {
        return;
    }

    $game_id = sanitize_text_field( $provider_id . '_' . $game_code );

    $existing = get_posts( [
        'post_type'   => 'scp_game',
        'meta_key'    => 'scp_game_id',
        'meta_value'  => $game_id,
        'post_status' => 'any',
        'numberposts' => 1,
    ] );

    $post_args = [
        'post_title'   => $game_name,
        'post_content' => $game_desc,
        'post_type'    => 'scp_game',
        'post_status'  => 'publish',
    ];

    if ( ! empty( $existing ) ) {
        $post_id = $existing[0]->ID;
        $post_args['ID'] = $post_id;
        wp_update_post( $post_args );
    } else {
        $post_id = wp_insert_post( $post_args );
    }

    update_post_meta( $post_id, 'scp_game_id', $game_id );
    update_post_meta( $post_id, 'scp_game_provider_id', $provider_id );
    update_post_meta( $post_id, 'scp_game_provider_name', $provider_name );
    update_post_meta( $post_id, 'scp_game_category', $provider_id );
    update_post_meta( $post_id, 'scp_game_code', $game_code );
    update_post_meta( $post_id, 'scp_game_type', $game_type );
    update_post_meta( $post_id, 'scp_game_in_maintenance', ! empty( $game_data['inMaintenance'] ) );

    if ( ! empty( $thumbnail ) ) {
        scp_set_featured_image_from_url( $post_id, $thumbnail );
    }

    if ( ! get_post_meta( $post_id, 'scp_game_enabled', true ) ) {
        update_post_meta( $post_id, 'scp_game_enabled', true );
    }
}

// Helper to set featured image from URL (simple version, may need adjustments)
function scp_set_featured_image_from_url( $post_id, $url ) {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
    if ( ! is_wp_error( $attachment_id ) ) {
        set_post_thumbnail( $post_id, $attachment_id );
    }
}


function scp_admin_user_label( $row ) {
    $user = get_userdata( $row->user_id );
    if ( $user ) {
        return $user->display_name . ' (' . $user->user_email . ')';
    }

    return $row->user_login ?: ( 'User #' . $row->user_id );
}

function scp_admin_handle_wallet_request_action( $page_slug ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '';
    }

    if ( isset( $_GET['approve'] ) ) {
        $id = absint( $_GET['approve'] );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'scp-approve-' . $id ) ) {
            return '<div class="notice notice-error"><p>Invalid approval request.</p></div>';
        }

        if ( ! function_exists( 'scp_approve_wallet_request' ) ) {
            return '<div class="notice notice-error"><p>Approval helpers are missing.</p></div>';
        }

        $result = scp_approve_wallet_request( $id );
        if ( ! empty( $result['success'] ) ) {
            return '<div class="notice notice-success"><p>Request approved. Scorpio API was called and the log was updated.</p></div>';
        }

        return '<div class="notice notice-error"><p>Approval failed: ' . esc_html( $result['message'] ?? 'Unknown error' ) . '</p></div>';
    }

    if ( isset( $_GET['reject'] ) ) {
        $id = absint( $_GET['reject'] );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'scp-reject-' . $id ) ) {
            return '<div class="notice notice-error"><p>Invalid rejection request.</p></div>';
        }

        $result = scp_reject_wallet_request( $id );
        if ( ! empty( $result['success'] ) ) {
            return '<div class="notice notice-success"><p>Request rejected. The log was updated.</p></div>';
        }

        return '<div class="notice notice-error"><p>Rejection failed: ' . esc_html( $result['message'] ?? 'Unknown error' ) . '</p></div>';
    }

    unset( $page_slug );
    return '';
}

function scp_admin_render_pending_wallet_page( $type, $title, $page_slug ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( ! function_exists( 'scp_get_pending_wallet_requests' ) ) {
        echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
        echo '<div class="notice notice-error"><p>Transaction helpers are missing. Reload the ScorpioPlay plugin files and try again.</p></div></div>';
        return;
    }

    try {
        $notice  = scp_admin_handle_wallet_request_action( $page_slug );
        $pending = scp_get_pending_wallet_requests( $type );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<p>Approve only after the selected payment method has succeeded. Approval calls Scorpio API and leaves a transaction log.</p>';

        if ( empty( $pending ) ) {
            echo '<p>No pending requests.</p></div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>ID</th><th>User</th><th>Amount</th><th>Method / details</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $pending as $row ) {
            $meta    = scp_decode_transaction_response( $row );
            $details = scp_format_wallet_details( $meta );
            $approve = wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&approve=' . $row->id ), 'scp-approve-' . $row->id );
            $reject  = wp_nonce_url( admin_url( 'admin.php?page=' . $page_slug . '&reject=' . $row->id ), 'scp-reject-' . $row->id );

            echo '<tr>';
            echo '<td>' . esc_html( $row->id ) . '<br><code>' . esc_html( $row->txn_id ) . '</code></td>';
            echo '<td>' . esc_html( scp_admin_user_label( $row ) ) . '</td>';
            echo '<td>' . esc_html( $row->amount ) . ' ' . esc_html( $row->currency ) . '</td>';
            echo '<td>' . esc_html( $details ?: '—' ) . '</td>';
            echo '<td>' . esc_html( $row->created_at ) . '</td>';
            echo '<td><a href="' . esc_url( $approve ) . '">Approve</a> | <a href="' . esc_url( $reject ) . '">Reject</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    } catch ( Throwable $e ) {
        echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
        echo '<div class="notice notice-error"><p>Unable to load requests: ' . esc_html( $e->getMessage() ) . '</p></div></div>';
    }
}

function scp_deposits_page() {
    scp_admin_render_pending_wallet_page( 'deposit', 'Pending Deposit Requests', 'scp-deposits' );
}

function scp_admin_badge( $label, $kind ) {
    $map = array(
        'deposit'   => array( '#166534', '#dcfce7' ),
        'withdraw'  => array( '#9a3412', '#ffedd5' ),
        'Win'       => array( '#166534', '#dcfce7' ),
        'Bet'       => array( '#9a3412', '#ffedd5' ),
        'completed' => array( '#166534', '#dcfce7' ),
        'Success'   => array( '#166534', '#dcfce7' ),
        'pending'   => array( '#854d0e', '#fef9c3' ),
        'failed'    => array( '#991b1b', '#fee2e2' ),
        'rejected'  => array( '#991b1b', '#fee2e2' ),
        'Failed'    => array( '#991b1b', '#fee2e2' ),
    );
    $colors = $map[ $kind ] ?? array( '#334155', '#e2e8f0' );

    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . esc_attr( $colors[1] ) . ';color:' . esc_attr( $colors[0] ) . ';">' . esc_html( $label ) . '</span>';
}

function scp_admin_short_hash( $value ) {
    $value = function_exists( 'scp_scalar_string' ) ? scp_scalar_string( $value ) : (string) $value;
    if ( $value === '' ) {
        return '';
    }
    if ( strlen( $value ) > 18 ) {
        return substr( $value, 0, 10 ) . '…' . substr( $value, -6 );
    }
    return $value;
}

function scp_admin_log_query( $view, $extra = array() ) {
    $query = array_merge(
        array(
            'page'             => 'scp-logs',
            'view'             => $view === 'games' ? 'games' : 'wallet',
            'playerExternalId' => sanitize_text_field( wp_unslash( $_GET['playerExternalId'] ?? '' ) ),
            'startTime'        => sanitize_text_field( wp_unslash( $_GET['startTime'] ?? '' ) ),
            'endTime'          => sanitize_text_field( wp_unslash( $_GET['endTime'] ?? '' ) ),
            'walletType'      => sanitize_text_field( wp_unslash( $_GET['walletType'] ?? '' ) ),
            'status'           => sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ),
            'operator'         => sanitize_text_field( wp_unslash( $_GET['operator'] ?? '' ) ),
            'transType'        => sanitize_text_field( wp_unslash( $_GET['transType'] ?? '' ) ),
            'roundId'          => sanitize_text_field( wp_unslash( $_GET['roundId'] ?? '' ) ),
        ),
        $extra
    );

    foreach ( $query as $key => $value ) {
        if ( $value === '' || $value === null ) {
            unset( $query[ $key ] );
        }
    }

    return admin_url( 'admin.php?' . http_build_query( $query ) );
}

function scp_admin_toggle_query( $view, $key, $value ) {
    $current = sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) );
    return scp_admin_log_query( $view, array(
        $key     => ( (string) $current === (string) $value ) ? '' : $value,
        'offset' => '',
    ) );
}

function scp_game_trans_type_label( $trans_type ) {
    $map = array(
        '1' => 'Bet',
        '2' => 'Win',
        '3' => 'Refund',
    );
    $key = (string) $trans_type;
    return $map[ $key ] ?? '';
}

function scp_admin_log_pagination( $total, $offset, $limit ) {
    if ( $total <= $limit ) {
        return;
    }
    $base = remove_query_arg( 'offset' );
    echo '<p style="margin-top:12px;">';
    if ( $offset > 0 ) {
        echo '<a class="button" href="' . esc_url( add_query_arg( 'offset', max( 0, $offset - $limit ), $base ) ) . '">Previous</a> ';
    }
    if ( ( $offset + $limit ) < $total ) {
        echo '<a class="button" href="' . esc_url( add_query_arg( 'offset', $offset + $limit, $base ) ) . '">Next</a>';
    }
    echo '</p>';
}

function scp_admin_log_styles() {
    echo '<style>
        .scp-log-stats{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px;margin:16px 0;}
        .scp-log-stat{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 14px;text-decoration:none;color:#1d2327;display:block;cursor:pointer;}
        .scp-log-stat:hover{border-color:#2271b1;}
        .scp-log-stat.is-active{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;background:#f0f6fc;}
        .scp-mix-legend a{color:#646970;text-decoration:none;}
        .scp-mix-legend a:hover,.scp-mix-legend a.is-active{color:#2271b1;font-weight:600;}
        .scp-log-stat span{display:block;color:#646970;font-size:12px;}
        .scp-log-stat strong{display:block;font-size:22px;margin-top:4px;}
        .scp-txn-filters{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0;}
        .scp-txn-filters .scp-filter-grid{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px;align-items:end;}
        .scp-id-cell code{display:block;margin-top:4px;font-size:11px;word-break:break-all;}
        .scp-analytics{margin:16px 0 8px;}
        .scp-mix{display:flex;height:10px;border-radius:999px;overflow:hidden;background:#e2e8f0;margin:8px 0 4px;}
        .scp-mix i{display:block;height:100%;}
        .scp-mix-legend{display:flex;gap:16px;color:#646970;font-size:12px;margin-bottom:12px;}
        .scp-analytics-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px;}
        .scp-panel{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 14px;}
        .scp-panel h3{margin:0 0 8px;font-size:13px;}
        .scp-panel table{margin:0;}
        .scp-muted{color:#646970;font-size:12px;margin:0 0 12px;}
        @media (max-width:1100px){.scp-analytics-grid{grid-template-columns:1fr;}.scp-log-stats{grid-template-columns:repeat(2,minmax(140px,1fr));}}
    </style>';
}

function scp_logs_page() {
    $view = sanitize_key( wp_unslash( $_GET['view'] ?? 'wallet' ) );
    if ( $view === 'games' ) {
        scp_game_log_page();
        return;
    }
    scp_wallet_log_page();
}

function scp_wallet_log_legacy_page() {
    wp_safe_redirect( admin_url( 'admin.php?page=scp-logs&view=wallet' ) );
    exit;
}

function scp_game_log_legacy_page() {
    wp_safe_redirect( admin_url( 'admin.php?page=scp-logs&view=games' ) );
    exit;
}

function scp_admin_log_tabs( $current ) {
    echo '<h1>Transaction History</h1>';
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px;">';
    echo '<a class="nav-tab' . ( $current === 'wallet' ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=scp-logs&view=wallet' ) ) . '">Wallet Log</a>';
    echo '<a class="nav-tab' . ( $current === 'games' ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=scp-logs&view=games' ) ) . '">Game Play</a>';
    echo '</nav>';
}

function scp_wallet_log_page() {
    echo '<div class="wrap">';
    scp_admin_log_tabs( 'wallet' );
    scp_admin_log_styles();
    try {
        scp_admin_render_wallet_log();
    } catch ( Throwable $e ) {
        echo '<div class="notice notice-error"><p>Unable to load transactions: ' . esc_html( $e->getMessage() ) . '</p></div>';
    }
    echo '</div>';
}

function scp_game_log_page() {
    echo '<div class="wrap">';
    scp_admin_log_tabs( 'games' );
    scp_admin_log_styles();
    try {
        scp_admin_render_game_log();
    } catch ( Throwable $e ) {
        echo '<div class="notice notice-error"><p>Unable to load transactions: ' . esc_html( $e->getMessage() ) . '</p></div>';
    }
    echo '</div>';
}

function scp_admin_render_wallet_log() {
    if ( ! function_exists( 'scp_fetch_wallet_transaction_log' ) ) {
        echo '<div class="notice notice-error"><p>Transaction helpers are missing. Reload the ScorpioPlay plugin files and try again.</p></div>';
        return;
    }

    $defaults = scp_wallet_log_default_times();
    $filters  = array(
        'walletType'       => sanitize_text_field( wp_unslash( $_GET['walletType'] ?? '' ) ),
        'status'           => sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ),
        'startTime'        => sanitize_text_field( wp_unslash( $_GET['startTime'] ?? $defaults['startTime'] ) ),
        'endTime'          => sanitize_text_field( wp_unslash( $_GET['endTime'] ?? $defaults['endTime'] ) ),
        'playerExternalId' => sanitize_text_field( wp_unslash( $_GET['playerExternalId'] ?? '' ) ),
        'offset'           => max( 0, absint( $_GET['offset'] ?? 0 ) ),
        'limit'            => 20,
    );

    $result = scp_fetch_wallet_transaction_log( $filters );
    $list   = is_array( $result['list'] ?? null ) ? $result['list'] : array();
    $total  = (int) ( $result['total'] ?? 0 );
    $offset = (int) ( $result['offset'] ?? 0 );
    $start_local = $filters['startTime'] ? str_replace( ' ', 'T', substr( $filters['startTime'], 0, 16 ) ) : '';
    $end_local   = $filters['endTime'] ? str_replace( ' ', 'T', substr( $filters['endTime'], 0, 16 ) ) : '';

    echo '<form method="get" class="scp-txn-filters">';
    echo '<input type="hidden" name="page" value="scp-logs" />';
    echo '<input type="hidden" name="view" value="wallet" />';
    echo '<div class="scp-filter-grid">';
    echo '<label>Type<br><select name="walletType"><option value="">All wallet</option>';
    foreach ( array( 'deposit' => 'Deposit', 'withdraw' => 'Withdraw' ) as $value => $label ) {
        echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['walletType'], $value, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Status<br><select name="status"><option value="">All</option>';
    foreach ( array( 'pending' => 'Pending', 'completed' => 'Completed', 'failed' => 'Failed', 'rejected' => 'Rejected' ) as $value => $label ) {
        echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['status'], $value, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Player<br><input type="text" name="playerExternalId" class="regular-text" value="' . esc_attr( $filters['playerExternalId'] ) . '" placeholder="Login or player ID" /></label>';
    echo '<label>Start Time<br><input type="datetime-local" name="startTime" value="' . esc_attr( $start_local ) . '" /></label>';
    echo '<label>End Time<br><input type="datetime-local" name="endTime" value="' . esc_attr( $end_local ) . '" /></label>';
    echo '<p><button type="submit" class="button button-primary">Search</button> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=scp-logs&view=wallet' ) ) . '">Reset</a></p>';
    echo '</div></form>';

    $stats = function_exists( 'scp_summarize_wallet_transactions' )
        ? scp_summarize_wallet_transactions( $filters )
        : array( 'counts' => $result['counts'] ?? array() );
    scp_admin_render_wallet_analytics( $stats );

    echo '<p>Showing ' . esc_html( (string) count( $list ) ) . ' of ' . esc_html( (string) $total ) . ' wallet movements in this range.</p>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Player</th><th>Type</th><th>Amount</th><th>Method / details</th><th>Status</th><th>On-chain / gateway</th><th>Created</th></tr></thead><tbody>';

    if ( empty( $list ) ) {
        echo '<tr><td colspan="8">No deposits or withdrawals found for this range.</td></tr>';
    }

    foreach ( $list as $row ) {
        $player  = function_exists( 'scp_admin_user_label' ) && ! empty( $row['row'] ) ? scp_admin_user_label( $row['row'] ) : scp_scalar_string( $row['user_login'] ?? '' );
        $gateway = scp_scalar_string( $row['gateway'] ?? '' );
        echo '<tr>';
        echo '<td class="scp-id-cell">' . esc_html( (string) ( $row['id'] ?? '' ) ) . '<code>' . esc_html( $row['txn_id'] ?? '' ) . '</code></td>';
        echo '<td>' . esc_html( $player ) . '</td>';
        echo '<td>' . scp_admin_badge( $row['typeLabel'] ?? '', $row['type'] ?? '' ) . '</td>';
        echo '<td>' . esc_html( scp_admin_money( $row['amount'] ?? 0 ) ) . ' ' . esc_html( $row['currency'] ?? '' ) . '</td>';
        echo '<td>' . esc_html( $row['details'] !== '' ? $row['details'] : '—' ) . '</td>';
        echo '<td>' . scp_admin_badge( $row['statusLabel'] ?? '', $row['status'] ?? '' ) . '</td>';
        echo '<td>' . ( $gateway !== '' ? '<code title="' . esc_attr( $gateway ) . '">' . esc_html( scp_admin_short_hash( $gateway ) ) . '</code>' : '—' ) . '</td>';
        echo '<td>' . esc_html( scp_admin_format_datetime( $row['createdAt'] ?? '' ) ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    scp_admin_log_pagination( $total, $offset, $filters['limit'] );
}

function scp_admin_money( $amount ) {
    return '$' . number_format( (float) $amount, 2 );
}

function scp_admin_render_wallet_analytics( $stats ) {
    $deposited = (float) ( $stats['deposit_sum'] ?? 0 );
    $withdrawn = (float) ( $stats['withdraw_sum'] ?? 0 );
    $pending   = (float) ( $stats['pending_deposit_sum'] ?? 0 ) + (float) ( $stats['pending_withdraw_sum'] ?? 0 );
    $failed    = (float) ( $stats['failed_sum'] ?? 0 );
    $net       = (float) ( $stats['net'] ?? 0 );
    $mix_total = max( 0.01, $deposited + $withdrawn + $pending + $failed );
    $counts    = is_array( $stats['counts'] ?? null ) ? $stats['counts'] : array();

    echo '<div class="scp-analytics">';
    echo '<div class="scp-log-stats">';
    $cards = array(
        array( 'Deposited', scp_admin_money( $deposited ), 'walletType', 'deposit', array( 'status' => 'completed' ) ),
        array( 'Withdrawn', scp_admin_money( $withdrawn ), 'walletType', 'withdraw', array( 'status' => 'completed' ) ),
        array( 'Net in', scp_admin_money( $net ), 'status', 'completed', array( 'walletType' => '' ) ),
        array( 'Players', (string) (int) ( $stats['player_count'] ?? 0 ), 'status', '', array( 'walletType' => '' ) ),
    );
    $current_type   = sanitize_text_field( wp_unslash( $_GET['walletType'] ?? '' ) );
    $current_status = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
    foreach ( $cards as $card ) {
        $active = false;
        if ( $card[2] === 'walletType' ) {
            $active = $current_type === $card[3] && $current_status === 'completed';
        } elseif ( $card[0] === 'Net in' ) {
            $active = $current_status === 'completed' && $current_type === '';
        } elseif ( $card[0] === 'Players' ) {
            $active = $current_type === '' && $current_status === '';
        }
        $extra = array_merge( array( $card[2] => ( $active ? '' : $card[3] ), 'offset' => '' ), $card[4] );
        if ( $card[0] === 'Players' ) {
            $extra = array( 'walletType' => '', 'status' => '', 'offset' => '' );
        }
        echo '<a class="scp-log-stat' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( scp_admin_log_query( 'wallet', $extra ) ) . '" title="Filter the table"><span>' . esc_html( $card[0] ) . '</span><strong>' . esc_html( (string) $card[1] ) . '</strong></a>';
    }
    echo '</div>';

    echo '<p class="scp-muted">';
    echo esc_html( (string) (int) ( $stats['deposit_count'] ?? 0 ) ) . ' completed deposits';
    echo ' · ' . esc_html( (string) (int) ( $stats['withdraw_count'] ?? 0 ) ) . ' completed withdrawals';
    echo ' · pending ' . esc_html( scp_admin_money( $pending ) );
    echo ' (' . esc_html( (string) ( (int) ( $counts['pending_deposit'] ?? 0 ) + (int) ( $counts['pending_withdraw'] ?? 0 ) ) ) . ')';
    echo ' · failed ' . esc_html( (string) (int) ( $stats['failed_count'] ?? 0 ) );
    if ( ( $stats['deposit_count'] ?? 0 ) > 0 ) {
        echo ' · avg deposit ' . esc_html( scp_admin_money( $stats['avg_deposit'] ?? 0 ) );
    }
    if ( ( $stats['withdraw_count'] ?? 0 ) > 0 ) {
        echo ' · avg withdraw ' . esc_html( scp_admin_money( $stats['avg_withdraw'] ?? 0 ) );
    }
    echo '. Totals use every matching wallet row, not just this page.';
    echo '</p>';

    echo '<div class="scp-mix" title="Completed deposits vs withdrawals vs pending vs failed">';
    echo '<i style="width:' . esc_attr( (string) ( ( $deposited / $mix_total ) * 100 ) ) . '%;background:#166534;"></i>';
    echo '<i style="width:' . esc_attr( (string) ( ( $withdrawn / $mix_total ) * 100 ) ) . '%;background:#9a3412;"></i>';
    echo '<i style="width:' . esc_attr( (string) ( ( $pending / $mix_total ) * 100 ) ) . '%;background:#ca8a04;"></i>';
    echo '<i style="width:' . esc_attr( (string) ( ( $failed / $mix_total ) * 100 ) ) . '%;background:#991b1b;"></i>';
    echo '</div>';
    echo '<div class="scp-mix-legend">';
    echo '<span>Deposits ' . esc_html( scp_admin_money( $deposited ) ) . '</span>';
    echo '<span>Withdrawals ' . esc_html( scp_admin_money( $withdrawn ) ) . '</span>';
    echo '<span>Pending ' . esc_html( scp_admin_money( $pending ) ) . '</span>';
    echo '<span>Failed ' . esc_html( scp_admin_money( $failed ) ) . '</span>';
    echo '</div>';

    echo '<div class="scp-analytics-grid">';
    scp_admin_render_game_rank_panel( 'Top depositors', $stats['depositors'] ?? array(), 'total', true );
    scp_admin_render_game_rank_panel( 'Top withdrawals', $stats['withdrawers'] ?? array(), 'total', true );
    scp_admin_render_game_rank_panel( 'By method', $stats['methods'] ?? array(), 'total', true );
    echo '</div></div>';
}

function scp_admin_render_game_analytics( $stats, $trans_type = '' ) {
    $bets    = (float) ( $stats['bets_sum'] ?? 0 );
    $wins    = (float) ( $stats['wins_sum'] ?? 0 );
    $refunds = (float) ( $stats['refunds_sum'] ?? 0 );
    $hold    = (float) ( $stats['hold'] ?? 0 );
    $mix_total = max( 0.01, $bets + $wins + $refunds );
    $sampled = (int) ( $stats['sampled'] ?? 0 );
    $total   = (int) ( $stats['api_total'] ?? 0 );
    $rtp     = $stats['rtp'];
    $trans_type = (string) $trans_type;

    echo '<div class="scp-analytics">';
    echo '<div class="scp-log-stats">';
    $cards = array(
        array( 'Wagered', scp_admin_money( $bets ), '1' ),
        array( 'Wins paid', scp_admin_money( $wins ), '2' ),
        array( 'Hold', scp_admin_money( $hold ), '' ),
        array( 'Players / rounds', (int) ( $stats['player_count'] ?? 0 ) . ' / ' . (int) ( $stats['round_count'] ?? 0 ), '' ),
    );
    foreach ( $cards as $card ) {
        $target = $card[2];
        $active = ( $target !== '' && $trans_type === $target );
        $href   = ( $target === '' )
            ? scp_admin_log_query( 'games', array( 'transType' => '', 'offset' => '' ) )
            : scp_admin_toggle_query( 'games', 'transType', $target );
        echo '<a class="scp-log-stat' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $href ) . '" title="Filter the table"><span>' . esc_html( $card[0] ) . '</span><strong>' . esc_html( (string) $card[1] ) . '</strong></a>';
    }
    echo '</div>';

    echo '<p class="scp-muted">';
    if ( $sampled === 0 ) {
        echo 'No game rounds in this range yet, so the snapshot is empty.';
    } elseif ( $total > $sampled ) {
        echo 'Snapshot from the latest ' . esc_html( (string) $sampled ) . ' of ' . esc_html( (string) $total ) . ' rounds in this range.';
    } else {
        echo 'Snapshot from ' . esc_html( (string) $sampled ) . ' rounds in this range.';
    }
    if ( $rtp !== null ) {
        echo ' RTP ' . esc_html( number_format( (float) $rtp, 1 ) ) . '%. Avg bet ' . esc_html( scp_admin_money( $stats['avg_bet'] ?? 0 ) ) . '.';
    }
    echo ' Click a card to filter the table.';
    echo '</p>';

    echo '<div class="scp-mix" title="Wager vs wins vs refunds">';
    echo '<i style="width:' . esc_attr( (string) ( ( $bets / $mix_total ) * 100 ) ) . '%;background:#9a3412;"></i>';
    echo '<i style="width:' . esc_attr( (string) ( ( $wins / $mix_total ) * 100 ) ) . '%;background:#166534;"></i>';
    echo '<i style="width:' . esc_attr( (string) ( ( $refunds / $mix_total ) * 100 ) ) . '%;background:#334155;"></i>';
    echo '</div>';
    echo '<div class="scp-mix-legend">';
    $legend = array(
        array( 'code' => '1', 'label' => 'Bets', 'amount' => $bets, 'count' => (int) ( $stats['bets_count'] ?? 0 ) ),
        array( 'code' => '2', 'label' => 'Wins', 'amount' => $wins, 'count' => (int) ( $stats['wins_count'] ?? 0 ) ),
        array( 'code' => '3', 'label' => 'Refunds', 'amount' => $refunds, 'count' => (int) ( $stats['refunds_count'] ?? 0 ) ),
    );
    foreach ( $legend as $item ) {
        echo '<a class="' . ( $trans_type === $item['code'] ? 'is-active' : '' ) . '" href="' . esc_url( scp_admin_toggle_query( 'games', 'transType', $item['code'] ) ) . '">' . esc_html( $item['label'] ) . ' ' . esc_html( (string) $item['count'] ) . ' · ' . esc_html( scp_admin_money( $item['amount'] ) ) . '</a>';
    }
    echo '</div>';

    echo '<div class="scp-analytics-grid">';
    scp_admin_render_game_rank_panel( 'Top games', $stats['games'] ?? array(), 'wagered', true );
    scp_admin_render_game_rank_panel( 'Top providers', $stats['providers'] ?? array(), 'wagered', false );
    scp_admin_render_game_rank_panel( 'Top players', $stats['players_top'] ?? array(), 'wagered', false );
    echo '</div></div>';
}

function scp_admin_render_game_rank_panel( $title, $rows, $amount_key, $show_provider ) {
    echo '<div class="scp-panel"><h3>' . esc_html( $title ) . '</h3>';
    echo '<table class="widefat striped"><tbody>';
    if ( empty( $rows ) ) {
        echo '<tr><td>No data in this range.</td></tr>';
    }
    foreach ( $rows as $row ) {
        $label = scp_scalar_string( $row['name'] ?? '' );
        echo '<tr><td>';
        echo esc_html( $label !== '' ? $label : '—' );
        if ( $show_provider && ! empty( $row['provider'] ) ) {
            echo '<br><span class="scp-muted">' . esc_html( $row['provider'] ) . '</span>';
        }
        echo '</td><td style="text-align:right;white-space:nowrap;">' . esc_html( scp_admin_money( $row[ $amount_key ] ?? 0 ) ) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function scp_admin_render_game_log() {
    if ( ! function_exists( 'scp_fetch_transaction_list' ) ) {
        echo '<div class="notice notice-error"><p>Transaction helpers are missing. Reload the ScorpioPlay plugin files and try again.</p></div>';
        return;
    }

    $defaults = scp_transaction_default_times();
    $page_limit = 20;
    $filters  = [
        'operator'         => sanitize_text_field( wp_unslash( $_GET['operator'] ?? '' ) ),
        'transType'        => sanitize_text_field( wp_unslash( $_GET['transType'] ?? '' ) ),
        'startTime'        => sanitize_text_field( wp_unslash( $_GET['startTime'] ?? $defaults['startTime'] ) ),
        'endTime'          => sanitize_text_field( wp_unslash( $_GET['endTime'] ?? $defaults['endTime'] ) ),
        'playerExternalId' => sanitize_text_field( wp_unslash( $_GET['playerExternalId'] ?? '' ) ),
        'roundId'          => sanitize_text_field( wp_unslash( $_GET['roundId'] ?? '' ) ),
        'offset'           => max( 0, absint( $_GET['offset'] ?? 0 ) ),
        'limit'            => $page_limit,
    ];

    $fetch = $filters;
    $fetch['transType'] = '';
    $fetch['offset']    = 0;
    $fetch['limit']     = 100;

    $result = scp_fetch_transaction_list( $fetch );
    if ( ! is_array( $result ) ) {
        $result = array( 'success' => false, 'message' => 'Unable to fetch transactions.', 'list' => array(), 'total' => 0, 'offset' => $filters['offset'] );
    }

    $sample = is_array( $result['list'] ?? null ) ? $result['list'] : array();
    $offset = (int) $filters['offset'];
    $wanted = function_exists( 'scp_game_trans_type_label' ) ? scp_game_trans_type_label( $filters['transType'] ) : '';
    $filtered = $sample;
    if ( $wanted !== '' ) {
        $filtered = array();
        foreach ( $sample as $row ) {
            if ( is_array( $row ) && ( $row['type'] ?? '' ) === $wanted ) {
                $filtered[] = $row;
            }
        }
    }
    $total = count( $filtered );
    $list  = array_slice( $filtered, $offset, $page_limit );
    $start_local = $filters['startTime'] ? str_replace( ' ', 'T', substr( $filters['startTime'], 0, 16 ) ) : '';
    $end_local   = $filters['endTime'] ? str_replace( ' ', 'T', substr( $filters['endTime'], 0, 16 ) ) : '';

    if ( empty( $result['success'] ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html( $result['message'] ?? 'Unable to fetch transactions.' ) . '</p></div>';
    } elseif ( ! empty( $result['stale'] ) ) {
        echo '<div class="notice notice-warning"><p>Scorpio is rate-limiting new requests. Showing the last cached snapshot.</p></div>';
    }

    echo '<form method="get" class="scp-txn-filters">';
    echo '<input type="hidden" name="page" value="scp-logs" />';
    echo '<input type="hidden" name="view" value="games" />';
    echo '<div class="scp-filter-grid">';
    echo '<label>Operator<br><input type="text" name="operator" class="regular-text" value="' . esc_attr( $filters['operator'] ) . '" /></label>';
    echo '<label>Transaction Type<br><select name="transType"><option value="">All</option>';
    foreach ( [ '1' => 'Bet', '2' => 'Win', '3' => 'Refund' ] as $value => $label ) {
        echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['transType'], $value, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Start Time<br><input type="datetime-local" name="startTime" value="' . esc_attr( $start_local ) . '" /></label>';
    echo '<label>End Time<br><input type="datetime-local" name="endTime" value="' . esc_attr( $end_local ) . '" /></label>';
    echo '<label>Player External ID<br><input type="text" name="playerExternalId" class="regular-text" value="' . esc_attr( $filters['playerExternalId'] ) . '" /></label>';
    echo '<label>Round ID<br><input type="text" name="roundId" class="regular-text" value="' . esc_attr( $filters['roundId'] ) . '" /></label>';
    echo '<p><button type="submit" class="button button-primary">Search</button></p>';
    echo '</div></form>';

    if ( function_exists( 'scp_summarize_game_transactions' ) ) {
        $api_total = (int) ( $result['total'] ?? count( $sample ) );
        scp_admin_render_game_analytics( scp_summarize_game_transactions( $sample, $api_total ), $filters['transType'] );
    }

    echo '<p>Showing ' . esc_html( (string) count( $list ) ) . ' of ' . esc_html( (string) $total ) . ' game rounds.</p>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Player</th><th>Round</th><th>Provider</th><th>Game</th><th>Type</th>';
    echo '<th>Amount</th><th>Pre Balance</th><th>Current Balance</th><th>Status</th><th>Created At</th>';
    echo '</tr></thead><tbody>';

    if ( empty( $list ) ) {
        echo '<tr><td colspan="11">No game transactions found for this range.</td></tr>';
    }

    foreach ( $list as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $type   = scp_scalar_string( $row['type'] ?? '' );
        $status = scp_scalar_string( $row['status'] ?? '' );
        echo '<tr>';
        echo '<td>' . esc_html( scp_scalar_string( $row['id'] ?? '' ) ) . '</td>';
        echo '<td>' . esc_html( scp_scalar_string( $row['player'] ?? '' ) ) . '</td>';
        echo '<td>' . esc_html( scp_scalar_string( $row['round'] ?? '' ) ) . '</td>';
        echo '<td>' . esc_html( scp_scalar_string( $row['provider'] ?? '' ) ) . '</td>';
        echo '<td>' . esc_html( scp_scalar_string( $row['game'] ?? '' ) ) . '</td>';
        echo '<td>' . scp_admin_badge( $type, $type ) . '</td>';
        echo '<td>$' . esc_html( number_format( (float) ( $row['amount'] ?? 0 ), 2 ) ) . '</td>';
        echo '<td>$' . esc_html( number_format( (float) ( $row['preBalance'] ?? 0 ), 2 ) ) . '</td>';
        echo '<td>$' . esc_html( number_format( (float) ( $row['currentBalance'] ?? 0 ), 2 ) ) . '</td>';
        echo '<td>' . scp_admin_badge( $status, $status ) . '</td>';
        echo '<td>' . esc_html( scp_admin_format_datetime( $row['createdAt'] ?? '' ) ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    scp_admin_log_pagination( $total, $offset, $filters['limit'] );
}

function scp_withdrawals_page() {
    scp_admin_render_pending_wallet_page( 'withdraw', 'Pending Withdrawal Requests', 'scp-withdrawals' );
}

// Balance Manager (manual adjust)
function scp_balance_page() {
    if ( isset( $_POST['scp_manual_balance'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'scp_manual_balance' ) ) {
        $user_id = intval( $_POST['user_id'] );
        $amount = floatval( $_POST['amount'] );
        $action = sanitize_text_field( $_POST['balance_action'] ); // 'deposit' or 'withdraw'
        $token  = get_user_meta( $user_id, 'scp_token', true );
        $player_id = get_user_meta( $user_id, 'scp_player_id', true );

        $user = get_userdata( $user_id );
        $player_login = $user ? $user->user_login : $player_id;
        $api = new SCP_API_Client();
        $tx_id = 'admin-' . uniqid();
        if ( $action == 'deposit' ) {
            $res = $api->deposit( $player_login, $amount, 'USD', $tx_id );
        } else {
            $res = $api->withdraw( $player_login, $amount, 'USD', $tx_id );
        }

        if ( is_array( $res ) && ! empty( $res['success'] ) ) {
            $data = is_array( $res['data'] ?? null ) ? $res['data'] : array();
            scp_add_transaction( $user_id, $player_login, $action, $amount, 'USD', 'completed', '', $data['transaction_id'] ?? '', $res );
            echo '<div class="notice notice-success"><p>Balance updated.</p></div>';
        } else {
            $message = is_array( $res ) ? ( $res['message'] ?? 'Unknown error' ) : 'Unknown error';
            echo '<div class="notice notice-error"><p>Error: ' . esc_html( $message ) . '</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Manual Balance Adjustment</h1>
        <form method="post">
            <?php wp_nonce_field( 'scp_manual_balance' ); ?>
            <table class="form-table">
                <tr>
                    <th>User</th>
                    <td><input type="text" name="user_id" placeholder="User ID" required></td>
                </tr>
                <tr>
                    <th>Amount</th>
                    <td><input type="number" name="amount" step="0.01" required></td>
                </tr>
                <tr>
                    <th>Action</th>
                    <td>
                        <select name="balance_action">
                            <option value="deposit">Deposit</option>
                            <option value="withdraw">Withdraw</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Submit' ); ?>
        </form>
    </div>
    <?php
}

function scp_render_user_mapping_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions' );
    }

    $msg = '';
    if ( isset( $_GET['scp_msg'] ) ) {
        $msg = sanitize_text_field( wp_unslash( $_GET['scp_msg'] ) );
    }

    $ajax_nonce = wp_create_nonce( 'scp_ajax_nonce' );
    $users = get_users( [
        'role__not_in' => [ 'administrator' ],
        'orderby'      => 'ID',
        'order'        => 'ASC',
    ] );

    $players = [];
    foreach ( $users as $user ) {
        $sync = scp_sync_player_info_for_user( $user );
        $players[] = [
            'id'          => $user->ID,
            'username'    => $user->user_login,
            'email'       => $user->user_email,
            'full_name'   => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
            'player_code' => $sync['player_code'],
            'balance'     => $sync['balance'],
        ];
    }
    ?>
    <div class="wrap">
        <h1>ScorpioPlay Players</h1>

        <?php if ( $msg ) : ?>
            <div id="message" class="updated notice is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
        <?php endif; ?>

        <?php if ( ! empty( $players ) ) : ?>
            <div class="scp-player-list">
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Full Name</th>
                            <th>Player Code</th>
                            <th>Balance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $players as $player ) : ?>
                        <tr>
                            <td><?php echo esc_html( $player['id'] ); ?></td>
                            <td><?php echo esc_html( $player['username'] ); ?></td>
                            <td><?php echo esc_html( $player['email'] ); ?></td>
                            <td><?php echo esc_html( $player['full_name'] ?: 'N/A' ); ?></td>
                            <td><?php echo esc_html( $player['player_code'] ?: 'N/A' ); ?></td>
                            <td><?php echo scp_format_player_balance( $player['balance'] ); ?></td>
                            <td>
                                <button type="button" class="button button-small scp-view-details" data-user-id="<?php echo esc_attr( $player['id'] ); ?>">View Details</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="scp-player-details-modal" class="scp-modal" style="display:none;">
                <div class="scp-modal-overlay"></div>
                <div class="scp-modal-content" style="width: min(800px, 95%);">
                    <h2>Player Details</h2>
                    <button type="button" id="scp-details-close" class="button button-secondary button-small" style="float:right;">Close</button>
                    <div style="clear:both;"></div>
                    <div id="scp-modal-details-content"></div>
                </div>
            </div>

            <style>
                .scp-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9999; }
                .scp-modal-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.5); }
                .scp-modal-content { position: relative; width: min(640px, 95%); margin: 3% auto; background: #fff; padding: 20px; border-radius: 4px; box-shadow: 0 2px 10px rgba(0,0,0,.2); z-index: 1; }
                .scp-modal-content .form-table th { width: 140px; padding: 0.5rem 1rem 0.5rem 0; vertical-align: top; text-align: left; }
                .scp-modal-content .form-table td { padding: 0.5rem; word-break: break-word; }
            </style>

            <script>
            (function($){
                var playersData = <?php echo wp_json_encode( $players, JSON_PRETTY_PRINT ); ?>;

                $(document).on('click', '.scp-view-details', function() {
                    var userId = parseInt( $(this).data('user-id'), 10 );
                    var player = playersData.find(function(item) { return item.id === userId; });
                    if ( ! player ) {
                        return;
                    }

                    var html = '<table class="form-table">';
                    html += '<tr><th>ID</th><td>' + escapeHtml( player.id || 'N/A' ) + '</td></tr>';
                    html += '<tr><th>Username</th><td>' + escapeHtml( player.username || 'N/A' ) + '</td></tr>';
                    html += '<tr><th>Email</th><td>' + escapeHtml( player.email || 'N/A' ) + '</td></tr>';
                    html += '<tr><th>Full Name</th><td>' + escapeHtml( player.full_name || 'N/A' ) + '</td></tr>';
                    html += '<tr><th>Player Code</th><td>' + escapeHtml( player.player_code || 'N/A' ) + '</td></tr>';

                    if ( player.balance && Array.isArray( player.balance ) && player.balance.length > 0 ) {
                        var balances = player.balance.map(function(entry) {
                            return escapeHtml( ( entry.currency || 'N/A' ) + ': ' + ( entry.amount || '0' ) );
                        }).join('<br />');
                        html += '<tr><th>Balance</th><td>' + balances + '</td></tr>';
                    } else {
                        html += '<tr><th>Balance</th><td>N/A</td></tr>';
                    }

                    html += '</table>';
                    $('#scp-modal-details-content').html(html);
                    $('#scp-player-details-modal').show();
                });

                $(document).on('click', '#scp-details-close, .scp-modal-overlay', function(e) {
                    if ( e.target === this || $(this).is('#scp-details-close') ) {
                        $('#scp-player-details-modal').hide();
                    }
                });

                function escapeHtml(text) {
                    var map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    };
                    if ( typeof text === 'string' ) {
                        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
                    }
                    return text;
                }
            })(jQuery);
            </script>

        <?php else : ?>
            <div class="notice notice-warning"><p>No ScorpioPlay players found.</p></div>
        <?php endif; ?>

    </div>
    <?php
}