<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Virtual recurrence engine.
 *
 * Expands RRULE JSON into date arrays at query time — no child posts
 * are created. Only exception posts (detached) live in the database.
 *
 * Meta on recurring parents:
 *   _rp_rrule  – JSON rrule
 *   _rp_exdates – JSON array of excluded YYYY-MM-DD dates
 *
 * @package RotaPress
 */
class RotaPress_Recurrence {

	private const MAX_INSTANCES = 500;

	private const DAY_MAP = array(
		'SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3,
		'TH' => 4, 'FR' => 5, 'SA' => 6,
	);

	/**
	 * Expand an RRULE into date strings between $range_start and $range_end.
	 *
	 * The start date of the parent IS included (first occurrence).
	 * Dates in $exdates are excluded.
	 *
	 * @param array  $rrule       Associative RRULE array.
	 * @param string $start_date  Parent start date YYYY-MM-DD.
	 * @param string $range_start Query range start YYYY-MM-DD.
	 * @param string $range_end   Query range end YYYY-MM-DD.
	 * @param array  $exdates     Excluded dates YYYY-MM-DD.
	 * @return string[]
	 */
	public static function expand_in_range( array $rrule, string $start_date, string $range_start, string $range_end, array $exdates = array() ): array {
		$all_dates  = self::expand_all( $rrule, $start_date );
		$range_s    = $range_start;
		$range_e    = $range_end;
		$exdate_map = array_flip( $exdates );

		$result = array();
		foreach ( $all_dates as $d ) {
			if ( $d < $range_s || $d > $range_e ) {
				continue;
			}
			if ( isset( $exdate_map[ $d ] ) ) {
				continue;
			}
			$result[] = $d;
		}

		return $result;
	}

	/**
	 * Expand ALL dates from rrule (start date included), up to until or MAX.
	 *
	 * @param array  $rrule      Associative RRULE.
	 * @param string $start_date YYYY-MM-DD.
	 * @return string[]
	 */
	public static function expand_all( array $rrule, string $start_date ): array {
		$freq     = $rrule['freq'] ?? '';
		$interval = max( 1, (int) ( $rrule['interval'] ?? 1 ) );
		$until    = $rrule['until'] ?? '';
		$byday    = $rrule['byday'] ?? array();

		if ( '' === $freq || '' === $until ) {
			return array( $start_date );
		}

		$start_dt = new \DateTime( $start_date );
		$until_dt = new \DateTime( $until );

		/* Start with the parent date itself. */
		$dates = array( $start_date );

		if ( 'daily' === $freq ) {
			$dates = array_merge( $dates, self::expand_daily( $start_dt, $until_dt, $interval ) );
		} elseif ( 'weekly' === $freq && ! empty( $byday ) ) {
			$dates = array_merge( $dates, self::expand_weekly_byday( $start_dt, $until_dt, $interval, (array) $byday ) );
		} elseif ( 'weekly' === $freq ) {
			$dates = array_merge( $dates, self::expand_weekly( $start_dt, $until_dt, $interval ) );
		} elseif ( 'monthly' === $freq ) {
			$dates = array_merge( $dates, self::expand_monthly( $start_dt, $until_dt, $interval ) );
		}

		return array_slice( array_unique( $dates ), 0, self::MAX_INSTANCES );
	}

	/**
	 * Get the exdates array for a parent event.
	 */
	public static function get_exdates( int $parent_id ): array {
		$raw = get_post_meta( $parent_id, '_rp_exdates', true );
		if ( ! $raw ) {
			return array();
		}
		$arr = json_decode( (string) $raw, true );
		return is_array( $arr ) ? $arr : array();
	}

	/**
	 * Add an exdate to a parent event.
	 */
	public static function add_exdate( int $parent_id, string $date ): void {
		$exdates = self::get_exdates( $parent_id );
		if ( ! in_array( $date, $exdates, true ) ) {
			$exdates[] = $date;
			update_post_meta( $parent_id, '_rp_exdates', wp_json_encode( array_values( $exdates ) ) );
		}
	}

