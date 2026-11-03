<?php
/**
 * Build URLs from the host the request came in on, so the dev store works both
 * at https://localhost:<WP_PORT> and behind a public HTTPS front (Caddy's
 * plain-HTTP site). Without it WordPress redirects every visitor to the stored
 * siteurl, which is localhost, and links assets there.
 *
 * Local dev store only -- never shipped with the plugin. X-Forwarded-Host is set
 * by Caddy from the request's Host, so it is the name the visitor used.
 * WP-CLI and cron have no such header and keep the stored URLs.
 *
 * @package OGOship\WooCommerce\Dev
 */

defined( 'ABSPATH' ) || exit;

$ogoship_dev_host = isset( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_HOST'] : '';

if ( '' !== $ogoship_dev_host && preg_match( '/^[A-Za-z0-9.-]+(:\d+)?$/', $ogoship_dev_host ) ) {
	$ogoship_dev_url    = 'https://' . $ogoship_dev_host;
	$ogoship_stored_url = untrailingslashit( (string) get_option( 'siteurl' ) );

	add_filter( 'option_home', static fn() => $ogoship_dev_url );
	add_filter( 'option_siteurl', static fn() => $ogoship_dev_url );

	// WP_CONTENT_URL and WP_PLUGIN_URL are fixed from the stored siteurl before
	// mu-plugins load, so the asset URLs built from them are rewritten here.
	if ( $ogoship_stored_url !== $ogoship_dev_url ) {
		$ogoship_dev_rewrite = static fn( $url ) => is_string( $url ) && str_starts_with( $url, $ogoship_stored_url )
			? $ogoship_dev_url . substr( $url, strlen( $ogoship_stored_url ) )
			: $url;

		foreach ( array( 'content_url', 'plugins_url', 'theme_root_uri', 'stylesheet_directory_uri', 'template_directory_uri', 'script_loader_src', 'style_loader_src' ) as $ogoship_dev_filter ) {
			add_filter( $ogoship_dev_filter, $ogoship_dev_rewrite );
		}
		add_filter(
			'upload_dir',
			static function ( $dirs ) use ( $ogoship_dev_rewrite ) {
				$dirs['url']     = $ogoship_dev_rewrite( $dirs['url'] );
				$dirs['baseurl'] = $ogoship_dev_rewrite( $dirs['baseurl'] );
				return $dirs;
			}
		);
	}
}
