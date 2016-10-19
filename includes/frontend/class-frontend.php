<?php

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists('WC_NFe_FrontEnd') ) :

/**
 * WooCommerce NFe WC_NFe_FrontEnd Class.
 *
 * @author   NFe.io
 * @package  WooCommerce_NFe/Class/WC_NFe_FrontEnd
 * @version  1.0.4
 */
class WC_NFe_FrontEnd {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Filters
		add_filter( 'woocommerce_my_account_my_orders_columns', 			 array( $this, 'nfe_column' ) );
		add_filter( 'woocommerce_my_account_my_address_description', 		 array( $this, 'account_desc' ) );

		// Actions
		add_action( 'woocommerce_my_account_my_orders_column_sales-receipt', array( $this, 'column_content' ) );
		add_action( 'woocommerce_order_details_after_order_table', 			 array( $this, 'column_content' ) );
		add_action( 'woocommerce_before_edit_address_form_billing', 		 array( $this, 'billing_notice' ) );
	}

	/**
	 * Notice added on the WooCommerce edit-address page
	 *
	 * @return string
	 */
	public function billing_notice() {
		if ( nfe_get_field('nfe_enable') == 'yes' ) {
			echo '<div class="woocommerce-message">' . __( 'The following address will <strong>also</strong> be used when issuing a NFe Sales Receipt.', 'woo-nfe' ) . '</div>';
		}
	}

	/**
	 * Notice added in the My Account page
	 *
	 * @return string
	 */
	public function account_desc() {
		return __( 'The following address(es) will be used on the checkout page by default and also when issuing a NFe sales receipt.', 'woo-nfe' );
	}

	/**
     * NFe Column Header on Recent Orders
     *
     * @return array
     */
	public function nfe_column( $columns ) {
		$new_column = array();

        foreach ( $columns as $column_name => $column_info ) {
            $new_columns[ $column_name ] = $column_info;

            if ( 'order-total' == $column_name ) {
                $new_columns['sales-receipt'] = __( 'Sales Receipt', 'woo-nfe' );
            }
        }

        return $new_columns;
	}

	/**
     * NFe Sales Receipt Column Content on Recent Orders
     *
     * @return string
     */
    public function column_content( $order ) {
        $order_id   = $order->id;
        $nfe 		= get_post_meta( $order_id, 'nfe_issued', true );
        $actions 	= array();
        $status     = array( 'PullFromCityHall', 'WaitingCalculateTaxes', 'WaitingDefineRpsNumber' );
				$issue_when = nfe_get_field('issue_when');
				$issue_when_status = nfe_get_field('issue_when_status');

        if ( nfe_get_field('nfe_enable') == 'yes' && $order->has_status( 'completed' ) ) {
            if ( $nfe && $nfe['status'] == 'Cancelled' ) {
                $actions['woo_nfe_cancelled'] = array(
                    'url'       => '#',
                    'name'      => __( 'NFe Cancelled', 'woo-nfe' ),
                    'action'    => 'woo_nfe_cancelled'
                );
            }
            else if ( $nfe && in_array( $nfe['status'], $status ) ) {
                $actions['woo_nfe_issuing'] = array(
                    'url'       => '#',
                    'name'      => __( 'Issuing NFe', 'woo-nfe' ),
                    'action'    => 'woo_nfe_issuing'
                );
            }
            else {
                if ( nfe_order_address_filled( $order_id ) ) {
                    $actions['woo_nfe_pending_address'] = array(
                        'url'       => esc_url( wc_get_endpoint_url( 'edit-address' ) ),
                        'name'      => __( 'Pending Address', 'woo-nfe' ),
                        'action'    => 'woo_nfe_pending_address'
                    );
                }
                else {
                    if ( $nfe && $nfe['id'] ) {
                        $actions['woo_nfe_download'] = array(
                            'url'       => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_nfe_download&order_id=' . $order->id ), 'woo_nfe_download' ),
                            'name'      => __( 'Download NFe', 'woo-nfe' ),
                            'action'    => 'woo_nfe_download'
                        );
                    }
                    else {
                        if ( nfe_get_field('issue_past_notes') == 'yes' ) {
                            if ( nfe_issue_past_orders( $order ) && empty( $nfe['id'] ) ) {
                                $actions['woo_nfe_issue'] = array(
                                    'url'       => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_nfe_issue&order_id=' . $order->id ), 'woo_nfe_issue' ),
                                    'name'      => __( 'Issue NFe', 'woo-nfe' ),
                                    'action'    => 'woo_nfe_issue'
                                );
                            }
                            else {
                                $actions['woo_nfe_expired'] = array(
                                    'url'       => '#',
                                    'name'      => __( 'Issue Expired', 'woo-nfe' ),
                                    'action'    => 'woo_nfe_expired'
                                );
                            }
                        }
                        else if ( $issue_when == "manual"	|| $issue_when_status == $order->post_status ) {
                            $actions['woo_nfe_issue'] = array(
                                'url'       => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_nfe_issue&order_id=' . $order->id ), 'woo_nfe_issue' ),
                                'name'      => __( 'Issue NFe', 'woo-nfe' ),
                                'action'    => 'woo_nfe_issue'
                            );
                        }
                    }
                }
            }
        }

        if ( nfe_get_field('nfe_enable') == 'no' && current_user_can('manage_woocommerce') ) {
            $actions['woo_nfe_tab'] = array(
                'url'       => WOOCOMMERCE_NFE_SETTINGS_URL,
                'name'      => __( 'Enable NFe', 'woo-nfe' ),
                'action'    => 'woo_nfe_tab'
            );
        }

        foreach ( $actions as $action ) {
            printf( '<a class="button view %s" href="%s" data-tip="%s">%s</a>',
                esc_attr( $action['action'] ),
                esc_url( $action['url'] ),
                esc_attr( $action['name'] ),
                esc_attr( $action['name'] )
            );
        }
    }
}

return new WC_NFe_FrontEnd();

endif;

// That's it! =)
