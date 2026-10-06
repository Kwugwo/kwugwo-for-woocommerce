<?php
/**
 * Admin screens: the setup guide on the settings page, "Test connection",
 * the Kwugwo box on orders, the setup reminder and privacy policy text.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Kwugwo_Admin {

	/**
	 * Kwugwo's outbound IP, for stores behind a firewall.
	 */
	const WEBHOOK_IP = '176.97.192.227';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_kwugwo_wc_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_refund_destination' ) );
		add_action( 'admin_post_kwugwo_wc_sync_order', array( __CLASS__, 'sync_order' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * Whether the current screen is the Kwugwo settings page.
	 *
	 * @return bool
	 */
	private static function is_settings_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only reads which page is open.
		return is_admin()
			&& isset( $_GET['page'], $_GET['tab'], $_GET['section'] )
			&& 'wc-settings' === $_GET['page']
			&& 'checkout' === $_GET['tab']
			&& KWUGWO_WC_GATEWAY_ID === $_GET['section'];
		// phpcs:enable
	}

	/**
	 * Load the settings page script and styles.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_settings_page() ) {
			return;
		}

		wp_enqueue_style( 'kwugwo-admin', KWUGWO_WC_URL . 'assets/css/admin.css', array(), KWUGWO_WC_VERSION );
		wp_enqueue_script( 'kwugwo-admin', KWUGWO_WC_URL . 'assets/js/admin.js', array(), KWUGWO_WC_VERSION, true );
		wp_localize_script(
			'kwugwo-admin',
			'kwugwoAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kwugwo_wc_admin' ),
				'i18n'    => array(
					'testing'   => __( 'Checking with Kwugwo…', 'kwugwo-for-woocommerce' ),
					'failed'    => __( 'Could not check the keys. Please try again.', 'kwugwo-for-woocommerce' ),
					'copied'    => __( 'Copied', 'kwugwo-for-woocommerce' ),
					'copy'      => __( 'Copy', 'kwugwo-for-woocommerce' ),
					'automatic' => __( 'Automatic (use my routing rules)', 'kwugwo-for-woocommerce' ),
				),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Setup guide
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Setup checklist and step-by-step guide shown above the settings.
	 *
	 * @param Kwugwo_Gateway $gateway Gateway.
	 */
	public static function render_setup_guide( Kwugwo_Gateway $gateway ) {
		$mode     = $gateway->get_mode();
		$label    = Kwugwo_Gateway::mode_label( $mode );
		$checks   = self::checklist( $gateway );
		$complete = ! in_array( false, wp_list_pluck( $checks, 'done' ), true );
		?>
		<div class="kwugwo-setup">
			<p class="kwugwo-mode kwugwo-mode--<?php echo esc_attr( $mode ); ?>">
				<?php
				if ( 'live' === $mode ) {
					esc_html_e( 'Live mode: customers pay with real money.', 'kwugwo-for-woocommerce' );
				} else {
					esc_html_e( 'Sandbox mode: payments are tests and no real money moves. Switch to Live under "Test or live payments" when you are ready.', 'kwugwo-for-woocommerce' );
				}
				?>
			</p>

			<div class="kwugwo-setup__checklist">
				<h3>
					<?php
					/* translators: %s: environment name (Sandbox or Live). */
					echo esc_html( sprintf( __( 'Setup checklist (%s)', 'kwugwo-for-woocommerce' ), $label ) );
					?>
				</h3>
				<ul>
					<?php foreach ( $checks as $check ) : ?>
						<li class="<?php echo esc_attr( $check['done'] ? 'is-done' : 'is-todo' ); ?>">
							<span class="dashicons <?php echo esc_attr( $check['done'] ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php echo esc_html( $check['done'] ? __( 'Done:', 'kwugwo-for-woocommerce' ) : __( 'To do:', 'kwugwo-for-woocommerce' ) ); ?></span>
							<?php echo wp_kses_post( $check['text'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $complete ) : ?>
					<p><strong><?php esc_html_e( 'All set. Kwugwo is ready to take payments.', 'kwugwo-for-woocommerce' ); ?></strong></p>
				<?php endif; ?>
			</div>

			<details class="kwugwo-guide" <?php echo $complete ? '' : 'open'; ?>>
				<summary><?php esc_html_e( 'How to set up Kwugwo, step by step', 'kwugwo-for-woocommerce' ); ?></summary>
				<ol class="kwugwo-guide__steps">
					<li>
						<strong><?php esc_html_e( 'Get your Kwugwo account ready', 'kwugwo-for-woocommerce' ); ?></strong>
						<p>
							<?php
							printf(
								/* translators: %s: link to the Kwugwo dashboard. */
								esc_html__( 'Sign in to your %s. Connect the payment provider that already collects money for you, then create a checkout for NGN and choose the payment methods to offer (bank transfer, USSD, pay with bank). Money goes straight into your own provider account; Kwugwo never holds it.', 'kwugwo-for-woocommerce' ),
								'<a href="' . esc_url( Kwugwo_Gateway::DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Kwugwo dashboard', 'kwugwo-for-woocommerce' ) . '</a>'
							);
							?>
						</p>
						<p><?php esc_html_e( 'Kwugwo has a Sandbox side for testing and a Live side for real payments. Each has its own keys, checkouts and webhooks, so you will do steps 2 and 3 once for each.', 'kwugwo-for-woocommerce' ); ?></p>
					</li>
					<li>
						<strong><?php esc_html_e( 'Copy your API keys', 'kwugwo-for-woocommerce' ); ?></strong>
						<p><?php esc_html_e( 'On the API Keys page of your dashboard, copy the public key (starts with "pk.") and the secret key (starts with "sk.") into the matching boxes below, then click "Test connection". Never share the secret key with anyone; anyone who has it can manage payments on your account.', 'kwugwo-for-woocommerce' ); ?></p>
					</li>
					<li>
						<strong><?php esc_html_e( 'Connect webhooks so orders update by themselves', 'kwugwo-for-woocommerce' ); ?></strong>
						<p><?php esc_html_e( 'A webhook is how Kwugwo tells your store that a customer has paid, even if they close their browser before coming back. Without it, some orders may stay "Pending payment".', 'kwugwo-for-woocommerce' ); ?></p>
						<ol>
							<li><?php esc_html_e( 'Copy the Webhook URL below.', 'kwugwo-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'On the Webhooks page of your dashboard, add a new endpoint and paste the URL. Set a signing secret when asked.', 'kwugwo-for-woocommerce' ); ?></li>
							<li>
								<?php esc_html_e( 'Choose the events to send. Tick these:', 'kwugwo-for-woocommerce' ); ?>
								<ul class="kwugwo-guide__events">
									<?php foreach ( self::webhook_events() as $event => $purpose ) : ?>
										<li><code><?php echo esc_html( $event ); ?></code>: <?php echo esc_html( $purpose ); ?></li>
									<?php endforeach; ?>
								</ul>
							</li>
							<li><?php esc_html_e( 'Copy the signing secret (starts with "wsk.") into "Webhook signing secret" below, then save.', 'kwugwo-for-woocommerce' ); ?></li>
						</ol>
						<p class="description">
							<?php
							printf(
								/* translators: %s: IP address. */
								esc_html__( 'If your hosting has a firewall, allow requests from Kwugwo\'s address %s.', 'kwugwo-for-woocommerce' ),
								'<code>' . esc_html( self::WEBHOOK_IP ) . '</code>'
							);
							?>
						</p>
					</li>
					<li>
						<strong><?php esc_html_e( 'Try a test payment, then go live', 'kwugwo-for-woocommerce' ); ?></strong>
						<p><?php esc_html_e( 'In Sandbox mode, place an order on your store and pay in the Kwugwo window. Depending on your payment provider, sandbox payments either complete by themselves or need you to approve them with the provider\'s test tools. The order should move to "Processing" on its own.', 'kwugwo-for-woocommerce' ); ?></p>
						<p><?php esc_html_e( 'When that works, set Mode to Live, add your Live keys and Live webhook signing secret, and save. Your customers can now pay you for real.', 'kwugwo-for-woocommerce' ); ?></p>
					</li>
				</ol>
				<p>
					<?php
					printf(
						/* translators: %s: link to the Kwugwo documentation. */
						esc_html__( 'Need more detail? Read the %s.', 'kwugwo-for-woocommerce' ),
						'<a href="https://docs.kwugwo.africa/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Kwugwo documentation', 'kwugwo-for-woocommerce' ) . '</a>'
					);
					?>
				</p>
			</details>
		</div>
		<?php
	}

	/**
	 * Webhook events the plugin acts on, with what each one does.
	 *
	 * @return array Event name => purpose.
	 */
	private static function webhook_events() {
		return array(
			'ugwo.successful'                      => __( 'required; marks the order paid', 'kwugwo-for-woocommerce' ),
			'ugwo.updated'                         => __( 'updates the order when a payment is cancelled or refunded', 'kwugwo-for-woocommerce' ),
			'ugwo.activity.updated'                => __( 'puts the order on hold if the payment provider reverses a payment', 'kwugwo-for-woocommerce' ),
			'ugwo.activity.double_charge_detected' => __( 'warns you when a customer pays twice', 'kwugwo-for-woocommerce' ),
			'refund.successful'                    => __( 'notes on the order when a refund completes', 'kwugwo-for-woocommerce' ),
			'refund.failed'                        => __( 'warns you on the order when a refund fails', 'kwugwo-for-woocommerce' ),
		);
	}

	/**
	 * Setup checklist items for the active mode.
	 *
	 * @param Kwugwo_Gateway $gateway Gateway.
	 * @return array[] Each item has `done` (bool) and `text` (HTML).
	 */
	private static function checklist( Kwugwo_Gateway $gateway ) {
		$mode     = $gateway->get_mode();
		$label    = Kwugwo_Gateway::mode_label( $mode );
		$currency = get_woocommerce_currency();
		$secret   = $gateway->get_env_option( 'secret_key' );
		$received = (array) get_option( 'kwugwo_wc_last_webhook', array() );
		$checks   = array();

		$checks[] = in_array( $currency, $gateway->get_supported_currencies(), true )
			? self::check( true, __( 'Your store currency is Nigerian Naira (NGN).', 'kwugwo-for-woocommerce' ) )
			: self::check(
				false,
				sprintf(
					/* translators: 1: store currency code, 2: link to general settings. */
					__( 'Your store currency is %1$s, but Kwugwo only accepts Nigerian Naira (NGN). Change it in %2$s.', 'kwugwo-for-woocommerce' ),
					esc_html( $currency ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'general settings', 'kwugwo-for-woocommerce' ) . '</a>'
				)
			);

		if ( ! $gateway->is_configured() ) {
			/* translators: %s: environment name. */
			$checks[] = self::check( false, esc_html( sprintf( __( 'Add your %s public and secret keys (step 2).', 'kwugwo-for-woocommerce' ), $label ) ) );
		} elseif ( ! Kwugwo_Gateway::is_verified( $mode, $secret ) ) {
			/* translators: %s: environment name. */
			$checks[] = self::check( false, esc_html( sprintf( __( 'Your %s keys are saved but have not been confirmed. Click "Test connection".', 'kwugwo-for-woocommerce' ), $label ) ) );
		} else {
			/* translators: %s: environment name. */
			$checks[] = self::check( true, esc_html( sprintf( __( 'Your %s keys work.', 'kwugwo-for-woocommerce' ), $label ) ) );
		}

		if ( '' === $gateway->get_env_option( 'webhook_secret' ) ) {
			/* translators: %s: environment name. */
			$checks[] = self::check( false, esc_html( sprintf( __( 'Add the %s webhook and its signing secret (step 3).', 'kwugwo-for-woocommerce' ), $label ) ) );
		} elseif ( empty( $received[ $mode ] ) ) {
			$checks[] = self::check( true, esc_html__( 'Webhook signing secret saved. Kwugwo has not sent an update yet; the first one arrives with your first payment.', 'kwugwo-for-woocommerce' ) );
		} else {
			$checks[] = self::check(
				true,
				esc_html(
					sprintf(
						/* translators: %s: time since, e.g. "5 mins". */
						__( 'Webhook connected. Kwugwo last sent an update %s ago.', 'kwugwo-for-woocommerce' ),
						human_time_diff( (int) $received[ $mode ] )
					)
				)
			);
		}

		$checks[] = 'yes' === $gateway->enabled
			? self::check( true, esc_html__( 'Kwugwo is turned on at checkout.', 'kwugwo-for-woocommerce' ) )
			: self::check( false, esc_html__( 'Tick "Turn on Kwugwo" below and save to show it at checkout.', 'kwugwo-for-woocommerce' ) );

		return $checks;
	}

	/**
	 * Build one checklist item.
	 *
	 * @param bool   $done Whether the item is done.
	 * @param string $text HTML text.
	 * @return array
	 */
	private static function check( $done, $text ) {
		return array(
			'done' => $done,
			'text' => $text,
		);
	}

	/**
	 * AJAX: check the keys typed on the settings page with Kwugwo.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'kwugwo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to change payment settings.', 'kwugwo-for-woocommerce' ) ), 403 );
		}

		$gateway = kwugwo_wc_gateway();
		$mode    = isset( $_POST['mode'] ) && 'live' === $_POST['mode'] ? 'live' : 'sandbox';
		$public  = isset( $_POST['public_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['public_key'] ) ) ) : '';
		$secret  = isset( $_POST['secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) ) : '';

		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'The Kwugwo payment method is not loaded.', 'kwugwo-for-woocommerce' ) ) );
		}

		$problem = Kwugwo_Gateway::key_format_problem( $public, $secret );
		if ( $problem ) {
			wp_send_json_error( array( 'message' => ucfirst( $problem ) ) );
		}

		$checkouts = $gateway->verify_connection( $mode, $secret );
		if ( is_wp_error( $checkouts ) ) {
			wp_send_json_error( array( 'message' => ucfirst( $checkouts->get_error_message() ) ) );
		}

		$labels = Kwugwo_Gateway::checkout_labels( $checkouts );
		if ( $labels ) {
			$message = sprintf(
				/* translators: 1: environment name, 2: number of checkouts. */
				_n( 'Connected to Kwugwo %1$s. You have %2$d active checkout. Save your changes to keep these keys.', 'Connected to Kwugwo %1$s. You have %2$d active checkouts. Save your changes to keep these keys.', count( $labels ), 'kwugwo-for-woocommerce' ),
				Kwugwo_Gateway::mode_label( $mode ),
				count( $labels )
			);
		} else {
			$message = sprintf(
				/* translators: %s: environment name. */
				__( 'Connected to Kwugwo %s, but there are no active checkouts yet. Create one in your Kwugwo dashboard before taking payments, then save your changes here.', 'kwugwo-for-woocommerce' ),
				Kwugwo_Gateway::mode_label( $mode )
			);
		}

		wp_send_json_success(
			array(
				'message'   => $message,
				'checkouts' => $labels,
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Orders
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Add the "Kwugwo payment" box to Kwugwo orders.
	 *
	 * @param string           $screen_id     Screen id.
	 * @param WP_Post|WC_Order $post_or_order Post (legacy storage) or order (HPOS).
	 */
	public static function add_order_meta_box( $screen_id, $post_or_order = null ) {
		if ( ! in_array( $screen_id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );
		if ( ! $order || ! $order->get_meta( Kwugwo_Gateway::META_UGWO_UID ) ) {
			return;
		}

		add_meta_box( 'kwugwo-payment', __( 'Kwugwo payment', 'kwugwo-for-woocommerce' ), array( __CLASS__, 'render_order_meta_box' ), $screen_id, 'side', 'default' );
	}

	/**
	 * Render the order box: payment details, a status check, and the refund account.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post (legacy storage) or order (HPOS).
	 */
	public static function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$destination = (array) $order->get_meta( Kwugwo_Gateway::META_REFUND_DESTINATION );
		$sync_url    = wp_nonce_url(
			admin_url( 'admin-post.php?action=kwugwo_wc_sync_order&order_id=' . $order->get_id() ),
			'kwugwo_wc_sync_order_' . $order->get_id()
		);
		$rows        = array(
			__( 'Mode', 'kwugwo-for-woocommerce' )       => Kwugwo_Gateway::mode_label( $order->get_meta( Kwugwo_Gateway::META_ENVIRONMENT ) ),
			__( 'Status', 'kwugwo-for-woocommerce' )     => self::status_label( $order->get_meta( Kwugwo_Gateway::META_STATUS ) ),
			__( 'Payment ID', 'kwugwo-for-woocommerce' ) => $order->get_meta( Kwugwo_Gateway::META_UGWO_UID ),
			__( 'Reference', 'kwugwo-for-woocommerce' )  => $order->get_meta( Kwugwo_Gateway::META_UGWO_REF ),
			__( 'Provider reference', 'kwugwo-for-woocommerce' ) => $order->get_meta( Kwugwo_Gateway::META_PSP_REF ),
		);
		?>
		<dl class="kwugwo-order-details">
			<?php foreach ( array_filter( $rows ) as $term => $value ) : ?>
				<dt><?php echo esc_html( $term ); ?></dt>
				<dd><code><?php echo esc_html( $value ); ?></code></dd>
			<?php endforeach; ?>
		</dl>
		<p>
			<a class="button" href="<?php echo esc_url( $sync_url ); ?>"><?php esc_html_e( 'Check payment status now', 'kwugwo-for-woocommerce' ); ?></a>
		</p>

		<?php if ( $order->get_transaction_id() ) : ?>
			<?php wp_nonce_field( 'kwugwo_wc_refund_destination', 'kwugwo_wc_refund_nonce' ); ?>
			<p><strong><?php esc_html_e( 'Refund account (only if asked)', 'kwugwo-for-woocommerce' ); ?></strong></p>
			<p class="description"><?php esc_html_e( 'Some payment providers cannot send money back to a bank transfer or USSD payer on their own. If a refund asks for the customer\'s account, enter it here, click Update, then refund again.', 'kwugwo-for-woocommerce' ); ?></p>
			<p>
				<label for="kwugwo_refund_bank"><?php esc_html_e( 'Bank', 'kwugwo-for-woocommerce' ); ?></label>
				<select id="kwugwo_refund_bank" name="kwugwo_refund_bank" style="width:100%">
					<option value=""><?php esc_html_e( 'Choose a bank', 'kwugwo-for-woocommerce' ); ?></option>
					<?php foreach ( Kwugwo_Gateway::get_refund_banks() as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( isset( $destination['provider'] ) ? $destination['provider'] : '', $code ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="kwugwo_refund_account"><?php esc_html_e( 'Account number (10 digits)', 'kwugwo-for-woocommerce' ); ?></label>
				<input type="text" id="kwugwo_refund_account" name="kwugwo_refund_account" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" style="width:100%" value="<?php echo esc_attr( isset( $destination['account_number'] ) ? $destination['account_number'] : '' ); ?>" />
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Readable name of an ugwo status.
	 *
	 * @param string $status Ugwo status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'requires_ugwo'      => __( 'Waiting for payment', 'kwugwo-for-woocommerce' ),
			'processing'         => __( 'Customer is paying', 'kwugwo-for-woocommerce' ),
			'ugwo_successful'    => __( 'Paid', 'kwugwo-for-woocommerce' ),
			'partially_refunded' => __( 'Partly refunded', 'kwugwo-for-woocommerce' ),
			'refunded'           => __( 'Refunded', 'kwugwo-for-woocommerce' ),
			'cancelled'          => __( 'Cancelled', 'kwugwo-for-woocommerce' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Save the refund account entered in the order box.
	 *
	 * @param int $order_id Order id.
	 */
	public static function save_refund_destination( $order_id ) {
		if ( ! isset( $_POST['kwugwo_wc_refund_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kwugwo_wc_refund_nonce'] ) ), 'kwugwo_wc_refund_destination' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$bank    = isset( $_POST['kwugwo_refund_bank'] ) ? sanitize_key( wp_unslash( $_POST['kwugwo_refund_bank'] ) ) : '';
		$account = isset( $_POST['kwugwo_refund_account'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['kwugwo_refund_account'] ) ) ) : '';

		if ( '' === $bank && '' === $account ) {
			$order->delete_meta_data( Kwugwo_Gateway::META_REFUND_DESTINATION );
		} elseif ( ! array_key_exists( $bank, Kwugwo_Gateway::get_refund_banks() ) || 10 !== strlen( $account ) ) {
			WC_Admin_Meta_Boxes::add_error( __( 'Kwugwo refund account not saved: choose a bank and enter a 10-digit account number.', 'kwugwo-for-woocommerce' ) );
			return;
		} else {
			$order->update_meta_data(
				Kwugwo_Gateway::META_REFUND_DESTINATION,
				array(
					'provider'       => $bank,
					'account_number' => $account,
				)
			);
		}

		$order->save();
	}

	/**
	 * Admin action: fetch the latest payment status for an order.
	 */
	public static function sync_order() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'kwugwo_wc_sync_order_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this order.', 'kwugwo-for-woocommerce' ) );
		}

		$result = Kwugwo_Payment_Sync::sync( $order );

		wp_safe_redirect( add_query_arg( 'kwugwo_synced', is_wp_error( $result ) ? 'error' : 'ok', $order->get_edit_order_url() ) );
		exit;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Notices and privacy
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Result of "Check payment status now", and a reminder when Kwugwo is
	 * turned on without keys (WooCommerce and Plugins screens only).
	 */
	public static function admin_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$synced = isset( $_GET['kwugwo_synced'] ) ? sanitize_key( wp_unslash( $_GET['kwugwo_synced'] ) ) : '';
		if ( 'ok' === $synced ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Kwugwo payment status checked. Any change is in the order notes.', 'kwugwo-for-woocommerce' ) . '</p></div>';
		} elseif ( 'error' === $synced ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not reach Kwugwo to check this payment. Check your keys in the Kwugwo settings, or try again in a moment.', 'kwugwo-for-woocommerce' ) . '</p></div>';
		}

		$screen = get_current_screen();
		if ( ! $screen || self::is_settings_page() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( 'plugins' !== $screen->id && false === strpos( $screen->id, 'woocommerce' ) && 'shop_order' !== $screen->id ) {
			return;
		}

		$gateway = kwugwo_wc_gateway();
		if ( ! $gateway || 'yes' !== $gateway->enabled || $gateway->is_configured() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: environment name. */
					__( 'Kwugwo is turned on but its %s keys are missing, so customers cannot see it at checkout.', 'kwugwo-for-woocommerce' ),
					Kwugwo_Gateway::mode_label( $gateway->get_mode() )
				)
			),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . KWUGWO_WC_GATEWAY_ID ) ),
			esc_html__( 'Finish setting up Kwugwo', 'kwugwo-for-woocommerce' )
		);
	}

	/**
	 * Suggested text for the site's privacy policy.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'We use Kwugwo (kwugwo.africa) to process payments. When you choose to pay with Kwugwo, we send Kwugwo your email address, your name, the order amount and currency, and the order number, so it can create the payment and show you the payment options. You then enter your payment details in a secure window hosted by Kwugwo; we never see your bank details.', 'kwugwo-for-woocommerce' ) . '</p>'
			. '<p>'
			. sprintf(
				/* translators: 1: Kwugwo privacy policy URL, 2: Kwugwo terms URL. */
				__( 'See the <a href="%1$s">Kwugwo privacy policy</a> and <a href="%2$s">terms of service</a>.', 'kwugwo-for-woocommerce' ),
				'https://kwugwo.africa/privacy',
				'https://kwugwo.africa/terms'
			)
			. '</p>';

		wp_add_privacy_policy_content( __( 'Kwugwo for WooCommerce', 'kwugwo-for-woocommerce' ), wp_kses_post( $content ) );
	}
}
