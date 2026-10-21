<?php
/**
 * Script inventory: scans the merchant's OWN store pages and records the
 * external scripts loaded on them. No third-party or phone-home requests.
 *
 * @package CyberShield_Checkout_Script_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CSCSM_Inventory.
 */
class CSCSM_Inventory {

	/**
	 * Compact third-party catalog: host substring => array( category, label ).
	 * Deliberately small. Unmatched third parties are still listed as "third party".
	 *
	 * RECOGNITION LIST ONLY: these hosts are string patterns used to put a
	 * friendly vendor label on scripts already present on the merchant's own
	 * pages. The plugin never loads, embeds, or connects to any of them.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function catalog() {
		return array(
			'googletagmanager.com' => array( 'tag-manager', 'Google Tag Manager' ),
			'google-analytics.com' => array( 'analytics', 'Google Analytics' ),
			'js.stripe.com'        => array( 'payment', 'Stripe' ),
			'paypal.com'           => array( 'payment', 'PayPal' ),
			'paypalobjects.com'    => array( 'payment', 'PayPal' ),
			'squareup.com'         => array( 'payment', 'Square' ),
			'squarecdn.com'        => array( 'payment', 'Square' ),
			'connect.facebook.net' => array( 'advertising', 'Meta Pixel' ),
			'hotjar.com'           => array( 'session-replay', 'Hotjar' ),
			'fullstory.com'        => array( 'session-replay', 'FullStory' ),
			'clarity.ms'           => array( 'session-replay', 'Microsoft Clarity' ),
			'intercomcdn.com'      => array( 'chat', 'Intercom' ),
			'zdassets.com'         => array( 'chat', 'Zendesk' ),
			'klaviyo.com'          => array( 'analytics', 'Klaviyo' ),
			'cdnjs.cloudflare.com' => array( 'cdn', 'cdnjs' ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- recognition pattern only; nothing is loaded from this host.
			'jsdelivr.net'         => array( 'cdn', 'jsDelivr' ),
			'code.jquery.com'      => array( 'cdn', 'jQuery CDN' ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- recognition pattern only; nothing is loaded from this host.
			'unpkg.com'            => array( 'cdn', 'unpkg' ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- recognition pattern only; nothing is loaded from this host.
		);
	}

	/**
	 * URLs to scan: home, and WooCommerce shop/cart/checkout when available.
	 *
	 * @return array<string,string> label => url
	 */
	public function target_urls() {
		$urls = array( 'Home' => home_url( '/' ) );

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array(
				'Shop'     => 'shop',
				'Cart'     => 'cart',
				'Checkout' => 'checkout',
			) as $label => $page ) {
				$link = wc_get_page_permalink( $page );
				if ( $link ) {
					$urls[ $label ] = $link;
				}
			}
		}
		return $urls;
	}

	/**
	 * Run a scan of the store's own pages and store the snapshot.
	 *
	 * @return array The snapshot.
	 */
	public function scan() {
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$scripts   = array(); // keyed by src to de-dup across pages.
		$errors    = array();

		foreach ( $this->target_urls() as $label => $url ) {
			$res = wp_remote_get(
				$url,
				array(
					'timeout'    => 15,
					'user-agent' => 'CyberShield-Checkout-Script-Monitor/' . CSCSM_VERSION . ' (+https://cybershieldstudio.com)',
				)
			);

			if ( is_wp_error( $res ) ) {
				$errors[ $label ] = $res->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 400 ) {
				/* translators: %d: HTTP status code. */
				$errors[ $label ] = sprintf( __( 'HTTP %d', 'cybershield-checkout-script-monitor' ), $code );
				continue;
			}

			$html = wp_remote_retrieve_body( $res );
			foreach ( $this->extract_scripts( $html, $url ) as $item ) {
				$src = $item['src'];
				if ( ! isset( $scripts[ $src ] ) ) {
					$item['host']        = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
					$item['third_party'] = $this->is_third_party( $item['host'], $site_host );
					$vendor              = $this->match_vendor( $item['host'] . ' ' . $src );
					$item['category']    = $vendor[0];
					$item['vendor']      = $vendor[1];
					$item['source']      = $item['third_party'] ? $vendor[1] : $this->first_party_source( $src );
					$item['pages']       = array( $label );
					$scripts[ $src ]     = $item;
				} elseif ( ! in_array( $label, $scripts[ $src ]['pages'], true ) ) {
					$scripts[ $src ]['pages'][] = $label;
				}
			}
		}

		$scripts = array_values( $scripts );
		$third   = 0;
		$missing = 0;
		$hosts   = array();
		foreach ( $scripts as $s ) {
			if ( $s['third_party'] ) {
				$third++;
			}
			if ( empty( $s['has_sri'] ) ) {
				$missing++;
			}
			if ( ! empty( $s['host'] ) ) {
				$hosts[ $s['host'] ] = true;
			}
		}

		$snapshot = array(
			'scanned_at'  => current_time( 'mysql' ),
			'site_host'   => $site_host,
			'scripts'     => $scripts,
			'hosts'       => array_keys( $hosts ),
			'total'       => count( $scripts ),
			'third_party' => $third,
			'missing_sri' => $missing,
			'errors'      => $errors,
		);
		update_option( CSCSM_OPT_INVENTORY, $snapshot, false );
		return $snapshot;
	}

	/**
	 * Extract external <script src> entries from a page's HTML.
	 *
	 * @param string $html     Page HTML.
	 * @param string $base_url URL the HTML came from (to resolve relative src).
	 * @return array<int,array{src:string,has_sri:bool}>
	 */
	private function extract_scripts( $html, $base_url ) {
		$out = array();
		if ( '' === trim( (string) $html ) ) {
			return $out;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		// The XML pragma nudges DOMDocument to treat the input as UTF-8.
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		foreach ( $doc->getElementsByTagName( 'script' ) as $node ) {
			if ( ! $node->hasAttribute( 'src' ) ) {
				continue; // Inline scripts are out of MVP scope.
			}
			$src = trim( $node->getAttribute( 'src' ) );
			if ( '' === $src ) {
				continue;
			}
			$out[] = array(
				'src'     => $this->absolutize( $src, $base_url ),
				'has_sri' => $node->hasAttribute( 'integrity' ) && '' !== trim( $node->getAttribute( 'integrity' ) ),
			);
		}
		return $out;
	}

	/**
	 * Resolve a possibly-relative script src to an absolute URL.
	 */
	private function absolutize( $src, $base_url ) {
		if ( preg_match( '#^https?://#i', $src ) ) {
			return $src;
		}
		$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
		$scheme = $scheme ? $scheme : 'https';

		if ( 0 === strpos( $src, '//' ) ) {
			return $scheme . ':' . $src;
		}
		$host = wp_parse_url( $base_url, PHP_URL_HOST );
		if ( ! $host ) {
			return $src;
		}
		$origin = $scheme . '://' . $host;
		if ( 0 === strpos( $src, '/' ) ) {
			return $origin . $src;
		}
		return $origin . '/' . ltrim( $src, '/' );
	}

	private function normalize_host( $host ) {
		return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
	}

	private function is_third_party( $host, $site_host ) {
		// Compare with www. stripped so www.mystore.com counts as first-party to
		// mystore.com. The stored host itself keeps its real form (see scan()).
		$host      = $this->normalize_host( $host );
		$site_host = $this->normalize_host( $site_host );
		if ( '' === $host || '' === $site_host ) {
			return true;
		}
		if ( $host === $site_host ) {
			return false;
		}
		// A subdomain of the site host is first-party.
		return substr( $host, - ( strlen( $site_host ) + 1 ) ) !== '.' . $site_host;
	}

	private function match_vendor( $haystack ) {
		$haystack = strtolower( $haystack );
		foreach ( $this->catalog() as $needle => $vendor ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $vendor;
			}
		}
		return array( '', '' );
	}

	/**
	 * Friendly name for a FIRST-PARTY script based on its path: WordPress core,
	 * the installed plugin's real display name, the theme, etc. Empty when the
	 * path is not a recognizable WordPress location (caller falls back to
	 * "Your store").
	 *
	 * @param string $src Absolute script URL.
	 * @return string
	 */
	private function first_party_source( $src ) {
		$path = strtolower( (string) wp_parse_url( $src, PHP_URL_PATH ) );
		if ( false !== strpos( $path, '/wp-includes/js/jquery/' ) ) {
			return __( 'jQuery (WordPress core)', 'cybershield-checkout-script-monitor' );
		}
		if ( preg_match( '#/wp-(?:includes|admin)/#', $path ) ) {
			return __( 'WordPress core', 'cybershield-checkout-script-monitor' );
		}
		if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $path, $m ) ) {
			return $this->plugin_name( $m[1] );
		}
		if ( false !== strpos( $path, '/wp-content/mu-plugins/' ) ) {
			return __( 'Must-use plugin', 'cybershield-checkout-script-monitor' );
		}
		if ( preg_match( '#/wp-content/themes/([^/]+)/#', $path, $m ) ) {
			return $this->theme_name( $m[1] );
		}
		return '';
	}

	/**
	 * Real display name of an installed plugin by directory slug, or a
	 * prettified slug if it is not in the registry.
	 */
	private function plugin_name( $slug ) {
		$names = $this->installed_plugin_names();
		return isset( $names[ $slug ] ) ? $names[ $slug ] : $this->prettify_slug( $slug );
	}

	/**
	 * Map of plugin directory slug => display Name, from the WordPress registry.
	 * Cached for the request.
	 *
	 * @return array<string,string>
	 */
	private function installed_plugin_names() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$slug = strtok( (string) $file, '/' );
			if ( $slug && ! isset( $map[ $slug ] ) && ! empty( $data['Name'] ) ) {
				$map[ $slug ] = $data['Name'];
			}
		}
		return $map;
	}

	private function theme_name( $slug ) {
		$name = '';
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $slug );
			$name  = $theme ? (string) $theme->get( 'Name' ) : '';
		}
		if ( '' === $name ) {
			$name = $this->prettify_slug( $slug );
		}
		/* translators: %s: theme name. */
		return sprintf( __( '%s (theme)', 'cybershield-checkout-script-monitor' ), $name );
	}

	private function prettify_slug( $slug ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', (string) $slug ) );
	}

	/**
	 * The stored snapshot (or empty array).
	 *
	 * @return array
	 */
	public function get_snapshot() {
		$snap = get_option( CSCSM_OPT_INVENTORY, array() );
		return is_array( $snap ) ? $snap : array();
	}

	/**
	 * Unique script hosts from the latest snapshot (for the CSP baseline).
	 *
	 * @return array<int,string>
	 */
	public function snapshot_hosts() {
		$snap = $this->get_snapshot();
		return isset( $snap['hosts'] ) && is_array( $snap['hosts'] ) ? $snap['hosts'] : array();
	}
}
