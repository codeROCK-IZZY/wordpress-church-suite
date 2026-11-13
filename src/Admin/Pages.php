<?php
/**
 * Admin screens: people, the rota, giving (with year-end statements), and settings.
 *
 * @package Congregation
 */

namespace Congregation\Admin;

use Congregation\Core\Statement;
use Congregation\Data\Repo;
use Congregation\RotaService;
use Congregation\Settings;

/**
 * Admin pages.
 */
final class Pages {

	public const SLUG = 'congregation';

	/**
	 * Register.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		foreach ( array( 'person', 'plan', 'ask', 'gift', 'refund', 'settings' ) as $a ) {
			add_action( 'admin_post_congregation_' . $a, array( self::class, 'handle_' . $a ) );
		}
	}

	/**
	 * Menu.
	 */
	public static function menu(): void {
		add_menu_page( __( 'Congregation', 'congregation' ), __( 'Congregation', 'congregation' ), 'edit_others_posts', self::SLUG, array( self::class, 'rota' ), 'dashicons-groups', 27 );
		add_submenu_page( self::SLUG, __( 'Rota', 'congregation' ), __( 'Rota', 'congregation' ), 'edit_others_posts', self::SLUG, array( self::class, 'rota' ) );
		add_submenu_page( self::SLUG, __( 'People', 'congregation' ), __( 'People', 'congregation' ), 'edit_others_posts', self::SLUG . '-people', array( self::class, 'people' ) );
		add_submenu_page( self::SLUG, __( 'Giving', 'congregation' ), __( 'Giving', 'congregation' ), 'manage_options', self::SLUG . '-giving', array( self::class, 'giving' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'congregation' ), __( 'Settings', 'congregation' ), 'manage_options', self::SLUG . '-settings', array( self::class, 'settings' ) );
	}

