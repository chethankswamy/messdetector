<?php

defined( 'SYSPATH' ) OR die( 'No direct access allowed.' );

/**
 * @Class  Xero.php
 * @package    Core
 * @Copyright (c) 2011 Acclivity Group LLC
 * @created date 04/12/2012
 */
class Xero_Controller extends Integration_Controller {

    public $template = 'template-rerun';
    public $auto_render = FALSE;
    private $xero_model;
    private $token_encryption_key;
    private $discount_item_name;
    private $xero_suppliers_group;
    private $xero_customer_group;
    private $app = 'xero';
    
    public function __construct() {
        parent::__construct();
        $this->_pre_dispatch();
        $this->_setup();
        $this->set_merchant_defaults( 'xero' );
        $this->discount_item_name = 'Rerun Discount';
        $this->xero_customer_group = 'Xero Customers';
        $this->xero_suppliers_group = 'Xero Suppliers';
        ini_set( 'max_execution_time', 0 );
		ini_set('memory_limit', '-1');
        date_default_timezone_set( 'GMT' );
    }

    /**
     *  function to make Xero setup
     *  @access private
     */
    private function _setup() {
        define( 'BASE_PATH', APPPATH );
        define( 'USERAGENT', 'Rerun' );
        define( 'OAUTH_CALLBACK', $this->_protocol() . '://' . $_SERVER['HTTP_HOST'] . '/xero/authenticate/' );
        if(IN_PRODUCTION) {
            define( 'XERO_APP_TYPE', 'Partner' );
            define( 'XERO_APP_CONSUMER_KEY', 'QJQ0KW2HUNBOXSVSBJ2M1NEHAESGAE' );
            define( 'XERO_APP_CONSUMER_SECRET', 'KAHQLWO6FMUWFPT7QUFNC5PK2S3EAA' );
        } else {
            define( 'XERO_APP_TYPE', 'Public' );
            define( 'XERO_APP_CONSUMER_KEY', 'SKG4UEG5VOHB3MKRQJWAVSGXXIE3PG' );
            define( 'XERO_APP_CONSUMER_SECRET', 'CF75MWOTYNPYU3GRFJGTYLE97IXKCC' );
        }
        define( 'REFERSH_TOKENS_BEFORE_SECONDS', 360 ); //refresh tokens only after the 24 minutes of its creation
        $this->token_encryption_key = 'XeRo-RerUNIntegrationKey';
    }

    /**
     * function to initialise models
     */
    public function initialize() {
        $this->xero_model = new Xero_Model();
        parent::initialize();
    }

    /**
     *  fnction to take care of if a user is logged in or not
     *  @access private
     */
    private function _pre_dispatch() {
        if ( $this->session->get( 'user_type' ) != 'merchant' ) {
            $currentAction = Router::$method;
            $openActions = array( 'refresh_tokens' );
            if ( !in_array( $currentAction, $openActions ) ) {
                header( "HTTP/1.0 401 Not Authorized" );
                if ( $this->_is_ajax_request() ) {
                    echo json_encode( array( 'error' => 1, 'message' => 'Unauthorized' ) );
                } else {
                    url::redirect( '/merchant/login/' );
                }
                exit( 0 );
            }
        }
    }

    /**
     * function to get the oauth signature params
     * @access public
     * @return array
     */
    private function _get_signature_params() {
        $signatures = array(
            'consumer_key' => XERO_APP_CONSUMER_KEY,
            'shared_secret' => XERO_APP_CONSUMER_SECRET,
            'core_version' => '2.0',
            'payroll_version' => '1.0'
        );
        /**
         * @todo Once the app gets approved, give the correct files for certificates
         */
        if ( XERO_APP_TYPE == 'Private' || XERO_APP_TYPE == 'Partner' ) {
            $signatures['rsa_private_key'] = BASE_PATH . 'certs/privatekey.pem';
            $signatures['rsa_public_key'] = BASE_PATH . 'certs/publickey.cer';
        }

        if ( XERO_APP_TYPE == 'Partner' ) {
            $signatures['curl_ssl_cert'] = BASE_PATH . 'certs/entrust-cert.pem';
            $signatures['curl_ssl_password'] = 'rerun2013';
            $signatures['curl_ssl_key'] = BASE_PATH . 'certs/entrust-private.pem';
        }

        return $signatures;
    }

    /**
     * function to get the XeroOauth class object
     * @return object XeroOauth || boolean FALSE
     * @access private
     */
    private function _get_xero_oauth_object() {
        $signatures = $this->_get_signature_params();
        $xeroOAuth = new XeroOAuth( array_merge( array(
                            'application_type' => XERO_APP_TYPE,
                            'oauth_callback' => OAUTH_CALLBACK,
                            'user_agent' => USERAGENT
                                ), $signatures ) );

        $initialCheckErrors = $xeroOAuth->diagnostics(); //check if the created object has all the required parameters correct
        $validObject = empty( $initialCheckErrors );
        return $validObject ? $xeroOAuth : FALSE;
    }

    /**
     * function to read and  set access tokens to object
     * @param object $xeroOauth
     * @return array || object
     */
    private function _set_tokens_to_object( $xeroOauth ) {
        if ( !is_object( $xeroOauth ) )
            return array( 'error' => 1, 'code' => 'not_object' );
        $merchantOauth = $this->xero_model->get_active_xero_tokens( $this->session->get( 'id' ) );
        if ( empty( $merchantOauth ) )
            return array( 'error' => 1, 'code' => 'no_tokens' );
        if ( $merchantOauth[0]->time_to_expire <= 0 )
            return array( 'error' => 1, 'code' => 'tokens_expired' );
        $xeroOauth->config['access_token'] = $this->_decrypt_tokens( $merchantOauth[0]->access_token, $this->token_encryption_key );
        $xeroOauth->config['access_token_secret'] = $this->_decrypt_tokens( $merchantOauth[0]->access_token_secret, $this->token_encryption_key );
        $XeroOAuth->config['session_handle'] = $merchantOauth[0]->session_handle;
        return $xeroOauth;
    }

    /**
     * function to include required libraries
     * @access private
     */
    private function include_required_libraries() {
        require_once Kohana::find_file( 'libraries/Xero', 'XeroOAuth' );
    }

    /**
     * function to do xero authentication oauth
     * @return doesn't return anything redirects to xero settings page on success, any auth error displays the error and exits @see _auth_success
     * @access public
     */
    public function authenticate() {
        $this->include_required_libraries();
        $xeroOAuth = $this->_get_xero_oauth_object();
        $here = OAUTH_CALLBACK;

        if ( $xeroOAuth === FALSE ) {
            echo 'There are some errors in the app set up. Please contact the administrator.';
            exit( 0 );
        } else {
            if ( isset( $_REQUEST['oauth_verifier'] )  && isset($_SESSION['xero_oauth'])) {
                $xeroOAuth->config['access_token'] = $_SESSION['xero_oauth']['oauth_token'];
                $xeroOAuth->config['access_token_secret'] = $_SESSION['xero_oauth']['oauth_token_secret'];
                $oauth_verifier = $_REQUEST['oauth_verifier'];
                $code = $xeroOAuth->request( 'GET', $xeroOAuth->url( 'AccessToken', '' ), array(
                    'oauth_verifier' => $_REQUEST['oauth_verifier'],
                    'oauth_token' => $_REQUEST['oauth_token']
                        ) );
                if ( $xeroOAuth->response['code'] == 200 ) {
                    $response = $xeroOAuth->extract_params( $xeroOAuth->response['response'] );
                    $response['oauth_verifier'] = $oauth_verifier;
                    $wasActivelyConnected = $this->xero_model->is_actively_integrated_to_xero( $this->session->get( 'id' ) );
                    $type = $wasActivelyConnected ? 'subsequent' : 'firsttime';
                    $this->_auth_success( $response, $type ); //xero authentication success
                } else {
                    $this->_oauth_error_handler( $xeroOAuth );
                }
            } else { //start oauth dance :)
                $params = array(
                    'oauth_callback' => $here
                );
                $response = $xeroOAuth->request( 'GET', $xeroOAuth->url( 'RequestToken', '' ), $params );
                if ( $xeroOAuth->response['code'] == 200 ) {
                    $scope = '';
                    $_SESSION['xero_oauth'] = $xeroOAuth->extract_params( $xeroOAuth->response['response'] );
                    $authurl = $xeroOAuth->url( "Authorize", '' ) . "?oauth_token={$_SESSION['xero_oauth']['oauth_token']}&scope=" . $scope;
                    url::redirect( $authurl );
                } else {
                    $this->_oauth_error_handler( $xeroOAuth );
                }
            }
        }
    }

    /**
     * function to print and exit in case of error
     * @access private
     */
    private function _oauth_error_handler( $xeroOAuth ) {
        echo 'Error: ' . $xeroOAuth->response['response'] . PHP_EOL;
        var_dump( $xeroOAuth ); /// @todo Remove it while going live
        exit( 0 );
    }

