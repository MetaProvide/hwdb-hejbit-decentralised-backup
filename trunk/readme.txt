=== HejBit Decentralised Backup ===

Contributors: joaosraposo, app.hejbit.com , metaprovide.org
Tested up to: 6.8
Stable tag: 1.0.8
Requires PHP: 7.3
License: AGPLv3
License URI: https://www.gnu.org/licenses/agpl-3.0.html
Tags: nextcloud, webdav, backup, decentralised, swarm

Securely back up your WordPress site to decentralized storage via Nextcloud and HejBit.

== Description ==

This plugin is a simple and effective tool to backup your WordPress site and its database, in a decentralized manner, directly to your Hejbit Folder on Nextcloud account. With it, you can define the parameters to create regular copies of your site, including the frequency and time of the operation.

Once you have configured the parameters, the plugin will automatically run in the background to create backups of your site and its database according to your schedule. Backups are stored directly on your Nextcloud account, allowing you to access them from anywhere, at any time.

This plugin is easy to install and configure, and it offers a complete backup solution to protect your site from data loss in the event of a technical problem or hacking. By choosing this plugin, you can have peace of mind knowing that your site is safe.

WARNING: Automatic restoration of backups is not yet possible. It must be done manually by replacing the files on the hosting and restoring the database(DB).

**New in version 1.0.7:** 
- Comprehensive logging system accessible via HejBit Decentralised Backup > Logs submenu to monitor all backup operations
- Test NextCloud Connection button to verify your setup before running backups

== Required Third-Party Services == 

* Nextcloud: A cloud storage platform.
* HejBit: A decentralization service that connects your Nextcloud folder to Swarm decentralized storage.

