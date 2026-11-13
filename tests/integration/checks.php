<?php
/**
 * Integration checks inside a real WordPress (run with: wp eval-file checks.php).
 * People, a planned and requested rota, answers and replacements, giving statements, sermons and privacy.
 * Leaves one accept link and one decline link in the option congregation_test_links for run.sh to open over HTTP.
 *
 * @package Congregation
 */

use Congregation\Core\Statement;
use Congregation\Data\Repo;
use Congregation\Data\Schema;
use Congregation\Privacy\Privacy;
use Congregation\RotaService;
use Congregation\Settings;

$failures = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $name . ( $ok || '' === $detail ? '' : ' (' . $detail . ')' ) . "\n";
	$failures += $ok ? 0 : 1;
};
global $wpdb;
foreach ( array( 'assignments', 'services', 'gifts', 'people' ) as $t ) {
	$wpdb->query( 'DELETE FROM ' . Schema::t( $t ) );
}
delete_option( 'congregation_test_mail' );
update_option(
	Settings::OPTION,
	array(
		'church' => 'Harbour Church',
		'times'  => 'Sun 09:00, Sun 11:00',
		'needs'  => "sound: 1\nkids-leader: 1\nwelcome: 2",
	)
);
$check( 'tables created', 4 === count( array_filter( array( 'people', 'services', 'assignments', 'gifts' ), static fn ( string $t ): bool => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::t( $t ) ) ) ) ) );
$check( 'service times and roles read from settings', array( array( 7, '09:00' ), array( 7, '11:00' ) ) === Settings::times() && 2 === Settings::needs()['welcome'] );

$names = array( 'Grace Adeyemi', 'Tom Price', 'Mei Lin', 'Sam Okoro', 'Ruth Allen', 'Dan Allen', 'Priya Nair', 'Joe Brook', 'Ana Silva', 'Leo Grant', 'Kemi Bello', 'Noah Reed' );
$ids   = array();
foreach ( $names as $i => $n ) {
	$roles = array( 'welcome' );
	if ( $i < 3 ) {
		$roles[] = 'sound';
	}
	if ( $i >= 3 && $i < 6 ) {
		$roles[] = 'kids-leader';
	}
	$ids[] = Repo::save_person(
		array(
			'name'      => $n,
			'email'     => strtolower( str_replace( ' ', '.', $n ) ) . '@example.com',
			'roles'     => $roles,
			'per_month' => 3,
			'household' => in_array( $i, array( 4, 5 ), true ) ? 1 : 0,
			'together'  => in_array( $i, array( 4, 5 ), true ),
		)
	);
}
$from = gmdate( 'Y-m-d', strtotime( 'next sunday' ) );
$to   = gmdate( 'Y-m-d', strtotime( $from . ' +3 weeks' ) );
$r    = RotaService::plan( $from, $to );
$check( 'a month of rota is planned: 8 services x 4 places', 32 === $r['planned'] + $r['unfilled'], wp_json_encode( $r ) );
$dup = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT service_id, person_id FROM ' . Schema::t( 'assignments' ) . ' WHERE person_id > 0 GROUP BY service_id, person_id HAVING COUNT(*) > 1) d' );
$check( 'nobody has two roles at one service', 0 === $dup, (string) $dup );
$over = 0;
foreach ( $ids as $id ) {
	$over += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GREATEST(0, MAX(n) - 3) FROM (SELECT COUNT(*) n FROM ' . Schema::t( 'assignments' ) . ' a JOIN ' . Schema::t( 'services' ) . ' s ON s.id = a.service_id WHERE a.person_id = %d GROUP BY LEFT(s.date, 7)) m', $id ) );
}
$check( 'nobody is asked more than they offered in a month', 0 === $over );
$again = RotaService::plan( $from, $to );
$check( 'planning again adds nothing (filled places are kept)', 0 === $again['planned'] && $again['unfilled'] === $r['unfilled'], wp_json_encode( $again ) );

$sent = RotaService::ask( $from, $to );
$mail = (array) get_option( 'congregation_test_mail', array() );
$check( 'one request email per planned place, with accept and decline links', $sent === $r['planned'] && str_contains( (string) wp_json_encode( $mail ), 'answer=decline' ), $sent . ' sent' );
$check( 'requests are sent once', 0 === RotaService::ask( $from, $to ) );

$rows   = Repo::assignments( $from, $to );
$asked  = array_values( array_filter( $rows, static fn ( array $a ): bool => 'asked' === $a['status'] ) );
$first  = $asked[0];
$second = $asked[1];
update_option(
	'congregation_test_links',
	array(
		'accept'  => RotaService::link( $first, 'accept' ),
		'decline' => RotaService::link( $second, 'decline' ),
		'second'  => (int) $second['id'],
		'person'  => (int) $second['person_id'],
		'max'     => 0,
	)
);
$forged = RotaService::answer( $first['id'] . '.' . str_repeat( '0', 64 ), 'decline' );
$check( 'a forged link changes nothing', str_contains( $forged, 'not valid' ) && 'asked' === Repo::assignment( (int) $first['id'] )['status'] );

