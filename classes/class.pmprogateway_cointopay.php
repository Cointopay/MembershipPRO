<?php
	//load classes init method
	
	require_once(dirname(__FILE__) . "/includes/cointopay/init.php");
    require_once(dirname(__FILE__) . "/includes/cointopay/version.php");
	class PMProGateway_cointopay extends PMProGateway
	{
		/**
		 * @var bool    Is the cointopay/PHP Library loaded
		 */
	    private static $is_loaded = false;
	    private static $cointopay_merchant_id;
	    private static $cointopay_security_code;
		/**
		 * cointopay Class Constructor
		 *
		 * @since 1.4
		 */
		function __construct($gateway = NULL)
		{
			$this->gateway = $gateway;
			$this->gateway_environment = pmpro_getOption("gateway_environment");
            self::$cointopay_merchant_id = pmpro_getOption("cointopay_merchant_id");
            $this->cointopay_security_code = pmpro_getOption("cointopay_security_code");			
			return $this->gateway;
		}

		
		/**
		 * Run on WP init
		 *
		 * @since 1.8
		 */
		static function init()
		{
			//make sure cointopay is a gateway option
			add_filter('pmpro_gateways', array('PMProGateway_cointopay', 'pmpro_gateways'));

			//add fields to payment settings
			add_filter('pmpro_payment_options', array('PMProGateway_cointopay', 'pmpro_payment_options'));
			add_filter('pmpro_payment_option_fields', array('PMProGateway_cointopay', 'pmpro_payment_option_fields'), 10, 2);

			//add some fields to edit user page (Updates)
			add_action('pmpro_after_membership_level_profile_fields', array('PMProGateway_cointopay', 'user_profile_fields'));
			add_action('profile_update', array('PMProGateway_cointopay', 'user_profile_fields_save'));

			//old global RE showing billing address or not

			add_filter('pmpro_required_billing_fields', array('PMProGateway_cointopay', 'pmpro_required_billing_fields'));
			
			//make sure we clean up subs we will be cancelling after checkout before processing
			add_action('pmpro_checkout_before_processing', array('PMProGateway_cointopay', 'pmpro_checkout_before_processing'));
			
			//updates cron
			add_action('pmpro_cron_cointopay_subscription_updates', array('PMProGateway_cointopay', 'pmpro_cron_cointopay_subscription_updates'));

			$default_gateway = pmpro_getOption('gateway');
			$current_gateway = pmpro_getGateway();

			if( ($default_gateway == "cointopay" || $current_gateway == "cointopay") )
			{
				add_action('pmpro_checkout_preheader', array('PMProGateway_cointopay', 'pmpro_checkout_preheader'));
				add_action('pmpro_billing_preheader', array('PMProGateway_cointopay', 'pmpro_checkout_preheader'));
				add_filter('pmpro_checkout_order', array('PMProGateway_cointopay', 'pmpro_checkout_order'));
				add_filter('pmpro_billing_order', array('PMProGateway_cointopay', 'pmpro_checkout_order'));
				add_filter('pmpro_include_billing_address_fields', array('PMProGateway_cointopay', 'pmpro_include_billing_address_fields'));
				add_filter('pmpro_include_cardtype_field', array('PMProGateway_cointopay', 'pmpro_include_billing_address_fields'));
				add_filter('pmpro_include_payment_information_fields', array('PMProGateway_cointopay', 'pmpro_include_payment_information_fields'));
				add_filter('pmpro_required_billing_fields', array('PMProGateway_cointopay', 'pmpro_required_billing_fields'));
				add_action('admin_menu',array('PMProGateway_cointopay','add_link_on_dashboard_side_bar'));
				add_filter('pmpro_checkout_before_change_membership_level', array('PMProGateway_cointopay', 'pmpro_checkout_before_change_membership_level_cointopay'), 10, 2);
			add_filter( 'pmpro_confirmation_message', array('PMProGateway_cointopay', 'pmpro_pmpro_PMProGateway_confirmation_message') );
			add_action( 'pmpro_before_send_to_cointopay',  array('PMProGateway_cointopay', 'pmpro_after_checkout_update_consent_cointopay', 10, 2));
				
			}
            add_action( 'init', array( 'PMProGateway_cointopay', 'pmpro_clear_saved_subscriptions' ) );
		}

		// add link to admin sidebar 
        static function add_link_on_dashboard_side_bar()
        {
        	add_menu_page( 'Cointopay Payment Gateway', 'Cointopay','manage_options','cointopay-payment-gatway', array('PMProGateway_cointopay','show_cointopay_payment_setting_page'),'dashicons-screenoptions', 10 );
        }
        static function show_cointopay_payment_setting_page()
        {
        	echo '<h1>Cointopay Payment Gateway Setting</h1>';
        }
        static function pmpro_required_billing_fields($fields)
		{
			unset($fields['bfirstname']);
			unset($fields['blastname']);
			unset($fields['baddress1']);
			unset($fields['bcity']);
			unset($fields['bstate']);
			unset($fields['bzipcode']);
			unset($fields['bphone']);
			unset($fields['bemail']);
			unset($fields['bcountry']);
			unset($fields['CardType']);
			unset($fields['AccountNumber']);
			unset($fields['ExpirationMonth']);
			unset($fields['ExpirationYear']);
			unset($fields['CVV']);
			
			return $fields;
		}
		/**
		 * Clear any saved (preserved) subscription IDs that should have been processed and are now timed out.
		 */
		public static function pmpro_clear_saved_subscriptions() {
			
		    if ( ! is_user_logged_in() ) {
		        return;
		    }
		    
		    global $current_user;
		    $preserve = get_user_meta( $current_user->ID, 'pmpro_cointopay_dont_cancel', true );
			
			// Clean up the subscription timeout values (if applicable)
		    if ( !empty( $preserve ) ) {
			    
			    foreach ( $preserve as $sub_id => $timestamp ) {
			        
			        // Make sure the ID has "timed out" (more than 3 days since it was last updated/added.
				    if ( intval( $timestamp ) >= ( current_time( 'timestamp' ) + ( 3 * DAY_IN_SECONDS ) ) ) {
					    unset( $preserve[ $sub_id ] );
				    }
			    }
			    
			    update_user_meta( $current_user->ID, 'pmpro_cointopay_dont_cancel', $preserve );
		    }
		}

		/**
		 * Make sure cointopay is in the gateways list
		 *
		 * @since 1.8
		 */
		static function pmpro_gateways($gateways)
		{
			if(empty($gateways['cointopay']))
				$gateways['cointopay'] = __('Cointopay', 'paid-memberships-pro' );

			return $gateways;
		}

		/**
		 * Get a list of payment options that the cointopay gateway needs/supports.
		 *
		 * @since 1.8
		 */
		static function getGatewayOptions()
		{
			$options = array(
				'sslseal',
				'nuclear_HTTPS',
				'gateway_environment',
				'cointopay_security_code',
				'cointopay_merchant_id',
				'cointopay_billingaddress',
				'currency',
				'use_ssl',
				'tax_state',
				'tax_rate',
				'accepted_credit_cards'
			);

			return $options;
		}

		/**
		 * Set payment options for payment settings page.
		 *
		 * @since 1.8
		 */
		static function pmpro_payment_options($options)
		{			
			//get cointopay options
			$cointopay_options = self::getGatewayOptions();

			//merge with others.
			$options = array_merge($cointopay_options, $options);

			return $options;
		}				

		/**
		 * Display fields for cointopay options.
		 *
		 * @since 1.8
		 */
		static function pmpro_payment_option_fields($values, $gateway)
		{
		?>
		<tr class="pmpro_settings_divider gateway gateway_cointopay" <?php if($gateway != "cointopay") { ?>style="display: none;"<?php } ?>>
			<td colspan="2">
				<?php _e('cointopay Settings', 'paid-memberships-pro' ); ?>
			</td>
		</tr>
		<tr class="gateway gateway_cointopay" <?php if($gateway != "cointopay") { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="cointopay_merchant_id"><?php _e('Merchant ID', 'paid-memberships-pro' );?>:</label>
			</th>
			<td>
				<input type="text" id="cointopay_merchant_id" name="cointopay_merchant_id" size="60" value="<?php echo esc_attr($values['cointopay_merchant_id'])?>" />
				
			</td>
		</tr>		
		<tr class="gateway gateway_cointopay" <?php if($gateway != "cointopay") { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="cointopay_security_code"><?php _e('Security Code', 'paid-memberships-pro' );?>:</label>
			</th>
			<td>
				<input type="text" id="cointopay_security_code" name="cointopay_security_code" size="60" value="<?php echo esc_attr($values['cointopay_security_code'])?>" />
			</td>
		</tr>
		<tr class="gateway gateway_cointopay" <?php if($gateway != "cointopay") { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="cointopay_billingaddress"><?php _e('Show Billing Address Fields', 'paid-memberships-pro' );?>:</label>
			</th>
			<td>
				<select id="cointopay_billingaddress" name="cointopay_billingaddress">
					<option value="0" <?php if(empty($values['cointopay_billingaddress'])) { ?>selected="selected"<?php } ?>><?php _e('No', 'paid-memberships-pro' );?></option>
					<option value="1" <?php if(!empty($values['cointopay_billingaddress'])) { ?>selected="selected"<?php } ?>><?php _e('Yes', 'paid-memberships-pro' );?></option>
				</select>
				<small><?php _e("cointopay doesn't require billing address fields. Choose 'No' to hide them on the checkout page.<br /><strong>If No, make sure you disable address verification in the cointopay dashboard settings.</strong>", 'paid-memberships-pro' );?></small>
			</td>
		</tr>
		<tr class="gateway gateway_cointopay" <?php if($gateway != "cointopay") { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label><?php _e('Web Hook URL', 'paid-memberships-pro' );?>:</label>
			</th>
			<td>
				<p><?php _e('To fully integrate with cointopay, be sure to set your Web Hook URL to', 'paid-memberships-pro' );?> <pre><?php echo admin_url("admin-ajax.php") . "?action=cointopay_webhook";?></pre></p>
			</td>
		</tr>
		<?php
		}

		/**
		 * Code added to checkout preheader.
		 *
		 * @since 1.8
		 */
		static function pmpro_checkout_preheader()
		{
			global $gateway, $pmpro_level;
            $mid =  pmpro_getoption("cointopay_merchant_id");
            $sid =  pmpro_getoption("cointopay_security_code");
			$default_gateway = pmpro_getOption("gateway");
			
			if(($gateway == "cointopay" || $default_gateway == "cointopay") && !pmpro_isLevelFree($pmpro_level))
			{ 
			   if ( ! function_exists( 'pmpro_cointopay_javascript' ) ) {

					//stripe js code for checkout
					function pmpro_cointopay_javascript()
					{
					?>
						<script type="text/javascript">
							var tokenNum = 0;
							 var form$ = jQuery("#pmpro_form, .pmpro_form");
							// token contains id, last4, and card type
							var token = '758967596756';
							// insert the token into the form so it gets submitted to the server
							form$.append("<input type='hidden' name='cointopayToken" + tokenNum + "' value='" + token + "'/>");
							tokenNum++;
						</script>
					<?php
					}
				add_action("wp_head", "pmpro_cointopay_javascript");
				}
			}
		}
		
		static function payThroughCointopay($order)
		{
			$params = array(
				"authentication:1",
				'cache-control: no-cache',
				);
				$ch = curl_init();
				curl_setopt_array($ch, array(
				CURLOPT_URL => 'https://cointopay.com/MerchantAPI?Checkout=true',
				//CURLOPT_USERPWD => $this->apikey,
				CURLOPT_POSTFIELDS => 'SecurityCode=' .  pmpro_getOption("cointopay_security_code") . '&MerchantID=' . pmpro_getOption("cointopay_merchant_id") . '&Amount=' . number_format($order->InitialPayment, 2, '.', '') . '&AltCoinID=1&output=json&inputCurrency=USD&CustomerReferenceNr=' . $order->id.'&returnurl='.rawurlencode(esc_url(site_url().'/membership-confirmation/?level=')).$order->membership_id.'&transactionconfirmurl='.site_url('/?wc-api=Cointopay') .'&transactionfailurl='.rawurlencode(esc_url('www.cointopay.com')),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_HTTPHEADER => $params,
				CURLOPT_USERAGENT => 1,
				CURLOPT_HTTPAUTH => CURLAUTH_BASIC
				)
				);
				$redirect = curl_exec($ch);
				if($redirect){
					$results = json_decode($redirect);
					return array(
					'result' => 'success',
					'url' => $results->RedirectURL
					);
				}
		}

		/**
		 * Filtering orders at checkout.
		 *
		 * @since 1.8
		 */
		static function pmpro_checkout_order($morder)
		{
			return $morder;
		}

		/**
		 * Code to run after checkout
		 *
		 * @since 1.8
		 */
		static function pmpro_after_checkout($user_id, $morder)
		{
			global $gateway;

			if($gateway == "cointopay")
			{
				if(!empty($morder) && !empty($morder->Gateway) && !empty($morder->Gateway->customer) && !empty($morder->Gateway->customer->id))
				{
					update_user_meta($user_id, "pmpro_cointopay_customerid", $morder->Gateway->customer->id);
				}
			}
		}

		/**
		 * Check settings if billing address should be shown.
		 * @since 1.8
		 */
		static function pmpro_include_billing_address_fields($include)
		{
			//check settings RE showing billing address
			if(!pmpro_getOption("cointopay_billingaddress"))
				$include = false;

			return $include;
		}

		/**
		 * Use our own payment fields at checkout. (Remove the name attributes.)		
		 * @since 1.8
		 */
		static function pmpro_include_payment_information_fields($include)
		{
		
			?>
			<div id="pmpro_payment_information_fields" class="pmpro_checkout">
				<h3>
					<span class="pmpro_checkout-h3-name"><?php _e('Payment Information', 'paid-memberships-pro' );?></span>
					<span class="pmpro_checkout-h3-msg"> Pay Through Cointopay</span>
				</h3>
				
			</div> <!-- end pmpro_payment_information_fields -->
			<?php

			//don't include the default
			return false;
		}

		/**
		 * Fields shown on edit user page
		 *
		 * @since 1.8
		 */
		static function user_profile_fields($user)
		{
			global $wpdb, $current_user, $pmpro_currency_symbol;

			$cycles = array( __('Day(s)', 'paid-memberships-pro' ) => 'Day', __('Week(s)', 'paid-memberships-pro' ) => 'Week', __('Month(s)', 'paid-memberships-pro' ) => 'Month', __('Year(s)', 'paid-memberships-pro' ) => 'Year' );
			$current_year = date_i18n("Y");
			$current_month = date_i18n("m");

			//make sure the current user has privileges
			$membership_level_capability = apply_filters("pmpro_edit_member_capability", "manage_options");
			if(!current_user_can($membership_level_capability))
				return false;

			//more privelges they should have
			$show_membership_level = apply_filters("pmpro_profile_show_membership_level", true, $user);
			if(!$show_membership_level)
				return false;

			//check that user has a current subscription at cointopay
			$last_order = new MemberOrder();
			$last_order->getLastMemberOrder($user->ID);

			//assume no sub to start
			$sub = false;

			//check that gateway is cointopay
			if($last_order->gateway == "cointopay")
			{
				//is there a customer?
				$sub = $last_order->Gateway->getSubscription($last_order);
			}

			$customer_id = $user->pmpro_cointopay_customerid;

			if(empty($sub))
			{
				//make sure we delete cointopay updates
				update_user_meta($user->ID, "pmpro_cointopay_updates", array());

				//if the last order has a sub id, let the admin know there is no sub at cointopay
				if(!empty($last_order) && $last_order->gateway == "cointopay" && !empty($last_order->subscription_transaction_id) && strpos($last_order->subscription_transaction_id, "sub_") !== false)
				{
				?>
				<p><?php printf( __('%1$sNote:%2$s Subscription %3$s%4$s%5$s could not be found at cointopay. It may have been deleted.', 'paid-memberships-pro'), '<strong>', '</strong>', '<strong>', esc_attr($last_order->subscription_transaction_id), '</strong>' ); ?></p>
				<?php
				}
			}
			elseif ( true === self::$is_loaded )
			{
			?>
			<h3><?php _e("Subscription Updates", 'paid-memberships-pro' ); ?></h3>
			<p>
				<?php
					if(empty($_REQUEST['user_id']))
						_e("Subscription updates, allow you to change the member's subscription values at predefined times. Be sure to click Update Profile after making changes.", 'paid-memberships-pro' );
					else
						_e("Subscription updates, allow you to change the member's subscription values at predefined times. Be sure to click Update User after making changes.", 'paid-memberships-pro' );
				?>
			</p>
			<table class="form-table">
				<tr>
					<th><label for="membership_level"><?php _e("Update", 'paid-memberships-pro' ); ?></label></th>
					<td id="updates_td">
						<?php
							$old_updates = $user->pmpro_cointopay_updates;
							if(is_array($old_updates))
							{
								$updates = array_merge(
									array(array('template'=>true, 'when'=>'now', 'date_month'=>'', 'date_day'=>'', 'date_year'=>'', 'billing_amount'=>'', 'cycle_number'=>'', 'cycle_period'=>'Month')),
									$old_updates
								);
							}
							else
								$updates = array(array('template'=>true, 'when'=>'now', 'date_month'=>'', 'date_day'=>'', 'date_year'=>'', 'billing_amount'=>'', 'cycle_number'=>'', 'cycle_period'=>'Month'));

							foreach($updates as $update)
							{
							?>
							<div class="updates_update" <?php if(!empty($update['template'])) { ?>style="display: none;"<?php } ?>>
								<select class="updates_when" name="updates_when[]">
									<option value="now" <?php selected($update['when'], "now");?>>Now</option>
									<option value="payment" <?php selected($update['when'], "payment");?>>After Next Payment</option>
									<option value="date" <?php selected($update['when'], "date");?>>On Date</option>
								</select>
								<span class="updates_date" <?php if($update['when'] != "date") { ?>style="display: none;"<?php } ?>>
									<select name="updates_date_month[]">
										<?php
											for($i = 1; $i < 13; $i++)
											{
											?>
											<option value="<?php echo str_pad($i, 2, "0", STR_PAD_LEFT);?>" <?php if(!empty($update['date_month']) && $update['date_month'] == $i) { ?>selected="selected"<?php } ?>>
												<?php echo date_i18n("M", strtotime($i . "/1/" . $current_year));?>
											</option>
											<?php
											}
										?>
									</select>
									<input name="updates_date_day[]" type="text" size="2" value="<?php if(!empty($update['date_day'])) echo esc_attr($update['date_day']);?>" />
									<input name="updates_date_year[]" type="text" size="4" value="<?php if(!empty($update['date_year'])) echo esc_attr($update['date_year']);?>" />
								</span>
								<span class="updates_billing" <?php if($update['when'] == "now") { ?>style="display: none;"<?php } ?>>
									<?php echo $pmpro_currency_symbol?><input name="updates_billing_amount[]" type="text" size="10" value="<?php echo esc_attr($update['billing_amount']);?>" />
									<small><?php _e('per', 'paid-memberships-pro' );?></small>
									<input name="updates_cycle_number[]" type="text" size="5" value="<?php echo esc_attr($update['cycle_number']);?>" />
									<select name="updates_cycle_period[]">
									  <?php
										foreach ( $cycles as $name => $value ) {
										  echo "<option value='$value'";
										  if(!empty($update['cycle_period']) && $update['cycle_period'] == $value) echo " selected='selected'";
										  echo ">$name</option>";
										}
									  ?>
									</select>
								</span>
								<span>
									<a class="updates_remove" href="javascript:void(0);">Remove</a>
								</span>
							</div>
							<?php
							}
							?>
						<p><a id="updates_new_update" href="javascript:void(0);">+ New Update</a></p>
					</td>
				</tr>
			</table>
			<script>
				<!--
				jQuery(document).ready(function() {
					//function to update dropdowns/etc based on when field
					function updateSubscriptionUpdateFields(when)
					{
						if(jQuery(when).val() == 'date')
							jQuery(when).parent().children('.updates_date').show();
						else
							jQuery(when).parent().children('.updates_date').hide();

						if(jQuery(when).val() == 'no')
							jQuery(when).parent().children('.updates_billing').hide();
						else
							jQuery(when).parent().children('.updates_billing').show();
					}

					//and update on page load
					jQuery('.updates_when').each(function() { if(jQuery(this).parent().css('display') != 'none') updateSubscriptionUpdateFields(this); });

					//add a new update when clicking to
					var num_updates_divs = <?php echo count($updates);?>;
					jQuery('#updates_new_update').click(function() {
						//get updates
						updates = jQuery('.updates_update').toArray();

						//clone the first one
						new_div = jQuery(updates[0]).clone();

						//append
						new_div.insertBefore('#updates_new_update');

						//update events
						addUpdateEvents()

						//unhide it
						new_div.show();
						updateSubscriptionUpdateFields(new_div.children('.updates_when'));
					});

					function addUpdateEvents()
					{
						//update when when changes
						jQuery('.updates_when').change(function() {
							updateSubscriptionUpdateFields(this);
						});

						//remove updates when clicking
						jQuery('.updates_remove').click(function() {
							jQuery(this).parent().parent().remove();
						});
					}
					addUpdateEvents();
				});
			-->
			</script>
			<?php
			}
		}

		/**
		 * Process fields from the edit user page
		 *
		 * @since 1.8
		 */
		static function user_profile_fields_save($user_id)
		{
			global $wpdb;

			//check capabilities
			$membership_level_capability = apply_filters("pmpro_edit_member_capability", "manage_options");
			if(!current_user_can($membership_level_capability))
				return false;

			//make sure some value was passed
			if(!isset($_POST['updates_when']) || !is_array($_POST['updates_when']))
				return;

			//vars
			$updates = array();
			$next_on_date_update = "";

			//build array of updates (we skip the first because it's the template field for the JavaScript
			for($i = 1; $i < count($_POST['updates_when']); $i++)
			{
				$update = array();

				//all updates have these values
				$update['when'] = pmpro_sanitize_with_safelist($_POST['updates_when'][$i], array('now', 'payment', 'date'));
				$update['billing_amount'] = sanitize_text_field($_POST['updates_billing_amount'][$i]);
				$update['cycle_number'] = intval($_POST['updates_cycle_number'][$i]);
				$update['cycle_period'] = sanitize_text_field($_POST['updates_cycle_period'][$i]);

				//these values only for on date updates
				if($_POST['updates_when'][$i] == "date")
				{
					$update['date_month'] = str_pad(intval($_POST['updates_date_month'][$i]), 2, "0", STR_PAD_LEFT);
					$update['date_day'] = str_pad(intval($_POST['updates_date_day'][$i]), 2, "0", STR_PAD_LEFT);
					$update['date_year'] = intval($_POST['updates_date_year'][$i]);
				}

				//make sure the update is valid
				if(empty($update['cycle_number']))
					continue;

				//if when is now, update the subscription
				if($update['when'] == "now")
				{
					PMProGateway_cointopay::updateSubscription($update, $user_id);

					continue;
				}
				elseif($update['when'] == 'date')
				{
					if(!empty($next_on_date_update))
						$next_on_date_update = min($next_on_date_update, $update['date_year'] . "-" . $update['date_month'] . "-" . $update['date_day']);
					else
						$next_on_date_update = $update['date_year'] . "-" . $update['date_month'] . "-" . $update['date_day'];
				}

				//add to array
				$updates[] = $update;
			}

			//save in user meta
			update_user_meta($user_id, "pmpro_cointopay_updates", $updates);

			//save date of next on-date update to make it easier to query for these in cron job
			update_user_meta($user_id, "pmpro_cointopay_next_on_date_update", $next_on_date_update);
		}		
		
		/**
		 * Cron activation for subscription updates.
		 *
		 * @since 1.8
		 */
		static function pmpro_activation()
		{
			pmpro_maybe_schedule_event(time(), 'daily', 'pmpro_cron_cointopay_subscription_updates');
		}

		/**
		 * Cron deactivation for subscription updates.
		 *
		 * @since 1.8
		 */
		static function pmpro_deactivation()
		{
			wp_clear_scheduled_hook('pmpro_cron_cointopay_subscription_updates');
		}

		/**
		 * Cron job for subscription updates.
		 *
		 * @since 1.8
		 */
		static function pmpro_cron_cointopay_subscription_updates()
		{
			global $wpdb;

			//get all updates for today (or before today)
			$sqlQuery = "SELECT *
						 FROM $wpdb->usermeta
						 WHERE meta_key = 'pmpro_cointopay_next_on_date_update'
							AND meta_value IS NOT NULL
							AND meta_value <> ''
							AND meta_value < '" . date_i18n("Y-m-d", strtotime("+1 day", current_time('timestamp'))) . "'";
			$updates = $wpdb->get_results($sqlQuery);
			
			if(!empty($updates))
			{
				//loop through
				foreach($updates as $update)
				{
					//pull values from update
					$user_id = $update->user_id;

					$user = get_userdata($user_id);
					
					//if user is missing, delete the update info and continue
					if(empty($user) || empty($user->ID))
					{						
						delete_user_meta($user_id, "pmpro_cointopay_updates");
						delete_user_meta($user_id, "pmpro_cointopay_next_on_date_update");
					
						continue;
					}
					
					$user_updates = $user->pmpro_cointopay_updates;
					$next_on_date_update = "";					
					
					//loop through updates looking for updates happening today or earlier
					if(!empty($user_updates))
					{
						foreach($user_updates as $key => $ud)
						{
							if($ud['when'] == 'date' &&
							   $ud['date_year'] . "-" . $ud['date_month'] . "-" . $ud['date_day'] <= date_i18n("Y-m-d", current_time('timestamp') )
							)
							{
								PMProGateway_cointopay::updateSubscription($ud, $user_id);

								//remove update from list
								unset($user_updates[$key]);
							}
							elseif($ud['when'] == 'date')
							{
								//this is an on date update for the future, update the next on date update
								if(!empty($next_on_date_update))
									$next_on_date_update = min($next_on_date_update, $ud['date_year'] . "-" . $ud['date_month'] . "-" . $ud['date_day']);
								else
									$next_on_date_update = $ud['date_year'] . "-" . $ud['date_month'] . "-" . $ud['date_day'];
							}
						}
					}

					//save updates in case we removed some
					update_user_meta($user_id, "pmpro_cointopay_updates", $user_updates);

					//save date of next on-date update to make it easier to query for these in cron job
					update_user_meta($user_id, "pmpro_cointopay_next_on_date_update", $next_on_date_update);
				}
			}
		}
		
		/**
		 * Before processing a checkout, check for pending invoices we want to clean up.
		 * This prevents double billing issues in cases where cointopay has pending invoices
		 * because of an expired credit card/etc and a user checks out to renew their subscription
		 * instead of updating their billing information via the billing info page.
		 */
		static function pmpro_checkout_before_processing() {		
			global $wpdb, $current_user;

			//we're only worried about cases where the user is logged in
			if(!is_user_logged_in()){
				// echo $_REQUEST['username'];die();
			//save user fields for PayPal Express
				//get values from post
				if(isset($_REQUEST['username']))
					$username = trim(sanitize_text_field($_REQUEST['username']));
				else
					$username = "";
				if(isset($_REQUEST['password']))
					$password = sanitize_text_field($_REQUEST['password']);
				else
					$password = "";
				if(isset($_REQUEST['bemail']))
					$bemail = sanitize_email($_REQUEST['bemail']);
				else
					$bemail = "";

				//save to session
				$_SESSION['pmpro_signup_username'] = $username;
				$_SESSION['pmpro_signup_password'] = $password;
				$_SESSION['pmpro_signup_email'] = $bemail;

			if( !empty( $_REQUEST['tos'] ) ) {
				$tospost = get_post( pmpro_getOption( 'tospage' ) );
				$_SESSION['tos'] = array(
					'post_id' => $tospost->ID,
					'post_modified' => $tospost->post_modified,
				);
			}

			//can use this hook to save some other variables to the session
			do_action("pmpro_cointopay_session_vars");
		
			}
				return;
			
			//get user and membership level			
			$membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
			
			//no level, then probably no subscription at cointopay anymore
			if(empty($membership_level))
				return;
						
			/**
			 * Filter which levels to cancel at the gateway.
			 * MMPU will set this to all levels that are going to be cancelled during this checkout.
			 * Others may want to display this by add_filter('pmpro_cointopay_levels_to_cancel_before_checkout', __return_false);
			 */
			$levels_to_cancel = apply_filters('pmpro_cointopay_levels_to_cancel_before_checkout', array($membership_level->id), $current_user);
						
			foreach($levels_to_cancel as $level_to_cancel) {
				//get the last order for this user/level
				$last_order = new MemberOrder();
				$last_order->getLastMemberOrder($current_user->ID, 'success', $level_to_cancel, 'cointopay');
								
				//so let's cancel the user's susbcription
				if(!empty($last_order) && !empty($last_order->subscription_transaction_id)) {										
					$subscription = $last_order->Gateway->getSubscription($last_order);
					if(!empty($subscription)) {					
						$last_order->Gateway->cancelSubscriptionAtGateway($subscription, true);
						
						//cointopay was probably going to cancel this subscription 7 days past the payment failure (maybe just one hour, use a filter for sure)
						$memberships_users_row = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_memberships_users WHERE user_id = '" . $current_user->ID . "' AND membership_id = '" . $level_to_cancel . "' AND status = 'active' LIMIT 1");
												
						if(!empty($memberships_users_row) && (empty($memberships_users_row->enddate) || $memberships_users_row->enddate == '0000-00-00 00:00:00')) {
							/**
							 * Filter graced period days when canceling existing subscriptions at checkout.
							 *
							 * @since 1.9.4
							 *
							 * @param int $days Grace period defaults to 3 days
							 * @param object $membership Membership row from pmpro_memberships_users including membership_id, user_id, and enddate
							 */
							$days_grace = apply_filters('pmpro_cointopay_days_grace_when_canceling_existing_subscriptions_at_checkout', 3, $memberships_users_row);
							$new_enddate = date('Y-m-d H:i:s', current_time('timestamp')+3600*24*$days_grace);
							$wpdb->update( $wpdb->pmpro_memberships_users, array('enddate'=>$new_enddate), array('user_id'=>$current_user->ID, 'membership_id'=>$level_to_cancel, 'status'=>'active'), array('%s'), array('%d', '%d', '%s') );
						}						
					}
				}
			}			
		}

		/**
		 * Process checkout and decide if a charge and or subscribe is needed
		 *
		 * @since 1.4
		 */
		function process(&$order)
		{
			if(empty($order->code))
				$order->code = $order->getRandomCode();			
			//clean up a couple values
			$order->payment_type = "Cointopay";
			
			//just save, the user will go to cointopay.com to pay
			$order->status = "pending";	
			//print_r($order);	die();										
			$order->saveOrder();
			//$this->senToCointopay($order);
           // die();
			return true;
		}
        function senToCointopay($order)
        {
        	// redirect to cointopay API
            $result = self::payThroughCointopay($order);
         	$result['url'];
			if(!empty($result['url']))
			{
                header("location:".$result['url']." ");
                exit('not redirect');
			}
        }
		/**
		 * Make a one-time charge with cointopay
		 *
		 * @since 1.4
		 */
		function charge(&$order)
		{
			//charge logic here
			return true;
		}

		/**
		 * Get a cointopay customer object.
		 *
		 * If $this->customer is set, it returns it.
		 * It first checks if the order has a subscription_transaction_id. If so, that's the customer id.
		 * If not, it checks for a user_id on the order and searches for a customer id in the user meta.
		 * If a customer id is found, it checks for a customer through the cointopay API.
		 * If a customer is found and there is a cointopayToken on the order passed, it will update the customer.
		 * If no customer is found and there is a cointopayToken on the order passed, it will create a customer.
		 *
		 * @since 1.4
		 * @return cointopay_Customer|false
		 */
		function getCustomer(&$order = false, $force = false)
		{
			return true;
		}

		/**
		 * Get a cointopay subscription from a PMPro order
		 *
		 * @since 1.8
		 */
		function getSubscription(&$order)
		{
			global $wpdb;

			//no order?
			if(empty($order) || empty($order->code))
				return false;

			$result = $this->getCustomer($order, true);	//force so we don't get a cached sub for someone else

			//no customer?
			if(empty($result))
				return false;

			//is there a subscription transaction id pointing to a sub?
			if(!empty($order->subscription_transaction_id) && strpos($order->subscription_transaction_id, "sub_") !== false)
			{
				try
				{
					$sub = $this->customer->subscriptions->retrieve($order->subscription_transaction_id);
				}
				catch (Exception $e)
				{
					$order->error = __("Error getting subscription with cointopay:", 'paid-memberships-pro' ) . $e->getMessage();
					$order->shorterror = $order->error;
					return false;
				}

				return $sub;
			}
			
			//no subscriptions object in customer
			if(empty($this->customer->subscriptions))
				return false;

			//find subscription based on customer id and order/plan id
			$subscriptions = $this->customer->subscriptions->all();

			//no subscriptions
			if(empty($subscriptions) || empty($subscriptions->data))
				return false;

			//we really want to test against the order codes of all orders with the same subscription_transaction_id (customer id)
			$codes = $wpdb->get_col("SELECT code FROM $wpdb->pmpro_membership_orders WHERE user_id = '" . $order->user_id . "' AND subscription_transaction_id = '" . $order->subscription_transaction_id . "' AND status NOT IN('refunded', 'review', 'token', 'error')");

			//find the one for this order
			foreach($subscriptions->data as $sub)
			{
				if(in_array($sub->plan->id, $codes))
				{
					return $sub;
				}
			}

			//didn't find anything yet
			return false;
		}

		/**
		 * Create a new subscription with cointopay
		 *
		 * @since 1.4
		 */
		function subscribe(&$order, $checkout = true)
		{
			return true;
		}
		
		/**
		 * Helper method to save the subscription ID to make sure the membership doesn't get cancelled by the webhook
		 */
		static function ignoreCancelWebhookForThisSubscription($subscription_id, $user_id = NULL) {
			if(empty($user_id)) {
				global $current_user;
				$user_id = $current_user->ID;
			}
			
			$preserve = get_user_meta( $user_id, 'pmpro_cointopay_dont_cancel', true );
			
			// No previous values found, init the array
			if ( empty( $preserve ) ) {
				$preserve = array();
			}
			
			// Store or update the subscription ID timestamp (for cleanup)
			$preserve[$subscription_id] = current_time( 'timestamp' );

			update_user_meta( $user_id, 'pmpro_cointopay_dont_cancel', $preserve );
		}
			
		/**
		 * Helper method to process a cointopay subscription update
		 */
		static function updateSubscription($update, $user_id) {
			global $wpdb;
			
			//get level for user
			$user_level = pmpro_getMembershipLevelForUser($user_id);

			//get current plan at cointopay to get payment date
			$last_order = new MemberOrder();
			$last_order->getLastMemberOrder($user_id);
			$last_order->setGateway('cointopay');
			$last_order->Gateway->getCustomer($last_order);

			$subscription = $last_order->Gateway->getSubscription($last_order);

			if(!empty($subscription))
			{
				$end_timestamp = $subscription->current_period_end;
				
				//cancel the old subscription
				if(!$last_order->Gateway->cancelSubscriptionAtGateway($subscription, true))
				{
					//throw error and halt save
					if ( !function_exists( 'pmpro_cointopay_user_profile_fields_save_error' )) {
						//throw error and halt save
						function pmpro_cointopay_user_profile_fields_save_error( $errors, $update, $user ) {
							$errors->add( 'pmpro_cointopay_updates', __( 'Could not cancel the old subscription. Updates have not been processed.', 'paid-memberships-pro' ) );
						}
					
						add_filter( 'user_profile_update_errors', 'pmpro_cointopay_user_profile_fields_save_error', 10, 3 );
					}

					//stop processing updates
					return;
				}
			}

			//if we didn't get an end date, let's set one one cycle out
			if(empty($end_timestamp))
				$end_timestamp = strtotime("+" . $update['cycle_number'] . " " . $update['cycle_period'], current_time('timestamp'));

			//build order object
			$update_order = new MemberOrder();
			$update_order->setGateway('cointopay');
			$update_order->user_id = $user_id;
			$update_order->membership_id = $user_level->id;
			$update_order->membership_name = $user_level->name;
			$update_order->InitialPayment = 0;
			$update_order->PaymentAmount = $update['billing_amount'];
			$update_order->ProfileStartDate = date_i18n("Y-m-d", $end_timestamp);
			$update_order->BillingPeriod = $update['cycle_period'];
			$update_order->BillingFrequency = $update['cycle_number'];

			//need filter to reset ProfileStartDate
			add_filter('pmpro_profile_start_date', create_function('$startdate, $order', 'return "' . $update_order->ProfileStartDate . 'T0:0:0";'), 10, 2);

			//update subscription
			$update_order->Gateway->subscribe($update_order, false);

			//update membership
			$sqlQuery = "UPDATE $wpdb->pmpro_memberships_users
							SET billing_amount = '" . esc_sql($update['billing_amount']) . "',
								cycle_number = '" . esc_sql($update['cycle_number']) . "',
								cycle_period = '" . esc_sql($update['cycle_period']) . "',
								trial_amount = '',
								trial_limit = ''
							WHERE user_id = '" . esc_sql($user_id) . "'
								AND membership_id = '" . esc_sql($last_order->membership_id) . "'
								AND status = 'active'
							LIMIT 1";

			$wpdb->query($sqlQuery);

			//save order so we know which plan to look for at cointopay (order code = plan id)
			$update_order->status = "success";
			$update_order->saveOrder();			
		}

		/**
		 * Helper method to update the customer info via getCustomer
		 *
		 * @since 1.4
		 */
		function update(&$order)
		{
			//we just have to run getCustomer which will look for the customer and update it with the new token
			$result = $this->getCustomer($order);

			if(!empty($result))
			{
				return true;
			}
			else
			{
				return false;	//couldn't find the customer
			}
		}

		/**
		 * Cancel a subscription at cointopay
		 *
		 * @since 1.4
		 */
		function cancel(&$order, $update_status = true)
		{
			//no matter what happens below, we're going to cancel the order in our system
			if($update_status)
				$order->updateStatus("cancelled");

			//require a subscription id
			if(empty($order->subscription_transaction_id))
				return false;

			//find the customer
			$result = $this->getCustomer($order);

			if(!empty($result))
			{
				//find subscription with this order code
				$subscription = $this->getSubscription($order);

				if(!empty($subscription))
				{
					if($this->cancelSubscriptionAtGateway($subscription))
					{
						//we're okay, going to return true later
					}
					else
					{
						$order->error = __("Could not cancel old subscription.", 'paid-memberships-pro' );
						$order->shorterror = $order->error;

						return false;
					}
				}

				/*
					Clear updates for this user. (But not if checking out, we would have already done that.)
				*/
				if(empty($_REQUEST['submit-checkout']))
					update_user_meta($order->user_id, "pmpro_cointopay_updates", array());

				return true;
			}
			else
			{
				$order->error = __("Could not find the customer.", 'paid-memberships-pro' );
				$order->shorterror = $order->error;
				return false;	//no customer found
			}
		}

		/**
		 * Helper method to cancel a subscription at cointopay and also clear up any upaid invoices.
		 *
		 * @since 1.8
		 */
		function cancelSubscriptionAtGateway($subscription, $preserve_local_membership = false)
		{
			//need a valid sub
			if(empty($subscription->id))
				return false;

			//make sure we get the customer for this subscription
			$order = new MemberOrder();
			$order->getLastMemberOrderBySubscriptionTransactionID($subscription->id);

			//no order?
			if(empty($order))
			{
				//lets cancel anyway, but this is suspicious
				$r = $subscription->cancel();

				return true;
			}

			//okay have an order, so get customer so we can cancel invoices too
			$this->getCustomer($order);

			//get open invoices
			$invoices = $this->customer->invoices();
			$invoices = $invoices->all();

			//found it, cancel it
			try
			{
				//find any open invoices for this subscription and forgive them
				if(!empty($invoices))
				{
					foreach($invoices->data as $invoice)
					{
						if(!$invoice->closed && $invoice->subscription == $subscription->id)
						{
							$invoice->closed = true;
							$invoice->save();
						}
					}
				}

				//sometimes we don't want to cancel the local membership when cointopay sends its webhook
				if($preserve_local_membership)					
					PMProGateway_cointopay::ignoreCancelWebhookForThisSubscription($subscription->id, $order->user_id);
				
				//cancel
				$r = $subscription->cancel();

				return true;
			}
			catch(Exception $e)
			{
				return false;
			}
		}
		
		/**
		 * Filter pmpro_next_payment to get date via API if possible
		 *
		 * @since 1.8.6
		*/
		static function pmpro_next_payment($timestamp, $user_id, $order_status)
		{
			//find the last order for this user
			if(!empty($user_id))
			{
				//get last order
				$order = new MemberOrder();
				$order->getLastMemberOrder($user_id, $order_status);
				
				//check if this is a cointopay order with a subscription transaction id
				if(!empty($order->id) && !empty($order->subscription_transaction_id) && $order->gateway == "cointopay")
				{
					//get the subscription and return the current_period end or false
					$subscription = $order->Gateway->getSubscription($order);					
					
					if(!empty($subscription->current_period_end))
						return $subscription->current_period_end;
					else
						return false;
				}
			}
						
			return $timestamp;
		}
		/**
		 * Instead of change membership levels, send users to PayPal to pay.
		 *
         * @param int           $user_id
         * @param \MemberOrder  $morder
         *
		 * @since 1.8
		 */
	   	static function pmpro_checkout_before_change_membership_level_cointopay($user_id, $morder)
		{
			global $discount_code_id, $wpdb;
					
			//if no order, no need to pay
			if(empty($morder))
				return;
				
			$morder->user_id = $user_id;				
			$morder->saveOrder();
			//save discount code use
			if(!empty($discount_code_id))
				$wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");	
			
			do_action("pmpro_before_send_to_cointopay", $user_id, $morder);

			$morder->Gateway->senToCointopay($morder);
			
			
		}
		/**
		 * Update TOS consent log after checkout.
		 * @since 1.9.5
		 */
		function pmpro_after_checkout_update_consent_cointopay( $user_id, $order ) {
			if( !empty( $_REQUEST['tos'] ) ) {
				$tospage_id = pmpro_getOption( 'tospage' );
				pmpro_save_consent( $user_id, $tospage_id, NULL, $order->id );
			} elseif ( !empty( $_SESSION['tos'] ) ) {
				// PayPal Express and others might save tos info into a session variable
				$tospage_id = $_SESSION['tos']['post_id'];
				$tospage_modified = $_SESSION['tos']['post_modified'];
				pmpro_save_consent( $user_id, $tospage_id, $tospage_modified, $order->id );
				unset( $_SESSION['tos'] );
			}
		}
		/**
		 * Refund a payment or invoice
		 * @param  object &$order           Related PMPro order object.
		 * @param  string $transaction_id   Payment or Invoice id to void.
		 * @return bool                     True or false if the void worked
		 */
		function void(&$order, $transaction_id = null)
		{
			//cointopay doesn't differentiate between voids and refunds, so let's just pass on to the refund function
			return $this->refund($order, $transaction_id);
		}

		/**
		 * Refund a payment or invoice
		 * @param  object &$order         Related PMPro order object.
		 * @param  string $transaction_id Payment or invoice id to void.
		 * @return bool                   True or false if the refund worked.
		 */
		function refund(&$order, $transaction_id = NULL)
		{
			//default to using the payment id from the order
			if(empty($transaction_id) && !empty($order->payment_transaction_id))
				$transaction_id = $order->payment_transaction_id;

			//need a transaction id
			if(empty($transaction_id))
				return false;

			//if an invoice ID is passed, get the charge/payment id
			if(strpos($transaction_id, "in_") !== false) {
				$invoice = cointopay_Invoice::retrieve($transaction_id);

				if(!empty($invoice) && !empty($invoice->charge))
					$transaction_id = $invoice->charge;
			}

			//get the charge
			try {
				$charge = cointopay_Charge::retrieve($transaction_id);
			} catch (Exception $e) {
				$charge = false;
			}

			//can't find the charge?
			if(empty($charge)) {
				$order->status = "error";
				$order->errorcode = "";
				$order->error = "";
				$order->shorterror = "";
				
				return false;
			}

			//attempt refund
			try
			{
				$refund = $charge->refund();
			}
			catch (Exception $e)
			{
				//$order->status = "error";
				$order->errorcode = true;
				$order->error = __("Error: ", 'paid-memberships-pro' ) . $e->getMessage();
				$order->shorterror = $order->error;
				return false;
			}

			if($refund->status == "succeeded") {
				$order->status = "refunded";
				$order->saveOrder();

				return true;
			} else  {
				$order->status = "error";
				$order->errorcode = true;
				$order->error = sprintf(__("Error: Unkown error while refunding charge #%s", 'paid-memberships-pro' ), $transaction_id);
				$order->shorterror = $order->error;
				
				return false;
			}
		}
		/**
		 * Order Confirmation
		 */
		function pmpro_pmpro_PMProGateway_confirmation_message( $message ) {
			if(isset($_REQUEST['ConfirmCode'])){
				 $data = [ 
                           'mid' => pmpro_getoption("cointopay_merchant_id") , 
                           'TransactionID' => $_REQUEST['TransactionID'] ,
                           'ConfirmCode' => $_REQUEST['ConfirmCode']
                      ];
              $response = self::validateOrder($data);
			  if($response->Status !== $_REQUEST['status'])
              {
                  echo "We have detected different order status. Your order has been halted.";
                  exit;
              }
			  else if($response->CustomerReferenceNr == $_REQUEST['CustomerReferenceNr'])
              {
				global $wpdb, $current_user, $pmpro_invoice, $pmpro_msg, $pmpro_msgt;
				$table_name = $wpdb->pmpro_membership_orders;
				$table_name_u = $wpdb->pmpro_memberships_users;
				
				if($_REQUEST['status']=='paid' && $_REQUEST['notenough']==0){
	
				$user_id = $wpdb->get_results("SELECT user_id,membership_id FROM $table_name WHERE id = '" . $_REQUEST['CustomerReferenceNr'] . "'");
				
				if($user_id){
					$pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$user_id[0]->membership_id . "' LIMIT 1");
						
	
					if(is_numeric($pmpro_level->cycle_number) && $pmpro_level->cycle_number > 0 && $pmpro_level->cycle_period &&
						!($pmpro_level->expiration_number && $pmpro_level->expiration_period &&
							strtotime("+ " . $pmpro_level->expiration_number." ".$pmpro_level->expiration_period) < strtotime("+ " . $pmpro_level->cycle_number." ".$pmpro_level->cycle_period)))
					{
						$pmpro_level->expiration_number = $pmpro_level->cycle_number;
						$pmpro_level->expiration_period = $pmpro_level->cycle_period;
					}
					 
					 
					$old_startdate = current_time('timestamp');
					$old_enddate = current_time('timestamp');
					 
					$active_levels = pmpro_getMembershipLevelsForUser($user_id[0]->user_id);
					if (is_array($active_levels))
						foreach ($active_levels as $row)
						{
							if ($row->id == $pmpro_level->id && $row->enddate > current_time('timestamp'))
							{
								$old_startdate = $row->startdate;
								$old_enddate   = $row->enddate;
							}
						}
	
					// subscription start/end
					$startdate = "'" . date("Y-m-d H:i:s", $old_startdate) . "'";
					$enddate = (!empty($pmpro_level->expiration_number)) ? "'" . date("Y-m-d H:i:s", strtotime("+ ".$pmpro_level->expiration_number." ".$pmpro_level->expiration_period, $old_enddate)) . "'" : "NULL";
					
					$prevorder = new MemberOrder();
					$prevorder->getLastMemberOrder($user_id[0]->user_id, apply_filters("pmpro_confirmation_order_status", array("success")));
					$prevorder->updateStatus("-success-");
					
					$custom_level = array(
							'user_id' 			=> $user_id[0]->user_id,
							'membership_id' 	=> $pmpro_level->id,
							'code_id' 			=> '',
							'initial_payment' 	=> $pmpro_level->initial_payment,
							'billing_amount' 	=> $pmpro_level->billing_amount,
							'cycle_number' 		=> $pmpro_level->cycle_number,
							'cycle_period' 		=> $pmpro_level->cycle_period,
							'billing_limit' 	=> $pmpro_level->billing_limit,
							'trial_amount' 		=> $pmpro_level->trial_amount,
							'trial_limit' 		=> $pmpro_level->trial_limit,
							'startdate' 		=> $startdate,
							'enddate' 			=> $enddate);
					$num_rows = $wpdb->get_var('SELECT COUNT(*) FROM '.$table_name_u.' where user_id="'.$current_user->ID.'" order by id DESC LIMIT 1');
					if($num_rows>0){
						$user_status = $wpdb->get_col( "
					SELECT status FROM $wpdb->pmpro_memberships_users
					WHERE user_id = '" . (int)$current_user->ID . "' order by id DESC LIMIT 1" );
						foreach($user_status as $user_s) {
							if( $user_s != 'active'){
							pmpro_changeMembershipLevel($custom_level, $current_user->ID, 'active', $user_s);
							}
						}
						
					}
					else{
						
					pmpro_changeMembershipLevel($custom_level, $current_user->ID, 'changed');
					}
				}
	
				}
				
				else if($_REQUEST['status']=='paid' && $_REQUEST['notenough']==1){
		
				$wpdb->update( 
					$table_name, 
					array( 
						'notes' => 'IPN: Payment failed from Cointopay because notenough',  // string
					), 
					array( 'id' => $_REQUEST['CustomerReferenceNr'] ),
					array( 
							'%s'	// value1
						), 
						array( '%d' ) 
				);     
				
				$user_id = $wpdb->get_results("SELECT user_id,membership_id FROM $table_name WHERE id = '" . $_REQUEST['CustomerReferenceNr'] . "'");
				
				if($user_id){
					
					$num_rows = $wpdb->get_var('SELECT COUNT(*) FROM '.$table_name_u.' where user_id="'.$current_user->ID.'" order by id DESC LIMIT 1');
					if($num_rows>0){
						$user_status = $wpdb->get_col( "
					SELECT status FROM $wpdb->pmpro_memberships_users
					WHERE user_id = '" . (int)$current_user->ID . "' order by id DESC LIMIT 1" );
				 
					foreach($user_status as $user_s) {
						pmpro_changeMembershipLevel(0, $current_user->ID);
						
					}
				
					}
					
				}
			   $message = "<p>" . __('IPN: Payment failed from Cointopay because notenough.', 'paid-memberships-pro' ) . "</p>";
	
				}
				
				else if($_REQUEST['status']=='failed' && $_REQUEST['notenough']==1){ 
				$wpdb->update( 
					$table_name, 
					array( 
						'notes' => 'IPN: Payment failed from Cointopay because notenough',  // string
					), 
					array( 'id' => $_REQUEST['CustomerReferenceNr'] ),
					array( 
							'%s',	// value1
						), 
						array( '%d' ) 
				);      
				
			  $user_id = $wpdb->get_results("SELECT user_id,membership_id FROM $table_name WHERE id = '" . $_REQUEST['CustomerReferenceNr'] . "'");
				
				if($user_id){
					
					$num_rows = $wpdb->get_var('SELECT COUNT(*) FROM '.$table_name_u.' where user_id="'.$current_user->ID.'" order by id DESC LIMIT 1');
					if($num_rows>0){
						$user_status = $wpdb->get_col( "
					SELECT status FROM $wpdb->pmpro_memberships_users
					WHERE user_id = '" . (int)$current_user->ID . "' order by id DESC LIMIT 1" );
					foreach($user_status as $user_s) {
						pmpro_changeMembershipLevel(0, $current_user->ID);
					}
				
					}
					
				}
			   
			   $message = "<p>" . __('IPN: Payment failed from Cointopay because notenough.', 'paid-memberships-pro' ) . "</p>";
				
				}
				else if($_REQUEST['status']=='failed' && $_REQUEST['notenough']==0){ 
				$wpdb->update( 
					$table_name, 
					array( 
						'notes' => 'IPN: Payment failed from Cointopay because notenough',  // string
					), 
					array( 'id' => $_REQUEST['CustomerReferenceNr'] ),
					array( 
							'%s',	// value1
						), 
						array( '%d' ) 
				);      
				
			  $user_id = $wpdb->get_results("SELECT user_id,membership_id FROM $table_name WHERE id = '" . $_REQUEST['CustomerReferenceNr'] . "'");
				
				if($user_id){
					
					$num_rows = $wpdb->get_var('SELECT COUNT(*) FROM '.$table_name_u.' where user_id="'.$current_user->ID.'" order by id DESC LIMIT 1');
					if($num_rows>0){
						$user_status = $wpdb->get_col( "
					SELECT status FROM $wpdb->pmpro_memberships_users
					WHERE user_id = '" . (int)$current_user->ID . "' order by id DESC LIMIT 1" );
					foreach($user_status as $user_s) {
						pmpro_changeMembershipLevel(0, $current_user->ID);
					}
			
				}
				
			}
		   
		   $message = "<p>" . __('Payment failed from Cointopay.', 'paid-memberships-pro' ) . "</p>";
		    
			}
		   }
		  else if($response == 'not found')
              {
                  echo "We have detected different order status. Your order has been halted.";
                  exit;
              }
		   else{
				  echo "We have detected different order status. Your order has been halted.";
                  exit;
			  }
		  }
			return apply_filters( 'the_content', $message );
		}
		/**
		 * Validate Order
		 */
		static function  validateOrder($data){
		   //$this->pp($data);
		   //https://cointopay.com/v2REAPI?MerchantID=14351&Call=QA&APIKey=_&output=json&TransactionID=230196&ConfirmCode=YGBMWCNW0QSJVSPQBCHWEMV7BGBOUIDQCXGUAXK6PUA
		   $params = array(
		   "authentication:1",
		   'cache-control: no-cache',
		   );
			$ch = curl_init();
			curl_setopt_array($ch, array(
			CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
			//CURLOPT_USERPWD => $this->apikey,
			CURLOPT_POSTFIELDS => 'MerchantID='.$data['mid'].'&Call=QA&APIKey=_&output=json&TransactionID='.$data['TransactionID'].'&ConfirmCode='.$data['ConfirmCode'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_HTTPHEADER => $params,
			CURLOPT_USERAGENT => 1,
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC
			)
			);
			$response = curl_exec($ch);
			$results = json_decode($response);
			if($results->CustomerReferenceNr)
			{
				return $results;
			}
			else if($response == '"not found"')
              {
                  echo "Your order not found.";
                  exit;
              }
		   
			   echo $response;
			  
		}
		
	}
