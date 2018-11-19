<?php

class WC_Gateway_B2Binpay_Test extends WP_UnitTestCase {
	/**
	 * Test that WC_Gateway_B2Binpay payment gateway has loaded.
	 */
	public function test_gateway_loaded() {
		$this->assertArrayHasKey( 'b2binpay', WC()->payment_gateways()->payment_gateways() );
	}
}
