<?php 

// Fixes a security issue mentioned in the email
if( !defined( 'ABSPATH' ) ){ exit(); }

/*
Plugin Name: HejBit Decentralised Backup
Plugin URI: https://app.hejbit.com/
Description: Backup your WordPress site and database to your Hejbit Swarm Folder on Nextcloud.
Version: 1.0.0
Author: Hejbit
Author URI: https://app.hejbit.com
License: AGPLv3 or later
License URI: https://www.gnu.org/licenses/agpl-3.0.html
Network: True
Contributors: João Raposo <metajoao@metaprovide.org>
*/

// Main folder of the plugin
define('PLUGIN_PATH_HEJBIT', dirname(plugin_dir_path( __FILE__ )) . "/hdb-hejbit-decentralised-backup/");

class hejbit_save_to_nextcloud{	
	
	// Activation
	function activate(){
		
		// Creating tables
		global $wpdb;

		// Fetches the database(DB) types
		$charset_collate = $wpdb->get_charset_collate();

		// Table name
		$nameTable = $wpdb->prefix.'hejbit_saveInProgress';
		// Query for creating the table
		$sql = "CREATE TABLE IF NOT EXISTS $nameTable ( 
					id_zip int(11) NOT NULL auto_increment,
					name text DEFAULT NULL,
					fileNumber varchar(100) DEFAULT 0,
					status int(2) DEFAULT 0,
					uuid text,
					finish int(2) DEFAULT 0, 
					PRIMARY KEY (id_zip)
				)$charset_collate;";		
				
		// Fetches the doc to modify the DB
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');

