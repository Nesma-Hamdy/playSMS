<?php

/**
 * This file is part of playSMS.
 *
 * playSMS is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * playSMS is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with playSMS.  If not, see <http://www.gnu.org/licenses/>.
 */

defined('_SECURE_') or die('Forbidden');

function sendsms_getvalidnumber($number) {
	$number = preg_replace("/[^0-9\+]/", "", $number);
	if (strlen($number) > 20) {
		$number = substr($number, 0, 20);
	}
	return $number;
}

function sendsms_manipulate_prefix($number, $user) {
	logger_print("dest number before manipulation: '$number'", 3, "sendsms");
	if (is_array($user)) {
		if (isset($user['local_length']) && !empty($user['local_length']) && is_numeric($user['local_length'])) {
			if (strlen($number) == $user['local_length']) {
				$number = $user['replace_zero'] . $number;
				logger_print("dest number '$number' prefixed with '" . $user['replace_zero'] . "' string", 3, "sendsms");
			}
		}
		if ($user['replace_zero']) {
			$number = preg_replace('/^0/', $user['replace_zero'], $number);
		}
		if ($user['plus_sign_remove']) {
			$number = preg_replace('/^\+/', '', $number);
		}
		if ($user['plus_sign_add']) {
			$number = '+' . $number;
		}
	}
	logger_print("dest number after manipulation '$number'", 3, "sendsms");
	return $number;
}

function sendsms_intercept($sms_sender, $sms_footer, $sms_to, $sms_msg, $uid, $gpid = 0, $sms_type = 'text', $unicode = 0) {
	global $core_config;
	$ret = array();
	$ret_final = array();
	
	// feature list
	for ($c = 0; $c < count($core_config['featurelist']); $c++) {
		$ret = core_hook($core_config['featurelist'][$c], 'sendsms_intercept', array(
			$sms_sender,
			$sms_footer,
			$sms_to,
			$sms_msg,
			$uid,
			$gpid,
			$sms_type,
			$unicode
		));
		if ($ret['modified']) {
			$sms_sender = ($ret['param']['sms_sender'] ? $ret['param']['sms_sender'] : $sms_sender);
			$sms_footer = ($ret['param']['sms_footer'] ? $ret['param']['sms_footer'] : $sms_footer);
			$sms_to = ($ret['param']['sms_to'] ? $ret['param']['sms_to'] : $sms_to);
			$sms_msg = ($ret['param']['sms_msg'] ? $ret['param']['sms_msg'] : $sms_msg);
			$uid = ($ret['param']['uid'] ? $ret['param']['uid'] : $uid);
			$gpid = ($ret['param']['gpid'] ? $ret['param']['gpid'] : $gpid);
			$sms_type = ($ret['param']['sms_type'] ? $ret['param']['sms_type'] : $sms_type);
			$unicode = ($ret['param']['unicode'] ? $ret['param']['unicode'] : $unicode);
			$ret_final['modified'] = $ret['modified'];
			$ret_final['cancel'] = $ret['cancel'];
			$ret_final['param']['sms_sender'] = $ret['param']['sms_sender'];
			$ret_final['param']['sms_footer'] = $ret['param']['sms_footer'];
			$ret_final['param']['sms_to'] = $ret['param']['sms_to'];
			$ret_final['param']['sms_msg'] = $ret['param']['sms_msg'];
			$ret_final['param']['uid'] = $ret['param']['uid'];
			$ret_final['param']['gpid'] = $ret['param']['gpid'];
			$ret_final['param']['sms_type'] = $ret['param']['sms_type'];
			$ret_final['param']['unicode'] = $ret['param']['unicode'];
		}
	}
	return $ret_final;
}

/**
 * Create SMS queue
 * @global array $core_config
 * @param string $sms_sender
 * @param string $sms_footer
 * @param string $sms_msg
 * @param integer $uid
 * @param integer $gpid
 * @param string $sms_type
 * @param integer $unicode
 * @param string $sms_schedule
 * @return string Queue code
 */
function sendsms_queue_create($sms_sender, $sms_footer, $sms_msg, $uid, $gpid = 0, $sms_type = 'text', $unicode = 0, $sms_schedule = '') {
	global $core_config;
	
	$ret = FALSE;
	$dt = core_get_datetime();
	$sms_schedule = (trim($sms_schedule) ? core_adjust_datetime($sms_schedule) : $dt);
	$queue_code = md5(uniqid($uid . $gpid, true));
	logger_print("saving queue_code:" . $queue_code . " src:" . $sms_sender, 2, "sendsms_queue_create");
	
	// message entering this proc already stripslashed, we need to addslashes it before saving to db
	$sms_sender = addslashes($sms_sender);
	$sms_msg = addslashes($sms_msg);
	$sms_footer = addslashes($sms_footer);
	
	$db_query = "INSERT INTO " . _DB_PREF_ . "_tblSMSOutgoing_queue ";
	$db_query.= "(queue_code,datetime_entry,datetime_scheduled,uid,gpid,sender_id,footer,message,sms_type,unicode,flag) ";
	$db_query.= "VALUES ('$queue_code','" . $dt . "','" . $sms_schedule . "','$uid','$gpid','$sms_sender','$sms_footer','$sms_msg','$sms_type','$unicode','2')";
	if ($id = @dba_insert_id($db_query)) {
		logger_print("saved queue_code:" . $queue_code . " id:" . $id, 2, "sendsms_queue_create");
		$ret = $queue_code;
	}
	
	return $ret;
}

