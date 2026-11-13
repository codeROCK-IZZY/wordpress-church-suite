<?php
/**
 * A year-end giving statement: every gift in the year, totals by fund and in all. Refunded gifts are listed but not
 * counted; amounts are summed in whole cents so totals never drift.
 *
 * @package Congregation
 */

namespace Congregation\Core;

/**
 * Statement.
 */
final class Statement {

	/**
	 * Lines: gifts in date order.
	 *
	 * @var list<array{date: string, fund: string, method: string, cents: int, refunded: bool}>
	 */
	public array $lines = array();

	/**
	 * Totals by fund, in cents.
	 *
	 * @var array<string, int>
	 */
	public array $by_fund = array();

	public int $total = 0;

	/**
	 * Build from gift rows.
	 *
	 * @param int                               $year Year.
	 * @param list<array<string, string|int>>   $rows Gift rows: given_on, fund, method, amount_cents, refunded.
	 */
	public function __construct( public readonly int $year, array $rows ) {
		usort( $rows, static fn ( array $a, array $b ): int => array( (string) $a['given_on'], (int) ( $a['id'] ?? 0 ) ) <=> array( (string) $b['given_on'], (int) ( $b['id'] ?? 0 ) ) );
		foreach ( $rows as $r ) {
			if ( (int) substr( (string) $r['given_on'], 0, 4 ) !== $year ) {
				continue;
			}
			$refunded      = (bool) (int) $r['refunded'];
			$cents         = (int) $r['amount_cents'];
			$this->lines[] = array(
				'date'     => (string) $r['given_on'],
				'fund'     => (string) $r['fund'],
				'method'   => (string) $r['method'],
				'cents'    => $cents,
				'refunded' => $refunded,
			);
			if ( ! $refunded ) {
				$this->by_fund[ (string) $r['fund'] ] = ( $this->by_fund[ (string) $r['fund'] ] ?? 0 ) + $cents;
				$this->total                         += $cents;
			}
		}
		ksort( $this->by_fund );
	}

	/**
	 * Cents as money text.
	 *
	 * @param int $cents Cents.
	 */
	public static function money( int $cents ): string {
		return ( $cents < 0 ? '-' : '' ) . number_format( intdiv( abs( $cents ), 100 ) ) . '.' . str_pad( (string) ( abs( $cents ) % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Parse money text ("1,234.5", "$20") into cents; null when it is not an amount.
	 *
	 * @param string $text Text.
	 */
	public static function cents( string $text ): ?int {
		$t = str_replace( array( ',', '$', '£', '€', ' ' ), '', trim( $text ) );
		if ( ! preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/', $t, $m ) ) {
			return null;
		}
		return (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '0', 2, '0' );
	}
}
