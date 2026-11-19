<?php
/**
 * Minimal WordPress stubs for unit tests.
 *
 * Only what the plugin touches is implemented. State lives in WpStubs so tests
 * can reset and inspect it.
 *
 * @package Saizul\RedirectManager
 */

declare(strict_types=1);

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

final class WpDie extends RuntimeException {
}

final class WpStubs {
	/** @var array<string,array<int,array<int,callable>>> */
	public static $filters = array();
	/** @var array<string,mixed> */
	public static $options = array();
	/** @var bool */
	public static $admin = false;
	/** @var bool */
	public static $ajax = false;
	/** @var bool */
	public static $cron = false;
	/** @var bool */
	public static $logged_in = false;
	/** @var string[] */
	public static $roles = array();
	/** @var bool|array<string,bool> */
	public static $can = true;
	/** @var bool */
	public static $nonce_ok = true;
	/** @var string */
	public static $redirect = '';
	/** @var string */
	public static $theme_template = '';
	/** @var array<string,string[]> */
	public static $calls = array();
	/** @var array<string,mixed> */
	public static $registry = array();
	/** @var array<string,mixed> */
	public static $transients = array();
	/** @var array<string,int> */
	public static $events = array();
	/** @var bool */
	public static $is_404 = false;
	/** @var array{0:string,1:int}|null */
	public static $wp_redirect = null;
	/** @var array<int,string> */
	public static $permalinks = array();

	public static function reset(): void {
		self::$filters        = array();
		self::$options        = array();
		self::$admin          = false;
		self::$ajax           = false;
		self::$cron           = false;
		self::$logged_in      = false;
		self::$roles          = array();
		self::$can            = true;
		self::$nonce_ok       = true;
		self::$redirect       = '';
		self::$theme_template = '';
		self::$calls          = array();
		self::$registry       = array();
		self::$transients     = array();
		self::$events         = array();
		self::$is_404         = false;
		self::$wp_redirect    = null;
		self::$permalinks     = array();
		$GLOBALS['wp_query']  = new WP_Query_Stub();
		$GLOBALS['wpdb']      = new WpdbStub();
		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		$_COOKIE                = array();
	}

	public static function record( string $what, string $value ): void {
		self::$calls[ $what ][] = $value;
	}

	/** @return string[] */
	public static function calls( string $what ): array {
		return self::$calls[ $what ] ?? array();
	}
}

final class WP_Error {
	/** @var string */
	public $code;
	/** @var string */
	public $message;
	/** @var mixed */
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

final class WP_Roles_Stub {
	/** @var array<string,array<string,mixed>> */
	public $roles = array(
		'administrator' => array( 'name' => 'Administrator' ),
		'editor'        => array( 'name' => 'Editor' ),
		'author'        => array( 'name' => 'Author' ),
		'contributor'   => array( 'name' => 'Contributor' ),
		'subscriber'    => array( 'name' => 'Subscriber' ),
	);

	/** @return array<string,string> */
	public function get_names() {
		return array_map(
			static function ( $role ) {
				return $role['name'];
			},
			$this->roles
		);
	}
}

final class AdminBarStub {
	/** @var array<string,array<string,mixed>> */
	public $nodes = array();