$third = $asked[2];
delete_option( 'congregation_test_mail' );
$last = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Schema::t( 'assignments' ) ) );
$msg  = RotaService::answer( Settings::tokens()->issue( (int) $third['id'], (int) $third['person_id'] ), 'decline' );
$new  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d', Schema::t( 'assignments' ), $last ), ARRAY_A );
$check( 'a decline asks the best other person straight away', str_contains( $msg, 'ask someone else' ) && is_array( $new ) && (int) $new['person_id'] > 0 && (int) $new['person_id'] !== (int) $third['person_id'] && 'asked' === $new['status'], wp_json_encode( $new ) );
$check( 'and emails them', is_array( $new ) && str_contains( (string) wp_json_encode( get_option( 'congregation_test_mail' ) ), (string) Repo::person( (int) $new['person_id'] )['email'] ) );
$check( 'accepting after declining is refused', str_contains( RotaService::answer( Settings::tokens()->issue( (int) $third['id'], (int) $third['person_id'] ), 'accept' ), 'already said' ) );

$person = $ids[0];
Repo::add_gift( $person, '2026-01-04', 5000, 'General', 'bank' );
Repo::add_gift( $person, '2026-03-01', 2550, 'Building', 'card' );
$refund = Repo::add_gift( $person, '2026-04-05', 10000, 'General', 'card' );
Repo::add_gift( $person, '2025-12-28', 999, 'General', 'cash' );
Repo::refund_gift( $refund );
$s = new Statement( 2026, Repo::gifts( $person, 2026 ) );
$check( 'the 2026 statement counts its gifts, lists the refund, and leaves 2025 out', 7550 === $s->total && 3 === count( $s->lines ) && 5000 === $s->by_fund['General'], (string) $s->total );

$sermon = wp_insert_post(
	array(
		'post_type'    => 'congregation_sermon',
		'post_status'  => 'publish',
		'post_title'   => 'Living Water',
		'post_excerpt' => 'Jesus and the woman at the well.',
	)
);
update_post_meta( $sermon, '_congregation_audio_url', 'https://media.example.com/2026-06-07.mp3' );
update_post_meta( $sermon, '_congregation_audio_bytes', 18342011 );
update_post_meta( $sermon, '_congregation_scripture', 'John 4:1-26' );
wp_set_object_terms( $sermon, 'Ruth Allen', 'congregation_speaker' );
wp_set_object_terms( $sermon, 'Encounters with Jesus', 'congregation_series' );
$list = do_shortcode( '[congregation_sermons]' );
$check( 'the sermon list shows speaker, series, scripture and a player', str_contains( $list, 'Living Water' ) && str_contains( $list, 'Ruth Allen · Encounters with Jesus · John 4:1-26' ) && str_contains( $list, 'wp-audio-shortcode' ) );

$export = Privacy::export( 'grace.adeyemi@example.com' );
$check( 'privacy export includes the person and their gifts', 1 + 4 === count( $export['data'] ) );
$kemi  = (int) Repo::by_email( 'kemi.bello@example.com' )[0]['id'];
$erase = Privacy::erase( 'kemi.bello@example.com' );
$check( 'privacy erase removes contact details', $erase['items_removed'] && 0 === count( Repo::by_email( 'kemi.bello@example.com' ) ) );
$check( 'and releases their upcoming rota places', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE person_id = %d', Schema::t( 'assignments' ), $kemi ) ) );
$erase = Privacy::erase( 'grace.adeyemi@example.com' );
$check( 'and keeps gift amounts, saying why', $erase['items_retained'] && 1 === count( $erase['messages'] ) && 7550 === ( new Statement( 2026, Repo::gifts( $person, 2026 ) ) )->total );

$user = get_user_by( 'login', 'admin' );
if ( $user ) {
	$pid = Repo::save_person(
		array(
			'name'  => 'Site Admin',
			'email' => $user->user_email,
			'roles' => array( 'welcome' ),
		)
	);
	$wpdb->insert(
		Schema::t( 'assignments' ),
		array(
			'service_id' => (int) $first['service_id'],
			'role'       => 'welcome',
			'person_id'  => $pid,
			'status'     => 'confirmed',
		)
	);
	wp_set_current_user( $user->ID );
	$check( 'members see their own upcoming places', str_contains( do_shortcode( '[congregation_my_rota]' ), 'Welcome' ) );
	wp_set_current_user( 0 );
	$check( 'and nobody else\'s when logged out', str_contains( do_shortcode( '[congregation_my_rota]' ), 'Log in' ) );
}

$links        = (array) get_option( 'congregation_test_links' );
$links['max'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Schema::t( 'assignments' ) ) );
update_option( 'congregation_test_links', $links );

echo $failures ? "\n{$failures} integration check(s) failed\n" : "\nall integration checks passed\n";
if ( $failures ) {
	WP_CLI::halt( 1 );
}
