<?php
/**
 * Kwugwo payment gateway for WooCommerce.
 *
 * When the customer places an order we create an ugwo (payment request) on
 * the server with the secret key, then send the customer to the order-pay
 * page where the Kwugwo checkout overlay opens for that ugwo. The order is
 * only marked paid after the server re-fetches the ugwo from Kwugwo (see
 * Kwugwo_Payment_Sync).
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kwugwo gateway.
 */
class Kwugwo_Gateway extends WC_Payment_Gateway {

	/**
	 * Smallest and largest amount Kwugwo accepts for NGN, in kobo.
	 */
	const NGN_MIN_KOBO = 20000;
	const NGN_MAX_KOBO = 5000000000;

	/**
	 * Order meta keys.
	 */
	const META_UGWO_UID           = '_kwugwo_ugwo_uid';
	const META_UGWO_REF           = '_kwugwo_ugwo_ref';
	const META_ENVIRONMENT        = '_kwugwo_environment';
	const META_ATTEMPT            = '_kwugwo_attempt';
	const META_STATUS             = '_kwugwo_status';
	const META_ACTIVITY_UID       = '_kwugwo_activity_uid';
	const META_PSP_REF            = '_kwugwo_psp_ref';
	const META_REFUND_DESTINATION = '_kwugwo_refund_destination';
	const META_REFUND_SEQ         = '_kwugwo_refund_seq';