	public function add_node( $args ) {
		$this->nodes[ $args['id'] ] = $args;
	}
}

// Hooks.
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	WpStubs::$filters[ $hook ][ $priority ][] = $callback;
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function has_filter( $hook, $callback = false ) {
	foreach ( WpStubs::$filters[ $hook ] ?? array() as $priority => $callbacks ) {
		foreach ( $callbacks as $registered ) {
			if ( false === $callback || $registered == $callback ) {
				return $priority;
			}
		}
	}
	return false;
}

function apply_filters( $hook, $value, ...$args ) {
	$callbacks = WpStubs::$filters[ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $list ) {
		foreach ( $list as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
	}
	return $value;
}

function do_action( $hook, ...$args ) {
	WpStubs::record( 'do_action', (string) $hook );
	$callbacks = WpStubs::$filters[ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $list ) {
		foreach ( $list as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
}

function __return_false() {
	return false;
}

// Options.
function get_option( $name, $default = false ) {
	if ( 'blog_charset' === $name ) {
		return 'UTF-8';
	}
	if ( 'date_format' === $name ) {
		return 'Y-m-d';
	}
	if ( 'time_format' === $name ) {
		return 'H:i';
	}
	return array_key_exists( $name, WpStubs::$options ) ? WpStubs::$options[ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	WpStubs::$options[ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( WpStubs::$options[ $name ] );
	return true;
}

// Users and roles.
function wp_roles() {
	return new WP_Roles_Stub();
}

function translate_user_role( $name ) {
	return $name;
}

function is_user_logged_in() {
	return WpStubs::$logged_in;
}

function wp_get_current_user() {
	$user        = new stdClass();
	$user->ID    = WpStubs::$logged_in ? 1 : 0;
	$user->roles = WpStubs::$logged_in ? WpStubs::$roles : array();
	return $user;
}

function current_user_can( $capability, ...$args ) {
	if ( is_array( WpStubs::$can ) ) {
		return WpStubs::$can[ $capability ] ?? false;
	}
	return WpStubs::$can;
}

// Context.
function is_admin() {
	return WpStubs::$admin;
}

function wp_doing_ajax() {
	return WpStubs::$ajax;
}

function wp_doing_cron() {
	return WpStubs::$cron;
}

function is_ssl() {
	return false;
}

function is_multisite() {
	return ! empty( WpStubs::$registry['multisite'] );
}

function is_admin_bar_showing() {
	return true;
}

// Time.
function wp_timezone() {
	return new DateTimeZone( WpStubs::$registry['timezone'] ?? 'UTC' );
}

function wp_timezone_string() {
	return wp_timezone()->getName();
}

function wp_date( $format, $timestamp = null, $timezone = null ) {
	return ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $timezone ?? wp_timezone() )->format( $format );
}

// Security helpers.
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out   = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $out;
}

function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	WpStubs::record( 'check_admin_referer', (string) $action );
	if ( ! WpStubs::$nonce_ok ) {
		throw new WpDie( 'nonce' );
	}
	return 1;
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new WpDie( (string) $message );
}

function wp_nonce_field( $action = -1 ) {
	echo '<input type="hidden" name="_wpnonce" value="nonce">';
}

// Sanitising and escaping.
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_text_field( $text ) {
	$text = strip_tags( (string) $text );
	$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	return trim( (string) $text );
}

function sanitize_hex_color( $color ) {
	if ( '' === $color ) {
		return '';
	}
	return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ? $color : null;
}

function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( '/' === $url[0] ) {
		return $url;
	}
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	$allow  = $protocols ?? array( 'http', 'https' );
	if ( ! in_array( $scheme, $allow, true ) ) {
		return '';
	}
	return str_replace( array( '"', "'", '<', '>', ' ' ), array( '%22', '%27', '%3C', '%3E', '%20' ), $url );
}

function esc_url( $url ) {
	return htmlspecialchars( esc_url_raw( $url ), ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return esc_html( $text );
}

function esc_textarea( $text ) {
	return esc_html( $text );
}

function wp_kses_post( $html ) {
	$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', (string) $html );
	$html = strip_tags( (string) $html, '<a><strong><em><b><i><br><p><ul><ol><li>' );
	return (string) preg_replace( '/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html );
}

function wpautop( $text ) {
	return '<p>' . $text . '</p>';
}

function checked( $checked, $current = true, $echo = true ) {
	return (string) $checked === (string) $current ? " checked='checked'" : '';
}

// i18n.
function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( $text );
}

function load_plugin_textdomain( $domain, $deprecated = false, $path = false ) {
	WpStubs::record( 'load_plugin_textdomain', (string) $domain );
	return true;
}

function language_attributes() {
	echo 'lang="en-US"';
}

// URLs.
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

function home_url( $path = '' ) {
	return ( WpStubs::$registry['home'] ?? 'https://example.org' ) . '/' . ltrim( (string) $path, '/' );
}

function admin_url( $path = '' ) {
	return 'https://example.org/wp-admin/' . ltrim( (string) $path, '/' );
}

function plugin_basename( $file ) {
	return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
}

function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		$params = $args[0];
		$url    = $args[1] ?? ( $_SERVER['REQUEST_URI'] ?? '/' );
	} else {
		$params = array( $args[0] => $args[1] );
		$url    = $args[2] ?? ( $_SERVER['REQUEST_URI'] ?? '/' );
	}
	$parts = explode( '?', (string) $url, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
	}
	$query = array_merge( $query, $params );
	return $parts[0] . ( $query ? '?' . http_build_query( $query ) : '' );
}

function remove_query_arg( $key, $url = false ) {
	$url   = false === $url ? ( $_SERVER['REQUEST_URI'] ?? '/' ) : $url;
	$parts = explode( '?', (string) $url, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
	}
	foreach ( (array) $key as $k ) {
		unset( $query[ $k ] );
	}
	return $parts[0] . ( $query ? '?' . http_build_query( $query ) : '' );
}

function wp_safe_redirect( $location, $status = 302 ) {
	WpStubs::$redirect = (string) $location;
	return true;
}

// Responses.
function status_header( $code ) {
	WpStubs::record( 'status_header', (string) $code );
}

function nocache_headers() {
	WpStubs::record( 'nocache_headers', '1' );
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

function get_bloginfo( $show = '' ) {
	return 'Example Site';
}

function locate_template( $names ) {
	return WpStubs::$theme_template;
}

function wp_add_inline_style( $handle, $css ) {
	WpStubs::record( 'inline_style', (string) $handle );
	return true;
}

// Settings API and admin screens.
function register_setting( $group, $name, $args = array() ) {
	WpStubs::$registry['settings'][ $name ] = $args;
}

function add_settings_section( $id, $title, $callback, $page ) {
	WpStubs::record( 'section', (string) $id );
}

function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {
	WpStubs::record( 'field', (string) $id );
}

function add_options_page( $page_title, $menu_title, $capability, $slug, $callback = '' ) {
	WpStubs::record( 'options_page', (string) $slug );
	return 'settings_page_' . $slug;
}

function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">';
}

function do_settings_sections( $page ) {
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true ) {
	echo '<button type="submit">' . esc_html( $text ?? 'Save Changes' ) . '</button>';
}

function get_admin_page_title() {
	return 'Redirects';
}

// Multisite.
function get_sites( $args = array() ) {
	return WpStubs::$registry['sites'] ?? array();
}

function switch_to_blog( $site_id ) {
	WpStubs::record( 'switch_to_blog', (string) $site_id );
	WpStubs::$options                  = WpStubs::$registry[ 'site_options:' . $site_id ] ?? array();
	WpStubs::$registry['current_site'] = (int) $site_id;
	return true;
}

function restore_current_blog() {
	$site                                         = WpStubs::$registry['current_site'] ?? 0;
	WpStubs::$registry[ 'site_options:' . $site ] = WpStubs::$options;
	return true;
}

final class WP_Query_Stub {
	/** @var bool */
	public $is_404 = false;

