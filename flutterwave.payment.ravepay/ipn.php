<?php

/** RM: Customisations have been annotated using a custom PHPDoc tag @custom */

/* 
 * Ravepay Extension for CiviCRM - Circle Interactive 2012
 * Author: andyw@circle
 * Callback Notification Class
 *
 * Distributed under GPL v2
 * https://www.gnu.org/licenses/gpl-2.0.html
 */

require_once 'CRM/Core/Payment/BaseIPN.php';


class flutterwave_payment_ravepay_notify extends CRM_Core_Payment_BaseIPN {

    protected $ravepay;
    static    $_paymentProcessor = null;
    
    function __construct($ravepay) {
        $this->ravepay = $ravepay;
        parent::__construct();
    }
    function verify_payment($reference)
    {
      
        $settings  =  self::$_paymentProcessor;
       if ($settings['is_test'] == 0) {
            $url =  'https://api.ravepay.co/flwv3-pug/getpaidx/api/verify';
            
            $secret_key = $settings['password'];
            
        } else {
            $url =  'https://ravesandboxapi.flutterwave.com/flwv3-pug/getpaidx/api/verify';
            $secret_key = $settings['password'];
            
        }
        $response = [];
        $postdata = array(
            'flw_ref' => $reference,
            'SECKEY' => $secret_key,
            'sslverify' => false
        );
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));                                              
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $headers = [
            'Content-Type: application/json',
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        $result =  json_decode($response, true);
        return $result;
    }


    function main($component = 'contribute') {
        
        require_once 'CRM/Utils/Request.php';
        require_once 'CRM/Core/DAO.php';
        
        $ravepay = &$this->ravepay;
        
        $objects = $ids = $input  = array();
                 
        define('CCRM_RAVE_ORDER_KEY', self::retrieve('qf', 'String', 'GET', false));
        
        $this->component = $input['component']  = $component;
        $url         = ($this->component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
        $cancel      = ($this->component == 'event') ? '_qf_Register_display'   : '_qf_Main_display';
        $cancelURL = CRM_Utils_System::url($url, "$cancel=1&cancel=1&qfKey=".CCRM_RAVE_ORDER_KEY, true, null, false, true);
        if(isset($_REQUEST['flwref']) && isset($_REQUEST['reference'])){
            $trxref = $_REQUEST['reference'];
            $order_id = substr($trxref, 0, strpos($trxref, '_'));
            
            if(!$order_id) {
                $order_id = 0;
            }
            if($order_id != 0 ){
                $ids['contact']       = self::retrieve('cid',   'Integer', 'GET', true);
                $ids['contribution']  = $order_id;
                // $this->getInput($input, $ids);
                $input['trxn_id'] =	$trxref;
                $input['newInvoice'] = $trxref;
                if ($component == 'event') {
                    $ids['event']       = self::retrieve('eid', 'Integer', 'GET', true);
                    $ids['participant'] = self::retrieve('pid', 'Integer', 'GET', true);
                    $ids['membership']  = null;
                } else {
                    
                    $ids['membership']          = self::retrieve('mid',  'Integer', 'GET', false);
                    $ids['contributionRecur']   = self::retrieve('crid', 'Integer', 'GET', false);
                    $ids['contributionPage']    = self::retrieve('cpid', 'Integer', 'GET', false);
                    $ids['related_contact']     = self::retrieve('rcid', 'Integer', 'GET', false);
                    $ids['onbehalf_dupe_alert'] = self::retrieve('obda', 'Integer', 'GET', false);
                
                }
        
                if (!$this->validateData($input, $ids, $objects)) {
                    $ravepay->error('Transaction failed: Unable to validate data', __CLASS__ . '::' . __METHOD__, __LINE__);
                    return false;
                }
                self::$_paymentProcessor = &$objects['paymentProcessor'];

                $flwReference = $_REQUEST['flwref'];
                $response_api =  $this->verify_payment($flwReference);
                if(($response_api['status'] == 'success') && isset($response_api['data']['status']) && ($response_api['data']['status'] == 'successful')) {
                    
                    if ($component == 'contribute') {
                        
                        if (@$ids['contributionRecur']) {
                            
                            $first = true;
                            if ($objects['contribution']->contribution_status_id == 1)
                                $first = false;
                            
                            return $this->recur($input, $ids, $objects, $first,$response_api);
                        
                        } else {
                            $ravepay->log('Transaction success: contribution', 4);
                            return $this->single($input, $ids, $objects, false, false,$response_api);
                        }
                    
                    } else {
                        $ravepay->log('Transaction success: event', 4);
                        
                        return $this->single($input, $ids, $objects, false, false,$response_api);
                    
                    }
                }else{
                    
                    CRM_Utils_System::redirect($cancelURL);
                }
            }
           
        }
        CRM_Utils_System::redirect($cancelURL);
       
        
    }
    
    // There is no IPN notification for a REPEAT transaction. Instead, we get an instant response,
    // then call this function to complete transaction / mark as failed.
    public function processRepeatTransaction(&$params, &$response) {
        
        $ravepay = &$this->ravepay;
        $objects = array();
   
        if ($response['Status'] == 'OK') {
            
            // Spoof enough notification params to allow IPN code to complete the transaction ..
            $ids = array(
                'contact'           => $params['contactID'],
                'contribution'      => $params['contributionID'],
                'contributionRecur' => $params['recurID'],
                'membership'        => $params['membershipID']
            );
            
            $input = array(
                'component'     => 'contribute',
                'paymentStatus' => $response['Status'],
                'invoice'       => $params['RelatedVendorTxCode'],
                'trxn_id'       => $response['VPSTxId'],
                'amount'        => $params['amount']
            );
            
            // define('RAVEPAY_QFKEY', ''); // Not relevant in this context, but define something to prevent warnings
            
            if (!$this->validateData($input, $ids, $objects)) {
                $ravepay->error('Transaction failed: Unable to validate data', __CLASS__ . '::' . __METHOD__, __LINE__);
                return false;
            }

            // Suppress any output which may occur, then run IPN completion code
            ob_start();
            $this->recur($input, $ids, $objects, $first);
            
            // Grab output and log if logging level >= 4
            $output = ob_get_clean();
            $ravepay->log("Processed REPEAT transaction:\n" . $output, 4);
            
            return true;
            
        } else {
            
            // Handle != OK responses ..
            
            // Log stuff if logging level >= 3
            $ravepay->log(
                sprintf(
                    'Payment failure on recurring payment at Ravepay on contribution_id %d using contribution_recur_id %d.\n' .
                    'Ravepay system responded: %s',
                    $ids['contribution'],
                    $ids['contributionRecur'],
                    print_r($response, true)
                ), 3
            );
            
            // Update contribution_recur record failure count
            $ravepay->updateFailureCount($params['recurID']);
            
            // Mark contribution as Failed
            require_once 'CRM/Contribute/PseudoConstant.php';
            $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
            
            if ($ravepay->getCRMVersion() >= 3.4) {
                civicrm_api("Contribution", "create", 
                    $ref = array(
                        'version'                => '3',
                        'id'                     => $params['contributionID'],
                        'contribution_status_id' => $status_id['Failed']
                    )
                );            
            } else {
                civicrm_contribution_add(
                    $ref = array(
                        'id'                     => $params['contributionID'],
                        'contribution_status_id' => $status_id['Failed']
                    )
                );
            }
            
            return false;  
        
        }
    
    }
    
    static function retrieve($name, $type, $location = 'POST', $abort = true) {
        
        static $store = null;
        $value = CRM_Utils_Request::retrieve($name, $type, $store, false, null, $location);
        if ($abort && $value === null) {
            CRM_Core_Error::debug_log_message("Could not find an entry for $name in $location");
            echo "Failure: Missing Parameter";
            exit();
        }
        return $value;
        
    }
    
    protected function getInput(&$input, &$ids) {
        
        if (!@$this->getBillingID($ids))
            return false;
            
        $input['VPSProtocol']    = self::retrieve('VPSProtocol',    'Money',  'POST', false);
        $input['TxType']         = self::retrieve('TxType',         'String', 'POST', false);
        $input['invoice']        = self::retrieve('VendorTxCode',   'String', 'POST', false);
        $input['trxn_id']        = self::retrieve('VPSTxId',        'String', 'POST', false);
        $input['paymentStatus']  = self::retrieve('Status',         'String', 'POST', false);
        $input['StatusDetail']   = self::retrieve('StatusDetail',   'String', 'POST', false);
        $input['TxAuthNo']       = self::retrieve('TxAuthNo',       'String', 'POST', false);
        $input['AVSCV2']         = self::retrieve('AVSCV2',         'String', 'POST', false);
        $input['AddressResult']  = self::retrieve('AddressResult',  'String', 'POST', false);
        $input['PostCodeResult'] = self::retrieve('PostCodeResult', 'String', 'POST', false);
        $input['CV2Result']      = self::retrieve('CV2Result',      'String', 'POST', false);
        $input['GiftAid']        = self::retrieve('GiftAid',        'String', 'POST', false);
        $input['3DSecureStatus'] = self::retrieve('3DSecureStatus', 'String', 'POST', false);
        $input['CAVV']           = self::retrieve('CAVV',           'String', 'POST', false);
        $input['AddressStatus']  = self::retrieve('AddressStatus',  'String', 'POST', false);
        $input['PayerStatus']    = self::retrieve('PayerStatus',    'String', 'POST', false);
        $input['CardType']       = self::retrieve('CardType',       'String', 'POST', false);
        $input['Last4Digits']    = self::retrieve('Last4Digits',    'String', 'POST', false);
        $input['VPSSignature']   = self::retrieve('VPSSignature',   'String', 'POST', false);

        # added for protocol v3.0 ..
        $input['DeclineCode']    = self::retrieve('DeclineCode',   'String', 'POST', false);
        $input['ExpiryDate']     = self::retrieve('ExpiryDate',    'String', 'POST', false);
        $input['FraudResponse']  = self::retrieve('FraudResponse', 'String', 'POST', false);
        $input['BankAuthCode']   = self::retrieve('BankAuthCode',  'String', 'POST', false);

            
    }
    
    function recur(&$input, &$ids, &$objects, $first) {
        
        require_once 'CRM/Contribute/PseudoConstant.php';
        $contribution_status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
        
        $recur              = &$objects['contributionRecur'];
        $ravepay            = &$this->ravepay;
        $ravepay_recur_data = $ravepay->api('get', 'recurring', array('entity_id' => $recur->id));
        
        // Make sure invoice id is valid and matches the contribution record
        if ($recur->invoice_id != $input['invoice']) {
            $ravepay->log("Invoice values don't match between database and Ravepay request", 3);
            echo "Failure: Invoice values don't match between database and Ravepay request\r\n";
            return false;
        }
        
        $now = date('YmdHis');

        // Fix dates that already exist
        foreach (array('create', 'start', 'end', 'cancel', 'modified') as $date) {
            $name = "{$date}_date";
            if (isset($recur->$name) and $recur->$name) 
                $recur->$name = CRM_Utils_Date::isoToMysql($recur->$name);
        }
            
        $first ? $recur->start_date    = 
                 $recur->modified_date = 
                 $now 
               :
                 $recur->modified_date = 
                 $now;

        if ($recur->contribution_status_id != $contribution_status_id['Completed'])
            $recur->contribution_status_id = $contribution_status_id['In Progress'];
                
        if ($first) {
            // Create internal recurring record, storing VPSTxId, VendorTxCode etc for REPEAT transactions
            $ravepay->api('insert', 'recurring',
                array(
                    'entity_id' => $recur->id,
                    'data' => array(
                        'RelatedVPSTxId'      => $input['trxn_id'],
                        'RelatedVendorTxCode' => $input['invoice'],
                        'RelatedSecurityKey'  => $input['security_key'],
                        'RelatedTxAuthNo'     => $input['TxAuthNo'],
                        'installments'        => $recur->installments,
                        'current_installment' => 1
                    )
                )
            );
            // And set recur transaction id to that of the first contribution
            $recur->trxn_id = $input['trxn_id'];
        
        } else {
            
            // Last contribution complete? Then mark contribution_recur as complete.
            // NB: $recur->installments will be null in the case of an indefinite recurring period - eg: membership auto-renew 
            if ($recur->installments and ++$ravepay_recur_data['current_installment'] >= $recur->installments) {
                
                $recur->contribution_status_id = $contribution_status_id['Completed'];
                $recur->end_date               = $now;
                
                // And delete internal recurring record
                $ravepay->api('delete', 'recurring', array(
                    'entity_id' => $recur->id
                ));
            
            } else {
                // Otherwise, update internal record with new installment count
                $ravepay->api('update', 'recurring', 
                    array(
                        'entity_id' => $recur->id,
                        'data'      => $ravepay_recur_data
                    )
                );
            }
        }
        
        // If not completed, update next_sched_contribution date
        if ($recur->contribution_status_id != $contribution_status_id['Completed'])      
            $recur->next_sched_contribution = date(
                'YmdHis', 
                strtotime('+' . $recur->frequency_interval . ' ' . $recur->frequency_unit)
            );
        
        // Reset failure count since transaction was successful
        $recur->failure_count = 0;
        
        // Save contribution_recur record
        $recur->save();
                
        // And complete single transaction / contribution record
        return $this->single($input, $ids, $objects, true, $first);
    }
    
    function single(&$input, &$ids, &$objects, $recur = false, $first = false,$response_api) {
        
       
        $contribution =& $objects['contribution'];
        $settings  = self::$_paymentProcessor;
       
        if ( $contribution->contribution_status_id == 1 ) {
            CRM_Core_Error::debug_log_message( "Returning since contribution has already been handled" );
            echo "Success: Contribution has already been handled<p>";
            return true;
        } 

        $input['amount'] = $contribution->total_amount;
        if ( $contribution->total_amount != $response_api['data']['amount']) {
            CRM_Core_Error::debug_log_message( "Amount values dont match between database and IPN request" );
            echo "Failure: Amount values dont match between database and IPN request. ".$contribution->total_amount."/".$input['amount']."<p>";
            return;
        }
        
        require_once 'CRM/Core/Transaction.php';
        $transaction = new CRM_Core_Transaction();
        
       
        $participant = &$objects['participant'];
        $membership  = &$objects['membership'];
        
        $url         = ($this->component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
        $cancel      = ($this->component == 'event') ? '_qf_Register_display'   : '_qf_Main_display';

        $cancelURL   = CRM_Utils_System::url($url, "$cancel=1&cancel=1&qfKey=", true, null, false, true);

        /** @custom This sets the cancel URL to the webform's URL, in case the user came from a webform. */
        if (($node = self::retrieve('node', 'String', 'GET', false)) !== null) {
            $cancelURL = CRM_Utils_System::url("node/{$node}", null, true, null, false, true);
        }
        $contribution->save();

        // echo '<pre>';
        // print_r($input);
        // print_r($ids);
        // print_r($objects);
        // print_r($transaction);
        // die();
        $this->completeTransaction($input, $ids, $objects, $transaction);

        
        $url = ($input['component'] == 'event' ) ? 'civicrm/event/register' : 'civicrm/contribute/transact';

        /** @custom This is commented out in favour of the logic below */
        // $returnURL = CRM_Utils_System::url($url, "_qf_ThankYou_display=1&qfKey=" . RAVEPAY_QFKEY, true, null, false, true);
        // end custom

        /**
         * @custom This builds the return URL based on whether the form submitted was a Webform or a Civi's native form.
         */
        if (($node = self::retrieve('node', 'String', 'GET', false)) !== null) {
            $query = array();
            if (($sid = self::retrieve('sid', 'String', 'GET', false)) !== null) {
                $query[] = "sid={$sid}";
            }
            if (($token = self::retrieve('token', 'String', 'GET', false)) !== null) {
                $query[] = "token={$token}";
            }

            $returnURL = CRM_Utils_System::url("node/{$node}/done", implode('&', $query), true, null, false, true);
        } else {
            $returnURL = CRM_Utils_System::url($url, "_qf_ThankYou_display=1&qfKey=".CCRM_RAVE_ORDER_KEY, true, null, false, true);
            $returnURL = CRM_Utils_System::url($url, "_qf_ThankYou_display=true&qfKey=".CCRM_RAVE_ORDER_KEY, false,null,  false);
        }
        CRM_Utils_System::redirect($returnURL);
        
    }
    

};
