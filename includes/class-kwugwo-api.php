<?php
/**
 * Kwugwo merchant REST API client (secret key, server to server).
 *
 * Wraps the `/v1/*` endpoints documented at https://docs.kwugwo.africa/.
 * Every method returns the decoded JSON body as an array, or a WP_Error
 * describing the transport, HTTP or decoding failure.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kwugwo API client.
 */
class Kwugwo_API {

	const LIVE_BASE    = 'https://api.kwugwo.africa';
	const SANDBOX_BASE = 'https://sandbox-api.kwugwo.africa';

	/**
	 * Secret key (sk.…) for the environment this client talks to.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * API base URL for the environment.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Secret key for the chosen environment.
	 * @param bool   $sandbox    Whether to target the sandbox API.
	 */
	public function __construct( $secret_key, $sandbox ) {
		$this->secret_key = trim( (string) $secret_key );
		$this->base_url   = $sandbox ? self::SANDBOX_BASE : self::LIVE_BASE;
	}

	/**
	 * Create an ugwo (payment request).
	 *
	 * @param array $body Request body: amount, currency, ref, description, onye or onye_email, checkout, meta.
	 * @return array|WP_Error
	 */
	public function create_ugwo( array $body ) {
		return $this->request( 'POST', '/v1/ugwo', $this->filter_empty( $body ) );
	}

	/**
	 * Fetch an ugwo by id. This is the source of truth for fulfilment.
	 *
	 * @param string $ugwo_uid Ugwo id (ugw.…).
	 * @return array|WP_Error
	 */
	public function get_ugwo( $ugwo_uid ) {
		return $this->request( 'GET', '/v1/ugwo/' . rawurlencode( $ugwo_uid ) );
	}

	/**
	 * Cancel an unpaid ugwo. Only possible while it is `requires_ugwo`.
	 *
	 * @param string $ugwo_uid Ugwo id (ugw.…).
	 * @return array|WP_Error
	 */
	public function cancel_ugwo( $ugwo_uid ) {
		return $this->request( 'DELETE', '/v1/ugwo/' . rawurlencode( $ugwo_uid ) );
	}

	/**
	 * Update a customer's details.
	 *
	 * @param string $onye_uid Customer id (ony.…).
	 * @param array  $body     Fields to change.
	 * @return array|WP_Error
	 */
	public function update_onye( $onye_uid, array $body ) {
		return $this->request( 'PATCH', '/v1/onye/' . rawurlencode( $onye_uid ), $this->filter_empty( $body ) );
	}

	/**
	 * List the active checkouts on the workspace.
	 *
	 * @return array|WP_Error List of checkout objects.
	 */
	public function list_checkouts() {
		return $this->request( 'GET', '/v1/checkouts?' . rawurlencode( 'filter[status][]' ) . '=1' );
	}

	/**
	 * Refund all or part of a successful activity.
	 *
	 * @param array $body Request body: ugwo_activity, amount, ref, reason, destination.
	 * @return array|WP_Error
	 */
	public function create_refund( array $body ) {
		return $this->request( 'POST', '/v1/refunds', $this->filter_empty( $body ) );
	}

	/**
	 * Perform an authenticated request and normalise the response.
	 *
	 * @param string     $method HTTP verb.
	 * @param string     $path   Path starting with /v1.
	 * @param array|null $body   Request body for write calls.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = null ) {
		if ( '' === $this->secret_key ) {
			return new WP_Error( 'kwugwo_no_key', __( 'No Kwugwo secret key is set for this environment.', 'kwugwo-for-woocommerce' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Accept'        => 'application/json',
				'User-Agent'    => 'kwugwo-for-woocommerce/' . KWUGWO_WC_VERSION,
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		Kwugwo_Logger::log( sprintf( '→ %s %s %s', $method, $path, null !== $body ? $args['body'] : '' ) );

		$response = wp_remote_request( $this->base_url . $path, $args );

		if ( is_wp_error( $response ) ) {
			Kwugwo_Logger::log( 'Transport error: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		Kwugwo_Logger::log( sprintf( '← %d %s', $status, $raw ) );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'kwugwo_http_' . $status,
				$this->error_message( $status, $data ),
				array(
					'status' => $status,
					'body'   => $data,
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'kwugwo_bad_json', __( 'Kwugwo sent a response we could not read.', 'kwugwo-for-woocommerce' ) );
		}

		return $data;
	}

	/**
	 * Build a readable message from an error response body.
	 *
	 * @param int        $status HTTP status.
	 * @param array|null $data   Decoded body.
	 * @return string
	 */
	private function error_message( $status, $data ) {
		if ( is_array( $data ) ) {
			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$messages = array();
				foreach ( $data['errors'] as $error ) {
					if ( isset( $error['field'], $error['message'] ) ) {
						$messages[] = $error['field'] . ': ' . $error['message'];
					}
				}
				if ( $messages ) {
					return implode( ' ', $messages );
				}
			}
			if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
				return $data['message'];
			}
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Kwugwo returned HTTP %d.', 'kwugwo-for-woocommerce' ),
			$status
		);
	}

	/**
	 * Drop null and empty-string values from a request body.
	 *
	 * @param array $body Body.
	 * @return array
	 */
	private function filter_empty( array $body ) {
		return array_filter(
			$body,
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
	}

	/**
	 * Read the machine code of an API error, e.g. `nzube.ugwo.error.ref_already_exists`.
	 *
	 * @param WP_Error $error Error from this client.
	 * @return string
	 */
	public static function error_code( WP_Error $error ) {
		$data = $error->get_error_data();
		return isset( $data['body']['message'] ) && is_string( $data['body']['message'] ) ? $data['body']['message'] : '';
	}

	/**
	 * HTTP status of an API error, or 0 for transport errors.
	 *
	 * @param WP_Error $error Error from this client.
	 * @return int
	 */
	public static function error_status( WP_Error $error ) {
		$data = $error->get_error_data();
		return isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Normalise an enum field. Some responses send enums as a
	 * [machine_value, translation_key] pair instead of a plain string.
	 *
	 * @param mixed $value Raw field value.
	 * @return string
	 */
	public static function enum_value( $value ) {
		if ( is_array( $value ) ) {
			return isset( $value[0] ) ? (string) $value[0] : '';
		}
		return (string) $value;
	}

	/**
	 * Constant-time check of a webhook signature (HMAC-SHA256 of the raw body, hex).
	 *
	 * @param string $raw_body Raw request body.
	 * @param string $header   Value of the X-Kwugwo-Signature header.
	 * @param string $secret   Endpoint signing secret.
	 * @return bool
	 */
	public static function verify_signature( $raw_body, $header, $secret ) {
		if ( '' === (string) $header ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $raw_body, $secret ), (string) $header );
	}
}
