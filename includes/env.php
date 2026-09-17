<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Load KEY=VALUE pairs from a .env file without overriding existing env/constants.
 */
function scp_load_dotenv() {
    static $loaded = false;
    if ( $loaded ) {
        return;
    }
    $loaded = true;

    $candidates = array(
        SCP_PLUGIN_DIR . '.env',
    );
    if ( defined( 'ABSPATH' ) ) {
        $candidates[] = ABSPATH . '.env';
    }
    if ( defined( 'WP_CONTENT_DIR' ) ) {
        $candidates[] = dirname( WP_CONTENT_DIR ) . '/.env';
    }

    foreach ( $candidates as $file ) {
        if ( is_readable( $file ) ) {
            scp_parse_dotenv_file( $file );
            return;
        }
    }
}

function scp_parse_dotenv_file( $file ) {
    $lines = file( $file, FILE_IGNORE_NEW_LINES );
    if ( ! is_array( $lines ) ) {
        return;
    }

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line === '' || strpos( $line, '#' ) === 0 ) {
            continue;
        }
        if ( strpos( $line, '=' ) === false ) {
            continue;
        }
        list( $key, $value ) = explode( '=', $line, 2 );
        $key   = trim( $key );
        $value = trim( $value );
        if ( $key === '' ) {
            continue;
        }
        if (
            ( strlen( $value ) >= 2 && $value[0] === '"' && substr( $value, -1 ) === '"' )
            || ( strlen( $value ) >= 2 && $value[0] === "'" && substr( $value, -1 ) === "'" )
        ) {
            $value = substr( $value, 1, -1 );
        }
        if ( scp_env( $key, null ) !== null ) {
            continue;
        }
        putenv( $key . '=' . $value );
        $_ENV[ $key ]    = $value;
        $_SERVER[ $key ] = $value;
    }
}

/**
 * Read config from wp-config constants, then environment / .env.
 *
 * @param string      $key
 * @param string|null $default  Pass null to detect "not set".
 * @return string|null
 */
function scp_env( $key, $default = '' ) {
    if ( defined( $key ) ) {
        $const = constant( $key );
        if ( $const !== null && $const !== false && $const !== '' ) {
            return (string) $const;
        }
    }

    $from_getenv = getenv( $key );
    if ( $from_getenv !== false && $from_getenv !== '' ) {
        return (string) $from_getenv;
    }
    if ( isset( $_ENV[ $key ] ) && $_ENV[ $key ] !== '' ) {
        return (string) $_ENV[ $key ];
    }
    if ( isset( $_SERVER[ $key ] ) && $_SERVER[ $key ] !== '' ) {
        return (string) $_SERVER[ $key ];
    }

    return $default;
}

function scp_bep20_normalize_mode( $mode ) {
    return 'live';
}

function scp_bep20_mode_from_config() {
    return 'live';
}

function scp_bep20_mode_from_env() {
    return 'live';
}

function scp_bep20_mode() {
    return 'live';
}
