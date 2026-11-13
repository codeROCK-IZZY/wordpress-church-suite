<?php
/**
 * Seeds a demo church for the screenshots in docs/images (run with: wp eval-file demo.php): sixteen people, a month
 * of rota planned and requested with some answers in, and one person's year of giving. All names are invented.
 * Prints the giver's id and the rota's first date.
 *
 * @package Congregation
 */

use Congregation\Data\Repo;
use Congregation\Data\Schema;
use Congregation\RotaService;
use Congregation\Settings;

global $wpdb;
foreach ( array( 'assignments', 'services', 'gifts', 'people' ) as $t ) {
	$wpdb->query( 'DELETE FROM ' . Schema::t( $t ) );
}
update_option(
	Settings::OPTION,
	array(
		'church' => 'Harbour Church',
		'times'  => 'Sun 09:00, Sun 11:00',
		'needs'  => "worship-leader: 1\nsound: 1\nkids-leader: 1\nwelcome: 2",
	)
);
$from   = gmdate( 'Y-m-d', strtotime( 'next sunday' ) );
$second = gmdate( 'Y-m-d', strtotime( $from . ' +1 week' ) );
$people = array(
	array( 'Grace Adeyemi', array( 'worship-leader', 'welcome' ), 2, '', 0, false, array() ),
	array( 'Tom Price', array( 'sound', 'welcome' ), 3, '09:00', 0, false, array() ),
	array( 'Mei Lin', array( 'sound' ), 2, '', 0, false, array( $second ) ),
	array( 'Sam Okoro', array( 'kids-leader', 'welcome' ), 2, '11:00', 0, false, array() ),
	array( 'Ruth Allen', array( 'kids-leader', 'welcome' ), 3, '', 1, true, array() ),
	array( 'Dan Allen', array( 'worship-leader', 'welcome' ), 2, '', 1, true, array() ),
	array( 'Priya Nair', array( 'welcome' ), 2, '', 0, false, array() ),
	array( 'Joe Brook', array( 'welcome', 'sound' ), 1, '', 0, false, array() ),
	array( 'Ana Silva', array( 'welcome' ), 2, '09:00', 0, false, array() ),
	array( 'Leo Grant', array( 'kids-leader' ), 2, '', 0, false, array() ),
	array( 'Kemi Bello', array( 'worship-leader' ), 2, '', 2, true, array() ),
	array( 'Noah Bello', array( 'welcome', 'sound' ), 2, '', 2, true, array() ),
	array( 'Hannah Becker', array( 'welcome' ), 2, '', 0, false, array( $from ) ),
	array( 'Wei Chen', array( 'kids-leader', 'welcome' ), 2, '', 0, false, array() ),
	array( 'Sofia Rossi', array( 'welcome' ), 3, '11:00', 0, false, array() ),
	array( 'Kwame Mensah', array( 'sound', 'welcome' ), 2, '', 0, false, array() ),
);
$tom    = 0;
foreach ( $people as [ $name, $roles, $per, $prefers, $house, $together, $away ] ) {
	$id  = Repo::save_person(
		array(
			'name'      => $name,
			'email'     => strtolower( str_replace( ' ', '.', $name ) ) . '@example.com',
			'phone'     => '+44 20 7946 0' . wp_rand( 100, 999 ),
			'roles'     => $roles,
			'per_month' => $per,
			'prefers'   => $prefers,
			'household' => $house,
			'together'  => $together,
			'away'      => $away,
		)
	);
	$tom = 'Tom Price' === $name ? $id : $tom;
}
$to = gmdate( 'Y-m-d', strtotime( $from . ' +3 weeks' ) );
RotaService::plan( $from, $to );
RotaService::ask( $from, $to );
foreach ( Repo::assignments( $from, $to ) as $i => $a ) {
	if ( 'asked' === $a['status'] && 0 !== $i % 3 ) {
		Repo::update_assignment( (int) $a['id'], array( 'status' => 'confirmed' ) );
	}
}
foreach ( array( '01', '02', '03', '04', '05', '06', '07', '08', '09', '10' ) as $m ) {
	Repo::add_gift( $tom, "2026-{$m}-01", 15000, 'General', 'bank' );
}
Repo::add_gift( $tom, '2026-03-15', 50000, 'Building', 'bank' );
Repo::add_gift( $tom, '2026-06-21', 7500, 'Missions', 'card' );
Repo::refund_gift( Repo::add_gift( $tom, '2026-08-09', 2500, 'General', 'card' ) );
echo $tom . ' ' . $from . "\n";