function sendsms_queue_push($queue_code, $sms_to) {
	$ret = false;
	$db_query = "SELECT id FROM " . _DB_PREF_ . "_tblSMSOutgoing_queue WHERE queue_code='$queue_code' AND flag='2'";
	$db_result = dba_query($db_query);
	$db_row = dba_fetch_array($db_result);
	$queue_id = $db_row['id'];
	if ($queue_id) {
		$db_query = "INSERT INTO " . _DB_PREF_ . "_tblSMSOutgoing_queue_dst (queue_id,dst) VALUES ('$queue_id','$sms_to')";
		logger_print("saving queue_code:" . $queue_code . " dst:" . $sms_to, 2, "sendsms_queue_push");
		if ($smslog_id = @dba_insert_id($db_query)) {
			logger_print("saved queue_code:" . $queue_code . " smslog_id:" . $smslog_id, 2, "sendsms_queue_push");
			$ret = $smslog_id;
		}
	}
	return $ret;
}

function sendsms_queue_update($queue_code, $updates) {
	$ret = false;
	if (is_array($updates)) {
		$ret = dba_update(_DB_PREF_ . '_tblSMSOutgoing_queue', $updates, array(
			'queue_code' => $queue_code
		));
	}
	return $ret;
}

function sendsmsd($single_queue = '', $sendsmsd_limit = 0, $sendsmsd_offset = 0) {
	global $core_config;
	if ($single_queue) {
		$queue_sql = "AND queue_code='" . $single_queue . "'";
		
		//logger_print("single queue queue_code:".$single_queue, 2, "sendsmsd");
		
		
	}
	$sendsmsd_limit = (int)$sendsmsd_limit;
	if ($sendsmsd_limit > 0) {
		$sql_limit = "LIMIT " . $sendsmsd_limit;
	}
	$sendsmsd_offset = (int)$sendsmsd_offset;
	if ($sendsmsd_offset > 0) {
		$sql_offset = "OFFSET " . $sendsmsd_offset;
	}
	$db_query = "SELECT * FROM " . _DB_PREF_ . "_tblSMSOutgoing_queue WHERE flag='0' " . $queue_sql . " " . $sql_limit . " " . $sql_offset;
	
	//logger_print("q: ".$db_query, 3, "sendsmsd");
	$db_result = dba_query($db_query);
	while ($db_row = dba_fetch_array($db_result)) {
		$c_queue_id = $db_row['id'];
		$c_queue_code = $db_row['queue_code'];
		$c_sender_id = addslashes(trim($db_row['sender_id']));
		$c_footer = addslashes(trim($db_row['footer']));
		$c_message = addslashes(trim($db_row['message']));
		$c_uid = $db_row['uid'];
		$c_gpid = $db_row['gpid'];
		$c_sms_type = $db_row['sms_type'];
		$c_unicode = $db_row['unicode'];
		$c_sms_count = $db_row['sms_count'];
		$c_schedule = $db_row['datetime_scheduled'];
		$c_current = core_get_datetime();
		
		// logger_print("delivery datetime qeueue:".$c_queue_code." scheduled:".$c_schedule." current:".$c_current, 3, "sendsmsd");
		if (strtotime($c_current) >= strtotime($c_schedule)) {
			logger_print("start processing queue_code:" . $c_queue_code . " sms_count:" . $c_sms_count . " uid:" . $c_uid . " gpid:" . $c_gpid . " sender_id:" . $c_sender_id, 2, "sendsmsd");
			$counter = 0;
			$db_query2 = "SELECT * FROM " . _DB_PREF_ . "_tblSMSOutgoing_queue_dst WHERE queue_id='$c_queue_id' AND flag='0'";
			$db_result2 = dba_query($db_query2);
			while ($db_row2 = dba_fetch_array($db_result2)) {
				$counter++;
				
				// queue_dst ID is SMS Log ID
				$c_smslog_id = $db_row2['id'];
				
				$c_dst = $db_row2['dst'];
				$c_flag = 2;
				$c_ok = false;
				logger_print("sending queue_code:" . $c_queue_code . " smslog_id:" . $c_smslog_id . " to:" . $c_dst . " sms_count:" . $c_sms_count . " counter:" . $counter, 2, "sendsmsd");
				$ret = sendsms_process($c_smslog_id, $c_sender_id, $c_footer, $c_dst, $c_message, $c_uid, $c_gpid, $c_sms_type, $c_unicode, $c_queue_code);
				$c_dst = $ret['to'];
				if ($ret['status']) {
					$c_ok = true;
					$c_flag = 1;
				}
				logger_print("result queue_code:" . $c_queue_code . " to:" . $c_dst . " flag:" . $c_flag . " smslog_id:" . $c_smslog_id, 2, "sendsmsd");
				$db_query3 = "UPDATE " . _DB_PREF_ . "_tblSMSOutgoing_queue_dst SET flag='$c_flag' WHERE id='$c_smslog_id'";
				$db_result3 = dba_query($db_query3);
				$ok[] = $c_ok;
				$to[] = $c_dst;
				$smslog_id[] = $c_smslog_id;
				$queue[] = $c_queue_code;
				$counts[] = $c_sms_count;
			}
			$db_query = "SELECT count(*) AS count FROM " . _DB_PREF_ . "_tblSMSOutgoing_queue_dst WHERE queue_id='$c_queue_id' AND NOT flag ='0'";
			$db_result = dba_query($db_query);
			$db_row = dba_fetch_array($db_result);
			
			// destinations processed
			$dst_processed = (int)($db_row['count'] ? $db_row['count'] : 0);
			
			// number of SMS processed
			$sms_processed = $dst_processed * $c_sms_count;
			
			// check whether SMS processed is >= stated SMS count in queue
			// if YES then processing queue is finished
			if ($sms_processed >= $c_sms_count) {
				$dt = core_get_datetime();
				$db_query5 = "UPDATE " . _DB_PREF_ . "_tblSMSOutgoing_queue SET flag='1', datetime_update='" . $dt . "' WHERE id='$c_queue_id'";
				if ($db_result5 = dba_affected_rows($db_query5)) {
					logger_print("finish processing queue_code:" . $c_queue_code . " uid:" . $c_uid . " sender_id:" . $c_sender_id . " sms_count:" . $c_sms_count, 2, "sendsmsd");
				} else {
					logger_print("fail to finalize process queue_code:" . $c_queue_code . " uid:" . $c_uid . " sender_id:" . $c_sender_id . " sms_processed:" . $sms_processed, 2, "sendsmsd");
				}
			} else {
				logger_print("partially processing queue_code:" . $c_queue_code . " uid:" . $c_uid . " sender_id:" . $c_sender_id . " sms_count:" . $c_sms_count . " sms_processed:" . $sms_processed . " counter:" . $counter, 2, "sendsmsd");
			}
		}
	}
	return array(
		$ok,
		$to,
		$smslog_id,
		$queue,
		$counts
	);
}

