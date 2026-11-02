<?php
/**
 * The OGOship page shown inside the connect screen.
 *
 * @package OGOship\WooCommerce
 */

declare( strict_types=1 );

namespace OGOship\WooCommerce\Connect;

use OGOship\WooCommerce\Shipping\StoreKey;

defined( 'ABSPATH' ) || exit;

/**
 * Once the store is connected, WooCommerce → OGOship shows the store's OGOship page in a frame:
 * order counts, the delivery options offered at checkout with a tester, and the account.
 *
 * The page has no OGOship login of its own. When it needs to call OGOship it asks this admin page
 * for a session token (postMessage), and the admin page gets one from WordPress (admin-ajax, the
 * store manager's own login and a nonce). The token is signed with the consumer secret of the REST
 * key OGOship got at the connect, like the shipping method's rate requests, and lives two minutes.
 * The secret itself never leaves the server.
 */
final class EmbeddedPage {

	public const AJAX_ACTION = 'ogoship_session_token';

	/** The token format OGOship checks. */
	public const TOKEN_VERSION = 'v1';

	/** How long a token is valid. OGOship refuses anything over ten minutes. */
	public const TOKEN_TTL = 120;

	private const APP_BASE = 'https://my2.ogoship.com';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_token' ) );
	}

	/**
	 * Where the OGOship page is served from. OGOSHIP_APP_BASE points a development store at a
	 * test environment (e.g. https://devogo2.ogoship.com), as OGOSHIP_API_BASE does for the API.
	 */
	public static function app_base(): string {
		if ( defined( 'OGOSHIP_APP_BASE' ) && is_string( \OGOSHIP_APP_BASE ) && '' !== \OGOSHIP_APP_BASE ) {
			return untrailingslashit( \OGOSHIP_APP_BASE );
		}

		return self::APP_BASE;
	}

	/**
	 * The scheme, host and port of a URL, as a browser names an origin; null when it has none.
	 *
	 * @param string $url The URL.
	 */
	public static function origin_of( string $url ): ?string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );

		return isset( $parts['port'] ) ? $origin . ':' . (int) $parts['port'] : $origin;
	}

	/**
	 * A session token for the OGOship page:
	 * `v1.{base64url(payload)}.{base64url(HMAC-SHA256("v1.{payload part}", consumer secret))}`.
	 *
	 * @param StoreKey $key     The OGOship REST key.
	 * @param string   $store   The store URL, home_url() as in the rate request.
	 * @param int      $user_id The store manager's WordPress user id.
	 * @param int      $now     Unix time.
	 */
	public static function session_token( StoreKey $key, string $store, int $user_id, int $now ): string {
		$payload = array(
			'store' => $store,
			'key'   => $key->key_ending,
			'uid'   => $user_id,
			'iat'   => $now,
			'exp'   => $now + self::TOKEN_TTL,
		);

		$part   = self::base64url( (string) wp_json_encode( $payload ) );
		$signed = self::TOKEN_VERSION . '.' . $part;

		return $signed . '.' . self::base64url( hash_hmac( 'sha256', $signed, $key->consumer_secret, true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the token is base64url by contract.
	}

	/**
	 * `admin-ajax.php?action=ogoship_session_token`: a fresh token for a store manager.
	 */
	public function handle_token(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to open OGOship for this store.', 'ogoship-for-woocommerce' ) ), 403 );
		}

		$key = StoreKey::current();

		if ( null === $key ) {
			wp_send_json_error( array( 'message' => __( 'This store is not connected to OGOship.', 'ogoship-for-woocommerce' ) ), 409 );
		}

		wp_send_json_success( array( 'token' => self::session_token( $key, home_url(), get_current_user_id(), time() ) ) );
	}

	/**
	 * Render the frame. False when there is no OGOship key to sign with, so the caller can show
	 * something else.
	 */
	public static function render(): bool {
		$app_origin   = self::origin_of( self::app_base() );
		$admin_origin = self::origin_of( admin_url() );

		if ( null === StoreKey::current() || null === $app_origin || null === $admin_origin ) {
			return false;
		}

		$src = add_query_arg( array( 'origin' => rawurlencode( $admin_origin ) ), self::app_base() . '/woo/' );

		printf(
			'<iframe id="ogoship-embedded" src="%1$s" title="%2$s" style="width:100%%;max-width:792px;height:900px;border:0;display:block;margin:16px 0" loading="eager"></iframe>',
			esc_url( $src ),
			esc_attr__( 'OGOship', 'ogoship-for-woocommerce' )
		);

		$config = array(
			'appOrigin' => $app_origin,
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'action'    => self::AJAX_ACTION,
			'nonce'     => wp_create_nonce( self::AJAX_ACTION ),
			'refused'   => __( 'WordPress did not give a session token.', 'ogoship-for-woocommerce' ),
		);

		wp_print_inline_script_tag( 'window.ogoshipEmbedded = ' . wp_json_encode( $config ) . ';' . self::bridge_script() );

		return true;
	}

	/**
	 * Answers the frame's token requests and follows its height. Only messages from the frame,
	 * from the OGOship origin, are read; answers go to that origin only.
	 */
	private static function bridge_script(): string {
		return <<<'JS'
(function () {
  var c = window.ogoshipEmbedded;
  var frame = document.getElementById('ogoship-embedded');
  if (!c || !frame) return;

  window.addEventListener('message', function (event) {
    if (event.origin !== c.appOrigin || event.source !== frame.contentWindow) return;
    var data = event.data || {};

    if (data.type === 'ogoship:height' && typeof data.height === 'number') {
      frame.style.height = Math.max(300, Math.min(Math.ceil(data.height), 20000)) + 'px';
      return;
    }

    if (data.type !== 'ogoship:session-token-request' || typeof data.id !== 'string') return;
    var reply = function (answer) {
      answer.type = 'ogoship:session-token';
      answer.id = data.id;
      frame.contentWindow.postMessage(answer, c.appOrigin);
    };

    var body = new URLSearchParams({ action: c.action, nonce: c.nonce });
    fetch(c.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.success && json.data && json.data.token) reply({ token: json.data.token });
        else reply({ error: (json && json.data && json.data.message) || c.refused });
      })
      .catch(function () { reply({ error: c.refused }); });
  });
})();
JS;
	}
}
