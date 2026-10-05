<?php
/**
 * Resolve payments that were never resolved in the browser.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitClient;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService;

/**
 * Backstop for a closed browser, lost network or discarded checkout tab.
 *
 * Query stale current attempts and transactions set aside while still live.
 * Never cancel or abandon: HIT cancellation needs the terminal's button.
 */
class PaymentSweeper {
	public const CRON_HOOK = 'wctwc_sweep_stale_payments';
	public const SCHEDULE  = 'wctwc_ten_minutes';

	/**
	 * Payment service, created when needed.
	 *
	 * @var HitPaymentService|null
	 */
	private $service;

	/**
	 * Register the interval, sweep and scheduling hooks.
	 *
	 * @param HitPaymentService|null $service Payment service.
	 */
	public function __construct( ?HitPaymentService $service = null ) {
		$this->service = $service;
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'sweep' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Register the custom 10-minute cron interval.
	 *
	 * @param array $schedules Cron intervals.
	 * @return array Cron intervals including the payment sweep.
	 */
	public function add_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 10 minutes (Windcave Terminal cleanup)', 'windcave-terminal-for-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the sweep if it is not already registered.
	 */
	public function ensure_scheduled(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Remove scheduled sweeps on deactivation.
	 */
	public static function unschedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		while ( $ts = wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Number of seconds a pending payment may age before being checked.
	 *
	 * @return int Stale threshold in seconds.
	 */
	public static function stale_threshold(): int {
		return (int) apply_filters( 'wctwc_stale_payment_seconds', 600 );
	}

	/**
	 * Resolve a batch of stale and set-aside attempts.
	 */
	public function sweep(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$limit  = (int) apply_filters( 'wctwc_stale_payment_batch', 25 );
		$orders = array();
		// Orders whose current attempt may have gone stale in the browser.
		foreach ( $this->find_orders( array( 'pending', 'failed', 'on-hold' ), PaymentAttempt::META_CURRENT_TXN_REF, $limit ) as $order ) {
			$orders[ (int) $order->get_id() ] = $order;
		}
		// Orders holding a transaction abandoned locally while still live at
		// Windcave. Their current-attempt pointer is gone, so the query above cannot
		// see them, and their status is irrelevant: the order may since have been
		// paid in cash or cancelled while the transaction stayed live.
		foreach ( $this->find_orders( 'any', PaymentAttempt::META_ABANDONED_TXN_REFS, $limit ) as $order ) {
			$orders[ (int) $order->get_id() ] = $order;
		}
		foreach ( $orders as $order ) {
			$this->sweep_order( $order );
		}
	}

	/**
	 * Find orders carrying the requested attempt meta.
	 *
	 * @param string|array $status   Order statuses.
	 * @param string       $meta_key Attempt meta key.
	 * @param int          $limit    Maximum orders.
	 * @return array Orders to inspect.
	 */
	private function find_orders( $status, string $meta_key, int $limit ): array {
		$orders = wc_get_orders(
			array(
				// Refunds are order objects too and come back by default. They never
				// carry the attempt meta and lack the WC_Order methods the sweep calls.
				'type'         => 'shop_order',
				'limit'        => $limit,
				'status'       => $status,
				'orderby'      => 'date',
				'order'        => 'ASC',
				// Not 'meta_query': the legacy posts order store ignores that argument
				// (a doing_it_wrong notice at most) and returns the oldest orders of
				// any kind. The meta_key shortcut is honoured by both stores.
				'meta_key'     => $meta_key,
				'meta_compare' => 'EXISTS',
			)
		);
		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Resolve one order's attempts. Kept separate from the DB query so it can be
	 * unit-tested with a plain fake order.
	 *
	 * @param \WC_Order $order Order to inspect.
	 * @return bool Whether any transaction resolution was attempted.
	 */
	public function sweep_order( $order ): bool {
		$swept = false;
		try {
			// Abandoned transactions first: resolving one can complete the order.
			foreach ( PaymentAttempt::abandoned( $order ) as $txn_ref ) {
				$service = $this->service();
				$swept   = true;
				$result  = $service->resolve_txn( $order, $txn_ref, 'abandoned_sweep' );
				if ( 'pending' !== $result['status'] ) {
					$order->add_order_note( sprintf( 'Windcave Terminal: set-aside TxnRef %s resolved by automatic follow-up (%s).', $txn_ref, $result['status'] ) );
				}
			}
			// Resolution can complete the order on a re-read copy. Continue from the
			// database's version so a stale copy cannot trigger a paid order's sweep.
			if ( $swept ) {
				$order = OrderCompletion::reload_order( $order );
			}
			if ( $order->is_paid() ) {
				return $swept;
			}
			$current = PaymentAttempt::current( $order );
			if ( ! $current || ! PaymentAttempt::is_pending( $current['status'] ) ) {
				return $swept;
			}
			$created = strtotime( $current['created_at'] );
			if ( ! $created || ( time() - $created ) < self::stale_threshold() ) {
				return $swept;
			}
			$service = $this->service();
			$swept   = true;
			$result  = $service->resolve_txn( $order, $current['txn_ref'], 'stale_sweep' );
			Logger::log(
				'Stale Windcave payment resolved.',
				array(
					'order_id' => (int) $order->get_id(),
					'txn_ref'  => $current['txn_ref'],
					'status'   => $result['status'],
				),
				'info'
			);
		} catch ( \Exception $e ) {
			Logger::log( 'Payment sweep failed for order: ' . $e->getMessage(), array( 'order_id' => (int) $order->get_id() ), 'error' );
		}
		return $swept;
	}

	/**
	 * Get the payment service for this sweep.
	 *
	 * @return HitPaymentService Payment service.
	 */
	private function service(): HitPaymentService {
		if ( ! $this->service ) {
			$settings      = new Settings();
			$this->service = new HitPaymentService( new HitClient( $settings ), $settings );
		}
		return $this->service;
	}
}
