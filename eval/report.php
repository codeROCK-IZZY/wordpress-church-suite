<?php
/**
 * Markdown and HTML versions of the evaluation.
 *
 * @package Congregation
 */

namespace Congregation\Evaluation;

/**
 * Percentage.
 *
 * @param float|int $x Fraction.
 */
function pct( float|int $x ): string {
	return number_format( 100 * $x, 1 ) . '%';
}

const COLUMNS = array(
	'filled'                => 'Places filled',
	'filled_after_declines' => 'Filled after declines',
	'double_booked'         => 'Two roles at one service',
	'over_limit'            => 'Times over a person\'s monthly limit',
	'unused'                => 'Volunteers never asked',
	'consecutive'           => 'Back-to-back Sundays',
	'household_split'       => 'Households split',
	'load_spread'           => 'Load spread',
);

/**
 * A cell.
 *
 * @param string    $k Column.
 * @param float|int $v Value.
 */
function cell( string $k, float|int $v ): string {
	return in_array( $k, array( 'double_booked', 'over_limit' ), true ) ? number_format( (int) $v ) : ( 'load_spread' === $k ? number_format( (float) $v, 3 ) : pct( $v ) );
}

/**
 * Markdown report.
 *
 * @param array<string, mixed> $r Result.
 */
function report_markdown( array $r ): string {
	$out = array( '# Volunteer rotas: evaluation', '', 'Simulated churches, a quarter (13 Sundays) each. Load spread is the standard deviation of how much of their offered time each volunteer is asked for.', '' );
	foreach ( $r['banks'] as $bank ) {
		$any   = reset( $bank['methods'] );
		$out[] = sprintf( '## %s: %d churches, %s volunteers, %s places', $bank['label'], $bank['churches'], number_format( $any['volunteers'] ), number_format( $any['places'] ) );
		$out[] = '';
		$out[] = '| Method | ' . implode( ' | ', COLUMNS ) . ' |';
		$out[] = '|---|' . str_repeat( '---:|', count( COLUMNS ) );
		foreach ( $bank['methods'] as $m ) {
			$row = array();
			foreach ( array_keys( COLUMNS ) as $k ) {
				$row[] = cell( $k, $m[ $k ] );
			}
			$out[] = '| ' . $m['label'] . ' | ' . implode( ' | ', $row ) . ' |';
		}
		$out[] = '';
	}
	$out[] = '## Gate (regression limits, not targets)';
	$out[] = '';
	foreach ( $r['checks'] as $k => $v ) {
		$out[] = sprintf( '- %s %s: %s (limit %s)', $r['passed'][ $k ] ? 'PASS' : 'FAIL', $k, $v, $r['limits'][ $k ] );
	}
	return implode( "\n", $out ) . "\n";
}

/**
 * HTML report.
 *
 * @param array<string, mixed> $r Result.
 */
function report_html( array $r ): string {
	$e     = static fn ( mixed $s ): string => htmlspecialchars( (string) $s, ENT_QUOTES );
	$h     = $r['banks']['heldout']['methods'];
	$tiles = array(
		array( pct( $h['plugin']['filled'] ), 'of places filled without breaking a rule (one coordinator: ' . pct( $h['first']['filled'] ) . ')' ),
		array( number_format( $h['teams']['double_booked'] + $h['teams']['over_limit'] ) . ' vs 0', 'broken rules (two roles at once, over a monthly limit) with team spreadsheets' ),
		array( pct( $h['plugin']['unused'] ), 'of volunteers never asked (one coordinator: ' . pct( $h['first']['unused'] ) . ')' ),
		array( pct( $h['plugin']['consecutive'] ), 'of Sundays served straight after another (one coordinator: ' . pct( $h['first']['consecutive'] ) . ')' ),
	);
	$css   = ':root{--bg:#0d1016;--panel:#151b24;--line:#263042;--ink:#e8edf4;--ink2:#97a3b6;--ok:#3ccf8e;--bad:#ff6b6b}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 Inter,system-ui,sans-serif}main{max-width:1180px;margin:0 auto;padding:34px 28px 56px}h1{font-size:28px;margin:0 0 4px}h2{font-size:18px;margin:28px 0 12px}.sub{color:var(--ink2);margin:0 0 20px}.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.tile{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px 18px}.tile b{display:block;font-size:26px}.tile span{color:var(--ink2);font-size:13px}table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden;font-size:13px}th,td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:right}th:first-child,td:first-child{text-align:left}th{color:var(--ink2);font-weight:500;font-size:11.5px}.good{color:var(--ok);font-weight:600}.bad{color:var(--bad);font-weight:600}@media(max-width:800px){.tiles{grid-template-columns:1fr 1fr}}';
	$out   = array( "<!doctype html><html lang='en'><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Volunteer rota evaluation</title><style>{$css}</style><main><h1>Volunteer rotas: evaluation</h1><p class='sub'>Simulated churches, 13 Sundays each, with holidays, monthly limits, households and declines. Weights set on separate tuning churches.</p><div class='tiles'>" );
	foreach ( $tiles as [ $v, $t ] ) {
		$out[] = "<div class='tile'><b>{$e($v)}</b><span>{$e($t)}</span></div>";
	}
	$out[] = '</div>';
	foreach ( $r['banks'] as $key => $bank ) {
		if ( 'tuning' === $key ) {
			continue;
		}
		$out[] = "<h2>{$e($bank['label'])}</h2><table><tr><th>Method</th>";
		foreach ( COLUMNS as $label ) {
			$out[] = "<th>{$e($label)}</th>";
		}
		$out[] = '</tr>';
		foreach ( $bank['methods'] as $m ) {
			$out[] = "<tr><td>{$e($m['label'])}</td>";
			foreach ( array_keys( COLUMNS ) as $k ) {
				$bad   = in_array( $k, array( 'double_booked', 'over_limit' ), true ) && $m[ $k ] > 0;
				$out[] = "<td class='" . ( $bad ? 'bad' : '' ) . "'>" . cell( $k, $m[ $k ] ) . '</td>';
			}
			$out[] = '</tr>';
		}
		$out[] = '</table>';
	}
	$out[] = '<h2>Gate (regression limits)</h2><table><tr><th>Check</th><th>Value</th><th>Limit</th><th>Result</th></tr>';
	foreach ( $r['checks'] as $k => $v ) {
		$ok    = $r['passed'][ $k ];
		$out[] = "<tr><td>{$e($k)}</td><td>{$e($v)}</td><td>{$e($r['limits'][$k])}</td><td class='" . ( $ok ? 'good' : 'bad' ) . "'>" . ( $ok ? 'pass' : 'fail' ) . '</td></tr>';
	}
	$out[] = '</table></main></html>';
	return implode( '', $out ) . "\n";
}
