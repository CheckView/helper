=== CheckView – Form & Checkout Testing ===
Contributors: checkview, inspry
Donate link: https://checkview.io/
Tags: form testing, form monitoring, wordpress testing, woocommerce testing, site monitoring
Requires at least: 5.0.1
Tested up to: 6.9
Requires PHP: 7.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Stable tag: 2.0.26

[CheckView](https://checkview.io/) automates WordPress form and WooCommerce testing, monitoring key flows to catch failures early before they cost you leads or sales everyday.

== Description ==

Websites rarely fail loudly. Forms stop submitting. Leads disappear. Emails never send. Checkout buttons break quietly in the background. These issues often go unnoticed until sales or leads are lost.

CheckView automatically tests your WordPress forms on a schedule, using real browser sessions to verify that submissions complete successfully, emails are sent, and integrations work as expected. It also supports testing logins, carts, and WooCommerce checkout flows.

Built specifically for WordPress and WooCommerce, CheckView helps site owners, developers, and agencies detect problems early and resolve them faster, without writing code or setting up complex testing infrastructure.

== Works Well With ==

CheckView works well with popular WordPress form and eCommerce plugins including Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms, Formidable Forms, WS Form, and WooCommerce. 

== Important == 

This plugin requires an active [CheckView](https://checkview.io/) account.  The plugin can be installed and activated without an account, but automated testing features are only available once the site is connected to the CheckView platform.

== Key Features ==

= Automated WordPress Form Testing =

   * Run real browser-based tests on WordPress forms, including multi-step and dynamic forms
   * Validate form submissions from start to finish, including email notifications and integrations
   * Test WooCommerce checkout flows alongside forms for the most complete coverage
   * Verify full form submissions, including email notifications and stored entries
   * Confirm WooCommerce order creation when testing checkout flows
   * Test real WooCommerce products or automated test products
   * Detect silent failures that uptime monitoring alone cannot catch

= Scheduled Monitoring & Alerts =

   * Schedule tests to run automatically
   * Receive alerts right away when a test fails
   * View detailed failure logs and video recordings showing exactly where the test broke

= No-Code, One-Click Setup =

   * Connect your site to CheckView with a single click
   * No Chrome extensions, GitHub repositories, or custom scripts required
   * Replace repetitive manual testing with reliable automated coverage
   * Tests are executed externally without impacting visitor page load times

= Custom Test Flows =

   * Customize test steps to match your theme, plugins, and site structure
   * Adjust URLs, selectors, and checkout behavior using the built-in test flow editor
   * Designed to adapt to real-world WordPress customization

== Who CheckView Is For ==

   * Sites that rely on contact, lead, or application forms
   * WooCommerce stores where checkout reliability matters
   * Agencies managing multiple WordPress sites
   * Teams that want automated testing without writing code

== Common Use Cases ==

*  Monitoring contact and lead forms after plugin or theme updates
*  Verifying checkout functionality before marketing campaigns
*  Catching silent failures caused by security, theme, or plugin conflicts

== Built for Form-Heavy Sites, Agencies & Multiple Websites ==

   * Monitor multiple sites from a single dashboard
   * Track site health and test results across client websites
   * Complement existing uptime monitoring with functional testing

== Privacy & Data Protection ==

   * Test data is automatically purged from your website after each run
   * No personal customer data is stored
   * Designed to protect the integrity of form submissions and WooCommerce orders
   * All requests are authenticated, and the plugin only responds to authorized CheckView test traffic

== Account and Pricing ==

   * CheckView is currently available to agencies and site owners via the CheckView platform. An active account at [CheckView](https://checkview.io/) is required. Please visit the site for current plans and availability.

== Data Usage Transparency ==

When connected, CheckView may access the following non-sensitive data strictly for testing purposes:

  * WordPress site metadata (core version, active theme, installed plugins)
  * Form and WooCommerce metadata needed to generate and validate tests (no personal customer data)
  * Product names and images used for checkout test selection

== What CheckView Does Not Do ==

CheckView does not replace manual QA, visual regression testing, or load testing tools. It is designed specifically to verify that critical WordPress actions, such as form submissions and checkout flows, function correctly over time and may require manual adjustment depending on your specific website setup.

== Installation ==

1. Upload the CheckView plugin to the /wp-content/plugins/ directory

2. Activate the plugin from the Plugins menu in WordPress

3. Return to [CheckView](https://checkview.io/) to connect your site and configure tests

== Documentation ==
Extensive documentation can be found in our [Docs](https://checkview.io/docs/) hub.

== Support ==
Support is available directly within the CheckView dashboard. Our team can help troubleshoot issues, configure your tests, and build custom test flows for your site.

== Frequently Asked Questions ==

= Do I need a CheckView account? =

Yes. A [CheckView](https://checkview.io/) account is required to enable automated testing features. The plugin alone does not perform testing without being connected to the platform.

= Which form plugins does CheckView support? =

CheckView supports automated testing for many popular WordPress form plugins, including WS Form, WPForms, Ninja Forms, Gravity Forms, Formidable Forms, Contact Form 7, and Fluent Forms.

= If my preferred form plugin is not listed, can I still use CheckView? =

Yes. CheckView can still be used with other form plugins, though validation may be more limited and some manual setup may be required using the test flow editor. Our support team is available to help ensure your tests are configured correctly.

Support continues to expand and CheckView is designed to adapt to custom themes and form configurations using customizable test flows. 

= Can CheckView test WooCommerce checkout flows? =

Yes. CheckView is designed to automatically generate test flows for standard WooCommerce product, cart, and checkout pages, allowing sites to be tested for add-to-cart functionality and the full checkout process.

CheckView can safely test payment gateways using its built-in dummy checkout method or by leveraging Stripe in test mode, ensuring checkout functionality is validated without processing real transactions.

= Can I use CheckView to test logins, learning management systems, or specific user flows? =

Yes. CheckView can be used to test a wide range of user flows, including logins, learning management systems, and other custom interactions. These tests may require manual configuration, but CheckView provides powerful assertion support to validate specific actions, messages, and outcomes throughout the flow.

= Does CheckView replace uptime monitoring? =

No. CheckView complements uptime monitoring.

Uptime monitoring checks whether a site is online. CheckView verifies that critical actions, such as form submissions and checkout flows, actually work. A site can be online while forms or checkout are broken. CheckView is designed to catch those silent failures.

= Will automated tests affect real users or create real orders? =

No. CheckView is designed to run safely in the background.

Form data and test orders are automatically handled and cleaned up after each test. No real customer data is stored, and test activity is isolated from real users.

= Does CheckView work with CAPTCHA and anti-spam plugins? =

Yes. CheckView includes compatibility handling for common CAPTCHA and anti-spam solutions, including honeypots, Google reCAPTCHA, hCaptcha, Cloudflare Turnstile, WP Armour, Akismet, OOPSpam, CleanTalk and popular security plugins such as Solid Security and WordFence.

These protections are safely bypassed during automated tests while remaining active for real visitors.

= Do I need to write code or configure scripts? =

No. CheckView is a no-code solution.

Most sites can connect and start testing with a single click. Custom test flows can be adjusted visually without writing code.

= Can I use CheckView on multiple websites? =

Yes. CheckView is built for agencies and site owners managing multiple WordPress websites. All connected sites can be monitored from a single dashboard.

= What happens when a test fails? =

When a test fails, CheckView records the failure, captures logs and a video replay, and sends an alert so you can see exactly where and why the issue occurred.

= Does this slow down my site? =

No. CheckView runs tests externally using real browser sessions and does not add load to normal visitor traffic. Tests are executed on a schedule and are designed to have minimal impact on site performance.

= Can I pause or schedule tests? =

Yes. Tests can be scheduled to run automatically, and you can pause or adjust test schedules at any time through the CheckView dashboard.

= Is this suitable for staging or production sites? =

Yes. CheckView can be used on both staging and production environments. Many users run tests in production to ensure real-world functionality, while others use staging for pre-release validation.

= Does CheckView work with custom themes? =

Yes. CheckView is designed to work with custom WordPress themes. Test flows can be adjusted to match custom layouts, selectors, and user flows using our visual step editor, allowing testing to align with how your site is built.

= What data does CheckView store during tests? =
CheckView runs tests externally. Temporary test data created on your site (such as test entries or test orders) is cleaned up after each run. CheckView does not store customer personal data as part of testing.

= Where do I get support? =

Support and test configuration are handled through the CheckView platform. Please visit [CheckView](https://checkview.io/) for documentation and support resources.

== Screenshots ==

1. CheckView test flow.

2. CheckView test flow results.

3. CheckView general settings.

== Changelog ==

= 2.0.26 =
* Add logging for email submissions and headers across all form helper classes.

= 2.0.25 =
* Add new filter, `checkview_jwt_leeway`, for JWT leeway configuration (in seconds).

= 2.0.24 =
* Confirmed compatibility with WordPress 6.9.

= 2.0.23 =
* Trim ports when detecting IP addresses.

= 2.0.22 =
* Handle CF7 piped value mismatches.

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

= 2.0.26 =
* Add logging for email submissions and headers across all form helper classes.

= 2.0.25 =
* Add new filter, `checkview_jwt_leeway`, for JWT leeway configuration (in seconds).

= 2.0.24 =
* Confirmed compatibility with WordPress 6.9.

= 2.0.23 =
* Trim ports when detecting IP addresses.

= 2.0.22 =
* Handle CF7 piped value mismatches.

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