    /**
     * function after oauth authentication success
     * redirects to xero settings page
     * @access private
     * @param $response array oauth response,
     * @param $mode string can be either of 'user' or 'auto'
     *
     */
    private function _auth_success( $response, $mode ) {
        if ( isset( $_SESSION['xero_oauth'] ) )
            unset( $_SESSION['xero_oauth'] );

        $accessToken = $response['oauth_token'];
        $accessTokenSecret = $response['oauth_token_secret'];
        $expiresIn = $response['oauth_expires_in']; // after how many seconds the tokens would expire
        $org = isset( $response['xero_org_muid'] ) ? $response['xero_org_muid'] : 'xero';

        $accessTokenHashed = $this->_encrypt_tokens( $accessToken, $this->token_encryption_key );
        $accessTokenSecretHashed = $this->_encrypt_tokens( $accessTokenSecret, $this->token_encryption_key );
        if ( $mode == 'firsttime' )
            $mode = 'insert';
        if ( $mode == 'subsequent' )
            $mode = 'update';
        
        //oauth_session_handle will not be set for development account
        $oauth_session_handle = isset($response['oauth_session_handle'])?$response['oauth_session_handle']:'';        
        $status = $this->_store_tokens( array( 'access_token' => $accessTokenHashed, 'oauth_verifier' => $response['oauth_verifier'], 'session_handle' => $oauth_session_handle, 'access_token_secret' => $accessTokenSecretHashed, 'expires_in' => $expiresIn, 'organization' => $org ), $mode );
        if ( $status ) {
            $this->store_merchant_sync_data( array( 'thirdparty_file' => $org ), 'xero' );
            $this->xero_model->update_third_party_status_to_xero( $this->session->get( 'id' ) );
            $this->session->set( 'third_party_status', $this->xero_model->get_xero_third_party_status() );
            $this->session->set( 'third_party_app', $this->xero_model->get_xero_third_party_status() );
            $this->integration_model->mark_customers_non_synced($this->session->get('id'));
            $this->get_accounts();
            $this->_create_xero_customer_groups();
            $this->close_and_show_menu();
        } else {
            echo 'Couldn\'t connect to storage engine.Please enter after sometime';
            exit( 0 );
        }
    }

    private function close_and_show_menu() {
        echo html::script( 'assets/js/xero/xeroauth_success.js' );
    }

    /**
     * function to store the tokens into storage engine
     * @access private
     * @param $data array('access_token','access_token_secret','expires_in')
     * @param $op string can be 'insert' or 'update'
     */
    private function _store_tokens( $data, $op ) {

        $storeData = array(
            'merchant_id' => $this->session->get( 'id' ),
            'access_token' => $data['access_token'],
            'access_token_secret' => $data['access_token_secret'],
            'session_handle' => $data['session_handle'],
            'expires_at' => 'DATE_ADD(NOW(),INTERVAL ' . $data['expires_in'] . ' SECOND)',
            'created_at' => 'NOW()',
            'organization' => $data['organization'],
            'last_updated_at' => 'NOW()',
            'status' => '1'
        );

        if ( $op == 'update' )
            unset( $storeData['created_at'] );

        $result = $this->xero_model->store_tokens( $storeData, $op );
        if ( $op == 'update' )
            return TRUE;
        if ( $op == 'insert' )
            return (is_numeric( $result ) && $result > 0);
    }

    /**
     * Function to disconnect from Xero
     * De-actiavtes xero tokens and changes the third party sttaus in Rerun
     */
    public function disconnect($noajax='') {
        $merchant_id = $this->session->get( 'id' );
        $this->xero_model->deactivate_xero_tokens( $merchant_id );
        $this->xero_model->disconnect_update_third_party_status( $merchant_id );
        $this->quickbooks_model->unset_merchant_data( $merchant_id, 'xero', $this->get_merchant_default_value( 'thirdparty_file' ) );
        $this->session->set( 'third_party_status', '0' );
        $this->session->set( 'third_party_app', '0' );
        
        if($noajax)
            return true;
        else
            echo json_encode( array( 'error' => 0, 'redirect' => '/settings/1' ) );
    }

    /**
     * function to refresh the xero oauth tokens tokens
     * this function is run on a cron job
     */
    public function refresh_tokens() {
        @session_start();
        $this->include_required_libraries();
        $modes = $this->get_db_modes();
        foreach ( $modes as $mode ) {
            $_SESSION['trial_merchant'] = $mode == 'trial' ? '1' : '0';
            $this->initialize();
            $tokensToRefresh = $this->xero_model->get_tokens_to_refresh();
            foreach ( $tokensToRefresh as $token ) {
                $_SESSION['id'] = $token->merchant_id;
                $accessToken = $this->_decrypt_tokens( $token->access_token, $this->token_encryption_key );
                $xeroOauth = $this->_get_xero_oauth_object();
                $response = $xeroOauth->refreshToken( $accessToken, $token->session_handle);
                if($response['code'] == 200) {  
                    $accessTokenHashed = $this->_encrypt_tokens($response['oauth_token'], $this->token_encryption_key);
                    $tokenSecretHashed = $this->_encrypt_tokens($response['oauth_token_secret'], $this->token_encryption_key);
                    $org = isset($response['xero_org_muid']) ? $response['xero_org_muid'] : 'xero';
                    $expiresIn = $response['oauth_expires_in'];
                    $status = $this->_store_tokens( array( 'access_token' => $accessTokenHashed, 
                                                           'session_handle' => $response['oauth_session_handle'], 
                                                           'access_token_secret' => $tokenSecretHashed, 
                                                           'expires_in' => $expiresIn, 
                                                           'organization' => $org ), 
                            'update' );
                }
            }
        }
        echo 'Done Refreshing Tokens at:'.date('Y-m-d- H:i:s');
        exit(0);
    }

