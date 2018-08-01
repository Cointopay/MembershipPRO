<?php
/*
Plugin Name: Cointopay Gateway for Paid Memberships Pro
Description: Cointopay payment gateway for paid memberships pro
Version: 1.0
*/

define("PMPRO_COINTOPAYGATEWAY_DIR", dirname(__FILE__));
add_action('init', array('PMProGateway_cointopay', 'init'));

	/**
	 * PMProGateway_gatewayname Class
	 *
	 * Handles cointopay integration.
	 *
	 */
	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

	if (!is_plugin_active('paid-memberships-pro/paid-memberships-pro.php')) {
	
		add_action('admin_notices', 'cointopay_notice_memberships_pro');
	    deactivate_plugins( plugin_basename( __FILE__ ) );
		return;
	
	}
	function cointopay_notice_memberships_pro() {
	 echo '<div id="message" class="error fade"><p style="line-height: 150%">';
	
		_e('<strong>Cointopay Gateway for Paid Memberships Pro</strong></a> requires the Paid Memberships Pro plugin to be activated. Please <a href="https://wordpress.org/plugins/paid-memberships-pro/‎">install / activate Paid Memberships Pro</a> first.', 'PMProGateway_cointopay');
	
		echo '</p></div>';
	}
//load payment gateway class
require_once(PMPRO_COINTOPAYGATEWAY_DIR . "/classes/class.pmprogateway_cointopay.php");