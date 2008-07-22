<?php

function api_user_realm_auth ($filename = '') {
	global $user_realms, $user_auth_realm_filenames;
	/* list all realms that this user has access to */

	if (!isset($user_realms)) {
		if (read_config_option('global_auth') == 'on') {
			$user_realms = db_fetch_assoc('select realm_id from user_auth_realm where user_id=' . $_SESSION['sess_user_id']);
			$user_realms = array_rekey($user_realms, 'realm_id', 'realm_id');
		}else{
			$user_realms = $user_auth_realms;
		}
	}

	if ($filename != '') {
		if (isset($user_realms[$user_auth_realm_filenames{basename($filename)}]))
			return TRUE;
	}
	return FALSE;
}
?>