<?php
/**
 * Tools > Redirects screen.
 *
 * @package Saizul\RedirectManager
 */

namespace Saizul\RedirectManager\Admin;

use Saizul\RedirectManager\Csv;
use Saizul\RedirectManager\Path;
use Saizul\RedirectManager\Plugin;
use Saizul\RedirectManager\Rule;
use Saizul\RedirectManager\Sanitizer;
use Saizul\RedirectManager\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen with four tabs: redirects (form and list), 404 log,
 * import/export and settings.
 *
 * Every state-changing request checks the capability and a nonce. Forms post
 * to admin-post.php (save, import, export, clear log, row actions) or to the
 * screen itself (list table bulk actions, handled on `load-{$hook}`); the
 * settings tab uses the Settings API.
 */
final class AdminPage {

	const SLUG          = 'wp-redirect-manager';
	const GROUP         = 'wp_redirect_manager';
	const SAVE          = 'redirect_manager_save';
	const ROW           = 'redirect_manager_row';
	const IMPORT        = 'redirect_manager_import';
	const EXPORT        = 'redirect_manager_export';
	const CLEAR_404     = 'redirect_manager_clear_404';
	const MAX_UPLOAD    = 5242880;
	const ROW_OPERATION = array( 'enable', 'disable', 'delete', 'reset_hits', 'delete_404' );

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ROW, array( $this, 'handle_row' ) );
		add_action( 'admin_post_' . self::IMPORT, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'handle_export' ) );
		add_action( 'admin_post_' . self::CLEAR_404, array( $this, 'handle_clear_404' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( Plugin::class, 'capability' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin->file() ), array( $this, 'action_links' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array<string,string> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Nonced URL for a row action.
	 *
	 * @param string $operation One of ROW_OPERATION.
	 * @param int    $id        Rule or 404 entry ID.
	 */
	public static function row_action_url( string $operation, int $id ): string {
		return add_query_arg(
			array(
				'action'   => self::ROW,
				'op'       => $operation,
				'id'       => (string) $id,
				'_wpnonce' => wp_create_nonce( self::ROW . '_' . $operation . '_' . $id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Format a UTC `Y-m-d H:i:s` time in the site time zone.
	 *
	 * @param string $time Time.
	 */
	public static function format_time( string $time ): string {
		$timestamp = strtotime( $time . ' UTC' );
		if ( false === $timestamp ) {
			return $time;
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Add Tools > Redirects.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Redirects', 'wp-redirect-manager' ),
			__( 'Redirects', 'wp-redirect-manager' ),
			Plugin::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_bulk' ) );
		}
	}

	/**
	 * Add a "Redirects" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		$links   = is_array( $links ) ? $links : array();
		$links[] = '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Redirects', 'wp-redirect-manager' ) . '</a>';

		return $links;
	}

	/**
	 * Register the settings option.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage redirects.', 'wp-redirect-manager' ), 403 );
		}
		$tabs = array(
			'redirects' => __( 'Redirects', 'wp-redirect-manager' ),
			'404'       => __( '404 log', 'wp-redirect-manager' ),
			'import'    => __( 'Import / export', 'wp-redirect-manager' ),
			'settings'  => __( 'Settings', 'wp-redirect-manager' ),
		);
		$tab  = self::get_key( 'tab' );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'redirects';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Redirects', 'wp-redirect-manager' ) . '</h1>';
		$this->notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$class = $key === $tab ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::url( 'redirects' === $key ? array() : array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		switch ( $tab ) {
			case '404':
				$this->render_404();
				break;
			case 'import':
				$this->render_import();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				$this->render_redirects();
		}
		echo '</div>';
	}

	/**
	 * Redirects tab: add/edit form and list.
	 */
	private function render_redirects(): void {
		$form = $this->form_state();
		$this->render_form( $form['values'], $form['errors'], $form['id'], $form['from_404'] );

		$table = new RedirectsTable( $this->plugin->redirects()->store() );
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		$table->search_box( __( 'Search redirects', 'wp-redirect-manager' ), 'redirect-search' );
		echo '</form>';

		echo '<form method="post">';
		$table->display();
		echo '</form>';
	}

	/**
	 * Values and errors for the add/edit form.
	 *
	 * @return array{values:array<string,mixed>,errors:string[],id:int,from_404:int}
	 */
	private function form_state(): array {
		$values   = array(
			'source'     => '',
			'target'     => '',
			'status'     => 301,
			'match_type' => Rule::MATCH_EXACT,
			'query'      => Rule::QUERY_IGNORE,
			'enabled'    => true,
			'note'       => '',
		);
		$errors   = array();
		$id       = self::get_int( 'edit' );
		$from_404 = self::get_int( 'from_404' );

		if ( $id > 0 ) {
			$rule = $this->plugin->redirects()->store()->find( $id );
			if ( null === $rule ) {
				$id = 0;
			} else {
				$values = $this->plugin->redirects()->input( $rule );
			}
		} elseif ( $from_404 > 0 ) {
			$entry = $this->plugin->not_found()->find( $from_404 );
			if ( null !== $entry ) {
				$values['source'] = Path::split( (string) $entry['url'] )[0];
			}
		}

		$key    = self::transient_key();
		$failed = get_transient( $key );
		if ( is_array( $failed ) && self::get_int( 'failed' ) > 0 ) {
			$values   = array_merge( $values, (array) ( $failed['values'] ?? array() ) );
			$errors   = array_map( 'strval', (array) ( $failed['errors'] ?? array() ) );
			$id       = (int) ( $failed['id'] ?? $id );
			$from_404 = (int) ( $failed['from_404'] ?? $from_404 );
			delete_transient( $key );
		}

		return array(
			'values'   => $values,
			'errors'   => $errors,
			'id'       => $id,
			'from_404' => $from_404,
		);
	}

	/**
	 * Print the add/edit form.
	 *
	 * @param array<string,mixed> $values   Field values.
	 * @param string[]            $errors   Validation errors.
	 * @param int                 $id       Rule ID when editing.
	 * @param int                 $from_404 404 entry the form was opened from.
	 */
	private function render_form( array $values, array $errors, int $id, int $from_404 ): void {
		$statuses = array(
			301 => __( '301 Moved Permanently', 'wp-redirect-manager' ),
			302 => __( '302 Found (temporary)', 'wp-redirect-manager' ),
			307 => __( '307 Temporary Redirect (keeps the method)', 'wp-redirect-manager' ),
			308 => __( '308 Permanent Redirect (keeps the method)', 'wp-redirect-manager' ),
			410 => __( '410 Gone (no target)', 'wp-redirect-manager' ),
		);
		$matches  = array(
			Rule::MATCH_EXACT  => __( 'Exact path', 'wp-redirect-manager' ),
			Rule::MATCH_PREFIX => __( 'Path prefix (the rest of the path is appended to the target)', 'wp-redirect-manager' ),
			Rule::MATCH_REGEX  => __( 'Regular expression ($1, $2 ... in the target)', 'wp-redirect-manager' ),
		);
		$queries  = array(
			Rule::QUERY_IGNORE => __( 'Ignore the query string', 'wp-redirect-manager' ),
			Rule::QUERY_EXACT  => __( 'Query string must match exactly', 'wp-redirect-manager' ),
			Rule::QUERY_PASS   => __( 'Pass the query string on to the target', 'wp-redirect-manager' ),
		);

		echo '<h2>' . ( $id > 0 ? esc_html__( 'Edit redirect', 'wp-redirect-manager' ) : esc_html__( 'Add redirect', 'wp-redirect-manager' ) ) . '</h2>';
		if ( array() !== $errors ) {
			echo '<div class="notice notice-error"><ul>';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="from_404" value="' . esc_attr( (string) $from_404 ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="rm-source">' . esc_html__( 'Source', 'wp-redirect-manager' ) . '</label></th><td>';
		echo '<input type="text" class="large-text code" id="rm-source" name="rule[source]" value="' . esc_attr( (string) $values['source'] ) . '" placeholder="/old-page" required>';
		echo '<p class="description">' . esc_html__( 'Path from the domain root, as in the address bar. Regex example: ^/blog/(\d{4})/(.*)$', 'wp-redirect-manager' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rm-target">' . esc_html__( 'Target', 'wp-redirect-manager' ) . '</label></th><td>';
		echo '<input type="text" class="large-text code" id="rm-target" name="rule[target]" value="' . esc_attr( (string) $values['target'] ) . '" placeholder="/new-page">';
		echo '<p class="description">' . esc_html__( 'Path on this site or a full http(s) URL. Leave empty for 410 Gone.', 'wp-redirect-manager' ) . '</p></td></tr>';

		$this->select_row( 'rm-status', __( 'Status', 'wp-redirect-manager' ), 'rule[status]', $statuses, (string) $values['status'] );
		$this->select_row( 'rm-match', __( 'Match', 'wp-redirect-manager' ), 'rule[match_type]', $matches, (string) $values['match_type'] );
		$this->select_row( 'rm-query', __( 'Query string', 'wp-redirect-manager' ), 'rule[query]', $queries, (string) $values['query'] );

		echo '<tr><th scope="row"><label for="rm-note">' . esc_html__( 'Note', 'wp-redirect-manager' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="rm-note" name="rule[note]" maxlength="255" value="' . esc_attr( (string) $values['note'] ) . '"></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'wp-redirect-manager' ) . '</th><td><label>';
		echo '<input type="checkbox" name="rule[enabled]" value="1"' . checked( ! empty( $values['enabled'] ), true, false ) . '> ';
		echo esc_html__( 'Active', 'wp-redirect-manager' ) . '</label></td></tr>';
		echo '</tbody></table>';

		submit_button( $id > 0 ? __( 'Update redirect', 'wp-redirect-manager' ) : __( 'Add redirect', 'wp-redirect-manager' ) );
		if ( $id > 0 ) {
			echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'wp-redirect-manager' ) . '</a></p>';
		}
		echo '</form>';
	}

	/**
	 * One select field row.
	 *
	 * @param string               $id      Element ID.
	 * @param string               $label   Label.
	 * @param string               $name    Field name.
	 * @param array<string,string> $options Options.
	 * @param string               $current Current value.
	 */
	private function select_row( string $id, string $label, string $name, array $options, string $current ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $current, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/**
	 * 404 log tab.
	 */
	private function render_404(): void {
		$settings = Settings::get();
		if ( empty( $settings['log_404'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The 404 log is switched off in the settings.', 'wp-redirect-manager' ) . '</p></div>';
		}
		/* translators: 1: number of days, 2: number of rows. */
		echo '<p>' . esc_html( sprintf( __( 'Entries are kept for %1$d days, at most %2$d URLs.', 'wp-redirect-manager' ), (int) $settings['log_retention_days'], (int) $settings['log_max_rows'] ) ) . '</p>';

		$table = new NotFoundTable( $this->plugin->not_found() );
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="404">';
		$table->search_box( __( 'Search 404 log', 'wp-redirect-manager' ), 'not-found-search' );
		echo '</form>';

		echo '<form method="post">';
		$table->display();
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::CLEAR_404 );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CLEAR_404 ) . '">';
		submit_button( __( 'Clear the 404 log', 'wp-redirect-manager' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Import / export tab.
	 */
	private function render_import(): void {
		$key    = self::transient_key() . '_import';
		$report = get_transient( $key );
		if ( is_array( $report ) ) {
			delete_transient( $key );
			$this->render_report( $report );
		}

		echo '<h2>' . esc_html__( 'Import', 'wp-redirect-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'CSV columns, in this order (a header row is optional and lets you use any order):', 'wp-redirect-manager' ) . '</p>';
		echo '<p><code>' . esc_html( implode( ',', Csv::COLUMNS ) ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'Only source and target are required. status: 301, 302, 307, 308 or 410 (default 301). match_type: exact, prefix or regex. query: ignore, exact or pass. enabled: 1 or 0. Rows with the same source and match type as an existing redirect update it.', 'wp-redirect-manager' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::IMPORT );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::IMPORT ) . '">';
		echo '<p><input type="file" name="csv" accept=".csv,text/csv,text/plain" required></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1"> ' . esc_html__( 'Dry run (check the file, save nothing)', 'wp-redirect-manager' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="skip_existing" value="1"> ' . esc_html__( 'Keep existing redirects unchanged', 'wp-redirect-manager' ) . '</label></p>';
		submit_button( __( 'Import CSV', 'wp-redirect-manager' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Export', 'wp-redirect-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'Downloads every redirect as CSV, with hits and last hit at the end. The file can be imported again.', 'wp-redirect-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::EXPORT );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT ) . '">';
		submit_button( __( 'Export CSV', 'wp-redirect-manager' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Print an import report.
	 *
	 * @param array<string,mixed> $report Report from Redirects::import().
	 */
	private function render_report( array $report ): void {
		$errors = (array) ( $report['errors'] ?? array() );
		$class  = array() === $errors ? 'notice-success' : 'notice-warning';
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: added, 2: updated, 3: skipped, 4: errors. */
				__( '%1$d added, %2$d updated, %3$d skipped, %4$d errors.', 'wp-redirect-manager' ),
				(int) ( $report['added'] ?? 0 ),
				(int) ( $report['updated'] ?? 0 ),
				(int) ( $report['skipped'] ?? 0 ),
				count( $errors )
			)
		);
		if ( ! empty( $report['dry_run'] ) ) {
			echo ' ' . esc_html__( 'Dry run: nothing was saved.', 'wp-redirect-manager' );
		}
		echo '</p>';
		if ( array() !== $errors ) {
			echo '<ul>';
			foreach ( array_slice( $errors, 0, 100, true ) as $line => $message ) {
				/* translators: 1: line number, 2: error message. */
				echo '<li>' . esc_html( sprintf( __( 'Line %1$d: %2$s', 'wp-redirect-manager' ), (int) $line, (string) $message ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Settings tab.
	 */
	private function render_settings(): void {
		$settings = Settings::get();
		$name     = Settings::OPTION;
		$checks   = array(
			'ignore_case'           => __( 'Match paths case-insensitively (/About = /about)', 'wp-redirect-manager' ),
			'ignore_trailing_slash' => __( 'Ignore trailing slashes (/about/ = /about)', 'wp-redirect-manager' ),
			'count_hits'            => __( 'Count hits and remember the last hit (one small UPDATE per redirect)', 'wp-redirect-manager' ),
			'auto_slug'             => __( 'Add a 301 automatically when a published post or page changes its URL', 'wp-redirect-manager' ),
			'log_404'               => __( 'Log 404 errors', 'wp-redirect-manager' ),
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Behaviour', 'wp-redirect-manager' ) . '</th><td><fieldset>';
		foreach ( $checks as $key => $label ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $name . '[' . $key . ']' ) . '" value="1"' . checked( ! empty( $settings[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="rm-retention">' . esc_html__( 'Keep 404 entries for', 'wp-redirect-manager' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="3650" class="small-text" id="rm-retention" name="' . esc_attr( $name . '[log_retention_days]' ) . '" value="' . esc_attr( (string) $settings['log_retention_days'] ) . '"> ' . esc_html__( 'days', 'wp-redirect-manager' ) . '</td></tr>';

		echo '<tr><th scope="row"><label for="rm-max-rows">' . esc_html__( 'Maximum 404 URLs', 'wp-redirect-manager' ) . '</label></th><td>';
		echo '<input type="number" min="100" max="1000000" class="small-text" id="rm-max-rows" name="' . esc_attr( $name . '[log_max_rows]' ) . '" value="' . esc_attr( (string) $settings['log_max_rows'] ) . '">';
		echo '<p class="description">' . esc_html__( 'The oldest entries are removed by a daily clean-up.', 'wp-redirect-manager' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rm-ignore">' . esc_html__( 'Do not log URLs containing', 'wp-redirect-manager' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="4" id="rm-ignore" name="' . esc_attr( $name . '[log_ignore]' ) . '">' . esc_textarea( implode( "\n", (array) $settings['log_ignore'] ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One text per line, case-insensitive, for example /wp-content/ or .php', 'wp-redirect-manager' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler below calls guard() (capability and check_admin_referer()) before it reads or changes anything.

	/**
	 * Save the add/edit form.
	 */
	public function handle_save(): void {
		$this->guard( self::SAVE );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitizer::rule() sanitises every field.
		$input    = isset( $_POST['rule'] ) && is_array( $_POST['rule'] ) ? wp_unslash( $_POST['rule'] ) : array();
		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$from_404 = isset( $_POST['from_404'] ) ? absint( $_POST['from_404'] ) : 0;
		$input    = array_intersect_key( (array) $input, array_flip( array( 'source', 'target', 'status', 'match_type', 'query', 'enabled', 'note' ) ) );
		if ( ! isset( $input['enabled'] ) ) {
			$input['enabled'] = false;
		}

		$result = $this->plugin->redirects()->save( $input, $id );
		if ( null === $result['rule'] ) {
			set_transient(
				self::transient_key(),
				array(
					'values'   => array_map( array( self::class, 'form_value' ), $input ),
					'errors'   => $result['errors'],
					'id'       => $id,
					'from_404' => $from_404,
				),
				5 * MINUTE_IN_SECONDS
			);
			$this->redirect_back( array( 'failed' => '1' ) );
			return;
		}
		if ( $from_404 > 0 ) {
			$this->plugin->not_found()->delete( array( $from_404 ) );
		}
		$this->redirect_back( array( 'notice' => $id > 0 ? 'updated' : 'added' ) );
	}

	/**
	 * Row actions (enable, disable, delete, reset hits, delete 404 entry).
	 */
	public function handle_row(): void {
		$operation = isset( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		$id        = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		if ( ! in_array( $operation, self::ROW_OPERATION, true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'wp-redirect-manager' ), 400 );
		}
		$this->guard( self::ROW . '_' . $operation . '_' . $id );
		$count = $this->apply( $operation, array( $id ) );
		$this->redirect_back(
			array(
				'notice' => $operation,
				'count'  => (string) $count,
				'tab'    => 'delete_404' === $operation ? '404' : '',
			)
		);
	}

	/**
	 * Bulk actions of both list tables, on `load-tools_page_wp-redirect-manager`.
	 */
	public function handle_bulk(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! isset( $_POST['ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard() before anything changes.
			return;
		}
		$action = '';
		foreach ( array( 'action', 'action2' ) as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard() before anything changes.
			$value = isset( $_POST[ $field ] ) ? sanitize_key( wp_unslash( $_POST[ $field ] ) ) : '';
			if ( '' !== $value && '-1' !== $value ) {
				$action = $value;
				break;
			}
		}
		if ( '' === $action ) {
			return;
		}
		$is_404 = '404' === self::get_key( 'tab' );
		$this->guard( $is_404 ? 'bulk-not_founds' : 'bulk-redirects' );
		$ids = array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) );

		if ( $is_404 ) {
			$count = 'delete' === $action ? $this->apply( 'delete_404', $ids ) : 0;
			$this->redirect_back(
				array(
					'tab'    => '404',
					'notice' => 'delete_404',
					'count'  => (string) $count,
				)
			);
			return;
		}
		if ( ! in_array( $action, array( 'enable', 'disable', 'delete', 'reset_hits' ), true ) ) {
			return;
		}
		$count = $this->apply( $action, $ids );
		$this->redirect_back(
			array(
				'notice' => $action,
				'count'  => (string) $count,
			)
		);
	}

	/**
	 * Import an uploaded CSV file.
	 */
	public function handle_import(): void {
		$this->guard( self::IMPORT );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Only the temporary path and size of the upload are used; the content is parsed and sanitised row by row.
		$file = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : array();
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || $size <= 0 || $size > self::MAX_UPLOAD ) {
			$this->redirect_back(
				array(
					'tab'    => 'import',
					'notice' => 'upload_failed',
				)
			);
			return;
		}
		$csv     = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload.
		$dry_run = ! empty( $_POST['dry_run'] );
		$report  = $this->plugin->redirects()->import( $csv, $dry_run, empty( $_POST['skip_existing'] ) );

		$report['dry_run'] = $dry_run;
		set_transient( self::transient_key() . '_import', $report, 5 * MINUTE_IN_SECONDS );
		$this->redirect_back( array( 'tab' => 'import' ) );
	}

	/**
	 * Download every redirect as CSV.
	 */
	public function handle_export(): void {
		$this->guard( self::EXPORT );
		$csv = $this->plugin->redirects()->export();
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="redirects-' . gmdate( 'Y-m-d' ) . '.csv"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, not HTML.
		if ( apply_filters( 'redirect_manager_exit', true ) ) {
			exit;
		}
	}

	/**
	 * Clear the 404 log.
	 */
	public function handle_clear_404(): void {
		$this->guard( self::CLEAR_404 );
		$this->plugin->not_found()->clear();
		$this->redirect_back(
			array(
				'tab'    => '404',
				'notice' => 'cleared',
			)
		);
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Run an operation on rules or 404 entries.
	 *
	 * @param string $operation Operation.
	 * @param int[]  $ids       IDs.
	 * @return int Items changed.
	 */
	private function apply( string $operation, array $ids ): int {
		$redirects = $this->plugin->redirects();
		switch ( $operation ) {
			case 'enable':
				return $redirects->set_enabled( $ids, true );
			case 'disable':
				return $redirects->set_enabled( $ids, false );
			case 'delete':
				return $redirects->delete( $ids );
			case 'reset_hits':
				return $redirects->reset_hits( $ids );
			case 'delete_404':
				return $this->plugin->not_found()->delete( $ids );
		}

		return 0;
	}

	/**
	 * Print the notice for the last action.
	 */
	private function notice(): void {
		$notice   = self::get_key( 'notice' );
		$count    = self::get_int( 'count' );
		$messages = array(
			'added'         => __( 'Redirect added.', 'wp-redirect-manager' ),
			'updated'       => __( 'Redirect updated.', 'wp-redirect-manager' ),
			/* translators: %d: number of redirects. */
			'enable'        => sprintf( _n( '%d redirect enabled.', '%d redirects enabled.', $count, 'wp-redirect-manager' ), $count ),
			/* translators: %d: number of redirects. */
			'disable'       => sprintf( _n( '%d redirect disabled.', '%d redirects disabled.', $count, 'wp-redirect-manager' ), $count ),
			/* translators: %d: number of redirects. */
			'delete'        => sprintf( _n( '%d redirect deleted.', '%d redirects deleted.', $count, 'wp-redirect-manager' ), $count ),
			/* translators: %d: number of redirects. */
			'reset_hits'    => sprintf( _n( 'Hits reset for %d redirect.', 'Hits reset for %d redirects.', $count, 'wp-redirect-manager' ), $count ),
			/* translators: %d: number of 404 entries. */
			'delete_404'    => sprintf( _n( '%d entry deleted.', '%d entries deleted.', $count, 'wp-redirect-manager' ), $count ),
			'cleared'       => __( 'The 404 log is empty.', 'wp-redirect-manager' ),
			'upload_failed' => __( 'The file could not be uploaded. Choose a CSV file smaller than 5 MB.', 'wp-redirect-manager' ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		$class = 'upload_failed' === $notice ? 'notice-error' : 'notice-success';
		if ( 'enable' === $notice && 0 === $count ) {
			$messages[ $notice ] = __( 'No redirect was enabled. Redirects that would create a loop stay disabled.', 'wp-redirect-manager' );
			$class               = 'notice-warning';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
	}

	/**
	 * Stop unless the user may manage redirects and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage redirects.', 'wp-redirect-manager' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the screen.
	 *
	 * @param array<string,string> $args Query arguments.
	 */
	private function redirect_back( array $args ): void {
		wp_safe_redirect( self::url( array_filter( $args, 'strlen' ) ) );
		if ( apply_filters( 'redirect_manager_exit', true ) ) {
			exit;
		}
	}

	/**
	 * Transient key for the current user's form state.
	 */
	private static function transient_key(): string {
		return 'wp_redirect_manager_form_' . get_current_user_id();
	}

	/**
	 * Scalar form value for re-display.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function form_value( $value ): string {
		return is_scalar( $value ) ? Sanitizer::clean( (string) $value ) : '';
	}

	/**
	 * Sanitised key from the query string.
	 *
	 * @param string $name Parameter.
	 */
	private static function get_key( string $name ): string {
		return isset( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	}

	/**
	 * Positive integer from the query string.
	 *
	 * @param string $name Parameter.
	 */
	private static function get_int( string $name ): int {
		return isset( $_GET[ $name ] ) ? absint( $_GET[ $name ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	}
}
