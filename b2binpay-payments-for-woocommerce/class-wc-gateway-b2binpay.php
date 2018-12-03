<?php
/**
 * Main B2BinPay Gateway class
 *
 * @package B2Binpay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * B2BinPay Payment Gateway for WooCommerce.
 *
 * @class   WC_Gateway_B2Binpay
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_B2Binpay extends WC_Payment_Gateway {

	/**
	 * Instance of B2BinPay Provider.
	 *
	 * @var \B2Binpay\Provider
	 */
	private $provider;

	/**
	 * Instance of B2Binpay Currency
	 *
	 * @var \B2Binpay\Currency
	 */
	private $currency;

	/**
	 * Instance of B2BinPay AmountFactory.
	 *
	 * @var \B2Binpay\AmountFactory
	 */
	private $amount_factory;

	/**
	 * Markup value.
	 *
	 * @var int
	 */
	private $markup;

	/**
	 * Wallet list.
	 *
	 * @var array
	 */
	private $wallet_list;

	/**
	 * Chosen wallet.
	 *
	 * @var array
	 */
	private $wallet;

	/**
	 * Order Lifetime.
	 *
	 * @var int
	 */
	private $order_lifetime;

	/**
	 * Callback URL.
	 *
	 * @var string
	 */
	private $callback_url;

	/**
	 * B2BinPay API statuses compared to WC order statuses.
	 *
	 * @var array
	 */
	private $order_statuses;

	/**
	 * B2BinPay API statuses.
	 *
	 * @var array
	 */
	private $api_bull_statuses = array(
		'1'  => 'Pending',
		'2'  => 'Success',
		'4'  => 'Closed',
		'-1' => 'Expired',
		'-2' => 'Error',
		'3'  => 'Freeze',
	);

	/**
	 * Default B2BinPay / WC statuses.
	 *
	 * @var array
	 */
	private $default_statuses = array(
		'1'  => 'wc-pending',
		'2'  => 'wc-processing',
		'4'  => 'wc-processing',
		'-1' => 'wc-cancelled',
		'-2' => 'wc-failed',
		'3'  => 'wc-failed',
	);

	/**
	 * WC_Gateway_B2Binpay constructor.
	 *
	 * @param \B2Binpay\Provider|null      $provider B2BinPay Provider.
	 * @param \B2Binpay\Currency|null      $currency B2Binpay Currency.
	 * @param \B2Binpay\AmountFactory|null $amount_factory B2BinPay AmountFactory.
	 */
	public function __construct(
		\B2Binpay\Provider $provider = null,
		\B2Binpay\Currency $currency = null,
		\B2Binpay\AmountFactory $amount_factory = null
	) {
		$this->id         = 'b2binpay';
		$this->has_fields = true;
		$this->icon       = ( 'yes' === $this->get_option( 'show_logo', 'no' ) ) ? apply_filters( 'woocommerce_b2binpay_icon', B2BINPAY_ICON ) : '';

		// Title/description for Payment WC Settings Page.
		$this->method_title       = 'B2BinPay';
		$this->method_description = 'Accept Bitcoin, Bitcoin Cash, Litecoin, Ethereum &amp; more.';

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Title/description for checkout page.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// Payment params.
		$this->order_lifetime = (int) $this->get_option( 'order_lifetime' );
		$this->markup         = (int) $this->get_option( 'markup' );
		$this->order_statuses = (array) $this->get_option( 'order_statuses' );

		// B2BinPay wallet list.
		$this->wallet_list = (array) $this->get_option(
			'wallet_list',
			array(
				array(
					'id'             => $this->get_option( 'id' ),
					'currency_name'  => $this->get_option( 'currency_name' ),
					'currency_alpha' => $this->get_option( 'currency_alpha' ),
					'currency_iso'   => $this->get_option( 'currency_iso' ),
				),
			)
		);

		// Generate callback url.
		$this->callback_url = WC()->api_request_url( 'WC_Gateway_B2Binpay' );

		// Initialize B2BinPay Provider.
		$this->provider = $provider ?? new \B2Binpay\Provider(
			$this->get_option( 'auth_key' ),
			$this->get_option( 'auth_secret' ),
			( 'yes' === $this->get_option( 'test', 'no' ) )
		);

		// Initialize B2BinPay support classes.
		$this->currency       = $currency ?? new \B2Binpay\Currency();
		$this->amount_factory = $amount_factory ?? new \B2Binpay\AmountFactory( $this->currency );

		// Hooks for options being updated.
		if ( is_admin() ) {
			add_action(
				'woocommerce_update_options_payment_gateways_' . $this->id,
				array(
					$this,
					'process_admin_options',
				)
			);
			add_action(
				'woocommerce_update_options_payment_gateways_' . $this->id,
				array(
					$this,
					'save_order_statuses',
				)
			);
			add_action(
				'woocommerce_update_options_payment_gateways_' . $this->id,
				array(
					$this,
					'save_wallet_list',
				)
			);
		}

		// Frontend styles and scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

		// Callback processing function.
		add_action( 'woocommerce_api_wc_gateway_' . $this->id, array( $this, 'return_handler' ) );
	}

	/**
	 * Initialise admin settings form fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'       => __( 'Enable B2BinPay', 'b2binpay-payments-for-woocommerce' ),
				'label'       => __( 'Enable CryptoCurrency payments via B2BinPay', 'b2binpay-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'show_logo'      => array(
				'title'       => __( 'B2BinPay logo', 'b2binpay-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show B2BinPay logo aside payment method title', 'b2binpay-payments-for-woocommerce' ),
				'default'     => 'no',
				'description' => '',
			),
			'title'          => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The payment method title which a customer sees at the checkout of your store.', 'b2binpay-payments-for-woocommerce' ),
				'default'     => __( 'CryptoCurrency', 'b2binpay-payments-for-woocommerce' ),
			),
			'description'    => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'The payment method description which a user sees at the checkout of your store.', 'b2binpay-payments-for-woocommerce' ),
				'default'     => __( 'Pay with Bitcoin, Bitcoin Cash, Litecoin, Ethereum &amp; more.' ),
			),
			'test'           => array(
				'title'       => __( 'Test (Sandbox)', 'b2binpay-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Test Mode (Sandbox)', 'b2binpay-payments-for-woocommerce' ),
				'default'     => 'yes',
				'description' => sprintf(
					__( 'To test on Sandbox, turn Test Mode "On"', 'b2binpay-payments-for-woocommerce' )
				) . '<br />' . __( 'Warning: Sandbox and main gateway has their own pairs of key and secret!', 'b2binpay-payments-for-woocommerce' ),
			),
			'auth_key'       => array(
				'title'       => __( 'Auth Key', 'b2binpay-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'B2BinPay API Auth Key', 'b2binpay-payments-for-woocommerce' ),
				'default'     => '',
			),
			'auth_secret'    => array(
				'title'       => __( 'Auth Secret', 'b2binpay-payments-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'B2BinPay API Auth Secret', 'b2binpay-payments-for-woocommerce' ),
				'default'     => '',
			),
			'wallet_list'    => array(
				'type' => 'wallet_list',
			),
			'markup'         => array(
				'title'       => __( 'Markup (%)', 'b2binpay-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Markup percentage for each payment', 'b2binpay-payments-for-woocommerce' ),
				'default'     => '',
			),
			'order_lifetime' => array(
				'title'       => __( 'Order lifetime (seconds)', 'b2binpay-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Lifetime for your orders in seconds', 'b2binpay-payments-for-woocommerce' ),
				'default'     => '',
			),
			'order_statuses' => array(
				'type' => 'order_statuses',
			),
		);
	}

	/**
	 * Output admin settings header
	 */
	public function admin_options() {
		?>
		<h3><?php echo esc_html( $this->method_title ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: link to B2BinPay */
				__( 'Accept Bitcoin, Bitcoin Cash, Litecoin, Ethereum &amp; more through %s', 'b2binpay-payments-for-woocommerce' ),
				'<a href="https://www.b2binpay.com" target="_blank">B2BinPay</a>'
			);
			?>
			<br/>
			<?php
			printf(
				/* translators: %1$s: open link tag %2$s: close link tag */
				__( 'Check out the list of %1$sAvailable CryptoCurrencies%2$s', 'b2binpay-payments-for-woocommerce' ),
				'<a href="https://www.b2binpay.com/#CryptoCurrencies" target="_blank">',
				'</a>'
			);
			?>
		</p>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
		<?php
	}

	/**
	 * Show description and currency form on checkout page
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wptexturize( $this->description ) );
		}

		// Show form if there are any saved wallets.
		if ( ! empty( $this->wallet_list ) && ! empty( $this->wallet_list[0]['id'] ) ) {
			$this->select_currency_form();
		}
	}

	/**
	 * Output currency form on checkout page
	 */
	public function select_currency_form() {
		?>
		<fieldset id="wc-b2binpay-form" class="wc-payment-form">
			<ul class="b2binpay-currency-list">
				<?php $i = - 1; ?>
				<?php foreach ( $this->wallet_list as $wallet ) : ?>
					<?php $i ++; ?>
					<li class="b2binpay-currency-item">
						<input id="wc-b2binpay-<?php echo esc_html( $wallet['currency_alpha'] ); ?>" class="input-radio"
								name="b2binpay-crypto" value="<?php echo esc_html( $wallet['id'] ); ?>"
							<?php echo esc_html( ( 0 === $i ) ? 'checked="checked"' : '' ); ?> type="radio">
						<label for="wc-b2binpay-<?php echo esc_html( $wallet['currency_alpha'] ); ?>"><?php echo esc_html( $wallet['currency_name'] ); ?>
							(<?php echo esc_html( $wallet['currency_alpha'] ); ?>)</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="clear"></div>
		</fieldset>
		<?php
	}

	/**
	 * Register styles for checkout page
	 */
	public function payment_scripts() {
		// Don't load styles on other pages.
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		// Don't load if plugin deactivated.
		if ( 'no' === $this->enabled ) {
			return;
		}

		// Register main styles.
		wp_enqueue_style( 'b2binpay', plugins_url( 'assets/b2binpay.css', __FILE__ ), array(), '1.0' );

		// Register styles for Storefront-based theme.
		if ( wp_style_is( 'storefront-fonts', 'queue' ) ) {
			wp_enqueue_style( 'b2binpay-storefront', plugins_url( 'assets/b2binpay-storefront.css', __FILE__ ), array(), '1.0' );
		}
	}

	/**
	 * Validate currency form fields on checkout page
	 */
	public function validate_fields() {
		if ( empty( $_POST['b2binpay-crypto'] ) ) {
			wc_add_notice( 'Currency is required!', 'error' );

			return false;
		}

		// Sanitize field.
		$wallet_id = (int) sanitize_text_field( wp_unslash( $_POST['b2binpay-crypto'] ) );

		// Get wallet by id passed from checkout page.
		$this->wallet = array_reduce(
			$this->wallet_list,
			function ( $carry, $item ) use ( $wallet_id ) {
				if ( $item['id'] === $wallet_id ) {
					$carry = $item;
				}

				return $carry;
			},
			array()
		);

		if ( empty( $this->wallet ) ) {
			wc_add_notice( __( 'Unknown currency: ' . $wallet_id, 'b2binpay-payments-for-woocommerce' ), 'error' );

			return false;
		}

		return true;
	}

	/**
	 * Process Payment
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		global $woocommerce;

		// Get WC Order.
		$order = new WC_Order( $order_id );

		// Convert WC Order total to wallet's cryptocurrency.
		$amount = $this->provider->convertCurrency(
			(string) $order->get_total(),
			get_woocommerce_currency(),
			$this->wallet['currency_alpha']
		);

		// Add markup if provided.
		if ( ! empty( $this->markup ) ) {
			$amount = $this->provider->addMarkup(
				$amount,
				$this->wallet['currency_alpha'],
				$this->markup
			);
		}

		try {
			// Try to create bill.
			$bill = $this->provider->createBill(
				$this->wallet['id'],
				$amount,
				$this->wallet['currency_alpha'],
				$this->order_lifetime,
				$order_id,
				$this->callback_url
			);

		} catch ( \B2Binpay\Exception\B2BinpayException $e ) {

			wc_add_notice( __( 'Payment error: ', 'b2binpay-payments-for-woocommerce' ) . $e->getMessage(), 'error' );

			return array(
				'result' => 'fail',
			);
		}

		if ( $bill && $bill->url ) {
			// Reduce stock levels.
			wc_reduce_stock_levels( $order_id );

			// Empty WC cart.
			$woocommerce->cart->empty_cart();

			// Add WC Order note with actual amount.
			$order->add_order_note(
				sprintf(
					/* translators: %1$s: amount %2$s: currency %3$s: bill id */
					__( 'B2BinPay created new invoice for %1$s %2$s. Bill ID: %3$s', 'b2binpay-payments-for-woocommerce' ),
					$amount,
					$this->wallet['currency_alpha'],
					$bill->id
				)
			);

			// Redirect to the created bill page.
			return array(
				'result'   => 'success',
				'redirect' => $bill->url,
			);
		}

		return array(
			'result' => 'fail',
		);
	}

	/**
	 * Process API callback
	 */
	public function return_handler() {
		$headers = $this->get_headers();

		// Check authorisation.
		if ( empty( $headers['Authorization'] ) || ( $headers['Authorization'] !== $this->provider->getAuthorization() ) ) {
			header( 'HTTP/1.1 401 Unauthorized' );
			exit();
		}

		// Check fields.
		if ( empty( $_POST['id'] ) || empty( $_POST['tracking_id'] ) || empty( $_POST['status'] ) ) {
			header( 'HTTP/1.1 400 Bad Request' );
			exit();
		}

		$bill_id     = $_POST['id'];
		$bill_status = (string) $_POST['status'];

		// Get WC Order ID from tracking id.
		$order_id = $_POST['tracking_id'];

		// Get WC Order.
		$order = wc_get_order( $order_id );

		// If bill was paid.
		if ( '2' == $bill_status ) {
			$amount         = $_POST['amount'];
			$actual_amount  = $_POST['actual_amount'];
			$currency_iso   = $_POST['currency']['iso'];
			$currency_alpha = $_POST['currency']['alpha'];
			$pow            = $_POST['pow'];

			// Check if all requested amount fulfilled.
			if ( $amount === $actual_amount ) {
				// Complete WC payment.
				$order->payment_complete();

				$order->add_order_note(
					sprintf(
					/* translators: %s: bill id */
						__( 'B2BinPay payment complete! Bill ID: %s', 'b2binpay-payments-for-woocommerce' ),
						$bill_id
					)
				);
			} else {
				// Generate actual amount from post-data.
				$b2binpay_actual_amount = $this->amount_factory->create(
					$actual_amount,
					$currency_iso,
					$pow
				);

				// Generate requested amount from post-data.
				$b2binpay_requested_amount = $this->amount_factory->create(
					$amount,
					$currency_iso,
					$pow
				);

				$order->add_order_note(
					sprintf(
					/* translators: %1$s: amount %2$s: currency %3$s: amount %4$s: currency %5$s: bill id */
						__( 'B2BinPay received payment. Current amount: %1$s %2$s. Requested amount: %3$s %4$s. Bill ID: %5$s', 'b2binpay-payments-for-woocommerce' ),
						$b2binpay_actual_amount->getValue(),
						$currency_alpha,
						$b2binpay_requested_amount->getValue(),
						$currency_alpha,
						$bill_id
					)
				);
			}
		}

		// Get status messages array.
		$status_messages = $this->get_status_messages();

		// Update order status and message.
		if ( ! empty ( $status_messages[ $bill_status ] ) ) {
			$order->add_order_note(
				sprintf(
					$status_messages[ $bill_status ],
					$bill_id
				)
			);

			$order->update_status( $this->order_statuses[ $bill_status ] );
		}

		header( 'HTTP/1.1 200 OK' );
		exit( 'OK' );
	}

	/**
	 * Get status messages.
	 *
	 * @return array
	 */
	public function get_status_messages() {
		return array(
			'-2' => __( 'B2BinPay payment error! Bill ID: %s', 'b2binpay-payments-for-woocommerce' ),
			'-1' => __( 'B2BinPay payment expired! Bill ID: %s', 'b2binpay-payments-for-woocommerce' ),
			'3'  => __( 'B2BinPay payment freeze! Bill ID: %s', 'b2binpay-payments-for-woocommerce' ),
			'4'  => __( 'B2BinPay payment closed! Bill ID: %s', 'b2binpay-payments-for-woocommerce' )
		);
	}

	/**
	 * Generate wallet list html.
	 *
	 * @return string
	 */
	public function generate_wallet_list_html() {
		ob_start();

		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'B2BinPay wallets:', 'b2binpay-payments-for-woocommerce' ); ?></th>
			<td class="forminp" id="b2binpay_wallets">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
						<tr>
							<th class="sort">&nbsp;</th>
							<th><?php esc_html_e( 'ID', 'b2binpay-payments-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Currency', 'b2binpay-payments-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Currency alpha', 'b2binpay-payments-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Currency ISO', 'b2binpay-payments-for-woocommerce' ); ?></th>
						</tr>
						</thead>
						<tbody class="wallets">
						<?php
						$i = - 1;
						if ( ! empty( $this->wallet_list ) ) {
							foreach ( $this->wallet_list as $wallet ) {
								$i ++;

								echo '<tr class="wallet">
										<td class="sort"></td>
										<td><input type="text" value="' . esc_attr( $wallet['id'] ) . '" name="b2binpay_wallet_id[' . esc_attr( $i ) . ']" /></td>
										<td><input type="text" value="' . esc_attr( $wallet['currency_name'] ) . '" name="b2binpay_currency_name[' . esc_attr( $i ) . ']" /></td>
										<td>' . esc_attr( $wallet['currency_alpha'] ) . '</td>
										<td>' . esc_attr( $wallet['currency_iso'] ) . '</td>
									</tr>';
							}
						}
						?>
						</tbody>
						<tfoot>
						<tr>
							<th colspan="7"><a href="#"
									class="add button"><?php esc_html_e( '+ Add wallet', 'b2binpay-payments-for-woocommerce' ); ?></a>
								<a href="#"
									class="remove_rows button"><?php esc_html_e( 'Remove selected wallet(s)', 'b2binpay-payments-for-woocommerce' ); ?></a>
							</th>
						</tr>
						</tfoot>
					</table>
				</div>
				<script type="text/javascript">
					jQuery(function () {
						jQuery('#b2binpay_wallets').on('click', 'a.add', function () {

							var size = jQuery('#b2binpay_wallets').find('tbody .wallet').length;

							jQuery('<tr class="wallet">\
									<td class="sort"></td>\
									<td><input type="text" name="b2binpay_wallet_id[' + size + ']" /></td>\
									<td><input type="text" name="b2binpay_currency_name[' + size + ']" /></td>\
									<td></td>\
									<td></td>\
									<td></td>\
								</tr>').appendTo('#b2binpay_wallets table tbody');

							return false;
						});
					});
				</script>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Save wallet list table.
	 */
	public function save_wallet_list() {
		if ( ! $this->check_auth() ) {
			return;
		}

		if ( empty( $_POST['b2binpay_wallet_id'] ) ) {

			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error is-dismissible"><p>'
						. __( 'B2BinPay error: You need to enter your wallet id(s).', 'b2binpay-payments-for-woocommerce' )
						. '</p></div>';
				}
			);

			return;
		}

		$wallets = array();

		if ( ! empty( $_POST['b2binpay_wallet_id'] ) ) {

			$wallet_id     = wc_clean( wp_unslash( $_POST['b2binpay_wallet_id'] ) );
			$currency_name = wc_clean( wp_unslash( $_POST['b2binpay_currency_name'] ) );

			foreach ( $wallet_id as $i => $id ) {
				if ( empty( $wallet_id[ $i ] ) ) {
					continue;
				}

				try {
					// Request wallet info by id.
					$wallet = $this->provider->getWallet( (int) $wallet_id[ $i ] );

					$currency = ( '' === $currency_name[ $i ] ) ? $this->currency->getName( $wallet->currency->iso ) : $currency_name[ $i ];

					// Save wallet.
					$wallets[] = array(
						'id'             => $wallet->id,
						'currency_name'  => $currency,
						'currency_alpha' => $wallet->currency->alpha,
						'currency_iso'   => $wallet->currency->iso,
					);

				} catch ( \B2Binpay\Exception\ServerApiException $e ) {

					add_action(
						'admin_notices',
						function () {
							echo '<div class="notice notice-error is-dismissible"><p>'
								. __( 'B2BinPay error: Incorrect wallet id.', 'b2binpay-payments-for-woocommerce' )
								. '</p></div>';
						}
					);

					continue;
				}
			}
		}

		$this->update_option( 'wallet_list', $wallets );
	}

	/**
	 * Check auth params
	 */
	public function check_auth() {
		// Initialize B2BinPay Provider.
		$this->provider = new \B2Binpay\Provider(
			$this->settings['auth_key'],
			$this->settings['auth_secret'],
			( 'yes' === $this->settings['test'] )
		);

		try {
			// Try to get Token with provided API key/secret.
			$this->provider->getAuthToken();

		} catch ( \B2Binpay\Exception\B2BinpayException $e ) {

			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error is-dismissible"><p>'
					     . __( 'B2BinPay error: Wrong key/secret pair.', 'b2binpay-payments-for-woocommerce' )
					     . '</p></div>';
				}
			);

			return false;
		}

		return true;
	}

	/**
	 * Output order statuses form on admin page
	 *
	 * @return string
	 */
	public function generate_order_statuses_html() {
		ob_start();

		// Get WC default statuses.
		$wc_statuses = wc_get_order_statuses();

		// Get WP settings.
		$order_statuses = $this->get_option( 'order_statuses' );

		// Get previously selected statuses.
		$current_statuses = ( empty( $order_statuses ) ) ? $this->default_statuses : $order_statuses;

		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="b2binpay_order_statuses"><?php _e( 'Order Statuses', 'b2binpay-payments-for-woocommerce' ); ?>:</label></th>
			<td class="forminp">
				<table>
					<?php foreach ( $this->api_bull_statuses as $api_status_name => $api_status_title ) : ?>
						<tr>
							<th><?php echo $api_status_title; ?></th>
							<td>
								<select id="b2binpay_order_statuses" name="b2binpay_order_status__<?php echo $api_status_name; ?>">
									<?php foreach ( $wc_statuses as $wc_status_name => $wc_status_title ) : ?>
										<option value="<?php echo $wc_status_name; ?>"
											<?php echo ( $current_statuses[ $api_status_name ] === $wc_status_name ) ? 'selected' : ''; ?>>
											<?php echo $wc_status_title; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Validate order statuses form fields on admin page
	 *
	 * @return string
	 */
	public function validate_order_statuses_field() {
		$order_statuses = $this->get_option( 'order_statuses' );

		// Retrieve order statuses from post-data.
		if ( ! empty( $_POST[ $this->plugin_id . $this->id . '_order_statuses' ] ) ) {
			$order_statuses = $_POST[ $this->plugin_id . $this->id . '_order_statuses' ];
		}

		return $order_statuses;
	}

	/**
	 * Save order statuses form fields on admin page
	 */
	public function save_order_statuses() {
		// Get WC default statuses.
		$wc_statuses = wc_get_order_statuses();

		$order_statuses = [];

		foreach ( $this->api_bull_statuses as $api_status_name => $api_status_title ) {
			if ( empty( $_POST[ 'b2binpay_order_status__' . $api_status_name ] ) ) {
				continue;
			}

			// Get status from post-data.
			$wc_status_name = $_POST[ 'b2binpay_order_status__' . $api_status_name ];

			// Update order status.
			if ( array_key_exists( $wc_status_name, $wc_statuses ) ) {
				$order_statuses[ $api_status_name ] = $wc_status_name;
			}
		}

		// Store new order statuses in WP settings.
		$this->update_option( 'order_statuses', $order_statuses );
	}

	/**
	 * Get request headers on Apache2 / Nginx
	 *
	 * @return array
	 */
	public function get_headers() {
		if ( function_exists( 'getallheaders' ) ) {
			return getallheaders();
		} else {
			if ( ! is_array( $_SERVER ) ) {
				return array();
			}
			$headers = array();
			foreach ( $_SERVER as $name => $value ) {
				if ( substr( $name, 0, 5 ) === 'HTTP_' ) {
					$key             = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) );
					$headers[ $key ] = $value;
				}
			}

			return $headers;
		}
	}
}
