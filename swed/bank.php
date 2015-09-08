<?php

/**

	Käesoleva loomingu autoriõigused kuuluvad Revo Rästale ja Aktsiamaailm OÜ-le

	Litsentsitingimused on saadaval http://www.e-abi.ee/litsents

	@version 1.0

*/

/*
	AIM:
	Convert payment method notification URL without _GET parameters to VirtueMart's plugin response received, that contains _GET parameters.
	Payment method notification URL may not contain _GET parameters (Query String parameters)

	WHY:
	Because the notification URL of this payment method does not support _GET parameters

	ACTION:
	Converts:
	//plugins/vmpayment/swed/bank.php
	to
	//index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived


*/

//make required _GET data (comes from To: URL)
$_GET['option'] = 'com_virtuemart';
$_GET['view'] = 'pluginresponse';
$_GET['task'] = 'pluginresponsereceived';

//same with _REQUEST as _REQUEST should also contain _GET variables
$_REQUEST['option'] = 'com_virtuemart';
$_REQUEST['view'] = 'pluginresponse';
$_REQUEST['task'] = 'pluginresponsereceived';

//require once statement may check some server variables like filename
foreach ($_SERVER as $k => $v) {
	$_SERVER[$k] = str_replace('/plugins/vmpayment/swed/bank.php', '/index.php', $_SERVER[$k]);
}
chdir(dirname(dirname(dirname(dirname(__FILE__)))));
require_once('index.php');
