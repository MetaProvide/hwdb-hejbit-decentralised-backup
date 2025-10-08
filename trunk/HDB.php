<?php

/**
 * Plugin Name: HejBit Decentralised Backup
 * Plugin URI: https://metaprovide.org/hejbit/
 * Description: Securely back up your WordPress site to decentralized storage via Nextcloud and HejBit.
 * Requires at least: 6.7
 * Tested up to: 6.7.2
 * Requires PHP: 7.3
 * Version: 1.0.7
 * Author: Hejbit, Joaosraposo
 * License: AGPLv3
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Network: True
 */

// Custom tables used exclusively by this plugin and for infrequent operations (backup)
// phpcs:disable WordPress.DB

// Fixes a security issue
if (!defined('ABSPATH')) {
	exit();
}

// Load the WordPress filesystem
class hejbit_save_to_nextcloud
{

	// Activation
	function activate()
	{

		// Creating tables
		global $wpdb;

		// Fetches the database(DB) types
		$charset_collate = $wpdb->get_charset_collate();

		// Table name
		$nameTable = $wpdb->prefix . 'hejbit_saveInProgress';
		// Query for creating the table
		$sql1 = "CREATE TABLE IF NOT EXISTS $nameTable ( 
					id_zip int(11) NOT NULL auto_increment,
					name text DEFAULT NULL,
					fileNumber varchar(100) DEFAULT 0,
					status int(2) DEFAULT 0,
					uuid text,
					finish int(2) DEFAULT 0, 
					PRIMARY KEY (id_zip)
				)$charset_collate;";

