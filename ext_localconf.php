<?php
if (!defined ('TYPO3_MODE')) 	die ('Access denied.');

require_once (t3lib_extMgm::extPath ('paymentlib').'lib/class.tx_paymentlib_providerfactory.php');
require_once (t3lib_extMgm::extPath ('paymentlib_transcentral').'class.tx_paymentlibtranscentral_provider.php');

$providerFactoryObj = tx_paymentlib_providerfactory::getInstance();
$providerFactoryObj->registerProviderClass ('tx_paymentlibtranscentral_provider');

?>