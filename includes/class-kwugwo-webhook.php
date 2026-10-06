<?php
/**
 * Kwugwo webhook listener at {site}/?wc-api=kwugwo_webhook.
 *
 * Verifies the signature, ignores repeated deliveries, finds the order and
 * then asks the Kwugwo API for the current state of the payment. The event
 * body is never trusted to mark an order paid.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhook handler.
 */
class Kwugwo_Webhook {

	/**
	 * Register the endpoint.
	 */
	public static function init() {
		add_action( 'woocommerce_api_kwugwo_webhook', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle a delivery and always answer with a status code. Kwugwo retries
	 * anything other than 2xx twice, 30 minutes apart.
	 */
	public static function handle() {
		$raw_body  = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_KWUGWO_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_KWUGWO_SIGNATURE'] ) ) : '';
		$payload   = json_decode( (string) $raw_body, true );
		$gateway   = kwugwo_wc_gateway();

		if ( ! $gateway || ! is_array( $payload ) || empty( $payload['event'] ) || empty( $payload['uid'] ) ) {
			self::respond( 400, 'malformed payload' );
		}

		$event_uid = (string) $payload['uid'];
		$event     = (string) $payload['event'];
		$data      = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		// Sandbox ids end in "_t"; each environment has its own signing secret.
		$mode   = '_t' === substr( $event_uid, -2 ) ? 'sandbox' : 'live';
		$secret = $gateway->get_env_option( 'webhook_secret', $mode );

		if ( '' !== $secret && ! Kwugwo_API::verify_signature( $raw_body, $signature, $secret ) ) {
			Kwugwo_Logger::log( 'Webhook signature check failed for ' . $event_uid, 'error' );
			self::respond( 401, 'invalid signature' );
		}

		$received          = (array) get_option( 'kwugwo_wc_last_webhook', array() );
		$received[ $mode ] = time();
		update_option( 'kwugwo_wc_last_webhook', $received, false );

		Kwugwo_Logger::log( sprintf( 'Webhook %s (%s)', $event, $event_uid ) );

		$processed_key = 'kwugwo_wc_evt_' . md5( $event_uid );
		if ( get_transient( $processed_key ) ) {
			self::respond( 200, 'duplicate' );
		}

		$ugwo_uid = self::ugwo_uid( $event, $data );
		$order    = $ugwo_uid ? self::find_order( $ugwo_uid, $data ) : null;

		if ( $order ) {
			if ( 0 === strpos( $event, 'refund.' ) ) {
				self::note_refund( $order, $event, $data );
			} elseif ( 'ugwo.activity.double_charge_detected' === $event ) {
				self::note_double_charge( $order, $data );
			} elseif ( is_wp_error( Kwugwo_Payment_Sync::sync( $order ) ) ) {
				self::respond( 500, 'could not fetch payment' );
			}
		}

		set_transient( $processed_key, 1, WEEK_IN_SECONDS );
		self::respond( 200, $order ? 'ok' : 'ignored' );
	}

	/**
	 * The ugwo an event is about, or '' for events we do not act on.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Event data.
	 * @return string
	 */
	private static function ugwo_uid( $event, array $data ) {
		if ( 0 === strpos( $event, 'ugwo.activity.next_action.' ) ) {
			return '';
		}
		if ( 0 === strpos( $event, 'ugwo.activity.' ) ) {
			return isset( $data['ugwo_uid'] ) ? (string) $data['ugwo_uid'] : '';
		}
		if ( 0 === strpos( $event, 'ugwo.' ) ) {
			return isset( $data['uid'] ) ? (string) $data['uid'] : '';
		}
		if ( 0 === strpos( $event, 'refund.' ) ) {
			return isset( $data['ugwo']['uid'] ) ? (string) $data['ugwo']['uid'] : '';
		}
		return '';
	}

	/**
	 * Find the Kwugwo order that owns an ugwo.
	 *
	 * @param string $ugwo_uid Ugwo id.
	 * @param array  $data     Event data, which may carry our order id in meta.
	 * @return WC_Order|null
	 */
	private static function find_order( $ugwo_uid, array $data ) {
		if ( ! empty( $data['meta']['order_id'] ) ) {
			$order = wc_get_order( absint( $data['meta']['order_id'] ) );
			if ( $order && $order->get_meta( Kwugwo_Gateway::META_UGWO_UID ) === $ugwo_uid ) {
				return $order;
			}
		}

		$orders = wc_get_orders(
			array(
				'limit'          => 1,
				'payment_method' => KWUGWO_WC_GATEWAY_ID,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only runs for webhooks that do not carry the order id.
					array(
						'key'   => Kwugwo_Gateway::META_UGWO_UID,
						'value' => $ugwo_uid,
					),
				),
			)
		);

		return $orders ? $orders[0] : null;
	}

	/**
	 * Record the outcome of a refund on the order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $event Event type.
	 * @param array    $data  Refund object.
	 */
	private static function note_refund( $order, $event, array $data ) {
		$refund_uid = isset( $data['uid'] ) ? (string) $data['uid'] : '-';
		$amount     = isset( $data['amount'] ) ? wp_strip_all_tags( wc_price( (int) $data['amount'] / 100, array( 'currency' => $order->get_currency() ) ) ) : '';

		if ( 'refund.successful' === $event ) {
			Kwugwo_Payment_Sync::note_once(
				$order,
				'refund_ok_' . $refund_uid,
				/* translators: 1: amount, 2: Kwugwo refund id. */
				sprintf( __( 'Kwugwo refund of %1$s completed (%2$s). The money is on its way back to the customer.', 'kwugwo-for-woocommerce' ), $amount, $refund_uid )
			);
		} elseif ( 'refund.failed' === $event ) {
			$reason = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
			Kwugwo_Payment_Sync::note_once(
				$order,
				'refund_failed_' . $refund_uid,
				/* translators: 1: amount, 2: Kwugwo refund id, 3: reason. */
				sprintf( __( 'Kwugwo refund of %1$s FAILED (%2$s): %3$s The customer has not been paid back, although WooCommerce lists the refund. Fix the cause and refund again, or pay the customer directly.', 'kwugwo-for-woocommerce' ), $amount, $refund_uid, $reason )
			);
		}
	}

	/**
	 * Warn the store owner that the customer paid twice.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Activity object.
	 */
	private static function note_double_charge( $order, array $data ) {
		$activity_uid = isset( $data['uid'] ) ? (string) $data['uid'] : '-';
		$amount       = isset( $data['charged_amount'] ) ? (int) $data['charged_amount'] : ( isset( $data['amount'] ) ? (int) $data['amount'] : 0 );

		Kwugwo_Payment_Sync::note_once(
			$order,
			'double_' . $activity_uid,
			sprintf(
				/* translators: 1: amount, 2: Kwugwo activity id. */
				__( 'Kwugwo: the customer paid for this order a second time (%1$s, payment attempt %2$s). Refund that extra payment from your Kwugwo dashboard.', 'kwugwo-for-woocommerce' ),
				wp_strip_all_tags( wc_price( $amount / 100, array( 'currency' => $order->get_currency() ) ) ),
				$activity_uid
			)
		);
	}

	/**
	 * Send a status code with a short JSON body and stop.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Short reason.
	 */
	private static function respond( $code, $message ) {
		wp_send_json( array( 'message' => $message ), $code );
	}
}
