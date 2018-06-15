<?php
/**
 * Payments Query
 *
 * @package     EDD
 * @subpackage  Payments
 * @copyright   Copyright (c) 2018, Easy Digital Downloads, LLC
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.8
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * EDD_Payments_Query Class.
 *
 * This class is for retrieving payments data.
 *
 * Payments can be retrieved for date ranges and pre-defined periods.
 *
 * @since 1.8
 * @since 3.0 Updated to use the new query classes and custom tables.
 */
class EDD_Payments_Query extends EDD_Stats {

	/**
	 * The args to pass to the edd_get_payments() query
	 *
	 * @var array
	 * @since 1.8
	 */
	public $args = array();

	/**
	 * The args as they came into the class.
	 *
	 * @var array
	 * @since 2.7.2
	 */
	public $initial_args = array();

	/**
	 * The payments found based on the criteria set
	 *
	 * @var array
	 * @since 1.8
	 */
	public $payments = array();

	/**
	 * Default query arguments.
	 *
	 * Not all of these are valid arguments that can be passed to WP_Query. The ones that are not, are modified before
	 * the query is run to convert them to the proper syntax.
	 *
	 * @since 1.8
	 * @since 3.0 Updated to use the new query classes and custom tables.
	 *
	 * @param array $args The array of arguments that can be passed in and used for setting up this payment query.
	 */
	public function __construct( $args = array() ) {
		$defaults = array(
			'output'          => 'payments', // Use 'posts' to get standard post objects
			'post_type'       => array( 'edd_payment' ),
			'start_date'      => false,
			'end_date'        => false,
			'number'          => 20,
			'page'            => null,
			'orderby'         => 'ID',
			'order'           => 'DESC',
			'user'            => null,
			'customer'        => null,
			'status'          => edd_get_payment_status_keys(),
			'meta_key'        => null,
			'year'            => null,
			'month'           => null,
			'day'             => null,
			's'               => null,
			'search_in_notes' => false,
			'children'        => false,
			'fields'          => null,
			'download'        => null,
			'gateway'         => null,
			'post__in'        => null,
		);

		// We need to store an array of the args used to instantiate the class, so that we can use it in later hooks.
		$this->args = $this->initial_args = wp_parse_args( $args, $defaults );
	}

	/**
	 * Set a query variable.
	 *
	 * @since 1.8
	 */
	public function __set( $query_var, $value ) {
		if ( in_array( $query_var, array( 'meta_query', 'tax_query' ), true ) ) {
			$this->args[ $query_var ][] = $value;
		} else {
			$this->args[ $query_var ] = $value;
		}
	}

	/**
	 * Unset a query variable.
	 *
	 * @since 1.8
	 */
	public function __unset( $query_var ) {
		unset( $this->args[ $query_var ] );
	}

	/**
	 * Retrieve payments.
	 *
	 * The query can be modified in two ways; either the action before the
	 * query is run, or the filter on the arguments (existing mainly for backwards
	 * compatibility).
	 *
	 * @since 1.8
	 * @since 3.0 Updated to use the new query classes and custom tables.
	 *
	 * @return EDD_Payment[]
	 */
	public function get_payments() {

		// Modify the query/query arguments before we retrieve payments.
		$this->date_filter_pre();
		$this->orderby();
		$this->status();
		$this->month();
		$this->per_page();
		$this->page();
		$this->user();
		$this->customer();
		$this->search();
		$this->gateway();
		$this->mode();
		$this->children();
		$this->download();
		$this->post__in();

		do_action( 'edd_pre_get_payments', $this );

		$should_out_wp_post_objects = false;

		if ( 'posts' === $this->args['output'] ) {
			$should_out_wp_post_objects = true;
		}

		$this->remap_args();

		$orders = edd_get_orders( $this->args );

		if ( $should_out_wp_post_objects ) {
			// TODO: We need to return WP_Post objects here for backwards compatibility...
		}

		foreach ( $orders as $order ) {
			/** @var $order EDD\Orders\Order */

			$payment = edd_get_payment( $order->id );

			if ( edd_get_option( 'enable_sequential' ) ) {
				// Backwards compatibility, needs to set `payment_number` attribute
				$payment->payment_number = $payment->number;
			}

			$this->payments[] = apply_filters( 'edd_payment', $payment, $order->id, $this );
		}

		do_action( 'edd_post_get_payments', $this );

		return $this->payments;
	}