		// Create logs table
    	$logsTable = $wpdb->prefix . 'hejbit_logs';
    	$sql2 = "CREATE TABLE IF NOT EXISTS $logsTable (
            		id int(11) NOT NULL auto_increment,
                	backup_id int(11) DEFAULT NULL,
                	timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                	log_level varchar(20) DEFAULT 'INFO',
                	step varchar(50) DEFAULT NULL,
                	message text,
                	details longtext,
                	PRIMARY KEY (id),
                	KEY backup_id_idx (backup_id),
                	KEY timestamp_idx (timestamp)
            	)$charset_collate;";		

		// Fetches the doc to modify the DB
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		// Applies the queries
		dbDelta($sql1);
		dbDelta($sql2);

	}

	// Deactivation
	function deactivate()
	{

		//Deletion of the cron jobs
		if (wp_next_scheduled('hejbit_Save')) {
			wp_clear_scheduled_hook('hejbit_Save');
		};

		if (wp_next_scheduled('hejbit_Save', array('next'))) {
			wp_clear_scheduled_hook('hejbit_Save', array('next'));
		};

		// Delete the tables
		global $wpdb;
		$tables = array('hejbit_saveInProgress', 'hejbit_logs');

		foreach ($tables as $table) {
			$table_name = $wpdb->prefix . $table;
			$wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
		}

		// Deletion of the options
		$plugin_options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'%hejbit_%'
			)
		);
		foreach ($plugin_options as $option) {
			delete_option($option->option_name);
		}
	}

	// Backup
	function hejbit_SaveInProgress($NbrRelance = 0)
	{

		global $wpdb;
		$nc_status = true;
		$hejbit_folder = true;

		// Storing the backup 
		$table_site = $wpdb->prefix . "hejbit_saveInProgress";
		$rows = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table_site}` WHERE finish = %d",
				0
			)
		);

		// If no backup is in progress, create a new backup
		if (empty($rows->id_zip)) {

			 // Log new backup start
        	self::log('Starting new backup process', 'INFO', 'INIT');
			
			// Creation of a new backup
			$tableName = $wpdb->prefix . 'hejbit_saveInProgress';

			$inProgress = array(
				"fileNumber" => 0,
				"status" => 0
			);

			$wpdb->insert($tableName, $inProgress);
		} else {
			// Log resume
			self::log('Resuming backup process', 'INFO', 'RESUME', array(
				'status' => $rows->status,
				'fileNumber' => $rows->fileNumber
			));

			$inProgress = array(
				"fileNumber" => $rows->fileNumber,
				"uuid" => $rows->uuid,
				"status" => $rows->status
			);
		};

		// Resumption based on the backup status (add logging for each case)
		switch ($inProgress['status']) {


			case "0":
				self::log('Starting database export', 'INFO', 'CREATE_DB');
				// Export of the DB
				include plugin_dir_path(__FILE__) . 'inc/CreateDB.php';
				// End of the script before relaunch by cron to avoid timeout
				exit();



			case "1":
				self::log('Starting ZIP creation', 'INFO', 'CREATE_ZIP');
				// Creation of the Zip
				include plugin_dir_path(__FILE__) . 'inc/CreateZip.php';
				// End of the script before relaunch by cron to avoid timeout
				exit();


			case "2":
				self::log('Merging backup files', 'INFO', 'MERGE_ZIP');
				// Merging the files to be backed up
				include plugin_dir_path(__FILE__) . 'inc/MergeZip.php';
				// End of the script before relaunch by cron to avoid timeout
				exit();


			case "3":
				self::log('Preparing to send to NextCloud', 'INFO', 'SEND_CHUNK');

				$nc_status = hejbit_save_to_nextcloud::is_NextCloud_good();
				$hejbit_folder = hejbit_save_to_nextcloud::is_Folder_hejbit();

				if (!$nc_status) {
					self::log('NextCloud connection failed', 'ERROR', 'SEND_CHUNK');
				}
				if (!$hejbit_folder) {
					self::log('Invalid HejBit folder', 'ERROR', 'SEND_CHUNK');
				}

				// If the connection with NextCloud is correct and folder is hejbit
				if ($nc_status && $hejbit_folder) {

					// Creation of the UUID(Universally Unique Identifier) on NextCloud if it doesn't exist
					if (empty($inProgress['uuid'])) {
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
								'Authorization' => 'Basic ' . base64_encode(get_option("hejbit_login_dlwcloud") . ":" . get_option("hejbit_pass_dlwcloud")),
							),
							'body' => array(),
							'cookies' => array()
						);

						// Sending the request
						$resCreateFolder = wp_remote_request(get_option('hejbit_url_dlwcloud') . '/remote.php/dav/uploads/' . get_option('hejbit_login_dlwcloud') . '/' . $dirChunk, $args);

						//Storing the UUID in the DB
						$datafinish = array(
							"uuid" => $dirChunk
						);
						$wherefinish = array("finish" => 0);
						$wpdb->update($wpdb->prefix . 'hejbit_saveInProgress', $datafinish, $wherefinish);

						//Adding the created UUID to the inProgress array
						$inProgress['uuid'] = $dirChunk;
					};

					// Sending the Zip file in chunks to NextCloud (Recommended method by NextCloud)
					include plugin_dir_path(__FILE__) . 'inc/SendChunk.php';
				} else {

					// We change the backup status, nothing is sent to NextCloud, and we alert that the zip must be retrieved from the FTP
					$datafinish = array(
						"finish" => 1
					);
					$wherefinish = array("finish" => 0);
					$wpdb->update(esc_sql($wpdb->prefix) . 'hejbit_saveInProgress', $datafinish, $wherefinish);


					if (!$nc_status) {
						$info = "The connection to your Nextcloud instance was not established, your backup must be retrieved directly from your web server (ftp).<br>Please check the information regarding your Nextcloud instance and ensure it is accessible online.";
					} else {
						$info = "The connection to your Nextcloud instance has been successfully established. However, an error occurred because the specified backup folder is not a Hejbit folder, the folder is incorrect, or the path to the folder is wrong.<br>
Please ensure that your backup folder is obtained directly from your web server (via FTP) and that you designate a Hejbit folder as your destination path.";
					}
					// Sending the notification email and cleaning up

					$this->sendInfo("ERROR", $info);
				};

				// Only log END when we're done with all chunks (this will be logged when SendChunk.php changes status to 4)
				// The END message will appear in case "4" or when the process is complete

				exit();

				exit();

			case "4":
				self::log('Merging chunks on NextCloud', 'INFO', 'MERGE_CHUNK');

				// If the connection with NextCloud is correct
				if (hejbit_save_to_nextcloud::is_NextCloud_good()) {

					// Rebuilding the chunks on NextCloud
					include plugin_dir_path(__FILE__) . 'inc/MergeChunk.php';
				} else {


					// Restart in 10 minutes with a retry parameter to only retry 3 times before alerting
					if ($NbrRelance < 3) {

						$NbrRelance++;
						wp_schedule_single_event(time() + 600, 'hejbit_SaveInProgress', array($NbrRelance));
					} else {

						// We change the backup status
						$datafinish = array(
							"finish" => 1
						);
						$wherefinish = array("finish" => 0);
						$wpdb->update($wpdb->prefix . 'hejbit_saveInProgress', $datafinish, $wherefinish);

						// Sending the notification email and cleanup
						$info = "The connection to your Nextcloud instance was lost during the sending of your backup, it must be retrieved directly from your web server (ftp).<br>Please check the information regarding your Nextcloud instance and ensure it is accessible online.";
						$this->sendInfo("ERROR", $info);
					}
				};

				exit();
		};
	}

	// Scheduling the instant backup
	static function hejbit_Save($next = null)
	{
		// Verify nonce for direct backups FIRST
		if (!$next) {
			// Check if this is a POST request (form submission)
			if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
				if (
					!isset($_POST['hejbit_save_now_nonce']) ||
					!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hejbit_save_now_nonce'])), 'hejbit_save_now_action')
				) {
					wp_die('Security check failed');
				}
			}
			// For GET requests, check the URL nonce
			else {
				if (
					!isset($_GET['_wpnonce']) ||
					!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'hejbit_save_action')
				) {
					wp_die('Security check failed');
				}
			}
		}
		// Clean
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'hejbit_saveInProgress', array("finish" => "0"));
		$upload_dir = wp_upload_dir();
		$hejbit_upload_dir = $upload_dir['basedir'] . '/hejbit-backups/';
		$filesInFtp = glob($hejbit_upload_dir . "hejbitSave_*");
		foreach ($filesInFtp as $file) {
			wp_delete_file($file);
		};

		// Clean old logs (keep last 30 days by default)
		$logs_retention = get_option('hejbit_logs_retention', 30);
		hejbit_save_to_nextcloud::clean_logs($logs_retention);

		// Starting the backup - THIS SHOULD ALWAYS HAPPEN
		if (!wp_next_scheduled('hejbit_SaveInProgress')) {
			wp_schedule_single_event(time(), 'hejbit_SaveInProgress');
		};

		// Direct launch
		if (!$next) {
			// Creating a nonce for the action
			$nonce = wp_create_nonce('hejbit_save_action');

			// Redirecting to admin page
			if (is_multisite()) {
				wp_redirect(add_query_arg(
					array(
						'page' => 'hejbit_decentralised-backup',
						'backup_started' => 'true', // Changed from 'save' => 'now'
						'_wpnonce' => $nonce
					),
					network_admin_url('admin.php')
				));
			} else {
				wp_redirect(add_query_arg(
					array(
						'page' => 'hejbit_decentralised-backup',
						'backup_started' => 'true', // Changed from 'save' => 'now'
						'_wpnonce' => $nonce
					),
					admin_url('admin.php')
				));
			}
			exit(); // Important: exit after redirect
		}
		// Scheduled launch
		else {
			hejbit_save_to_nextcloud::hejbit_programSave();
		};
	}

	// Returns the domain name of the WordPress site
	static function getDomain()
	{

		// Fetches the domain name of the WordPress site
		$urlparts = wp_parse_url(home_url());
		// Fetches the domain name of the WordPress site
		return $urlparts['host'];
	}

	// Sends backup information via email
	static function sendInfo($type, $text)
	{

		// Objects
		$subject = $type . ' > About the backup of ' . hejbit_save_to_nextcloud::getDomain();
		$headers[] = 'From: HejBit Decentralised Backup <hejbitbackup@' . hejbit_save_to_nextcloud::getDomain() . '>';
		$headers[] = 'Content-Type: text/html; charset=UTF-8';

		wp_mail(get_option('hejbit_email_dlwcloud'), $subject, $text, $headers);
	}

	// Function that tests the connection with NextCloud by sending a request with the provided credentials
	static function is_NextCloud_good()
	{

		// Initiates a connection test with NextCloud
		$headers = array(
			// Username and password entered in the fields
			'Authorization' => 'Basic ' . base64_encode(get_option("hejbit_login_dlwcloud") . ":" . get_option("hejbit_pass_dlwcloud")),
		);

		// Initiates the connection test request
		$nextcloud_response = wp_remote_head(get_option('hejbit_url_dlwcloud') . '/remote.php/dav/files', array('headers' => $headers));

		// Check if the request failed to execute
		if (is_wp_error($nextcloud_response)) {
			return false;
		}

		// Check the HTTP response code
		$response_code = wp_remote_retrieve_response_code($nextcloud_response);

		// Only 2xx status codes indicate success
		if ($response_code >= 200 && $response_code < 300) {
			return true;
		} else {
			return false;
		}
	}
	// Function that tests the connection with NextCloud and checks if the folder is a Swarm node
	static function is_Folder_hejbit()
	{

		// Validate required options exist
		$url_base = get_option('hejbit_url_dlwcloud');
		$login = get_option("hejbit_login_dlwcloud");
		$folder = get_option("hejbit_folder_dlwcloud");
		$password = get_option("hejbit_pass_dlwcloud");

		if (empty($url_base) || empty($login) || empty($folder) || empty($password)) {
			return false;
		}

		$url = $url_base . '/remote.php/dav/files/' . $login . $folder;

		$headers = array(
			'Content-Type' => 'application/xml',
			'Depth' => '0',
			'Authorization' => 'Basic ' . base64_encode($login . ":" . $password),
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

		// Check if the request failed to execute
		if (is_wp_error($nextcloud_response)) {
			return false;
		}

		// Check the HTTP response code
		$response_code = wp_remote_retrieve_response_code($nextcloud_response);

		// Handle different response codes appropriately
		switch ($response_code) {
			case 207: // Multi-Status (WebDAV success)
				// This is the expected success response for PROPFIND
				return hejbit_save_to_nextcloud::check_ethswarm_node_status($nextcloud_response);

			case 401:
				return false;

			case 403:
				return false;

			case 404:
				return false;

			case 405:
				return false;

			default:
				if ($response_code >= 200 && $response_code < 300) {
					// Other 2xx codes might be valid
					return hejbit_save_to_nextcloud::check_ethswarm_node_status($nextcloud_response);
				} else {
					return false;
				}
		}
	}
	static function check_ethswarm_node_status($xml_response)
	{

		// Extract the body from the WordPress response array
		$body = wp_remote_retrieve_body($xml_response);

		// Check if we got a valid body
		if (empty($body)) {
			return false;
		}

		// Create a new SimpleXML object from the XML string
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);

		// If XML couldn't be parsed, return false
		if ($xml === false) {
			$errors = libxml_get_errors();

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
			$value = trim((string)$nodes[0]);

			// Accept both "1" and "true" as valid values
			$is_swarm = (strtolower($value) === 'true' || $value === '1');

			return $is_swarm;
		}

		// Element not found
		return false;
	}

	// Scheduled backup
	static function hejbit_programSave()
	{

		if (wp_next_scheduled('hejbit_Save', array('next'))) {

			wp_clear_scheduled_hook('hejbit_Save', array('next'));
		};

		// If monthly backup
		if (get_option('hejbit_frequency_dlwcloud') == "month") {
			$timestamp = strtotime('first ' . get_option('hejbit_day_dlwcloud') . ' of next month ' . get_option('hejbit_hour_dlwcloud') . ":00");
		}
		// If weekly backup
		else if (get_option('hejbit_frequency_dlwcloud') == "week") {
			$timestamp = strtotime('next ' . get_option('hejbit_day_dlwcloud') . ' ' . get_option('hejbit_hour_dlwcloud') . ":00");
		}
		// Otherwise bi-monthly
		else {
			switch (true) {

				// If we haven't passed the first $day of the month
				case time() < strtotime('first ' . get_option('hejbit_day_dlwcloud') . ' of this month ' . get_option('hejbit_hour_dlwcloud') . ":00"):
					$timestamp = strtotime('first ' . get_option('hejbit_day_dlwcloud') . ' of this month ' . get_option('hejbit_hour_dlwcloud') . ":00");
					break;

				// If we haven't passed the third $day of the month
				case time() < strtotime('third ' . get_option('hejbit_day_dlwcloud') . ' of this month ' . get_option('hejbit_hour_dlwcloud') . ":00"):
					$timestamp = strtotime('third ' . get_option('hejbit_day_dlwcloud') . ' of this month ' . get_option('hejbit_hour_dlwcloud') . ":00");
					break;

				// Otherwise
				default:
					// The first $day of the following month
					$timestamp = strtotime('first ' . get_option('hejbit_day_dlwcloud') . ' of next month ' . get_option('hejbit_hour_dlwcloud') . ":00");
					break;
			}
		}

		wp_schedule_single_event($timestamp, 'hejbit_Save', array('next'));
	}


	static function hejbit_get_memory()
	{

		$memoryBefore = memory_get_usage();

		// Retrieve the allocated memory size
		$memoryMax = ini_get('memory_limit');

		switch (substr($memoryMax, -1)) {
			case 'M':
			case 'm':
				$memoryMax = (int)$memoryMax * 1048576;
				break;
			case 'K':
			case 'k':
				$memoryMax = (int)$memoryMax * 1024;
				break;
			case 'G':
			case 'g':
				$memoryMax = (int)$memoryMax * 1073741824;
				break;
			default:
				break;
		};

		// Calculate the remaining memory minus 10% for the rest of the script
		$memoryLimit = ($memoryMax - $memoryBefore) * 0.9;

		// Add memory constant with filter
		define('HEJBIT_MAX_MEMORY_LIMIT', apply_filters('hejbit_max_memory_limit', 314572800)); // 300MB

		// Limit max memory usage
		if ($memoryLimit > HEJBIT_MAX_MEMORY_LIMIT) {
			$memoryLimit = HEJBIT_MAX_MEMORY_LIMIT;
		};

		return (int)$memoryLimit;
	}

	// Logging method
	static function log($message, $level = 'INFO', $step = null, $details = null) {
		global $wpdb;
		
		// Get current backup ID
		$backup_id = null;
		$current_backup = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id_zip FROM {$wpdb->prefix}hejbit_saveInProgress WHERE finish = %d",
				0
			)
		);
		
		if ($current_backup) {
			$backup_id = $current_backup->id_zip;
		}
		
		// Insert log entry
		$wpdb->insert(
			$wpdb->prefix . 'hejbit_logs',
			array(
				'backup_id' => $backup_id,
				'log_level' => $level,
				'step' => $step,
				'message' => $message,
				'details' => $details ? json_encode($details) : null
			),
			array('%d', '%s', '%s', '%s', '%s')
		);
	}

	// Get logs for display
	static function get_logs($backup_id = null, $limit = 100, $offset = 0) {
		global $wpdb;
		
		$where = '';
		$prepare_values = array();
		
		if ($backup_id !== null) {
			$where = "WHERE backup_id = %d";
			$prepare_values[] = $backup_id;
		}
		
		$prepare_values[] = $limit;
		$prepare_values[] = $offset;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, s.name as backup_name 
				FROM {$wpdb->prefix}hejbit_logs l
				LEFT JOIN {$wpdb->prefix}hejbit_saveInProgress s ON l.backup_id = s.id_zip
				{$where}
				ORDER BY l.timestamp DESC
				LIMIT %d OFFSET %d",
				$prepare_values
			)
		);
	}

	// Clean old logs
	static function clean_logs($days_to_keep = 30) {
		global $wpdb;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}hejbit_logs 
				WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_to_keep
			)
		);
		return $deleted;
	}
};

// phpcs:enable WordPress.DB

// Admin view
$save_to_nextcloud = new hejbit_save_to_nextcloud();
register_activation_hook(__FILE__, array($save_to_nextcloud, 'activate'));
register_deactivation_hook(__FILE__, array($save_to_nextcloud, 'deactivate'));
add_action('hejbit_Save', array($save_to_nextcloud, 'hejbit_Save'));
add_action('hejbit_SaveInProgress', array($save_to_nextcloud, 'hejbit_SaveInProgress'));
add_action('admin_post_saveNow', array($save_to_nextcloud, 'hejbit_Save'));


// Activation of auto updates for WP
$next_event_timestamp = wp_next_scheduled('hejbit_SaveInProgress');



// Administration menu
if (is_admin()) {

	// If we are on a multisite
	if (is_multisite()) {
		// Add the menu, not as a sub-menu
		add_action('network_admin_menu', 'hejbit_savetonextcloud_setup_menu');
	}
	// If we are on a single site
	else {
		// Adds the menu in the settings
		add_action('admin_menu', 'hejbit_savetonextcloud_setup_menu');
	}

	// Function to add the menu
	function hejbit_savetonextcloud_setup_menu()
	{
		// Menu creation
		add_menu_page('HejBit Decentralised Backup', 'HejBit Decentralised Backup', 'manage_options', 'hejbit_nextcloud');
		
		// Adds a 'Backup' sub-menu
		add_submenu_page('hejbit_nextcloud', 'Backup', 'Backup', 'manage_options', 'hejbit_decentralised-backup', 'hejbit_savetonextcloud_param');

		// Add logs sub-menu
    	add_submenu_page('hejbit_nextcloud', 'Logs', 'Logs', 'manage_options', 'hejbit_logs', 'hejbit_logs_page');

		// The method 'add_menu_page()' also creates a 'HejBit Decentralised Backup' sub-menu, so we delete it
		remove_submenu_page('hejbit_nextcloud', 'hejbit_nextcloud');
	}

	// Declaration of admin settings
	add_action('admin_init', 'hejbit_savetonextcloud_settings');

	function hejbit_savetonextcloud_settings()
	{
		// Register the settings and sanitize the input data
		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_url_dlwcloud',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_url',
			)
		);

		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_login_dlwcloud',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_pass_dlwcloud',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_frequency_dlwcloud',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_day_dlwcloud',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_hour_dlwcloud',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_folder_dlwcloud',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_email_dlwcloud',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_email',
			)
		);

		register_setting(
			'hejbit_nextcloud-group',
			'hejbit_db_only_dlwcloud',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'hejbit_filter_db_only',
			)
		);
	}


	// Custom sanitization function for the db_only field
	function hejbit_filter_db_only($input)
	{
		return filter_var($input, FILTER_VALIDATE_BOOLEAN); // Ensures the input is a valid boolean
	}

	// Update your admin_init action to handle scheduling FIRST
	add_action('admin_init', 'hejbit_handle_scheduling'); // Add this BEFORE notification
	add_action('admin_init', 'hejbit_notification');

	function hejbit_handle_scheduling()
	{
		// Handle scheduling when settings are updated
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (isset($_GET['settings-updated']) && sanitize_text_field(wp_unslash($_GET['settings-updated'])) === 'true') {
			// Schedule the backup - ALWAYS when settings are updated
			hejbit_save_to_nextcloud::hejbit_programSave();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	function hejbit_notification()
	{
		// Check if the settings were successfully updated FIRST - PRIORITY
		if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {

			// ALWAYS show schedule notification when settings are updated
			$timestamp = wp_next_scheduled('hejbit_Save', array('next'));

			if ($timestamp) {
				$date_format = 'j F Y, H:i';
				$formatted_date = date_i18n($date_format, $timestamp);

				$notif = "Your next backup is scheduled for the {$formatted_date}. If you haven't done so yet, click the \"Backup Now\" button to avoid waiting.";
			} else {
				$notif = "Backup scheduling completed, but could not retrieve the scheduled time.";
			}

			add_settings_error('hejbit', 'hejbit_success', $notif, 'updated-nag');

			// IMPORTANT: Return here to prevent any other notifications
			return;
		}

		// For backup initiated notification - ONLY if settings were NOT updated
		if (isset($_GET['backup_started']) && sanitize_text_field(wp_unslash($_GET['backup_started'])) === 'true' && isset($_GET['_wpnonce'])) {
			$nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));

			if (wp_verify_nonce($nonce, 'hejbit_save_action')) {
				$notif = "The backup is in progress, this may take a few minutes. You will receive an email once it is complete.";
				add_settings_error('hejbit', 'hejbit_success', $notif, 'updated-nag');
			} else {
				add_settings_error('hejbit', 'hejbit_error', 'Security verification failed.', 'error');
			}
		}
	}
	// add_action('admin_notices', 'hejbit_notification');
	add_action('admin_init', 'hejbit_notification');
}

/*
 * API
 * 
 */

function hejbit_all_user_param()
{
	$nameParam = array("hejbit_frequency_dlwcloud", "hejbit_day_dlwcloud", "hejbit_hour_dlwcloud");

	$allParam = array();

	foreach ($nameParam as $param) {
		$allParam[$param] = get_option($param);
	}

	// Checking for an update
	$allParam['NeedUpdate'] = 'noneed';

	// Load admin files if necessary (especially on the front-end)
	if (! function_exists('get_core_updates')) {
		require_once ABSPATH . 'wp-admin/includes/update.php'; // Load the update.php file
	}

	// Check if the site is multisite and retrieve the appropriate updates
	$plugin_updates = is_multisite() ? get_site_transient('update_plugins') : get_transient('update_plugins');
	$theme_updates = is_multisite() ? get_site_transient('update_themes') : get_transient('update_themes');
	$core_updates = get_core_updates(); // Works for both types of installation

	// Check for updates for plugins, themes, or core
	if (! empty($plugin_updates->response) || ! empty($theme_updates->response) || (!empty($core_updates) && isset($core_updates[0]->response) && $core_updates[0]->response === 'upgrade')) {
		$allParam['NeedUpdate'] = 'need';
	}

	return $allParam;
};

add_action('rest_api_init', function () {
	// Create the 'parameter' route in the API
	register_rest_route("HDB", 'param', array(
		'methods' => 'GET',
		'callback' => 'hejbit_all_user_param',
		'permission_callback' => function () {
			return current_user_can('manage_options');
		},
	));
});

function hejbit_get_all_saves()
{

	global $wpdb;

	// Initialize the array to store the results
	$result = array();

	// Execute the query to retrieve all backups
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$allSaves = $wpdb->get_results(
		"SELECT * FROM {$wpdb->prefix}hejbit_saveInProgress"
	);

	return $allSaves;

	// Add the number of backups to the result
	$result["nbSaves"] = count($allSaves);

	// Retrieving the date of the next backup
	$timestamp = wp_next_scheduled('hejbit_Save', array('next'));

	if ($timestamp) {

		$date = gmdate('Y-m-d H:i:s', $timestamp);
		$result["nextSave"] =  $date;
	} else {

		$result["nextSave"] =  "empty";
	};

	// For each backup
	foreach ($allSaves as $save) {

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
add_action('rest_api_init', function () {
	// Create the "parameter" route in the API
	register_rest_route("HDB", 'saves', array(
		// GET Method
		'methods' => 'GET',
		// Call the method 'hejbit_all_user_param'
		'callback' => 'hejbit_get_all_saves',
		'permission_callback' => function () {
			return current_user_can('manage_options');
		},
	));
});

// phpcs:enable WordPress.DB

// Admin page
function hejbit_savetonextcloud_param()
{ ?>
	<script>
		document.addEventListener('DOMContentLoaded', function() {
    	var btn = document.getElementById('hejbit-test-nextcloud');
    	if (btn) {
        	btn.addEventListener('click', function() {
            	var resultDiv = document.getElementById('hejbit-nextcloud-result');
            	resultDiv.innerHTML = 'Testing connection...';
            	fetch(ajaxurl, {
                	method: 'POST',
                	headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                	body: 'action=hejbit_test_nextcloud'
            	})
            	.then(response => response.json())
            	.then(data => {
                	if (data.success) {
    					resultDiv.innerHTML = '✅ Connection established.<br>' +
        					(data.hejbit_folder ? '✅ Hejbit folder exists.' : '❌ Hejbit folder does NOT exist.');
					} else {
    					resultDiv.innerHTML = '❌ Connection failed: ' + data.message;
					}
            	})
            	.catch(() => {
                	resultDiv.innerHTML = '❌ Error testing connection.';
           		});
       		});
    	}
	});
	</script>

	<div class="wrap">
		<h2>HejBit Decentralised Backup</h2>
		<h2>Backup</h2>
		<p>Please fill in your settings</p>
		<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
			<?php
			settings_fields('hejbit_nextcloud-group');
			do_settings_fields('hejbit_nextcloud-group', 'dlwcloud'); ?>

			<table class="form-table">
				<tr valign="top">
					<th scope="row" style="width:350px;">URL ( https://cloud.domain.com )</th>
					<td><input type="text" name="hejbit_url_dlwcloud" value="<?php echo esc_url(get_option('hejbit_url_dlwcloud')); ?>" required /></td>
				</tr>

				<tr valign="top">
					<th scope="row" style="width:350px;">Login</th>
					<td><input type="text" name="hejbit_login_dlwcloud" value="<?php echo esc_html(get_option('hejbit_login_dlwcloud')); ?>" required /></td>
				</tr>

				<tr valign="top">
					<th scope="row" style="width:350px;">Password</th>
					<td><input type="password" name="hejbit_pass_dlwcloud" value="<?php echo esc_html(get_option('hejbit_pass_dlwcloud')); ?>" required />
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" style="width:350px;">Backup Frequency</th>
					<td><select name="hejbit_frequency_dlwcloud">
							<option value="week" <?php if (get_option('hejbit_frequency_dlwcloud') == "week") { ?> selected <?php } ?>>Weekly</option>
							<option value="twicemonth" <?php if (get_option('hejbit_frequency_dlwcloud') == "twicemonth") { ?> selected <?php } ?>>Bi-monthly
							</option>
							<option value="month" <?php if (empty(get_option('hejbit_frequency_dlwcloud')) || get_option('hejbit_frequency_dlwcloud') == "month") {
													?> selected <?php } ?>>Monthly</option>
						</select>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" style="width:350px;">Backup day</th>
					<td><select name="hejbit_day_dlwcloud">
							<option value="Monday" <?php if (empty(get_option('hejbit_day_dlwcloud')) || get_option('hejbit_day_dlwcloud') == "Monday") { ?> selected <?php } ?>>Monday</option>
							<option value="Tuesday" <?php if (get_option('hejbit_day_dlwcloud') == "Tuesday") { ?> selected <?php } ?>>Tuesday</option>
							<option value="Wednesday" <?php if (get_option('hejbit_day_dlwcloud') == "Wednesday") { ?> selected <?php } ?>>Wednesday</option>
							<option value="Thursday" <?php if (get_option('hejbit_day_dlwcloud') == "Thursday") { ?> selected <?php } ?>>Thursday</option>
							<option value="Friday" <?php if (get_option('hejbit_day_dlwcloud') == "Friday") { ?> selected <?php } ?>>Friday</option>
							<option value="Saturday" <?php if (get_option('hejbit_day_dlwcloud') == "Saturday") { ?> selected <?php } ?>>Saturday</option>
							<option value="Sunday" <?php if (get_option('hejbit_day_dlwcloud') == "Sunday") { ?> selected <?php } ?>>Sunday</option>
						</select>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" style="width:350px;">Backup time</th>
					<td><input type="time" name="hejbit_hour_dlwcloud" value="<?php echo esc_html(get_option('hejbit_hour_dlwcloud')); ?>" required /></td>
				</tr>

				<tr valign="top">
					<th scope="row" style="width:350px;">Remote Backup Folder ( /Hejbit/WordpressBackups/ )
						<p>
							Save the schedule first to use this button.
						</p>
					</th>
					<td style="display: flex; align-items: center; gap: 10px;">
						<input type="text" name="hejbit_folder_dlwcloud" value="<?php echo esc_html(get_option('hejbit_folder_dlwcloud', '')); ?>" required />
						<button type="button" id="hejbit-test-nextcloud" class="button">Test Nextcloud Connection</button>
						<div id="hejbit-nextcloud-result" style="margin-left:10px;"></div>
					</td>
				</tr>
			
				<tr valign="top">
					<th scope="row" style="width:350px;">Notification email separated by ;</th>
					<td><input type="text" name="hejbit_email_dlwcloud" value="<?php if (!empty(get_option('hejbit_email_dlwcloud'))) {
																					echo
																					esc_html(get_option('hejbit_email_dlwcloud'));
																				} ?>" required /></td>
				</tr>
			</table>

			<table>
				<tr valign="top">
					<th scope="row"></th>
					<td>
						<details>
							<summary>View advanced settings</summary>
							<table class="form-table">



				</tr>
				<th scope="row" style="width:350px;">Do you want to backup only the database(s)?</th>
				<td><select name="hejbit_db_only_dlwcloud">
						<option value="true" <?php if (get_option('hejbit_db_only_dlwcloud') == "true") { ?> selected <?php } ?>>Yes
						</option>
						<option value="false" <?php if (
													empty(get_option('hejbit_db_only_dlwcloud')) ||
													get_option('hejbit_db_only_dlwcloud') == "false"
												) { ?> selected <?php } ?>>No</option>
					</select>
					</tr>
			</table>

			</details>
			</td>
			</tr>

			</table>
			<?php
			submit_button("Save the schedule"); ?>
		</form>
		<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
			<input type="hidden" name="action" value="saveNow">
			<?php wp_nonce_field('hejbit_save_now_action', 'hejbit_save_now_nonce'); ?>
			<?php submit_button('Make a backup now'); ?>
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
<?php settings_errors('hejbit');
}; 

//Handler AJAX for testing Nextcloud connection (button)
add_action('wp_ajax_hejbit_test_nextcloud', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json(array('success' => false, 'message' => 'Permission denied.'));
    }
    $nc_status = hejbit_save_to_nextcloud::is_NextCloud_good();
    if (!$nc_status) {
        wp_send_json(array('success' => false, 'message' => 'Could not connect to Nextcloud.'));
    }
    $hejbit_folder = hejbit_save_to_nextcloud::is_Folder_hejbit();
    if ($hejbit_folder) {
        wp_send_json(array(
            'success' => true,
            'hejbit_folder' => true
        ));
    } else {
        wp_send_json(array(
            'success' => true,
            'hejbit_folder' => false
        ));
    }
});

// Logs page
function hejbit_logs_page() {
    // Handle log cleanup if requested
	if (
		isset($_POST['clear_old_logs']) &&
		isset($_POST['hejbit_logs_nonce']) &&
		wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hejbit_logs_nonce'])), 'hejbit_clear_logs')
	) {
		$days = isset($_POST['days_to_keep']) ? intval($_POST['days_to_keep']) : 30;
		$deleted = hejbit_save_to_nextcloud::clean_logs($days);
		echo '<div class="notice notice-success"><p>' . sprintf('Deleted %d old log entries.', esc_html($deleted)) . '</p></div>';
	}
    
    // Pagination
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 50;
    $offset = ($page - 1) * $per_page;
    
    // Get logs
    $logs = hejbit_save_to_nextcloud::get_logs(null, $per_page, $offset);
    
    // Get total count for pagination
	global $wpdb;
	// Try to get cached value first
	$cache_key = 'hejbit_total_logs_count';
	$total_logs = wp_cache_get($cache_key, 'hejbit_logs');
	if (false === $total_logs) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_logs = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}hejbit_logs"
		);
		wp_cache_set($cache_key, $total_logs, 'hejbit_logs', 300); // Cache for 5 minutes
	}

	$total_pages = ceil($total_logs / $per_page);
    ?>
    
    <div class="wrap">
        <h1>HejBit Backup Logs</h1>
        
        <div style="margin: 20px 0;">
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('hejbit_clear_logs', 'hejbit_logs_nonce'); ?>
                <label>Keep logs for: <input type="number" name="days_to_keep" value="30" min="1" max="365" style="width: 60px;"> days</label>
                <input type="submit" name="clear_old_logs" class="button" value="Clean Old Logs">
				<input type="button" class="button" value="Refresh" onclick="location.reload();">
            </form>
        </div>

		<div style="margin-bottom: 20px; padding: 10px; background: #f8f8f8; border: 1px solid #eee;">
			<strong>Backup Status Codes:</strong>
			<ul style="margin: 8px 0 0 20px;">
				<li><strong>0</strong>: Start of backup (database export)</li>
				<li><strong>1</strong>: ZIP creation (zipping files)</li>
				<li><strong>2</strong>: Merge ZIP (merging backup files)</li>
				<li><strong>3</strong>: Send chunks to NextCloud (uploading backup)</li>
				<li><strong>4</strong>: Merge chunks on NextCloud (finalizing backup)</li>
			</ul>
		</div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Timestamp</th>
                    <th style="width: 80px;">Level</th>
                    <th style="width: 100px;">Step</th>
                    <th>Message</th>
                    <th style="width: 150px;">Backup</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5">No logs found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr class="log-<?php echo esc_attr(strtolower($log->log_level)); ?>">
                            <td><?php echo esc_html($log->timestamp); ?></td>
                            <td>
                                <span style="
                                    padding: 2px 8px;
                                    border-radius: 3px;
                                    color: white;
                                    background-color: <?php 
                                        echo $log->log_level === 'ERROR' ? '#dc3232' : 
                                            ($log->log_level === 'WARNING' ? '#ffb900' : 
                                            ($log->log_level === 'SUCCESS' ? '#46b450' : '#0073aa')); 
                                    ?>;">
                                    <?php echo esc_html($log->log_level); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->step ?: '-'); ?></td>
                            <td>
                                <?php echo esc_html($log->message); ?>
                                <?php if ($log->details): ?>
                                    <?php $details = json_decode($log->details, true); ?>
                                    <?php if ($details): ?>
                                        <div style="margin-top: 5px; font-size: 12px; color: #666;">
                                            <?php foreach ($details as $key => $value): ?>
                                                <strong><?php echo esc_html($key); ?>:</strong> <?php echo esc_html($value); ?><br>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($log->backup_name ?: 'Current'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
					$pagination_links = paginate_links(array(
						'base' => add_query_arg('paged', '%#%'),
						'format' => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total' => $total_pages,
						'current' => $page
					));
					if ($pagination_links) {
						echo wp_kses_post($pagination_links);
					}
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <style>
        .log-error { background-color: #ffebe8 !important; }
        .log-warning { background-color: #fff8e5 !important; }
        .log-success { background-color: #edfaef !important; }
    </style>
    <?php
}
?>