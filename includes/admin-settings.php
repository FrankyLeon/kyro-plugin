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
        'Transaction Log',
        'Transaction Log',
        'manage_options',
        'scp-logs',
        'scp_logs_page'
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
                Bank, crypto, and PayPal receiving details are managed on
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
}

function scp_deposits_page() {
    scp_admin_render_pending_wallet_page( 'deposit', 'Pending Deposit Requests', 'scp-deposits' );
}

function scp_logs_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'scp_transactions';
    $logs  = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC LIMIT 200" );
    echo '<div class="wrap"><h1>Transaction Log</h1>';
    echo '<p>All deposit and withdraw requests, including pending, completed, failed, and rejected.</p>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>User</th><th>Type</th><th>Amount</th><th>Status</th><th>Method / details</th><th>SCP Txn</th><th>Date</th></tr></thead><tbody>';
    foreach ( $logs as $row ) {
        $meta    = scp_decode_transaction_response( $row );
        $details = scp_format_wallet_details( $meta );
        echo '<tr>';
        echo '<td>' . esc_html( $row->id ) . '<br><code>' . esc_html( $row->txn_id ) . '</code></td>';
        echo '<td>' . esc_html( scp_admin_user_label( $row ) ) . '</td>';
        echo '<td>' . esc_html( scp_normalize_wallet_type( $row->type ) ) . '</td>';
        echo '<td>' . esc_html( $row->amount ) . ' ' . esc_html( $row->currency ) . '</td>';
        echo '<td>' . esc_html( $row->status ) . '</td>';
        echo '<td>' . esc_html( $details ?: ( $row->gateway_txn_id ?: '—' ) ) . '</td>';
        echo '<td>' . esc_html( $row->scp_txn_id ) . '</td>';
        echo '<td>' . esc_html( $row->created_at ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
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

        if ( $res['success'] ) {
            scp_add_transaction( $user_id, $player_login, $action, $amount, 'USD', 'completed', '', $res['data']['transaction_id'] ?? '', $res );
            echo '<div class="notice notice-success"><p>Balance updated.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html( $res['message'] ) . '</p></div>';
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