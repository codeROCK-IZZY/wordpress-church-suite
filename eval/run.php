<?php
/**
 * Offline evaluation: rotas for a quarter of Sundays.
 * Writes eval/reports/report.{json,md,html}. With --gate, exits 1 if a regression limit is crossed.
 *
 * Usage: php eval/run.php [--gate]
 *
 * Banks of simulated churches, 13 Sundays each:
 * - tuning (churches 1-15): the scheduler's weights were set here;
 * - held-out (churches 101-140), run after freezing;
 * - different shapes (churches 201-210), held back: very small churches (18-26 volunteers, one service) and large
 *   ones (140-200 volunteers, three services).
 *
 * @package Congregation
 */

namespace Congregation\Evaluation;

use Congregation\Core\Scheduler;

require dirname( __DIR__ ) . '/src/autoload.php';
require __DIR__ . '/Church.php';
require __DIR__ . '/Baselines.php';
require __DIR__ . '/Measure.php';
require __DIR__ . '/report.php';

$gate    = in_array( '--gate', $argv, true );
$banks   = array(
	'tuning'    => array( range( 1, 15 ), false, 'Tuning churches' ),
	'heldout'   => array( range( 101, 140 ), false, 'Held-out churches' ),
	'different' => array( range( 201, 210 ), true, 'Very small and very large churches (held back)' ),
);
$methods = array(
	'teams'  => 'Team spreadsheets (each leader rotates their own list)',
	'first'  => 'One coordinator, first person who can',
	'plugin' => 'This plugin',
);

/**
 * Results.
 *
 * @var array<string, mixed> $result
 */
$result = array( 'banks' => array() );
foreach ( $banks as $key => [ $seeds, $different, $label ] ) {
	$bank = array(
		'label'    => $label,
		'churches' => count( $seeds ),
		'methods'  => array(),
	);
	foreach ( $methods as $method => $m_label ) {
		$tot = array();
		foreach ( $seeds as $seed ) {
			$c     = new Church( $seed, $different );
			$sched = new Scheduler();
			$rota  = 'teams' === $method ? Baselines::teams( $c->rota() ) : ( 'first' === $method ? Baselines::first( $c->rota() ) : $sched->plan( $c->rota() ) );
			foreach ( Measure::rota( $rota, $c, Measure::replacer( $method, $sched ) ) as $k => $v ) {
				$tot[ $k ] = ( $tot[ $k ] ?? 0 ) + $v;
			}
		}
		$bank['methods'][ $method ] = array(
			'label'                 => $m_label,
			'places'                => (int) $tot['places'],
			'filled'                => round( $tot['filled'] / $tot['places'], 4 ),
			'filled_after_declines' => round( $tot['filled_after_declines'] / $tot['places'], 4 ),
			'double_booked'         => (int) $tot['double_booked'],
			'over_limit'            => (int) $tot['over_limit'],
			'load_spread'           => round( $tot['load_spread'] / count( $seeds ), 3 ),
			'unused'                => round( $tot['unused'] / $tot['volunteers'], 4 ),
			'consecutive'           => round( $tot['consecutive'] / max( 1, $tot['served_dates'] ), 4 ),
			'household_split'       => round( $tot['household_split'] / max( 1, $tot['household_dates'] ), 4 ),
			'volunteers'            => (int) $tot['volunteers'],
		);
	}
	$result['banks'][ $key ] = $bank;
}

/**
 * Methods per bank.
 *
 * @var array<string, array<string, float|int>> $h
 * @var array<string, array<string, float|int>> $d
 */
$h      = $result['banks']['heldout']['methods'];
$d      = $result['banks']['different']['methods'];
$checks = array(
	'heldout_double_booked_max'        => $h['plugin']['double_booked'] + $h['plugin']['over_limit'],
	'heldout_filled_vs_first_min'      => round( (float) $h['plugin']['filled'] - (float) $h['first']['filled'], 4 ),
	'heldout_unused_max'               => $h['plugin']['unused'],
	'heldout_consecutive_vs_first_max' => round( (float) $h['plugin']['consecutive'] - (float) $h['first']['consecutive'], 4 ),
	'different_filled_vs_first_min'    => round( (float) $d['plugin']['filled'] - (float) $d['first']['filled'], 4 ),
	'different_double_booked_max'      => $d['plugin']['double_booked'] + $d['plugin']['over_limit'],
);
$limits = array(
	'heldout_double_booked_max'        => 0,
	'heldout_filled_vs_first_min'      => 0.0,
	'heldout_unused_max'               => 0.05,
	'heldout_consecutive_vs_first_max' => -0.1,
	'different_filled_vs_first_min'    => -0.01,
	'different_double_booked_max'      => 0,
);
$passed = array();
foreach ( $checks as $k => $v ) {
	$passed[ $k ] = str_ends_with( $k, '_max' ) ? $v <= $limits[ $k ] : $v >= $limits[ $k ];
}
$result += array(
	'checks' => $checks,
	'limits' => $limits,
	'passed' => $passed,
	'ok'     => ! in_array( false, $passed, true ),
);
$dir     = __DIR__ . '/reports';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
file_put_contents( $dir . '/report.json', json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
file_put_contents( $dir . '/report.md', report_markdown( $result ) );
file_put_contents( $dir . '/report.html', report_html( $result ) );
echo report_markdown( $result );
exit( $gate && ! $result['ok'] ? 1 : 0 );