    /**
     * get the detailed error message
     * @param object || array  $xeroOauth
     * @return string
     */
    private function _check_object_errors( $xeroOauth ) {
        if ( is_array( $xeroOauth ) ) { //invalid would be possible only if type is array
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( $xeroOauth['code'] ) ) );
            exit( 0 );
        }
    }

    /**
     * Function to get detailed error description
     * @param string $errorCode
     * @return string
     */
    private function _get_error_message( $errorCode ) {
        $errorMessages = array( 'no_tokens' => 'You are not integrated with Xero.',
            'tokens_expired' => 'Your Xero tokens have expired. Please click on Connect to Xero button and reauthorize.',
            'not_object' => 'There are some configuration errors. Please contact administrator.',
            'general_error_item_sync' => 'An error occured while syncing items.',
            'item_sync_success' => 'Items synced successfully.',
            'account_refresh_success' => 'Accounts refreshed successfully',
            'income_accounts_fetched' => 'Income accounts fetched successfully',
            'income_account_not_selected' => 'Please select an income account before performing the item sync.',
            'tax_sync_success' => 'Taxes synced successfully.',
            'customer_sync_success' => 'Customers synced successfully',
            'transaction_sync_success' => 'Transactions synced successfully',
            'customer_sync_conflict' => 'There were conflicts while doing customer import. Please select the actions below:'
        );
        return array_key_exists( $errorCode, $errorMessages ) ? $errorMessages[$errorCode] : $errorCode;
    }

    /**
     * function to extract object array from the xero api response
     * @param array $response
     * @param string $object
     * @return array
     */
    private function _extract_object_array_from_response( $response, $object ) {
        $responseArray = json_decode( $response['response'], TRUE );
        return isset( $responseArray[$object] ) ? $responseArray[$object] : array( );
    }

    
    /*
     * function to get list of merchant's accounts
     */
    private function _get_merchant_accounts ($merchant_id) {
        $xeroids = array();
        $accounts = $this->xero_model->get_income_accounts($merchant_id);
        if($accounts){
            foreach ( $accounts as $account ) {
                $xeroids[$account->id] = $account->xero_id;
            }
        }
        return $xeroids;
    }
    
    /*
     * function to check if the account is present in xero or not
     */
    private function _check_account_in_xero($xero_id) {
        if($xero_id){
           $this->include_required_libraries();
           $xeroOauth = $this->_get_xero_oauth_object();
           $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
           $this->_check_object_errors( $xeroOauth );
           //$object = 'Accounts?where=Account.AccountID%20%3D%20Guid%28%22260368ec-d0e4-47ad-ab48-bdad78e4444b%22%29';
           $object = 'Accounts/'.$xero_id;
           if ( is_object( $xeroOauth ) ) {
               $response = $xeroOauth->request( 'GET', $xeroOauth->url( $object, 'core' ), array( ) );
               unset( $xeroOauth ); // I don't need this anymore
               if ( $this->_is_successful_request( $response ) ) {           
                   return true;
               } else {
                   return false;
               }
           }
        } else {
            return false;
        }
    }
    
    /**
     * function to get accounts from Xero
     * retruns json_encoded response
     */
    public function get_accounts($ajax='') {
        $this->include_required_libraries();
        $xeroOauth = $this->_get_xero_oauth_object();
        $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
        $this->_check_object_errors( $xeroOauth );
        $object = 'Accounts';
        if ( is_object( $xeroOauth ) ) {
            $response = $xeroOauth->request( 'GET', $xeroOauth->url( $object, 'core' ), array( ) );
            unset( $xeroOauth ); // I don't need this anymore
            if ( $this->_is_successful_request( $response ) ) {
                $accounts = $this->_extract_object_array_from_response( $response, $object );
                $existing_acc_ids = $this->_get_merchant_accounts ($this->session->get('id'));

                foreach ( $accounts as $account ) {
                    $accountData = $this->_extract_account_data( $account );
                    $existingAccountData = $this->xero_model->check_account_exists( $accountData['xero_id'], $this->session->get( 'id' ) );
                    
                    if($existing_acc_ids){
                        if(in_array($accountData['xero_id'], $existing_acc_ids)){
                            $acc_row_id = array_keys($existing_acc_ids, $accountData['xero_id']);
                            if($accountData['status'] == 'ACTIVE'){
                                unset($existing_acc_ids[$acc_row_id[0]]);
                            }
                        }
                    }                    
                    $accountData['status'] = ($accountData['status'] == 'ACTIVE')? '1': '0';
                    if ( $existingAccountData == FALSE ) {
                        $this->xero_model->save_account( $accountData );
                    } else {
                        $this->xero_model->update_account( $existingAccountData[0]->id, $accountData );
                    }
                }
                $this->xero_model->set_account_status($existing_acc_ids);
                if($ajax)
                    echo json_encode(array('error' => 0, 'message' => $this->_get_error_message('account_refresh_success'), 'code' => 'account_refresh_success'));
                else
                    return true;    
            } else {
                $this->_handle_response_error( $response );
            }
        } else {
            //echo json_encode(array('error' => 1, 'message' => $this->_get_error_message('not_object'), 'code' => 'not_object'));
        }
    }

    /**
     * Function to get income accounts options HTML
     * returns the json encoded data
     */
    public function get_account_options() {
        $accounts = $this->xero_model->get_income_accounts( $this->session->get( 'id' ) );
        $categorizedAccountOptions = $this->_categorize_accounts_options( $accounts );
        //$selectedAccount = $this->get_merchant_income_account();
        echo json_encode( array( 'error' => 0, 'code' => 'income_accounts_fetched', 'message' => $this->_get_error_message( 'income_accounts_fetched' ), 'data' => $categorizedAccountOptions ) );
    }

    /**
     * Function to categorize the accounts to income, discount and payment accounts
     * @param array $accounts
     * @return array Categorized accounts
     */
    private function _categorize_accounts_options( $accounts ) {
        $categorizedAccountOptions = array( 'income' => '<option value="0">Select Account</option>',
            'discount' => '<option value="0">Select Account</option>',
            'payment' => '<option value="0">Select Account</option>' );
        $incomeAccountTypes = array( 'REVENUE' ); // Income accounts will of be type REVENUE
        $discountAccountTypes = array( 'REVENUE', 'EXPENSE' ); // Discount accounts will of be type REVENUE Or EXPENSE
        $paymentAccountTypes = array( 'BANK' ); // Payment accounts will of be type BANK or any other account for which payment is enabled
        $includePaymentEnabled = TRUE; //Set this varibale to TRUE so that to include payment enabled accounts can be used as payment accounts. Doing this FALSE will make only BANK accounts to be used as payment accounts

        $selectedIncomeAccount = $this->get_merchant_default_value( 'thirdparty_default_account_id' );
        $selectedDiscountAccount = $this->get_merchant_default_value( 'thirdparty_discount_account_id' );
        $selectedPaymentAccount = $this->get_merchant_default_value( 'thirdparty_payment_account_id' );
        foreach ( $accounts as $account ) {
            $type = $account->type;
            if ( in_array( $type, $incomeAccountTypes ) ) {
                $selected = $account->code == $selectedIncomeAccount ? 'selected="selected"' : '';
                $categorizedAccountOptions['income'] .= '<option value="' . $account->code . '"' . $selected . '>' . $account->code . ' - ' . $account->name . '</option>';
            }
            if ( in_array( $type, $discountAccountTypes ) ) {
                $selected = $account->code == $selectedDiscountAccount ? 'selected="selected"' : '';
                $categorizedAccountOptions['discount'] .= '<option value="' . $account->code . '"' . $selected . '>' . $account->code . ' - ' . $account->name . '</option>';
            }
            if ( in_array( $type, $paymentAccountTypes ) || ($includePaymentEnabled && $account->is_payment_enabled) ) {
                $selected = $account->xero_id == $selectedPaymentAccount ? 'selected="selected"' : '';
                $categorizedAccountOptions['payment'] .= '<option value="' . $account->xero_id . '"' . $selected . '>' . trim( $account->code . ' - ' . $account->name, '- ' ) . '</option>';
            }
        }
        return $categorizedAccountOptions;
    }

    /**
     * Function to extract account data in Rerun format
     * @param type $account
     * @return type
     */
    private function _extract_account_data( $account ) {
        $accountData = array( );
        $accountData['merchant_id'] = $this->session->get( 'id' );
        $accountData['code'] = isset( $account['Code'] ) ? $account['Code'] : '';
        $accountData['name'] = $account['Name'];
        $accountData['xero_id'] = $account['AccountID'];
        $accountData['type'] = $account['Type'];
        $accountData['is_payment_enabled'] = isset( $account['EnablePaymentsToAccount'] ) ? $account['EnablePaymentsToAccount'] : FALSE;
        $accountData['status'] = $account['Status'];
        return $accountData;
    }

    /**
     * function to do perform sync
     * json encoded response
     */
    public function sync_items() {
        if ( !$this->_income_account_selected() ) {
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( 'income_account_not_selected' ), 'code' => 'income_account_not_selected' ) );
            exit( 0 );
        }
        $merchant_id = $this->session->get( 'id' );
        $this->include_required_libraries();
        $lastSyncTime = $this->xero_model->get_merchant_last_sync_time( $merchant_id, 'item', 'xero' );
        $itemsEffected = $this->_get_items( $lastSyncTime );
        $this->_do_item_export( $itemsEffected );
        $this->_store_sync_time( 'item' );
        echo json_encode( array( 'error' => 0, 'message' => $this->_get_error_message( 'item_sync_success' ), 'code' => 'item_sync_success' ) );
    }

    /**
     * Adds If-Modified-Since request header if $modifiedSince is not null
     * @param string $modifiedSince
     * @param object $xeroOauth
     */
    private function _check_and_add_modified_since_header( $modifiedSince, $xeroOauth ) {
        if ( !empty( $modifiedSince ) ) {
            $xeroOauth->addHeaders( array( 'If-Modified-Since' => $modifiedSince ) );
        }
    }

    /**
     * function to check if the request was success
     * @param Response Array
     * @return boolean
     */
    private function _is_successful_request( $response ) {
        if ( isset( $response['code'] ) && $response['code'] == 200 ) {
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Stores the last sync time of an object sync
     * @param String $object Ex : item,tax,customer,trx
     */
    private function _store_sync_time( $object ) {
        $syncDateTime = date( 'D, d M Y H:i:s T' );
        $fields = array( 'item' => 'last_thirdparty_item_sync', 'customer' => 'last_thirdparty_customer_sync', 'tax' => 'last_thirdparty_tax_sync', 'trx' => 'last_thirdparty_trx_sync' );
        if ( array_key_exists( $object, $fields ) ) {
            $this->integration_model->store_merchant_sync_data( $this->session->get( 'id' ), array( $fields[$object] => "$syncDateTime" ), 'xero' );
        }
    }

    /**
     * function to handle error in response
     * @param array
     * Prints the error and die
     */
    private function _handle_response_error( $response, $return_err = FALSE ) {
        $responseMessage = $response['response'];
        parse_str( $responseMessage, $problem );
        if ( isset( $problem['oauth_problem'] ) && $problem['oauth_problem'] == 'token_expired' ) {
            $errorCode = 'tokens_expired';
        } else {
            $errorCode = 'general_error_item_sync';
        }
        $message = $this->_get_error_message( $errorCode );
        
        if($return_err)
            return array('error' => 1,'message' => $message,'code' => $errorCode);
        else     
        echo json_encode( array( 'error' => 1, 'message' => $message, 'code' => $errorCode ) );
    }

    /**
     * Function to create xml element with root elements set
     * @param string $object (Items)
     * @return SimpleXMLElement
     */
    private function _create_xml_root_element( $object ) {
        $xml = new SimpleXMLElement( "<?xml version=\"1.0\" encoding=\"UTF-8\"?><$object></$object>" );
        return $xml;
    }

    /**
     * Function to get items from Xero modified after $modifiedSince
     * @param String $modifiedSince
     */
    private function _get_items( $modifiedSince ) {
        $xeroOauth = $this->_get_xero_oauth_object();
        $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
        $this->_check_object_errors( $xeroOauth );
        $object = 'Items';
        if ( is_object( $xeroOauth ) ) {
            $this->_check_and_add_modified_since_header( $modifiedSince, $xeroOauth );
            $response = $xeroOauth->request( 'GET', $xeroOauth->url( $object, 'core' ), array( ) );
            unset( $xeroOauth ); // I don't need this anymore
            if ( $this->_is_successful_request( $response ) ) {
                $itemsEffected = array( );
                $items = $this->_extract_object_array_from_response( $response, $object );
                foreach ( $items as $item ) {
                    if ( $this->_is_item_for_sale( $item ) ) {
                        $itemData = $this->_extract_item_data( $item );
                        $status = $this->handle_incoming_item( $itemData );
                        $itemsEffected[] = $status['id'];
                    }
                }
                return $itemsEffected;
            } else {
                $this->_handle_response_error( $response );
            }
        } else {
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' ) );
        }
    }

    /**
     * Function to check if the item is for sale
     * @param array $item
     * @return boolean
     */
    private function _is_item_for_sale( $item ) {
        if ( isset( $item['SalesDetails'] ) )
            return TRUE;
        return FALSE;
    }

    /**
     * Function to extract item data to Rerun format
     * @param array $item
     * @return array
     */
    private function _extract_item_data( $item ) {
        $itemData = array( );
        $itemData['thirdparty_reference_id'] = $item['ItemID'];
        $itemData['name'] = (isset($item['Code'])) ? $item['Code'] : '';
        $itemData['description'] = isset( $item['Description'] ) ? $item['Description'] : '';
        $itemSalesDetails = $item['SalesDetails'];
        $itemData['income_account_reference'] = (isset($itemSalesDetails['AccountCode'])) ? $itemSalesDetails['AccountCode'] : '';
        $itemData['price'] = (isset($itemSalesDetails['UnitPrice'])) ? $itemSalesDetails['UnitPrice'] : '';
        $itemData['created_date'] = date( 'Y-m-d' );
        $itemData['last_updated_time'] = $this->_get_current_UTC_time();
        $itemData['aed_item'] = '3'; // item type = 2 for quickbooks items
        $itemData['app'] = 'xero';
        $itemData['thirdparty_file'] = $this->get_merchant_default_value( 'thirdparty_file' );
        return $itemData;
    }

    /**
     * Function to export items
     * @param string $modifiedSince
     * @param array $excludeList
     * @return Boolean
     */
    private function _export_items( $modifiedSince, $excludeList, $return_err = FALSE ) {
        $this->include_required_libraries();
        $xeroOauth = $this->_get_xero_oauth_object();
        $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
        $this->_check_object_errors( $xeroOauth );
        $object = 'Items';
        if ( is_object( $xeroOauth ) ) {
            $itemsToExport = $this->xero_model->get_items_to_export( 'xero', $this->get_merchant_default_value( 'thirdparty_file' ), $this->session->get( 'id' ), $modifiedSince, NULL, $excludeList );
            if ( empty( $itemsToExport ) )
                return TRUE;
            $exportOrder = $this->_get_export_order( $itemsToExport );
            $itemsToExport = $this->_prepare_export( $itemsToExport, 'Item' );
            $xml = $this->_create_xml_root_element( $object );
            ArrayToXML::array_to_xml( $itemsToExport, $xml );
            $xeroOauth->logIt( $xml->asXML() );
            $response = $xeroOauth->request( 'POST', $xeroOauth->url( $object, 'core' ), array( ), $xml->asXML() );
            if ( $this->_is_successful_request( $response ) ) {
                $responseItems = json_decode( $response['response'], TRUE );
                $this->_item_export_success( $responseItems['Items'], $exportOrder );
                return TRUE;
            } else {
                if($return_err)
                    return $this->_handle_export_errors( $response, $object, $return_err );
                else 
                    $this->_handle_export_errors( $response, $object, $return_err );
            }
        } else {
            if($return_err)
                return array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' );
            else 
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' ) );
        }
    }

    /**
     * Function which return the order in which items will be exported
     * @param array $exportArr
     * @return array
     */
    private function _get_export_order( $exportArr ) {
        $order = array( );
        foreach ( $exportArr as $object ) {
            $order[] = $object->id;
        }
        return $order;
    }

    private function _item_export_success( $received, $exportOrder ) {
        $i = 0;
        $merchantId = $this->session->get( 'id' );
        $app = 'xero';
        $file = $this->get_merchant_default_value( 'thirdparty_file' );
        foreach ( $received as $item ) {
            $rerunItemId = $exportOrder[$i];
            $this->xero_model->update_item_sync_data( $rerunItemId, $merchantId, array( 'thirdparty_reference_id' => $item['ItemID'],
                'income_account_reference' => $item['SalesDetails']['AccountCode'],
                'app' => $app,
                'thirdparty_file' => $file ) );
            $i++;
        }
        return TRUE;
    }

    /**
     * Hanlde errors occured during export opn
     * @param array $response
     * @param $object string
     */
    private function _handle_export_errors( $response, $object, $return_err = FALSE ) {
        $responseArray = json_decode( $response['response'], TRUE );
        if ( $response['code'] == 400 ) { //bad request, something wrong with data
            if ( strtolower( $responseArray['Type'] ) == 'validationexception' ) {
                $this->_handle_validation_errors( $responseArray, $object, $return_err );
            }
        } else {
            $this->_handle_response_error( $response, $return_err );
        }
    }

    /**
     * Hanlder for validation error
     * @param array $responseArray
     * @param string $object
     */
    private function _handle_validation_errors( $responseArray, $object, $return_err = FALSE ) {
        $message = '';
        switch ( $object ) {
            case 'Items' :
                $message = 'Couldn\'t export items because of following reasons <br/>';
                foreach ( $responseArray['Elements'] as $errorElement ) {
                    $message .= 'Item ' . $errorElement['Code'] . ': ';
                    foreach ( $errorElement['ValidationErrors'] as $error ) {
                        $message .= $error['Message'];
                    }
                    $message .= '<br/>';
                }
                break;
            case 'Contacts' :
                $message = 'Couldn\'t export customers because of following reasons <br/>';
                foreach ( $responseArray['Elements'] as $errorElement ) {
                    $message .= 'Customer ' . $errorElement['Name'] . ': ';
                    foreach ( $errorElement['ValidationErrors'] as $error ) {
                        $message .= $error['Message'];
                    }
                    $message .= '<br/>';
                }
                break;
            case 'Invoices' :
                $message = 'Couldn\'t export transactions because of following reasons <br/>';
                foreach ( $responseArray['Elements'] as $errorElement ) {
                    $message .= 'Transaction ' . $errorElement['Reference'] . ': ';
                    foreach ( $errorElement['ValidationErrors'] as $error ) {
                        $message .= $error['Message'];
                    }
                    $message .= '<br/>';
                }
                break;
        }
        if($return_err)
            return array('error' => 1, 'message' => $message);
        else 
        echo json_encode( array( 'error' => 1, 'message' => $message ) );
        exit( 0 );
    }

    /**
     * Prepare the items array to a format in which it can be formatted properly
     * @param array_of_objects $itemsToExport
     * @param string $object
     * @return array
     */
    private function _prepare_export( $itemsToExport, $object, $syncedTransactions=NULL ) {
        $finalArray = array( );
        $i = 0;
        foreach ( $itemsToExport as $key => $item ) {
            if ( $object == 'Item' ) {
                $preparedData = $this->_format_item_to_export( $item );
            } elseif ( $object == 'Contact' ) {
                $preparedData = $this->_format_customer_to_export( $item );
            } elseif ( $object == 'Invoice' ) {
                if ( $item->synced_to_file == 1 )
                    continue;
                $preparedData = $this->_format_transaction_to_export( $item );
            } elseif ( $object == 'Payment' ) {
                $preparedData = $this->_format_payments_to_export( $item, $syncedTransactions[$key]['Invoice']['Total'] );
            }
			if(!empty($preparedData)) {
                $finalArray[$i++][$object] = $preparedData;
            }
        }
        return $finalArray;
    }

    /**
     * Format the item object to Array with field names matching Xero field names
     * @param object $item
     * @return array
     */
    private function _format_item_to_export( $item ) {
        $itemData = array( );
        if ( !empty( $item->thirdparty_reference_id ) ) {
            $itemData['ItemID'] = $item->thirdparty_reference_id;
        }
        $itemData['Code'] = $item->name;
        $itemData['SalesDetails'] = array(
            'UnitPrice' => $item->price,
            'AccountCode' => empty( $item->income_account_reference ) ? $this->get_merchant_default_value( 'thirdparty_default_account_id' ) : $item->income_account_reference
        );
        return $itemData;
    }

    /**
     * Function to do the taxes sync
     * Currently import only
     */
    public function sync_taxes() {
        $merchant_id = $this->session->get( 'id' );
        $this->include_required_libraries();
        $lastSyncTime = $this->xero_model->get_merchant_last_sync_time( $merchant_id, 'tax', 'xero' );
        $taxes = $this->_get_taxes( $lastSyncTime );
        echo json_encode( array( 'error' => 0, 'message' => $this->_get_error_message( 'tax_sync_success' ), 'code' => 'tax_sync_success' ) );
    }

    /**
     * Function to get all taxes from Xero modified after $modifiedSince
     * @param string $modifiedSince
     */
    private function _get_taxes( $modifiedSince ) {
        $xeroOauth = $this->_get_xero_oauth_object();
        $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
        $this->_check_object_errors( $xeroOauth );
        $object = 'TaxRates';
        if ( is_object( $xeroOauth ) ) {
            $this->_check_and_add_modified_since_header( $modifiedSince, $xeroOauth );
            $response = $xeroOauth->request( 'GET', $xeroOauth->url( $object, 'core' ), array( ) );
            unset( $xeroOauth ); // I don't need this anymore
            if ( $this->_is_successful_request( $response ) ) {
                $taxesEffected = array( );
                $this->_store_sync_time( 'tax' );
                $taxes = $this->_extract_object_array_from_response( $response, $object );
                
                foreach ( $taxes as $tax ) {
                    if(isset($tax['CanApplyToRevenue']) && $tax['CanApplyToRevenue'] == 1){
                        $taxData = $this->_extract_tax_data( $tax );
                        $this->handle_incoming_tax( $taxData );
                    }
                }
                return $taxesEffected;
            } else {
                $this->_handle_response_error( $response );
            }
        } else {
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' ) );
        }
    }

    /**
     * Function to extract tax data to Rerun format
     * @param array $tax
     * @return array
     */
    private function _extract_tax_data( $tax ) {
        $taxData = array( );
        $taxData['merchant_id'] = $this->session->get( 'id' );
        $taxData['tax_name'] = $tax['Name'];
        $taxData['tax_code'] = $tax['Name'];
        $taxData['tax_percentage'] = $tax['EffectiveRate'];
        $taxData['aed_tax'] = '3';
        $taxData['created_date'] = $this->_get_current_UTC_time();
        $taxData['last_updated_time'] = $this->_get_current_UTC_time();
        $taxData['tax_aed_record_id'] = $tax['TaxType'];
        $taxData['app'] = 'xero';
        $taxData['thirdparty_file'] = $this->get_merchant_default_value( 'thirdparty_file' );
        return $taxData;
    }

    private function _create_xero_customer_groups() {
        $groupsToCreate = array( $this->xero_customer_group, $this->xero_suppliers_group );
        $groupModel = new Group_Model;
        foreach ( $groupsToCreate as $group ) {
            $groupExists = $groupModel->check_group( $group, $this->session->get( 'id' ) );
            if ( $groupExists[0]->cnt == 0 ) {
                $groupData = array(
                    'name' => $group,
                    'status' => '1',
                    'merchant_id' => $this->session->get( 'id' )
                );

                $groupModel->create( $groupData );
            }
        }
    }

    /**
     * Function to do the customer sync
     * Returns JSON encoded data
     */
    public function sync_customers() {
        $merchant_id = $this->session->get( 'id' );
        $this->include_required_libraries();
        
        $lastSyncTime = $this->xero_model->get_merchant_last_sync_time( $merchant_id, 'customer', 'xero' );
        $customersEffected = $this->_get_customers( $lastSyncTime );
        if ( empty( $customersEffected['conflicts'] ) ) {
            $this->_export_customers( $customersEffected['success'] );
            echo json_encode( array( 'error' => 0, 'message' => $this->_get_error_message( 'customer_sync_success' ), 'code' => 'customer_sync_success' ) );
        } else {
            echo json_encode( array( 'error' => 2, 'message' => $this->_get_error_message( 'customer_sync_conflict' ), 'code' => 'customer_sync_conflict', 'conflicts' => $customersEffected['conflicts'] ) );
        }
    }

    /**
     * Function to get customers from Xero to Rerun
     * @param string $modifiedSince
     * @return array $customersEffected of customer ids created/updated in Rerun
     */
    private function _get_customers( $modifiedSince ) {
        $xeroOauth = $this->_get_xero_oauth_object();
        $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
        $this->_check_object_errors( $xeroOauth );
        $object = 'Contacts';
        if ( is_object( $xeroOauth ) ) {
            $this->_check_and_add_modified_since_header( $modifiedSince, $xeroOauth );
            $response = $xeroOauth->request( 'GET', $xeroOauth->url( $object, 'core' ), array( ) );
            unset( $xeroOauth ); // I don't need this anymore
            if ( $this->_is_successful_request( $response ) ) {
                $customersEffected = array( 'success' => array( ), 'conflicts' => array( ) );
                $this->_store_sync_time( 'customer' );
                $customers = $this->_extract_object_array_from_response( $response, $object );
                foreach ( $customers as $customer ) {
                    $customerData = $this->_extract_customer_data( $customer );
                    $importStatus = $this->handle_incoming_customer( $customerData );
                    if ( strpos( $importStatus['status'], 'conflict' ) === FALSE ) {
                        $this->_add_to_xero_customer_groups( $customer, $importStatus );
                        $customersEffected['success'][] = $importStatus['id'];
                    } else {
                        $customersEffected['conflicts'][] = array( 'id' => $customerData['thirdparty_customer_id'],
                            'conflict' => $importStatus['status'],
                            'customer_name' => Misc :: customer_name_format( $customerData['firstname'], $customerData['lastname'], $customerData['company'], $customerData['is_company'], 0 ),
                            'company' => $customerData['company'],
                            'email' => $customerData['primary_email'],
                            'conflicting_id' => $importStatus['id']
                        );
                        $customerData['conflicting_id'] = $importStatus['id'];
                        $this->_store_conflicted_customers( $customerData );
                    }
                }
                return $customersEffected;
            } else {
                $this->_handle_response_error( $response );
            }
        } else {
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' ) );
        }
    }

    /**
     * Function to extract customer data from Xero customer data
     * @param array $customer
     * @return array
     */
    private function _extract_customer_data( $customer ) {
        $customerData = array( );
        $customerData['thirdparty_customer_id'] = $customer['ContactID'];
        $customerData['company'] = isset( $customer['Name'] ) ? $customer['Name'] : '';
        $customerData['thirdparty_name'] = isset( $customer['Name'] ) ? $customer['Name'] : '';
        $customerData['firstname'] = isset( $customer['FirstName'] ) ? $customer['FirstName'] : '';
        $customerData['lastname'] = isset( $customer['LastName'] ) ? $customer['LastName'] : '';
        //$customerData['primary_email'] = $customer['EmailAddress'];
        $customerData['primary_email'] = isset( $customer['EmailAddress'] ) ? $customer['EmailAddress'] : '';
        $customerData['is_company'] = '1';

        $addresses = $customer['Addresses'];
        $addressCount = count( $addresses ); // get number of address
        for ( $i = 0; $i < $addressCount; $i++ ) {
            $customerAddress = $addresses[$i];
            if ( is_array( $customerAddress ) && !empty( $customerAddress ) ) {
                $addressType = trim( strtolower( $customerAddress['AddressType'] ) );
                if ( 'pobox' == $addressType ) {
                    $customerData['billing_address'] = isset( $customerAddress['AddressLine1'] ) ? $customerAddress['AddressLine1'] : '';
                    $customerData['billing_address2'] = trim( @$customerAddress['AddressLine2'] . chr( 10 ) . @$customerAddress['AddressLine3'] . chr( 10 ) . @$customerAddress['AddressLine4'], chr( 10 ) );
                    $customerData['billing_city'] = isset( $customerAddress['City'] ) ? $customerAddress['City'] : '';
                    $customerData['billing_country'] = isset( $customerAddress['Country'] ) ? $customerAddress['Country'] : '';
                    $customerData['billing_state'] = isset( $customerAddress['Region'] ) ? $customerAddress['Region'] : '';
                    $customerData['billing_zipcode'] = isset( $customerAddress['PostalCode'] ) ? $customerAddress['PostalCode'] : '';
                } elseif ( 'street' == $addressType ) {
                    $customerData['address1'] = isset( $customerAddress['AddressLine1'] ) ? $customerAddress['AddressLine1'] : '';
                    $customerData['address2'] = trim( @$customerAddress['AddressLine2'] . chr( 10 ) . @$customerAddress['AddressLine3'] . chr( 10 ) . @$customerAddress['AddressLine4'], chr( 10 ) );
                    $customerData['city'] = isset( $customerAddress['City'] ) ? $customerAddress['City'] : '';
                    $customerData['country'] = isset( $customerAddress['Country'] ) ? $customerAddress['Country'] : '';
                    $customerData['state'] = isset( $customerAddress['Region'] ) ? $customerAddress['Region'] : '';
                    $customerData['zip_code'] = isset( $customerAddress['PostalCode'] ) ? $customerAddress['PostalCode'] : '';
                }
            }
        }
        $phones = $customer['Phones'];
        $phoneCount = count( $phones ); //get count of phones
        for ( $i = 0; $i < $phoneCount; $i++ ) {
            $customerPhone = $phones[$i];
            $deviceType = strtolower( trim( $customerPhone['PhoneType'] ) );
            $rerunType = '';
            if ( $deviceType == 'mobile' ) {
                $rerunType = 'mobile';
            } elseif ( $deviceType == 'default' ) {
                $rerunType = 'primary_phone';
            } elseif ( $deviceType == 'fax' ) {
                $rerunType = 'fax';
            } elseif ( $deviceType == 'ddi' ) {
                $rerunType = 'phone';
            }
            if ( !empty( $rerunType ) ) {
                $phoneNumber = trim( $customerPhone['PhoneCountryCode'] . '-' . $customerPhone['PhoneAreaCode'] . '-' . $customerPhone['PhoneNumber'], '-' );
                $customerData[$rerunType] = $phoneNumber;
            }
        }
        $customerData['app'] = 'xero';
        $customerData['thirdparty_file'] = $this->get_merchant_default_value( 'thirdparty_file' );
        return $customerData;
    }

    private function _add_to_xero_customer_groups( $customerXeroData, $rerunData ) {
        $merchantId = $this->session->get( 'id' );
        $groupsToAdd = $this->xero_model->get_xero_customer_groups( array( $this->xero_customer_group, $this->xero_suppliers_group ), $merchantId );
        $supplierGroupId = 0;
        $customerGroupId = 0;
        foreach ( $groupsToAdd as $group ) {
            if ( $group->name == $this->xero_customer_group ) {
                $customerGroupId = $group->id;
            }
            if ( $group->name == $this->xero_suppliers_group ) {
                $supplierGroupId = $group->id;
            }
        }
        if ( $customerGroupId == 0 || $supplierGroupId == 0 ) {
            $this->_create_xero_customer_groups();
        }
        $groupModel = new Group_Model;
        $customerId = $rerunData['id'];
        if ( isset( $customerXeroData['IsSupplier'] ) && $customerXeroData['IsSupplier'] == TRUE ) {
            if ( $groupModel->_isAssignedGroup( $supplierGroupId, $customerId ) == 0 ) {
                $groupModel->add_to_group( array( 'merchant_id' => $merchantId, 'group_id' => $supplierGroupId, 'customer_id' => $customerId ) );
            }
        }

        if ( isset( $customerXeroData['IsCustomer'] ) && $customerXeroData['IsCustomer'] == TRUE ) {
            if ( $groupModel->_isAssignedGroup( $customerGroupId, $customerId ) == 0 ) {
                $groupModel->add_to_group( array( 'merchant_id' => $merchantId, 'group_id' => $customerGroupId, 'customer_id' => $customerId ) );
            }
        }
    }

    /**
     * function to import customers from Xero
     * @param array $excludeList
     */
    public function _export_customers( $excludeList = array(), $return_err = FALSE) {
        $this->include_required_libraries();
        $xeroOauth = $this->_get_xero_oauth_object();
        $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
        $this->_check_object_errors( $xeroOauth );
        if ( is_object( $xeroOauth ) ) {
            $merchantId = $this->session->get( 'id' );
            $object = 'Contacts';
            $customersToExport = $this->xero_model->get_customers_to_export( 'xero', $this->get_merchant_default_value( 'thirdparty_file' ), $merchantId, NULL, $excludeList );
            if ( empty( $customersToExport ) )
                return TRUE;
            $exportOrder = $this->_get_export_order( $customersToExport );
            $customersToExport = $this->_prepare_export( $customersToExport, 'Contact' );
            $xml = $this->_create_xml_root_element( $object );
            ArrayToXML::array_to_xml( $customersToExport, $xml );
            //$response = $xeroOauth->request( 'POST', $xeroOauth->url( $object, 'core' ), array(), $xml->asXML() );
            $response = $xeroOauth->request('POST', $xeroOauth->url($object, 'core'), array(), $xml->asXML());
			unset( $xeroOauth ); // I don't need this anymore
            if ( $this->_is_successful_request( $response ) ) {
                $responseCustomers = json_decode( $response['response'], TRUE );
                $this->_customer_export_success( $responseCustomers[$object], $exportOrder );
                return TRUE;
            } else {
                if($return_err)
                    return $this->_handle_export_errors( $response, $object, $return_err );
                else 
                    $this->_handle_export_errors( $response, $object, $return_err );
            }
        } else {
            if($return_err)
                return array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' );
            else     
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' ) );
        }
    }

    /**
     * Handler for customer import success
     * @param array $received
     * @param array $exportOrder
     * @return Boolean
     */
    private function _customer_export_success( $received, $exportOrder ) {
        $i = 0;
        foreach ( $received as $customer ) {
            $rerunCustomerId = $exportOrder[$i];
            $this->update_sync_data( $rerunCustomerId, 'xero', array( 'thirdparty_customer_id' => $customer['ContactID'], 'thirdparty_name' => $customer['Name'] ) );
            $i++;
        }
        return TRUE;
    }

    /**
     * Function to format customer data according to Xero fields
     * @param array $customer
     */
    private function _format_customer_to_export( $customer ) {
        $customerData = array( );
        if ( $customer->synced_to_file == 1 ) {
            $customerData['ContactID'] = $customer->thirdparty_customer_id;
        }
        $customerData['Name'] = $this->_get_customer_name( $customer, 'xero' );
        $customerData['FirstName'] = $customer->firstname;
        $customerData['LastName'] = $customer->lastname;
        $customerData['EmailAddress'] = $customer->primary_email;
        $customerData['Addresses'] = array( );
        $addressCount = 0;
        if ( !empty( $customer->billing_address ) ) {
            $address = array( );
            $address['AddressType'] = 'POBOX';
            $address['AddressLine1'] = $customer->billing_address;
            $address2Arr = explode( chr( 10 ), $customer->billing_address2 );
            $address['AddressLine2'] = isset( $address2Arr[0] ) ? $address2Arr[0] : '';
            $address['AddressLine3'] = isset( $address2Arr[1] ) ? $address2Arr[1] : '';
            $address['AddressLine4'] = isset( $address2Arr[2] ) ? $address2Arr[2] : '';
            $address['City'] = $customer->billing_city;
            $address['Country'] = $customer->billing_country;
            $address['Region'] = $customer->billing_state;
            $address['PostalCode'] = $customer->billing_zipcode;
            $customerData['Addresses'][$addressCount]['Address'] = $address;
            $addressCount++;
        }
        if ( !empty( $customer->address1 ) ) {
            $address = array( );
            $address['AddressType'] = 'STREET';
            $address['AddressLine1'] = $customer->address1;
            $address2Arr = explode( chr( 10 ), $customer->address2 );
            $address['AddressLine2'] = isset( $address2Arr[0] ) ? $address2Arr[0] : '';
            $address['AddressLine3'] = isset( $address2Arr[1] ) ? $address2Arr[1] : '';
            $address['AddressLine4'] = isset( $address2Arr[2] ) ? $address2Arr[2] : '';
            $address['City'] = $customer->city;
            $address['Country'] = $customer->billing_country;
            $address['Region'] = $customer->state;
            $address['PostalCode'] = $customer->zip_code;
            $customerData['Addresses'][$addressCount]['Address'] = $address;
        }
        $phoneCount = 0;
        $customerData['Phones'] = array( );
        if ( !empty( $customer->primary_phone ) ) {
            $phoneParts = $this->_extract_phone_fields( $customer->primary_phone );
            $phoneParts['PhoneType'] = 'DEFAULT';
            $customerData['Phones'][$phoneCount]['Phone'] = $phoneParts;
            $phoneCount++;
        }
        if ( !empty( $customer->phone ) ) {
            $phoneParts = $this->_extract_phone_fields( $customer->phone );
            $phoneParts['PhoneType'] = 'DDI';
            $customerData['Phones'][$phoneCount]['Phone'] = $phoneParts;
            $phoneCount++;
        }
        if ( !empty( $customer->mobile ) ) {
            $phoneParts = $this->_extract_phone_fields( $customer->phone );
            $phoneParts['PhoneType'] = 'MOBILE';
            $customerData['Phones'][$phoneCount]['Phone'] = $phoneParts;
            $phoneCount++;
        }
        if ( !empty( $customer->fax ) ) {
            $phoneParts = $this->_extract_phone_fields( $customer->phone );
            $phoneParts['PhoneType'] = 'FAX';
            $customerData['Phones'][$phoneCount]['Phone'] = $phoneParts;
        }
        return $customerData;
    }

    /**
     * Function to extarct phone number fields
     * @param atring $phoneNumber
     * @return array
     */
    private function _extract_phone_fields( $phoneNumber ) {
        if ( strlen( str_replace( '-', '', $phoneNumber ) ) > 10 ) {
            $phoneNumber = explode( '-', $phoneNumber );
            $return = array( 'PhoneNumber' => '', 'PhoneCountryCode' => '', 'PhoneAreaCode' => '' );
            $parts = count( $phoneNumber );
            if ( $parts == 1 ) {
                $return['PhoneNumber'] = $phoneNumber[0];
            } elseif ( $parts == 2 ) {
                $return['PhoneAreaCode'] = $phoneNumber[0];
                $return['PhoneNumber'] = $phoneNumber[1];
            } elseif ( $parts == 3 ) {
                $return['PhoneCountryCode'] = $phoneNumber[0];
                $return['PhoneAreaCode'] = $phoneNumber[1];
                $return['PhoneNumber'] = $phoneNumber[2];
            }
            return $return;
        } else {
            return array( 'PhoneNumber' => $phoneNumber, 'PhoneCountryCode' => '', 'PhoneAreaCode' => '' );
        }
    }

    /**
     * function to the item export
     * @param array $excludeList item ids to exclude
     */
    private function _do_item_export( $excludeList = array(), $return_err = FALSE ) {
        $lastSyncTime = $this->xero_model->get_merchant_last_sync_time( $this->session->get( 'id' ), 'item', 'xero' );
        $lastSyncDBTime = empty( $lastSyncTime ) ? '' : date( 'Y-m-d H:i:s', strtotime( $lastSyncTime ) );
        if($return_err)
            return $this->_export_items( $lastSyncDBTime, $excludeList, $return_err );
        else 
        $this->_export_items( $lastSyncDBTime, $excludeList );
    }

    /**
     * function to do the transaction sync to Xero
     * Transactions will be exported as fully paid Invoices
     * It is a 2 step process -
     * Step 1- Send Transactions as Invoices
     * Step 2- Send Payments for these Invoices so that they are marked as Paid in Xero
     */
    public function sync_transactions() {
        $this->include_required_libraries();
        $this->_export_transactions();
        echo json_encode( array( 'error' => 0, 'message' => $this->_get_error_message( 'transaction_sync_success' ), 'code' => 'transaction_sync_success' ) );
    }

    /**
     * Function for export transactions
     */
    private function _export_transactions() {
        $this->include_required_libraries();
        $xeroOauth = $this->_get_xero_oauth_object();
        $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
        $this->_check_object_errors( $xeroOauth );
        
        if ( is_object( $xeroOauth ) ) {
            $merchantId = $this->session->get( 'id' );
            $object = 'Invoices';
            $xeroid = $this->get_merchant_default_value('thirdparty_payment_account_id');
            
            //Check if the selected account exists in xero
            if($xeroid) {
                $check_account = $this->_check_account_in_xero($xeroid);
                if(!$check_account){
                    $this->xero_model->set_account_status ('',$xeroid);
                    echo json_encode( array( 'error' => 1, 'message' => 'The account you selected for payments for the Invoices does not exist in xero, please select a different account' ) );
                    die();
                }
            } else {
                echo json_encode( array( 'error' => 1, 'message' => 'Please select the Account where you would like to record payments for the Invoices created in Rerun' ) );
                die();
            }
            // do the item sync
            $items = $this->_do_item_export(array(), 1);

            //do the customer export
            $customer = $this->_export_customers( array(), 1 );

            $transToExport = $this->integration_model->get_transactions_to_export( $merchantId, 'xero', $this->get_merchant_default_value( 'thirdparty_file' ) );
            //print_r($transToExport);exit;
            if ( empty( $transToExport ) ) {
                $this->_store_sync_time('trx');
                return TRUE;
            }
            $exportOrder = $this->_get_export_order( $transToExport );
            $formattedTransToExport = $this->_prepare_export( $transToExport, 'Invoice' );
            $xml = $this->_create_xml_root_element( $object );
            ArrayToXML::array_to_xml( $formattedTransToExport, $xml );
            $xeroOauth->logIt( $xml->asXML() );
            $response = $xeroOauth->request( 'POST', $xeroOauth->url( $object, 'core' ), array( ), $xml->asXML() );
			unset( $xeroOauth ); // I don't need this anymore
            if ( $this->_is_successful_request( $response ) ) {
                $responseTransactions = json_decode( $response['response'], TRUE );
                $this->_transaction_export_success( $responseTransactions[$object], $exportOrder, $transToExport );
                $paymentsApplied = $this->_apply_invoice_payments( $transToExport, $formattedTransToExport );
                $this->_store_sync_time('trx');
                return TRUE;
            } else {
                $this->_handle_export_errors( $response, $object );
            }            
        } else {
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' ) );
        }
    }

    private function _transaction_export_success( $received, $exportOrder, &$transExported ) {
        $i = 0;
        $app = 'xero';
        $file = $this->get_merchant_default_value( 'thirdparty_file' );
        foreach ( $received as $transaction ) {
            $rerunTransactionId = $exportOrder[$i];
            $invoiceId = $transaction['InvoiceID'];
            $this->integration_model->update_transaction_sync_status( $rerunTransactionId, array( 'thirdparty_reference_id' => $invoiceId ), $app, $file );
            $transExported[$i]->invoiceId = $invoiceId;
            $i++;
        }
    }

    private function _apply_invoice_payments( $transactionInvoices, $formattedTransExported=NULL ) {
        $xeroOauth = $this->_get_xero_oauth_object();
        $xeroOauth = $this->_set_tokens_to_object( $xeroOauth );
        $this->_check_object_errors( $xeroOauth );
        if ( is_object( $xeroOauth ) ) {
            $object = 'Payments';
            $exportOrder = $this->_get_export_order( $transactionInvoices );
            $transactionInvoices = $this->_prepare_export( $transactionInvoices, 'Payment', $formattedTransExported );
            $xml = $this->_create_xml_root_element( $object );
            ArrayToXML::array_to_xml( $transactionInvoices, $xml );
            $response = $xeroOauth->request( 'POST', $xeroOauth->url( $object, 'core' ), array( ), $xml->asXML() );
	     	$xeroOauth->logIt( $xml->asXML() );
			//unset( $xeroOauth ); // I don't need this anymore
            if ( $this->_is_successful_request( $response ) ) {
                $responsePayments = json_decode( $response['response'], TRUE );
                $this->_payment_export_success( $responsePayments[$object], $exportOrder );
                return TRUE;
            } else {
                $this->_handle_export_errors( $response, $object );
            }
        } else {
            echo json_encode( array( 'error' => 1, 'message' => $this->_get_error_message( 'not_object' ), 'code' => 'not_object' ) );
        }
    }

    private function _payment_export_success( $received, $exportOrder ) {
        $i = 0;
        $app = 'xero';
        $file = $this->get_merchant_default_value( 'thirdparty_file' );
        foreach ( $received as $payment ) {
            $rerunTransactionId = $exportOrder[$i];
            $paymentId = $payment['PaymentID'];
            if ( !empty( $paymentId ) )
                $this->integration_model->update_transaction_sync_status( $rerunTransactionId, array( 'is_synced' => '1' ), $app, $file );
            $i++;
        }
    }

    private function _format_transaction_to_export( $transaction ) {
        /**
         * Pre-checks
         */
        $transactionItems = array( );
        $transactionAmounts = array( );
        $oldTransaction = TRUE;
        $app = 'xero';
        $file = $this->get_merchant_default_value( 'thirdparty_file' );
        $transactionId = $transaction->transaction_id;
        if ( !empty( $transaction->stream_subscriber_id ) ) {
            $transactionAmounts = $this->transaction_model->get_transaction_amounts( $transactionId ); //get the transaction amounts from the transaction_amounts table. If rows exist here, then fetch from here..else fetch from stream_subscriber
            $oldTransaction = empty( $transactionAmounts );
            if ( $oldTransaction ) {
                $transactionItems = $this->integration_model->get_transaction_subscription_items_old_trans( $transaction->stream_subscriber_id, $app, $file );
            } else {
                $transactionItems = $this->integration_model->get_transaction_subscription_items_new_trans( $transactionId, $app, $file );
            }
            if ( empty( $transactionItems ) ) { // don't sync transactions w/o items
                return false;
            }
        } else {
            $transactionAmounts = array( );
            $oldTransaction = TRUE; // coz no entry would be made in transaction_amounts table
            if ( $transaction->transaction_type == '2' ) { //charged transactions
                $transactionItems = $this->integration_model->get_or_create_charge_item( $this->session->get( 'id' ), $app, $file );
                if ( empty( $transactionItems ) ) {
                    return false;
                } else if ( $transactionItems[0]->is_existed == 'no' ) {
                    $this->_do_item_export( array( ) );
                }
                $transactionItems[0]->item_price = $transaction->transaction_amount;
                $transactionItems = array_slice( $transactionItems, 0, 1);
            } else if ($transaction->transaction_type == '0' && $transaction->is_credited == '1') { 
                $transactionItems = $this->integration_model->get_or_create_credit_charge_item( $this->session->get( 'id' ), $app, $file, $transaction->transaction_amount );
                 if ( empty( $transactionItems ) ) {
                    return false;
                } else if ( $transactionItems[0]->is_existed == 'no' ) {
                    $this->_do_item_export( array( ) );
                }
                $transactionItems[0]->item_price = $transaction->transaction_amount;
                $transactionItems = array_slice( $transactionItems, 0, 1);
            } else { // no charge item exists OR craeting failed
                return false;
            }
        }
        //Pre-checks done
        //Get tax and discounts
        $taxAndDiscount = array( );
        if ( $oldTransaction ) {
            $taxAndDiscount = $this->_calculate_tax_discount( $transaction->base_amount, $transaction->discount_amount, $transaction->is_amount, $transaction->sales_tax_1, $transaction->sales_tax_2 );
        } else {
            $taxAndDiscount['discount'] = $transactionAmounts[0]->discount_amount;
            if ( !empty( $transactionAmounts[0]->tax1_amount ) ) {
                $taxAndDiscount['tax'] = $transactionAmounts[0]->tax1_amount;
            } else if ( !empty( $transactionAmounts[0]->tax2_amount ) ) {
                $taxAndDiscount['tax'] = $transactionAmounts[0]->tax2_amount;
            } else {
                $taxAndDiscount['tax'] = 0;
            }
        }
        //Get applied taxes
        $taxes = array( );
        if ( !empty( $transaction->stream_subscriber_id ) ) { // checking for charged transactions where no tax is involved and stream sub id is null
            $taxes = $oldTransaction ? $this->integration_model->get_transaction_taxes_old_trans( $transaction->stream_subscriber_id, $app, $file ) : $this->integration_model->get_transaction_taxes_new_trans( $transactionId, $app, $file );
        }
        //Start preparing the final array
        $invoiceData = array( );
        $invoiceData['Type'] = 'ACCREC'; // Account Receivable Always
        // Add Contact Data
        $contactData = array( );
        $contactData['ContactID'] = $transaction->thirdparty_customer_id;
        $contactData['Name'] = $this->_get_customer_name( $transaction, 'xero' );
        $invoiceData['Contact'] = $contactData;
        //Contact data added
        //Prepare transaction description
        $transactionDescription = $transaction->transaction_description;
        if ( empty( $transactionDescription ) ) {
            $transactionDescription = $this->integration_model->get_stream_name_as_description( $transaction->payment_stream_id );
            if ( empty( $transactionDescription ) ) { ///ufff
                $transactionDescription = 'Rerun Transaction';
            }
        }

        //Add Line Items
        $lineItems = array( );
        $itemCount = 0;
        $subTotal = 0;

        //if no taxes applied, then in Xero also no taxes should be applied, else apply what was applied in Rerun
        $taxType = empty( $taxes ) ? 'NONE' : $taxes[0]->tax_aed_record_id;
        $estimatedXeroTax = 0;
        $amountCalculator = new Amount_calculator();
        $baseAmount = $oldTransaction ? $transaction->base_amount : $transactionAmounts[0]->base_amount;
        $taxPercent = $oldTransaction ? $transaction->sales_tax_1 : $transactionAmounts[0]->tax1_percentage;
        $appliedTax = $taxAndDiscount['tax'];
        $discountApplied = 0;

        foreach ( $transactionItems as $item ) {
            $line_tax_amt = $amountCalculator->get_item_tax( $baseAmount, $item->item_price, 0, 1, $taxPercent );
            $lineTax = $amountCalculator->round_price($line_tax_amt);
            $estimatedXeroTax = $estimatedXeroTax + $lineTax;
            $lineItem = array( );
            $lineItem['Description'] = $transactionDescription;
            $lineItem['Quantity'] = 1;  // Always in Rerun
            $lineItem['AccountCode'] = isset( $item->income_account_reference ) ? $item->income_account_reference : $this->get_merchant_default_value( 'thirdparty_default_account_id' );
            $lineItem['UnitAmount'] = $item->item_price;
            $lineItem['ItemCode'] = $item->name;
            $lineItem['TaxType'] = $taxType;
            $lineItem['TaxAmount'] = $lineTax;
            $lineItems[$itemCount] = array( );
            $lineItems[$itemCount]['LineItem'] = $lineItem;
            $itemCount++;
            $subTotal += $item->item_price;
        }

        if ( $taxAndDiscount['discount'] > 0 ) {
            $discountItemDetails = $this->_check_create_sync_xero_discount_item();
            $discountApplied = 0 - $taxAndDiscount['discount'];
            $lineTax = $amountCalculator->round_price( $amountCalculator->get_item_tax( $baseAmount, $discountApplied, 0, 1, $taxPercent ) );
            $estimatedXeroTax = $estimatedXeroTax + $lineTax;
            $lineItem = array( );
            $lineItem['Description'] = 'Rerun Discount';
            $lineItem['Quantity'] = 1;  // Always in Rerun
            $lineItem['AccountCode'] = $this->get_merchant_default_value( 'thirdparty_discount_account_id' );
            $lineItem['UnitAmount'] = $discountApplied; //send the -ve amount
            $lineItem['ItemCode'] = $discountItemDetails['name'];
            $lineItem['TaxType'] = $taxType; //no tax on discount
            $lineItem['TaxAmount'] = $lineTax;
            $lineItems[$itemCount] = array( );
            $lineItems[$itemCount]['LineItem'] = $lineItem;
            $itemCount++;
            $subTotal += $discountApplied;
        }
        // Hell...now do the tax adjustments
        $indextoChange = $discountApplied == 0 ? ($itemCount - 1) : ($itemCount - 2); // get the index of last actual line item..not the discount line
        $taxOff = $estimatedXeroTax - $appliedTax;
        
        /*
        echo 'est-->'.$estimatedXeroTax;
        echo 'app-->'.$appliedTax;
        echo 'taxoff-->'.$taxOff;
        */
        
        //Commenting the below as it was not matching with xero calculations
        //$lineItems[$indextoChange]['LineItem']['TaxAmount'] -= $taxOff;
        
        $invoiceData['LineItems'] = $lineItems;
        //Added Line Items
        //Set dates
        $invoiceData['Date'] = date( 'Y-m-d', strtotime( $transaction->transaction_date ) );
        $invoiceData['DueDate'] = empty( $transaction->transaction_due_date ) ? date( 'Y-m-d', strtotime( $transaction->transaction_date ) ) : date( 'Y-m-d', strtotime( $transaction->transaction_due_date ) );

        //Are line items inclusive/exclusive of tax
        $invoiceData['LineAmountTypes'] = 'Exclusive';

        //Set Rerun transaction id as Reference
        $invoiceData['Reference'] = $transaction->transaction_reference_id;

        //set Invoice sttaus as Approved
        $invoiceData['Status'] = 'AUTHORISED';

        //sub total = sum of items - discount
        $invoiceData['SubTotal'] = $subTotal;

        //Tax
        $invoiceData['TotalTax'] = $estimatedXeroTax;

        //Total amount

        //Commenting the below as it was not matching with xero calculations
        //$invoiceData['Total'] = $transaction->transaction_amount;
        $invoiceData['Total'] = ($subTotal + $estimatedXeroTax);
                
        return $invoiceData;
    }

    private function _check_create_sync_xero_discount_item() {
        $existingItemData = $this->item_model->check_item_exists( $this->session->get( 'id' ), trim( $this->discount_item_name ) );
        $merchant_id = $this->session->get( 'id' );
        if ( empty( $existingItemData ) ) {
            $itemData = array(
                'merchant_id' => $merchant_id,
                'name' => $this->discount_item_name,
                'price' => -1,
                'aed_item' => '3',
                'thirdparty_reference_id' => NULL,
                //'income_account_reference' => $this->get_merchant_default_value('thirdparty_discount_account_id'),
                'created_date' => date( 'Y-m-d' ),
                'last_updated_time' => date( 'Y-m-d H:i:s' )
            );
            $id = $this->item_model->add( $itemData );
            $itemData['id'] = $id;
        } else {
            $itemData = get_object_vars( $existingItemData[0] );
        }

        if ( empty( $itemData['thirdparty_reference_id'] ) ) {
            $this->_do_item_export();
        }

        return $itemData;
    }

    private function _format_payments_to_export( $transaction, $formattedTransTotal ) {        
        $paymentData = array( );
        //add invoice data
        $paymentData['Invoice'] = array( 'InvoiceID' => $transaction->invoiceId );
        //add account data
        $paymentData['Account'] = array( 'AccountID' => $this->get_merchant_default_value( 'thirdparty_payment_account_id' ) );
        //set the date as transaction date
        $paymentData['Date'] = date( 'Y-m-d', strtotime( $transaction->transaction_date ) );
        //set the amount
        //$paymentData['Amount'] = $transaction->transaction_amount;
        $paymentData['Amount'] = $formattedTransTotal;
        
        //set the reference
        $paymentData['Reference'] = 'Gateway Id- ' . $transaction->gateway_transaction_id . ' and Auth code- ' . $transaction->gateway_authorization_code . '.';

        return $paymentData;
    }

    public function complete_customer_sync($is_refresh = 0) {
        $conflictedCustomerIds = $this->input->post( 'customer_ids_conflicted' );
        $selectedActions = $this->input->post( 'conflict_options' );
        $customerCount = count( $conflictedCustomerIds );
        $actionsCount = count( $selectedActions );
        if ( $customerCount == $actionsCount ) {
            for ( $i = 0; $i < $customerCount; $i++ ) {
                $customerId = $conflictedCustomerIds[$i];
                $action = trim( $selectedActions[$i] );
                $customerData = $this->_get_conflicted_customer( $customerId );
                if ( FALSE != $customerData ) {
                    if ( 'dont-sync' == $action ) {
                        continue;
                    } else if ( 'update-existing' == $action ) {
                        $conflctingId = $customerData['conflicting_id'];
                        unset( $customerData['conflicting_id'] );
                        $this->_update_customer_in_rerun( $conflctingId, $customerData );
                    }
                }
            }
        }
        if(!$is_refresh) 
            $this->_export_customers();
        echo json_encode( array( 'error' => 0, 'message' => $this->_get_error_message( 'customer_sync_success' ), 'code' => 'customer_sync_success' ) );
    }
    
    /**
     * This method will sync items, taxes, customers and accounts from xero
     * @param 
     * @Author Chethan Krishnaswamy
     */
    public function refresh_lists_sync() {
        $this->get_accounts();
        $this->_get_items(NULL);
        $this->_get_taxes(NULL);
        $customersEffected = $this->_get_customers(NULL);
        if (!empty($customersEffected['conflicts'])) {
            echo json_encode(array('error' => 2, 'message' => $this->_get_error_message('customer_sync_conflict'), 'code' => 'customer_sync_conflict', 'conflicts' => $customersEffected['conflicts']));
            exit(0);
        }
        echo json_encode(array('error' => 0, 'message' => 'All lists refreshed successfully.'));
    }

    /**
     * This method will return the last sync time
     * @param 
     * @Author Chethan Krishnaswamy
     */
	 public function get_last_sync_time() {
	 	$this->auto_render = false;
	 	$entities = $this->input->post('entities');
	 	$allowedEntities = array('customer','item','tax','trx');
	 	$entities = explode('#',$entities);
	 	$selectFields = array();
	 	foreach($entities as $entity) {
	 		if(!empty($entity) && in_array($entity,$allowedEntities)) {
	 			$field = 'last_thirdparty_'.$entity.'_sync';
	 			$selectFields[$entity] = $field;
	 		}
	 	}
	 	if(!empty($selectFields)) {
	 		$selectField = implode(',',$selectFields);
	 		$lastSyncedDetails = $this->xero_model->get_last_sync_time($selectField,$this->session->get('id'),$this->app);
	 		$lastSyncedDetails = $lastSyncedDetails[0];
	 		$return = array();
	 		foreach($lastSyncedDetails as $key => $value) {
	 			$return[array_search($key,$selectFields)] = !empty($value) ? QBLib::format_datetime($value) : 'Never synced';
	 		}
	 		echo json_encode(array('error'=>0,'last_sync_details'=>$return));
	 	} else {
	 		echo json_encode(array('error'=>1,'last_sync_details'=>array()));
	 	}
	 }    
     
     public function should_show_sync_warning() {
        $show = $this->xero_model->is_trial_non_intuit_merchant($this->session->get('id'));
        echo json_encode(array('error' => 0,'show'=>$show));
        exit(0);
    }
}

// class ends
?>
