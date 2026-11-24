<?php
/**
 * اجرای مستقل: php tests/run.php
 * این تست‌ها هیچ درخواست شبکه‌ای نمی‌فرستند.
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-woocommerce-catalog-sync-rules.php';

final class TestFailure extends RuntimeException {}

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new TestFailure( $message . ' | expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) );
	}
}

function assert_true( $actual, $message ) {
	assert_same( true, (bool) $actual, $message );
}

/**
 * یک درگاه حافظه‌ای برای سناریوهای کسب‌وکار. این مدل عمداً شبکه را از تصمیم‌های
 * ایجاد/ویرایش/بازیابی جدا می‌کند و از قواعد خالصی استفاده می‌کند که افزونه نیز مصرف می‌کند.
 */
final class FakeSyncScenario {
	public $remote = array();
	public $mapping = 0;
	public $ambiguous = false;
	public $post_count = 0;
	public $put_count = 0;
	public $image_upload_count = 0;
	public $auth_fails = false;
	public $lose_create_response = false;
	public $reconciliation_fails = false;
	private $next_id = 100;

	public function sync( $selected, $sku, $price, $image_signature ) {
		if ( ! $selected ) {
			return 'skipped';
		}
		if ( $this->auth_fails ) {
			return 'auth_error';
		}

		if ( $this->mapping > 0 && isset( $this->remote[ $sku ] ) && $this->remote[ $sku ]['id'] === $this->mapping ) {
			$this->put_count++;
			$this->apply_update( $sku, $price, $image_signature );
			return 'updated';
		}

		$matches = WC_Catalog_Sync_Rules::exact_sku_matches( $this->objects_for_sku( $sku ), $sku );
		if ( 1 === count( $matches ) ) {
			$this->mapping = (int) $matches[0]->id;
			$this->ambiguous = false;
			$this->put_count++;
			$this->apply_update( $sku, $price, $image_signature );
			return 'recovered';
		}
		if ( $this->ambiguous ) {
			return 'ambiguous';
		}

		$this->post_count++;
		$this->remote[ $sku ] = array(
			'id'        => $this->next_id++,
			'sku'       => $sku,
			'price'     => (string) $price,
			'image_sig' => '',
		);
		$this->apply_update( $sku, $price, $image_signature );

		if ( $this->lose_create_response ) {
			if ( $this->reconciliation_fails ) {
				$this->ambiguous = true;
				return 'ambiguous';
			}
			$matches = WC_Catalog_Sync_Rules::exact_sku_matches( $this->objects_for_sku( $sku ), $sku );
			if ( 1 === count( $matches ) ) {
				$this->mapping = (int) $matches[0]->id;
				return 'recovered';
			}
		}

		$this->mapping = (int) $this->remote[ $sku ]['id'];
		return 'created';
	}

	private function apply_update( $sku, $price, $image_signature ) {
		$this->remote[ $sku ]['price'] = (string) $price;
		if ( '' !== $image_signature && $this->remote[ $sku ]['image_sig'] !== $image_signature ) {
			$this->image_upload_count++;
			$this->remote[ $sku ]['image_sig'] = $image_signature;
		}
	}

	private function objects_for_sku( $sku ) {
		if ( ! isset( $this->remote[ $sku ] ) ) {
			return array();
		}
		return array( (object) $this->remote[ $sku ] );
	}
}

$tests = array();

$tests['category selection supports direct and child terms'] = function () {
	assert_true( WC_Catalog_Sync_Rules::product_is_selected( array( 10 ), array( 10 ) ), 'direct category must match' );
	assert_true( WC_Catalog_Sync_Rules::product_is_selected( array( 11 ), array( 10 ), array( 11 => array( 10 ) ) ), 'child category must match' );
	assert_same( false, WC_Catalog_Sync_Rules::product_is_selected( array( 99 ), array( 10 ), array( 99 => array( 98 ) ) ), 'outside category must not match' );
};

$tests['zero price is not treated as empty'] = function () {
	assert_same( '0', WC_Catalog_Sync_Rules::regular_price( '0', '25' ), 'zero regular price must be preserved' );
	assert_same( '25', WC_Catalog_Sync_Rules::regular_price( '', '25' ), 'empty regular price may fall back to current price' );
};

$tests['selected product creates once and outside product is skipped'] = function () {
	$sync = new FakeSyncScenario();
	assert_same( 'skipped', $sync->sync( false, 'BOOK-OUT', '10', 'img-a' ), 'outside product' );
	assert_same( 0, $sync->post_count, 'outside product must not POST' );
	assert_same( 'created', $sync->sync( true, 'BOOK-1', '10', 'img-a' ), 'selected product' );
	assert_same( 1, $sync->post_count, 'selected product creates once' );
};

$tests['price and image changes update the same remote product'] = function () {
	$sync = new FakeSyncScenario();
	$sync->sync( true, 'BOOK-2', '10', 'img-a' );
	$remote_id = $sync->mapping;
	$sync->sync( true, 'BOOK-2', '0', 'img-a' );
	assert_same( $remote_id, $sync->mapping, 'price update keeps mapping' );
	assert_same( 1, $sync->image_upload_count, 'price-only update must not upload image again' );
	$sync->sync( true, 'BOOK-2', '0', 'img-b' );
	assert_same( $remote_id, $sync->mapping, 'image update keeps mapping' );
	assert_same( 2, $sync->image_upload_count, 'changed image uploads once' );
	assert_same( 1, $sync->post_count, 'no second product is created' );
};

$tests['repeat event and lost create response are idempotent'] = function () {
	$sync = new FakeSyncScenario();
	$sync->lose_create_response = true;
	assert_same( 'recovered', $sync->sync( true, 'BOOK-3', '10', 'img-a' ), 'lost response must reconcile by SKU' );
	$sync->lose_create_response = false;
	$sync->sync( true, 'BOOK-3', '12', 'img-a' );
	assert_same( 1, $sync->post_count, 'repeat event must not create again' );
	assert_same( 1, count( $sync->remote ), 'only one remote product exists' );
};

$tests['uncertain create blocks blind retry'] = function () {
	$sync = new FakeSyncScenario();
	$sync->lose_create_response = true;
	$sync->reconciliation_fails = true;
	assert_same( 'ambiguous', $sync->sync( true, 'BOOK-4', '10', 'img-a' ), 'uncertain response must become visible state' );
	assert_same( 'ambiguous', $sync->sync( true, 'BOOK-4', '10', 'img-a' ), 'retry remains blocked' );
	assert_same( 1, $sync->post_count, 'blind second POST is forbidden' );
};

$tests['authentication failure preserves existing mapping'] = function () {
	$sync = new FakeSyncScenario();
	$sync->sync( true, 'BOOK-5', '10', 'img-a' );
	$remote_id = $sync->mapping;
	$sync->auth_fails = true;
	assert_same( 'auth_error', $sync->sync( true, 'BOOK-5', '20', 'img-b' ), 'auth error is reported' );
	assert_same( $remote_id, $sync->mapping, 'mapping must survive auth error' );
	assert_same( 1, $sync->post_count, 'auth error must not POST a duplicate' );
};

$passed = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		$passed++;
		echo "PASS: {$name}\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "FAIL: {$name}: {$error->getMessage()}\n" );
		exit( 1 );
	}
}

echo "OK: {$passed} tests passed; no network requests were made.\n";
