<?php
if (! $called_from_hook_call) {
	chdir ("../../../");

	// ignore CSRF
	$core_config['init']['ignore_csrf'] = TRUE;

	include "init.php";
	include $core_config['apps_path']['libs']."/function.php";
	chdir ("plugin/gateway/kannel");
}

$remote_addr = $_SERVER['REMOTE_ADDR'];
// srosa 20100531: added var below
$remote_host = $_SERVER['HTTP_HOST'];
// srosa 20100531: changed test below to allow hostname in bearerbox_host instead of ip
// if ($remote_addr != $plugin_config['kannel']['bearerbox_host'])
if ($remote_addr != $plugin_config['kannel']['bearerbox_host'] && $remote_host != $plugin_config['kannel']['bearerbox_host']) {
	logger_print("exit remote_addr:".$remote_addr." remote_host:".$remote_host." bearerbox_host:".$plugin_config['kannel']['bearerbox_host'], 2, "kannel incoming");
	exit();
}

// if the arrival time is in UTC then we need to adjust it with this:
if ($plugin_config['kannel']['local_time']) {
	$t = trim($_REQUEST['t']);
} else {
	// in UTC
	$t = core_display_datetime($_REQUEST['t']);
}

$q = trim($_REQUEST['q']);	// sms_sender
$a = trim($_REQUEST['a']);	// message
$Q = trim($_REQUEST['Q']);	// sms_receiver

logger_print("addr:".$remote_addr." host:".$remote_host." t:".$t." q:".$q." a:".$a." Q:".$Q, 3, "kannel incoming");

if ($t && $q && $a) {
	// collected:
	// $sms_datetime, $sms_sender, $message, $sms_receiver
	recvsms($t, $q, $a, $Q);
}
