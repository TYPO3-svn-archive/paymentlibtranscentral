<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2006 Franz Holzinger <kontakt@fholzinger.com>
*  All rights reserved
*
*  This script is part of the Typo3 project. The Typo3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once (t3lib_extMgM::extPath('paymentlib').'lib/class.tx_paymentlib_provider.php');

class tx_paymentlibtranscentral_provider extends tx_paymentlib_provider {

	public $scriptIsCertified = FALSE;												// Unless set to TRUE via the Extension Manager, all SOAP functionaly will be turned off because it needs certification by VISA / Mastercard
	public $redirectURI;															// URI used for redirect back from the Transaction Central server. Will be set in the constructor or can be modified from outside 

	protected $providerKey = 'transcentral';										// Identifier for this provider implementation
	protected $transactionTypes = array (											// Provider specific transaction type keys
		TX_PAYMENTLIB_TRANSACTION_ACTION_AUTHORIZEANDTRANSFER => 'a',
	);

	protected $sharedSecret='';														// Shared secret for creating MD5 hashes
	protected $WSDLURI='';															// The WSDL location for the Transaction Central SOAP webservice
	protected $formActionURI;		// Action URI for the Transaction Central "silent mode" via POST 
	protected $paymentMethod = '';													// Contains the key of the currently selected payment method
	protected $callingExtensionKey = '';											// Extension key of the extension using the paymentlib. Used to identify the extension which triggered a transaction
	protected $action = 0;															// The currently selected action
	protected $processed = FALSE;													// TRUE if this transaction has been processed
	protected $resultArr = array();													// Result of the transaction if it has been processed. Access this via transaction_getResults();

	protected $accountDataArr;														// Account data for connecting to Transaction Central gateway, defined in the Extension Manager configuration
	protected $paymentDataArr;														// Payment data for the current transaction, set by transaction_setDetails()
	protected $transactionDataArr;													// Transaction data for the current transaction, set by transaction_setDetails()
	protected $optionsArr;															// Additional options for the current transaction, set by transaction_setDetails()

	public function __construct () {
		$extensionManagerConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['paymentlib_transcentral']);
		$this->accountDataArr = array (
			'MerchantID' => $extensionManagerConf['merchantid'],
			'RegKey' => $extensionManagerConf['regkey'], 
			'trxuserId' => $extensionManagerConf['trxuserid'],
			'trxpassword' => $extensionManagerConf['trxpassword'],
		);
		$this->WSDLURI = $extensionManagerConf['wsdluri'];
		$this->formActionURI = $extensionManagerConf['formuri'];
		$this->sharedSecret = $extensionManagerConf['sharedsecret'];
		$this->scriptIsCertified = $extensionManagerConf['scriptiscertified'];
		
