<?php
/**
 * Keeps WooCommerce orders in step with their Kwugwo payments.
 *
 * Every status change goes through apply(), which works from an ugwo
 * fetched from the Kwugwo API by the server. That fetch can be triggered by
 * a webhook, by the customer landing on the order-received page, or by the
 * background check that runs every 15 minutes in case a webhook is missed.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment synchronisation.
 */
class Kwugwo_Payment_Sync {

	/**
	 * Order meta key holding the notes already added, to avoid repeats.
	 */
	const META_NOTES = '_kwugwo_notes';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'sync_on_return' ) );
		add_action( 'woocommerce_thankyou_' . KWUGWO_WC_GATEWAY_ID, array( __CLASS__, 'thankyou_message' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'cancel_ugwo' ), 10, 2 );
		add_action( 'kwugwo_wc_reconcile', array( __CLASS__, 'reconcile' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule_reconcile' ) );
	}

	/**
	 * Fetch the order's ugwo from Kwugwo and update the order.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error The ugwo status.
	 */
	public static function sync( $order ) {
		$ugwo_uid = $order->get_meta( Kwugwo_Gateway::META_UGWO_UID );
		$gateway  = kwugwo_wc_gateway();

		if ( ! $ugwo_uid || ! $gateway ) {
			return new WP_Error( 'kwugwo_no_payment', __( 'This order has no Kwugwo payment.', 'kwugwo-for-woocommerce' ) );
		}

		$mode = 'sandbox' === $order->get_meta( Kwugwo_Gateway::META_ENVIRONMENT ) ? 'sandbox' : 'live';
		$ugwo = $gateway->get_api( $mode )->get_ugwo( $ugwo_uid );

		if ( is_wp_error( $ugwo ) ) {
			Kwugwo_Logger::log( sprintf( 'Order #%d: could not fetch %s: %s', $order->get_id(), $ugwo_uid, $ugwo->get_error_message() ), 'error' );
			return $ugwo;
		}

		return self::apply( $order, $ugwo );
	}

	/**
	 * Update the order to match an ugwo fetched from the API.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $ugwo  Ugwo from GET /v1/ugwo/{uid}.
	 * @return string The ugwo status.
	 */
	public static function apply( $order, array $ugwo ) {
		$status   = Kwugwo_API::enum_value( isset( $ugwo['status'] ) ? $ugwo['status'] : '' );
		$ugwo_uid = isset( $ugwo['uid'] ) ? (string) $ugwo['uid'] : '';

		// Ignore an ugwo the order has since moved away from (e.g. after a retry).
		if ( $ugwo_uid !== $order->get_meta( Kwugwo_Gateway::META_UGWO_UID ) ) {
			return $status;
		}

		Kwugwo_Logger::log( sprintf( 'Order #%d: %s is %s', $order->get_id(), $ugwo_uid, $status ) );

		$order->update_meta_data( Kwugwo_Gateway::META_STATUS, $status );
		self::store_activity( $order, $ugwo );

		switch ( $status ) {
			case 'ugwo_successful':
				if ( ! $order->is_paid() ) {
					self::complete( $order, $ugwo );
				}
				break;

			case 'partially_refunded':
			case 'refunded':
				if ( ! $order->is_paid() ) {
					self::hold( $order, 'refunded_before_paid', __( 'Kwugwo: this payment was refunded before the order was marked paid. Check the payment in your Kwugwo dashboard before sending anything.', 'kwugwo-for-woocommerce' ) );
				}
				break;

			default:
				self::handle_unpaid( $order, $ugwo, $status );
		}

		$order->save();

		return $status;
	}

	/**
	 * Mark the order paid after checking the ugwo really matches it.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $ugwo  Ugwo.
	 */
	private static function complete( $order, array $ugwo ) {
		$problems = self::mismatches( $order, $ugwo );

		if ( $problems ) {
			self::hold(
				$order,
				'mismatch',
				sprintf(
					/* translators: %s: list of problems. */
					__( 'Kwugwo says this payment succeeded, but it does not match the order: %s Check it in your Kwugwo dashboard before sending anything.', 'kwugwo-for-woocommerce' ),
					implode( ' ', $problems )
				)
			);
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: Kwugwo payment id, 2: payment provider reference. */
				__( 'Kwugwo payment confirmed (%1$s). Payment provider reference: %2$s.', 'kwugwo-for-woocommerce' ),
				$ugwo['uid'],
				$order->get_meta( Kwugwo_Gateway::META_PSP_REF ) ? $order->get_meta( Kwugwo_Gateway::META_PSP_REF ) : '-'
			)
		);
		$order->payment_complete( $ugwo['uid'] );
	}

	/**
	 * Checks from https://docs.kwugwo.africa/guides/validate-and-fulfil.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $ugwo  Ugwo.
	 * @return string[] Problems found; empty when the payment matches.
	 */
	private static function mismatches( $order, array $ugwo ) {
		$problems = array();
		$amount   = isset( $ugwo['amount'] ) ? (int) $ugwo['amount'] : 0;
		$paid     = isset( $ugwo['total_amount_paid'] ) ? (int) $ugwo['total_amount_paid'] : 0;

		if ( $paid < $amount ) {
			$problems[] = __( 'less than the full amount was received.', 'kwugwo-for-woocommerce' );
		}
		if ( Kwugwo_Gateway::to_kobo( $order->get_total() ) !== $amount ) {
			$problems[] = __( 'the amount is different from the order total.', 'kwugwo-for-woocommerce' );
		}
		if ( ! isset( $ugwo['currency'] ) || $ugwo['currency'] !== $order->get_currency() ) {
			$problems[] = __( 'the currency is different.', 'kwugwo-for-woocommerce' );
		}
		if ( ! isset( $ugwo['ref'] ) || $ugwo['ref'] !== $order->get_meta( Kwugwo_Gateway::META_UGWO_REF ) ) {
			$problems[] = __( 'the payment reference is different.', 'kwugwo-for-woocommerce' );
		}

		return $problems;
	}

	/**
	 * Handle an ugwo that is not (or no longer) paid.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $ugwo   Ugwo.
	 * @param string   $status Ugwo status.
	 */
	private static function handle_unpaid( $order, array $ugwo, $status ) {
		// Paid through this ugwo before, but Kwugwo no longer says so: the
		// provider reversed the payment.
		if ( $order->is_paid() && $order->get_transaction_id() === $ugwo['uid'] ) {
			$note = __( 'Kwugwo reports this payment is no longer successful; the payment provider may have reversed it. Check your Kwugwo dashboard before sending anything.', 'kwugwo-for-woocommerce' );
			if ( $order->has_status( 'processing' ) ) {
				self::hold( $order, 'reversed', $note );
			} else {
				self::note_once( $order, 'reversed', $note );
			}
			return;
		}

		$amount = isset( $ugwo['amount'] ) ? (int) $ugwo['amount'] : 0;
		$paid   = isset( $ugwo['total_amount_paid'] ) ? (int) $ugwo['total_amount_paid'] : 0;

		if ( 'requires_ugwo' === $status && $paid > 0 && $paid < $amount ) {
			self::note_once(
				$order,
				'underpaid_' . $paid,
				sprintf(
					/* translators: 1: amount received, 2: amount due. */
					__( 'Kwugwo: the customer paid %1$s of %2$s. The order stays unpaid. Refund the part payment from your Kwugwo dashboard, or settle the difference with the customer.', 'kwugwo-for-woocommerce' ),
					wp_strip_all_tags( wc_price( $paid / 100, array( 'currency' => $order->get_currency() ) ) ),
					wp_strip_all_tags( wc_price( $amount / 100, array( 'currency' => $order->get_currency() ) ) )
				)
			);
		} elseif ( 'cancelled' === $status && $order->needs_payment() ) {
			self::note_once( $order, 'cancelled', __( 'Kwugwo: the payment request was cancelled. If the customer tries to pay again, a new request is created automatically.', 'kwugwo-for-woocommerce' ) );
		}
	}

	/**
	 * Remember the activity that paid the ugwo; refunds are made against it.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $ugwo  Ugwo.
	 */
	private static function store_activity( $order, array $ugwo ) {
		// The API spells this field "lastest_ugwo_activity".
		$activity = isset( $ugwo['lastest_ugwo_activity'] ) && is_array( $ugwo['lastest_ugwo_activity'] ) ? $ugwo['lastest_ugwo_activity'] : array();
		$status   = Kwugwo_API::enum_value( isset( $activity['status'] ) ? $activity['status'] : '' );

		if ( empty( $activity['uid'] ) || ! empty( $activity['is_double_charge'] ) ) {
			return;
		}
		if ( ! in_array( $status, array( 'successful', 'partially_refunded', 'refunded' ), true ) ) {
			return;
		}

		$order->update_meta_data( Kwugwo_Gateway::META_ACTIVITY_UID, $activity['uid'] );
		if ( ! empty( $activity['psp_external_id'] ) ) {
			$order->update_meta_data( Kwugwo_Gateway::META_PSP_REF, $activity['psp_external_id'] );
		}
	}

	/**
	 * Put the order on hold with a note, once per reason.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Reason key.
	 * @param string   $note   Note.
	 */
	private static function hold( $order, $reason, $note ) {
		if ( self::seen_note( $order, $reason ) ) {
			return;
		}
		self::remember_note( $order, $reason );
		$order->update_status( 'on-hold', $note );
	}

	/**
	 * Add an order note once per key.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Note key.
	 * @param string   $note  Note.
	 */
	public static function note_once( $order, $key, $note ) {
		if ( self::seen_note( $order, $key ) ) {
			return;
		}
		self::remember_note( $order, $key );
		$order->add_order_note( $note );
		$order->save();
	}

	/**
	 * Whether a note key was already used on this order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Note key.
	 * @return bool
	 */
	private static function seen_note( $order, $key ) {
		return in_array( $key, (array) $order->get_meta( self::META_NOTES ), true );
	}

	/**
	 * Record a note key on the order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Note key.
	 */
	private static function remember_note( $order, $key ) {
		$notes   = (array) $order->get_meta( self::META_NOTES );
		$notes[] = $key;
		$order->update_meta_data( self::META_NOTES, array_values( array_unique( $notes ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Triggers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * When the customer comes back from the payment window, ask Kwugwo
	 * straight away instead of waiting for the webhook.
	 */
	public static function sync_on_return() {
		if ( ! is_order_received_page() ) {
			return;
		}

		global $wp;
		$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the order key itself authorises viewing the order.
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) $key ) ) {
			return;
		}
		if ( KWUGWO_WC_GATEWAY_ID === $order->get_payment_method() && $order->needs_payment() ) {
			self::sync( $order );
		}
	}

	/**
	 * Tell the customer what happens next when payment is still being confirmed.
	 *
	 * @param int $order_id Order id.
	 */
	public static function thankyou_message( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->needs_payment() ) {
			return;
		}
		echo '<p class="kwugwo-confirming">' . esc_html__( 'We are waiting for your bank to confirm your payment. This usually takes a minute or two. You will get an email as soon as it is confirmed, so there is no need to pay again.', 'kwugwo-for-woocommerce' ) . '</p>';
	}

	/**
	 * When an unpaid order is cancelled, cancel its Kwugwo payment request
	 * too so the customer cannot pay for it later.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 */
	public static function cancel_ugwo( $order_id, $order ) {
		if ( ! $order || KWUGWO_WC_GATEWAY_ID !== $order->get_payment_method() || $order->get_transaction_id() ) {
			return;
		}

		$ugwo_uid = $order->get_meta( Kwugwo_Gateway::META_UGWO_UID );
		$gateway  = kwugwo_wc_gateway();
		if ( ! $ugwo_uid || ! $gateway ) {
			return;
		}

		$mode   = 'sandbox' === $order->get_meta( Kwugwo_Gateway::META_ENVIRONMENT ) ? 'sandbox' : 'live';
		$result = $gateway->get_api( $mode )->cancel_ugwo( $ugwo_uid );

		if ( ! is_wp_error( $result ) ) {
			$order->update_meta_data( Kwugwo_Gateway::META_STATUS, 'cancelled' );
			self::note_once( $order, 'cancelled', __( 'Kwugwo: the payment request was cancelled along with the order.', 'kwugwo-for-woocommerce' ) );
		}
	}

	/**
	 * Schedule the background payment check while the gateway is on.
	 */
	public static function schedule_reconcile() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( 'kwugwo_wc_reconcile' ) ) {
			return;
		}
		$gateway = kwugwo_wc_gateway();
		if ( $gateway && 'yes' === $gateway->enabled ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, 'kwugwo_wc_reconcile', array(), 'kwugwo' );
		}
	}

	/**
	 * Check orders that are still unpaid 10 minutes to 2 days after they were
	 * placed, in case a webhook did not arrive.
	 */
	public static function reconcile() {
		$orders = wc_get_orders(
			array(
				'payment_method' => KWUGWO_WC_GATEWAY_ID,
				'status'         => array( 'pending' ),
				'date_created'   => ( time() - 2 * DAY_IN_SECONDS ) . '...' . ( time() - 10 * MINUTE_IN_SECONDS ),
				'limit'          => 25,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $orders as $order ) {
			if ( $order->get_meta( Kwugwo_Gateway::META_UGWO_UID ) ) {
				self::sync( $order );
			}
		}
	}
}