		// Applies the queries
		dbDelta($sql);
	}
		
	// Deactivation
	function desactivate(){

		//Deletion of the cron jobs
		if ( wp_next_scheduled ('hejbit_Save') ) {
			
			wp_clear_scheduled_hook('hejbit_Save');
		
		};	
		
		if ( wp_next_scheduled ('hejbit_Save',array('next')) ) {
			
			wp_clear_scheduled_hook('hejbit_Save',array('next'));
		
		};
		
		// Delete the tables
		global $wpdb;
		$nameTable = 'hejbit_saveInProgress';
		$wpdb->query(
    		$wpdb->prepare(
        		"DROP TABLE IF EXISTS %s",
        		$wpdb->prefix . $nameTable
    		)
		);
		
		// Deletion of the options
		$plugin_options = $wpdb->get_results("SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%dlwcloud'");
		foreach ($plugin_options as $option) {
			delete_option($option->option_name);
		}		
		
	}

	// Backup
	function hejbit_SaveInProgress($NbrRelance = 0){
		
		global $wpdb;
		$nc_status = true;
		$hejbit_folder = true;

		// Storing the backup 
		$table_site=$wpdb->prefix."hejbit_saveInProgress";
		$rows = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE finish = %d",
				$table_site,
				0
			)
		);

		// If no backup is in progress, create a new backup
		if ( empty( $rows->id_zip ) ) {
										
				// Creation of a new backup
				$nomTable = $wpdb->prefix.'hejbit_saveInProgress';

				$inProgress = array(
					"fileNumber" => 0,
					"status" => 0
				);
				
				$wpdb->insert($nomTable, $inProgress);
								
		}else{
			
				$inProgress = array(
					"fileNumber" => $rows->fileNumber ,
					"uuid" => $rows->uuid ,
					"status" => $rows->status
				);			
	
		};
				
		// Resumption based on the backup status
		// Switch
		switch ($inProgress['status']) {  
				          	    
	          	    
			case "0":
				error_log('DB-Exporting-BEGIN');
				// Export of the DB
				include ('inc/CreateDB.php');
				error_log('DB-Exporting-END');	
				// End of the script before relaunch by cron to avoid timeout
				exit();

			
			
			case "1":
				error_log('ZIP-Creation-BEGIN');	
				// Creation of the Zip
				include ('inc/CreateZip.php');
				error_log('ZIP-Creation-END');							
				// End of the script before relaunch by cron to avoid timeout
				exit();				

			
			case "2":
				error_log('Merging-ZIP-BEGIN');
				// Merging the files to be backed up
				include ('inc/MergeZip.php');
				error_log('Merging-ZIP-END');		
				// End of the script before relaunch by cron to avoid timeout
				exit();				

			
			case "3":
				error_log('Sending-Chunk-BEGIN');
				$nc_status = hejbit_save_to_nextcloud::is_NextCloud_good();
				$hejbit_folder = hejbit_save_to_nextcloud::is_Folder_hejbit();
			

				error_log('Nextcloud status: ' . ($nc_status ? 'active' : 'inactive'));
				error_log('Hejbit folder status: ' . ($hejbit_folder ? 'exists' : 'does not exist'));
				
				// If the connection with NextCloud is correct and folder is hejbit
				if($nc_status && $hejbit_folder){
										
					// Creation of the UUID(Universally Unique Identifier) on NextCloud if it doesn't exist
					if ( empty( $inProgress['uuid'] )){
						// Generates a random 16-bit string
						$data = random_bytes(16);
						// Sets the version to 0100
						$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
						// Changes the 6th and 7th bits to 10
						$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

						// Prepares the folder name with a UUID
						$dirChunk = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
						
						// Prepares the request arguments
						$args = array(
						  // Command to create a folder
						  'method' => 'MKCOL',
						  'timeout' => 30,
						  'redirection' => 5,
						  'httpversion' => '1.0',
						  'blocking' => true,
						  'headers' => array(
							// NextCloud username and password
							'Authorization' => 'Basic ' . base64_encode(get_option("login_dlwcloud") . ":" . get_option("pass_dlwcloud")),
						   ),
						  'body' => array(),
						  'cookies' => array()
						);

						// Sending the request
						$resCreateFolder = wp_remote_request(get_option('url_dlwcloud').'/remote.php/dav/uploads/' . get_option('login_dlwcloud'). '/' . $dirChunk, $args );
					
						//Storing the UUID in the DB
						$datafinish = array(
										"uuid" => $dirChunk
									  );
						$wherefinish = array( "finish" => 0 );
						$wpdb->update( $wpdb->prefix.'hejbit_saveInProgress' , $datafinish, $wherefinish );	
						
						//Adding the created UUID to the inProgress array
						$inProgress['uuid'] = $dirChunk;					
						
					};
					
					// Sending the Zip file in chunks to NextCloud (Recommended method by NextCloud)
					include ('inc/SendChunk.php');
		
					
				} else {

					// We change the backup status, nothing is sent to NextCloud, and we alert that the zip must be retrieved from the FTP
					$datafinish = array(
									"finish" => 1
								  );
					$wherefinish = array( "finish" => 0 );
					$wpdb->update( $wpdb->prefix.'hejbit_saveInProgress' , $datafinish, $wherefinish );
						
					
					if (!$nc_status){
							$info= "The connection to your Nextcloud instance was not established, your backup must be retrieved directly from your web server (ftp).<br>Please check the information regarding your Nextcloud instance and ensure it is accessible online.";
						}else{
							$info= "The connection to your Nextcloud instance has been successfully established. However, an error occurred because the specified backup folder is not a HejBit folder, the folder is incorrect, or the path to the folder is wrong.<br>
Please ensure that your backup folder is obtained directly from your web server (via FTP) and that you designate a HejBit folder as your destination path.";
						}
					// Sending the notification email and cleaning up
				
					$this->sendInfo("ERROR",$info);								
					
				};			
			error_log('Sending-Chunk-END');
			exit();
			
			case "4":
				error_log('MergingChunk-BEGIN');
				// If the connection with NextCloud is correct
				if(hejbit_save_to_nextcloud::is_NextCloud_good()){
					
					// Rebuilding the chunks on NextCloud
					include ('inc/MergeChunk.php');
					
				} else {
					
					
					// Restart in 10 minutes with a retry parameter to only retry 3 times before alerting
 					if ( $NbrRelance < 3 ) {
						
						$NbrRelance++;
						wp_schedule_single_event(time() + 600 ,'hejbit_SaveInProgress', array($NbrRelance));
					
					}else{
						
						// We change the backup status
						$datafinish = array(
										"finish" => 1
									  );
						$wherefinish = array( "finish" => 0 );
						$wpdb->update( $wpdb->prefix.'hejbit_saveInProgress' , $datafinish, $wherefinish );
							
						// Sending the notification email and cleanup
						$info= "The connection to your Nextcloud instance was lost during the sending of your backup, it must be retrieved directly from your web server (ftp).<br>Please check the information regarding your Nextcloud instance and ensure it is accessible online."; 
						$this->sendInfo("ERROR",$info);							
						
					}
					

				};				
				error_log('MergingChunk-END');		
			exit();					

		};
		
	}
	
	// Scheduling the instant backup
	static function hejbit_Save($next=null){
		
		// Clean
		global $wpdb;
		$wpdb->delete( $wpdb->prefix.'hejbit_saveInProgress', array("finish" => "0" ) );
		$filesInFtp = glob(ABSPATH . "hejbitSave_*");
		foreach($filesInFtp as $file){ 	wp_delete_file($file);	};	
		

		// Starting the backup
		if (!wp_next_scheduled ('hejbit_SaveInProgress')) {
			
			wp_schedule_single_event(time(),'hejbit_SaveInProgress');
			
		};
		
		// Direct launch
		if ( !$next ){

			// Redirecting to admin page
			if(is_multisite()){
				// Redirects to the page
				wp_redirect('/wp-admin/network/admin.php?page=hejbit_decentralised-backup&save=now');
			}
			// If we are on a standard site
			else{
				// Redirects to the page
				wp_redirect('/wp-admin/admin.php?page=hejbit_decentralised-backup&save=now');

			}
			
		// Scheduled launch
		}else{
			
			hejbit_save_to_nextcloud::hejbit_programSave();
						
		};
		
	}
	
	// Returns the domain name of the WordPress site
	static function getDomain(){
	
		// Fetches the domain name of the WordPress site
		$urlparts = wp_parse_url(home_url());	
		// Fetches the domain name of the WordPress site
		return $urlparts['host'];
	
	}	
	
	// Sends backup information via email
	static function sendInfo($type,$text){
	
		// Objects
		$sujet = $type . ' > About the backup of '. hejbit_save_to_nextcloud::getDomain();
		$headers[] = 'From: HejBit Decentralised Backup <hejbitbackup@'. hejbit_save_to_nextcloud::getDomain().'>';
		$headers[] = 'Content-Type: text/html; charset=UTF-8';

		wp_mail( get_option('email_dlwcloud') , $sujet, $text, $headers);
		
	}
	
	// Function that tests the connection with NextCloud by sending a request with the provided credentials
	static function is_NextCloud_good(){

		// Initiates a connection test with NextCloud
		$headers = array(
			// Username and password entered in the fields
			'Authorization' => 'Basic ' . base64_encode(get_option("login_dlwcloud") . ":" . get_option("pass_dlwcloud")),
		);

		// Initiates the connection test request
		$nextcloud_response = wp_remote_head(get_option('url_dlwcloud').'/remote.php/dav/files', array('headers' => $headers));	
			
		// If the connection is incorrect
		if(is_wp_error($nextcloud_response)){
			return false;
		}
		// If the connection is correct
		else{
			return true;
		}
	}
	
	// Function that tests the connection with NextCloud and checks if the folder is a Swarm node
	static function is_Folder_hejbit(){
		
		$url = get_option('url_dlwcloud').'/remote.php/dav/files/'. get_option("login_dlwcloud").get_option("folder_dlwcloud");
		error_log('$url: ' . $url);
		$headers = array(
			'Content-Type' => 'application/xml',
			'Depth' => '0',  // Important: this limits to only the root folder
			'Authorization' => 'Basic ' . base64_encode(get_option("login_dlwcloud") . ":" . get_option("pass_dlwcloud")),
		);

		$body = '<?xml version="1.0"?>
		<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" xmlns:oc="http://owncloud.org/ns" xmlns:ocs="http://open-collaboration-services.org/ns">
			<d:prop>
				<nc:ethswarm-node />
			</d:prop>
		</d:propfind>';

		$args = array(
			'method'    => 'PROPFIND',
			'headers'   => $headers,
			'body'      => $body,
			'timeout'   => 30,
		);

		$nextcloud_response = wp_remote_request($url, $args);
			
		// If the connection is incorrect
		if(is_wp_error($nextcloud_response)){
			return false;
		}
		// If the connection is correct
		else{
			//check is folder is swarm
			return hejbit_save_to_nextcloud::check_ethswarm_node_status($nextcloud_response);
		}
	}
	
	static function check_ethswarm_node_status($xml_response) {
		error_log('Full XML response: ' . print_r($xml_response, true));
		// Extract the body from the WordPress response array
		$body = wp_remote_retrieve_body($xml_response);

		// Check if we got a valid body
		if (empty($body)) {
			error_log('Empty response body from Nextcloud');
			return false;
		}
		
		// Create a new SimpleXML object from the XML string
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		
		// If XML couldn't be parsed, return false
		if ($xml === false) {
			$errors = libxml_get_errors();
			foreach ($errors as $error) {
				error_log('XML parsing error: ' . $error->message);
			}
			libxml_clear_errors();
			return false;
		}
		
		// Register the namespaces
		$xml->registerXPathNamespace('d', 'DAV:');
		$xml->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
		
		// Find the ethswarm-node element using XPath
		$nodes = $xml->xpath('//nc:ethswarm-node');
		
		// Check if element exists and its value
		if (count($nodes) > 0) {
			$value = (string)$nodes[0];
			return strtolower($value) === 'true';
		}
		
		// Element not found
		return false;
	}
	
	
	// Scheduled backup
	static function hejbit_programSave(){
		
		if ( wp_next_scheduled ('hejbit_Save',array('next')) ) {
			
			wp_clear_scheduled_hook('hejbit_Save',array('next'));
		
		};		
			
		// If monthly backup
		if(get_option('frequency_dlwcloud') == "month"){
			$timestamp = strtotime('first '.get_option('day_dlwcloud').' of next month '.get_option('hour_dlwcloud').":00");
		}
		// If weekly backup
		else if(get_option('frequency_dlwcloud') == "week"){
			$timestamp = strtotime('next '.get_option('day_dlwcloud').' '.get_option('hour_dlwcloud').":00");
		}
		// Otherwise bi-monthly
		else{
			switch(true){
				
				// If we haven't passed the first $day of the month
				case time() < strtotime('first '.get_option('day_dlwcloud').' of this month '.get_option('hour_dlwcloud').":00"):
					$timestamp = strtotime('first '.get_option('day_dlwcloud').' of this month '.get_option('hour_dlwcloud').":00");
					break;
					
				// If we haven't passed the third $day of the month
				case time() < strtotime('third '.get_option('day_dlwcloud').' of this month '.get_option('hour_dlwcloud').":00"):
					$timestamp = strtotime('third '.get_option('day_dlwcloud').' of this month '.get_option('hour_dlwcloud').":00");
					break;
					
				// Otherwise
				default:
					// The first $day of the following month
					$timestamp = strtotime('first '.get_option('day_dlwcloud').' of next month '.get_option('hour_dlwcloud').":00");
					break;

			}
		}

		wp_schedule_single_event($timestamp,'hejbit_Save',array('next'));

	}
	
	function hejbit_get_memory() {
		
		$memoryBefore = memory_get_usage();
		
		// Retrieve the allocated memory size
		$memoryMax = ini_get('memory_limit');

		switch ( substr ($memoryMax, -1) ) {
			case 'M': case 'm': $memoryMax = (int)$memoryMax * 1048576;break;
			case 'K': case 'k': $memoryMax = (int)$memoryMax * 1024;break;
			case 'G': case 'g': $memoryMax = (int)$memoryMax * 1073741824;break;
			default:break;
		};
		
		// Calculate the remaining memory minus 10% for the rest of the script
		$memoryLimit = ( $memoryMax - $memoryBefore ) * 0.9 ;

		// Limit max memory usage to 314MB
		if ( $memoryLimit > 314572800 ){ $memoryLimit = 314572800; };
		
		return (int)$memoryLimit;
	}	

};