	/**
	 * Kwugwo dashboard, where merchants get keys, checkouts and webhooks.
	 */
	const DASHBOARD_URL = 'https://app.kwugwo.africa';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = KWUGWO_WC_GATEWAY_ID;
		$this->method_title       = __( 'Kwugwo', 'kwugwo-for-woocommerce' );
		$this->method_description = __( 'Let customers pay by bank transfer, USSD or pay with bank in a secure Kwugwo window, routed to the payment providers you have connected on Kwugwo.', 'kwugwo-for-woocommerce' );
		$this->has_fields         = false;
		$this->icon               = apply_filters( 'kwugwo_wc_icon', KWUGWO_WC_URL . 'assets/images/kwugwo-logo.jpg' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$fields = array(
			'enabled'     => array(
				'title'   => __( 'Turn on Kwugwo', 'kwugwo-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Kwugwo as a payment option at checkout', 'kwugwo-for-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Name at checkout', 'kwugwo-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What customers see as the payment option name.', 'kwugwo-for-woocommerce' ),
				'default'     => __( 'Bank transfer, USSD and more (Kwugwo)', 'kwugwo-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Message at checkout', 'kwugwo-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment option name.', 'kwugwo-for-woocommerce' ),
				'default'     => __( 'Pay securely by bank transfer, USSD or with your bank app. A secure Kwugwo window will open to complete your payment.', 'kwugwo-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'mode_title'  => array(
				'title'       => __( 'Test or live payments', 'kwugwo-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Kwugwo has two separate environments, each with its own keys. Use Sandbox to try things out without real money, then switch to Live when you are ready to sell.', 'kwugwo-for-woocommerce' ),
			),
			'mode'        => array(
				'title'   => __( 'Mode', 'kwugwo-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'sandbox',
				'options' => array(
					'sandbox' => __( 'Sandbox (test payments, no real money)', 'kwugwo-for-woocommerce' ),
					'live'    => __( 'Live (real payments)', 'kwugwo-for-woocommerce' ),
				),
			),
			'webhook_url' => array(
				'title'       => __( 'Webhook URL', 'kwugwo-for-woocommerce' ),
				'type'        => 'kwugwo_webhook_url',
				'description' => __( 'Kwugwo uses this address to tell your store when a customer has paid. Add it in your Kwugwo dashboard under Webhooks, once in Sandbox and once in Live.', 'kwugwo-for-woocommerce' ),
			),
		);

		foreach ( array( 'sandbox', 'live' ) as $mode ) {
			$fields += $this->environment_fields( $mode );
		}

		$fields['advanced_title'] = array(
			'title' => __( 'Troubleshooting', 'kwugwo-for-woocommerce' ),
			'type'  => 'title',
		);
		$fields['debug']          = array(
			'title'       => __( 'Debug log', 'kwugwo-for-woocommerce' ),
			'type'        => 'checkbox',
			'label'       => __( 'Record Kwugwo requests and webhooks in the WooCommerce log', 'kwugwo-for-woocommerce' ),
			'default'     => 'no',
			'description' => sprintf(
				/* translators: %s: log source name. */
				__( 'Find the log under WooCommerce → Status → Logs (source: %s). Logs include customer emails and order amounts, so only turn this on while fixing a problem.', 'kwugwo-for-woocommerce' ),
				'<code>kwugwo</code>'
			),
		);

		$this->form_fields = $fields;
	}

	/**
	 * Key, webhook and checkout fields for one environment.
	 *
	 * @param string $mode sandbox or live.
	 * @return array
	 */
	private function environment_fields( $mode ) {
		$sandbox = 'sandbox' === $mode;
		$class   = 'kwugwo-env kwugwo-env-' . $mode;

		return array(
			$mode . '_title'          => array(
				'title'       => $sandbox ? __( 'Sandbox keys', 'kwugwo-for-woocommerce' ) : __( 'Live keys', 'kwugwo-for-woocommerce' ),
				'type'        => 'title',
				'class'       => $class,
				'description' => $sandbox
					? __( 'In your Kwugwo dashboard, switch to Sandbox, open the API Keys page, then copy both keys here.', 'kwugwo-for-woocommerce' )
					: __( 'In your Kwugwo dashboard, switch to Live, open the API Keys page, then copy both keys here.', 'kwugwo-for-woocommerce' ),
			),
			$mode . '_public_key'     => array(
				'title'       => __( 'Public key', 'kwugwo-for-woocommerce' ),
				'type'        => 'text',
				'class'       => $class,
				'placeholder' => 'pk.XXXX.…',
				'description' => __( 'Starts with "pk.". Safe to show in the browser; it opens the payment window.', 'kwugwo-for-woocommerce' ),
				'desc_tip'    => true,
			),
			$mode . '_secret_key'     => array(
				'title'       => __( 'Secret key', 'kwugwo-for-woocommerce' ),
				'type'        => 'password',
				'class'       => $class,
				'placeholder' => 'sk.XXXX.…',
				'description' => __( 'Starts with "sk.". Keep it private: it is only used by your store\'s server and never shown to customers.', 'kwugwo-for-woocommerce' ),
				'desc_tip'    => true,
			),
			$mode . '_test'           => array(
				'title' => '',
				'type'  => 'kwugwo_test_connection',
				'class' => $class,
				'mode'  => $mode,
			),
			$mode . '_webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'kwugwo-for-woocommerce' ),
				'type'        => 'password',
				'class'       => $class,
				'placeholder' => 'wsk.…',
				'description' => __( 'Shown in your Kwugwo dashboard after you add the webhook URL above. It lets your store confirm that payment updates really come from Kwugwo.', 'kwugwo-for-woocommerce' ),
				'desc_tip'    => true,
			),
			$mode . '_checkout'       => array(
				'title'       => __( 'Checkout (optional)', 'kwugwo-for-woocommerce' ),
				'type'        => 'kwugwo_checkout',
				'class'       => $class,
				'mode'        => $mode,
				'description' => __( 'Leave on "Automatic" to let your Kwugwo routing rules choose. Pick a checkout to make every payment from this store use its payment methods and providers, for example to keep this store\'s money separate.', 'kwugwo-for-woocommerce' ),
			),
		);
	}

	/**
	 * Render the webhook URL row with a copy button.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 * @return string
	 */
	public function generate_kwugwo_webhook_url_html( $key, $data ) {
		$field_id = $this->get_field_key( $key );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div class="kwugwo-copy">
					<input type="text" readonly class="input-text regular-input code" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( self::get_webhook_url() ); ?>" />
					<button type="button" class="button kwugwo-copy__button" data-copy-target="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Copy', 'kwugwo-for-woocommerce' ); ?></button>
				</div>
				<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the "Test connection" button for one environment.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 * @return string
	 */
	public function generate_kwugwo_test_connection_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top" class="<?php echo esc_attr( $data['class'] ); ?>">
			<th scope="row" class="titledesc"></th>
			<td class="forminp">
				<button type="button" class="button kwugwo-test-connection" data-mode="<?php echo esc_attr( $data['mode'] ); ?>"><?php esc_html_e( 'Test connection', 'kwugwo-for-woocommerce' ); ?></button>
				<span class="kwugwo-test-result" role="status" aria-live="polite"></span>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the checkout picker. Falls back to a text box when the list
	 * of checkouts cannot be loaded.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 * @return string
	 */
	public function generate_kwugwo_checkout_html( $key, $data ) {
		$field_id = $this->get_field_key( $key );
		$value    = $this->get_option( $key );
		$options  = $this->get_checkout_options( $data['mode'] );

		ob_start();
		?>
		<tr valign="top" class="<?php echo esc_attr( $data['class'] ); ?>">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $data['title'] ); ?> <?php echo wp_kses_post( $this->get_tooltip_html( $data + array( 'desc_tip' => true ) ) ); ?></label>
			</th>
			<td class="forminp">
				<?php if ( is_wp_error( $options ) ) : ?>
					<input type="text" class="input-text regular-input" name="<?php echo esc_attr( $field_id ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="chk.XXXX.…" />
					<p class="description"><?php esc_html_e( 'Save working keys to choose from your checkouts, or paste a checkout ID from your Kwugwo dashboard.', 'kwugwo-for-woocommerce' ); ?></p>
				<?php else : ?>
					<select class="kwugwo-checkout-select" name="<?php echo esc_attr( $field_id ); ?>" id="<?php echo esc_attr( $field_id ); ?>" data-mode="<?php echo esc_attr( $data['mode'] ); ?>">
						<option value=""><?php esc_html_e( 'Automatic (use my routing rules)', 'kwugwo-for-woocommerce' ); ?></option>
						<?php foreach ( $options as $uid => $label ) : ?>
							<option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $value, $uid ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
						<?php if ( $value && ! isset( $options[ $value ] ) ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" selected>
								<?php
								/* translators: %s: checkout id. */
								echo esc_html( sprintf( __( '%s (not found or inactive)', 'kwugwo-for-woocommerce' ), $value ) );
								?>
							</option>
						<?php endif; ?>
					</select>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * The webhook field has no value to save.
	 *
	 * @return string
	 */
	public function validate_kwugwo_webhook_url_field() {
		return '';
	}

	/**
	 * The test button has no value to save.
	 *
	 * @return string
	 */
	public function validate_kwugwo_test_connection_field() {
		return '';
	}

	/**
	 * Accept only a checkout id, or empty for automatic routing.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_kwugwo_checkout_field( $key, $value ) {
		$value = trim( (string) $value );
		if ( '' !== $value && ! preg_match( '/^chk\.[a-zA-Z0-9]{4}\.[a-zA-Z0-9_]{24}$/', $value ) ) {
			WC_Admin_Settings::add_error( __( 'Kwugwo: the checkout ID should look like "chk.XXXX.…". It was not saved.', 'kwugwo-for-woocommerce' ) );
			return '';
		}
		return $value;
	}

	/**
	 * Settings page: title, the setup guide, then the fields.
	 */
	public function admin_options() {
		echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
		echo wp_kses_post( wpautop( $this->get_method_description() ) );
		Kwugwo_Admin::render_setup_guide( $this );
		echo '<table class="form-table">';
		$this->generate_settings_html( $this->get_form_fields() );
		echo '</table>';
	}

	/**
	 * Save settings, then confirm any new secret keys with Kwugwo and check
	 * that each public key belongs to the same workspace as its secret key.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		foreach ( array( 'sandbox', 'live' ) as $mode ) {
			$this->check_saved_keys( $mode );
		}

		return $saved;
	}

	/**
	 * Report problems with one environment's saved keys.
	 *
	 * @param string $mode sandbox or live.
	 */
	private function check_saved_keys( $mode ) {
		$public = $this->get_env_option( 'public_key', $mode );
		$secret = $this->get_env_option( 'secret_key', $mode );
		$label  = self::mode_label( $mode );

		if ( '' === $public && '' === $secret ) {
			return;
		}

		$problem = self::key_format_problem( $public, $secret );
		if ( $problem ) {
			/* translators: 1: environment name (Sandbox or Live), 2: problem description. */
			WC_Admin_Settings::add_error( sprintf( __( 'Kwugwo %1$s keys: %2$s', 'kwugwo-for-woocommerce' ), $label, $problem ) );
			return;
		}

		if ( self::is_verified( $mode, $secret ) ) {
			return;
		}

		$result = $this->verify_connection( $mode, $secret );
		if ( is_wp_error( $result ) ) {
			/* translators: 1: environment name (Sandbox or Live), 2: problem description. */
			WC_Admin_Settings::add_error( sprintf( __( 'Kwugwo %1$s keys: %2$s', 'kwugwo-for-woocommerce' ), $label, $result->get_error_message() ) );
		}
	}

	/**
	 * Describe a problem with the shape of a key pair, or '' when they look right.
	 *
	 * @param string $public_key Public key.
	 * @param string $secret_key Secret key.
	 * @return string
	 */
	public static function key_format_problem( $public_key, $secret_key ) {
		if ( '' === $public_key || '' === $secret_key ) {
			return __( 'add both the public key and the secret key.', 'kwugwo-for-woocommerce' );
		}
		if ( 0 !== strpos( $public_key, 'pk.' ) ) {
			return __( 'the public key should start with "pk.". Check that you did not swap the two keys.', 'kwugwo-for-woocommerce' );
		}
		if ( 0 !== strpos( $secret_key, 'sk.' ) ) {
			return __( 'the secret key should start with "sk.". Check that you did not swap the two keys.', 'kwugwo-for-woocommerce' );
		}

		// Both keys carry the workspace segment: pk.XXXX.… and sk.XXXX.….
		$public_key_parts = explode( '.', $public_key );
		$secret_key_parts = explode( '.', $secret_key );
		if ( ! isset( $public_key_parts[1], $secret_key_parts[1] ) || $public_key_parts[1] !== $secret_key_parts[1] ) {
			return __( 'the public key and the secret key come from different Kwugwo workspaces. Copy both from the same workspace.', 'kwugwo-for-woocommerce' );
		}

		return '';
	}

	/**
	 * Call Kwugwo with a secret key and remember it when it works.
	 *
	 * @param string $mode   sandbox or live.
	 * @param string $secret Secret key to check.
	 * @return array|WP_Error Active checkouts on success.
	 */
	public function verify_connection( $mode, $secret ) {
		$checkouts = ( new Kwugwo_API( $secret, 'sandbox' === $mode ) )->list_checkouts();

		if ( is_wp_error( $checkouts ) ) {
			if ( 401 === Kwugwo_API::error_status( $checkouts ) ) {
				$other = 'sandbox' === $mode ? 'live' : 'sandbox';
				return new WP_Error(
					'kwugwo_bad_key',
					sprintf(
						/* translators: 1: environment name, 2: the other environment name. */
						__( 'Kwugwo did not accept this secret key for %1$s. Make sure you copied the whole key from the %1$s side of your dashboard, not %2$s, and that it has not been regenerated since.', 'kwugwo-for-woocommerce' ),
						self::mode_label( $mode ),
						self::mode_label( $other )
					)
				);
			}
			return new WP_Error(
				'kwugwo_unreachable',
				sprintf(
					/* translators: %s: error message. */
					__( 'could not reach Kwugwo to check the key (%s). Try again in a moment.', 'kwugwo-for-woocommerce' ),
					$checkouts->get_error_message()
				)
			);
		}

		$verified          = (array) get_option( 'kwugwo_wc_verified', array() );
		$verified[ $mode ] = md5( $secret );
		update_option( 'kwugwo_wc_verified', $verified, false );
		set_transient( self::checkouts_transient( $mode, $secret ), $checkouts, 10 * MINUTE_IN_SECONDS );

		return $checkouts;
	}

	/**
	 * Whether this secret key has been confirmed with Kwugwo.
	 *
	 * @param string $mode   sandbox or live.
	 * @param string $secret Secret key.
	 * @return bool
	 */
	public static function is_verified( $mode, $secret ) {
		$verified = (array) get_option( 'kwugwo_wc_verified', array() );
		return '' !== $secret && isset( $verified[ $mode ] ) && md5( $secret ) === $verified[ $mode ];
	}

	/**
	 * Active checkouts for the picker, as uid => label.
	 *
	 * @param string $mode sandbox or live.
	 * @return array|WP_Error
	 */
	private function get_checkout_options( $mode ) {
		$secret = $this->get_env_option( 'secret_key', $mode );
		if ( '' === $secret ) {
			return new WP_Error( 'kwugwo_no_key', '' );
		}

		$checkouts = get_transient( self::checkouts_transient( $mode, $secret ) );
		if ( ! is_array( $checkouts ) ) {
			$checkouts = $this->get_api( $mode )->list_checkouts();
			if ( is_wp_error( $checkouts ) ) {
				return $checkouts;
			}
			set_transient( self::checkouts_transient( $mode, $secret ), $checkouts, 10 * MINUTE_IN_SECONDS );
		}

		return self::checkout_labels( $checkouts );
	}

	/**
	 * Turn checkout objects into uid => readable label.
	 *
	 * @param array $checkouts Checkout objects.
	 * @return array
	 */
	public static function checkout_labels( array $checkouts ) {
		$names   = array(
			'bank_transfer' => __( 'Bank transfer', 'kwugwo-for-woocommerce' ),
			'ussd'          => __( 'USSD', 'kwugwo-for-woocommerce' ),
			'pay_with_bank' => __( 'Pay with bank', 'kwugwo-for-woocommerce' ),
			'mobile_money'  => __( 'Mobile money', 'kwugwo-for-woocommerce' ),
		);
		$options = array();

		foreach ( $checkouts as $checkout ) {
			if ( empty( $checkout['uid'] ) ) {
				continue;
			}
			$mediums = array();
			foreach ( isset( $checkout['mediums'] ) ? (array) $checkout['mediums'] : array() as $medium ) {
				$id        = isset( $medium['medium'] ) ? Kwugwo_API::enum_value( $medium['medium'] ) : '';
				$mediums[] = isset( $names[ $id ] ) ? $names[ $id ] : $id;
			}
			$options[ $checkout['uid'] ] = sprintf(
				'%1$s (%2$s: %3$s)',
				$checkout['uid'],
				isset( $checkout['currency'] ) ? $checkout['currency'] : '',
				$mediums ? implode( ', ', $mediums ) : __( 'no payment methods', 'kwugwo-for-woocommerce' )
			);
		}

		return $options;
	}

	/**
	 * Transient name for the cached checkout list of a key.
	 *
	 * @param string $mode   sandbox or live.
	 * @param string $secret Secret key.
	 * @return string
	 */
	private static function checkouts_transient( $mode, $secret ) {
		return 'kwugwo_wc_checkouts_' . $mode . '_' . substr( md5( $secret ), 0, 10 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Environment helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Active mode.
	 *
	 * @return string sandbox or live.
	 */
	public function get_mode() {
		return 'live' === $this->get_option( 'mode' ) ? 'live' : 'sandbox';
	}

	/**
	 * Read a per-environment setting such as public_key or webhook_secret.
	 *
	 * @param string      $name Setting name without the mode prefix.
	 * @param string|null $mode sandbox or live; defaults to the active mode.
	 * @return string
	 */
	public function get_env_option( $name, $mode = null ) {
		$mode = $mode ? $mode : $this->get_mode();
		return trim( (string) $this->get_option( $mode . '_' . $name, '' ) );
	}

	/**
	 * Whether both keys are present for a mode.
	 *
	 * @param string|null $mode sandbox or live; defaults to the active mode.
	 * @return bool
	 */
	public function is_configured( $mode = null ) {
		return '' !== $this->get_env_option( 'public_key', $mode ) && '' !== $this->get_env_option( 'secret_key', $mode );
	}

	/**
	 * API client for a mode.
	 *
	 * @param string|null $mode sandbox or live; defaults to the active mode.
	 * @return Kwugwo_API
	 */
	public function get_api( $mode = null ) {
		$mode = $mode ? $mode : $this->get_mode();
		return new Kwugwo_API( $this->get_env_option( 'secret_key', $mode ), 'sandbox' === $mode );
	}

	/**
	 * The URL merchants register as a webhook endpoint in Kwugwo.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		return add_query_arg( 'wc-api', 'kwugwo_webhook', home_url( '/' ) );
	}

	/**
	 * Translated name of a mode.
	 *
	 * @param string $mode sandbox or live.
	 * @return string
	 */
	public static function mode_label( $mode ) {
		return 'live' === $mode ? __( 'Live', 'kwugwo-for-woocommerce' ) : __( 'Sandbox', 'kwugwo-for-woocommerce' );
	}

	/**
	 * Currencies Kwugwo can charge.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return apply_filters( 'kwugwo_wc_supported_currencies', array( 'NGN' ) );
	}

	/**
	 * Convert an order total to kobo.
	 *
	 * @param string|float $amount Amount in naira.
	 * @return int
	 */
	public static function to_kobo( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Checkout
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Only offer Kwugwo when it is set up for the store currency.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled || ! $this->is_configured() ) {
			return false;
		}
		if ( ! in_array( get_woocommerce_currency(), $this->get_supported_currencies(), true ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Description at checkout, with a reminder when in sandbox mode.
	 */
	public function payment_fields() {
		parent::payment_fields();
		if ( 'sandbox' === $this->get_mode() ) {
			echo '<p class="kwugwo-test-mode">' . esc_html__( 'Test mode: no real money will be taken.', 'kwugwo-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Create (or reuse) the ugwo and send the customer to the payment page.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'We could not find your order. Please try again.', 'kwugwo-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$result = $this->prepare_ugwo( $order );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->needs_payment() ? $order->get_checkout_payment_url( true ) : $this->get_return_url( $order ),
		);
	}

	/**
	 * Make sure the order has a payable ugwo for its current total.
	 *
	 * Reuses the order's existing ugwo when it can still be paid, so a
	 * customer who closes the payment window can try again. Otherwise it
	 * creates a new one with a fresh reference (references are unique on
	 * Kwugwo).
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error Ugwo id, or an error to show the customer.
	 */
	private function prepare_ugwo( $order ) {
		$mode     = $this->get_mode();
		$currency = $order->get_currency();
		$amount   = self::to_kobo( $order->get_total() );

		if ( ! in_array( $currency, $this->get_supported_currencies(), true ) ) {
			return new WP_Error( 'kwugwo_currency', __( 'This payment option only accepts Nigerian Naira (NGN).', 'kwugwo-for-woocommerce' ) );
		}
		if ( $amount < self::NGN_MIN_KOBO || $amount > self::NGN_MAX_KOBO ) {
			return new WP_Error(
				'kwugwo_amount',
				sprintf(
					/* translators: 1: minimum amount, 2: maximum amount. */
					__( 'This payment option accepts orders from %1$s to %2$s.', 'kwugwo-for-woocommerce' ),
					wp_strip_all_tags( wc_price( self::NGN_MIN_KOBO / 100, array( 'currency' => 'NGN' ) ) ),
					wp_strip_all_tags( wc_price( self::NGN_MAX_KOBO / 100, array( 'currency' => 'NGN' ) ) )
				)
			);
		}

		$api      = $this->get_api( $mode );
		$existing = $order->get_meta( self::META_UGWO_UID );

		if ( $existing && $order->get_meta( self::META_ENVIRONMENT ) === $mode ) {
			$ugwo = $api->get_ugwo( $existing );
			if ( ! is_wp_error( $ugwo ) ) {
				$status = Kwugwo_API::enum_value( isset( $ugwo['status'] ) ? $ugwo['status'] : '' );

				if ( 'ugwo_successful' === $status ) {
					Kwugwo_Payment_Sync::apply( $order, $ugwo );
					return $existing;
				}

				$same_amount = isset( $ugwo['amount'], $ugwo['currency'] ) && (int) $ugwo['amount'] === $amount && $ugwo['currency'] === $currency;
				if ( $same_amount && in_array( $status, array( 'requires_ugwo', 'processing' ), true ) ) {
					return $existing;
				}

				// The total changed: retire the old request so it cannot be paid.
				if ( 'requires_ugwo' === $status ) {
					$api->cancel_ugwo( $existing );
				}
			}
		}

		$ugwo = $this->create_ugwo( $api, $order, $amount, $mode );

		if ( is_wp_error( $ugwo ) ) {
			Kwugwo_Logger::log( 'create_ugwo failed: ' . $ugwo->get_error_message(), 'error' );
			$order->add_order_note( self::explain_api_error( $ugwo ) );
			return new WP_Error( 'kwugwo_create', __( 'We could not start your payment. Please try again, or choose another payment option.', 'kwugwo-for-woocommerce' ) );
		}

		$order->update_meta_data( self::META_UGWO_UID, $ugwo['uid'] );
		$order->update_meta_data( self::META_UGWO_REF, isset( $ugwo['ref'] ) ? $ugwo['ref'] : '' );
		$order->update_meta_data( self::META_ENVIRONMENT, $mode );
		$order->update_meta_data( self::META_STATUS, Kwugwo_API::enum_value( $ugwo['status'] ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: environment name, 2: Kwugwo payment id. */
				__( 'Kwugwo (%1$s): payment request %2$s created. Waiting for the customer to pay.', 'kwugwo-for-woocommerce' ),
				self::mode_label( $mode ),
				$ugwo['uid']
			)
		);
		$order->save();

		$this->complete_customer_details( $api, $order, $ugwo );

		return $ugwo['uid'];
	}

	/**
	 * Create the ugwo, moving to a new reference if Kwugwo already has one.
	 *
	 * @param Kwugwo_API $api    Client.
	 * @param WC_Order   $order  Order.
	 * @param int        $amount Amount in kobo.
	 * @param string     $mode   sandbox or live.
	 * @return array|WP_Error
	 */
	private function create_ugwo( Kwugwo_API $api, $order, $amount, $mode ) {
		$body = array(
			'amount'      => $amount,
			'currency'    => $order->get_currency(),
			'description' => $this->build_description( $order ),
			'onye_email'  => $order->get_billing_email(),
			'checkout'    => $this->get_env_option( 'checkout', $mode ),
			'meta'        => array(
				'order_id' => (string) $order->get_id(),
				'source'   => 'woocommerce',
			),
		);

		$attempt = (int) $order->get_meta( self::META_ATTEMPT );
		$ugwo    = null;

		for ( $tries = 0; $tries < 3; $tries++ ) {
			++$attempt;
			$body['ref'] = kwugwo_wc_site_id() . '-' . $order->get_order_number() . ( $attempt > 1 ? '-' . $attempt : '' );
			$ugwo        = $api->create_ugwo( $body );

			if ( ! is_wp_error( $ugwo ) || 'nzube.ugwo.error.ref_already_exists' !== Kwugwo_API::error_code( $ugwo ) ) {
				break;
			}
		}

		$order->update_meta_data( self::META_ATTEMPT, $attempt );

		if ( ! is_wp_error( $ugwo ) && empty( $ugwo['uid'] ) ) {
			return new WP_Error( 'kwugwo_bad_response', __( 'Kwugwo did not return a payment id.', 'kwugwo-for-woocommerce' ) );
		}

		return $ugwo;
	}

	/**
	 * Add the customer's name to their Kwugwo customer record the first time
	 * we see them (Kwugwo creates it from the email alone). Best effort.
	 *
	 * @param Kwugwo_API $api   Client.
	 * @param WC_Order   $order Order.
	 * @param array      $ugwo  Created ugwo.
	 */
	private function complete_customer_details( Kwugwo_API $api, $order, array $ugwo ) {
		$onye = isset( $ugwo['onye_nzube'] ) && is_array( $ugwo['onye_nzube'] ) ? $ugwo['onye_nzube'] : array();
		if ( empty( $onye['uid'] ) || ! empty( $onye['first_name'] ) || ! empty( $onye['last_name'] ) ) {
			return;
		}

		$result = $api->update_onye(
			$onye['uid'],
			array(
				'first_name' => mb_substr( $order->get_billing_first_name(), 0, 100 ),
				'last_name'  => mb_substr( $order->get_billing_last_name(), 0, 100 ),
			)
		);

		if ( is_wp_error( $result ) ) {
			Kwugwo_Logger::log( 'Could not add the customer name: ' . $result->get_error_message(), 'warning' );
		}
	}

	/**
	 * Short description shown in the Kwugwo window (max 150 characters, one line).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function build_description( $order ) {
		$description = sprintf(
			/* translators: 1: order number, 2: store name. */
			__( 'Order %1$s at %2$s', 'kwugwo-for-woocommerce' ),
			$order->get_order_number(),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		return mb_substr( preg_replace( '/\s+/', ' ', $description ), 0, 150 );
	}

	/**
	 * Turn an API error into an order note a store owner can act on.
	 *
	 * @param WP_Error $error API error.
	 * @return string
	 */
	public static function explain_api_error( WP_Error $error ) {
		$status = Kwugwo_API::error_status( $error );
		$code   = Kwugwo_API::error_code( $error );

		switch ( true ) {
			case 401 === $status:
				$hint = __( 'Kwugwo rejected the secret key. Open the Kwugwo settings and use "Test connection".', 'kwugwo-for-woocommerce' );
				break;
			case 402 === $status:
				$hint = __( 'Your Kwugwo subscription balance cannot cover this payment. Top up in your Kwugwo dashboard.', 'kwugwo-for-woocommerce' );
				break;
			case 'nzube.ugwo.error.currency_not_allowed' === $code:
				$hint = __( 'None of the payment providers connected on Kwugwo accept this currency. Connect a provider in your Kwugwo dashboard.', 'kwugwo-for-woocommerce' );
				break;
			case 422 === $status:
				$hint = __( 'Kwugwo did not accept the payment details. If you picked a checkout in the Kwugwo settings, make sure it is still active.', 'kwugwo-for-woocommerce' );
				break;
			case 0 === $status:
				$hint = __( 'Your store could not reach Kwugwo. This is usually temporary.', 'kwugwo-for-woocommerce' );
				break;
			default:
				$hint = '';
		}

		return trim(
			sprintf(
				/* translators: 1: error message from Kwugwo, 2: what to do about it. */
				__( 'Kwugwo could not create the payment request: %1$s %2$s', 'kwugwo-for-woocommerce' ),
				$error->get_error_message(),
				$hint
			)
		);
	}

	/**
	 * Order-pay page: a pay button and the Kwugwo window, opened automatically.
	 *
	 * @param int $order_id Order id.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! $order->needs_payment() ) {
			echo '<p>' . esc_html__( 'This order has already been paid. Thank you!', 'kwugwo-for-woocommerce' ) . '</p>';
			return;
		}

		$ugwo_uid = $order->get_meta( self::META_UGWO_UID );
		if ( ! $ugwo_uid || $order->get_meta( self::META_ENVIRONMENT ) !== $this->get_mode() ) {
			$result   = $this->prepare_ugwo( $order );
			$ugwo_uid = is_wp_error( $result ) ? '' : $result;
		}

		if ( ! $ugwo_uid ) {
			echo '<p>' . esc_html__( 'We could not start your payment. Please refresh this page to try again, or contact the store.', 'kwugwo-for-woocommerce' ) . '</p>';
			return;
		}

		$this->enqueue_checkout_assets( $order, $ugwo_uid );
		?>
		<div class="kwugwo-pay">
			<?php if ( 'sandbox' === $this->get_mode() ) : ?>
				<p class="kwugwo-test-mode"><?php esc_html_e( 'Test mode: no real money will be taken.', 'kwugwo-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<p><?php esc_html_e( 'A secure Kwugwo window will open so you can pay by bank transfer, USSD or with your bank app. If it does not open, use the button below.', 'kwugwo-for-woocommerce' ); ?></p>
			<button type="button" id="kwugwo-pay-button" class="button alt wp-element-button">
				<?php
				printf(
					/* translators: %s: order total. */
					esc_html__( 'Pay %s', 'kwugwo-for-woocommerce' ),
					wp_kses_post( $order->get_formatted_order_total() )
				);
				?>
			</button>
			<p id="kwugwo-pay-status" class="kwugwo-pay__status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Load the Kwugwo checkout script and our page script.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $ugwo_uid Ugwo id.
	 */
	private function enqueue_checkout_assets( $order, $ugwo_uid ) {
		wp_enqueue_script( 'kwugwo-checkout', KWUGWO_WC_URL . 'assets/js/kwugwo-checkout.js', array(), '0.1.3', true );
		wp_enqueue_script( 'kwugwo-pay', KWUGWO_WC_URL . 'assets/js/kwugwo-pay.js', array( 'kwugwo-checkout' ), KWUGWO_WC_VERSION, true );
		wp_enqueue_style( 'kwugwo-pay', KWUGWO_WC_URL . 'assets/css/kwugwo-pay.css', array(), KWUGWO_WC_VERSION );

		wp_localize_script(
			'kwugwo-pay',
			'kwugwoPay',
			array(
				'publicKey' => $this->get_env_option( 'public_key' ),
				'baseUrl'   => apply_filters( 'kwugwo_wc_checkout_base_url', '' ),
				'ugwoUid'   => $ugwo_uid,
				'returnUrl' => $this->get_return_url( $order ),
				'i18n'      => array(
					'opening' => __( 'Opening the secure payment window…', 'kwugwo-for-woocommerce' ),
					'success' => __( 'Payment received. Taking you to your order…', 'kwugwo-for-woocommerce' ),
					'closed'  => __( 'The payment window was closed. Click the button when you are ready to pay.', 'kwugwo-for-woocommerce' ),
					'error'   => __( 'Something went wrong with the payment window. Please refresh the page and try again.', 'kwugwo-for-woocommerce' ),
				),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Refunds
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether this order can be refunded through Kwugwo.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		return $order && $order->get_meta( self::META_UGWO_UID ) && $order->get_transaction_id();
	}

	/**
	 * Refund through Kwugwo. WooCommerce only records the refund when Kwugwo
	 * accepts it; the final result arrives later by webhook.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount in naira.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->can_refund_order( $order ) ) {
			return new WP_Error( 'kwugwo_refund', __( 'This order was not paid through Kwugwo, so it cannot be refunded here.', 'kwugwo-for-woocommerce' ) );
		}

		$kobo = null === $amount ? null : self::to_kobo( $amount );
		if ( null !== $kobo && $kobo < self::NGN_MIN_KOBO ) {
			return new WP_Error(
				'kwugwo_refund',
				sprintf(
					/* translators: %s: minimum refund amount. */
					__( 'Kwugwo refunds must be at least %s.', 'kwugwo-for-woocommerce' ),
					wp_strip_all_tags( wc_price( self::NGN_MIN_KOBO / 100, array( 'currency' => 'NGN' ) ) )
				)
			);
		}

		$mode         = $order->get_meta( self::META_ENVIRONMENT );
		$api          = $this->get_api( $mode ? $mode : 'live' );
		$activity_uid = $order->get_meta( self::META_ACTIVITY_UID );

		if ( ! $activity_uid ) {
			$synced       = Kwugwo_Payment_Sync::sync( $order );
			$activity_uid = is_wp_error( $synced ) ? '' : $order->get_meta( self::META_ACTIVITY_UID );
		}
		if ( ! $activity_uid ) {
			return new WP_Error( 'kwugwo_refund', __( 'Could not find the successful Kwugwo payment for this order. Refund it from your Kwugwo dashboard instead.', 'kwugwo-for-woocommerce' ) );
		}

		$sequence = (int) $order->get_meta( self::META_REFUND_SEQ ) + 1;
		$order->update_meta_data( self::META_REFUND_SEQ, $sequence );
		$order->save();

		$body = array(
			'ugwo_activity' => $activity_uid,
			'amount'        => $kobo,
			'ref'           => $order->get_meta( self::META_UGWO_REF ) . '-refund-' . $sequence,
			'reason'        => mb_substr( (string) $reason, 0, 255 ),
		);

		$destination = $order->get_meta( self::META_REFUND_DESTINATION );
		if ( is_array( $destination ) && ! empty( $destination['provider'] ) && ! empty( $destination['account_number'] ) ) {
			$body['destination'] = array(
				'provider'       => $destination['provider'],
				'account_number' => $destination['account_number'],
			);
		}

		$refund = $api->create_refund( $body );

		if ( is_wp_error( $refund ) ) {
			return new WP_Error( 'kwugwo_refund', self::explain_refund_error( Kwugwo_API::error_code( $refund ), $refund->get_error_message() ) );
		}

		if ( 'failed' === Kwugwo_API::enum_value( isset( $refund['status'] ) ? $refund['status'] : '' ) ) {
			$message = isset( $refund['error']['message'] ) ? (string) $refund['error']['message'] : '';
			return new WP_Error( 'kwugwo_refund', self::explain_refund_error( $message, $message ) );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount, 2: Kwugwo refund id. */
				__( 'Kwugwo refund of %1$s requested (%2$s). The payment provider is processing it; you will see another note when it completes.', 'kwugwo-for-woocommerce' ),
				wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ),
				isset( $refund['uid'] ) ? $refund['uid'] : '-'
			)
		);

		return true;
	}

	/**
	 * Explain a refund failure in plain words.
	 *
	 * @param string $code    Kwugwo message key or provider message.
	 * @param string $message Original message.
	 * @return string
	 */
	private static function explain_refund_error( $code, $message ) {
		if ( false !== strpos( $code, 'missing_destination' ) ) {
			return __( 'The payment provider needs the customer\'s bank account to send this refund. Enter it in the "Kwugwo payment" box on this order, click Update, then try the refund again.', 'kwugwo-for-woocommerce' );
		}
		if ( false !== strpos( $code, 'refund_amount_too_high' ) ) {
			return __( 'This refund is more than what is left to refund on the Kwugwo payment.', 'kwugwo-for-woocommerce' );
		}
		if ( false !== strpos( $code, 'refund_amount_too_low' ) ) {
			return __( 'This refund is below the smallest amount Kwugwo can refund.', 'kwugwo-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: error message. */
			__( 'Kwugwo could not refund this payment: %s', 'kwugwo-for-woocommerce' ),
			$message
		);
	}

	/**
	 * Nigerian banks Kwugwo accepts as a refund destination (code => name),
	 * from https://docs.kwugwo.africa/concepts#bank-codes-nigeria.
	 *
	 * @return array
	 */
	public static function get_refund_banks() {
		$banks = array(
			'access'             => __( 'Access Bank', 'kwugwo-for-woocommerce' ),
			'access_diamond'     => __( 'Access (Diamond)', 'kwugwo-for-woocommerce' ),
			'ecobank'            => __( 'Ecobank Nigeria', 'kwugwo-for-woocommerce' ),
			'fbn'                => __( 'First Bank of Nigeria', 'kwugwo-for-woocommerce' ),
			'fcmb'               => __( 'FCMB', 'kwugwo-for-woocommerce' ),
			'fidelity'           => __( 'Fidelity Bank', 'kwugwo-for-woocommerce' ),
			'finance78'          => __( '78 Finance Company', 'kwugwo-for-woocommerce' ),
			'globus'             => __( 'Globus Bank', 'kwugwo-for-woocommerce' ),
			'gtb'                => __( 'Guaranty Trust Bank (GTBank)', 'kwugwo-for-woocommerce' ),
			'heritage'           => __( 'Heritage Bank', 'kwugwo-for-woocommerce' ),
			'jaiz'               => __( 'Jaiz Bank', 'kwugwo-for-woocommerce' ),
			'keystone'           => __( 'Keystone Bank', 'kwugwo-for-woocommerce' ),
			'kuda'               => __( 'Kuda Microfinance Bank', 'kwugwo-for-woocommerce' ),
			'lotus'              => __( 'Lotus Bank', 'kwugwo-for-woocommerce' ),
			'opay'               => __( 'OPay', 'kwugwo-for-woocommerce' ),
			'optimum_trust_bank' => __( 'Optimum Trust Bank', 'kwugwo-for-woocommerce' ),
			'palmpay'            => __( 'PalmPay', 'kwugwo-for-woocommerce' ),
			'polaris'            => __( 'Polaris Bank', 'kwugwo-for-woocommerce' ),
			'premium_trust_bank' => __( 'PremiumTrust Bank', 'kwugwo-for-woocommerce' ),
			'providus'           => __( 'Providus Bank', 'kwugwo-for-woocommerce' ),
			'rubies_mfb'         => __( 'Rubies (Highstreet) MFB', 'kwugwo-for-woocommerce' ),
			'scb'                => __( 'Standard Chartered Bank', 'kwugwo-for-woocommerce' ),
			'stanbicibank'       => __( 'Stanbic IBTC Bank', 'kwugwo-for-woocommerce' ),
			'sterling'           => __( 'Sterling Bank', 'kwugwo-for-woocommerce' ),
			'uba'                => __( 'United Bank for Africa (UBA)', 'kwugwo-for-woocommerce' ),
			'union'              => __( 'Union Bank of Nigeria', 'kwugwo-for-woocommerce' ),
			'unity'              => __( 'Unity Bank', 'kwugwo-for-woocommerce' ),
			'vfd'                => __( 'VFD Microfinance Bank', 'kwugwo-for-woocommerce' ),
			'wema'               => __( 'Wema Bank', 'kwugwo-for-woocommerce' ),
			'zenith'             => __( 'Zenith Bank', 'kwugwo-for-woocommerce' ),
		);

		/**
		 * Filter the banks offered as a refund destination.
		 *
		 * @param array $banks Kwugwo bank code => bank name.
		 */
		return apply_filters( 'kwugwo_wc_refund_banks', $banks );
	}
}