function sendsms_process($smslog_id, $sms_sender, $sms_footer, $sms_to, $sms_msg, $uid, $gpid = 0, $sms_type = 'text', $unicode = 0, $queue_code = '') {
	global $user_config;
	$ok = false;
	
	// get active gateway module
	$gw = core_gateway_get();
	
	$user = $user_config;
	if ($uid && ($user['uid'] != $uid)) {
		$user = user_getdatabyuid($uid);
	}
	
	$username = $user['username'];
	
	$sms_to = sendsms_getvalidnumber($sms_to);
	
	// now on sendsms()
	//$sms_to = sendsms_manipulate_prefix($sms_to, $user);
	
	$sms_datetime = core_get_datetime();
	
	// sent sms will be handled by plugins first
	$ret_intercept = sendsms_intercept($sms_sender, $sms_footer, $sms_to, $sms_msg, $uid, $gpid, $sms_type, $unicode);
	if ($ret_intercept['modified']) {
		$sms_sender = ($ret_intercept['param']['sms_sender'] ? $ret_intercept['param']['sms_sender'] : $sms_sender);
		$sms_footer = ($ret_intercept['param']['sms_footer'] ? $ret_intercept['param']['sms_footer'] : $sms_footer);
		$sms_to = ($ret_intercept['param']['sms_to'] ? $ret_intercept['param']['sms_to'] : $sms_to);
		$sms_msg = ($ret_intercept['param']['sms_msg'] ? $ret_intercept['param']['sms_msg'] : $sms_msg);
		$uid = ($ret_intercept['param']['uid'] ? $ret_intercept['param']['uid'] : $uid);
		$gpid = ($ret_intercept['param']['gpid'] ? $ret_intercept['param']['gpid'] : $gpid);
		$sms_type = ($ret_intercept['param']['sms_type'] ? $ret_intercept['param']['sms_type'] : $sms_type);
		$unicode = ($ret_intercept['param']['unicode'] ? $ret_intercept['param']['unicode'] : $unicode);
	}
	
	// if hooked function returns cancel=true then stop the sending, return false
	if ($ret_intercept['cancel']) {
		logger_print("end with cancelled smslog_id:" . $smslog_id . " uid:" . $uid . " gpid:" . $gpid . " gw:" . $gw . " s:" . $sms_sender . " to:" . $sms_to . " type:" . $sms_type . " unicode:" . $unicode, 2, "sendsms_process");
		$ret['status'] = false;
		return $ret;
	}
	
	// a hack to remove \r from \r\n
	// the issue begins with ENTER being \r\n and detected as 2 chars
	// and since the javascript message counter can't detect it as 2 chars
	// thus the message length counts is inaccurate
	$sms_msg = str_replace("\r\n", "\n", $sms_msg);
	
	// just to make sure its length, we need to stripslashes message before enter other procedures
	$sms_sender = stripslashes($sms_sender);
	$sms_msg = stripslashes($sms_msg);
	$sms_footer = stripslashes($sms_footer);
	
	// fixme anton - mobile number can be anything, screened by gateway
	// $sms_sender = sendsms_getvalidnumber($sms_sender);
	
	// fixme anton - add a space in front of $sms_footer
	if (trim($sms_footer)) {
		$sms_footer = ' ' . trim($sms_footer);
	}
	
	logger_print("start", 2, "sendsms_process");
	
	if (rate_cansend($username, strlen($sms_msg . $sms_footer) , $unicode, $sms_to)) {
		$p_status = 0;
	} else {
		logger_print("end with fail not enough credit smslog_id:" . $smslog_id, 2, "sendsms_process");
		$ret['status'] = true;
		
		// set TRUE to stop queue
		$ret['to'] = $sms_to;
		$ret['smslog_id'] = $smslog_id;
		$ret['p_status'] = 2;
		
		// set failed
		return $ret;
	}
	
	// message entering this proc already stripslashed, we need to addslashes it before saving to db
	$sms_sender = addslashes($sms_sender);
	$sms_msg = addslashes($sms_msg);
	$sms_footer = addslashes($sms_footer);
	
	// we save all info first and then process with gateway module
	// the thing about this is that message saved may not be the same since gateway may not be able to process
	// message with that length or certain characters in the message are not supported by the gateway
	$db_query = "
		INSERT INTO " . _DB_PREF_ . "_tblSMSOutgoing 
		(smslog_id,uid,p_gpid,p_gateway,p_src,p_dst,p_footer,p_msg,p_datetime,p_status,p_sms_type,unicode,queue_code) 
		VALUES ('$smslog_id','$uid','$gpid','$gw','$sms_sender','$sms_to','$sms_footer','$sms_msg','$sms_datetime','$p_status','$sms_type','$unicode','$queue_code')";
	logger_print("saving smslog_id:" . $smslog_id . " u:" . $uid . " g:" . $gpid . " gw:" . $gw . " s:" . $sms_sender . " d:" . $sms_to . " type:" . $sms_type . " unicode:" . $unicode . " status:" . $p_status, 2, "sendsms");
	
	// continue to gateway only when save to db is true
	if ($id = @dba_insert_id($db_query)) {
		logger_print("saved smslog_id:" . $smslog_id . " id:" . $id, 2, "sendsms_process");
		if ($p_status == 0) {
			logger_print("final smslog_id:" . $smslog_id . " gw:" . $gw . " message:" . $sms_msg . $sms_footer . " len:" . strlen($sms_msg . $sms_footer) , 3, "sendsms");
			if (core_hook($gw, 'sendsms', array(
				$sms_sender,
				$sms_footer,
				$sms_to,
				$sms_msg,
				$uid,
				$gpid,
				$smslog_id,
				$sms_type,
				$unicode
			))) {
				
				// fixme anton - deduct user's credit as soon as gateway returns true
				rate_deduct($smslog_id);
				$ok = true;
			} else {
				logger_print("fail no hook for sendsms", 2, "sendsms_process");
			}
		}
	} else {
		logger_print("fail to save in db table smslog_id:" . $smslog_id, 2, "sendsms_process");
	}
	
	logger_print("end", 2, "sendsms_process");
	
	$ret['status'] = $ok;
	$ret['to'] = $sms_to;
	$ret['smslog_id'] = $smslog_id;
	$ret['p_status'] = $p_status;
	return $ret;
}