// Admin view
$save_to_nextcloud=new hejbit_save_to_nextcloud();
register_activation_hook( PLUGIN_PATH_HEJBIT . 'HDB.php',array($save_to_nextcloud,'activate'));
register_deactivation_hook( PLUGIN_PATH_HEJBIT . 'HDB.php',array($save_to_nextcloud,'desactivate'));
add_action('hejbit_Save', array($save_to_nextcloud,'hejbit_Save'));
add_action('hejbit_SaveInProgress', array($save_to_nextcloud,'hejbit_SaveInProgress'));
add_action('admin_post_ProgramSave', array($save_to_nextcloud,'hejbit_ProgramSave'));
add_action('admin_post_saveNow',array($save_to_nextcloud,'hejbit_Save'));


// Activation of auto updates for WP
$next_event_timestamp = wp_next_scheduled('hejbit_SaveInProgress');

// If the option to manage auto updates is enabled
if ( get_option("auto_update_dlwcloud") == "true" ) {
	
	global $wpdb;

	// Last backup date
	$lastSave = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT name FROM {$wpdb->prefix}hejbit_saveInProgress ORDER BY id_zip DESC LIMIT %d",
			1 // Placeholder for the LIMIT value
		)
	);

	// Check if there are results
	if ($lastSave) {
		// Extract the last saved date from the result
		$date_str = $lastSave[0]->name;
		
        if (!empty($date_str)){
			// Extract the last 14 characters (assuming the date format is consistent)
			$date_substr = substr($date_str, -14, 14);

			// Extract date components
			$year = substr($date_substr, 0, 4);
			$month = substr($date_substr, 4, 2);
			$day = substr($date_substr, 6, 2);
			$hour = substr($date_substr, 8, 2);
			$minute = substr($date_substr, 10, 2);
			$second = substr($date_substr, 12, 2);

			// Calculate the difference between the current date and the last save date
			$last_save_date = new DateTime("$year-$month-$day $hour:$minute:$second");
			$current_date = new DateTime();
			$date_diff = $current_date->diff($last_save_date);
		
			// Check if the difference is less than two days
			if ($date_diff->days < 2) {
				add_filter('auto_update_core', '__return_true');
				add_filter('auto_update_theme', '__return_true');
				add_filter('auto_update_plugin', '__return_true');
				add_filter('auto_update_translation', '__return_true');
			} else {
				add_filter('auto_update_core', '__return_false');
				add_filter('auto_update_theme', '__return_false');
				add_filter('auto_update_plugin', '__return_false');
				add_filter('auto_update_translation', '__return_false');
			}
		}else{
			// Backup in progress, no auto-update
			add_filter('auto_update_core', '__return_false');
			add_filter('auto_update_theme', '__return_false');
			add_filter('auto_update_plugin', '__return_false');
			add_filter('auto_update_translation', '__return_false');			
		}
		
		
	}
};

