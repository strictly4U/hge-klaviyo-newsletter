<?php
/**
 * Logger class for HgE Automated Post Campaigns for Klaviyo.
 *
 * PSR-3 compatible levels, written to WooCommerce's logger so entries
 * appear in WooCommerce → Status → Logs filtered by source `hge-klaviyo`.
 * Falls back to PHP error_log() when WooCommerce's logger isn't available
 * (defensive — WooCommerce is a hard requirement so this rarely fires).
 *
 * Log levels (most → least severe):
 * - emergency / alert / critical : always logged
 * - error / warning              : always logged
 * - notice / info / debug        : logged only when the plugin's "Debug mode"
 *                                  setting is on (Tools → Klaviyo Newsletter →
 *                                  Settings → Debug mode). The single switch
 *                                  also unlocks the Status tab + credential
 *                                  display in the admin UI.
 *
 * Mirrors the pattern used by HgE FAN Courier (Standard) — same admin
 * filtering UX across the HgE plugin family.
 *
 * @package HgE\KlaviyoNewsletter
 * @since 3.0.13 — WooCommerce-logger migration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'HgE_Klaviyo_Logger' ) ) {

    class HgE_Klaviyo_Logger {

        /**
         * WooCommerce log source identifier — appears in the dropdown at
         * WooCommerce → Status → Logs and as the prefix on each log file
         * name (e.g. `hge-klaviyo-2026-05-24-<hash>.log`).
         */
        const LOG_SOURCE = 'hge-klaviyo';

        /**
         * Whether DEBUG / INFO / NOTICE entries get written. ERROR/WARNING
         * always go through regardless. Bound to the same `debug_mode`
         * flag the Settings UI already exposes — one switch unlocks the
         * Status tab, credential display, and verbose logging together.
         */
        public static function is_debug_enabled() {
            $settings = function_exists( 'hge_klaviyo_nl_get_settings' )
                ? hge_klaviyo_nl_get_settings()
                : (array) get_option( 'hge_klaviyo', array() );
            return ! empty( $settings['debug_mode'] );
        }

        /**
         * Strip secrets from a context array before it lands in the log.
         * The plugin handles a Klaviyo Private API key, a feed token, and
         * (Pro) license keys + webhook HMAC secret — none of those should
         * ever appear in the log files customers paste into support.
         */
        private static function sanitize_context( array $context ) {
            $sensitive_keys = array(
                'api_key', 'apiKey', 'apikey',
                'feed_token', 'feedToken',
                'license_key', 'licenseKey',
                'webhook_secret', 'webhookSecret',
                'authorization', 'Authorization',
                'password',
                'secret',
                'token',
            );
            foreach ( $sensitive_keys as $key ) {
                if ( isset( $context[ $key ] ) ) {
                    $context[ $key ] = '***';
                }
            }
            return $context;
        }

        private static function format_message( $message, array $context ) {
            $context = self::sanitize_context( $context );
            $msg     = is_string( $message ) ? $message : wp_json_encode( $message );
            $ctx     = empty( $context ) ? '' : ( ' | ' . wp_json_encode( $context ) );
            return '[HgE Klaviyo] ' . $msg . $ctx;
        }

        /**
         * Internal helper — write to WooCommerce's logger when available,
         * fall back to PHP error_log otherwise. The log() / debug() / etc.
         * wrappers below delegate here so the level + source contract stays
         * in one place.
         */
        private static function write( $level, $message, array $context = array() ) {
            $formatted = self::format_message( $message, $context );

            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                if ( $logger && method_exists( $logger, $level ) ) {
                    $logger->{$level}( $formatted, array( 'source' => self::LOG_SOURCE ) );
                    return;
                }
            }

            // Defensive fallback — WC is a required plugin, so we should
            // basically never end up here, but keeping a path means
            // critical/error messages still surface during plugin
            // activation before WC's logger is registered.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $formatted );
        }

        /** Gated by debug toggle. */
        public static function log( $message, array $context = array() ) {
            if ( ! self::is_debug_enabled() ) {
                return;
            }
            self::write( 'info', $message, $context );
        }

        /** Alias for log() — PSR-3 spelling. */
        public static function info( $message, array $context = array() ) {
            self::log( $message, $context );
        }

        /** Gated by debug toggle. */
        public static function debug( $message, array $context = array() ) {
            if ( ! self::is_debug_enabled() ) {
                return;
            }
            self::write( 'debug', $message, $context );
        }

        /** Gated by debug toggle. */
        public static function notice( $message, array $context = array() ) {
            if ( ! self::is_debug_enabled() ) {
                return;
            }
            self::write( 'notice', $message, $context );
        }

        /** Always logged. */
        public static function warning( $message, array $context = array() ) {
            self::write( 'warning', $message, $context );
        }

        /** Always logged. */
        public static function error( $message, array $context = array() ) {
            self::write( 'error', $message, $context );
        }

        /** Always logged. */
        public static function critical( $message, array $context = array() ) {
            self::write( 'critical', $message, $context );
        }

        /** Always logged. */
        public static function alert( $message, array $context = array() ) {
            self::write( 'alert', $message, $context );
        }

        /** Always logged. */
        public static function emergency( $message, array $context = array() ) {
            self::write( 'emergency', $message, $context );
        }
    }
}