/**
 * Send SMS helper
 * @global array $core_config, $user_config
 * @param string $username
 * @param mixed $sms_to
 * @param string $message
 * @param string $sms_type
 * @param integer $unicode
 * @param boolean $nofooter
 * @param string $sms_footer
 * @param string $sms_sender
 * @param string $sms_schedule
 * @return array array($status, $sms_to, $smslog_id, $queue, $counts, $sms_count, $sms_failed)
 */
function sendsms_helper($username, $sms_to, $message, $sms_type = 'text', $unicode = 0, $nofooter = false, $sms_footer = '', $sms_sender = '', $sms_schedule = '') {
	global $core_config, $user_config;
	
	// get user data
	if ($username && ($user_config['username'] != $username)) {
		$user_config = user_getdatabyusername($username);
	}
	
	if (!is_array($sms_to)) {
		$sms_to = explode(',', $sms_to);
	}
	
	// get destinations
	for ($i = 0; $i < count($sms_to); $i++) {
		if (substr(trim($sms_to[$i]) , 0, 1) == '#') {
			if ($c_group_code = substr(trim($sms_to[$i]) , 1)) {
				$c_gpid = phonebook_groupcode2id($user_config['uid'], $c_group_code);
				$members = phonebook_getdatabyid($c_gpid);
				foreach ($members as $member) {
					if (trim($member['p_num'])) {
						$array_sms_to[] = trim($member['p_num']);
					}
				}
			}
		} else if (substr(trim($sms_to[$i]) , 0, 1) == '@') {
			if ($c_username = substr(trim($sms_to[$i]) , 1)) {
				$array_username[] = $c_username;
			}
		} else {
			$array_sms_to[] = trim($sms_to[$i]);
		}
	}
	
	// remove duplicates destinations
	array_unique($array_sms_to);
	
	$sms_queued = 0;
	$sms_failed = 0;
	
	// sendsms
	if (is_array($array_sms_to) && $array_sms_to[0]) {
		list($ok, $to, $smslog_id, $queue, $counts) = sendsms($user_config['username'], $array_sms_to, $message, $sms_type, $unicode, $nofooter, $sms_footer, $sms_sender, $sms_schedule);
	}
	
	// fixme anton - IMs doesn't count
	// count SMSes only
	for ($i = 0; $i < count($ok); $i++) {
		if ($ok[$i]) {
			$sms_count+= $counts[$i];
		} else {
			$sms_failed+= $counts[$i];
		}
	}
	
	// sendsms_im
	if (is_array($array_username) && $array_username[0]) {
		$im_sender = '@' . $user_config['username'];
		foreach ($array_username as $target_user) {
			$im_sender = '@' . $user_config['username'];
			if (recvsms_inbox_add(core_get_datetime() , $im_sender, $target_user, $message)) {
				$ok[] = '1';
				$to[] = '@'.$target_user;
				$queue[] = md5($target_user.microtime());
				$sms_count++;
			}
		}
	}
	
	return array(
		$ok,
		$to,
		$smslog_id,
		$queue,
		$counts,
		$sms_count,
		$sms_failed,
	);
}