// Administration menu
if (is_admin()){ 
	
	// If we are on a multisite
	if(is_multisite()){
		// Add the menu, not as a sub-menu
		add_action('network_admin_menu','hejbit_savetonextcloud_setup_menu');
	}
	// If we are on a single site
	else{
		// Adds the menu in the settings
		add_action('admin_menu','hejbit_savetonextcloud_setup_menu');
	}
	
	// Function to add the menu
	function hejbit_savetonextcloud_setup_menu(){
		// Menu creation
		add_menu_page('HejBit Decentralised Backup', 'HejBit Decentralised Backup', 'manage_options', 'hejbit_nextcloud');
		// Adds a 'Backup' sub-menu
		add_submenu_page('hejbit_nextcloud', 'Backup', 'Backup', 'manage_options', 'hejbit_decentralised-backup', 'hejbit_savetonextcloud_param'); 

		// The method 'add_menu_page()' also creates a 'HejBit Decentralised Backup' sub-menu, so we delete it
		remove_submenu_page('hejbit_nextcloud', 'hejbit_nextcloud');
		 	  
	}

	// Declaration of admin settings
	add_action( 'admin_init', 'hejbit_savetonextcloud_settings' );	

	function hejbit_savetonextcloud_settings() {
    // Register the settings and sanitize the input data
    register_setting( 'nextcloud-group', 'url_dlwcloud', 'sanitize_url_dlwcloud' );
    register_setting( 'nextcloud-group', 'login_dlwcloud', 'sanitize_text_field' );
    register_setting( 'nextcloud-group', 'pass_dlwcloud', 'sanitize_text_field' );
    register_setting( 'nextcloud-group', 'frequency_dlwcloud', 'sanitize_text_field' );
    register_setting( 'nextcloud-group', 'day_dlwcloud', 'sanitize_text_field' );
    register_setting( 'nextcloud-group', 'hour_dlwcloud', 'sanitize_text_field' );
    register_setting( 'nextcloud-group', 'folder_dlwcloud', 'sanitize_text_field' );
    register_setting( 'nextcloud-group', 'email_dlwcloud', 'sanitize_email' );
    register_setting( 'nextcloud-group', 'nb_save_dlwcloud', 'absint' );
    register_setting( 'nextcloud-group', 'auto_update_dlwcloud', 'filter_auto_update' );
    register_setting( 'nextcloud-group', 'db_only_dlwcloud', 'filter_db_only' );
	}

	// Custom sanitization function for URL
	function sanitize_url_dlwcloud($input) {
    	return esc_url_raw($input); // Ensures the input is a valid URL
	}

	// Custom sanitization function for the auto_update field
	function filter_auto_update($input) {
    	return filter_var($input, FILTER_VALIDATE_BOOLEAN); // Ensures the input is a valid boolean
	}

	// Custom sanitization function for the db_only field
	function filter_db_only($input) {
    	return filter_var($input, FILTER_VALIDATE_BOOLEAN); // Ensures the input is a valid boolean
	}
	
	function hejbit_notification() {
		// Check if the settings were successfully updated
		if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
			
			$timestamp = wp_next_scheduled('hejbit_Save','next');
			$date_format = 'j F Y, H:i'; // Custom date format
			$formatted_date = date_i18n($date_format, $timestamp);			

			$notif = "Your next backup is scheduled for the ".$formatted_date.". If you haven't done so yet, click the \"Backup Now\" button to avoid waiting.";
			add_settings_error('hejbit', 'hejbit_success', $notif, 'updated-nag');
        
		}else if(isset($_GET['save'])){
			
			$notif = "The backup is in progress, this may take a few minutes. You will receive an email once it is complete.";
			add_settings_error('hejbit', 'hejbit_success', $notif, 'updated-nag');

		};
	}
	// add_action('admin_notices', 'hejbit_notification');
	add_action('admin_init', 'hejbit_notification');

	
}