	public function set_404() {
		$this->is_404 = true;
	}
}

final class WpdbStub {
	/** @var string */
	public $prefix = 'wp_';

	/** @var string[] */
	public $queries = array();

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[isd]/', is_int( $arg ) ? (string) $arg : '`' . $arg . '`', $query, 1 );
		}
		return $query;
	}

	public function query( $query ) {
		$this->queries[] = (string) $query;
		return 1;
	}
}

// Extra helpers used by Redirect Manager.
function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . md5( (string) $action );
}

function get_current_user_id() {
	return WpStubs::$logged_in ? 1 : 0;
}

function set_transient( $key, $value, $expiration = 0 ) {
	WpStubs::$transients[ $key ] = $value;
	return true;
}

function get_transient( $key ) {
	return WpStubs::$transients[ $key ] ?? false;
}

function delete_transient( $key ) {
	unset( WpStubs::$transients[ $key ] );
	return true;
}

function add_management_page( $page_title, $menu_title, $capability, $slug, $callback = '' ) {
	WpStubs::record( 'management_page', (string) $slug );
	return 'tools_page_' . $slug;
}

function wp_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
	WpStubs::$wp_redirect = array( (string) $location, (int) $status );
	return true;
}

function is_404() {
	return WpStubs::$is_404;
}

function wp_next_scheduled( $hook ) {
	return WpStubs::$events[ $hook ] ?? false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	WpStubs::$events[ $hook ] = (int) $timestamp;
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	unset( WpStubs::$events[ $hook ] );
	return 1;
}

function get_permalink( $post ) {
	return WpStubs::$permalinks[ spl_object_id( $post ) ] ?? false;
}

function is_post_type_viewable( $type ) {
	return in_array( $type, array( 'post', 'page' ), true );
}

function wp_is_post_revision( $post ) {
	return false;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

function selected( $selected, $current = true, $echo = true ) {
	return (string) $selected === (string) $current ? " selected='selected'" : '';
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text );
}

function register_activation_hook( $file, $callback ) {
}

function register_deactivation_hook( $file, $callback ) {
}