/**
 * Send SMS
 * @global array $core_config, $user_config
 * @param string $username
 * @param mixed $sms_to
 * @param string $message
 * @param string $sms_type
 * @param integer $unicode
 * @param boolean $nofooter
 * @param string $sms_footer
 * @param string $sms_sender
 * @param string $sms_schedule
 * @return array array($status, $sms_to, $smslog_id, $queue, $counts)
 */
function sendsms($username, $sms_to, $message, $sms_type = 'text', $unicode = 0, $nofooter = false, $sms_footer = '', $sms_sender = '', $sms_schedule = '') {
	global $core_config, $user_config;
	
	// get user data
	$user = $user_config;
	if ($username && ($user['username'] != $username)) {
		$user = user_getdatabyusername($username);
	}
	
	if (!is_array($sms_to)) {
		$sms_to = explode(',', $sms_to);
	}
	
	$uid = $user['uid'];
	
	// SMS sender ID
	if (!$core_config['main']['allow_custom_sender']) {
		$sms_sender = '';
	}
	$sms_sender = core_sanitize_sender($sms_sender);
	$sms_sender = (($sms_sender && sendsms_sender_isvalid($username, $sms_sender)) ? $sms_sender : sendsms_get_sender($username));
	
	// SMS footer
	if (!$core_config['main']['allow_custom_footer']) {
		$sms_footer = '';
	}
	$sms_footer = core_sanitize_footer($sms_footer);
	$sms_footer = ($sms_footer ? $sms_footer : $user['footer']);
	if ($nofooter) {
		$sms_footer = '';
	}
	
	// a hack to remove \r from \r\n
	// the issue begins with ENTER being \r\n and detected as 2 chars
	// and since the javascript message counter can't detect it as 2 chars
	// thus the message length counts is inaccurate
	$message = str_replace("\r\n", "\n", $message);
	
	// just to make sure its length, we need to stripslashes message before enter other procedures
	$sms_sender = stripslashes($sms_sender);
	$message = stripslashes($message);
	$sms_footer = stripslashes($sms_footer);
	
	// fixme anton - fix #71 but not sure whats the correct solution for this
	//$max_length = ( $unicode ?  $user['opt']['max_sms_length_unicode'] : $user['opt']['max_sms_length'] );
	$max_length = $user['opt']['max_sms_length'];
	
	if (strlen($message) > $max_length) {
		$message = substr($message, 0, $max_length);
	}
	$sms_msg = $message;
	
	logger_print("start uid:" . $uid . " sender:" . $sms_sender, 2, "sendsms");
	
	// add a space infront of footer if exists
	$c_sms_footer = (trim($sms_footer) ? ' ' . trim($sms_footer) : '');
	logger_print("maxlen:" . $max_length . " footerlen:" . strlen($c_sms_footer) . " footer:[" . $c_sms_footer . "] msglen:" . strlen($sms_msg) . " message:[" . $sms_msg . "]", 3, "sendsms");
	
	// create a queue
	$queue_code = sendsms_queue_create($sms_sender, $sms_footer, $sms_msg, $uid, 0, $sms_type, $unicode, $sms_schedule);
	if (!$queue_code) {
		
		// when unable to create a queue then immediately returns FALSE, no point to continue
		logger_print("fail to finalize queue creation, exit immediately", 2, "sendsms");
		return array(
			FALSE,
			'',
			'',
			'',
			'',
		);
	}
	
	if (is_array($sms_to)) {
		$array_sms_to = $sms_to;
	} else {
		$array_sms_to = explode(',', $sms_to);
	}
	
	// get manipulated and valid destination numbers
	$all_sms_to = array();
	for ($i = 0; $i < count($array_sms_to); $i++) {
		if ($c_sms_to = sendsms_getvalidnumber(trim($array_sms_to[$i]))) {
			$c_sms_to = sendsms_manipulate_prefix(trim($c_sms_to) , $user);
			$all_sms_to[] = $c_sms_to;
		}
	}
	
	// remove double entries
	$all_sms_to = array_unique($all_sms_to);
	
	// calculate total sms and charges
	$total_count = 0;
	$total_charges = 0;
	foreach ($all_sms_to as $c_sms_to) {
		list($count, $rate, $charge) = rate_getcharges(strlen($message . $c_sms_footer) , $unicode, $c_sms_to);
		$total_count+= $count;
		$total_charges+= $charge;
	}
	_log('dst:' . count($all_sms_to) . ' sms_count:' . $total_count . ' total_charges:' . $total_charges, 2, 'sendsms');
	
	// sender's
	$credit = rate_getusercredit($user['username']);
	$balance = $credit - $total_charges;
	
	// parent's when sender is a subuser
	$parent_uid = user_getparentbyuid($user['uid']);
	if ($parent_uid) {
		$username_parent = user_uid2username($parent_uid);
		$credit_parent = rate_getusercredit($username_parent);
		$balance_parent = $credit_parent - $total_charges;
	}
	
	// default returns
	for ($i = 0; $i < count($all_sms_to); $i++) {
		$ok[$i] = FALSE;
		$to[$i] = $all_sms_to[$i];
		$smslog_id[$i] = 0;
		$queue[$i] = $queue_code;
		$counts[$i] = $count;
	}
	
	if ($parent_uid) {
		if (!($balance_parent >= 0)) {
			_log('failed parent do not have enough credit. credit:' . $credit_parent . ' dst:' . count($all_sms_to) . ' sms_count:' . $total_count . ' total_charges:' . $total_charges, 2, 'sendsms');
			return array(
				$ok,
				$to,
				$smslog_id,
				$queue,
				$counts
			);
		}
	} else {
		if (!($balance >= 0)) {
			_log('failed user do not have enough credit. credit:' . $credit_parent . ' dst:' . count($all_sms_to) . ' sms_count:' . $total_count . ' total_charges:' . $total_charges, 2, 'sendsms');
			return array(
				$ok,
				$to,
				$smslog_id,
				$queue,
				$counts
			);
		}
	}
	
	$queue_count = 0;
	$sms_count = 0;
	$failed_queue_count = 0;
	$failed_sms_count = 0;
	for ($i = 0; $i < count($all_sms_to); $i++) {
		$c_sms_to = $all_sms_to[$i];
		if ($smslog_id[$i] = sendsms_queue_push($queue_code, $c_sms_to)) {
			$ok[$i] = TRUE;
			$queue_count++;
			$sms_count = $sms_count + $count;
		} else {
			$ok[$i] = FALSE;
			$failed_queue_count++;
			$failed_sms_count++;
		}
		$to[$i] = $c_sms_to;
		$queue[$i] = $queue_code;
		$counts[$i] = $count;
	}
	
	if (sendsms_queue_update($queue_code, array(
		'flag' => '0',
		'sms_count' => $sms_count
	))) {
		logger_print("end queue_code:" . $queue_code . " queue_count:" . $queue_count . " sms_count:" . $sms_count . " failed_queue:" . $failed_queue_count . " failed_sms:" . $failed_sms_count, 2, "sendsms");
	} else {
		logger_print("fail to prepare queue, exit immediately queue_code:" . $queue_code, 2, "sendsms");
		return array(
			FALSE,
			'',
			'',
			$queue_code,
			'',
		);
	}
	
	if (!$core_config['issendsmsd']) {
		unset($ok);
		unset($to);
		unset($queue);
		unset($counts);
		logger_print("sendsmsd off immediately process queue_code:" . $queue_code, 2, "sendsms");
		list($ok, $to, $smslog_id, $queue, $counts) = sendsmsd($queue_code);
	}
	
	return array(
		$ok,
		$to,
		$smslog_id,
		$queue,
		$counts
	);
}

