<?php
/**
 * Minimal secp256k1 + RLP helpers for signing BSC legacy transactions.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SCP_Eth_Math {
    public static function available() {
        return function_exists( 'gmp_add' ) || function_exists( 'bcadd' );
    }

    public static function hex2dec( $hex ) {
        $hex = strtolower( preg_replace( '/^0x/i', '', (string) $hex ) );
        $hex = ltrim( $hex, '0' );
        if ( $hex === '' ) {
            return '0';
        }
        if ( function_exists( 'gmp_init' ) ) {
            return gmp_strval( gmp_init( $hex, 16 ), 10 );
        }
        $dec = '0';
        $len = strlen( $hex );
        for ( $i = 0; $i < $len; $i++ ) {
            $dec = bcmul( $dec, '16', 0 );
            $dec = bcadd( $dec, (string) hexdec( $hex[ $i ] ), 0 );
        }
        return $dec;
    }

    public static function dec2hex( $dec ) {
        $dec = ltrim( (string) $dec, '+' );
        if ( $dec === '' || $dec === '0' ) {
            return '0';
        }
        if ( function_exists( 'gmp_init' ) ) {
            return gmp_strval( gmp_init( $dec, 10 ), 16 );
        }
        $hex = '';
        while ( bccomp( $dec, '0', 0 ) > 0 ) {
            $hex = dechex( (int) bcmod( $dec, '16' ) ) . $hex;
            $dec = bcdiv( $dec, '16', 0 );
        }
        return $hex === '' ? '0' : $hex;
    }

    public static function add( $a, $b ) {
        return function_exists( 'gmp_add' ) ? gmp_strval( gmp_add( $a, $b ) ) : bcadd( $a, $b, 0 );
    }

    public static function sub( $a, $b ) {
        return function_exists( 'gmp_sub' ) ? gmp_strval( gmp_sub( $a, $b ) ) : bcsub( $a, $b, 0 );
    }

    public static function mul( $a, $b ) {
        return function_exists( 'gmp_mul' ) ? gmp_strval( gmp_mul( $a, $b ) ) : bcmul( $a, $b, 0 );
    }

    public static function div( $a, $b ) {
        return function_exists( 'gmp_div_q' ) ? gmp_strval( gmp_div_q( $a, $b ) ) : bcdiv( $a, $b, 0 );
    }

    public static function mod( $a, $b ) {
        return function_exists( 'gmp_mod' ) ? gmp_strval( gmp_mod( $a, $b ) ) : bcmod( $a, $b );
    }

    public static function cmp( $a, $b ) {
        return function_exists( 'gmp_cmp' ) ? gmp_cmp( $a, $b ) : bccomp( $a, $b, 0 );
    }

    public static function powmod( $base, $exp, $mod ) {
        if ( function_exists( 'gmp_powm' ) ) {
            return gmp_strval( gmp_powm( $base, $exp, $mod ) );
        }
        return bcpowmod( $a = $base, $exp, $mod, 0 );
    }

    public static function invert( $a, $n ) {
        if ( function_exists( 'gmp_invert' ) ) {
            $inv = gmp_invert( $a, $n );
            return $inv === false ? null : gmp_strval( $inv );
        }
        return self::powmod( $a, self::sub( $n, '2' ), $n );
    }

    public static function and1( $a ) {
        if ( function_exists( 'gmp_and' ) ) {
            return gmp_strval( gmp_and( $a, '1' ) );
        }
        return bcmod( $a, '2' );
    }

    public static function rshift1( $a ) {
        return self::div( $a, '2' );
    }

    public static function pad_hex( $hex, $bytes ) {
        $hex = strtolower( preg_replace( '/^0x/i', '', $hex ) );
        return str_pad( $hex, $bytes * 2, '0', STR_PAD_LEFT );
    }

    public static function hex2bin_even( $hex ) {
        $hex = strtolower( preg_replace( '/^0x/i', '', $hex ) );
        if ( strlen( $hex ) % 2 ) {
            $hex = '0' . $hex;
        }
        if ( $hex === '' ) {
            return '';
        }
        return hex2bin( $hex );
    }
}

final class SCP_Secp256k1 {
    const P  = '115792089237316195423570985008687907853269984665640564039457584007908834671663';
    const N  = '115792089237316195423570985008687907852837564279074904382605163141518161494337';
    const GX = '55066263022277343669578718895168534326250603453777594175500187360389116729240';
    const GY = '32670510020758816978083085130507043184471273380659243275938904335757337482424';

    public static function keccak( $bin ) {
        return SCP_Keccak::hash256( $bin, true );
    }

    public static function private_to_address( $private_key_hex ) {
        $pub = self::private_to_public( $private_key_hex );
        if ( ! $pub ) {
            return '';
        }
        $hash = self::keccak( hex2bin( $pub ) );
        return '0x' . substr( bin2hex( $hash ), -40 );
    }

    public static function private_to_public( $private_key_hex ) {
        $d = SCP_Eth_Math::hex2dec( $private_key_hex );
        if ( SCP_Eth_Math::cmp( $d, '0' ) <= 0 || SCP_Eth_Math::cmp( $d, self::N ) >= 0 ) {
            return '';
        }
        $p = self::mul( [ self::GX, self::GY ], $d );
        if ( ! $p ) {
            return '';
        }
        return SCP_Eth_Math::pad_hex( SCP_Eth_Math::dec2hex( $p[0] ), 32 ) . SCP_Eth_Math::pad_hex( SCP_Eth_Math::dec2hex( $p[1] ), 32 );
    }

    public static function sign( $hash32, $private_key_hex ) {
        $z = SCP_Eth_Math::hex2dec( bin2hex( $hash32 ) );
        if ( SCP_Eth_Math::cmp( $z, self::N ) >= 0 ) {
            $z = SCP_Eth_Math::sub( $z, self::N );
        }
        $d = SCP_Eth_Math::hex2dec( $private_key_hex );
        $k = self::rfc6979_k( $hash32, $private_key_hex );

        $attempts = 0;
        while ( $attempts < 16 ) {
            $attempts++;
            if ( SCP_Eth_Math::cmp( $k, '0' ) <= 0 || SCP_Eth_Math::cmp( $k, self::N ) >= 0 ) {
                $k = self::mod_add_one( $k );
                continue;
            }
            $p = self::mul( [ self::GX, self::GY ], $k );
            if ( ! $p ) {
                $k = self::mod_add_one( $k );
                continue;
            }
            $r = SCP_Eth_Math::mod( $p[0], self::N );
            if ( SCP_Eth_Math::cmp( $r, '0' ) === 0 ) {
                $k = self::mod_add_one( $k );
                continue;
            }
            $k_inv = SCP_Eth_Math::invert( $k, self::N );
            $s     = SCP_Eth_Math::mod( SCP_Eth_Math::mul( $k_inv, SCP_Eth_Math::add( $z, SCP_Eth_Math::mul( $r, $d ) ) ), self::N );
            if ( SCP_Eth_Math::cmp( $s, '0' ) === 0 ) {
                $k = self::mod_add_one( $k );
                continue;
            }

            $recid = ( SCP_Eth_Math::and1( $p[1] ) === '1' ) ? 1 : 0;
            if ( SCP_Eth_Math::cmp( $p[0], self::N ) >= 0 ) {
                $recid += 2;
            }

            $half_n = SCP_Eth_Math::div( self::N, '2' );
            if ( SCP_Eth_Math::cmp( $s, $half_n ) > 0 ) {
                $s     = SCP_Eth_Math::sub( self::N, $s );
                $recid ^= 1;
            }

            return [
                'r'     => SCP_Eth_Math::pad_hex( SCP_Eth_Math::dec2hex( $r ), 32 ),
                's'     => SCP_Eth_Math::pad_hex( SCP_Eth_Math::dec2hex( $s ), 32 ),
                'recid' => $recid,
            ];
        }

        return null;
    }

    private static function mod_add_one( $k ) {
        return SCP_Eth_Math::mod( SCP_Eth_Math::add( $k, '1' ), self::N );
    }

    private static function rfc6979_k( $hash32, $private_key_hex ) {
        $x = SCP_Eth_Math::hex2bin_even( SCP_Eth_Math::pad_hex( $private_key_hex, 32 ) );
        $h1 = $hash32;
        $v  = str_repeat( "\x01", 32 );
        $k  = str_repeat( "\x00", 32 );
        $k  = hash_hmac( 'sha256', $v . "\x00" . $x . $h1, $k, true );
        $v  = hash_hmac( 'sha256', $v, $k, true );
        $k  = hash_hmac( 'sha256', $v . "\x01" . $x . $h1, $k, true );
        $v  = hash_hmac( 'sha256', $v, $k, true );

        for ( $i = 0; $i < 32; $i++ ) {
            $t = '';
            while ( strlen( $t ) < 32 ) {
                $v = hash_hmac( 'sha256', $v, $k, true );
                $t .= $v;
            }
            $t = substr( $t, 0, 32 );
            $cand = SCP_Eth_Math::hex2dec( bin2hex( $t ) );
            if ( SCP_Eth_Math::cmp( $cand, '0' ) > 0 && SCP_Eth_Math::cmp( $cand, self::N ) < 0 ) {
                return $cand;
            }
            $k = hash_hmac( 'sha256', $v . "\x00", $k, true );
            $v = hash_hmac( 'sha256', $v, $k, true );
        }

        return '1';
    }

    private static function add_points( $p, $q ) {
        if ( ! $p ) {
            return $q;
        }
        if ( ! $q ) {
            return $p;
        }
        $p_mod = self::P;
        if ( SCP_Eth_Math::cmp( $p[0], $q[0] ) === 0 ) {
            if ( SCP_Eth_Math::cmp( $p[1], $q[1] ) !== 0 ) {
                return null;
            }
            return self::double( $p );
        }
        $dy  = SCP_Eth_Math::mod( SCP_Eth_Math::sub( SCP_Eth_Math::add( $q[1], $p_mod ), $p[1] ), $p_mod );
        $dx  = SCP_Eth_Math::mod( SCP_Eth_Math::sub( SCP_Eth_Math::add( $q[0], $p_mod ), $p[0] ), $p_mod );
        $inv = SCP_Eth_Math::invert( $dx, $p_mod );
        $s   = SCP_Eth_Math::mod( SCP_Eth_Math::mul( $dy, $inv ), $p_mod );
        $x   = SCP_Eth_Math::mod( SCP_Eth_Math::sub( SCP_Eth_Math::sub( SCP_Eth_Math::mul( $s, $s ), $p[0] ), $q[0] ), $p_mod );
        $y   = SCP_Eth_Math::mod( SCP_Eth_Math::sub( SCP_Eth_Math::mul( $s, SCP_Eth_Math::sub( $p[0], $x ) ), $p[1] ), $p_mod );
        return [ $x, $y ];
    }

    private static function double( $p ) {
        if ( ! $p || SCP_Eth_Math::cmp( $p[1], '0' ) === 0 ) {
            return null;
        }
        $p_mod = self::P;
        $l     = SCP_Eth_Math::mod( SCP_Eth_Math::mul( '3', SCP_Eth_Math::mul( $p[0], $p[0] ) ), $p_mod );
        $inv   = SCP_Eth_Math::invert( SCP_Eth_Math::mod( SCP_Eth_Math::mul( '2', $p[1] ), $p_mod ), $p_mod );
        $s     = SCP_Eth_Math::mod( SCP_Eth_Math::mul( $l, $inv ), $p_mod );
        $x     = SCP_Eth_Math::mod( SCP_Eth_Math::sub( SCP_Eth_Math::sub( SCP_Eth_Math::mul( $s, $s ), $p[0] ), $p[0] ), $p_mod );
        $y     = SCP_Eth_Math::mod( SCP_Eth_Math::sub( SCP_Eth_Math::mul( $s, SCP_Eth_Math::sub( $p[0], $x ) ), $p[1] ), $p_mod );
        return [ $x, $y ];
    }

    private static function mul( $p, $k ) {
        $n   = $k;
        $r   = null;
        $acc = $p;
        while ( SCP_Eth_Math::cmp( $n, '0' ) > 0 ) {
            if ( SCP_Eth_Math::and1( $n ) === '1' ) {
                $r = self::add_points( $r, $acc );
            }
            $acc = self::double( $acc );
            $n   = SCP_Eth_Math::rshift1( $n );
        }
        return $r;
    }
}

final class SCP_Rlp {
    public static function encode( $input ) {
        if ( is_array( $input ) ) {
            $out = '';
            foreach ( $input as $item ) {
                $out .= self::encode( $item );
            }
            return self::encode_length( strlen( $out ), 0xc0 ) . $out;
        }

        $bin = (string) $input;
        if ( strlen( $bin ) === 1 && ord( $bin ) < 0x80 ) {
            return $bin;
        }
        return self::encode_length( strlen( $bin ), 0x80 ) . $bin;
    }

    private static function encode_length( $len, $offset ) {
        if ( $len < 56 ) {
            return chr( $len + $offset );
        }
        $hex    = ltrim( dechex( $len ), '0' );
        $hex    = ( strlen( $hex ) % 2 ) ? '0' . $hex : $hex;
        $lenlen = strlen( $hex ) / 2;
        return chr( $lenlen + $offset + 55 ) . hex2bin( $hex );
    }

    public static function int_bin( $value ) {
        $value = (string) $value;
        if ( preg_match( '/^0x/i', $value ) ) {
            $hex = strtolower( preg_replace( '/^0x/i', '', $value ) );
        } else {
            $hex = SCP_Eth_Math::dec2hex( $value );
        }
        $hex = ltrim( $hex, '0' );
        if ( $hex === '' ) {
            return '';
        }
        if ( strlen( $hex ) % 2 ) {
            $hex = '0' . $hex;
        }
        return hex2bin( $hex );
    }

    public static function addr_bin( $address ) {
        $hex = strtolower( preg_replace( '/^0x/i', '', $address ) );
        $hex = str_pad( $hex, 40, '0', STR_PAD_LEFT );
        return hex2bin( $hex );
    }
}

final class SCP_Eth_Tx {
    public static function sign_legacy( $nonce, $gas_price, $gas_limit, $to, $value, $data, $private_key, $chain_id ) {
        $nonce_bin     = SCP_Rlp::int_bin( $nonce );
        $gas_price_bin = SCP_Rlp::int_bin( $gas_price );
        $gas_limit_bin = SCP_Rlp::int_bin( $gas_limit );
        $to_bin        = SCP_Rlp::addr_bin( $to );
        $value_bin     = SCP_Rlp::int_bin( $value );
        $data_bin      = is_string( $data ) && preg_match( '/^0x/i', $data ) ? SCP_Eth_Math::hex2bin_even( $data ) : (string) $data;

        $unsigned = SCP_Rlp::encode( [
            $nonce_bin,
            $gas_price_bin,
            $gas_limit_bin,
            $to_bin,
            $value_bin,
            $data_bin,
            SCP_Rlp::int_bin( (string) (int) $chain_id ),
            '',
            '',
        ] );

        $hash = SCP_Secp256k1::keccak( $unsigned );
        $sig  = SCP_Secp256k1::sign( $hash, $private_key );
        if ( ! $sig ) {
            return '';
        }

        $v = ( (int) $chain_id * 2 ) + 35 + (int) $sig['recid'];
        $raw = SCP_Rlp::encode( [
            $nonce_bin,
            $gas_price_bin,
            $gas_limit_bin,
            $to_bin,
            $value_bin,
            $data_bin,
            SCP_Rlp::int_bin( (string) $v ),
            SCP_Eth_Math::hex2bin_even( $sig['r'] ),
            SCP_Eth_Math::hex2bin_even( $sig['s'] ),
        ] );

        return '0x' . bin2hex( $raw );
    }
}
