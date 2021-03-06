<?php
/**
 * Y4sent setup
 *
 * @package Y4sent
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Main Y4sent Class.
 *
 * @class Y4sent
 */
final class Y4sent {

	/**
	 * Y4sent version.
	 *
	 * @var string
	 */
	public $version = '1.0.0';

	/**
	 * Template path.
	 *
	 * @var string
	 */
	public $template_base;

	/**
	 * Order steps count, total and actives.
	 *
	 * @var array
	 */
	private $steps;

	/**
	 * Constructor for class. Hooks in methods.
	 */
	public function __construct() {
		// Set up localisation.
		$this->load_plugin_textdomain();

		$this->define_constants();

		// @codingStandardsIgnoreStart
		add_filter(
			'woocommerce_register_shop_order_post_statuses',
			array( $this, 'register_order_status' )
		);
		add_filter(
			'wc_order_statuses',
			array( $this, 'get_order_status' )
		);
		// @codingStandardsIgnoreEnd

		// Default template base if not declared in child constructor.
		if ( is_null( $this->template_base ) ) {
			$this->template_base = $this->plugin_path() . '/templates/';
		}

		// @codingStandardsIgnoreStart
		add_filter(
			'woocommerce_email_classes',
			array( $this, 'email_class' )
		);
		add_filter(
			'woocommerce_locate_core_template',
			array( $this, 'template_file' ),
			10,
			4
		);
		add_filter(
			'woocommerce_email_actions',
			array( $this, 'email_action' )
		);
		add_action(
			'woocommerce_order_details_before_order_table',
			array( $this, 'order_progress_in_details' )
		);
		// @codingStandardsIgnoreEnd

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'load_style' ) );

		self::shortcode();
	}

	/**
	 * Define Y4SENT Constants.
	 */
	private function define_constants() {
		$this->define( 'Y4SENT_VERSION', $this->version );
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param string      $name  Constant name.
	 * @param string|bool $value Constant value.
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Get the plugin path.
	 *
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( Y4SENT_PLUGIN_FILE ) );
	}

	/**
	 * Verify if WooCommerce plugin is active.
	 *
	 * @return bool
	 */
	public static function is_wc_active() {
		$check = false;

		$active_plugins = (array) get_option( 'active_plugins', array() );

		/**
		 * Apply not WooCommerce prefixed filter.
		 */
		$active_plugins = apply_filters( 'active_plugins', $active_plugins ); // @codingStandardsIgnoreLine

		if ( in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) ) {
			$check = true;
		}

		return $check;
	}

	/**
	 * Load Localisation files.
	 *
	 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
	 *
	 * Locales found in:
	 *      - Y4SENT_DIR/languages/LOCALE.mo
	 *      - WP_LANG_DIR/y4sent/y4sent-LOCALE.mo
	 *      - WP_LANG_DIR/plugins/y4sent-LOCALE.mo
	 */
	public function load_plugin_textdomain() {
		$locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$locale = apply_filters( 'plugin_locale', $locale, 'y4sent' ); // @codingStandardsIgnoreLine

		unload_textdomain( 'y4sent' );
		load_textdomain( 'y4sent', dirname( Y4SENT_PLUGIN_FILE ) . '/languages/' . $locale . '.mo' );
		load_textdomain( 'y4sent', WP_LANG_DIR . '/y4sent/y4sent-' . $locale . '.mo' );
		load_plugin_textdomain( 'y4sent', false, plugin_basename( dirname( Y4SENT_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Add sent order status in register.
	 *
	 * @param array $order_statuses Shop orders statuses.
	 * @return array
	 */
	public function register_order_status( $order_statuses ) {
		$sent           = array(
			'wc-sent' => array(
				'label'                     => _x( 'Sent', 'Order status', 'y4sent' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Sent <span class="count">(%s)</span>', 'Sent <span class="count">(%s)</span>', 'y4sent' ),
			),
		);
		$before         = apply_filters( 'y4sent_sent_statuses_before', 'wc-completed' );
		$order_statuses = self::array_insert_before( $order_statuses, $before, $sent );

		return $order_statuses;
	}

	/**
	 * Add sent order status in getter.
	 *
	 * @param array $order_statuses Shop orders statuses.
	 * @return array
	 */
	public function get_order_status( $order_statuses ) {
		$sent           = array(
			'wc-sent' => _x( 'Sent', 'Order status', 'y4sent' ),
		);
		$before         = apply_filters( 'y4sent_sent_statuses_before', 'wc-completed' );
		$order_statuses = self::array_insert_before( $order_statuses, $before, $sent );
		return $order_statuses;
	}

	/**
	 * Init email class.
	 *
	 * @param array $emails E-mail classes.
	 * @return array
	 */
	public function email_class( $emails ) {
		$sent   = array(
			'Y4sended_Email_Customer_Sent_Order' => include 'emails/class-y4sent-email-customer-sent-order.php',
		);
		$before = apply_filters( 'y4sent_sent_emails_before', 'WC_Email_Customer_Completed_Order' );
		$emails = self::array_insert_before( $emails, $before, $sent );

		return $emails;
	}

	/**
	 * Change template file.
	 *
	 * @param string $template_file Full path of template file.
	 * @param string $template      Relative template file.
	 * @param string $template_base WooCommerce template path.
	 * @param string $id            ID of current template.
	 * @return string
	 */
	public function template_file( $template_file, $template, $template_base, $id ) {
		if ( 'customer_sent_order' === $id ) {
			$template_file = $this->template_base . $template;
		}
		return $template_file;
	}

	/**
	 * Insert array before an key.
	 *
	 * @param array  $array Array to receive element.
	 * @param string $key Key word or number after new one.
	 * @param array  $insert Array with your own keys to be inserted.
	 * @return array
	 */
	public static function array_insert_before( $array, $key, $insert ) {
		$pos   = array_search( $key, array_keys( $array ), true );
		$count = count( $array );
		if ( $key ) {
			$head = array_slice( $array, 0, $pos + 1 );
			if ( $count >= $pos ) {
				$tail  = array_slice( $array, $pos + 1, $count - 1, true );
				$array = array_merge( $head, $insert, $tail );
			} else {
				$array = array_merge( $head, $insert );
			}
		} else {
			$array = array_merge( $array, $insert );
		}
		return $array;
	}

	/**
	 * Add email action.
	 *
	 * @param array $email_actions Email actions.
	 * @return array
	 */
	public function email_action( $email_actions ) {
		$email_actions[] = 'woocommerce_order_status_sent';
		return $email_actions;
	}

	/**
	 * Get order steps.
	 *
	 * @param object $order Order object.
	 * @return array
	 */
	public function order_steps( $order ) {
		$steps = array(
			'placed'     => array(
				'label'   => _x( 'Placed', 'Order step', 'y4sent' ),
				'reached' => true,
			),
			'processing' => array(
				'label'   => _x( 'Processing', 'Order step', 'y4sent' ),
				'reached' => true,
			),
			'completed'  => array(
				'label'   => _x( 'Completed', 'Order step', 'y4sent' ),
				'reached' => false,
			),
			'sent'       => array(
				'label'   => _x( 'Sent', 'Order step', 'y4sent' ),
				'reached' => false,
			),
		);

		$completed_statuses = apply_filters( 'y4sent_completed_statuses', array( 'completed', 'sent' ) );
		if ( in_array( $order->get_status(), $completed_statuses, true ) ) {
			$steps['completed']['reached'] = true;
		}
		$sent_statuses = apply_filters( 'y4sent_sent_statuses', array( 'sent' ) );
		if ( in_array( $order->get_status(), $sent_statuses, true ) ) {
			$steps['sent']['reached'] = true;
		}
		$steps = apply_filters( 'y4sent_order_steps', $steps );
		return $steps;
	}

	/**
	 * Add order progress in details.
	 *
	 * @param object $order Order object.
	 */
	public function order_progress_in_details( $order ) {
		$order_steps = $this->order_steps( $order );

		self::steps_progress_style( $order_steps );

		wc_get_template(
			'order/progress.php',
			array(
				'order'       => $order,
				'order_steps' => $order_steps,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Print inline frontend style.
	 *
	 * @param array $order_steps Order steps data.
	 */
	public static function steps_progress_style( $order_steps ) {
		$actives = 0;
		foreach ( $order_steps as $step ) {
			if ( isset( $step['reached'] ) && $step['reached'] ) {
				$actives++;
			}
		}

		$fraction = 100 / ( count( $order_steps ) * 2 );

		$style  = '.y4sent-order-steps::before{';
		$style .= 'left:' . $fraction . '%;';
		$style .= 'width:' . $fraction * ( ( $actives * 2 ) - 2 ) . '%}';
		$style .= '.y4sent-order-steps::after{';
		$style .= 'left:' . $fraction . '%;';
		$style .= 'width:' . ( 100 - ( $fraction * 2 ) ) . '%}';

		printf( '<style type="text/css">%s</style>', esc_attr( $style ) );
	}

	/**
	 * Return asset URL.
	 *
	 * @param string $path Assets path.
	 * @return string
	 */
	private static function get_asset_url( $path ) {
		return apply_filters( 'y4sent_get_asset_url', plugins_url( $path, Y4SENT_PLUGIN_FILE ), $path );
	}

	/**
	 * Register/queue frontend style.
	 */
	public static function load_style() {
		wp_register_style( 'y4sent', self::get_asset_url( 'assets/css/y4sent.css' ), array(), Y4SENT_VERSION );

		if ( self::is_wc_active() ) {
			wp_enqueue_style( 'y4sent' );
		}
	}

	/**
	 * Init shortcode.
	 */
	public static function shortcode() {
		$shortcodes = array(
			'y4sent_last_order' => __CLASS__ . '::shortcode_last_order',
		);

		foreach ( $shortcodes as $shortcode => $function ) {
			add_shortcode( apply_filters( "{$shortcode}_shortcode_tag", $shortcode ), $function ); // @codingStandardsIgnoreLine
		}
	}

	/**
	 * Order progress shortcode.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_last_order( $atts ) {
		require_once dirname( __FILE__ ) . '/shortcodes/class-y4sent-shortcode-last-order.php';
		return WC_Shortcodes::shortcode_wrapper( array( 'Y4sent_Shortcode_Last_Order', 'output' ), $atts );
	}
}