/**
 * Send SMS to phonebook group
 * @global array $core_config
 * @param string $username
 * @param integer $gpid
 * @param string $message
 * @param string $sms_type
 * @param integer $unicode
 * @param boolean $nofooter
 * @param string $sms_footer
 * @param string $sms_sender
 * @param string $sms_schedule
 * @return array array($status, $sms_to, $smslog_id, $queue)
 */
function sendsms_bc($username, $gpid, $message, $sms_type = 'text', $unicode = 0, $nofooter = false, $sms_footer = '', $sms_sender = '', $sms_schedule = '') {
	global $core_config, $user_config;
	$user = $user_config;
	if ($username && ($user['username'] != $username)) {
		$user = user_getdatabyusername($username);
	}
	
	$uid = $user['uid'];
	
	// SMS sender ID
	if (!$core_config['main']['allow_custom_sender']) {
		$sms_sender = '';
	}
	$sms_sender = core_sanitize_sender($sms_sender);
	$sms_sender = (($sms_sender && sendsms_sender_isvalid($username, $sms_sender)) ? $sms_sender : sendsms_get_sender($username));
	
	// SMS footer
	if (!$core_config['main']['allow_custom_footer']) {
		$sms_footer = '';
	}
	$sms_footer = core_sanitize_footer($sms_footer);
	$sms_footer = ($sms_footer ? $sms_footer : $user['footer']);
	if ($nofooter) {
		$sms_footer = '';
	}
	
	// a hack to remove \r from \r\n
	// the issue begins with ENTER being \r\n and detected as 2 chars
	// and since the javascript message counter can't detect it as 2 chars
	// thus the message length counts is inaccurate
	$message = str_replace("\r\n", "\n", $message);
	
	// just to make sure its length, we need to stripslashes message before enter other procedures
	$sms_sender = stripslashes($sms_sender);
	$message = stripslashes($message);
	$sms_footer = stripslashes($sms_footer);
	
	// fixme anton - fix #71 but not sure whats the correct solution for this
	//$max_length = ( $unicode ?  $user['opt']['max_sms_length_unicode'] : $user['opt']['max_sms_length'] );
	$max_length = $user['opt']['max_sms_length'];
	
	if (strlen($message) > $max_length) {
		$message = substr($message, 0, $max_length);
	}
	$sms_msg = $message;
	
	logger_print("start uid:" . $uid . " sender:" . $sms_sender, 2, "sendsms_bc");
	
	// add a space infront of footer if exists
	$c_sms_footer = (trim($sms_footer) ? ' ' . trim($sms_footer) : '');
	logger_print("maxlen:" . $max_length . " footerlen:" . strlen($c_sms_footer) . " footer:[" . $c_sms_footer . "] msglen:" . strlen($sms_msg) . " message:[" . $sms_msg . "]", 3, "sendsms_bc");
	
	// destination group should be an array, if single then make it array of 1 member
	if (is_array($gpid)) {
		$array_gpid = $gpid;
	} else {
		$array_gpid = explode(',', $gpid);
	}
	
	$j = 0;
	for ($i = 0; $i < count($array_gpid); $i++) {
		if ($c_gpid = trim($array_gpid[$i])) {
			logger_print("start gpid:" . $c_gpid . " uid:" . $uid . " sender:" . $sms_sender, 2, "sendsms_bc");
			
			// create a queue
			$queue_code = sendsms_queue_create($sms_sender, $sms_footer, $sms_msg, $uid, $c_gpid, $sms_type, $unicode, $sms_schedule);
			if (!$queue_code) {
				
				// when unable to create a queue then immediately returns FALSE, no point to continue
				logger_print("fail to finalize queue creation, exit immediately", 2, "sendsms_bc");
				return array(
					FALSE,
					'',
					'',
					'',
					'',
				);
			}
			
			$queue_count = 0;
			$sms_count = 0;
			$failed_queue_count = 0;
			$failed_sms_count = 0;
			$rows = phonebook_getdatabyid($c_gpid);
			if (is_array($rows)) {
				foreach ($rows as $key => $db_row) {
					$p_num = trim($db_row['p_num']);
					if ($sms_to = sendsms_getvalidnumber($p_num)) {
						$sms_to = sendsms_manipulate_prefix($sms_to, $user);
						if ($smslog_id[$j] = sendsms_queue_push($queue_code, $sms_to)) {
							$ok[$j] = true;
							$queue_count++;
							$sms_count+= $count;
						} else {
							$ok[$j] = FALSE;
							$failed_queue_count++;
							$failed_sms_count++;
						}
						$to[$j] = $sms_to;
						$queue[$j] = $queue_code;
						$counts[$j] = $count;
						$j++;
					}
				}
			}
			
			if (sendsms_queue_update($queue_code, array(
				'flag' => '0',
				'sms_count' => $sms_count
			))) {
				logger_print("end queue_code:" . $queue_code . " queue_count:" . $queue_count . " sms_count:" . $sms_count . " failed_queue:" . $failed_queue_count . " failed_sms:" . $failed_sms_count, 2, "sendsms_bc");
			} else {
				logger_print("fail to prepare queue, exit immediately queue_code:" . $queue_code, 2, "sendsms_bc");
				return array(
					FALSE,
					'',
					'',
					$queue_code,
					'',
				);
			}
		}
	}
	
	if (!$core_config['issendsmsd']) {
		unset($ok);
		unset($to);
		unset($queue);
		unset($counts);
		logger_print("sendsmsd off immediately process queue_code:" . $queue_code, 2, "sendsms_bc");
		list($ok, $to, $smslog_id, $queue, $counts) = sendsmsd($queue_code);
	}
	
	return array(
		$ok,
		$to,
		$smslog_id,
		$queue,
		$counts,
	);
}

