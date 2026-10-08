<?php
/**
 * Plugin Name:  Nowera ZiziCache Guard
 * Description:  Zastaví self-crawl ZiziCache, ktorý sa nedá vypnúť v nastaveniach: preload po purge a LiteSpeed status checker („Pages Not Cached“).
 * Version:      1.0.0
 * Author:       Nowera
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 *
 * Install as a must-use plugin: wp-content/mu-plugins/nowera-zizicache-guard.php
 *
 * Why (verified in ZiziCache 1.0.11, 2026-09-14):
 * - The WooCommerce integration, AutomaticallyClear and PageBuilders call Preload::preload_urls()
 *   or Preload::preload_url() after every purge without checking cache_preload. With async preload
 *   off, the crawl (5 URLs x desktop + mobile, blocking 30 s timeouts) runs inside the request that
 *   saves the product, and the PHP pool runs out of workers.
 * - With cache_mode=litespeed, CacheUrlsTracker checks tracked URLs with a blocking GET
 *   (UA "ZiziCache/1.0 LiteSpeedCacheChecker") on dashboard polling, Pages Not Cached sync and
 *   save_post. Every uncached URL is a full PHP render.
 *
 * Optional wp-config.php constants:
 *   define( 'NWR_ZIZI_GUARD', false );      Turn the guard off.
 *   define( 'NWR_ZIZI_PRELOAD', 'auto' );   auto  (default) block page preloads unless "Cache Preloading" is on;
 *                                                  stale refresh of the file cache stays allowed.
 *                                           block block every preload request, stale refresh included.
 *                                           allow never block preloads.
 *   define( 'NWR_ZIZI_TRACKER', 'block' );  block (default) answer the LiteSpeed status checker locally.
 *                                           allow let the checker request pages.
 *
 * Filters: nwr_zizi_guard_block_preload( bool $block, bool $stale_refresh )
 *          nwr_zizi_guard_block_tracker( bool $block )
 * Audit:   nwr_zizi_guard_audit() returns the guard state and risky ZiziCache settings.
 *
 * Not covered: LiteSpeed parallel preload (curl_multi bypasses the WP HTTP API). Keep it off.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'NWR_ZIZI_GUARD' ) && false === NWR_ZIZI_GUARD ) {
	return;
}

// Declared conditionally so a second copy of this file (e.g. also installed as a plugin) is a no-op.
if ( ! class_exists( 'Nwr_Zizi_Guard', false ) ) :

	final class Nwr_Zizi_Guard {

		const VERSION = '1.0.0';

		public static function init() {
			add_filter( 'pre_http_request', array( __CLASS__, 'filter_http' ), 1, 3 );
			add_filter( 'zizi_cache_preload_variant_delay', array( __CLASS__, 'filter_variant_delay' ), 99 );
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		}

		/**
		 * Short-circuits ZiziCache's requests to its own site.
		 *
		 * @param false|array|WP_Error $pre  Response from an earlier filter, false to continue.
		 * @param array                $args Request arguments.
		 * @param string               $url  Request URL.
		 * @return false|array|WP_Error
		 */
		public static function filter_http( $pre, $args, $url ) {
			if ( false !== $pre || ! is_array( $args ) ) {
				return $pre;
			}

			// Page preload (Preload.php) and stale-cache refresh (PreloadSqlite.php, adds X-Zizi-Warmed).
			if ( self::has_header( $args, 'X-Zizi-Preload' ) ) {
				$stale_refresh = self::has_header( $args, 'X-Zizi-Warmed' );
				if ( ! self::block_preload( $stale_refresh ) ) {
					return $pre;
				}
				if ( ! $stale_refresh ) {
					self::drain_queue();
				}
				return new WP_Error( 'nwr_zizi_preload_blocked', 'blocked by Nowera ZiziCache Guard' );
			}

			// Async preload: non-blocking self-ping that would process the queue in another PHP worker.
			if ( is_string( $url ) && false !== strpos( $url, 'action=zizi_cache_process_preload_queue' ) ) {
				if ( ! self::block_preload( false ) ) {
					return $pre;
				}
				self::drain_queue();
				return self::response( 200 );
			}

			// LiteSpeed status checker: HTTP 200 without LiteSpeed headers is recorded as "unknown"
			// and ZiziCache logs it only at warning level.
			if ( false !== stripos( self::user_agent( $args ), 'LiteSpeedCacheChecker' ) && self::block_tracker() ) {
				return self::response( 200 );
			}

			return $pre;
		}

		/**
		 * Drops the 0.5 s pause between desktop and mobile preloads when they are blocked anyway.
		 *
		 * @param float|int $delay Seconds.
		 * @return float|int
		 */
		public static function filter_variant_delay( $delay ) {
			$stale_refresh = doing_action( 'zizi_cache_preload_sqlite_worker' );
			return self::block_preload( $stale_refresh ) ? 0 : $delay;
		}

		/**
		 * @param bool $stale_refresh True for PreloadSqlite's refresh of expired file cache.
		 * @return bool
		 */
		public static function block_preload( $stale_refresh ) {
			switch ( self::preload_mode() ) {
				case 'allow':
					$block = false;
					break;
				case 'block':
					$block = true;
					break;
				default:
					$config = self::zizi_config();
					$block  = ! $stale_refresh && empty( $config['cache_preload'] );
			}
			return (bool) apply_filters( 'nwr_zizi_guard_block_preload', $block, (bool) $stale_refresh );
		}

		public static function block_tracker() {
			$mode = defined( 'NWR_ZIZI_TRACKER' ) ? strtolower( (string) NWR_ZIZI_TRACKER ) : 'block';
			return (bool) apply_filters( 'nwr_zizi_guard_block_tracker', 'allow' !== $mode );
		}

		public static function preload_mode() {
			$mode = defined( 'NWR_ZIZI_PRELOAD' ) ? strtolower( (string) NWR_ZIZI_PRELOAD ) : 'auto';
			return in_array( $mode, array( 'auto', 'block', 'allow' ), true ) ? $mode : 'auto';
		}

		/**
		 * Live ZiziCache config, falling back to the stored option.
		 *
		 * @return array
		 */
		public static function zizi_config() {
			try {
				if ( class_exists( 'ZiziCache\SysConfig', false ) && property_exists( 'ZiziCache\SysConfig', 'config' ) && is_array( \ZiziCache\SysConfig::$config ) ) {
					return \ZiziCache\SysConfig::$config;
				}
			} catch ( \Throwable $e ) {
				// Different ZiziCache internals; use the stored option.
			}
			$config = get_option( 'ZIZI_CACHE_CONFIG' );
			return is_array( $config ) ? $config : array();
		}

		/**
		 * Guard state plus ZiziCache settings that the guard cannot cover.
		 *
		 * @return array
		 */
		public static function audit() {
			$loaded   = defined( 'ZIZI_CACHE_CACHE_DIR' );
			$config   = self::zizi_config();
			$warnings = array();

			if ( $loaded ) {
				if ( ! empty( $config['cache_preload'] ) ) {
					$warnings['cache_preload'] = 'Cache Preloading je zapnutý, takže guard v režime auto nechá bežať aj preload po purge. Na zdieľanom hostingu ho vypni.';
				}
				if ( ! empty( $config['litespeed_preload_enabled'] ) ) {
					$warnings['litespeed_preload_enabled'] = 'LiteSpeed preload je zapnutý. Beží cez curl_multi a guard ho nezastaví. Vypni ho.';
				}
				if ( ! empty( $config['self_host_third_party_css_js'] ) ) {
					$warnings['self_host_third_party_css_js'] = 'Self-host 3rd-party CSS/JS je zapnutý. Bežný JS zablokuje validátor ZiziCache a každý necachovaný render ho sťahuje znova. Vypni ho.';
				}
				if ( isset( $config['cache_mode'] ) && 'litespeed' === $config['cache_mode'] && ! empty( $config['cache_separate_mobile'] ) ) {
					$warnings['cache_separate_mobile'] = 'Samostatná mobilná cache v LiteSpeed móde nevytvorí mobilnú variantu, len zdvojnásobí preload. Vypni ju.';
				}
			}

			$recommendations = array();
			if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
				$recommendations['wp_cron'] = 'WP-Cron sa spúšťa návštevami. Nastav serverový cron každých 5 minút a potom DISABLE_WP_CRON.';
			}

			return array(
				'guard'           => array(
					'version'               => self::VERSION,
					'preload_mode'          => self::preload_mode(),
					'page_preload_blocked'  => self::block_preload( false ),
					'stale_refresh_blocked' => self::block_preload( true ),
					'tracker_blocked'       => self::block_tracker(),
				),
				'zizicache'       => array(
					'loaded'     => $loaded,
					'version'    => self::zizi_version(),
					'cache_mode' => isset( $config['cache_mode'] ) ? $config['cache_mode'] : null,
				),
				'warnings'        => $warnings,
				'recommendations' => $recommendations,
			);
		}

		/**
		 * Lists risky settings on the Plugins screen and ZiziCache admin pages.
		 */
		public static function admin_notice() {
			if ( ! defined( 'ZIZI_CACHE_CACHE_DIR' ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! ( $screen && 'plugins' === $screen->id ) && 0 !== strpos( $page, 'zizi-cache' ) ) {
				return;
			}
			$warnings = self::audit()['warnings'];
			if ( ! $warnings ) {
				return;
			}
			echo '<div class="notice notice-warning"><p><strong>Nowera ZiziCache Guard:</strong> tieto nastavenia ZiziCache treba vypnúť ručne.</p><ul style="list-style:disc;padding-left:2em">';
			foreach ( $warnings as $message ) {
				echo '<li>' . esc_html( $message ) . '</li>';
			}
			echo '</ul></div>';
		}

		private static function drain_queue() {
			if ( ! defined( 'ZIZI_CACHE_CACHE_DIR' ) ) {
				return;
			}
			// Preload::preload_available() re-queues the rest of the batch and chains through
			// zizi_cache_preload_next; an empty queue ends that chain on its next run.
			$file = ZIZI_CACHE_CACHE_DIR . 'preloader-list.txt';
			clearstatcache( true, $file );
			if ( is_file( $file ) && filesize( $file ) > 0 ) {
				@file_put_contents( $file, '', LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		private static function has_header( array $args, $name ) {
			if ( empty( $args['headers'] ) ) {
				return false;
			}
			if ( is_string( $args['headers'] ) ) {
				return false !== stripos( $args['headers'], $name . ':' );
			}
			if ( is_array( $args['headers'] ) ) {
				foreach ( array_keys( $args['headers'] ) as $key ) {
					if ( is_string( $key ) && 0 === strcasecmp( $key, $name ) ) {
						return true;
					}
				}
			}
			return false;
		}

		private static function user_agent( array $args ) {
			$agent = isset( $args['user-agent'] ) && is_string( $args['user-agent'] ) ? $args['user-agent'] : '';
			if ( empty( $args['headers'] ) ) {
				return $agent;
			}
			if ( is_string( $args['headers'] ) ) {
				return $agent . ' ' . $args['headers'];
			}
			if ( is_array( $args['headers'] ) ) {
				foreach ( $args['headers'] as $key => $value ) {
					if ( is_string( $key ) && 0 === strcasecmp( $key, 'User-Agent' ) && is_string( $value ) ) {
						$agent .= ' ' . $value;
					}
				}
			}
			return $agent;
		}

		private static function response( $code ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => (int) $code,
					'message' => get_status_header_desc( $code ),
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		private static function zizi_version() {
			$file = WP_PLUGIN_DIR . '/zizi-cache/zizi-cache.php';
			if ( ! is_readable( $file ) ) {
				return null;
			}
			$data = get_file_data( $file, array( 'Version' => 'Version' ) );
			return ! empty( $data['Version'] ) ? $data['Version'] : null;
		}
	}

	/**
	 * @return array Guard state and risky ZiziCache settings.
	 */
	function nwr_zizi_guard_audit() {
		return Nwr_Zizi_Guard::audit();
	}

	Nwr_Zizi_Guard::init();

endif;
