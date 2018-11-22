<?php
/**
 * Unit tests for B2BinPay Payment Gateway
 *
 * @package B2Binpay
 */

/**
 * Class WC_Gateway_B2Binpay_Test
 */
class WC_Gateway_B2Binpay_Test extends WP_UnitTestCase {
	/**
	 * B2Binpay Provider mock object
	 *
	 * @var \B2Binpay\Provider
	 */
	private $b2binpay;

	/**
	 * B2Binpay Currency mock object
	 *
	 * @var \B2Binpay\Currency
	 */
	private $currency;

	/**
	 * B2Binpay AmountFactory mock object
	 *
	 * @var \B2Binpay\AmountFactory
	 */
	private $amount_factory;

	/**
	 * Testing class
	 *
	 * @var WC_Gateway_B2Binpay
	 */
	private $gateway;

	/**
	 * Mock products
	 *
	 * @var array
	 */
	private $products;

	/**
	 * Mock wallets
	 *
	 * @var array
	 */
	private $wallet_list = array(
		array(
			'id'             => 1,
			'currency_name'  => 'Ethereum',
			'currency_alpha' => 'ETH',
			'currency_iso'   => 1002
		),
		array(
			'id'             => 2,
			'currency_name'  => 'Bitcoin',
			'currency_alpha' => 'BTC',
			'currency_iso'   => 1000
		)
	);

	/**
	 * Mock order statuses
	 *
	 * @var array
	 */
	private $order_statuses = array(
		'1'  => 'wc-pending',
		'2'  => 'wc-processing',
		'4'  => 'wc-processing',
		'-1' => 'wc-cancelled',
		'-2' => 'wc-failed',
		'3'  => 'wc-failed',
	);

	/**
	 * Set up mock objects.
	 */
	public function setUp() {
		$settings = array(
			'order_statuses' => $this->order_statuses,
			'wallet_list'    => $this->wallet_list,
			'markup'         => 10
		);

		update_option( 'woocommerce_b2binpay_settings', $settings );

		$this->b2binpay       = $this->createMock( \B2Binpay\Provider::class );
		$this->currency       = $this->createMock( \B2Binpay\Currency::class );
		$this->amount_factory = $this->createMock( \B2Binpay\AmountFactory::class );

		$this->gateway = $this->getMockBuilder( WC_Gateway_B2Binpay::class )
		                      ->setConstructorArgs( array(
			                      $this->b2binpay,
			                      $this->currency,
			                      $this->amount_factory
		                      ) )
		                      ->setMethods( array(
			                      'get_headers'
		                      ) )
		                      ->getMock();
	}

	/**
	 * Test that WC_Gateway_B2Binpay payment gateway has loaded.
	 */
	public function test_gateway_loaded() {
		$this->assertArrayHasKey( 'b2binpay', WC()->payment_gateways()->payment_gateways() );
	}

	/**
	 * Test validate_fields()
	 */
	public function test_validate_fields() {
		$result = $this->gateway->validate_fields();
		$this->assertFalse( $result );

		$_POST['b2binpay-crypto'] = 9999;

		$result = $this->gateway->validate_fields();
		$this->assertFalse( $result );

		$_POST['b2binpay-crypto'] = 2;

		$result = $this->gateway->validate_fields();
		$this->assertTrue( $result );
	}

	/**
	 * Test process_payment()
	 */
	public function test_process_payment() {
		// User set up.
		$user = $this->factory->user->create( array(
			'role' => 'administrator',
		) );
		wp_set_current_user( $user );

		// Create products.
		$this->create_products( 3 );

		$order = WC_Helper_Order::create_order( $user );
		$this->add_products_to_order( $order, array() );

		$order->set_payment_method( $this->gateway );

		$order->calculate_shipping();
		$order->calculate_totals();

		add_filter( 'pre_http_request', array( $this, 'pre_http_request_charge_success' ) );

		$amount = '0.00000001';

		$bill_url = 'http://redirect.url';

		$bill = (object) array(
			'id'  => 123,
			'url' => $bill_url
		);

		$expect = array(
			'result'   => 'success',
			'redirect' => $bill_url,
		);

		$_POST['b2binpay-crypto'] = 2;

		$this->b2binpay->method( 'convertCurrency' )
		               ->willReturn( $amount );

		$this->gateway->validate_fields();

		$result = $this->gateway->process_payment( $order->get_id() );
		$this->assertSame( 'fail', $result['result'] );

		$this->b2binpay->method( 'createBill' )
		               ->will( $this->onConsecutiveCalls(
			               $this->throwException( new \B2Binpay\Exception\B2BinpayException() ),
			               $bill
		               ) );

		$result = $this->gateway->process_payment( $order->get_id() );
		$this->assertSame( 'fail', $result['result'] );

		$result = $this->gateway->process_payment( $order->get_id() );

		remove_filter( 'pre_http_request', array( $this, 'pre_http_request_charge_success' ) );

		$this->assertSame( $expect, $result );

		// Remove order and created products.
		WC_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Test validate_order_statuses_field()
	 */
	public function test_validate_order_statuses_field() {
		$order_statuses_post = array( 1, 2 );

		$result = $this->gateway->validate_order_statuses_field();
		$this->assertSame( $this->order_statuses, $result );

		$_POST['woocommerce_b2binpay_order_statuses'] = $order_statuses_post;

		$result = $this->gateway->validate_order_statuses_field();
		$this->assertSame( $order_statuses_post, $result );
	}

	/**
	 * Create $product_count simple products and store them in $this->products.
	 *
	 * @param int $product_count Number of products to create.
	 */
	protected function create_products( $product_count = 30 ) {
		$this->products = array();
		for ( $i = 0; $i < $product_count; $i ++ ) {
			$product = WC_Helper_Product::create_simple_product( false );
			$product->set_name( 'Dummy Product ' . $i );
			$this->products[] = $product;
		}

		if ( array_key_exists( 0, $this->products ) ) {
			$this->products[0]->set_name( 'Mock Product' );
		}
	}

	/**
	 * Add products from $this->products to $order as items, clearing existing order items.
	 *
	 * @param WC_Order $order Order to which the products should be added.
	 * @param array    $prices Array of prices to use for created products. Leave empty for default prices.
	 */
	protected function add_products_to_order( $order, $prices = array() ) {
		// Remove previous items.
		foreach ( $order->get_items() as $item ) {
			$order->remove_item( $item->get_id() );
		}

		// Add new products.
		$prod_count = 0;
		foreach ( $this->products as $product ) {
			$item = new WC_Order_Item_Product();
			$item->set_props( array(
				'product'  => $product,
				'quantity' => 3,
				'subtotal' => $prices ? $prices[ $prod_count ] : wc_get_price_excluding_tax( $product, array( 'qty' => 3 ) ),
				'total'    => $prices ? $prices[ $prod_count ] : wc_get_price_excluding_tax( $product, array( 'qty' => 3 ) ),
			) );

			$item->save();
			$order->add_item( $item );

			$prod_count++;
		}
	}
}