function sendsms_get_sender($username, $default_sender_id = '') {
	global $core_config, $plugin_config, $user_config;
	
	$ret = '';
	
	// get configured sender ID
	if ($username && ($gw = core_gateway_get())) {
		if ($core_config['main']['gateway_number']) {
			
			// 1st priority is "Default sender ID" from main configuration
			$sms_sender = $core_config['main']['gateway_number'];
		} else if ($plugin_config[$gw]['global_sender']) {
			
			// 2nd priority is "Module sender ID" from gateway module setting
			$sms_sender = $plugin_config[$gw]['global_sender'];
		} else {
			
			// 3rd priority is "SMS sender ID" from user preferences
			$sms_sender = $user_config['sender'];
			if ($user_config['username'] != $username) {
				$sms_sender = user_getfieldbyusername($username, 'sender');
			}
		}
	}
	
	// configured sender ID
	$sms_sender = core_sanitize_sender($sms_sender);
	
	// supplied sender ID as default in case configured sender ID is empty
	if (!$sms_sender && $default_sender_id) {
		$sms_sender = core_sanitize_sender($default_sender_id);
	}
	
	if ($sms_sender && sendsms_sender_isvalid($username, $sms_sender)) {
		$ret = $sms_sender;
	}
	
	return $ret;
}