	/**
	 * Truncate a parent's rrule so it ends before a given date.
	 * Returns the new "until" that was set.
	 */
	public static function truncate_until( int $parent_id, string $before_date ): string {
		$before_dt = new \DateTime( $before_date );
		$before_dt->modify( '-1 day' );
		$new_until = $before_dt->format( 'Y-m-d' );

		$rrule_json = get_post_meta( $parent_id, '_rp_rrule', true );
		$rrule      = json_decode( (string) $rrule_json, true );
		if ( is_array( $rrule ) ) {
			$rrule['until'] = $new_until;
			update_post_meta( $parent_id, '_rp_rrule', wp_json_encode( $rrule ) );
		}

		return $new_until;
	}

	/* ── Private expansion helpers (start date excluded) ──────────── */

	private static function expand_daily( \DateTime $start, \DateTime $until, int $interval ): array {
		$dates   = array();
		$current = clone $start;
		while ( true ) {
			$current->modify( "+{$interval} days" );
			if ( $current > $until ) { break; }
			$dates[] = $current->format( 'Y-m-d' );
			if ( count( $dates ) >= self::MAX_INSTANCES ) { break; }
		}
		return $dates;
	}

	private static function expand_weekly( \DateTime $start, \DateTime $until, int $interval ): array {
		$dates   = array();
		$current = clone $start;
		while ( true ) {
			$current->modify( "+{$interval} weeks" );
			if ( $current > $until ) { break; }
			$dates[] = $current->format( 'Y-m-d' );
			if ( count( $dates ) >= self::MAX_INSTANCES ) { break; }
		}
		return $dates;
	}

	private static function expand_weekly_byday( \DateTime $start, \DateTime $until, int $interval, array $byday ): array {
		$allowed_days = array();
		foreach ( $byday as $code ) {
			$code = strtoupper( trim( $code ) );
			if ( isset( self::DAY_MAP[ $code ] ) ) {
				$allowed_days[] = self::DAY_MAP[ $code ];
			}
		}
		if ( empty( $allowed_days ) ) {
			return self::expand_weekly( $start, $until, $interval );
		}

		$dates      = array();
		$start_week = (int) $start->format( 'W' );
		$start_year = (int) $start->format( 'o' );
		$current    = clone $start;
		$current->modify( '+1 day' );

		while ( $current <= $until ) {
			$current_week = (int) $current->format( 'W' );
			$current_year = (int) $current->format( 'o' );
			$week_diff    = ( ( $current_year - $start_year ) * 52 ) + ( $current_week - $start_week );

			if ( $week_diff >= 0 && 0 === $week_diff % $interval ) {
				$dow = (int) $current->format( 'w' );
				if ( in_array( $dow, $allowed_days, true ) ) {
					$dates[] = $current->format( 'Y-m-d' );
					if ( count( $dates ) >= self::MAX_INSTANCES ) { break; }
				}
			}
			$current->modify( '+1 day' );
		}
		return $dates;
	}

	private static function expand_monthly( \DateTime $start, \DateTime $until, int $interval ): array {
		$dates   = array();
		$current = clone $start;
		while ( true ) {
			$current->modify( "+{$interval} months" );
			if ( $current > $until ) { break; }
			$dates[] = $current->format( 'Y-m-d' );
			if ( count( $dates ) >= self::MAX_INSTANCES ) { break; }
		}
		return $dates;
	}

	/**
	 * Validate an RRULE array.
	 */
	public static function validate( array $rrule ): bool {
		$freq = $rrule['freq'] ?? '';
		if ( ! in_array( $freq, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			return false;
		}
		$until = $rrule['until'] ?? '';
		if ( '' === $until || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
			return false;
		}
		if ( (int) ( $rrule['interval'] ?? 1 ) < 1 ) {
			return false;
		}
		if ( isset( $rrule['byday'] ) ) {
			if ( ! is_array( $rrule['byday'] ) ) {
				return false;
			}
			foreach ( $rrule['byday'] as $day ) {
				if ( ! isset( self::DAY_MAP[ strtoupper( $day ) ] ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
