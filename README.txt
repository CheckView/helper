=== CheckView Automated Testing ===
Contributors: checkview, inspry, muhammadfaizanhaidar
Donate link: https://checkview.io/
Tags: testing, monitoring, uptime, tests, woocommerce
Requires at least: 5.0.1
Tested up to: 6.8
Requires PHP: 7.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Stable tag: 2.0.21

[CheckView](https://checkview.io/) is the friendly WordPress automated testing platform for everyone, from developers, shop owners to agencies.  

== Description ==

[CheckView](https://checkview.io/) is the leading fully automated testing platform  to swiftly identify and resolve any issues with your WordPress forms and WooCommerce checkout. Enjoy the peace of mind that comes with knowing your WordPress site is working, ensuring you never lose sales due to site issues.

== Important: == 

This plugin is for users with a Checkview.io account.  The plugin will still activate, but requires the [CheckView.io](https://checkview.io/) service to function properly.

= Comprehensive Testing: =

   * Utilize real browser testing on your site's forms and Woo checkout processes, ensuring you do not lose sales from broken forms or checkouts.  WooCommerce testing can use our automated test products or your selected real products.  Forms are verified from submission to email notifications and checkout covers product pages, adding products to the cart along with cart and checkout functionality.

= Automated Scheduling: =

   * Customize test schedules to fit your needs and stay informed with notifications through various channels, including detailed video recordings of tests and pinpointing where and why failures occur.

= No Code, One-Click Integration: =

   * Effortlessly connect CheckView to your WordPress or WooCommerce with a single click with no Chrome extensions to install, Github repos to setup, or coding skills required.  Save time and money by replacing tedious manual testing of your WordPress forms or WooCommerce checkout with an automated, comprehensive process that runs in the background.

= Customizable Test Flows: =

   * Tailor your testing to match the specifics of your WordPress form plugin or WooCommerce setup, including what URLs to test and custom theme adjustments using our built in test flow step editor.

= Perfect for Multiple Websites or Agencies: =


   * CheckView can be ran across multiple websites and their associated forms with ease.  Manage all of your site's statuses and notifications within one easy to use dashboard.  Provide additional value to your clients and complement your existing uptime monitoring.

= Privacy-Focused: =

   * After each test, CheckView ensures any collected data is immediately  purged, preserving the integrity of your form submissions and order data.

= Account and Pricing: =

   * Currently in beta, CheckView invites agencies and website owners to join for free with detailed pricing to be announced soon.

    Requires a separate account at [CheckView.io](https://checkview.io/) for platform access.

= Data Sharing Commitment: = 

   *  Upon integrating your account, CheckView is dedicated to handling the  following data with the highest standards of privacy and security:

       -  General WordPress installation details and plugin inventory.

       -  Metadata for orders, carts, and forms, ensuring no personal data is compromised.

       -  Product names and images, used solely for enhancing checkout testing accuracy.

Embrace a new standard of WordPress testing with CheckView, where cutting-edge technology meets user-friendly WordPress integration.

== Installation ==

1. Upload the checkview plugin into the /wp-content/plugins/ folder on your site.

2. Use the 'Plugins' section in WordPress to enable the plugin.

3. That's all for setup! Return to CheckView.io to proceed with adding your website to the platform.

== Frequently Asked Questions ==

= Is a CheckView account required to utilize this plugin? =

Yes, creating a [CheckView.io](https://checkview.io/) account is required - for further details, [please visit this link](https://checkview.io/) to join our beta. Although the plugin can be installed without the service, automated testing functionalities will not be accessible without linking a [CheckView.io](https://checkview.io/) account.

== Screenshots ==

1. CheckView test flow.

2. CheckView test flow results.

3. CheckView general settings.

== Changelog ==
= 2.0.21 =
* Handle errors when setting up logs folder.
* New filter that allows modification of the site URL used in JWT authentication.

= 2.0.20 =
* Only run testing code for requests with newly required cookie.
* Only run relevant test code for the specified test type in the newly required cookie.
* Add check for WS Forms before attempting to run code.
* Better handle page ID retrieval.
* Extract all code from I18n class into a single function.
* Extract all code from Public class into a single function.
* Make helper methods of Woo class static.
* Improve logging.

= 2.0.19 =
* Improve logging.
* Add hooks for disabling WP Forms CAPTCHAs/Turnstiles for tests.
* Disable test-related hooks on API requests.
* Disable Fluent Forms token-based spam prevention for tests.
* Remove Fluent Forms CAPTCHA/Turnstile key swap.
* Disable Fluent Forms CAPTCHAs using newly provided hook.

= 2.0.18 =
* Improve logging when querying for available test results.
* Increase JWT leeway.
* Remove unreachable/unnecessary code.
* Retrieve WooCommerce order IDs via method instead of protected property.

= 2.0.17 =
* Add polyfill for array_find function.

= 2.0.16 =
* Check status of CleanTalk token before attempting API requests.
* Separate transients for CleanTalk Antispam and CleanTalk Spam Firewall IPs.
* Improve validation and handling of data from CleanTalk requests.
* Utilize CleanTalk service ID for requests, greatly reducing CleanTalk API response times.
* Lower CleanTalk API timeout errors.
* Correct record types in CleanTalk POST requests.
* Loosen checks for IPs/domains in CleanTalk responses.
* Improve logging.

= 2.0.15 =
* Fixed an issue where CleanTalk requests were not properly being requested and cached.
* Errored CleanTalk API requests now return an empty array instead of null.

= 2.0.14 =
* Fixed an issue where CleanTalk requests were not properly being requested and cached.

= 2.0.13 =
* Added SaaS IP addresses to the CleanTalk Firewall.
* Removed empty files from the plugin.
* Added CheckView hidden mode.
* Removed conflict with WPML during authentications.
* Added method to delete WooCommerce test orders when cronjobs are disabled.
* Added robustness to the logs.
* Added compatibility check with WordPress 6.8.

= 2.0.12 =
* Added Google reCAPTCHA bypass for reCaptcha Integration for WooCommerce by I13 Web Solution.
* Added Google reCAPTCHA bypass for Google reCaptcha for WooCommerce by KoalaApps.
* Added Cloudflare Turnstile bypass for Enhanced Cloudflare Turnstile for WooCommerce by Press75.
* Whitelisted SaaS IP in WordFence.
* Whitelisted SaaS IP in All in One Security.
* Platform Filter bypass in SolidWP.
* Platform Filter bypass in Defender Pro.
* Fixed CheckView email diversion issues for Formidable Forms and WPForms.
* Suppressed Custom Captcha field for WPForms.
* Added Stripe test mode options for Gravity Forms.

= 2.0.11 =
* Resolved PHP errors related to the undefined wpforms() function.
* Removed IP address check from WooCommerce API-based helper endpoints.
* Corrected automated testing email handling in unit test cases.
* Resolved undefined index error in Formidable Forms by adding compatibility for repeater conditional fields.
* Enhanced formslist endpoint to properly handle empty form lists.

= 2.0.10 =
* Enhanced Cloudflare Turnstile integration with Fluent Forms by leveraging cron to prevent premature deactivation of test keys.
*  Fixed an issue with websites using Fluent Forms who upgraded from Google reCAPTCHA v2 to v3 encountered invalid key errors.

= 2.0.9 =
* **Urgent Bug Fix**: Addressed a critical, but intermittent issue preventing WooCommerce transactional emails from sending during automated test execution. This occurred regardless of whether the tests were directly related to WooCommerce, as long as WooCommerce was active on the site.

= 2.0.8 =   
* Added Recaptcha V2 bypass in FluentForms.  
* Updated the init hook to re-enable Recaptcha V2 and Cloudflare Turnstile after test completion.  
* Added suppression of BCC and CC fields in all forms.  
* Updated IP address check function to adapt to different server settings.    
* Updated log saving and retrieval methods.  
* Added a new endpoint to share helper logs.  
* Added function to delete logs after 7 days.    
* Delayed checkview entry deletion in WS Form.  
* Appended test ID to test emails for SaaS notifications.   
* Resolved warnings in Formidable Forms and GravityForms.  
* Resolved WSF entry deletion issues by delaying entry deletion.  
* Whitelisted SaaS IP addresses in WordFence, All in One Security, SolidWP, and Defender Pro.

= 2.0.7 =
* Updated IP address check function to adapt to different server configurations.

= 2.0.6 = 
* Added fix for WooCommerce order deletion global scope issue.

= 2.0.5 =
* Added Honeypot bypass for FluentForms.
* Resolved CleanTalk fixes.
* Exposed helper logs to SaaS via API endpoint.
* Added Bcc and CC email suppressions FluentForms.
* Added Flamingo Cf7 support.

= 2.0.4 =
* Added nonce table creation function in token verification process.

= 2.0.3 =
* Added upgrade.php dependencies in upgrader hook.
* Updated CF7 hooks priority.

= 2.0.2 =
* Resolved updater hook bug by adding global $wpdb variable.

= 2.0.1 =

* **New Integration**: Added support for WS Form.
* **Updates**:
  * Updated Contact Form 7 (CF7) hooks priority for better compatibility.
* **Stability Improvements**:
  * Enhanced plugin stability by shifting updates to the update hook.
* **Code Quality**:
  * Improved cognitive complexity by restructuring code for better readability and maintainability.
* **Performance Enhancements**:
  * Reduced database queries for faster load times.
  * Added caching mechanisms to enhance performance.
  * Added limit for WooCommerce Products to first 1000 by date modified.
  * Updated form delete endpoint to store results for 7 days.
* **New Features**:
  * Updated CleanTalk whitelisted IP addresses function to accumulate IPs across all sites.
  * Added functionality to enable or disable admin email notifications for all form submissions and WooCommerce orders made by SaaS.

= 2.0.0 =

* Resolved token validation issues (SaaS was bypassing without token for forms list endpoint).

* Resolved IP address validation issues (SaaS was not able to bypass even with valid IP address).

* Added data validations (IP address and test ID validations).

* Added API validations (Added extra layer of security with nonce addition).

* Added SaaS IP address validation.

* Added SaaS nonce token validation in all API endpoints.

* Added SSL checks for all API calls.

* Added new database table for storing used nonces.

* Added cron job to delete expired nonces.

* Added logs for API and internal functions.

* Resolved hCaptcha missing fields error after NinjaForms latest updates.

* Added endpoint to expose version changes for installed plugins.

* Resolved Recaptcha validation error in WPForms.

* Resolved Cloudflare Turnstile validation errors in FluentForms.

* Updated container IP addresses.

= 1.1.22 =
* Added a patch to ensure the Contact Form 7 module loads during AJAX requests.
* Resolved CAPTCHA errors for Contact Form 7.
* Updated the checkview_get_api_ip() function to address CleanTalk and hCaptcha bypass issues for SaaS IP addresses.

= 1.1.21 =
* Added a patch for CleanTalk whitelisting for SaaS IP addresses.
* Added a patch to divert Ninja Forms BCC and CC for admin notifications.
* Updated the priority of the checkview_get_visitor_ip() function definition to resolve the undefined function issue.
* Skipped CleanTalk deactivation function to avoid whitelisting failure. 
* Added cache to store list of whitelisted SaaS IP addresses.

= 1.1.20 =
* Updated endpoint url to retrieve the whitelisted IP's for CheckView SaaS.
* Updated SaaS IP address checks.

= 1.1.19 =
* Added internal logs to track API calls for delete endpoint.
* Resolved Cloudflare Turnstile bypass in WPForms latest updates.
* Added cron to delete CheckView test results after 7 days.

= 1.1.18 =
* Added internal logs to track IP address bypass.
* Updated IP validation checks.
* Added hCaptcha whitelisting for SaaS IP address.
* Added functions to handle parallel sessions for all forms.
* Added functions to handle parallel sessions for WooCommerce.

= 1.1.17 =
* Removed wp_die from IP address validations.
* Added internal logs to track IP address bypass.

= 1.1.16 =
* Added hCaptcha bypass in Ninja Forms.
* Updated SaaS public key address.
* Added wpdb->prepare compatibility across all direct database calls.
* Implemented Content Security Policy to avoid Cross-Site Scripting (XSS) Vulnerability.
* Added validations for SaaS IP addresses.
* Added checks to avoid default product duplications.
* Added auto restore from trash feature for the default product.

= 1.1.15 =
* Added filter for invalid URLs in CF7 and Ninja Forms.
* Added new endpoint to pull additional site info.
* Updated general functions to include CheckView slug to avoid conflicts.
* Added GitHub workflows for all forms (except Gravity Forms) and WooCommerce.
* Added hCaptcha bypass in Ninja Forms.
* Removed admin menu title settings from CheckView settings.
* Added function to bypass Gravity Forms reCaptcha addon.
* Added 2 new constants CHECKVIEW_URI & CHECKVIEW_EMAIL.
* Updated CheckView info email with CHECKVIEW_EMAIL constant.
* Updated php unit test cases with CHECKVIEW_EMAIL constant.
* Updated noindex bot check for CheckView default product to work for all SEO plugins.

= 1.1.14 =
* Added hCaptcha spam bypass in all forms.
* Added Google Recaptcha V3 bypass in Gravity Forms.
* Added hCaptcha spam bypass in WooCommerce checkout.
* Added Google Recaptcha V3 bypass in Ninja Forms (addon based).

= 1.1.13 =
* Added spam check bypass in all forms and WooCommerce.

= 1.1.12 =
* Added Google Recaptcha, hCaptcha, and Cloudflare Turnstile bypass in Gravity Forms.
* Added Google Recaptcha V3 bypass in FluentForms.
* Updated CheckView email address to divert admin notifications across all forms.
* Added Cloudflare Turnstile bypass in WooCommerce checkout.

= 1.1.11 =
* Added compatibility with FluentForms 5.1.19.
* Updated email filter hook for admin email notification of FluentForms.
* Updated email action hook for form submission of FluentForms.

= 1.1.10 =
* Resolved Cloudflare Turnstile bypass error with WPForms.
* Resolved Google Recaptcha bypass error with FluentForms.

= 1.1.9 =
* Added dimensions for Test Product.

= 1.1.8 =
* Resolved token validation issue.

= 1.1.7 =
* Added unit tests.
* Resolved PHP memory limit issue while retrieving orders.
* Updated single order endpoint to accept all requests.

= 1.1.6 =
* Declared compatibility with WooCommerce HPOS.
* Updated plugin's name.
* Updated payment gateway name.

= 1.1.5 =
* Resolved WooCommerce admin emails disabling issues.
* Updated dependencies area.
* Updated default emails sending address for WooCommerce emails to [CheckView](https://checkview.io/).
* Added POST call support to delete order endpoint.
* Added order ID support to delete order endpoint.

= 1.1.4 =
* Re-introduced `registerformtest` endpoint.
* Updated default emails sending address for [CheckView](https://checkview.io/).

= 1.1.3 =
* Removed Cache from `getformresults` endpoint.
* Updated testing product name.
* Added auto cache refresh on plugin's update.

= 1.1.2 =
* Added conditions so WooCommerce Automated Testing feature does not load in the absence of WooCommerce.

= 1.1.1 =
* Added conditions so WooCommerce Automated Testing feature does not load in the absence of WooCommerce.

= 1.1.0 =
* Added WooCommerce Automated Testing feature.
* Shifted WooCommerce Automated Testing functions from `functions.php` to class.
* Updated stock prevention feature.
* Updated default testing product name.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==
= 2.0.21 =
* Handle errors when setting up logs folder.
* New filter that allows modification of the site URL used in JWT authentication.

= 2.0.20 =
* Only run testing code for requests with newly required cookie.
* Only run relevant test code for the specified test type in the newly required cookie.
* Add check for WS Forms before attempting to run code.
* Better handle page ID retrieval.
* Extract all code from I18n class into a single function.
* Extract all code from Public class into a single function.
* Make helper methods of Woo class static.
* Improve logging.

= 2.0.19 =
* Improve logging
* Add hooks for disabling WP Forms CAPTCHAs/Turnstiles for tests
* Disable test-related hooks on API requests
* Disable Fluent Forms token-based spam prevention for tests
* Remove Fluent Forms CAPTCHA/Turnstile key swap
* Disable Fluent Forms CAPTCHAs using newly provided hook

= 2.0.18 =
* Improve logging when querying for available test results.
* Increase JWT leeway.
* Remove unreachable/unnecessary code.
* Retrieve WooCommerce order IDs via method instead of protected property.

= 2.0.17 =
* Add polyfill for array_find function.

= 2.0.16 =
* Check status of CleanTalk token before attempting API requests.
* Separate transients for CleanTalk Antispam and CleanTalk Spam Firewall IPs.
* Improve validation and handling of data from CleanTalk requests.
* Utilize CleanTalk service ID for requests, greatly reducing CleanTalk API response times.
* Lower CleanTalk API timeout errors.
* Correct record types in CleanTalk POST requests.
* Loosen checks for IPs/domains in CleanTalk responses.
* Improve logging.

= 2.0.15 =
* Fixed an issue where CleanTalk requests were not properly being requested and cached.
* Errored CleanTalk API requests now return an empty array instead of null.

= 2.0.14 =
* Fixed an issue where CleanTalk requests were not properly being requested and cached.

= 2.0.13 =
* Added SaaS IP addresses to the CleanTalk Firewall.
* Removed empty files from the plugin.
* Added CheckView hidden mode.
* Removed conflict with WPML during authentications.
* Added method to delete WooCommerce test orders when cronjobs are disabled.
* Added robustness to the logs.
* Added compatibility check with WordPress 6.8.

= 2.0.12 =
* Added Google reCAPTCHA bypass for reCaptcha Integration for WooCommerce by I13 Web Solution.

* Added Google reCAPTCHA bypass for Google reCaptcha for WooCommerce by KoalaApps.

* Added Cloudflare Turnstile bypass for Enhanced Cloudflare Turnstile for WooCommerce by Press75.

* Whitelisted SaaS IP in WordFence.

* Whitelisted SaaS IP in All in One Security.

* Platform Filter bypass in SolidWP.

* Platform Filter bypass in Defender Pro.

* Fixed CheckView email diversion issues for FormidableForms and WPForms.

* Suppressed Custom Captcha field for WPForms.

* Added Stripe test mode options for Gravity Forms.

= 2.0.11 =
* Resolved PHP errors related to the undefined wpforms() function.
* Removed IP address check from WooCommerce API-based helper endpoints.
* Corrected automated testing email handling in unit test cases.
* Resolved undefined index error in Formidable Forms by adding compatibility for repeater conditional fields.
* Enhanced formslist endpoint to properly handle empty form lists.

= 2.0.10 =
* Enhanced Cloudflare Turnstile integration with Fluent Forms by leveraging cron to prevent premature deactivation of test keys.
*  Fixed an issue with websites using Fluent Forms who upgraded from Google reCAPTCHA v2 to v3 encountered invalid key errors.

= 2.0.9 =
* **Urgent Bug Fix**: Addressed a critical, but intermittent issue preventing WooCommerce transactional emails from sending during automated test execution. This occurred regardless of whether the tests were directly related to WooCommerce, as long as WooCommerce was active on the site.

= 2.0.8 =   
* Added Recaptcha V2 bypass in FluentForms.  
* Updated the init hook to re-enable Recaptcha V2 and Cloudflare Turnstile after test completion.  
* Added suppression of BCC and CC fields in all forms.  
* Updated IP address check function to adapt to different server settings.    
* Updated log saving and retrieval methods.  
* Added a new endpoint to share helper logs.  
* Added function to delete logs after 7 days.    
* Delayed checkview entry deletion in WS Form.  
* Appended test ID to test emails for SaaS notifications.   
* Resolved warnings in Formidable Forms and GravityForms.  
* Resolved WSF entry deletion issues by delaying entry deletion.  
* Whitelisted SaaS IP addresses in WordFence, All in One Security, SolidWP, and Defender Pro.


= 2.0.7 =
* Updated IP address check function to adapt to different server configurations.


= 2.0.6 = 
* Added fix for WooCommerce order deletion global scope issue.

= 2.0.5 =
* Added Honeypot bypass for FluentForms.
* Resolved CleanTalk fixes.
* Exposed helper logs to SaaS via API endpoint.
* Added Bcc and CC email suppressions FluentForms.
* Added Flamingo Cf7 support.

= 2.0.4 =
* Added nonce table creation function in token verification process.

= 2.0.3 =
* Added upgrade.php dependencies in upgrader hook.
* Updated CF7 hooks priority.

= 2.0.2 =
* Resolved updater hook bug by adding global $wpdb variable.

= 2.0.1 =

* **New Integration**: Added support for WS Form.
* **Updates**:
  * Updated Contact Form 7 (CF7) hooks priority for better compatibility.
* **Stability Improvements**:
  * Enhanced plugin stability by shifting updates to the update hook.
* **Code Quality**:
  * Improved cognitive complexity by restructuring code for better readability and maintainability.
* **Performance Enhancements**:
  * Reduced database queries for faster load times.
  * Added caching mechanisms to enhance performance.
  * Added limit for WooCommerce Products to first 1000 by date modified.
  * Updated form delete endpoint to store results for 7 days.
* **New Features**:
  * Updated CleanTalk whitelisted IP addresses function to accumulate IPs across all sites.
  * Added functionality to enable or disable admin email notifications for all form submissions and WooCommerce orders made by SaaS.

= 2.0.0 =

* Resolved token validation issues (SaaS was bypassing without token for forms list endpoint).

* Resolved IP address validation issues (SaaS was not able to bypass even with valid IP address).

* Added data validations (IP address and test ID validations).

* Added API validations (Added extra layer of security with nonce addition).

* Added SaaS IP address validation.

* Added SaaS nonce token validation in all API endpoints.

* Added SSL checks for all API calls.

* Added new database table for storing used nonces.

* Added cron job to delete expired nonces.

* Added logs for API and internal functions.

* Resolved hCaptcha missing fields error after NinjaForms latest updates.

* Added endpoint to expose version changes for installed plugins.

* Resolved Recaptcha validation error in WPForms.

* Resolved Cloudflare Turnstile validation errors in FluentForms.

* Updated container IP addresses.

= 1.1.22 =
* Added a patch to ensure the Contact Form 7 module loads during AJAX requests.
* Resolved CAPTCHA errors for Contact Form 7.
* Updated the checkview_get_api_ip() function to address CleanTalk and hCaptcha bypass issues for SaaS IP addresses.

= 1.1.21 =
* Added a patch for CleanTalk whitelisting for SaaS IP addresses.
* Added a patch to divert Ninja Forms BCC and CC for admin notifications.
* Updated the priority of the checkview_get_visitor_ip() function definition to resolve the undefined function issue.
* Skipped CleanTalk deactivation function to avoid whitelisting failure. 
* Added cache to store list of whitelisted SaaS IP addresses.

= 1.1.20 =
* Updated endpoint url to retrieve the whitelisted IP's for CheckView SaaS.
* Updated SaaS IP address checks.

= 1.1.19 =
* Added internal logs to track API calls for delete endpoint.
* Resolved Cloudflare Turnstile bypass in WPForms latest updates.
* Added cron to delete CheckView test results after 7 days.

= 1.1.18 =
* Added internal logs to track IP address bypass.
* Updated IP validation checks.
* Added hCaptcha whitelisting for SaaS IP address.
* Added functions to handle parallel sessions for all forms.
* Added functions to handle parallel sessions for WooCommerce.

= 1.1.17 =
* Removed wp_die from IP address validations.
* Added internal logs to track IP address bypass.

= 1.1.16 =
* Added hCaptcha bypass in Ninja Forms.
* Updated SaaS public key address.
* Added wpdb->prepare compatibility across all direct database calls.
* Implemented Content Security Policy to avoid Cross-Site Scripting (XSS) Vulnerability.
* Added validations for SaaS IP addresses.
* Added checks to avoid default product duplications.
* Added auto restore from trash feature for the default product.

= 1.1.15 =
* Added filter for invalid URLs in CF7 and Ninja Forms.
* Added new endpoint to pull additional site info.
* Updated general functions to include CheckView slug to avoid conflicts.
* Added GitHub workflows for all forms (except Gravity Forms) and WooCommerce.
* Added hCaptcha bypass in Ninja Forms.
* Removed admin menu title settings from CheckView settings.
* Added function to bypass Gravity Forms reCaptcha addon.
* Added 2 new constants CHECKVIEW_URI & CHECKVIEW_EMAIL.
* Updated CheckView info email with CHECKVIEW_EMAIL constant.
* Updated php unit test cases with CHECKVIEW_EMAIL constant.
* Updated noindex bot check for CheckView default product to work for all SEO plugins.

= 1.1.14 =
* Added hCaptcha spam bypass in all forms.
* Added Google Recaptcha V3 bypass in Gravity Forms.
* Added hCaptcha spam bypass in WooCommerce checkout.
* Added Google Recaptcha V3 bypass in Ninja Forms (addon based).

= 1.1.13 =
* Added spam check bypass in all forms and WooCommerce.

= 1.1.12 =
* Added Google Recaptcha, hCaptcha, and Cloudflare Turnstile bypass in Gravity Forms.
* Added Google Recaptcha V3 bypass in FluentForms.
* Updated CheckView email address to divert admin notifications across all forms.
* Added Cloudflare Turnstile bypass in WooCommerce checkout.

= 1.1.11 =
* Added compatibility with FluentForms 5.1.19.
* Updated email filter hook for admin email notification of FluentForms.
* Updated email action hook for form submission of FluentForms.

= 1.1.10 =
* Resolved Cloudflare Turnstile bypass error with WPForms.
* Resolved Google Recaptcha bypass error with FluentForms.

= 1.1.9 =
* Added dimensions for Test Product.

= 1.1.8 =
* Resolved token validation issue.

= 1.1.7 =
* Added unit tests.
* Resolved PHP memory limit issue while retrieving orders.
* Updated single order endpoint to accept all requests.

= 1.1.6 =
* Declared compatibility with WooCommerce HPOS.
* Updated plugin's name.
* Updated payment gateway name.

= 1.1.5 =
* Resolved WooCommerce admin emails disabling issues.
* Updated dependencies area.
* Updated default emails sending address for WooCommerce emails to [CheckView](https://checkview.io/).
* Added POST call support to delete order endpoint.
* Added order ID support to delete order endpoint.

= 1.1.4 =
* Re-introduced `registerformtest` endpoint.
* Updated default emails sending address for [CheckView](https://checkview.io/).

= 1.1.3 =
* Removed Cache from `getformresults` endpoint.
* Updated testing product name.
* Added auto cache refresh on plugin's update.

= 1.1.2 =
* Added conditions so WooCommerce Automated Testing feature does not load in the absence of WooCommerce.

= 1.1.1 =
* Added conditions so WooCommerce Automated Testing feature does not load in the absence of WooCommerce.

= 1.1.0 =
* Added WooCommerce Automated Testing feature.
* Shifted WooCommerce Automated Testing functions from `functions.php` to class.
* Updated stock prevention feature.
* Updated default testing product name.

= 1.0.0 =
* Initial release.