function sendsms_get_template() {
	global $core_config;
	$templates = array();
	for ($c = 0; $c < count($core_config['featurelist']); $c++) {
		if ($templates = core_hook($core_config['featurelist'][$c], 'sendsms_get_template')) {
			break;
		}
	}
	return $templates;
}

function sendsms_getall_sender($username) {
	global $core_config;
	
	$ret = array();
	
	for ($c = 0; $c < count($core_config['featurelist']); $c++) {
		if ($ret = core_hook($core_config['featurelist'][$c], 'sendsms_getall_sender', array(
			$username
		))) {
			break;
		}
	}
	
	return $ret;
}

function sendsms_sender_isvalid($username, $sender_id) {
	global $core_config;
	
	$ret = FALSE;
	
	for ($c = 0; $c < count($core_config['featurelist']); $c++) {
		if ($ret = core_hook($core_config['featurelist'][$c], 'sendsms_sender_isvalid', array(
			$username,
			$sender_id
		))) {
			break;
		}
	}
	return $ret;
}

/**
 * Get SMS data from $smslog_id
 * @param integer $smslog_id
 * @return array
 */
function sendsms_get_sms($smslog_id) {
	$data = array();
	$db_query = "SELECT * FROM " . _DB_PREF_ . "_tblSMSOutgoing WHERE smslog_id='$smslog_id'";
	$db_result = dba_query($db_query);
	if ($db_row = dba_fetch_array($db_result)) {
		$data = $db_row;
	}
	return $data;
}
