<?php
    /**
    * GoCardless WHMCS module
    *
    * @author WHMCS <info@whmcs.com>
    * @version 0.1.0
    */

    # load GoCardless library
    require_once ROOTDIR . '/modules/gateways/gocardless/GoCardless.php';

    define('GC_VERSION', '0.1.0');

    /**
    ** GoCardless configuration for WHMCS
    ** This method is used by WHMCS to establish the configuration information
    ** used within the admin interface. These params are then stored in `tblpaymentgateways`
    **/
    function gocardless_config() {

        $aConfig = array(
            'FriendlyName'  => array('Type' => 'System', 'Value' => 'GoCardless'),
            'merchant_id'   => array('FriendlyName' => 'Merchant ID', 'Type' => 'text', 'Size' => '15', 'Description' => '<a href="http://gocardless.com/merchants/new">Sign up</a> for a GoCardless account then find your API keys in the Developer tab'),
            'app_id'        => array('FriendlyName' => 'App ID', 'Type' => 'text', 'Size' => '100'),
            'app_secret'    => array('FriendlyName' => 'App Secret', 'Type' => 'text', 'Size' => '100'),
            'access_token'  => array('FriendlyName' => 'Access Token', 'Type' => 'text', 'Size' => '100'),
            'oneoffonly'    => array('FriendlyName' => 'One Off Only', 'Type' => 'yesno', 'Description' => 'Tick to only perform one off captures - no recurring pre-auth agreements'),
            'instantpaid'     => array('FriendlyName' => 'Instant Activation', 'Type' => 'yesno', 'Description' => 'Tick to immediately mark invoices paid after payment is initiated (despite clearing not being confirmed for 3-5 days)', ),
            'testmode'         => array('FriendlyName' => 'Test Mode', 'Type' => 'yesno', 'Description' => 'Tick to enable test mode', ),
        );

        return $aConfig;

    }

    /**
    ** Builds the payment link for WHMCS users to be redirected to GoCardless
    **/
    function gocardless_link($params) {

        # get global config params
        global $CONFIG;

        # create GoCardless database if it hasn't already been created
        gocardless_createdb();

        # check for pending payment based on the invoiceID
        $pendingid = get_query_val('mod_gocardless', 'id', array('invoiceid' => $params['invoiceid'], 'resource_id' => array('sqltype' => 'NEQ', 'value' => '')));
		
		# check if a result was returned from the mod_gocardless table (if it has there is a pending payment)
        if ($pendingid) {
            # Pending Payment Found - Prevent Duplicate Payment with a Msg
            return '<strong>Your payment is currently pending and will be processed within 3-5 days.</strong>';
        } else {
		
			# create payment form to submit params to GoCardless
            
			# get tax rates
            $data = get_query_vals("tblinvoices", "taxrate,taxrate2", array('id' => $params['invoiceid']));
            $taxrate = $data["taxrate"];
            $taxrate2 = $data["taxrate2"];
			
			# if $taxrate is 0 and the tax is all inclusive, then set appropriate tax rate
			if(!$taxrate && $CONFIG['TaxType'] == 'Inclusive') {
				$taxrate = 1;
			} else {
				$taxrate = ($taxrate /100) + 1;
			}

			# set params $maxamount, $recurfrequency to 0
            $maxamount = $recurfrequency = 0;

            # check if the configuration is set to make one of payments only
            if (!$params['oneoffonly']) {
			
				# we could be handling a recurring payment

                # calculate max amount to be given to PreAuth
                $query = mysql_query("SELECT tblproducts.id, tblproducts.name FROM tblinvoiceitems INNER JOIN tblhosting ON tblinvoiceitems.relid = tblhosting.id INNER JOIN tblproducts ON tblhosting.packageid = tblproducts.id WHERE tblinvoiceitems.invoiceid = ".$params['invoiceid']." GROUP BY tblproducts.id");
                if (mysql_num_rows($query) == 1) {
                    $data = mysql_fetch_array($query);
                    $description = $data['name'];
                }

                $result = select_query("tblinvoiceitems", "type,relid,amount,taxed", array('invoiceid' => $params['invoiceid']));
                while ($data = mysql_fetch_array($result)) {

                    $itemtype   = $data['type'];
                    $itemrelid  = $data['relid'];
                    $itemamount = $data['amount'];
                    $itemtaxed  = $data['taxed'];

                    $itemtaxed = ($itemtaxed) ? $taxrate : 1;

                    $itemrecurvals = array();
                    if ($itemtype == 'Hosting') $itemrecurvals = get_query_vals("tblhosting", "firstpaymentamount,amount,billingcycle", array('id' => $itemrelid));
                    if ($itemtype == 'Addon') $itemrecurvals = get_query_vals("tblhostingaddons", "(setupfee+recurring),recurring,billingcycle", array('id' => $itemrelid));
                    if ($itemtype == 'DomainRegister' || $itemtype == 'DomainRegister') $itemrecurvals = get_query_vals("tbldomains", "firstpaymentamount,recurringamount", array('id' => $itemrelid));

                    if (count($itemrecurvals) && $itemrecurvals[2] != 'One Time' && $itemrecurvals[2] != 'Free Account' && $itemrecurvals[2] != 'Free') {

                        // Add to Recurring Amount
                        $maxamount += ($itemrecurvals[0] > $itemrecurvals[1]) ? $itemrecurvals[0] * $itemtaxed : $itemrecurvals[1] * $itemtaxed;

                        // Also track recurring months
                        $recurmonths = getBillingCycleMonths($itemrecurvals[2]);
                        if ( ! $recurfrequency || $recurmonths < $recurfrequency) {
                            $recurfrequency = $recurmonths;
                        }

                    }

                }

            }

            // Initialise Account Details
            GoCardless::set_account_details(array(
                    'app_id'        => $params['app_id'],
                    'app_secret'    => $params['app_secret'],
                    'merchant_id'   => $params['merchant_id'],
                    'access_token'  => $params['access_token'],
                    'ua_tag'        => 'gocardless-whmcs/v' . GC_VERSION
                ));
			
			# set user array based on params parsed to $link
            $aUser = array(
                'first_name'        => $params['clientdetails']['firstname'],
                'last_name'         => $params['clientdetails']['lastname'],
                'email'             => $params['clientdetails']['email'],
                'billing_address1'  => $params['clientdetails']['address1'],
                'billing_address2'  => $params['clientdetails']['address2'],
                'billing_town'      => $params['clientdetails']['city'],
                'billing_county'    => $params['clientdetails']['state'],
                'billing_postcode'  => $params['clientdetails']['postcode'],
            );
			
			# if the valuation of $maxamount is false, we are making a one off payment
            if (!$maxamount) {
				# we are making a one off payment, display the appropriate code
				# Button title
                $title = 'Pay Now with GoCardless';
				
				# create GoCardless one off payment URL using the GoCardless library
                $url = GoCardless::new_bill_url(array(
					'amount'  => $params['amount'],
					'name'    => $params['description'],
					'user'    => $aUser,
					'state'   => $params['invoiceid'] . ':' . $params['amount']
				));

                # return one time payment button code
                return '<a href="'.$url.'"><input type="button" value="'.$title.'" /></a>';

            } else {
                # we are setting up a recurring payment, display the appropriate code
				
				# Button title
                $title = 'Create Subscription with GoCardless';
				
				# create GoCardless preauth URL using the GoCardless library
                $url = GoCardless::new_pre_authorization_url(array(
					'max_amount'      => $maxamount,
					'name'            => $description,
					'interval_length' => $recurfrequency,
					'interval_unit'   => 'month',
					'user'            => $aUser,
					'state'           => $params['invoiceid'] . ':' . $params['amount']
				));

                # return the recurring preauth button code
                return 'When you get to GoCardless you will see an agreement for the <b>maximum possible amount</b> we\'ll ever need to charge you in a single invoice for this order, with a frequency of the shortest item\'s billing cycle. But rest assured we will never charge you more than the actual amount due.
                <br /><a href="'.$url.'"><input type="button" value="'.$title.'" /></a>';

            }
        }
    }

	/**
	** WHMCS method to capture payments
	** This method is triggered by WHMCS in an attempt to capture a PreAuth payment
	**
	** @param array $params Array of paramaters parsed by WHMCS
	**/
    function gocardless_capture($params) {
		
		# create GoCardless DB if it hasn't already been created
        gocardless_createdb();
		
		# Send the relevant API information to the GoCardless class for future processing
        GoCardless::set_account_details(array(
                'app_id'        => $params['app_id'],
                'app_secret'    => $params['app_secret'],
                'merchant_id'   => $params['merchant_id'],
                'access_token'  => $params['access_token'],
                'ua_tag'        => 'gocardless-whmcs/v' . GC_VERSION
            ));

		# check against the database if the bill relevant to this invoice has already been created
        $existing_payment_query = select_query('mod_gocardless', 'resource_id', array('invoiceid' => $params['invoiceid']));
        $existing_payment = mysql_fetch_assoc($existing_payment_query);

		# check if any rows have been returned or if the returned result is empty.
		# If no rows were returned, the bill has not already been made for this invoice
		# If a row was returned but the resource ID is empty, the bill has not been completed
		# we have already raised a bill with GoCardless (in theory)
        if (!mysql_num_rows($existing_payment_query) || empty($existing_payment['resource_id'])) {
			
			# query the database to get the relid of all invoice items
            $invoice_item_query = select_query('tblinvoiceitems', 'relid', array('invoiceid' => $params['invoiceid'], 'type' => 'Hosting'));
			
			# loop through each returned (each invoice item) and attempt to find a subscription ID
            while ($invoice_item = mysql_fetch_assoc($invoice_item_query)) {
                $package_query = select_query('tblhosting', 'subscriptionid', array('id' => $invoice_item['relid']));
                $package = mysql_fetch_assoc($package_query);
				
				# if we have found a subscriptionID, store it in $preauthid
                if (!empty($package['subscriptionid'])) {
                    $preauthid = $package['subscriptionid'];
                }
            }
			
			# now we are out of the loop, check if we have been able to get the PreAuth ID
            if (isset($preauthid)) {
				
				# we have found the PreAuth ID, so get it from GoCardless and process a new bill
				
                $pre_auth = GoCardless_PreAuthorization::find($preauthid);
				
				# check the preauth returned something
				if($pre_auth) {
					
					# Create a bill with the $pre_auth object
					$bill = $pre_auth->create_bill(array('amount' => $params['amount']));
					
					# check that the bill has been created
					if ($bill->id) {
						# check if the bill already exists in the database, if it does we will just update the record
						# if not, we will create a new record and record the transaction
						if (!mysql_num_rows($existing_payment_query)) {
							# Add the bill ID to the table
							insert_query('mod_gocardless', array('invoiceid' => $params['invoiceid'], 'billcreated' => 1, 'resource_id' => $bill->id));
							logTransaction('GoCardless', 'Transaction initiated successfully, confirmation will take 2-5 days', 'Pending');
						} else {
							# update the table with the bill ID
							update_query('mod_gocardless', array('billcreated' => 1, 'resource_id' => $bill->id), array('invoiceid' => $params['invoiceid']));
						}

					}
				} else {
					# PreAuth could not be verified
					logTransaction('GoCardless','Pre-Authorisation could not be verified','Incomplete');
				}
				

            } else {
				# we couldn't find the PreAuthID meaning at this point all we can do is give up!
				# the client will have to setup a new preauth to begin recurring payments again
				# or pay using an alternative method
                logTransaction('GoCardless', 'No pre-authorisation found', 'Incomplete');
            }

        }

    }

    /**
	** Supress credit card request on checkout
	**/
    function gocardless_nolocalcc() {}

	/**
	** Create mod_gocardless table if it does not already exist
	**/
    function gocardless_createdb() {

        $query = "CREATE TABLE IF NOT EXISTS `mod_gocardless` (
        `id` int(11) NOT NULL auto_increment,
        `invoiceid` int(11) NOT NULL,
        `billcreated` int(11) default NULL,
        `resource_id` varchar(16) default NULL,
        PRIMARY KEY  (`id`))";

        full_query($query);

    }
	
    function gocardless_initiatepayment() {}
	
	/**
	** Display payment status message to admin when the preauth
	** has been setup but the payment is incomplete
	**/
    function gocardless_adminstatusmsg($vars) {

        if ($vars['status']=='Unpaid') {

            $refid = get_query_val("mod_gocardless","id",array("invoiceid"=>$vars['invoiceid']));

            if ($refid) return array('type' => 'info', 'title' => 'GoCardless Payment Pending', 'msg' => 'There is a pending payment already in processing for this invoice. Status will be automatically updated once confirmation is received back from GoCardless.' );

        }

    }