	/**
	 * Styles.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( string $hook ): void {
		if ( str_contains( $hook, self::SLUG ) ) {
			wp_enqueue_style( 'congregation-admin', CONGREGATION_URL . 'assets/admin.css', array(), CONGREGATION_VERSION );
		}
	}

	/**
	 * Opening of a page with an optional notice.
	 *
	 * @param string $title Title.
	 */
	private static function open( string $title ): void {
		echo '<div class="wrap congregation"><h1>' . esc_html( $title ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$msg = isset( $_GET['cg_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['cg_msg'] ) ) : '';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/**
	 * A small admin-post form.
	 *
	 * @param string $action Action suffix.
	 * @param string $inner  Fields (already escaped).
	 * @param string $button Label.
	 * @param string $css    Button class.
	 */
	private static function form( string $action, string $inner, string $button, string $css = 'button' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cg-inline"><input type="hidden" name="action" value="congregation_' . esc_attr( $action ) . '">' . wp_nonce_field( 'congregation_' . $action, '_wpnonce', true, false ) . $inner . ' <button class="' . esc_attr( $css ) . '">' . esc_html( $button ) . '</button></form>';
	}

	/**
	 * Requested date range (default: the next eight weeks).
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function range(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-d' );
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d', strtotime( '+8 weeks' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$ok = static fn ( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
		return array( $ok( $from ) ? $from : gmdate( 'Y-m-d' ), $ok( $to ) ? $to : gmdate( 'Y-m-d', strtotime( '+8 weeks' ) ) );
	}

	/**
	 * Rota page: services as columns, roles as rows.
	 */
	public static function rota(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		[ $from, $to ] = self::range();
		self::open( __( 'Rota', 'congregation' ) );
		$fields = '<input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '">';
		echo '<form method="get" class="cg-inline"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label>' . esc_html__( 'From', 'congregation' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> <label>' . esc_html__( 'to', 'congregation' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> <button class="button">' . esc_html__( 'Show', 'congregation' ) . '</button></form> ';
		echo self::form( 'plan', $fields, __( 'Plan empty places', 'congregation' ), 'button button-primary' ) . ' ' . self::form( 'ask', $fields, __( 'Email requests', 'congregation' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form() escapes.
		$rows     = Repo::assignments( $from, $to );
		$services = array();
		$grid     = array();
		foreach ( $rows as $r ) {
			$key              = $r['date'] . ' ' . $r['time'];
			$services[ $key ] = true;
			if ( 'declined' !== $r['status'] ) {
				$grid[ $r['role'] ][ $key ][] = $r;
			}
		}
		if ( ! $services ) {
			echo '<p>' . esc_html__( 'Nothing planned in these dates yet. "Plan empty places" fills every role in Settings for each service, keeping to everyone\'s availability and monthly limits.', 'congregation' ) . '</p></div>';
			return;
		}
		$labels = array(
			'planned'   => __( 'planned', 'congregation' ),
			'asked'     => __( 'asked', 'congregation' ),
			'confirmed' => __( 'confirmed', 'congregation' ),
			'unfilled'  => __( 'nobody free', 'congregation' ),
		);
		echo '<div class="cg-scroll"><table class="widefat cg-rota"><thead><tr><th>' . esc_html__( 'Role', 'congregation' ) . '</th>';
		foreach ( array_keys( $services ) as $key ) {
			[ $d, $t ] = explode( ' ', $key );
			echo '<th>' . esc_html( (string) wp_date( 'D j M', (int) strtotime( $d ), new \DateTimeZone( 'UTC' ) ) ) . '<br><span class="cg-muted">' . esc_html( $t ) . '</span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( Settings::needs() as $role => $n ) {
			echo '<tr><th scope="row">' . esc_html( RotaService::role_label( $role ) ) . '</th>';
			foreach ( array_keys( $services ) as $key ) {
				echo '<td>';
				foreach ( $grid[ $role ][ $key ] ?? array() as $r ) {
					$st = (string) $r['status'];
					echo '<div class="cg-cell cg-' . esc_attr( $st ) . '">' . ( (int) $r['person_id'] ? esc_html( (string) $r['name'] ) : '&mdash;' ) . ' <small>' . esc_html( $labels[ $st ] ?? $st ) . '</small></div>';
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div><p class="cg-muted">' . esc_html__( 'Declined places are hidden; the person asked instead appears in their place.', 'congregation' ) . '</p></div>';
	}

	/**
	 * People page: list and add/edit form.
	 */
	public static function people(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		self::open( __( 'People', 'congregation' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which person to edit.
		$edit = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$p    = $edit ? Repo::person( $edit ) : null;
		$val  = static fn ( string $k, string $d = '' ): string => esc_attr( null !== $p ? (string) $p[ $k ] : $d );
		echo '<div class="cg-cols"><div><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'congregation' ) . '</th><th>' . esc_html__( 'Roles', 'congregation' ) . '</th><th>' . esc_html__( 'Per month', 'congregation' ) . '</th><th>' . esc_html__( 'Household', 'congregation' ) . '</th><th>' . esc_html__( 'Away', 'congregation' ) . '</th></tr></thead><tbody>';
		foreach ( Repo::people() as $row ) {
			$away = array_filter( explode( ',', (string) $row['away'] ) );
			echo '<tr><td><a href="' . esc_url( add_query_arg( 'edit', (int) $row['id'] ) ) . '">' . esc_html( (string) $row['name'] ) . '</a><br><span class="cg-muted">' . esc_html( (string) $row['email'] ) . '</span></td><td>' . esc_html( implode( ', ', array_map( array( RotaService::class, 'role_label' ), array_filter( explode( ',', (string) $row['roles'] ) ) ) ) ) . '</td><td>' . (int) $row['per_month'] . '</td><td>' . ( (int) $row['household'] ? '#' . (int) $row['household'] . ( (int) $row['together'] ? ' ' . esc_html__( '(together)', 'congregation' ) : '' ) : '&mdash;' ) . '</td><td>' . esc_html( count( $away ) ? sprintf( /* translators: %d: dates. */ _n( '%d date', '%d dates', count( $away ), 'congregation' ), count( $away ) ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table></div><div class="cg-card"><h2>' . ( $p ? esc_html__( 'Edit person', 'congregation' ) : esc_html__( 'Add a person', 'congregation' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="congregation_person"><input type="hidden" name="id" value="' . (int) $edit . '">';
		wp_nonce_field( 'congregation_person' );
		echo '<p><label>' . esc_html__( 'Name', 'congregation' ) . '<input class="widefat" name="name" required value="' . $val( 'name' ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $val escapes.
		echo '<p><label>' . esc_html__( 'Email', 'congregation' ) . '<input class="widefat" type="email" name="email" value="' . $val( 'email' ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $val escapes.
		echo '<p><label>' . esc_html__( 'Phone', 'congregation' ) . '<input class="widefat" name="phone" value="' . $val( 'phone' ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $val escapes.
		$mine = null !== $p ? array_filter( explode( ',', (string) $p['roles'] ) ) : array();
		echo '<fieldset><legend>' . esc_html__( 'Can serve as', 'congregation' ) . '</legend>';
		foreach ( array_keys( Settings::needs() ) as $role ) {
			echo '<label class="cg-check"><input type="checkbox" name="roles[]" value="' . esc_attr( $role ) . '" ' . checked( in_array( $role, $mine, true ), true, false ) . '> ' . esc_html( RotaService::role_label( $role ) ) . '</label>';
		}
		echo '</fieldset><p><label>' . esc_html__( 'Happy to serve (times a month)', 'congregation' ) . ' <input type="number" min="0" max="5" name="per_month" value="' . $val( 'per_month', '2' ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $val escapes.
		echo '<p><label>' . esc_html__( 'Household number (people with the same number are one household)', 'congregation' ) . ' <input type="number" min="0" name="household" value="' . $val( 'household', '0' ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $val escapes.
		echo '<p><label><input type="checkbox" name="together" value="1" ' . checked( null !== $p && (int) $p['together'], true, false ) . '> ' . esc_html__( 'Prefers to serve at the same service as their household', 'congregation' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Preferred service time (blank for any)', 'congregation' ) . ' <input name="prefers" placeholder="09:00" size="6" value="' . $val( 'prefers' ) . '"></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $val escapes.
		echo '<p><label>' . esc_html__( 'Away on (dates, one per line)', 'congregation' ) . '<textarea class="widefat" rows="3" name="away">' . esc_textarea( null !== $p ? str_replace( ',', "\n", (string) $p['away'] ) : '' ) . '</textarea></label></p>';
		submit_button( $p ? __( 'Save', 'congregation' ) : __( 'Add person', 'congregation' ) );
		echo '</form></div></div></div>';
	}

	/**
	 * Giving page: record gifts, recent gifts, and statements.
	 */
	public static function giving(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Which statement to show.
		$person = isset( $_GET['statement'] ) ? absint( $_GET['statement'] ) : 0;
		$year   = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : (int) gmdate( 'Y' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $person ) {
			self::statement( $person, $year );
			return;
		}
		self::open( __( 'Giving', 'congregation' ) );
		$people = Repo::people();
		$opts   = '';
		foreach ( $people as $p ) {
			$opts .= '<option value="' . (int) $p['id'] . '">' . esc_html( (string) $p['name'] ) . '</option>';
		}
		$funds = '';
		foreach ( Settings::funds() as $f ) {
			$funds .= '<option>' . esc_html( $f ) . '</option>';
		}
		echo '<div class="cg-card"><h2>' . esc_html__( 'Record a gift', 'congregation' ) . '</h2>' . self::form( 'gift', '<select name="person" required>' . $opts . '</select> <input type="date" name="date" required value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"> <input name="amount" placeholder="50.00" required size="8"> <select name="fund">' . $funds . '</select> <select name="method"><option value="bank">' . esc_html__( 'bank transfer', 'congregation' ) . '</option><option value="card">' . esc_html__( 'card', 'congregation' ) . '</option><option value="cash">' . esc_html__( 'cash', 'congregation' ) . '</option><option value="cheque">' . esc_html__( 'cheque', 'congregation' ) . '</option></select>', __( 'Record', 'congregation' ), 'button button-primary' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form() and the option lists escape.
		echo '<h2>' . esc_html__( 'Statements', 'congregation' ) . '</h2><p>';
		foreach ( $people as $p ) {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page'      => self::SLUG . '-giving',
						'statement' => (int) $p['id'],
						'year'      => (int) gmdate( 'Y' ),
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html( (string) $p['name'] ) . '</a> ';
		}
		echo '</p><h2>' . esc_html__( 'Recent gifts', 'congregation' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'congregation' ) . '</th><th>' . esc_html__( 'Person', 'congregation' ) . '</th><th>' . esc_html__( 'Fund', 'congregation' ) . '</th><th>' . esc_html__( 'Method', 'congregation' ) . '</th><th class="num">' . esc_html__( 'Amount', 'congregation' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( Repo::recent_gifts() as $g ) {
			echo '<tr' . ( (int) $g['refunded'] ? ' class="cg-refunded"' : '' ) . '><td>' . esc_html( (string) $g['given_on'] ) . '</td><td>' . esc_html( (string) $g['name'] ) . '</td><td>' . esc_html( (string) $g['fund'] ) . '</td><td>' . esc_html( (string) $g['method'] ) . '</td><td class="num">' . esc_html( Statement::money( (int) $g['amount_cents'] ) ) . '</td><td>' . ( (int) $g['refunded'] ? esc_html__( 'refunded', 'congregation' ) : self::form( 'refund', '<input type="hidden" name="gift" value="' . (int) $g['id'] . '">', __( 'Refund', 'congregation' ), 'button-link' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form() escapes.
		}
		echo '</tbody></table></div>';
	}

	/**
	 * A printable year-end statement.
	 *
	 * @param int $person Person id.
	 * @param int $year   Year.
	 */
	private static function statement( int $person, int $year ): void {
		$p = Repo::person( $person );
		if ( null === $p ) {
			return;
		}
		$s = new Statement( $year, Repo::gifts( $person, $year ) );
		$c = Settings::get();
		echo '<div class="wrap congregation cg-statement"><p class="cg-noprint"><a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG . '-giving' ), admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'Giving', 'congregation' ) . '</a> · <a href="' . esc_url( add_query_arg( 'year', $year - 1 ) ) . '">' . (int) ( $year - 1 ) . '</a> · <a href="#" onclick="window.print();return false">' . esc_html__( 'Print', 'congregation' ) . '</a></p>';
		/* translators: %d: year. */
		echo '<h1>' . esc_html( $c['church'] ) . '</h1><h2>' . esc_html( sprintf( __( 'Giving statement %d', 'congregation' ), $year ) ) . '</h2><p><strong>' . esc_html( (string) $p['name'] ) . '</strong><br>' . esc_html( (string) $p['email'] ) . '</p>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Date', 'congregation' ) . '</th><th>' . esc_html__( 'Fund', 'congregation' ) . '</th><th>' . esc_html__( 'Method', 'congregation' ) . '</th><th class="num">' . esc_html__( 'Amount', 'congregation' ) . '</th></tr></thead><tbody>';
		foreach ( $s->lines as $l ) {
			echo '<tr' . ( $l['refunded'] ? ' class="cg-refunded"' : '' ) . '><td>' . esc_html( $l['date'] ) . '</td><td>' . esc_html( $l['fund'] ) . '</td><td>' . esc_html( $l['method'] ) . ( $l['refunded'] ? ' (' . esc_html__( 'refunded, not counted', 'congregation' ) . ')' : '' ) . '</td><td class="num">' . esc_html( Statement::money( $l['cents'] ) ) . '</td></tr>';
		}
		echo '</tbody><tfoot>';
		foreach ( $s->by_fund as $fund => $cents ) {
			echo '<tr><td colspan="3">' . esc_html( $fund ) . '</td><td class="num">' . esc_html( Statement::money( $cents ) ) . '</td></tr>';
		}
		echo '<tr class="cg-total"><td colspan="3">' . esc_html__( 'Total', 'congregation' ) . '</td><td class="num">' . esc_html( Statement::money( $s->total ) ) . '</td></tr></tfoot></table><p>' . esc_html( $c['statement'] ) . '</p></div>';
	}

	/**
	 * Settings page.
	 */
	public static function settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::open( __( 'Congregation settings', 'congregation' ) );
		$s = Settings::get();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="congregation_settings">';
		wp_nonce_field( 'congregation_settings' );
		echo '<table class="form-table"><tr><th><label for="cg-church">' . esc_html__( 'Church name', 'congregation' ) . '</label></th><td><input id="cg-church" class="regular-text" name="church" value="' . esc_attr( $s['church'] ) . '"></td></tr>';
		echo '<tr><th><label for="cg-times">' . esc_html__( 'Service times', 'congregation' ) . '</label></th><td><input id="cg-times" class="regular-text" name="times" value="' . esc_attr( $s['times'] ) . '"><p class="description">' . esc_html__( 'For example "Sun 09:00, Sun 11:00".', 'congregation' ) . '</p></td></tr>';
		echo '<tr><th><label for="cg-needs">' . esc_html__( 'Roles at each service', 'congregation' ) . '</label></th><td><textarea id="cg-needs" class="large-text code" rows="9" name="needs">' . esc_textarea( $s['needs'] ) . '</textarea><p class="description">' . esc_html__( 'One role per line with how many people it needs, like "welcome: 2".', 'congregation' ) . '</p></td></tr>';
		echo '<tr><th><label for="cg-funds">' . esc_html__( 'Giving funds', 'congregation' ) . '</label></th><td><textarea id="cg-funds" class="regular-text" rows="4" name="funds">' . esc_textarea( $s['funds'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="cg-statement">' . esc_html__( 'Statement footer', 'congregation' ) . '</label></th><td><textarea id="cg-statement" class="large-text" rows="3" name="statement">' . esc_textarea( $s['statement'] ) . '</textarea><p class="description">' . esc_html__( 'Printed on every giving statement (for example a charity number and the wording your tax rules require).', 'congregation' ) . '</p></td></tr></table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Nonce and capability check.
	 *
	 * @param string $action Action suffix.
	 * @param string $cap    Capability.
	 */
	private static function guard( string $action, string $cap = 'edit_others_posts' ): void {
		check_admin_referer( 'congregation_' . $action );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'congregation' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Back to a page with a message.
	 *
	 * @param string               $page  Page slug.
	 * @param string               $msg   Message.
	 * @param array<string, mixed> $extra More query args.
	 */
	private static function back( string $page, string $msg, array $extra = array() ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'   => $page,
						'cg_msg' => rawurlencode( $msg ),
					),
					$extra
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler below calls guard() first.

	/**
	 * Save a person.
	 */
	public static function handle_person(): void {
		self::guard( 'person' );
		$away = isset( $_POST['away'] ) ? sanitize_textarea_field( wp_unslash( $_POST['away'] ) ) : '';
		$id   = Repo::save_person(
			array(
				'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'email'     => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'phone'     => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
				'household' => isset( $_POST['household'] ) ? absint( $_POST['household'] ) : 0,
				'roles'     => isset( $_POST['roles'] ) ? array_values( array_intersect( array_map( 'sanitize_title', (array) wp_unslash( $_POST['roles'] ) ), array_keys( Settings::needs() ) ) ) : array(),
				'per_month' => isset( $_POST['per_month'] ) ? absint( $_POST['per_month'] ) : 2,
				'together'  => ! empty( $_POST['together'] ),
				'prefers'   => isset( $_POST['prefers'] ) && preg_match( '/^\d{2}:\d{2}$/', sanitize_text_field( wp_unslash( $_POST['prefers'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['prefers'] ) ) : '',
				'away'      => array_values( array_filter( array_map( 'trim', explode( "\n", $away ) ), static fn ( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) ),
			),
			isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0
		);
		self::back( self::SLUG . '-people', __( 'Saved.', 'congregation' ), array( 'edit' => $id ) );
	}

	/**
	 * The date range posted.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function posted_range(): array {
		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			self::back( self::SLUG, __( 'Choose a date range.', 'congregation' ) );
		}
		return array( $from, $to );
	}

	/**
	 * Plan.
	 */
	public static function handle_plan(): void {
		self::guard( 'plan' );
		[ $from, $to ] = self::posted_range();
		$r             = RotaService::plan( $from, $to );
		self::back(
			self::SLUG,
			/* translators: 1: places filled, 2: places nobody could take. */
			sprintf( __( 'Planned %1$d places. %2$d could not be filled by anyone free within their limits.', 'congregation' ), $r['planned'], $r['unfilled'] ),
			array(
				'from' => $from,
				'to'   => $to,
			)
		);
	}

	/**
	 * Email requests.
	 */
	public static function handle_ask(): void {
		self::guard( 'ask' );
		[ $from, $to ] = self::posted_range();
		self::back(
			self::SLUG,
			/* translators: %d: emails. */
			sprintf( __( 'Sent %d requests.', 'congregation' ), RotaService::ask( $from, $to ) ),
			array(
				'from' => $from,
				'to'   => $to,
			)
		);
	}

	/**
	 * Record a gift.
	 */
	public static function handle_gift(): void {
		self::guard( 'gift', 'manage_options' );
		$cents = Statement::cents( isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '' );
		$date  = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$fund  = isset( $_POST['fund'] ) ? sanitize_text_field( wp_unslash( $_POST['fund'] ) ) : 'General';
		if ( null === $cents || 0 === $cents || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			self::back( self::SLUG . '-giving', __( 'Enter a date and an amount such as 50 or 49.99.', 'congregation' ) );
		}
		Repo::add_gift( isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0, $date, (int) $cents, $fund, isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'bank' );
		self::back( self::SLUG . '-giving', __( 'Gift recorded.', 'congregation' ) );
	}

	/**
	 * Refund a gift.
	 */
	public static function handle_refund(): void {
		self::guard( 'refund', 'manage_options' );
		Repo::refund_gift( isset( $_POST['gift'] ) ? absint( $_POST['gift'] ) : 0 );
		self::back( self::SLUG . '-giving', __( 'Marked as refunded. It no longer counts on statements.', 'congregation' ) );
	}

	/**
	 * Save settings.
	 */
	public static function handle_settings(): void {
		self::guard( 'settings', 'manage_options' );
		$s = array();
		foreach ( array( 'church', 'times' ) as $k ) {
			$s[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		foreach ( array( 'needs', 'funds', 'statement' ) as $k ) {
			$s[ $k ] = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		update_option( Settings::OPTION, $s );
		self::back( self::SLUG . '-settings', __( 'Settings saved.', 'congregation' ) );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