	/**
	 * If querying a specific date, add the proper filters.
	 *
	 * @since 1.8
	 */
	public function date_filter_pre() {
		if ( ! ( $this->args['start_date'] || $this->args['end_date'] ) ) {
			return;
		}

		$this->setup_dates( $this->args['start_date'], $this->args['end_date'] );
	}

	/**
	 * Post Status
	 *
	 * @since 1.8
	 */
	public function status() {
		if ( ! isset( $this->args['status'] ) ) {
			return;
		}

		$this->__set( 'post_status', $this->args['status'] );
		$this->__unset( 'status' );
	}

	/**
	 * Current Page
	 *
	 * @since 1.8
	 */
	public function page() {
		if ( ! isset( $this->args['page'] ) ) {
			return;
		}

		$this->__set( 'paged', $this->args['page'] );
		$this->__unset( 'page' );
	}

	/**
	 * Posts Per Page
	 *
	 * @since 1.8
	 */
	public function per_page() {
		if ( ! isset( $this->args['number'] ) ) {
			return;
		}

		if ( - 1 === $this->args['number'] ) {
			$this->__set( 'nopaging', true );
		} else {
			$this->__set( 'posts_per_page', $this->args['number'] );
		}

		$this->__unset( 'number' );
	}

	/**
	 * Current Month
	 *
	 * @since 1.8
	 */
	public function month() {
		if ( ! isset( $this->args['month'] ) ) {
			return;
		}

		$this->__set( 'monthnum', $this->args['month'] );
		$this->__unset( 'month' );
	}

	/**
	 * Order by
	 *
	 * @since 1.8
	 */
	public function orderby() {
		switch ( $this->args['orderby'] ) {
			case 'amount':
				$this->__set( 'orderby', 'meta_value_num' );
				$this->__set( 'meta_key', '_edd_payment_total' );
				break;
			default:
				$this->__set( 'orderby', $this->args['orderby'] );
				break;
		}
	}

	/**
	 * Specific User
	 *
	 * @since 1.8
	 */
	public function user() {
		if ( is_null( $this->args['user'] ) ) {
			return;
		}

		if ( is_numeric( $this->args['user'] ) ) {
			$user_key = '_edd_payment_user_id';
		} else {
			$user_key = '_edd_payment_user_email';
		}

		$this->__set( 'meta_query', array(
			'key'   => $user_key,
			'value' => $this->args['user'],
		) );
	}

	/**
	 * Specific customer id
	 *
	 * @since 2.6
	 */
	public function customer() {
		if ( is_null( $this->args['customer'] ) || ! is_numeric( $this->args['customer'] ) ) {
			return;
		}

		$this->__set( 'meta_query', array(
			'key'   => '_edd_payment_customer_id',
			'value' => (int) $this->args['customer'],
		) );
	}

	/**
	 * Specific gateway
	 *
	 * @since 2.8
	 */
	public function gateway() {
		if ( is_null( $this->args['gateway'] ) ) {
			return;
		}

		$this->__set( 'meta_query', array(
			'key'   => '_edd_payment_gateway',
			'value' => $this->args['gateway'],
		) );
	}

	/**
	 * Specific payments
	 *
	 * @since 2.8.7
	 */
	public function post__in() {
		if ( is_null( $this->args['post__in'] ) ) {
			return;
		}

		$this->__set( 'post__in', $this->args['post__in'] );
	}