To use this plugin, you need:
1. An active Nextcloud instance
2. A HejBit account (sign up at [app.hejbit.com](https://app.hejbit.com))

== Need Help with Nextcloud Setup? ==

Don't have a Nextcloud instance yet? No problem! We can help you get started with decentralized backups.

**We offer three options:**

1. **Shared Nextcloud Instance**: Quick access to one of Metaprovide's existing Nextcloud instances
2. **Custom Setup**: We'll set up a dedicated Nextcloud instance for you to have
3. **Consultation**: Contact us to discuss these options further and communicate any questions you may have before making a decision

**To request assistance:**

We typically respond within 24-48 hours and will guide you through the entire setup process to get your decentralized backups running smoothly.

Send us an email with the following details:

**Subject:** Nextcloud Setup Request for HejBit Decentralised Backup

**Message Template:**
Hello HejBit Support Team,

I'm interested in using the HejBit Decentralised Backup plugin for WordPress. I need some assistance with the setup for Nextcloud. 

Please help with one of these three options:
[ ] Quick access to one of Metaprovide's existing Nextcloud instances.
[ ] Setting up a dedicated Nextcloud instance for me.
[ ] Contact me to discuss these options further, and communicate any questions I may have before making a decision.

My details:
- Website URL: [Your WordPress site URL]
- Expected backup size: [Approximate size of your site]
- Preferred backup frequency: [Daily/Weekly/Monthly]
- Technical experience level: [Beginner/Intermediate/Advanced]

Additional comments or questions:
[Any specific requirements or questions you have]

Look forward to hear back from you.

Best regards,
[Your Name]
[Your Contact Information]


== Installation ==

= Installation from within WordPress =

1. Visit **Plugins > Add New**.
2. Search for **HejBit Decentralised Backup**.
3. Install and activate the HejBit Decentralised Backup plugin.

= Manual installation =

1. Upload the entire `hdb-hejbit-decentralised-backup` folder to the `/wp-content/plugins/` directory.
2. Visit **Plugins**.
3. Activate the HejBit Decentralised Backup plugin.

= Configure the settings =

Configure the settings in the menu **HejBit Decentralised Backup > Backup**.

The "Save schedule" button schedules your next backup.
The "Make a backup now" button starts the backup

Advanced options:
- Set the number of backups to keep (up to 10)
- Enable automatic update blocking: the core, plugins and themes being tagged to be updated automatically, will only be updated after a scheduled backup in order to prevent it from being polluted by a faulty plugin. Manual updates are still possible.

= Testing Your Connection =

Before running your first backup, fill in all the fields, click **Save Schedule**, and use the **Test NextCloud Connection** button next to the Remote Backup Folder field to verify:
- Your NextCloud credentials and URL are correct
- The connection to your NextCloud instance is established
- Your specified folder is a valid HejBit folder

The test will show:
- ✅ Connection established - if NextCloud connection is successful
- ✅ Hejbit folder exists - if the folder is properly configured for HejBit
- ❌ Connection failed: Could not connect to Nextcloud - if the URL or credentials are incorrect
- ❌ Hejbit folder does NOT exist - if the Remote Backup Folder path is invalid, missing, or a regular folder.

= Monitoring Backups =

The plugin includes a comprehensive logging system accessible via **HejBit Decentralised Backup > Logs**:

**Log Features:**
- Real-time backup progress tracking
- Color-coded log levels: INFO (blue), SUCCESS (green), WARNING (yellow), ERROR (red)
- Detailed status codes showing backup stages:
  * 0: Database export
  * 1: ZIP creation (file compression)
  * 2: ZIP merging
  * 3: Upload to NextCloud (chunk sending)
  * 4: Finalizing backup on NextCloud
- Timestamp for each operation
- Associated backup names for easy identification

**Log Management:**
- Clean old logs by specifying retention period (default: 30 days)
- Refresh button to update log display
- Pagination for easy navigation through historical logs

This feature helps you:
- Monitor backup progress in real-time
- Quickly identify and troubleshoot any backup failures
- Maintain a history of all backup operations
- Ensure your backups are completing successfully

== Server Configuration for Large Backups ==
To ensure successful backup uploads for websites of all sizes, especially large sites, you need adjust your server’s PHP, Nginx, and Nextcloud configuration as follows:

**1. PHP configuration (`php.ini`):**
Consider increasing limits related to file size and execution time.
Here’s an example configuration that allowed large uploads to complete successfully:
- memory_limit = 5G 
- post_max_size = 5G 
- upload_max_filesize = 5G 
- max_execution_time = 7200 
- max_input_time = 7200
(Use values appropriate for your environment)

**2. Nginx configuration:**
Nginx uses the client_max_body_size directive to control the maximum allowed size of an upload.
For example, to support uploads of several gigabytes, you might include a line like this in your nginx.conf (inside the http, server, or location block):
- client_max_body_size = 5G;
(Adjust the size limit to match your PHP and Nextcloud settings.)

**3. Nextcloud configuration (config.php):**
You may also want to review Nextcloud’s upload-related settings.
Here’s an example:
- 'uploadtimeout' => 7200,        // maximum time (in seconds) allowed for uploads
- 'max_chunk_size' => 10485760,   // chunk size (in bytes); 10MB is a good balance
(These are reference values — feel free to adjust them as needed.)

Note:
These settings are essential for handling large backup files and preventing timeouts or upload failures.
If you are on shared hosting, you may need to contact your hosting provider to increase these limits.

= Process Duration =

Several steps are required, and each step depends on the maximum memory allocated by your hosting. As a result, the process may take a long time (e.g., a few hours for a 10GB site with 64MB of memory).

= Backup File Limit =

Currently, the maximum limit for saving the backup ZIP file in Swarm is around 1GB.

= Backup Process =

1. DB extraction
2. Zip of the wp-content folder
3. Addition of the .htaccess, wp-config and DB files to the Zip
4. Sending the zip following the Chunck method on the Nextcloud space
5. Reassembling the chunks and moving them to the final Nextcloud folder
6. Cleaning of residual files on the hosting and deletion of obsolete backups on Nextcloud

= API =

Two access points are available:

* /wp-json/HDB/saves to retrieve the number of active backups and their name, as well as the next backup
* /wp-json/HDB/param to retrieve the backup frequency, the day, the time and the number to keep

= Uninstalling the Plugin =

* Disable the plugin: delete the schedules
* Delete the plugin : remove tables and options

== Data Handling ==

* This plugin sends backup files (database and files) from your WordPress installation to your Nextcloud instance.
* The backup data is then stored on Swarm decentralized storage via the HejBit integration.
* No user tracking or personal data collection occurs.

== Privacy & Terms==
* [HejBit Privacy Policy](https://app.hejbit.com/legal/privacy-policy)
* [HejBit Terms of Service](https://app.hejbit.com/legal/terms-of-service)
* Nextcloud is self-hosted by you or by other provider, so please refer to your instance's policies.

A free 14-day trial of HejBit is available for new users.

== Frequently Asked Questions ==

= Do I need both Nextcloud and HejBit? =

Yes, this plugin requires both a Nextcloud instance and a HejBit account to function properly.

= How long is the HejBit trial? =

HejBit offers a 14-day free trial for new users to test the decentralized storage capabilities.

== Links ==

* [HejBit App](https://app.hejbit.com/)

== Acknowledgements ==

This plugin would not have been possible without the solid foundation laid by Dave Delalleau. His work on the Save to Nextcloud plugin (https://wordpress.org/plugins/stn-save-to-Nextcloud/#developers) has been instrumental in shaping this project. We deeply appreciate his contributions and effort in making website backups more accessible and reliable. Thank you, Dave, for your dedication and hard work!

We would also like to acknowledge the Nextcloud community for their continued efforts in building a robust, open-source platform for file storage and collaboration. Their commitment to privacy, security, and decentralization has made it possible for projects like this to thrive. The flexibility and reliability of Nextcloud ensure that users have full control over their data, making it an ideal solution for secure backups.

== Known Bugs ==

Feature:"Make a Backup Now" Button Doesn't Work
Description: Clicking "Make a Backup Now" causes the fields to go empty, and the backup does not work.
Temporary Solution: The "Make a Backup Now" button should only be clicked after entering the data and saving them with "Save the Schedule".

Feature: Field Changes Not Recognized
Description: If you fill in any fields and click "Save the Schedule", but then modify a field afterward, the system does not recognize the new values. 
Temporary Solution: The user must always click "Save the Schedule" after any field modification.

Feature: Unable to Schedule Backup for the Current Day
Description: It is currently not possible to schedule a backup for the same day.
Temporary Solution: If you need to perform a backup immediately, please click on "Make a Backup Now."