/*
 * API
 * 
 */

function all_user_param() {
	$nameParam = array("frequency_dlwcloud", "day_dlwcloud", "hour_dlwcloud", "nb_save_dlwcloud");
	
	$allParam = array();
	
	foreach($nameParam as $param){
		$allParam[$param] = get_option($param);
	}

	// Checking for an update
	$allParam['NeedUpdate'] = 'noneed';

	// Load admin files if necessary (especially on the front-end)
	if ( ! function_exists( 'get_core_updates' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php'; // Load the update.php file
	}

	// Check if the site is multisite and retrieve the appropriate updates
	$plugin_updates = is_multisite() ? get_site_transient('update_plugins') : get_transient('update_plugins');
	$theme_updates = is_multisite() ? get_site_transient('update_themes') : get_transient('update_themes');
	$core_updates = get_core_updates(); // Works for both types of installation

	// Check for updates for plugins, themes, or core
	if ( ! empty($plugin_updates->response) || ! empty($theme_updates->response) || (!empty($core_updates) && isset($core_updates[0]->response) && $core_updates[0]->response === 'upgrade') ) {
		$allParam['NeedUpdate'] = 'need';
	}
	
	return $allParam;
};

add_action( 'rest_api_init', function () {
	// Create the 'parameter' route in the API
	register_rest_route("HDB", 'param', array(
	'methods' => 'GET',
	'callback' => 'all_user_param',
	'permission_callback' => '__return_true',
	) );
} );
	

function get_all_saves() {

	global $wpdb;
	
	// Initialize the array to store the results
	$result = array();

	// Execute the query to retrieve all backups
    $allSaves = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}hejbit_saveInProgress"
    );
    
    return $allSaves;
	
	// Add the number of backups to the result
	$result["nbSaves"] = count($allSaves);
	
	// Retrieving the date of the next backup
	$timestamp = wp_next_scheduled( 'hejbit_Save',array('next'));

	if ( $timestamp ) {
		
		$date = gmdate( 'Y-m-d H:i:s', $timestamp );
		$result["nextSave"] =  $date;
		
	} else {
		
		$result["nextSave"] =  "empty";
	};	
	
	// For each backup
	foreach($allSaves as $save){
		
		// We change the format of the variable
		$save = get_object_vars($save);
		
		$result[] = array(
			"date" => $save["name"],
			"uuid" => $save["uuid"],
			"status" => $save["status"],
			"Number of files" => $save["fileNumber"],
			"finish" => $save["finish"]
		);
	}

	// Returns the result array
	return $result;
	
};
	