	/**
	 * Search
	 *
	 * @since 1.8
	 */
	public function search() {
		if ( ! isset( $this->args['s'] ) ) {
			return;
		}

		$search = trim( $this->args['s'] );

		if ( empty( $search ) ) {
			return;
		}

		$is_email = is_email( $search ) || strpos( $search, '@' ) !== false;
		$is_user  = strpos( $search, strtolower( 'user:' ) ) !== false;

		if ( ! empty( $this->args['search_in_notes'] ) ) {
			$notes = edd_get_payment_notes( 0, $search );

			if ( ! empty( $notes ) ) {
				$payment_ids = wp_list_pluck( (array) $notes, 'comment_post_ID' );

				$this->__set( 'post__in', $payment_ids );
			}

			$this->__unset( 's' );
		} elseif ( $is_email || 32 === strlen( $search ) ) {
			$key         = $is_email ? '_edd_payment_user_email' : '_edd_payment_purchase_key';
			$search_meta = array(
				'key'     => $key,
				'value'   => $search,
				'compare' => 'LIKE',
			);

			$this->__set( 'meta_query', $search_meta );
			$this->__unset( 's' );
		} elseif ( $is_user ) {
			$search_meta = array(
				'key'   => '_edd_payment_user_id',
				'value' => trim( str_replace( 'user:', '', strtolower( $search ) ) ),
			);

			$this->__set( 'meta_query', $search_meta );

			if ( edd_get_option( 'enable_sequential' ) ) {
				$search_meta = array(
					'key'     => '_edd_payment_number',
					'value'   => $search,
					'compare' => 'LIKE',
				);

				$this->__set( 'meta_query', $search_meta );

				$this->args['meta_query']['relation'] = 'OR';
			}

			$this->__unset( 's' );
		} elseif ( edd_get_option( 'enable_sequential' ) && ( false !== strpos( $search, edd_get_option( 'sequential_prefix' ) ) || false !== strpos( $search, edd_get_option( 'sequential_postfix' ) ) ) ) {
			$search_meta = array(
				'key'     => '_edd_payment_number',
				'value'   => $search,
				'compare' => 'LIKE',
			);

			$this->__set( 'meta_query', $search_meta );
			$this->__unset( 's' );
		} elseif ( is_numeric( $search ) ) {
			$post = get_post( $search );

			if ( is_object( $post ) && 'edd_payment' === $post->post_type ) {
				$arr   = array();
				$arr[] = $search;
				$this->__set( 'post__in', $arr );
				$this->__unset( 's' );
			}

			if ( edd_get_option( 'enable_sequential' ) ) {
				$search_meta = array(
					'key'     => '_edd_payment_number',
					'value'   => $search,
					'compare' => 'LIKE',
				);

				$this->__set( 'meta_query', $search_meta );
				$this->__unset( 's' );
			}
		} elseif ( '#' === substr( $search, 0, 1 ) ) {
			$search = str_replace( '#:', '', $search );
			$search = str_replace( '#', '', $search );
			$this->__set( 'download', $search );
			$this->__unset( 's' );
		} elseif ( 0 === strpos( $search, 'discount:' ) ) {
			$search = trim( str_replace( 'discount:', '', $search ) );
			$search = 'discount.*' . $search;

			$search_meta = array(
				'key'     => '_edd_payment_meta',
				'value'   => $search,
				'compare' => 'REGEXP',
			);

			$this->__set( 'meta_query', $search_meta );
			$this->__unset( 's' );
		} else {
			$this->__set( 's', $search );
		}
	}

	/**
	 * Payment Mode
	 *
	 * @since 1.8
	 */
	public function mode() {
		if ( empty( $this->args['mode'] ) || 'all' === $this->args['mode'] ) {
			$this->__unset( 'mode' );

			return;
		}

		$this->__set( 'meta_query', array(
			'key'   => '_edd_payment_mode',
			'value' => $this->args['mode'],
		) );
	}

	/**
	 * Children
	 *
	 * @since 1.8
	 */
	public function children() {
		if ( empty( $this->args['children'] ) ) {
			$this->__set( 'post_parent', 0 );
		}

		$this->__unset( 'children' );
	}