		$this->redirectURI = t3lib_div::getIndpEnv ('TYPO3_REQUEST_URL');
	}





	/********************************************
	 *
	 * tx_pamyentlib_provider API implementation
	 *
	 ********************************************/

	/**
	 * Returns a configuration array of available payment methods.
	 *
	 * @return	array		Supported payment methods
	 * @access	public
	 */
	 public function getAvailablePaymentMethods () {
	 	return t3lib_div::xml2array (t3lib_div::getUrl(t3lib_extMgm::extPath ('paymentlib_transcentral').'paymentmethods.xml'));
	 }

	/**
	 * Returns the provider key
	 *
	 * @return	string		Provider key
	 * @access	public
	 */
	 public function getProviderKey () {
	 	return $this->providerKey;
	 }

	/**
	 * Returns TRUE if the payment implementation supports the given gateway mode.
	 *
	 * @param	integer		$gatewayMode: The gateway mode to check for. One of the constants TX_PAYMENTLIB_GATEWAYMODE_*
	 * @return	boolean		TRUE if the given gateway mode is supported
	 * @access	public
	 */
	public function supportsGatewayMode ($gatewayMode) {
		switch ($gatewayMode) {
			case TX_PAYMENTLIB_GATEWAYMODE_FORM : 
				return TRUE;
				
			case TX_PAYMENTLIB_GATEWAYMODE_WEBSERVICE : 
				return $this->scriptIsCertified ? TRUE : FALSE;
				
			default:
				return FALSE;
		}
	}

	/**
	 * Initializes a transaction.
	 *
	 * @param	integer		$action: Type of the transaction, one of the constants TX_PAYMENTLIB_TRANSACTION_ACTION_*
	 * @param	string		$paymentMethod: Payment method, one of the values of getSupportedMethods()
	 * @param	integer		$gatewayMode: One of the constants TX_PAYMENTLIB_GATEWAYMODE_*
	 * @param	string		$callingExtKey: Extension key of the calling script.
	 * @return	boolean		TRUE if initialisation was successful 
	 * @access	public
	 */
	 public function transaction_init ($action, $paymentMethod, $gatewayMode, $callingExtKey) {
	 	if (!$this->supportsGatewayMode ($gatewayMode)) return FALSE;

	 	$this->action = $action;
		$this->paymentMethod = $paymentMethod;
		$this->gatewayMode = $gatewayMode;
		$this->callingExtensionKey = $callingExtKey;

		unset ($this->paymentDataArr);
		unset ($this->transactionDataArr);
		unset ($this->optionsArr);
		
		return TRUE;
	 }

	/**
	 * Sets the payment details. 
	 *
	 * @param	array		$detailsArr: The payment details array
	 * @return	boolean		Returns TRUE if all required details have been set
	 * @access	public
	 * @TODO	Check fields depending on $this->action! (Currently applies to AUTH) Refactor!
	 */
	 public function transaction_setDetails ($detailsArr) {
	
	 	$this->processed = FALSE;
	 	$ok = FALSE;

		debug ($this->gatewayMode, '$this->gatewayMode', __LINE__, __FILE__);
		if ($this->gatewayMode == TX_PAYMENTLIB_GATEWAYMODE_WEBSERVICE) {
		} elseif ($this->gatewayMode == TX_PAYMENTLIB_GATEWAYMODE_FORM) {
			
		 	switch ($this->paymentMethod) {
		 		case 'paymentlib_transcentral_cc_visa':
		 		case 'paymentlib_transcentral_cc_mastercard':
		 		case 'paymentlib_transcentral_cc_americanexpress':
		 		case 'paymentlib_transcentral_ach':
		 			debug ($detailsArr, '$detailsArr', __LINE__, __FILE__);
					$ok = (
						is_array ($detailsArr['transaction']) &&
							intval($detailsArr['transaction']['amount']) &&
							strlen($detailsArr['transaction']['currency'])
					);
	
					if ($ok) {
						$this->transactionDataArr = array (
							'TransType' => 'CC',
							'RefID' => $detailsArr['transaction']['orderuid'],
							'Amount' => $detailsArr['transaction']['amount'],
							'Currency' => $detailsArr['transaction']['currency'],
							'returi' => $detailsArr['transaction']['returi'],
						);
						$this->optionsArr = array (
						);
					}
		 		break;
		 	}		 		
		}
		
	 	return $ok;
	 }

	/**
	 * Validates the transaction data which was set by transaction_setDetails().
	 * $level determines how strong the check is, 1 only checks if the data is
	 * formally correct while level 2 checks if the credit card or bank account
	 * really exists.
	 *
	 * @return	boolean		TRUE if validation was successful
	 * @access	public
	 */
	 public function transaction_validate ($level=1) {
		return FALSE;
	 }

	/**
	 * Submits the prepared transaction to the payment gateway via SOAP
	 *
	 * @return	boolean		TRUE if transaction was successul, FALSE if not. The result can be accessed via transaction_getResults()
	 * @access	public
	 */
	 public function transaction_process () {
		return FALSE;
	}

	/**
	 * Returns the form action URI to be used in mode TX_PAYMENTLIB_GATEWAYMODE_FORM.
	 *
	 * @return	string		Form action URI
	 * @access	public
	 */
	public function transaction_formGetActionURI () {
		if ($this->gatewayMode != TX_PAYMENTLIB_GATEWAYMODE_FORM) return FALSE;
		
		return $this->formActionURI;
	}

	/**
	 * Returns an array of field names and values which must be included as hidden
	 * fields in the form you render use mode TX_PAYMENTLIB_GATEWAYMODE_FORM.
	 *
	 * @return	array		Field names and values to be rendered as hidden fields
	 * @access	public
	 */
	public function transaction_formGetHiddenFields () {
		global $TSFE;
		
		if ($this->gatewayMode != TX_PAYMENTLIB_GATEWAYMODE_FORM) return FALSE;

			// Set key for payment type (credit card or ELV):
	 	switch ($this->paymentMethod) {
	 		case 'paymentlib_transcentral_cc_visa':
	 		case 'paymentlib_transcentral_cc_mastercard':
	 		case 'paymentlib_transcentral_cc_americanexpress':
				$paymentType = 'cc';
			break;
	 	}

//
//							'RURL' => $detailsArr['transaction']['rurl'],
//							'TransType' => 'CC',
//							'RefID' => $detailsArr['transaction']['orderuid'],
//							'Amount' => $detailsArr['transaction']['amount'],
//							'Currency' => $detailsArr['transaction']['currency'],
//							'MerchantID' => $extensionManagerConf['merchantid'],
//							'RegKey' => $extensionManagerConf['regkey'], 
//	

	 		// Build security hash:
	 	$securityHash = md5 ($this->accountDataArr['trxuserId'] . $this->transactionDataArr['Amount'] . $this->transactionDataArr['Currency'] . $this->accountDataArr['trxpassword'] . $this->sharedSecret);
	 	
	 		// Create array of hidden fields:
		$hiddenFieldsArr = array (
			'tx_paymentlib_transcentral_extkey' => $this->callingExtensionKey,
			'MerchantID' => $this->accountDataArr['MerchantID'],
			'RegKey' => $this->accountDataArr['RegKey'],
			'RURL' => $this->redirectURI,
			'CCRURL' => $this->transactionDataArr['returi'],
			// 'trxpassword' => $this->accountDataArr['trxpassword'],
			'Amount' => $this->transactionDataArr['Amount'],
			'Currency' => $this->transactionDataArr['Currency'],
			'TransType' => $this->transactionTypes[$this->action],
			'RefID' => $this->transactionDataArr['RefID'],
		);
		
		return $hiddenFieldsArr;
	}


	/**
	 * Returns an array of field names and their configuration which must be rendered
	 * for submitting credit card numbers etc.
	 *
	 * The configuration has the format of the TCA fields section and can be used for
	 * rendering the labels and fields with by the extension frontendformslib
	 *
	 * @return	array		Field names and configuration to be rendered as visible fields
	 * @access	public
	 */
	public function transaction_formGetVisibleFields () {
		if ($this->gatewayMode != TX_PAYMENTLIB_GATEWAYMODE_FORM) return FALSE;

	 	$paymentMethodsArr = t3lib_div::xml2array (t3lib_div::getUrl(t3lib_extMgm::extPath ('paymentlib_transcentral').'paymentmethods.xml'));
		return $paymentMethodsArr[$this->paymentMethod]['fields'];
	}


	/**
	 * Returns the results of a processed transaction
	 *
	 * @return	array		Results
	 * @access	public
	 */
	public function transaction_getResults () {
		global $LANG;

		debug ($this->gatewayMode, '$this->gatewayMode', __LINE__, __FILE__);
			// In FORM mode, the result will be created on demand, the transaction data
			// should be set on beforehand.
		if ($this->gatewayMode == TX_PAYMENTLIB_GATEWAYMODE_FORM) {

			if (t3lib_div::_GET('ret_errorcode') === 0) {
				$this->resultsArr = array(
					'type' => $this->action,
					'status' => 'booked',
					'amount' => $this->transactionDataArr['trxAmount'],
					'currency' => $this->transactionDataArr['trxCurrency'],
					'remotetimestamp' => $timestamp,
					'remotebookingnr' => t3lib_div::_GET('ret_booknr'),
					'remoteauthcode' => t3lib_div::_GET('ret_authcode'),
					'extkey' => t3lib_div::_GP('tx_paymentlib_transcentral_extkey'),
					'extreference' => t3lib_div::_GP('shopper_id'),
				);
			} elseif (t3lib_div::_GET('ret_errorcode') > 0) {
				$errorMessage = $LANG->sL ('LLL:EXT:paymentlib_transcentral/locallang.php:errormessage_'.t3lib_div::_GET('ret_errorcode'));
				if (!strlen ($errorMessage)) $errorMessage = $LANG->sL ('LLL:EXT:paymentlib_transcentral/locallang.php:errormessage_general');
				
				$this->resultsArr = array(
					'type' => $this->action,
					'status' => 'failed',
					'amount' => $this->transactionDataArr['trxAmount'],
					'currency' => $this->transactionDataArr['trxCurrency'],
					'remoteerrorcode' => t3lib_div::_GET('ret_errorcode'),
					'remotemessages' => array (
						$errorMessage,
					),
					'extkey' => t3lib_div::_GP('tx_paymentlib_transcentral_extkey'),
					'extreference' => t3lib_div::_GP('shopper_id'),
				);
			} else {
				$this->resultsArr = FALSE;	
			}
		} 
		return $this->resultsArr;
	}


	/****************************************
	 *
	 * Additional methods
	 *
	 ******************************************/

	/**
	 * Sets the account data for connecting to the Transaction Central gateway
	 *
	 * @param	array		accountDataArr: The account data. Keys: 'accountId', 'trxuserId', 'trxpassword'
	 * @return	void
	 * @access	public
	 */
	 public function setAccountData ($accountDataArr) {
	 	$this->accountDataArr = $accountDataArr;
	 }


	/**
	 * Generates the items list for rendering the expiry date year selector box
	 *
	 * @return	array		Supported payment methods
	 * @access	public
	 */
	public function itemProcFunc_ccexpdateyear_items (&$paramsArr, &$pObj) {
		$paramsArr['items'] = array();
		$todayArr = getdate();
		for ($year=$todayArr['year']; $year < ($todayArr['year']+10); $year++) {
			$paramsArr['items'][] = array ($year, $year);
		}
	}
	
}

?>