// Create the action 'get_user_param'
add_action( 'rest_api_init', function () {
		// Create the "parameter" route in the API
		register_rest_route("HDB", 'saves', array(
		// GET Method
		'methods' => 'GET',
		// Call the method 'all_user_param'
		'callback' => 'get_all_saves',
		'permission_callback' => '__return_true',
	) );
} );



// Admin page
function hejbit_savetonextcloud_param(){?>
	
<div class="wrap">
	<h2>HejBit Decentralised Backup</h2>
	<h2>Backup</h2>
	<p>Please fill in your settings</p>
	<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
		<input type="hidden" name="action" value="ProgramSave">
		<?php 
		settings_fields( 'nextcloud-group' );
		do_settings_fields( 'nextcloud-group','dlwcloud' );?>

		<table class="form-table">
			<tr valign="top">
			<th scope="row" style="width:350px;">URL ( https://cloud.domain.com )</th>
			<td><input type="text" name="url_dlwcloud" value="<?php echo esc_url(get_option('url_dlwcloud')); ?>" required/></td>
			</tr>

			<tr valign="top">	
			<th scope="row" style="width:350px;">Login</th>
			<td><input type="text" name="login_dlwcloud" value="<?php echo esc_html(get_option('login_dlwcloud')); ?>" required/></td>
			</tr>

			<tr valign="top">
			<th scope="row" style="width:350px;">Password</th>
		        <td><input type="password" name="pass_dlwcloud" value="<?php echo esc_html(get_option('pass_dlwcloud')); ?>" required/>
		        </td>
			</tr>

			<tr valign="top">
			<th scope="row" style="width:350px;">Backup Frequency</th>
			<td><select name="frequency_dlwcloud">
			<option value="week" <?php if(get_option('frequency_dlwcloud') == "week"){ ?> selected <?php } ?>>Weekly</option>
			<option value="twicemonth" <?php if(get_option('frequency_dlwcloud') == "twicemonth"){ ?> selected <?php } ?>>Bi-monthly
			</option>
			<option value="month" <?php if(empty(get_option('frequency_dlwcloud')) || get_option('frequency_dlwcloud') == "month"){ 
			?> selected <?php } ?>>Monthly</option>
			</select>
			</td>
			</tr>

			<tr valign="top">
			<th scope="row" style="width:350px;">Backup day</th>
			<td><select name="day_dlwcloud">
			<option value="Monday" <?php if(empty(get_option('day_dlwcloud')) || get_option('day_dlwcloud') == "Monday"){ ?> selected <?php } ?>>Monday</option>
			<option value="Tuesday" <?php if(get_option('day_dlwcloud') == "Tuesday"){ ?> selected <?php } ?>>Tuesday</option>
			<option value="Wednesday" <?php if(get_option('day_dlwcloud') == "Wednesday"){ ?> selected <?php } ?>>Wednesday</option>
			<option value="Thursday" <?php if(get_option('day_dlwcloud') == "Thursday"){ ?> selected <?php } ?>>Thursday</option>
			<option value="Friday" <?php if(get_option('day_dlwcloud') == "Friday"){ ?> selected <?php } ?>>Friday</option>
			<option value="Saturday" <?php if(get_option('day_dlwcloud') == "Saturday"){ ?> selected <?php } ?>>Saturday</option>
			<option value="Sunday" <?php if(get_option('day_dlwcloud') == "Sunday"){ ?> selected <?php } ?>>Sunday</option>
			</select>
			</td>
			</tr>

			<tr valign="top">
			<th scope="row" style="width:350px;">Backup time</th>
			<td><input type="time" name="hour_dlwcloud" value="<?php echo esc_html(get_option('hour_dlwcloud')); ?>" required/></td>
			</tr> 

			<tr valign="top">
			<th scope="row" style="width:350px;">Remote Backup Folder ( /Hejbit/WordpressBackups/ )</th>
			<td><input type="text" name="folder_dlwcloud" value="<?php if (!empty(get_option('folder_dlwcloud'))){echo 
			esc_html(get_option('folder_dlwcloud'));}else{echo "";};?>" required/></td>
			</tr>

			<tr valign="top">
			<th scope="row" style="width:350px;">Notification email separated by ;</th>
			<td><input type="text" name="email_dlwcloud" value="<?php if (!empty(get_option('email_dlwcloud'))){echo 
			esc_html(get_option('email_dlwcloud'));}?>" required/></td>
			</tr>    
		</table>
		
		<table>
			<tr valign="top">
			<th scope="row"></th>
			<td>
			<details>
				<summary>View advanced settings</summary>
				<table class="form-table"> 

					<th scope="row" style="width:350px;">Number of backups to keep on server</th>
					<td>
					<select name="nb_save_dlwcloud">
						<option value="1" <?php if(get_option('nb_save_dlwcloud') == "1"){ ?> selected <?php } ?>>1</option>
						<option value="2" <?php if(get_option('nb_save_dlwcloud') == "2"){ ?> selected <?php } ?>>2</option>
						<option value="3" <?php if(empty(get_option('day_dlwcloud')) || get_option('nb_save_dlwcloud') == "3"){ ?> selected <?php } ?>>3</option>
						<option value="4" <?php if(get_option('nb_save_dlwcloud') == "4"){ ?> selected <?php } ?>>4</option>
						<option value="5" <?php if(get_option('nb_save_dlwcloud') == "5"){ ?> selected <?php } ?>>5</option>
						<option value="6" <?php if(get_option('nb_save_dlwcloud') == "6"){ ?> selected <?php } ?>>6</option>
						<option value="7" <?php if(get_option('nb_save_dlwcloud') == "7"){ ?> selected <?php } ?>>7</option>
						<option value="8" <?php if(get_option('nb_save_dlwcloud') == "8"){ ?> selected <?php } ?>>8</option>
						<option value="9" <?php if(get_option('nb_save_dlwcloud') == "9"){ ?> selected <?php } ?>>9</option>
						<option value="10" <?php if(get_option('nb_save_dlwcloud') == "10"){ ?> selected <?php } ?>>10</option>
					</select></td>
					</tr>

					<th scope="row" style="width:350px;">Do you want to enable your automatic updates only after a backup?</th>
					<td><select name="auto_update_dlwcloud">
					<option value="true" <?php if(get_option('auto_update_dlwcloud') == "true"){ ?> selected <?php } ?>>yes
					</option>
					<option value="false" <?php if(empty(get_option('auto_update_dlwcloud')) || 
					get_option('auto_update_dlwcloud') == "false"){ ?> selected <?php } ?>>No</option>					
					</select>
					</br><p>For the proper functioning of your site, updates should only be performed after a full backup.
					If you enable this option, plugins, core and themes will be updated automatically only after the saved schedule.
					In case of problems, you can therefore restore the last backup of your site.</p>
					</tr> 

					<th scope="row" style="width:350px;">Do you want to backup only the database(s)?</th>
					<td><select name="db_only_dlwcloud">
					<option value="true" <?php if(get_option('db_only_dlwcloud') == "true"){ ?> selected <?php } ?>>Yes
					</option>
					<option value="false" <?php if(empty(get_option('db_only_dlwcloud')) || 
					get_option('db_only_dlwcloud') == "false"){ ?> selected <?php } ?>>No</option>					
					</select>
					</tr> 
				</table>

			</details>
			</td>
			</tr> 

		</table>
		<?php
		// If we are saving new options
		if (isset($_GET['settings-updated']) && $_GET['settings-updated'] == true) {
			// Schedule the next backup
			hejbit_save_to_nextcloud::hejbit_ProgramSave();
		}
		submit_button("Save the schedule"); ?>
	</form>
	<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
		<input type="hidden" name="action" value="saveNow">
		<?php submit_button('Make a backup now');?>
	</form>		
	<p>
    	The <strong>"Save the schedule"</strong> button allows you to automate the launch of future backups according to the preferences indicated:
	</p>
	<ul>
    	<li><strong>Weekly:</strong> Every week at the chosen time and day, starting next week.</li>
    	<li><strong>Bi-monthly:</strong> The first and third week of the month at the chosen time and day.</li>
    	<li><strong>Monthly:</strong> The first week of the month at the chosen time and day, starting next month.</li>
	</ul>
	<p>
    	The <strong>"Make a backup now"</strong> button allows you to launch a backup without waiting.
	</p>
</div>
<?php settings_errors('hejbit'); };?>