	/**
	 * Specific Download
	 *
	 * @since 1.8
	 */
	public function download() {
		if ( empty( $this->args['download'] ) ) {
			return;
		}

		$order_ids = array();

		if ( is_array( $this->args['download'] ) ) {
			$orders = edd_get_order_items( array(
				'product_id__in' => (array) $this->args['download'],
			) );

			foreach ( $orders as $order ) {
				/** @var $order EDD\Orders\Order */
				$order_ids[] = $order->id;
			}
		} else {
			$orders = edd_get_order_items( array(
				'product_id' => $this->args['download'],
			) );

			foreach ( $orders as $order ) {
				/** @var $order EDD\Orders\Order */
				$order_ids[] = $order->id;
			}
		}

		$this->args['id__in'] = $order_ids;

		$this->__unset( 'download' );
	}

	/**
	 * As of EDD 3.0, we have introduced new query classes and custom tables so we need to remap the arguments so we can
	 * pass them to the new query classes.
	 *
	 * @since  3.0
	 * @access private
	 */
	private function remap_args() {
		$arguments = array();

		if ( $this->args['start_date'] ) {
			$arguments['date_created_query']['after'] = array(
				'year'  => date( 'Y', $this->start_date ),
				'month' => date( 'm', $this->start_date ),
				'day'   => date( 'd', $this->start_date ),
			);

			$arguments['date_created_query']['inclusive'] = true;
		}

		if ( $this->args['end_date'] ) {
			$arguments['date_created_query']['before'] = array(
				'year'  => date( 'Y', $this->end_date ),
				'month' => date( 'm', $this->end_date ),
				'day'   => date( 'd', $this->end_date ),
			);

			$arguments['date_created_query']['inclusive'] = true;
		}

		$arguments['number'] = isset( $this->args['posts_per_page'] )
			? $this->args['posts_per_page']
			: 20;

		if ( isset( $this->args['nopaging'] ) && true === $this->args['nopaging'] ) {
			unset( $arguments['number'] );
		}

		if ( isset( $this->args['post_status'] ) ) {
			$arguments['status'] = $this->args['post_status'];
		}

		switch ( $this->args['orderby'] ) {
			case 'amount':
				$arguments['orderby'] = 'total';
				break;
			case 'ID':
			case 'title':
			case 'post_title':
			case 'author':
			case 'post_author':
			case 'type':
			case 'post_type':
				$arguments['orderby'] = 'id';
				break;
			case 'date':
			case 'post_date':
				$arguments['orderby'] = 'date_created';
				break;
			case 'modified':
			case 'post_modified':
				$arguments['orderby'] = 'date_modified';
				break;
			case 'parent':
			case 'post_parent':
				$arguments['orderby'] = 'parent';
				break;
			case 'post__in':
				$arguments['orderby'] = 'id__in';
				break;
			case 'post_parent__in':
				$arguments['orderby'] = 'parent__in';
				break;
			default:
				$arguments['orderby'] = 'id';
				break;
		}

		if ( ! is_null( $this->args['user'] ) ) {
			$argument_key = is_numeric( $this->args['user'] )
				? 'user_id'
				: 'email';

			$arguments[ $argument_key ] = $this->args['user'];
		}

		if ( ! is_null( $this->args['customer'] ) && is_numeric( $this->args['customer'] ) ) {
			$arguments['customer_id'] = (int) $this->args['customer'];
		}

		if ( ! is_null( $this->args['gateway'] ) ) {
			$arguments['gateway'] = $this->args['gateway'];
		}

		if ( ! is_null( $this->args['post__in'] ) ) {
			$arguments['id__in'] = $this->args['post__in'];
		}

		if ( ! empty( $this->args['mode'] ) && 'all' !== $this->args['mode'] ) {
			$arguments['mode'] = $this->args['mode'];
		}

		if ( ! empty( $this->args['post_parent'] ) ) {
			$this->args['parent'] = $this->args['post_parent'];
		}

		if ( isset( $this->args['paged'] ) && isset( $this->args['number'] ) ) {
			$arguments['offset'] = ( $this->args['paged'] * $this->args['number'] ) - $this->args['number'];
		}

		$this->args = $arguments;
	}
}
