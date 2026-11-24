<?php
/**
 * Plugin Name: WooCommerce Catalog Sync
 * Description: همگام‌سازی یک‌طرفه محصولات منتخب از یک فروشگاه ووکامرس به فروشگاه ووکامرس دیگر.
 * Version:     1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-catalog-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-woocommerce-catalog-sync-rules.php';

// نام کلاس و کلیدهای taha_* عمداً برای سازگاری با نصب‌های قبلی حفظ شده‌اند.
class Taha_Pub_Sync_Engine {

	private static $instance = null;
	private $option_name       = 'taha_pub_sync_settings';
	private $log_option_name   = 'taha_pub_sync_logs';
	private $batch_option_name = 'taha_pub_sync_batch_state';
	private $plugin_version    = '1.0.0';

	/**
	 * لیست محصولاتی که در درخواست فعلی تغییر کرده‌اند
	 */
	private $dirty_products = array();

	/**
	 * کش موقت دسته‌بندی‌های سایت مقصد در طول اجرای اسکریپت
	 */
	private $remote_cats_cache = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 1. منوی مدیریت، پردازش فرم‌ها و درخواست‌های AJAX
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_manual_actions' ) );
		add_action( 'wp_ajax_taha_pub_ajax_action', array( $this, 'handle_ajax_requests' ) );
		add_action( 'wp_ajax_nopriv_taha_pub_bg_worker', array( $this, 'handle_bg_worker_request' ) );
		add_action( 'wp_ajax_taha_pub_bg_worker', array( $this, 'handle_bg_worker_request' ) );

		// 2. هوک‌های شکار تغییرات محصول
		add_action( 'woocommerce_update_product', array( $this, 'mark_product_for_sync' ), 99, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'mark_product_for_sync' ), 99, 1 );
		add_action( 'save_post_product', array( $this, 'trigger_product_sync_on_post_save' ), 99, 3 );
		add_action( 'set_object_terms', array( $this, 'trigger_product_sync_on_term_change' ), 99, 6 );

		// شکار تغییرات سریع قیمت، موجودی و سفارش‌ها
		add_action( 'woocommerce_product_set_stock', array( $this, 'trigger_sync_by_product_obj' ), 99, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'trigger_sync_by_product_obj' ), 99, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'mark_product_for_sync' ), 99, 1 );
		add_action( 'woocommerce_product_quick_edit_save', array( $this, 'trigger_sync_by_product_obj' ), 99, 1 );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'trigger_sync_by_product_obj' ), 99, 1 );
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'trigger_sync_on_order_stock_change' ), 99, 1 );
		add_action( 'woocommerce_restore_order_stock', array( $this, 'trigger_sync_on_order_stock_change' ), 99, 1 );

		// شکار تغییرات مستقیم متادیتا (شامل تصویر شاخص، گالری، وزن، قیمت و موجودی)
		add_action( 'updated_post_meta', array( $this, 'trigger_sync_on_meta_change' ), 99, 4 );
		add_action( 'added_post_meta', array( $this, 'trigger_sync_on_meta_change' ), 99, 4 );
		add_action( 'deleted_post_meta', array( $this, 'trigger_sync_on_meta_change' ), 99, 4 );

		// اجرای نهایی محصولات علامت‌گذاری‌شده در انتهای Request
		add_action( 'shutdown', array( $this, 'dispatch_dirty_products' ), 20 );

		// 3. هوک‌های ساخت یا ویرایش دسته‌بندی‌ها
		add_action( 'created_product_cat', array( $this, 'trigger_category_sync' ), 10, 2 );
		add_action( 'edited_product_cat', array( $this, 'trigger_category_sync' ), 10, 2 );

		// 4. حذف یا خروج محصول از دسته در مقصد اعمال نمی‌شود؛ همگام‌سازی فقط ایجاد/ویرایش است.

		// 5. پردازشگرهای پس‌زمینه (Action Scheduler / WP-Cron)
		add_action( 'taha_pub_async_sync_product', array( $this, 'process_product_sync_cron' ), 10, 2 );
		add_action( 'taha_pub_process_batch_cron', array( $this, 'process_next_batch_step' ) );

		// کرون‌جاب پایش مستمر (هر ۵ دقیقه)
		add_filter( 'cron_schedules', array( $this, 'register_custom_cron_interval' ) );
		add_action( 'init', array( $this, 'schedule_watchdog_cron' ) );
		add_action( 'taha_pub_watchdog_cron_event', array( $this, 'run_watchdog_recent_sync' ) );
	}

	/**
	 * خواندن وضعیت صف پس‌زمینه (مستقیم از دیتابیس بدون تداخل کش Redis)
	 */
	public function get_batch_state() {
		wp_cache_delete( $this->batch_option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$default = array(
			'status'       => 'idle', // idle | running | completed | stopped
			'queue'        => array(),
			'total'        => 0,
			'processed'    => 0,
			'success'      => 0,
			'failed'       => 0,
			'current_id'   => 0,
			'force_img'    => true,
			'last_message' => '',
			'updated_at'   => 0,
		);
		$saved = get_option( $this->batch_option_name, array() );
		return is_array( $saved ) ? wp_parse_args( $saved, $default ) : $default;
	}

	/**
	 * ذخیره وضعیت صف پس‌زمینه
	 */
	public function save_batch_state( $state ) {
		$state['updated_at'] = time();
		update_option( $this->batch_option_name, $state, false );
		wp_cache_delete( $this->batch_option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * ارسال درخواست غیرمسدودکننده (Fire-and-Forget) به خود سرور برای ادامه پردازش در پس‌زمینه حتی اگر کاربر مرورگر را ببندد
	 */
	public function dispatch_background_worker() {
		$url = add_query_arg( array(
			'action' => 'taha_pub_bg_worker',
			'token'  => md5( NONCE_SALT . 'taha_bg_worker_secret' ),
			'_t'     => microtime( true ),
		), admin_url( 'admin-ajax.php' ) );

		wp_remote_post( $url, array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => true,
			'cookies'   => array(),
		) );
	}

	/**
	 * اندپوینت اجرای خودکار گام بعدی در پس‌زمینه سرور
	 */
	public function handle_bg_worker_request() {
		$token    = isset( $_REQUEST['token'] ) ? sanitize_text_field( $_REQUEST['token'] ) : '';
		$expected = md5( NONCE_SALT . 'taha_bg_worker_secret' );

		if ( ! hash_equals( $expected, $token ) ) {
			wp_die( 'Forbidden', 403 );
		}

		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			@litespeed_finish_request();
		}

		$this->process_next_batch_step();
		wp_die();
	}

	/**
	 * اجرای یک گام از صف همگام‌سازی سراسری با قفل ضدتداخل (Mutex Lock)
	 */
	public function process_next_batch_step() {
		wp_cache_delete( '_taha_batch_worker_lock', 'options' );
		$lock = (int) get_option( '_taha_batch_worker_lock', 0 );

		// اگر کمتر از ۲۵ ثانیه پیش قفل شده، یعنی یک پروسه دیگر همین الان مشغول سینک است
		if ( $lock > 0 && ( time() - $lock ) < 25 ) {
			return false;
		}

		update_option( '_taha_batch_worker_lock', time(), false );
		wp_cache_delete( '_taha_batch_worker_lock', 'options' );

		$state = $this->get_batch_state();
		if ( 'running' !== $state['status'] ) {
			delete_option( '_taha_batch_worker_lock' );
			return false;
		}

		if ( empty( $state['queue'] ) ) {
			$state['status']       = 'completed';
			$state['current_id']   = 0;
			$state['last_message'] = sprintf( 'عملیات بروزرسانی به پایان رسید (%d موفق از %d محصول).', (int) $state['success'], (int) $state['total'] );
			$this->save_batch_state( $state );
			delete_option( '_taha_batch_worker_lock' );
			$this->add_log( $state['last_message'], 'SUCCESS' );
			return false;
		}

		// برداشتن اولین کتاب از صف
		$pid                 = (int) array_shift( $state['queue'] );
		$state['current_id'] = $pid;
		$this->save_batch_state( $state );

		$ok    = $this->process_product_sync( $pid, (bool) $state['force_img'] );
		$title = get_the_title( $pid );

		// خواندن مجدد وضعیت برای اطمینان از اینکه کاربر در این حین دکمه توقف را نزده باشد
		$latest_state = $this->get_batch_state();
		if ( 'stopped' === $latest_state['status'] ) {
			delete_option( '_taha_batch_worker_lock' );
			return false;
		}

		$state['processed'] = (int) $state['processed'] + 1;
		if ( $ok ) {
			$state['success']      = (int) $state['success'] + 1;
			$state['last_message'] = "محصول «{$title}» (#{$pid}) با موفقیت همگام‌سازی شد.";
		} else {
			$state['failed']       = (int) $state['failed'] + 1;
			$state['last_message'] = "خطا در همگام‌سازی محصول «{$title}» (#{$pid}).";
		}

		if ( empty( $state['queue'] ) ) {
			$state['status']       = 'completed';
			$state['current_id']   = 0;
			$state['last_message'] = sprintf( 'عملیات بروزرسانی با موفقیت به پایان رسید (%d موفق از %d محصول).', (int) $state['success'], (int) $state['total'] );
			$this->save_batch_state( $state );
			delete_option( '_taha_batch_worker_lock' );
			$this->add_log( $state['last_message'], 'SUCCESS' );
			return false;
		}

		$this->save_batch_state( $state );
		delete_option( '_taha_batch_worker_lock' );

		// زنجیره خودکار برای پردازش کتاب بعدی در پس‌زمینه سرور
		$this->dispatch_background_worker();
		return true;
	}

	public function get_settings() {
		$defaults = array(
			'target_url'        => '',
			'consumer_key'      => '',
			'consumer_secret'   => '',
			'wp_username'       => '',
			'wp_app_password'   => '',
			'image_mode'        => 'upload',
			'book_info_attrs'   => 'yes',
			'root_categories'   => '',
			'auto_generate_sku' => 'yes',
			'sync_child_cats'   => 'yes',
			'realtime_sync'     => 'yes',
			'weight_sync_mode'  => 'sync_unit_gram',
		);
		$saved = get_option( $this->option_name, array() );
		return wp_parse_args( $saved, $defaults );
	}

	public function add_log( $message, $type = 'INFO' ) {
		$logs = get_option( $this->log_option_name, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$entry = sprintf(
			'[%s] [%s] %s',
			current_time( 'Y-m-d H:i:s' ),
			strtoupper( $type ),
			$message
		);

		array_unshift( $logs, $entry );
		$logs = array_slice( $logs, 0, 250 );
		update_option( $this->log_option_name, $logs, false );
	}

	public function get_monitored_root_term_ids() {
		$settings  = $this->get_settings();
		$raw_items = explode( ',', $settings['root_categories'] );
		$root_ids  = array();

		foreach ( $raw_items as $item ) {
			$item = trim( $item );
			if ( empty( $item ) ) {
				continue;
			}

			if ( is_numeric( $item ) ) {
				$term = get_term_by( 'id', (int) $item, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$root_ids[] = (int) $term->term_id;
					continue;
				}
			}

			$term_by_slug = get_term_by( 'slug', sanitize_title( $item ), 'product_cat' );
			if ( ! $term_by_slug ) {
				$term_by_slug = get_term_by( 'slug', $item, 'product_cat' );
			}
			if ( $term_by_slug && ! is_wp_error( $term_by_slug ) ) {
				$root_ids[] = (int) $term_by_slug->term_id;
				continue;
			}

			$term_by_name = get_term_by( 'name', $item, 'product_cat' );
			if ( $term_by_name && ! is_wp_error( $term_by_name ) ) {
				$root_ids[] = (int) $term_by_name->term_id;
				continue;
			}
		}

		return array_unique( $root_ids );
	}

	public function is_term_in_monitored_tree( $term_id, $root_ids = null ) {
		if ( null === $root_ids ) {
			$root_ids = $this->get_monitored_root_term_ids();
		}
		if ( empty( $root_ids ) ) {
			return false;
		}

		$term_id   = (int) $term_id;
		$ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
		return WC_Catalog_Sync_Rules::product_is_selected(
			array( $term_id ),
			$root_ids,
			array( $term_id => is_array( $ancestors ) ? $ancestors : array() )
		);
	}

	public function should_sync_product( $product_id ) {
		$root_ids = $this->get_monitored_root_term_ids();
		if ( empty( $root_ids ) ) {
			return false;
		}

		$product_terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $product_terms ) || empty( $product_terms ) ) {
			return false;
		}

		$ancestors_by_term = array();
		foreach ( $product_terms as $term_id ) {
			$ancestors_by_term[ (int) $term_id ] = get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' );
		}

		return WC_Catalog_Sync_Rules::product_is_selected( $product_terms, $root_ids, $ancestors_by_term );
	}

	/**
	 * دریافت لیست تمام آیدی‌های محصولات مجاز برای همگام‌سازی
	 */
	public function get_all_monitored_product_ids() {
		$root_ids = $this->get_monitored_root_term_ids();
		if ( empty( $root_ids ) ) {
			return array();
		}

		return get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'tax_query'      => array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $root_ids,
					'include_children' => true,
				),
			),
		) );
	}

	public function mark_product_for_sync( $product_id, $force_img = false ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}
		if ( wp_is_post_revision( $product_id ) || wp_is_post_autosave( $product_id ) ) {
			return;
		}

		$post_type = get_post_type( $product_id );
		if ( 'product_variation' === $post_type ) {
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id > 0 ) {
				$product_id = (int) $parent_id;
				$post_type  = get_post_type( $product_id );
			}
		}

		if ( 'product' !== $post_type ) {
			return;
		}

		if ( $force_img ) {
			delete_post_meta( $product_id, '_taha_synced_img_hash' );
		}

		$this->dirty_products[ $product_id ] = $force_img;
	}

	/**
	 * اجرای سریع در انتهای درخواست بدون مسدود کردن مرورگر (اگر فست‌سی‌جی‌آی موجود باشد، ابتدا پاسخ کاربر را بسته و در پس‌زمینه سینک می‌کند)
	 */
	public function dispatch_dirty_products() {
		if ( empty( $this->dirty_products ) ) {
			return;
		}

		$settings    = $this->get_settings();
		$is_realtime = ( 'yes' === $settings['realtime_sync'] );

		// اگر بیش از ۲ محصول همزمان تغییر کرده، برای جلوگیری از کندی پنل همه را به صف پس‌زمینه بسپار
		if ( ! $is_realtime || count( $this->dirty_products ) > 2 ) {
			$delay = 1;
			foreach ( $this->dirty_products as $product_id => $force_img ) {
				if ( 'publish' === get_post_status( $product_id ) && $this->should_sync_product( $product_id ) ) {
					$this->enqueue_product_sync( $product_id, $delay, $force_img );
					$delay += 2;
				}
			}
			$this->dirty_products = array();
			return;
		}

		// بستن اتصال مرورگر کاربر در سرورهای LiteSpeed / PHP-FPM تا کاربر منتظر پاسخ API نماند!
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			@litespeed_finish_request();
		}

		foreach ( $this->dirty_products as $product_id => $force_img ) {
			wp_cache_delete( $product_id, 'posts' );
			wp_cache_delete( $product_id, 'post_meta' );

			if ( 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}
			if ( ! $this->should_sync_product( $product_id ) ) {
				continue;
			}

			$res = $this->process_product_sync( $product_id, (bool) $force_img );
			if ( ! $res ) {
				$this->enqueue_product_sync( $product_id, 5, (bool) $force_img );
			}
		}

		$this->dirty_products = array();
	}

	public function enqueue_product_sync( $product_id, $delay = 2, $force_img = false ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		$args = array(
			'product_id' => $product_id,
			'force_img'  => (bool) $force_img,
		);

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( 'taha_pub_async_sync_product', $args, 'taha-pub-sync' ) ) {
				as_schedule_single_action( time() + $delay, 'taha_pub_async_sync_product', $args, 'taha-pub-sync' );
			}
		} else {
			if ( ! wp_next_scheduled( 'taha_pub_async_sync_product', array( $product_id, (bool) $force_img ) ) ) {
				wp_schedule_single_event( time() + $delay, 'taha_pub_async_sync_product', array( $product_id, (bool) $force_img ) );
			}
		}
	}

	public function process_product_sync_cron( $product_id, $force_img = false ) {
		$this->process_product_sync( (int) $product_id, (bool) $force_img );
	}

	public function trigger_sync_by_product_obj( $product ) {
		if ( is_a( $product, 'WC_Product' ) ) {
			$this->mark_product_for_sync( $product->get_id() );
		} elseif ( is_numeric( $product ) ) {
			$this->mark_product_for_sync( (int) $product );
		}
	}

	public function trigger_sync_on_order_stock_change( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
				$pid = $item->get_product_id();
				if ( $pid > 0 ) {
					$this->mark_product_for_sync( $pid );
				}
			}
		}
	}

	public function trigger_sync_on_meta_change( $meta_id, $object_id, $meta_key, $_meta_value ) {
		$image_keys = array( '_thumbnail_id', '_product_image_gallery' );
		if ( in_array( $meta_key, $image_keys, true ) ) {
			$this->mark_product_for_sync( $object_id, true );
			return;
		}

		$watched_keys = array(
			'_price',
			'_regular_price',
			'_sale_price',
			'_stock',
			'_stock_status',
			'_manage_stock',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_product_attributes',
			'_product_gtin',
			'_meta_total_pages',
			'_kowsar_barcode',
		);

		if ( in_array( $meta_key, $watched_keys, true ) ) {
			$this->mark_product_for_sync( $object_id, false );
		}
	}

	public function trigger_product_sync_on_post_save( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( isset( $post->post_status ) && 'publish' === $post->post_status ) {
			$this->mark_product_for_sync( $post_id );
		}
	}

	public function trigger_product_sync_on_term_change( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( 'product_cat' === $taxonomy || 0 === strpos( (string) $taxonomy, 'pa_' ) ) {
			$this->mark_product_for_sync( $object_id );
		}
	}

	public function register_custom_cron_interval( $schedules ) {
		$schedules['taha_every_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'هر ۵ دقیقه (پایش همگام‌سازی کاتالوگ)',
		);
		return $schedules;
	}

	public function schedule_watchdog_cron() {
		if ( ! wp_next_scheduled( 'taha_pub_watchdog_cron_event' ) ) {
			wp_schedule_event( time() + 60, 'taha_every_five_minutes', 'taha_pub_watchdog_cron_event' );
		}
	}

	public function run_watchdog_recent_sync() {
		$root_ids = $this->get_monitored_root_term_ids();
		if ( empty( $root_ids ) ) {
			return;
		}

		// اگر صف همگام‌سازی سراسری در حالت running مانده ولی بیش از ۳۰ ثانیه متوقف شده، دوباره بیدارش کن
		$batch_state = $this->get_batch_state();
		if ( 'running' === $batch_state['status'] && ( time() - (int) $batch_state['updated_at'] ) > 30 ) {
			$this->dispatch_background_worker();
		}

		$recent_products = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 15,
			'fields'         => 'ids',
			'date_query'     => array(
				array(
					'column' => 'post_modified_gmt',
					'after'  => '45 minutes ago',
				),
			),
			'tax_query'      => array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $root_ids,
					'include_children' => true,
				),
			),
		) );

		if ( empty( $recent_products ) ) {
			return;
		}

		$synced_in_run = 0;
		foreach ( $recent_products as $pid ) {
			if ( $synced_in_run >= 4 ) {
				break; // جلوگیری از طولانی شدن اجرای کرون در هر نوبت
			}
			$modified_time = (int) get_post_modified_time( 'U', true, $pid );
			$last_sync_ts  = (int) get_post_meta( $pid, '_taha_last_sync_timestamp', true );

			if ( $modified_time > ( $last_sync_ts + 5 ) ) {
				$this->process_product_sync( $pid );
				$synced_in_run++;
			}
		}
	}

	/* =====================================================================
	 * بخش تصاویر: آپلود مستقیم باینری (بدون دانلود توسط سایت مقصد)
	 * ===================================================================== */

	/**
	 * آیا اطلاعات Application Password سایت مقصد وارد شده است؟
	 */
	public function has_wp_credentials( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->get_settings();
		}
		return ! empty( $settings['wp_username'] ) && ! empty( $settings['wp_app_password'] );
	}

	private function safe_error_message( $message ) {
		$message = (string) $message;
		$message = preg_replace( '#https?://[^\s<>]+#i', '[URL حذف شد]', $message );
		$message = preg_replace( '/\b(?:ck|cs)_[A-Za-z0-9_-]+/i', '[کلید حذف شد]', $message );
		$message = preg_replace( '/(consumer_(?:key|secret)=)[^&\s]+/i', '$1[حذف شد]', $message );
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [حذف شد]', $message );
		return wp_strip_all_tags( $message );
	}

	private function request_error( $code, $message, $status, $method, $operation ) {
		return new WP_Error(
			$code,
			sprintf(
				'عملیات %1$s %2$s ناموفق بود (کد پاسخ: %3$s): %4$s',
				strtoupper( (string) $method ),
				sanitize_text_field( (string) $operation ),
				$status > 0 ? (string) (int) $status : 'بدون پاسخ',
				$this->safe_error_message( $message )
			),
			array(
				'status'    => (int) $status,
				'method'    => strtoupper( (string) $method ),
				'operation' => sanitize_text_field( (string) $operation ),
			)
		);
	}

	private function get_error_status( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return 0;
		}
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * درخواست به WP REST API استاندارد سایت مقصد (wp/v2) با Application Password
	 * $args: headers, query, body, timeout
	 */
	public function wp_rest_request( $method, $route, $args = array() ) {
		$settings = $this->get_settings();
		if ( empty( $settings['target_url'] ) || ! $this->has_wp_credentials( $settings ) ) {
			return $this->request_error( 'wp_rest_not_configured', 'نشانی مقصد یا اطلاعات Application Password کامل نیست.', 0, $method, 'wp/v2/' . ltrim( $route, '/' ) );
		}
		$url      = untrailingslashit( $settings['target_url'] ) . '/wp-json/wp/v2/' . ltrim( $route, '/' );

		$headers = array(
			'Authorization' => 'Basic ' . base64_encode( $settings['wp_username'] . ':' . $settings['wp_app_password'] ),
			'Accept'        => 'application/json',
			'User-Agent' => 'WooCommerceCatalogSync/1.0.0',
		);
		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$headers = array_merge( $headers, $args['headers'] );
		}
		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}

		$req = array(
			'method'    => strtoupper( $method ),
			'timeout'   => isset( $args['timeout'] ) ? (int) $args['timeout'] : 30,
			'sslverify' => true,
			'headers'   => $headers,
		);
		if ( isset( $args['body'] ) ) {
			$req['body'] = $args['body'];
		}

		$response = wp_remote_request( $url, $req );
		if ( is_wp_error( $response ) ) {
			return $this->request_error( 'wp_rest_transport', $response->get_error_message(), 0, $method, 'wp/v2/' . ltrim( $route, '/' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body );

		if ( $code < 200 || $code >= 300 ) {
			$msg = ( is_object( $json ) && isset( $json->message ) ) ? $json->message : 'HTTP Error ' . $code;
			return $this->request_error( 'wp_rest_error', $msg, $code, $method, 'wp/v2/' . ltrim( $route, '/' ) );
		}

		if ( null === $json ) {
			return $this->request_error( 'wp_rest_not_json', 'پاسخ مقصد JSON معتبر نبود.', $code, $method, 'wp/v2/' . ltrim( $route, '/' ) );
		}

		return $json;
	}

	/**
	 * تبدیل ارقام فارسی/عربی به لاتین
	 */
	private function to_latin_digits( $str ) {
		return strtr( (string) $str, array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		) );
	}

	/**
	 * شابک کتاب: از فیلد GTIN ووکامرس، سپس بارکد کوثر
	 */
	public function get_book_isbn( $product_id, $product = null ) {
		$candidates = array();

		if ( $product && method_exists( $product, 'get_global_unique_id' ) ) {
			$candidates[] = (string) $product->get_global_unique_id();
		}
		$candidates[] = (string) get_post_meta( $product_id, '_product_gtin', true );
		$candidates[] = (string) get_post_meta( $product_id, '_isbn', true );

		$barcode = get_post_meta( $product_id, '_kowsar_barcode', true );
		if ( is_array( $barcode ) ) {
			foreach ( $barcode as $b ) {
				$candidates[] = (string) $b;
			}
		} elseif ( is_string( $barcode ) && '' !== $barcode ) {
			$maybe = maybe_unserialize( $barcode );
			if ( is_array( $maybe ) ) {
				foreach ( $maybe as $b ) {
					$candidates[] = (string) $b;
				}
			} else {
				$candidates[] = $barcode;
			}
		}

		foreach ( $candidates as $c ) {
			$digits = preg_replace( '/[^0-9Xx]/', '', $this->to_latin_digits( $c ) );
			if ( 13 === strlen( $digits ) || 10 === strlen( $digits ) ) {
				return strtoupper( $digits );
			}
		}

		return '';
	}

	/**
	 * تعداد صفحات کتاب
	 */
	public function get_book_pages( $product_id ) {
		foreach ( array( '_meta_total_pages', '_total_pages', '_pages' ) as $key ) {
			$raw = $this->to_latin_digits( (string) get_post_meta( $product_id, $key, true ) );
			$num = (int) preg_replace( '/[^0-9]/', '', $raw );
			if ( $num > 0 ) {
				return (string) $num;
			}
		}
		return '';
	}

	/**
	 * پیدا کردن فایل فیزیکی سالم یک پیوست در سرور مبدا
	 */
	private function locate_source_image_file( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$file_path = get_attached_file( $attachment_id );
		if ( $file_path && file_exists( $file_path ) && filesize( $file_path ) > 200 ) {
			return $file_path;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( $file_path && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$base_dir       = trailingslashit( dirname( $file_path ) );
			$preferred_keys = array( 'large', 'woocommerce_single', 'medium_large', 'medium', 'woocommerce_thumbnail', 'thumbnail' );

			foreach ( $preferred_keys as $s_key ) {
				if ( ! empty( $meta['sizes'][ $s_key ]['file'] ) ) {
					$candidate = $base_dir . $meta['sizes'][ $s_key ]['file'];
					if ( file_exists( $candidate ) && filesize( $candidate ) > 200 ) {
						return $candidate;
					}
				}
			}
			foreach ( $meta['sizes'] as $s_info ) {
				if ( ! empty( $s_info['file'] ) ) {
					$candidate = $base_dir . $s_info['file'];
					if ( file_exists( $candidate ) && filesize( $candidate ) > 200 ) {
						return $candidate;
					}
				}
			}
		}

		return '';
	}

	/**
	 * توصیف یک تصویر مبدا: مسیر فایل، نوع، نام استاندارد انگلیسی و امضای تغییرات
	 */
	public function describe_source_image( $attachment_id, $product_id ) {
		$attachment_id = (int) $attachment_id;
		$path          = $this->locate_source_image_file( $attachment_id );
		if ( '' === $path ) {
			return false;
		}

		$info = @getimagesize( $path );
		if ( ! $info || $info[0] <= 2 || $info[1] <= 2 ) {
			return false;
		}

		$mime    = isset( $info['mime'] ) ? strtolower( $info['mime'] ) : '';
		$ext_map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		if ( ! isset( $ext_map[ $mime ] ) ) {
			return false;
		}

		$sig = $attachment_id . '-' . substr( md5( $path . '|' . (int) @filemtime( $path ) . '|' . (int) @filesize( $path ) ), 0, 10 );

		return array(
			'attachment_id' => $attachment_id,
			'path'          => $path,
			'mime'          => $mime,
			'width'         => (int) $info[0],
			'height'        => (int) $info[1],
			'filename'      => sprintf( 'taha-book-%d-%d.%s', (int) $product_id, $attachment_id, $ext_map[ $mime ] ),
			'sig'           => $sig,
		);
	}

	/**
	 * فقط برای حالت پشتیبان (بدون Application Password): کپی فایل در پوشه عمومی کش و برگرداندن URL
	 */
	private function build_public_cache_copy( $img, $product_id ) {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
			return '';
		}

		$cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'taha-sync-cache';
		if ( ! file_exists( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		$ext      = pathinfo( $img['filename'], PATHINFO_EXTENSION );
		$name     = sprintf( 'taha-book-%d-att-%d-%s.%s', (int) $product_id, (int) $img['attachment_id'], substr( md5( $img['sig'] ), 0, 10 ), $ext );
		$dest     = trailingslashit( $cache_dir ) . $name;
		$dest_url = trailingslashit( $upload_dir['baseurl'] ) . 'taha-sync-cache/' . $name;

		if ( ! file_exists( $dest ) || filesize( $dest ) < 200 ) {
			@copy( $img['path'], $dest );
		}

		return ( file_exists( $dest ) && filesize( $dest ) > 200 ) ? $dest_url : '';
	}

	/**
	 * سلامت یک فایل رسانه در سایت مقصد (ابعاد واقعی > ۲ پیکسل و عدم وجود خطای 404)
	 */
	public function remote_media_is_healthy( $media_id, $verify_file = false ) {
		$media_id = (int) $media_id;
		if ( $media_id <= 0 ) {
			return false;
		}

		$res = $this->wp_rest_request( 'GET', 'media/' . $media_id, array(
			'query'   => array( '_fields' => 'id,source_url,media_details' ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $res ) || ! is_object( $res ) ) {
			return false;
		}

		$w = ( isset( $res->media_details ) && is_object( $res->media_details ) && isset( $res->media_details->width ) ) ? (int) $res->media_details->width : 0;
		$h = ( isset( $res->media_details ) && is_object( $res->media_details ) && isset( $res->media_details->height ) ) ? (int) $res->media_details->height : 0;
		if ( $w <= 2 || $h <= 2 ) {
			return false;
		}

		if ( $verify_file && ! empty( $res->source_url ) ) {
			$head = wp_remote_head( $res->source_url, array(
				'timeout'   => 8,
				'sslverify' => true,
				'headers'   => array( 'User-Agent' => 'WooCommerceCatalogSync/' . $this->plugin_version ),
			) );
			if ( ! is_wp_error( $head ) ) {
				$code = (int) wp_remote_retrieve_response_code( $head );
				// فقط 404/410 قطعی را خراب حساب می‌کنیم؛ چالش CDN نباید باعث آپلود مجدد شود
				if ( 404 === $code || 410 === $code ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * آپلود مستقیم باینری تصویر به کتابخانه رسانه سایت مقصد؛ خروجی: شناسه رسانه یا WP_Error
	 */
	public function upload_image_to_remote( $img ) {
		$bytes = @file_get_contents( $img['path'] );
		if ( false === $bytes || strlen( $bytes ) < 200 ) {
			return new WP_Error( 'img_read', 'خواندن فایل تصویر از سرور مبدا ناموفق بود.' );
		}

		$res = $this->wp_rest_request( 'POST', 'media', array(
			'headers' => array(
				'Content-Type'        => $img['mime'],
				'Content-Disposition' => 'attachment; filename="' . $img['filename'] . '"',
			),
			'body'    => $bytes,
			'timeout' => 60,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! is_object( $res ) || empty( $res->id ) ) {
			return new WP_Error( 'img_upload', 'پاسخ آپلود فاقد شناسه رسانه بود.' );
		}

		$new_id = (int) $res->id;
		$w      = ( isset( $res->media_details ) && is_object( $res->media_details ) && isset( $res->media_details->width ) ) ? (int) $res->media_details->width : 0;
		$h      = ( isset( $res->media_details ) && is_object( $res->media_details ) && isset( $res->media_details->height ) ) ? (int) $res->media_details->height : 0;
		if ( $w <= 2 || $h <= 2 ) {
			$this->delete_remote_media( $new_id );
			return new WP_Error( 'img_invalid', 'فایل آپلودشده در مقصد ابعاد معتبر نداشت (' . $w . 'x' . $h . ').' );
		}

		return $new_id;
	}

	public function delete_remote_media( $media_id ) {
		$media_id = (int) $media_id;
		if ( $media_id <= 0 ) {
			return false;
		}
		$res = $this->wp_rest_request( 'DELETE', 'media/' . $media_id, array(
			'query'   => array( 'force' => 'true' ),
			'timeout' => 15,
		) );
		return ! is_wp_error( $res );
	}

	/**
	 * حذف تصاویر خراب/قدیمیِ ساخته‌شده توسط همین افزونه از کتابخانه مقصد (حداکثر ۶ مورد در هر بار)
	 */
	private function cleanup_remote_images( $candidate_ids, $ours_ids ) {
		$deleted = 0;
		foreach ( array_slice( array_unique( array_map( 'intval', $candidate_ids ) ), 0, 6 ) as $mid ) {
			if ( $mid <= 0 ) {
				continue;
			}
			if ( in_array( $mid, $ours_ids, true ) || ! $this->remote_media_is_healthy( $mid ) ) {
				if ( $this->delete_remote_media( $mid ) ) {
					$deleted++;
				}
			}
		}
		return $deleted;
	}

	/**
	 * بررسی سلامت تصاویر محصول مقصد در حالت پشتیبان (بدون Application Password)
	 */
	private function remote_images_look_healthy_by_url( $remote_product_obj ) {
		if ( ! $remote_product_obj || empty( $remote_product_obj->images ) || ! is_array( $remote_product_obj->images ) ) {
			return false;
		}
		$src = isset( $remote_product_obj->images[0]->src ) ? (string) $remote_product_obj->images[0]->src : '';
		if ( '' === $src ) {
			return false;
		}

		$response = wp_remote_get( $src, array(
			'timeout'   => 8,
			'sslverify' => true,
			'headers'   => array( 'User-Agent' => 'WooCommerceCatalogSync/' . $this->plugin_version ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$size = @getimagesizefromstring( wp_remote_retrieve_body( $response ) );
		return ( $size && $size[0] > 2 && $size[1] > 2 );
	}

	/**
	 * تصمیم‌گیری و آماده‌سازی تصاویر برای ارسال به مقصد.
	 * خروجی: payload (آرایه یا null = دست نزن)، sig، errors، map، stale و ours
	 */
	private function resolve_remote_images( $product, $product_id, $source_images, $remote_product_obj, $force ) {
		$settings   = $this->get_settings();
		$use_upload = ( 'upload' === $settings['image_mode'] ) && $this->has_wp_credentials( $settings );
		$alt        = wp_strip_all_tags( $product->get_name() );

		$out = array(
			'payload'     => null,
			'sig'         => md5( wp_json_encode( wp_list_pluck( $source_images, 'sig' ) ) ),
			'errors'      => 0,
			'map'         => null,
			'stale'       => array(),
			'ours'        => array(),
			'mode'        => $use_upload ? 'upload' : 'url',
		);

		$current_ids = array();
		if ( $remote_product_obj && ! empty( $remote_product_obj->images ) && is_array( $remote_product_obj->images ) ) {
			foreach ( $remote_product_obj->images as $ri ) {
				if ( ! empty( $ri->id ) ) {
					$current_ids[] = (int) $ri->id;
				}
			}
		}

		// ---------- حالت اصلی: آپلود مستقیم باینری ----------
		if ( $use_upload ) {
			$old_map = get_post_meta( $product_id, '_taha_remote_media_map', true );
			if ( ! is_array( $old_map ) ) {
				$old_map = array();
			}

			$new_map     = array();
			$desired_ids = array();

			foreach ( $source_images as $img ) {
				$mid = isset( $old_map[ $img['sig'] ] ) ? (int) $old_map[ $img['sig'] ] : 0;

				// اگر قبلاً آپلود شده و همین الان روی محصول مقصد نشسته و اجبار نداریم، بدون درخواست اضافه معتبر فرض می‌شود
				$trusted = ( $mid > 0 && ! $force && $remote_product_obj && in_array( $mid, $current_ids, true ) );
				if ( $mid > 0 && ! $trusted && ! $this->remote_media_is_healthy( $mid, (bool) $force ) ) {
					$mid = 0;
				}

				if ( $mid <= 0 ) {
					$up = $this->upload_image_to_remote( $img );
					if ( is_wp_error( $up ) ) {
						$out['errors']++;
						$this->add_log( "آپلود تصویر برای محصول #{$product_id} ناموفق بود ({$img['filename']}): " . $up->get_error_message(), 'ERROR' );
						continue;
					}
					$mid = (int) $up;
				}

				$desired_ids[]            = $mid;
				$new_map[ $img['sig'] ] = $mid;
			}

			// نقشه رسانه‌ها فوراً ذخیره می‌شود تا در تلاش مجدد، فایل‌ها دوباره آپلود نشوند
			if ( ! empty( $new_map ) ) {
				update_post_meta( $product_id, '_taha_remote_media_map', $new_map );
			}
			$out['map']  = $new_map;
			$out['ours'] = array_values( array_map( 'intval', $old_map ) );

			if ( 0 === $out['errors'] && ! empty( $desired_ids ) ) {
				if ( $desired_ids !== $current_ids ) {
					$payload_imgs = array();
					foreach ( $desired_ids as $did ) {
						$payload_imgs[] = array(
							'id'  => (int) $did,
							'alt' => $alt,
						);
					}
					$out['payload'] = $payload_imgs;
				}
				$out['stale'] = array_values( array_diff( array_unique( array_merge( $current_ids, $out['ours'] ) ), $desired_ids ) );
			}

			return $out;
		}

		// ---------- حالت پشتیبان: ارسال URL ----------
		$url_images = array();
		foreach ( $source_images as $img ) {
			$url = $this->build_public_cache_copy( $img, $product_id );
			if ( '' !== $url ) {
				$url_images[] = array(
					'src' => $url,
					'alt' => $alt,
				);
			}
		}
		if ( empty( $url_images ) ) {
			$out['errors']++;
			return $out;
		}

		$last_sig = get_post_meta( $product_id, '_taha_synced_img_hash', true );
		$healthy  = $remote_product_obj ? $this->remote_images_look_healthy_by_url( $remote_product_obj ) : false;

		if ( $force || ! $remote_product_obj || $out['sig'] !== $last_sig || ! $healthy ) {
			$out['payload'] = $url_images;
		}

		return $out;
	}

	/**
	 * تست کامل اتصال Application Password + آپلود و حذف یک تصویر آزمایشی
	 */
	public function run_upload_selftest() {
		$settings = $this->get_settings();
		if ( ! $this->has_wp_credentials( $settings ) ) {
			return array( false, 'نام کاربری و Application Password سایت مقصد در تنظیمات وارد نشده است.' );
		}

		$me = $this->wp_rest_request( 'GET', 'users/me', array(
			'query'   => array( 'context' => 'edit' ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $me ) ) {
			return array( false, 'احراز هویت در سایت مقصد ناموفق بود: ' . $me->get_error_message() . ' — اگر کد 401 است، Application Password یا هدر Authorization (در .htaccess سایت مقصد) را بررسی کنید.' );
		}

		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return array( false, 'افزونه GD در PHP سرور مبدا فعال نیست و تصویر آزمایشی ساخته نشد.' );
		}

		// تصویر آزمایشی ۱۲۰×۱۲۰ با نویز تصادفی تا حجم فایل از حد کمینه بیشتر باشد
		$im = imagecreatetruecolor( 120, 120 );
		for ( $x = 0; $x < 120; $x++ ) {
			for ( $y = 0; $y < 120; $y++ ) {
				imagesetpixel( $im, $x, $y, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
			}
		}
		ob_start();
		imagepng( $im );
		$bytes = ob_get_clean();
		imagedestroy( $im );

		$tmp = wp_tempnam( 'taha-selftest.png' );
		file_put_contents( $tmp, $bytes );

		$up = $this->upload_image_to_remote( array(
			'path'     => $tmp,
			'mime'     => 'image/png',
			'filename' => 'taha-selftest-' . time() . '.png',
		) );
		@unlink( $tmp );

		if ( is_wp_error( $up ) ) {
			return array( false, 'آپلود تصویر آزمایشی ناموفق بود: ' . $up->get_error_message() );
		}

		$this->delete_remote_media( (int) $up );
		$name = isset( $me->name ) ? $me->name : '';
		return array( true, "اتصال و آپلود تصویر در سایت مقصد با موفقیت تست شد (کاربر: {$name}). تصویر آزمایشی حذف گردید." );
	}

	public function trigger_category_sync( $term_id, $tt_id ) {
		$settings = $this->get_settings();
		if ( 'yes' !== $settings['sync_child_cats'] ) {
			return;
		}

		if ( $this->is_term_in_monitored_tree( $term_id ) ) {
			$remote_cat_id = $this->ensure_remote_category_synced( $term_id );
			if ( $remote_cat_id ) {
				$this->add_log( "دسته‌بندی #{$term_id} در سایت مقصد همگام‌سازی شد (شناسه مقصد: {$remote_cat_id}).", 'CAT_SYNC' );
			}
		}
	}

	private function reconcile_create_failure( $product_id, $sku, $create_error ) {
		$search_res = $this->api_request( 'GET', 'products', array(
			'sku'    => $sku,
			'status' => 'any',
		), 15 );

		$exact_matches = is_wp_error( $search_res ) ? array() : WC_Catalog_Sync_Rules::exact_sku_matches( $search_res, $sku );

		if ( 1 === count( $exact_matches ) ) {
			update_post_meta( $product_id, '_taha_remote_product_id', (int) $exact_matches[0]->id );
			delete_post_meta( $product_id, '_taha_sync_ambiguous_state' );
			$this->add_log( "پاسخ ایجاد محصول #{$product_id} نرسید، اما محصول با SKU در مقصد پیدا و نگاشت آن بازیابی شد.", 'SUCCESS' );
			return $exact_matches[0];
		}

		$create_status = $this->get_error_status( $create_error );
		$safe_rejection = WC_Catalog_Sync_Rules::safe_create_rejection( $create_status );
		if ( $safe_rejection && ( is_wp_error( $search_res ) || 0 === count( $exact_matches ) ) ) {
			delete_post_meta( $product_id, '_taha_sync_ambiguous_state' );
			return $create_error;
		}

		$reason = is_wp_error( $search_res ) ? 'reconciliation_failed' : ( count( $exact_matches ) > 1 ? 'multiple_matches' : 'lost_create_response' );
		update_post_meta( $product_id, '_taha_sync_ambiguous_state', array( 'sku' => $sku, 'reason' => $reason, 'time' => time() ) );

		return new WP_Error(
			'taha_create_ambiguous',
			"نتیجه ایجاد محصول #{$product_id} نامشخص است. برای جلوگیری از محصول تکراری، ایجاد دوباره متوقف شد؛ SKU را در مقصد بررسی کنید.",
			array( 'status' => 0, 'operation' => 'POST wc/v3/products' )
		);
	}

	public function get_all_remote_categories_map() {
		if ( null !== $this->remote_cats_cache ) {
			return $this->remote_cats_cache;
		}

		$map  = array();
		$page = 1;

		while ( $page <= 5 ) {
			$res = $this->api_request( 'GET', 'products/categories', array(
				'per_page' => 100,
				'page'     => $page,
			), 20 );

			if ( is_wp_error( $res ) || ! is_array( $res ) || empty( $res ) ) {
				break;
			}

			foreach ( $res as $cat ) {
				if ( isset( $cat->id ) ) {
					$map[ (int) $cat->id ] = array(
						'id'     => (int) $cat->id,
						'name'   => isset( $cat->name ) ? trim( $cat->name ) : '',
						'slug'   => isset( $cat->slug ) ? trim( $cat->slug ) : '',
						'parent' => isset( $cat->parent ) ? (int) $cat->parent : 0,
					);
				}
			}

			if ( count( $res ) < 100 ) {
				break;
			}
			$page++;
		}

		$this->remote_cats_cache = $map;
		return $map;
	}

	public function get_managed_remote_category_ids() {
		$managed_remote_ids = array();
		$root_ids           = $this->get_monitored_root_term_ids();

		foreach ( $root_ids as $root_id ) {
			$r_id = (int) get_term_meta( $root_id, '_taha_remote_cat_id', true );
			if ( $r_id > 0 ) {
				$managed_remote_ids[] = $r_id;
			}

			$children = get_term_children( $root_id, 'product_cat' );
			if ( ! is_wp_error( $children ) && is_array( $children ) ) {
				foreach ( $children as $child_id ) {
					$c_remote_id = (int) get_term_meta( $child_id, '_taha_remote_cat_id', true );
					if ( $c_remote_id > 0 ) {
						$managed_remote_ids[] = $c_remote_id;
					}
				}
			}
		}

		$remote_map = $this->get_all_remote_categories_map();
		if ( ! empty( $remote_map ) && ! empty( $managed_remote_ids ) ) {
			foreach ( $remote_map as $rcat_id => $rcat_info ) {
				$curr_parent = $rcat_info['parent'];
				$depth       = 0;
				while ( $curr_parent > 0 && $depth < 10 ) {
					if ( in_array( $curr_parent, $managed_remote_ids, true ) ) {
						$managed_remote_ids[] = (int) $rcat_id;
						break;
					}
					$curr_parent = isset( $remote_map[ $curr_parent ] ) ? $remote_map[ $curr_parent ]['parent'] : 0;
					$depth++;
				}
			}
		}

		return array_unique( $managed_remote_ids );
	}

	public function ensure_remote_category_synced( $local_term_id ) {
		$local_term_id = (int) $local_term_id;
		$term          = get_term( $local_term_id, 'product_cat' );

		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		$root_ids = $this->get_monitored_root_term_ids();

		$remote_parent_id = 0;
		if ( $term->parent > 0 ) {
			if ( $this->is_term_in_monitored_tree( $term->parent, $root_ids ) ) {
				$parent_synced_id = $this->ensure_remote_category_synced( $term->parent );
				if ( $parent_synced_id ) {
					$remote_parent_id = (int) $parent_synced_id;
				}
			}
		}

		$cached_remote_id = (int) get_term_meta( $local_term_id, '_taha_remote_cat_id', true );
		if ( $cached_remote_id > 0 ) {
			$remote_map = $this->get_all_remote_categories_map();
			if ( isset( $remote_map[ $cached_remote_id ] ) ) {
				$cached_matches_term = ( $remote_map[ $cached_remote_id ]['slug'] === $term->slug || $remote_map[ $cached_remote_id ]['name'] === $term->name );
				if ( ! $cached_matches_term ) {
					$this->add_log( "نگاشت ذخیره‌شده دسته #{$local_term_id} با محتوای مقصد سازگار نیست؛ قبل از هر ویرایش، دسته با slug/نام جست‌وجو می‌شود.", 'ERROR' );
				} elseif ( $remote_map[ $cached_remote_id ]['name'] !== $term->name || $remote_map[ $cached_remote_id ]['parent'] !== $remote_parent_id ) {
					$update_res = $this->api_request( 'PUT', 'products/categories/' . $cached_remote_id, array(
						'name'   => $term->name,
						'parent' => $remote_parent_id,
					), 15 );
					if ( is_wp_error( $update_res ) ) {
						$this->add_log( "ویرایش دسته مقصد برای دسته مبدأ #{$local_term_id} ناموفق بود: " . $update_res->get_error_message(), 'ERROR' );
						return false;
					}
					$this->remote_cats_cache = null;
					return $cached_remote_id;
				} else {
					return $cached_remote_id;
				}
			}
		}

		$search_res = $this->api_request( 'GET', 'products/categories', array(
			'slug' => $term->slug,
		), 15 );
		if ( is_wp_error( $search_res ) ) {
			$this->add_log( "بررسی دسته مبدأ #{$local_term_id} در مقصد ناموفق بود؛ دسته جدید ساخته نشد: " . $search_res->get_error_message(), 'ERROR' );
			return false;
		}

		if ( is_array( $search_res ) && ! empty( $search_res[0]->id ) ) {
			$found_id = (int) $search_res[0]->id;
			update_term_meta( $local_term_id, '_taha_remote_cat_id', $found_id );
			return $found_id;
		}

		$search_by_name = $this->api_request( 'GET', 'products/categories', array(
			'search' => $term->name,
		), 15 );
		if ( is_wp_error( $search_by_name ) ) {
			$this->add_log( "جست‌وجوی نام دسته مبدأ #{$local_term_id} در مقصد ناموفق بود؛ دسته جدید ساخته نشد: " . $search_by_name->get_error_message(), 'ERROR' );
			return false;
		}

		if ( is_array( $search_by_name ) ) {
			foreach ( $search_by_name as $remote_cat ) {
				if ( trim( $remote_cat->name ) === trim( $term->name ) ) {
					$found_id = (int) $remote_cat->id;
					update_term_meta( $local_term_id, '_taha_remote_cat_id', $found_id );
					return $found_id;
				}
			}
		}

		$create_payload = array(
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => $remote_parent_id,
		);

		$create_res = $this->api_request( 'POST', 'products/categories', $create_payload, 20 );
		if ( ! is_wp_error( $create_res ) && isset( $create_res->id ) ) {
			$new_id                  = (int) $create_res->id;
			$this->remote_cats_cache = null;
			update_term_meta( $local_term_id, '_taha_remote_cat_id', $new_id );
			return $new_id;
		}

		if ( is_wp_error( $create_res ) ) {
			// ممکن است دسته ساخته شده باشد ولی پاسخ نرسیده باشد؛ فقط جست‌وجو می‌کنیم و POST دوم نمی‌فرستیم.
			$recover_res = $this->api_request( 'GET', 'products/categories', array( 'slug' => $term->slug ), 15 );
			if ( ! is_wp_error( $recover_res ) && is_array( $recover_res ) && 1 === count( $recover_res ) && ! empty( $recover_res[0]->id ) ) {
				$new_id                  = (int) $recover_res[0]->id;
				$this->remote_cats_cache = null;
				update_term_meta( $local_term_id, '_taha_remote_cat_id', $new_id );
				return $new_id;
			}
			$this->add_log( "نتیجه ایجاد دسته مبدأ #{$local_term_id} قطعی نیست؛ ایجاد دوباره انجام نشد: " . $create_res->get_error_message(), 'ERROR' );
		}

		return false;
	}

	/**
	 * پردازشگر اصلی سینک یک کتاب با سایت مقصد
	 */
	public function process_product_sync( $product_id, $force_image_sync = false ) {
		$product_id = (int) $product_id;
		$lock_name  = '_taha_product_sync_lock_' . $product_id;
		$lock_time  = (int) get_option( $lock_name, 0 );

		if ( ! add_option( $lock_name, time(), '', false ) ) {
			if ( $lock_time > 0 && ( time() - $lock_time ) > 180 ) {
				delete_option( $lock_name );
				if ( ! add_option( $lock_name, time(), '', false ) ) {
					$this->add_log( "همگام‌سازی هم‌زمان محصول #{$product_id} متوقف شد؛ یک پردازش دیگر فعال است.", 'INFO' );
					return false;
				}
			} else {
				$this->add_log( "همگام‌سازی هم‌زمان محصول #{$product_id} متوقف شد؛ یک پردازش دیگر فعال است.", 'INFO' );
				return false;
			}
		}

		try {
			return $this->process_product_sync_unlocked( $product_id, $force_image_sync );
		} finally {
			delete_option( $lock_name );
		}
	}

	private function process_product_sync_unlocked( $product_id, $force_image_sync = false ) {
		$product_id = (int) $product_id;

		wp_cache_delete( $product_id, 'posts' );
		wp_cache_delete( $product_id, 'post_meta' );
		clean_post_cache( $product_id );
		wc_delete_product_transients( $product_id );

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$this->add_log( "محصول #{$product_id} یافت نشد.", 'ERROR' );
			return false;
		}

		if ( ! $product->is_type( 'simple' ) ) {
			$this->add_log( "محصول #{$product_id} همگام نشد: نسخه فعلی فقط محصول ساده را پشتیبانی می‌کند.", 'ERROR' );
			return false;
		}

		if ( ! $this->should_sync_product( $product_id ) ) {
			return false;
		}

		$settings = $this->get_settings();

		// 1. مدیریت SKU
		$sku = trim( (string) $product->get_sku() );
		if ( empty( $sku ) ) {
			if ( 'yes' === $settings['auto_generate_sku'] ) {
				$sku = 'TAHA-' . $product_id;
				update_post_meta( $product_id, '_sku', $sku );
				$this->add_log( "کد محصول خودکار {$sku} برای کتاب #{$product_id} ثبت شد.", 'INFO' );
			} else {
				$this->add_log( "خطا: کتاب #{$product_id} ({$product->get_name()}) فاقد کد محصول (SKU) است.", 'ERROR' );
				return false;
			}
		}

		// 2. آماده‌سازی تصاویر مبدا (مسیر فایل واقعی + امضای تغییرات)
		$source_images = array();
		$featured_id   = (int) get_post_meta( $product_id, '_thumbnail_id', true );
		if ( $featured_id <= 0 ) {
			$featured_id = (int) $product->get_image_id();
		}

		$wanted_ids = array();
		if ( $featured_id > 0 ) {
			$wanted_ids[] = $featured_id;
		}
		$raw_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
		$gallery_ids = ! empty( $raw_gallery ) ? array_filter( array_map( 'intval', explode( ',', (string) $raw_gallery ) ) ) : $product->get_gallery_image_ids();
		if ( ! empty( $gallery_ids ) && is_array( $gallery_ids ) ) {
			foreach ( $gallery_ids as $g_id ) {
				$g_id = (int) $g_id;
				if ( $g_id > 0 && ! in_array( $g_id, $wanted_ids, true ) ) {
					$wanted_ids[] = $g_id;
				}
			}
		}

		$missing_images = 0;
		foreach ( $wanted_ids as $att_id ) {
			$desc = $this->describe_source_image( $att_id, $product_id );
			if ( $desc ) {
				$source_images[] = $desc;
			} else {
				$missing_images++;
			}
		}
		if ( $missing_images > 0 ) {
			$this->add_log( "هشدار: {$missing_images} تصویر محصول #{$product_id} در سرور مبدا یافت نشد یا خراب است و ارسال نشد.", 'ERROR' );
		}

		// 3. استخراج ویژگی‌ها (Attributes)
		$attributes_data = array();
		$product_attrs   = $product->get_attributes();
		if ( ! empty( $product_attrs ) && is_array( $product_attrs ) ) {
			foreach ( $product_attrs as $attribute ) {
				if ( ! is_a( $attribute, 'WC_Product_Attribute' ) ) {
					continue;
				}

				$attr_label = wc_attribute_label( $attribute->get_name(), $product );
				$options    = array();

				if ( $attribute->is_taxonomy() ) {
					$terms = wp_get_post_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) );
					if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
						$options = array_map( 'strval', $terms );
					}
				} else {
					$raw_options = $attribute->get_options();
					if ( is_array( $raw_options ) ) {
						$options = array_map( 'strval', $raw_options );
					}
				}

				if ( ! empty( $options ) ) {
					$attributes_data[] = array(
						'name'      => $attr_label,
						'visible'   => (bool) $attribute->get_visible(),
						'variation' => false,
						'options'   => $options,
					);
				}
			}
		}

		// 4. بررسی نگاشت قبلی و سپس بازیابی با SKU؛ خطای اتصال هرگز به ایجاد کورکورانه منجر نمی‌شود.
		$remote_product_id  = (int) get_post_meta( $product_id, '_taha_remote_product_id', true );
		$remote_product_obj = null;
		$ambiguous_state    = get_post_meta( $product_id, '_taha_sync_ambiguous_state', true );

		if ( $remote_product_id > 0 ) {
			$check_res = $this->api_request( 'GET', 'products/' . $remote_product_id, array(), 15 );
			if ( is_wp_error( $check_res ) ) {
				if ( 404 !== $this->get_error_status( $check_res ) ) {
					$this->add_log( "بررسی نگاشت محصول #{$product_id} ناموفق بود؛ شناسه مقصد حفظ شد و ایجاد جدید انجام نشد: " . $check_res->get_error_message(), 'ERROR' );
					return false;
				}
				$remote_product_id = 0;
			} elseif ( isset( $check_res->id ) ) {
				$remote_sku = isset( $check_res->sku ) ? trim( (string) $check_res->sku ) : '';
				if ( $remote_sku === $sku ) {
					$remote_product_obj = $check_res;
					delete_post_meta( $product_id, '_taha_sync_ambiguous_state' );
				} else {
					$this->add_log( "شناسه مقصد ذخیره‌شده برای محصول #{$product_id} به SKU دیگری تعلق دارد؛ قبل از ویرایش، جست‌وجوی امن با SKU انجام می‌شود.", 'ERROR' );
					$remote_product_id = 0;
				}
			} else {
				$this->add_log( "پاسخ بررسی نگاشت محصول #{$product_id} شناسه معتبر نداشت؛ ایجاد جدید انجام نشد.", 'ERROR' );
				return false;
			}
		}

		if ( $remote_product_id <= 0 ) {
			$search_res = $this->api_request( 'GET', 'products', array(
				'sku'    => $sku,
				'status' => 'any',
			), 15 );

			if ( is_wp_error( $search_res ) ) {
				$this->add_log( "جست‌وجوی SKU برای محصول #{$product_id} ناموفق بود؛ برای جلوگیری از تکرار، محصول جدید ساخته نشد: " . $search_res->get_error_message(), 'ERROR' );
				return false;
			}

			$exact_matches = WC_Catalog_Sync_Rules::exact_sku_matches( $search_res, $sku );

			if ( 1 === count( $exact_matches ) ) {
				$remote_product_id  = (int) $exact_matches[0]->id;
				$remote_product_obj = $exact_matches[0];
				update_post_meta( $product_id, '_taha_remote_product_id', $remote_product_id );
				delete_post_meta( $product_id, '_taha_sync_ambiguous_state' );
			} elseif ( count( $exact_matches ) > 1 ) {
				update_post_meta( $product_id, '_taha_sync_ambiguous_state', array( 'sku' => $sku, 'reason' => 'multiple_matches', 'time' => time() ) );
				$this->add_log( "چند محصول مقصد با SKU محصول #{$product_id} پیدا شد؛ وضعیت مبهم است و ایجاد/ویرایش متوقف شد.", 'ERROR' );
				return false;
			} elseif ( ! empty( $ambiguous_state ) ) {
				$this->add_log( "وضعیت ایجاد محصول #{$product_id} از اجرای قبلی مبهم است؛ مقصد را با SKU بررسی و سپس قفل ایمنی را از صفحه افزونه آزاد کنید.", 'ERROR' );
				return false;
			}
		}

		// 4.5 شابک و تعداد صفحات + حفظ ویژگی‌های اختصاصی سایت مقصد
		$isbn  = $this->get_book_isbn( $product_id, $product );
		$pages = $this->get_book_pages( $product_id );

		if ( 'yes' === $settings['book_info_attrs'] ) {
			if ( '' !== $pages ) {
				$attributes_data[] = array(
					'name'      => 'تعداد صفحات',
					'visible'   => true,
					'variation' => false,
					'options'   => array( $pages . ' صفحه' ),
				);
			}
			if ( '' !== $isbn ) {
				$attributes_data[] = array(
					'name'      => 'شابک',
					'visible'   => true,
					'variation' => false,
					'options'   => array( $isbn ),
				);
			}
		}

		if ( $remote_product_obj && ! empty( $remote_product_obj->attributes ) && is_array( $remote_product_obj->attributes ) ) {
			$source_attr_names = array();
			foreach ( $attributes_data as $sa ) {
				$source_attr_names[] = mb_strtolower( trim( wp_strip_all_tags( $sa['name'] ) ) );
			}
			foreach ( $remote_product_obj->attributes as $ra ) {
				if ( ! isset( $ra->name ) ) {
					continue;
				}
				$ra_key = mb_strtolower( trim( wp_strip_all_tags( $ra->name ) ) );
				if ( in_array( $ra_key, $source_attr_names, true ) ) {
					continue;
				}
				$kept = array(
					'name'      => (string) $ra->name,
					'visible'   => ! empty( $ra->visible ),
					'variation' => ! empty( $ra->variation ),
					'options'   => isset( $ra->options ) ? array_map( 'strval', (array) $ra->options ) : array(),
				);
				if ( ! empty( $ra->id ) ) {
					$kept['id'] = (int) $ra->id;
				}
				if ( ! empty( $kept['options'] ) ) {
					$attributes_data[] = $kept;
				}
			}
		}

		// 5. ادغام هوشمند دسته‌بندی‌ها (حفظ دسته‌بندی‌های اختصاصی سایت مقصد)
		$final_category_ids   = array();
		$source_remote_cat_ids = null;
		if ( 'yes' === $settings['sync_child_cats'] ) {
			$root_ids      = $this->get_monitored_root_term_ids();
			$product_terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

			if ( ! is_wp_error( $product_terms ) && is_array( $product_terms ) ) {
				foreach ( $product_terms as $term_id ) {
					if ( $this->is_term_in_monitored_tree( $term_id, $root_ids ) ) {
						$remote_cat_id = $this->ensure_remote_category_synced( $term_id );
						if ( $remote_cat_id ) {
							$final_category_ids[] = (int) $remote_cat_id;
						}
					}
				}
			}

			// فقط دسته‌هایی که «خود افزونه» قبلاً روی همین کتاب گذاشته و حالا در مبدا برداشته شده‌اند حذف می‌شوند.
			// هر دسته‌ای که در فروشگاه مقصد ساخته یا به محصول وصل شده دست‌نخورده می‌ماند.
			$source_remote_cat_ids = array_values( array_unique( array_filter( array_map( 'intval', $final_category_ids ) ) ) );
			$prev_synced           = get_post_meta( $product_id, '_taha_synced_remote_cats', true );
			$prev_synced           = is_array( $prev_synced ) ? array_map( 'intval', $prev_synced ) : array();

			if ( $remote_product_obj && ! empty( $remote_product_obj->categories ) && is_array( $remote_product_obj->categories ) ) {
				foreach ( $remote_product_obj->categories as $existing_rcat ) {
					if ( ! isset( $existing_rcat->id ) ) {
						continue;
					}
					$existing_rcat_id = (int) $existing_rcat->id;
					if ( in_array( $existing_rcat_id, $prev_synced, true ) && ! in_array( $existing_rcat_id, $source_remote_cat_ids, true ) ) {
						continue; // قبلاً توسط افزونه گذاشته شده و در مبدا حذف شده است
					}
					$final_category_ids[] = $existing_rcat_id;
				}
			}
		}

		$final_category_ids = array_values( array_unique( array_filter( $final_category_ids ) ) );
		$remote_categories  = array();
		foreach ( $final_category_ids as $fc_id ) {
			$remote_categories[] = array( 'id' => (int) $fc_id );
		}

		// 6. استخراج دقیق قیمت، موجودی و وزن
		$regular_price = WC_Catalog_Sync_Rules::regular_price( $product->get_regular_price(), $product->get_price() );
		$sale_price    = (string) $product->get_sale_price();

		$manage_stock   = (bool) $product->get_manage_stock();
		$stock_quantity = $manage_stock ? (int) $product->get_stock_quantity() : null;
		$stock_status   = (string) $product->get_stock_status();

		if ( $manage_stock && null !== $stock_quantity && $stock_quantity <= 0 && 'no' === $product->get_backorders() ) {
			$stock_status = 'outofstock';
		}

		$raw_weight = (string) get_post_meta( $product_id, '_weight', true );
		if ( '' === $raw_weight ) {
			$raw_weight = (string) $product->get_weight();
		}
		$final_weight = $raw_weight;
		if ( 'convert_g_to_kg' === $settings['weight_sync_mode'] && is_numeric( $raw_weight ) && (float) $raw_weight > 0 ) {
			$final_weight = (string) round( ( (float) $raw_weight ) / 1000, 4 );
		}

		// تضمین ۱۰۰٪ حفظ توضیحات یونیک: فیلدهای description و short_description هرگز ارسال نمی‌شوند
		$payload = array(
			'name'           => $product->get_name(),
			'type'           => 'simple',
			'sku'            => (string) $sku,
			'regular_price'  => $regular_price,
			'sale_price'     => $sale_price,
			'manage_stock'   => $manage_stock,
			'stock_quantity' => $stock_quantity,
			'stock_status'   => $stock_status,
			'weight'         => $final_weight,
			'dimensions'     => array(
				'length' => (string) $product->get_length(),
				'width'  => (string) $product->get_width(),
				'height' => (string) $product->get_height(),
			),
			'status'         => 'publish',
			'attributes'     => $attributes_data,
		);

		if ( ! empty( $remote_categories ) ) {
			$payload['categories'] = $remote_categories;
		}

		// شابک (فیلد GTIN/ISBN ووکامرس) و تعداد صفحات (متای سفارشی)
		if ( '' !== $isbn ) {
			$payload['global_unique_id'] = $isbn;
		}
		$meta_data = array();
		if ( '' !== $pages ) {
			$meta_data[] = array( 'key' => '_meta_total_pages', 'value' => $pages );
		}
		if ( '' !== $isbn ) {
			$meta_data[] = array( 'key' => '_book_isbn', 'value' => $isbn );
		}
		if ( ! empty( $meta_data ) ) {
			$payload['meta_data'] = $meta_data;
		}

		// تضمین نهایی: توضیحات کوتاه/بلند هیچ‌گاه (چه برای کتاب موجود، چه جدید) ارسال نمی‌شود
		unset( $payload['description'], $payload['short_description'] );

		// 7. تصاویر
		$img_res = array(
			'payload' => null,
			'sig'     => '',
			'errors'  => 0,
			'stale'   => array(),
			'ours'    => array(),
			'mode'    => '',
		);
		if ( ! empty( $source_images ) ) {
			$img_res = $this->resolve_remote_images( $product, $product_id, $source_images, $remote_product_obj, (bool) $force_image_sync );
			if ( null !== $img_res['payload'] ) {
				$payload['images'] = $img_res['payload'];
			}
		}

		// 8. ارسال به سایت مقصد
		$is_create = ( $remote_product_id <= 0 );
		$send      = function ( $pl ) use ( $remote_product_id, $product, $product_id, $sku ) {
			if ( $remote_product_id > 0 ) {
				return $this->api_request( 'PUT', 'products/' . $remote_product_id, $pl, 40 );
			}
			update_post_meta( $product_id, '_taha_sync_ambiguous_state', array( 'sku' => $sku, 'reason' => 'create_in_flight', 'time' => time() ) );
			$pl['slug'] = $product->get_slug();
			return $this->api_request( 'POST', 'products', $pl, 40 );
		};
		$resolve_create = function ( $result ) use ( $is_create, $product_id, $sku ) {
			if ( $is_create && is_wp_error( $result ) ) {
				return $this->reconcile_create_failure( $product_id, $sku, $result );
			}
			return $result;
		};

		$response = $resolve_create( $send( $payload ) );
		if ( is_wp_error( $response ) && 'taha_create_ambiguous' === $response->get_error_code() ) {
			$this->add_log( $response->get_error_message(), 'ERROR' );
			return false;
		}

		// خطای شابک تکراری/نامعتبر نباید کل سینک را خراب کند
		if ( is_wp_error( $response ) && isset( $payload['global_unique_id'] ) ) {
			$emsg = $response->get_error_message();
			if ( false !== stripos( $emsg, 'gtin' ) || false !== stripos( $emsg, 'global_unique_id' ) || false !== stripos( $emsg, 'isbn' ) ) {
				$this->add_log( "هشدار: شابک {$isbn} برای محصول #{$product_id} در مقصد پذیرفته نشد ({$emsg}); ادامه بدون فیلد GTIN.", 'ERROR' );
				unset( $payload['global_unique_id'] );
				$response = $resolve_create( $send( $payload ) );
				if ( is_wp_error( $response ) && 'taha_create_ambiguous' === $response->get_error_code() ) {
					$this->add_log( $response->get_error_message(), 'ERROR' );
					return false;
				}
			}
		}

		$images_in_payload = isset( $payload['images'] );
		$image_error_message = is_wp_error( $response ) ? $response->get_error_message() : '';
		$image_error_status  = is_wp_error( $response ) ? $this->get_error_status( $response ) : 0;
		$image_rejected      = WC_Catalog_Sync_Rules::image_only_rejection( $image_error_status, $image_error_message );
		if ( is_wp_error( $response ) && $images_in_payload && $image_rejected ) {
			$this->add_log( "هشدار تصویر برای محصول #{$product_id} (" . $response->get_error_message() . ')؛ بروزرسانی بقیه اطلاعات بدون تصویر انجام شد.', 'ERROR' );
			unset( $payload['images'] );
			$images_in_payload = false;
			$response          = $resolve_create( $send( $payload ) );
			if ( is_wp_error( $response ) && 'taha_create_ambiguous' === $response->get_error_code() ) {
				$this->add_log( $response->get_error_message(), 'ERROR' );
				return false;
			}
		}

		if ( is_wp_error( $response ) ) {
			$this->add_log( "خطا در همگام‌سازی محصول #{$product_id} ({$product->get_name()}): " . $response->get_error_message(), 'ERROR' );
			return false;
		}

		if ( isset( $response->id ) ) {
			$now_ts = time();
			update_post_meta( $product_id, '_taha_remote_product_id', (int) $response->id );
			delete_post_meta( $product_id, '_taha_sync_ambiguous_state' );
			if ( is_array( $source_remote_cat_ids ) ) {
				update_post_meta( $product_id, '_taha_synced_remote_cats', $source_remote_cat_ids );
			}

			// هش تصویر فقط وقتی ثبت می‌شود که تصاویر دقیقاً در مقصد برقرار باشند
			$images_settled = ( ! empty( $source_images ) && 0 === (int) $img_res['errors'] && ( $images_in_payload || null === $img_res['payload'] ) );
			if ( $images_settled ) {
				update_post_meta( $product_id, '_taha_synced_img_hash', $img_res['sig'] );
			}

			// پاکسازی تصاویر خراب/قدیمی ساخته‌شده توسط افزونه در کتابخانه مقصد
			if ( $images_in_payload && 'upload' === $img_res['mode'] && ! empty( $img_res['stale'] ) ) {
				$cleaned = $this->cleanup_remote_images( $img_res['stale'], $img_res['ours'] );
				if ( $cleaned > 0 ) {
					$this->add_log( "{$cleaned} تصویر خراب/قدیمی محصول #{$product_id} از کتابخانه مقصد پاک شد.", 'INFO' );
				}
			}

			update_post_meta( $product_id, '_taha_last_sync_time', current_time( 'mysql' ) );
			update_post_meta( $product_id, '_taha_last_sync_timestamp', $now_ts );

			$stock_label = $manage_stock ? "موجودی: {$stock_quantity} ({$stock_status})" : "وضعیت: {$stock_status}";
			$img_status  = $images_in_payload ? ' | تصویر بروزرسانی شد' : '';
			$book_label  = ' | صفحات: ' . ( '' !== $pages ? $pages : '—' ) . ' | شابک: ' . ( '' !== $isbn ? $isbn : '—' );
			$this->add_log(
				"محصول «{$product->get_name()}» (#{$product_id}) همگام‌سازی شد | قیمت: {$regular_price} | وزن: {$final_weight} | {$stock_label}{$book_label}{$img_status} | شناسه مقصد: {$response->id}",
				'SUCCESS'
			);
			return true;
		}

		return false;
	}

	public function api_request( $method, $endpoint, $data = array(), $timeout = 25 ) {
		$settings   = $this->get_settings();
		if ( empty( $settings['target_url'] ) || empty( $settings['consumer_key'] ) || empty( $settings['consumer_secret'] ) ) {
			return $this->request_error( 'wc_rest_not_configured', 'نشانی مقصد یا کلیدهای WooCommerce کامل نیست.', 0, $method, 'wc/v3/' . ltrim( $endpoint, '/' ) );
		}
		$base_url   = untrailingslashit( $settings['target_url'] ) . '/wp-json/wc/v3/' . ltrim( $endpoint, '/' );
		$ck         = trim( $settings['consumer_key'] );
		$cs         = trim( $settings['consumer_secret'] );
		$auth_token = base64_encode( $ck . ':' . $cs );

		$args = array(
			'method'    => strtoupper( $method ),
			'timeout'   => (int) $timeout,
			'sslverify' => true,
			'headers'   => array(
				'Authorization' => 'Basic ' . $auth_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent' => 'WooCommerceCatalogSync/1.0.0',
			),
		);

		// اطلاعات احراز هویت فقط در هدر فرستاده می‌شود تا وارد URL و لاگ وب‌سرور نشود.
		$url = $base_url;

		if ( 'GET' === $args['method'] && ! empty( $data ) ) {
			$url = add_query_arg( $data, $url );
		} elseif ( in_array( $args['method'], array( 'POST', 'PUT' ), true ) && ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $this->request_error( 'wc_rest_transport', $response->get_error_message(), 0, $method, 'wc/v3/' . ltrim( $endpoint, '/' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body );

		if ( $code < 200 || $code >= 300 ) {
			$err_msg = isset( $json->message ) ? $json->message : "HTTP Error {$code}";
			return $this->request_error( 'wc_rest_error', $err_msg, $code, $method, 'wc/v3/' . ltrim( $endpoint, '/' ) );
		}

		if ( null === $json && '' !== trim( (string) $body ) ) {
			return $this->request_error( 'wc_rest_not_json', 'پاسخ مقصد JSON معتبر نبود.', $code, $method, 'wc/v3/' . ltrim( $endpoint, '/' ) );
		}

		return $json;
	}

	/**
	 * پردازشگر AJAX برای کنترل و مشاهده وضعیت لحظه‌ای صف پس‌زمینه (مقاوم در برابر رفرش صفحه)
	 */
	public function handle_ajax_requests() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'عدم دسترسی مجاز.' ) );
		}

		check_ajax_referer( 'taha_pub_ajax_nonce', 'nonce' );

		$sub_action = isset( $_POST['sub_action'] ) ? sanitize_text_field( $_POST['sub_action'] ) : '';

		// ۱. شروع عملیات دسته‌ای در پس‌زمینه سرور
		if ( 'start_background_batch' === $sub_action ) {
			$current = $this->get_batch_state();
			if ( 'running' === $current['status'] && ! empty( $current['queue'] ) ) {
				$this->dispatch_background_worker();
				wp_send_json_success( $this->format_batch_response( $current ) );
			}

			$product_ids = array_map( 'intval', $this->get_all_monitored_product_ids() );
			$total       = count( $product_ids );

			if ( 0 === $total ) {
				wp_send_json_error( array( 'message' => 'هیچ محصولی در دسته‌بندی‌های انتخاب‌شده یافت نشد.' ) );
			}

			// پاکسازی هش تصاویر قبلی جهت بررسی مجدد سلامت تصاویر
			global $wpdb;
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_taha_synced_img_hash' ) );
			delete_option( '_taha_batch_worker_lock' );

			$new_state = array(
				'status'       => 'running',
				'queue'        => $product_ids,
				'total'        => $total,
				'processed'    => 0,
				'success'      => 0,
				'failed'       => 0,
				'current_id'   => $product_ids[0],
				'force_img'    => true,
				'last_message' => 'عملیات همگام‌سازی در پس‌زمینه سرور آغاز شد...',
				'updated_at'   => time(),
			);

			$this->save_batch_state( $new_state );
			$this->add_log( "صف همگام‌سازی پس‌زمینه برای {$total} محصول آغاز شد.", 'QUEUE' );

			// استارت موتور پس‌زمینه سرور
			$this->dispatch_background_worker();

			wp_send_json_success( $this->format_batch_response( $new_state ) );
		}

		// ۲. دریافت وضعیت لحظه‌ای پیشرفت (حتی پس از رفرش صفحه یا بستن و باز کردن مرورگر) + محرک کمکی در صورت نیاز
		if ( 'poll_batch_status' === $sub_action ) {
			$state = $this->get_batch_state();

			if ( 'running' === $state['status'] ) {
				wp_cache_delete( '_taha_batch_worker_lock', 'options' );
				$lock = (int) get_option( '_taha_batch_worker_lock', 0 );
				$idle = time() - (int) $state['updated_at'];

				// اگر ورکر پس‌زمینه آزاد است یا بیش از ۴ ثانیه از آخرین گام گذشته، همین درخواست یک گام را به جلو ببرد
				if ( 0 === $lock || ( time() - $lock ) >= 25 || $idle >= 4 ) {
					$this->process_next_batch_step();
					$state = $this->get_batch_state();
				}
			}

			wp_send_json_success( $this->format_batch_response( $state ) );
		}

		// ۳. توقف کامل صف پس‌زمینه
		if ( 'stop_background_batch' === $sub_action ) {
			$state                 = $this->get_batch_state();
			$state['status']       = 'stopped';
			$state['queue']        = array();
			$state['current_id']   = 0;
			$state['last_message'] = 'عملیات توسط مدیر سیستم متوقف شد.';
			$this->save_batch_state( $state );
			delete_option( '_taha_batch_worker_lock' );
			$this->add_log( 'صف همگام‌سازی پس‌زمینه توسط کاربر متوقف شد.', 'SYSTEM' );

			wp_send_json_success( $this->format_batch_response( $state ) );
		}

		wp_send_json_error( array( 'message' => 'عملیات نامعتبر.' ) );
	}

	/**
	 * استانداردسازی خروجی وضعیت صف همراه با آخرین لاگ‌ها برای نمایش زنده در داشبورد
	 */
	private function format_batch_response( $state ) {
		$total     = max( 0, (int) $state['total'] );
		$processed = min( $total, max( 0, (int) $state['processed'] ) );
		$percent   = ( $total > 0 ) ? (int) round( ( $processed / $total ) * 100 ) : 0;

		if ( 'completed' === $state['status'] ) {
			$percent = 100;
		}

		$logs = get_option( $this->log_option_name, array() );

		return array(
			'status'       => $state['status'],
			'total'        => $total,
			'processed'    => $processed,
			'success'      => (int) $state['success'],
			'failed'       => (int) $state['failed'],
			'percent'      => $percent,
			'current_id'   => (int) $state['current_id'],
			'last_message' => (string) $state['last_message'],
			'logs'         => array_slice( is_array( $logs ) ? $logs : array(), 0, 80 ),
		);
	}

	public function register_admin_menu() {
		add_submenu_page(
			'woocommerce',
			'همگام‌سازی کاتالوگ ووکامرس',
			'همگام‌سازی کاتالوگ',
			'manage_woocommerce',
			'taha-pub-sync',
			array( $this, 'render_admin_page' )
		);
	}

	public function handle_manual_actions() {
		if ( ! isset( $_POST['taha_pub_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'taha_pub_nonce_action', 'taha_pub_nonce' );

		$action = sanitize_text_field( $_POST['taha_pub_action'] );

		if ( 'save_settings' === $action ) {
			$current_settings = $this->get_settings();
			$target_url       = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
			$target_parts     = wp_parse_url( $target_url );
			if ( empty( $target_parts['scheme'] ) || empty( $target_parts['host'] ) || ! in_array( strtolower( $target_parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $target_parts['user'] ) || isset( $target_parts['pass'] ) ) {
				add_settings_error( 'taha_pub_messages', 'invalid_target', 'آدرس مقصد معتبر نیست. یک نشانی کامل بدون نام کاربری یا رمز در URL وارد کنید.', 'error' );
				return;
			}

			$new_settings = array(
				'target_url'        => untrailingslashit( $target_url ),
				'consumer_key'      => $current_settings['consumer_key'],
				'consumer_secret'   => $current_settings['consumer_secret'],
				'wp_username'       => isset( $_POST['wp_username'] ) ? sanitize_user( wp_unslash( $_POST['wp_username'] ), true ) : '',
				'wp_app_password'   => $current_settings['wp_app_password'],
				'image_mode'        => ( isset( $_POST['image_mode'] ) && 'url' === $_POST['image_mode'] ) ? 'url' : 'upload',
				'book_info_attrs'   => isset( $_POST['book_info_attrs'] ) ? 'yes' : 'no',
				'root_categories'   => isset( $_POST['root_categories'] ) ? sanitize_text_field( wp_unslash( $_POST['root_categories'] ) ) : '',
				'auto_generate_sku' => isset( $_POST['auto_generate_sku'] ) ? 'yes' : 'no',
				'sync_child_cats'   => isset( $_POST['sync_child_cats'] ) ? 'yes' : 'no',
				'realtime_sync'     => isset( $_POST['realtime_sync'] ) ? 'yes' : 'no',
				'weight_sync_mode'  => isset( $_POST['weight_sync_mode'] ) ? sanitize_text_field( $_POST['weight_sync_mode'] ) : 'sync_unit_gram',
			);

			$new_consumer_key = isset( $_POST['consumer_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) ) : '';
			if ( '' !== $new_consumer_key ) {
				$new_settings['consumer_key'] = $new_consumer_key;
			}
			$new_consumer_secret = isset( $_POST['consumer_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['consumer_secret'] ) ) ) : '';
			if ( '' !== $new_consumer_secret ) {
				$new_settings['consumer_secret'] = $new_consumer_secret;
			}
			$new_app_password = isset( $_POST['wp_app_password'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['wp_app_password'] ) ) ) : '';
			if ( '' !== $new_app_password ) {
				$new_settings['wp_app_password'] = $new_app_password;
			}

			$old_target = untrailingslashit( (string) $current_settings['target_url'] );
			$new_target = untrailingslashit( (string) $new_settings['target_url'] );
			if ( '' !== $old_target && $old_target !== $new_target ) {
				// شناسه محصول حفظ می‌شود و هنگام سینک با SKU اعتبارسنجی خواهد شد؛ شناسه‌های رسانه/دسته وابسته به مقصد قبلی‌اند.
				global $wpdb;
				$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_taha_remote_media_map' ) );
				$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_taha_synced_img_hash' ) );
				$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_taha_synced_remote_cats' ) );
				$this->remote_cats_cache = null;
				$this->add_log( 'نشانی مقصد تغییر کرد؛ نگاشت رسانه‌ها و دسته‌های وابسته به مقصد قبلی بازنشانی شد. نگاشت محصول‌ها حفظ و با SKU بررسی می‌شود.', 'SYSTEM' );
			}

			update_option( $this->option_name, $new_settings );

			$this->add_log( 'تنظیمات سیستم همگام‌سازی بروزرسانی شد.', 'SYSTEM' );
			add_settings_error( 'taha_pub_messages', 'settings_saved', 'تنظیمات با موفقیت ذخیره گردید.', 'updated' );
		}

		if ( 'clear_ambiguous_state' === $action && ! empty( $_POST['ambiguous_product_id'] ) ) {
			$product_id = absint( $_POST['ambiguous_product_id'] );
			delete_post_meta( $product_id, '_taha_sync_ambiguous_state' );
			$this->add_log( "وضعیت مبهم محصول #{$product_id} با تأیید مدیر آزاد شد؛ اجرای بعدی ابتدا مقصد را با SKU بررسی می‌کند.", 'SYSTEM' );
			add_settings_error( 'taha_pub_messages', 'ambiguity_cleared', 'قفل ایمنی وضعیت مبهم برداشته شد. اکنون همگام‌سازی همان محصول را دوباره اجرا کنید.', 'updated' );
		}

		if ( 'sync_all_categories' === $action ) {
			$root_ids = $this->get_monitored_root_term_ids();
			$count    = 0;
			foreach ( $root_ids as $root_id ) {
				if ( $this->ensure_remote_category_synced( $root_id ) ) {
					$count++;
				}
				$children = get_term_children( $root_id, 'product_cat' );
				if ( ! is_wp_error( $children ) && is_array( $children ) ) {
					foreach ( $children as $child_id ) {
						if ( $this->ensure_remote_category_synced( $child_id ) ) {
							$count++;
						}
					}
				}
			}
			$this->add_log( "همگام‌سازی ساختار دسته‌بندی‌ها انجام شد ({$count} دسته‌بندی).", 'CAT_SYNC' );
			add_settings_error( 'taha_pub_messages', 'cats_synced', "تعداد {$count} دسته‌بندی مادر و زیرمجموعه با موفقیت در سایت مقصد همگام‌سازی شدند.", 'updated' );
		}

		if ( 'test_single_product' === $action && ! empty( $_POST['test_product_id'] ) ) {
			$test_id = (int) $_POST['test_product_id'];
			$res     = $this->process_product_sync( $test_id, true );
			if ( $res ) {
				add_settings_error( 'taha_pub_messages', 'single_synced', "محصول #{$test_id} با موفقیت در سایت مقصد بروزرسانی شد.", 'updated' );
			} else {
				add_settings_error( 'taha_pub_messages', 'single_failed', "خطا در همگام‌سازی محصول #{$test_id}. جزئیات را در بخش گزارش سیستم مشاهده نمایید.", 'error' );
			}
		}

		if ( 'test_image_upload' === $action ) {
			list( $ok, $msg ) = $this->run_upload_selftest();
			$this->add_log( 'تست آپلود تصویر: ' . $msg, $ok ? 'SUCCESS' : 'ERROR' );
			add_settings_error( 'taha_pub_messages', 'img_selftest', $msg, $ok ? 'updated' : 'error' );
		}

		if ( 'clear_logs' === $action ) {
			delete_option( $this->log_option_name );
			add_settings_error( 'taha_pub_messages', 'logs_cleared', 'گزارش‌های سیستم پاکسازی شدند.', 'updated' );
		}
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به تنظیمات همگام‌سازی را ندارید.', 'woocommerce-catalog-sync' ) );
		}
		$settings     = $this->get_settings();
		$detected_ids = $this->get_monitored_root_term_ids();
		$logs         = get_option( $this->log_option_name, array() );
		$batch_state  = $this->format_batch_response( $this->get_batch_state() );
		$ajax_nonce   = wp_create_nonce( 'taha_pub_ajax_nonce' );
		$ambiguous_ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_key'       => '_taha_sync_ambiguous_state',
		) );
		?>
		<style>
			.taha-enterprise-wrap {
				direction: rtl;
				text-align: right;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Tahoma, Arial, sans-serif;
				max-width: 1280px;
				margin: 20px 0;
			}
			.taha-header-bar {
				background: #ffffff;
				border: 1px solid #dcdcde;
				border-right: 4px solid #2271b1;
				border-radius: 6px;
				padding: 18px 24px;
				margin-bottom: 20px;
				display: flex;
				justify-content: space-between;
				align-items: center;
				box-shadow: 0 1px 2px rgba(0,0,0,0.04);
			}
			.taha-header-bar h1 {
				margin: 0 0 6px 0;
				font-size: 20px;
				font-weight: 700;
				color: #1d2327;
				padding: 0;
			}
			.taha-header-bar p {
				margin: 0;
				color: #50575e;
				font-size: 13px;
			}
			.taha-version-badge {
				background: #f0f6fc;
				color: #2271b1;
				border: 1px solid #c5d9ed;
				padding: 5px 12px;
				border-radius: 4px;
				font-size: 12px;
				font-weight: 600;
				white-space: nowrap;
			}
			.taha-grid-layout {
				display: grid;
				grid-template-columns: 1.8fr 1.2fr;
				gap: 20px;
				align-items: start;
			}
			@media (max-width: 960px) {
				.taha-grid-layout {
					grid-template-columns: 1fr;
				}
			}
			.taha-panel {
				background: #ffffff;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 22px;
				box-shadow: 0 1px 2px rgba(0,0,0,0.04);
				margin-bottom: 20px;
			}
			.taha-panel h2 {
				margin: 0 0 16px 0;
				padding-bottom: 12px;
				border-bottom: 1px solid #f0f0f1;
				font-size: 15px;
				font-weight: 700;
				color: #1d2327;
			}
			.taha-action-group {
				display: flex;
				flex-direction: column;
				gap: 10px;
			}
			.taha-btn {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				padding: 9px 16px;
				font-size: 13px;
				font-weight: 600;
				border-radius: 4px;
				border: 1px solid #2271b1;
				background: #f6f7f7;
				color: #2271b1;
				cursor: pointer;
				transition: all 0.15s ease;
				text-decoration: none;
				width: 100%;
				box-sizing: border-box;
			}
			.taha-btn:hover {
				background: #f0f6fc;
				border-color: #135e96;
				color: #135e96;
			}
			.taha-btn-primary {
				background: #2271b1;
				color: #ffffff;
				border-color: #2271b1;
			}
			.taha-btn-primary:hover {
				background: #135e96;
				border-color: #135e96;
				color: #ffffff;
			}
			.taha-btn:disabled {
				opacity: 0.6;
				cursor: not-allowed;
			}
			.taha-cat-pill {
				display: inline-block;
				background: #f0f6fc;
				color: #1d2327;
				border: 1px solid #c5d9ed;
				padding: 3px 10px;
				border-radius: 4px;
				font-size: 12px;
				margin: 4px 0 0 6px;
			}
			.taha-progress-box {
				display: none;
				margin-top: 16px;
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 14px;
			}
			.taha-progress-bar-bg {
				width: 100%;
				height: 10px;
				background: #dcdcde;
				border-radius: 5px;
				overflow: hidden;
				margin: 8px 0;
			}
			.taha-progress-bar-fill {
				height: 100%;
				width: 0%;
				background: #2271b1;
				transition: width 0.25s ease;
			}
			.taha-progress-status {
				font-size: 12px;
				color: #3c434a;
				display: flex;
				justify-content: space-between;
			}
			.taha-log-viewer {
				background: #1d2327;
				color: #c3c4c7;
				padding: 16px;
				border-radius: 6px;
				max-height: 340px;
				overflow-y: auto;
				font-family: Consolas, Monaco, monospace;
				font-size: 12.5px;
				line-height: 1.7;
			}
			.taha-log-row {
				border-bottom: 1px solid #2c3338;
				padding: 4px 0;
			}
		</style>

		<div class="wrap taha-enterprise-wrap">
			<div class="taha-header-bar">
				<div>
					<h1>همگام‌سازی یک‌طرفه کاتالوگ ووکامرس</h1>
					<p>ایجاد و ویرایش محصولات دسته‌های منتخب از فروشگاه مبدأ در فروشگاه مقصد، بدون بازنویسی توضیحات مقصد</p>
				</div>
				<span class="taha-version-badge">نسخه ۱.۰.۰</span>
			</div>

			<?php settings_errors( 'taha_pub_messages' ); ?>

			<div class="taha-grid-layout">
				<!-- ستون تنظیمات پایه -->
				<div class="taha-panel">
					<h2>پیکربندی ارتباط وب‌سرویس (REST API)</h2>
					<form method="post">
						<?php wp_nonce_field( 'taha_pub_nonce_action', 'taha_pub_nonce' ); ?>
						<input type="hidden" name="taha_pub_action" value="save_settings">

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label>آدرس وب‌سایت مقصد</label></th>
								<td>
									<input type="url" name="target_url" value="<?php echo esc_attr( $settings['target_url'] ); ?>" class="regular-text" style="direction:ltr; width:100%;" required>
								</td>
							</tr>
							<tr>
								<th scope="row"><label>کلید مصرف‌کننده (Consumer Key)</label></th>
								<td>
										<input type="password" name="consumer_key" value="" class="regular-text" style="direction:ltr; width:100%;" autocomplete="new-password" placeholder="<?php echo empty( $settings['consumer_key'] ) ? 'وارد نشده' : 'ذخیره شده؛ برای حفظ، خالی بگذارید'; ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label>رمز مصرف‌کننده (Consumer Secret)</label></th>
								<td>
										<input type="password" name="consumer_secret" value="" class="regular-text" style="direction:ltr; width:100%;" autocomplete="new-password" placeholder="<?php echo empty( $settings['consumer_secret'] ) ? 'وارد نشده' : 'ذخیره شده؛ برای حفظ، خالی بگذارید'; ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label>نام کاربری مدیر سایت مقصد</label></th>
								<td>
									<input type="text" name="wp_username" value="<?php echo esc_attr( $settings['wp_username'] ); ?>" class="regular-text" style="direction:ltr; width:100%;" autocomplete="off">
									<p class="description">برای آپلود مستقیم تصاویر (روش پایدار). در سایت مقصد: کاربران ← پروفایل ← Application Passwords.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label>Application Password سایت مقصد</label></th>
								<td>
										<input type="password" name="wp_app_password" value="" class="regular-text" style="direction:ltr; width:100%;" autocomplete="new-password" placeholder="<?php echo empty( $settings['wp_app_password'] ) ? 'وارد نشده' : 'ذخیره شده؛ برای حفظ، خالی بگذارید'; ?>">
										<p class="description">فیلدهای کلید و رمز هرگز دوباره نمایش داده نمی‌شوند. برای نگه‌داشتن مقدار فعلی، آن‌ها را خالی بگذارید.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label>روش انتقال تصاویر</label></th>
								<td>
									<select name="image_mode" style="width:100%;">
										<option value="upload" <?php selected( $settings['image_mode'], 'upload' ); ?>>آپلود مستقیم فایل به سایت مقصد (پیشنهادی - بدون وابستگی به CDN)</option>
										<option value="url" <?php selected( $settings['image_mode'], 'url' ); ?>>ارسال لینک تصویر (قدیمی - مقصد باید بتواند از مبدا دانلود کند)</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label>دسته‌بندی‌های مادر تحت پوشش</label></th>
								<td>
									<input type="text" name="root_categories" value="<?php echo esc_attr( $settings['root_categories'] ); ?>" class="regular-text" style="width:100%;" required>
									<div style="margin-top: 6px;">
										<?php
										if ( ! empty( $detected_ids ) ) {
											foreach ( $detected_ids as $did ) {
												$t = get_term( $did, 'product_cat' );
												if ( $t && ! is_wp_error( $t ) ) {
													$children = get_term_children( $did, 'product_cat' );
													$c_count  = is_array( $children ) ? count( $children ) : 0;
													echo '<span class="taha-cat-pill">دسته‌بندی: <strong>' . esc_html( $t->name ) . '</strong> (شناسه: ' . (int) $did . ' | زیرمجموعه: ' . (int) $c_count . ')</span>';
												}
											}
										}
										?>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row"><label>استاندارد واحد وزن</label></th>
								<td>
									<select name="weight_sync_mode" style="width:100%;">
									<option value="sync_unit_gram" <?php selected( $settings['weight_sync_mode'], 'sync_unit_gram' ); ?>>ارسال مقدار وزن بدون تبدیل (واحد دو فروشگاه باید یکسان باشد)</option>
										<option value="convert_g_to_kg" <?php selected( $settings['weight_sync_mode'], 'convert_g_to_kg' ); ?>>تبدیل خودکار گرم به کیلوگرم (تقسیم بر ۱۰۰۰)</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">سیاست‌های همگام‌سازی</th>
								<td>
									<fieldset>
										<label style="display:block; margin-bottom:8px;">
											<input type="checkbox" name="realtime_sync" value="yes" <?php checked( $settings['realtime_sync'], 'yes' ); ?>>
											همگام‌سازی آنی (Real-Time) در لحظه ویرایش محصول یا تغییر موجودی
										</label>
										<label style="display:block; margin-bottom:8px;">
											<input type="checkbox" name="sync_child_cats" value="yes" <?php checked( $settings['sync_child_cats'], 'yes' ); ?>>
											انتقال ساختار درختی زیرمجموعه‌ها و حفظ دسته‌بندی‌های اختصاصی سایت مقصد
										</label>
										<label style="display:block;">
											<input type="checkbox" name="auto_generate_sku" value="yes" <?php checked( $settings['auto_generate_sku'], 'yes' ); ?>>
											تولید خودکار شناسه محصول (SKU) برای کتاب‌های فاقد کد
										</label>
										<label style="display:block; margin-top:8px;">
											<input type="checkbox" name="book_info_attrs" value="yes" <?php checked( $settings['book_info_attrs'], 'yes' ); ?>>
											نمایش «تعداد صفحات» و «شابک» به صورت ویژگی قابل‌مشاهده در سایت مقصد
										</label>
									</fieldset>
								</td>
							</tr>
						</table>

						<p class="submit" style="margin-bottom:0; padding-bottom:0;">
							<button type="submit" class="button button-primary">ذخیره تنظیمات سیستم</button>
						</p>
					</form>
				</div>

				<!-- ستون عملیات اجرایی و پردازش پس‌زمینه پایدار -->
				<div>
					<div class="taha-panel">
						<h2>عملیات اجرایی و بروزرسانی پس‌زمینه</h2>
						<p style="font-size:12.5px; color:#50575e; margin-top:0; line-height:1.6;">
							فرآیند بروزرسانی محصولات در <strong>پس‌زمینه سرور</strong> اجرا می‌شود؛ در حین اجرا می‌توانید صفحه را رفرش کنید یا ببندید و وضعیت پیشرفت در هر زمان قابل مشاهده خواهد بود.
						</p>

						<div class="taha-action-group">
							<button type="button" id="taha-btn-batch-repair" class="taha-btn taha-btn-primary">
								شروع بررسی و بروزرسانی پس‌زمینه تمام محصولات و تصاویر
							</button>

							<form method="post" style="margin:0;">
								<?php wp_nonce_field( 'taha_pub_nonce_action', 'taha_pub_nonce' ); ?>
								<input type="hidden" name="taha_pub_action" value="sync_all_categories">
								<button type="submit" class="taha-btn">همگام‌سازی ساختار درختی دسته‌بندی‌ها</button>
							</form>

			<form method="post" style="margin:0;">
				<?php wp_nonce_field( 'taha_pub_nonce_action', 'taha_pub_nonce' ); ?>
				<input type="hidden" name="taha_pub_action" value="test_image_upload">
				<button type="submit" class="taha-btn">تست اتصال و آپلود تصویر به سایت مقصد</button>
			</form>
						</div>

						<!-- نوار پیشرفت پایدار (مقاوم در برابر رفرش صفحه) -->
						<div id="taha-batch-progress-box" class="taha-progress-box" style="<?php echo ( 'idle' !== $batch_state['status'] && $batch_state['total'] > 0 ) ? 'display:block;' : 'display:none;'; ?>">
							<div class="taha-progress-status">
								<span id="taha-progress-label">
									<?php
									if ( 'running' === $batch_state['status'] ) {
										echo esc_html( sprintf( 'در حال پردازش در پس‌زمینه (%d از %d محصول)...', $batch_state['processed'], $batch_state['total'] ) );
									} elseif ( 'completed' === $batch_state['status'] ) {
										echo 'عملیات بروزرسانی پس‌زمینه تکمیل شد.';
									} elseif ( 'stopped' === $batch_state['status'] ) {
										echo 'عملیات متوقف شده است.';
									}
									?>
								</span>
								<strong id="taha-progress-percent"><?php echo (int) $batch_state['percent']; ?>%</strong>
							</div>
							<div class="taha-progress-bar-bg">
								<div id="taha-progress-fill" class="taha-progress-bar-fill" style="width:<?php echo (int) $batch_state['percent']; ?>%;"></div>
							</div>
							<div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; gap:10px;">
								<span id="taha-progress-detail" style="font-size:11.5px; color:#646970;"><?php echo esc_html( $batch_state['last_message'] ); ?></span>
								<button type="button" id="taha-btn-stop-batch" class="button button-small" style="<?php echo ( 'running' === $batch_state['status'] ) ? '' : 'display:none;'; ?>">توقف عملیات</button>
							</div>
						</div>

						<hr style="margin: 20px 0; border:0; border-top:1px solid #f0f0f1;">

						<h2 style="border:none; padding:0; margin-bottom:10px;">همگام‌سازی موردی (بر اساس شناسه محصول)</h2>
						<form method="post" style="display:flex; gap:8px;">
							<?php wp_nonce_field( 'taha_pub_nonce_action', 'taha_pub_nonce' ); ?>
							<input type="hidden" name="taha_pub_action" value="test_single_product">
							<input type="number" name="test_product_id" placeholder="شناسه محصول (مثال: 57004)" style="flex:1;" required>
							<button type="submit" class="button button-primary">بروزرسانی محصول</button>
						</form>

							<?php if ( ! empty( $ambiguous_ids ) ) : ?>
								<div class="notice notice-warning inline" style="margin:16px 0 0; padding:10px 12px;">
									<p><strong>ایجاد نامطمئن تشخیص داده شده است.</strong> برای جلوگیری از محصول تکراری، ایجاد دوباره متوقف شده است. ابتدا SKU محصول را در مقصد جست‌وجو کنید؛ سپس فقط اگر محصولی وجود ندارد، قفل همان شناسه را آزاد کنید.</p>
									<p>شناسه‌های درگیر: <?php echo esc_html( implode( '، ', array_map( 'absint', $ambiguous_ids ) ) ); ?></p>
									<form method="post" style="display:flex; gap:8px;">
										<?php wp_nonce_field( 'taha_pub_nonce_action', 'taha_pub_nonce' ); ?>
										<input type="hidden" name="taha_pub_action" value="clear_ambiguous_state">
										<input type="number" name="ambiguous_product_id" min="1" placeholder="شناسه محصول مبدأ" required>
										<button type="submit" class="button">آزادکردن پس از بررسی مقصد</button>
									</form>
								</div>
							<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- بخش لاگ‌های سیستم -->
			<div class="taha-panel">
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
					<h2 style="margin:0; padding:0; border:none;">گزارش رویدادهای سیستم (System Logs)</h2>
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( 'taha_pub_nonce_action', 'taha_pub_nonce' ); ?>
						<input type="hidden" name="taha_pub_action" value="clear_logs">
						<button type="submit" class="button button-secondary">پاکسازی گزارش‌ها</button>
					</form>
				</div>
				<div id="taha-log-container" class="taha-log-viewer">
					<?php
					if ( empty( $logs ) ) {
						echo '<div style="color:#8c8f94;">هیچ رویدادی در سیستم ثبت نشده است.</div>';
					} else {
						foreach ( $logs as $log_line ) {
							$color = '#c3c4c7';
							if ( strpos( $log_line, '[ERROR]' ) !== false ) {
								$color = '#f86368';
							} elseif ( strpos( $log_line, '[SUCCESS]' ) !== false ) {
								$color = '#68de7c';
							} elseif ( strpos( $log_line, '[CAT_SYNC]' ) !== false ) {
								$color = '#72aee6';
							}
							echo '<div class="taha-log-row" style="color:' . esc_attr( $color ) . ';">' . esc_html( $log_line ) . '</div>';
						}
					}
					?>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var ajaxNonce = <?php echo wp_json_encode( $ajax_nonce ); ?>;
			var initialState = <?php echo wp_json_encode( $batch_state ); ?>;
			var pollTimer = null;
			var isRequestInFlight = false;

			function renderLogs(logs) {
				if (!logs || !logs.length) return;
				var html = '';
				for (var i = 0; i < logs.length; i++) {
					var line = logs[i];
					var color = '#c3c4c7';
					if (line.indexOf('[ERROR]') !== -1) color = '#f86368';
					else if (line.indexOf('[SUCCESS]') !== -1) color = '#68de7c';
					else if (line.indexOf('[CAT_SYNC]') !== -1) color = '#72aee6';
					html += '<div class="taha-log-row" style="color:' + color + ';"></div>';
				}
				var $container = $('#taha-log-container');
				$container.empty();
				for (var j = 0; j < logs.length; j++) {
					var rowLine = logs[j];
					var rowColor = '#c3c4c7';
					if (rowLine.indexOf('[ERROR]') !== -1) rowColor = '#f86368';
					else if (rowLine.indexOf('[SUCCESS]') !== -1) rowColor = '#68de7c';
					else if (rowLine.indexOf('[CAT_SYNC]') !== -1) rowColor = '#72aee6';
					$('<div class="taha-log-row"></div>').css('color', rowColor).text(rowLine).appendTo($container);
				}
			}

			function updateProgressUI(data) {
				if (!data) return;
				var $box = $('#taha-batch-progress-box');
				var $btnStart = $('#taha-btn-batch-repair');
				var $btnStop = $('#taha-btn-stop-batch');
				var $label = $('#taha-progress-label');
				var $percent = $('#taha-progress-percent');
				var $fill = $('#taha-progress-fill');
				var $detail = $('#taha-progress-detail');

				if (data.status !== 'idle' && data.total > 0) {
					$box.show();
				}

				$percent.text(data.percent + '%');
				$fill.css('width', data.percent + '%');
				$detail.text(data.last_message || '');

				if (data.logs && data.logs.length) {
					renderLogs(data.logs);
				}

				if (data.status === 'running') {
					$btnStart.prop('disabled', true).text('عملیات در پس‌زمینه سرور در حال اجراست...');
					$btnStop.show();
					$label.text('در حال همگام‌سازی در پس‌زمینه: ' + data.processed + ' از ' + data.total + ' محصول (شناسه فعلی: #' + data.current_id + ')');
					startPolling();
				} else {
					$btnStart.prop('disabled', false).text('شروع بررسی و بروزرسانی پس‌زمینه تمام محصولات و تصاویر');
					$btnStop.hide();
					if (data.status === 'completed') {
						$label.text('عملیات بروزرسانی با موفقیت به پایان رسید (' + data.success + ' موفق از ' + data.total + ' محصول).');
					} else if (data.status === 'stopped') {
						$label.text('عملیات توسط کاربر متوقف شد (' + data.processed + ' از ' + data.total + ' بررسی شد).');
					}
					stopPolling();
				}
			}

			function pollStatus() {
				if (isRequestInFlight) return;
				isRequestInFlight = true;

				$.post(ajaxurl, {
					action: 'taha_pub_ajax_action',
					sub_action: 'poll_batch_status',
					nonce: ajaxNonce
				}).done(function(res) {
					if (res && res.success && res.data) {
						updateProgressUI(res.data);
					}
				}).always(function() {
					isRequestInFlight = false;
				});
			}

			function startPolling() {
				if (!pollTimer) {
					pollTimer = setInterval(pollStatus, 2000);
				}
			}

			function stopPolling() {
				if (pollTimer) {
					clearInterval(pollTimer);
					pollTimer = null;
				}
			}

			// اگر هنگام لود یا رفرش صفحه، صف در حال اجرا بود، بلافاصله نوار پیشرفت را ادامه بده!
			if (initialState && initialState.status === 'running') {
				updateProgressUI(initialState);
				pollStatus();
			}

			// شروع صف پس‌زمینه
			$('#taha-btn-batch-repair').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('در حال ثبت صف در پس‌زمینه سرور...');

				$.post(ajaxurl, {
					action: 'taha_pub_ajax_action',
					sub_action: 'start_background_batch',
					nonce: ajaxNonce
				}).done(function(res) {
					if (res && res.success && res.data) {
						updateProgressUI(res.data);
						pollStatus();
					} else {
						$btn.prop('disabled', false).text('شروع بررسی و بروزرسانی پس‌زمینه تمام محصولات و تصاویر');
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('شروع بررسی و بروزرسانی پس‌زمینه تمام محصولات و تصاویر');
				});
			});

			// توقف صف پس‌زمینه
			$('#taha-btn-stop-batch').on('click', function() {
				var $stopBtn = $(this);
				$stopBtn.prop('disabled', true).text('در حال توقف...');

				$.post(ajaxurl, {
					action: 'taha_pub_ajax_action',
					sub_action: 'stop_background_batch',
					nonce: ajaxNonce
				}).done(function(res) {
					if (res && res.success && res.data) {
						updateProgressUI(res.data);
					}
				}).always(function() {
					$stopBtn.prop('disabled', false).text('توقف عملیات');
				});
			});
		});
		</script>
		<?php
	}
}

Taha_Pub_Sync_Engine::get_instance();
