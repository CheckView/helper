<?php
/**
 * Checkview_Mail_Redirect class
 *
 * Form-plugin-agnostic backstop that rewrites wp_mail recipients to TEST_EMAIL
 * during a CheckView test. Catches notification paths that bypass the per-
 * plugin filters (gform_pre_send_email, wpforms_email_send_*, etc.) — e.g.,
 * when a transactional-email plugin (Postmark, FluentSMTP, SendGrid) overrides
 * wp_mail, or when a third-party integration sends notifications outside the
 * form plugin's standard chain.
 *
 * Runs only when TEST_EMAIL is defined (i.e., inside a verified test session),
 * at wp_mail filter priority 999 so transport plugins have already applied
 * their own modifications before we rewrite the recipient.
 *
 * @since 2.0.35
 * @package Checkview
 * @subpackage Checkview/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Mail_Redirect' ) ) {

	/**
	 * Rewrites wp_mail recipients to TEST_EMAIL during a CheckView test.
	 *
	 * Acts as a backstop for cases where form-plugin-specific filters
	 * (gform_pre_send_email, wpforms_*, etc.) do not fire — for example, when a
	 * custom snippet or third-party integration sends the form notification via
	 * wp_mail directly, bypassing the form plugin's standard notification chain.
	 *
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Mail_Redirect {

		/**
		 * Constructor — registers the wp_mail filter when in test context.
		 */
		public function __construct() {
			if ( ! defined( 'TEST_EMAIL' ) || '' === (string) TEST_EMAIL ) {
				return;
			}

			// Priority 999: run after most other wp_mail filters (transport
			// plugins, GF Postmark add-on, etc.) so we rewrite the final
			// recipient just before send.
			add_filter( 'wp_mail', array( $this, 'redirect_recipient' ), 999, 1 );
		}

		/**
		 * Rewrites the wp_mail recipient to the CheckView test inbox.
		 *
		 * Mirrors Checkview_Gforms_Helper::checkview_inject_email() behavior:
		 * - Default (REPLACE): replace `to` with TEST_EMAIL and strip Cc/Bcc.
		 * - When `disable_email_receipt_<test_id>` option is 'true' (APPEND):
		 *   append TEST_EMAIL to the existing recipients rather than replacing.
		 *
		 * Two skip conditions avoid double-work when an earlier filter
		 * (e.g. gform_pre_send_email at priority 99) already did the rewrite:
		 * - REPLACE mode: skip if $to is already exactly TEST_EMAIL.
		 * - APPEND mode: skip if $to already contains TEST_EMAIL.
		 *
		 * @param mixed $atts wp_mail compact args (to/subject/message/headers/attachments).
		 * @return mixed Modified args (or original if not applicable).
		 */
		public function redirect_recipient( $atts ) {
			if ( ! is_array( $atts ) || empty( $atts['to'] ) ) {
				return $atts;
			}

			$cv_test_id  = get_checkview_test_id();
			$append_mode = $cv_test_id
				&& 'true' === get_option( 'disable_email_receipt_' . $cv_test_id, false );

			if ( $append_mode ) {
				if ( $this->contains_test_email( $atts['to'] ) ) {
					return $atts;
				}
				$original_to = $atts['to'];
				if ( is_array( $atts['to'] ) ) {
					$atts['to'][] = TEST_EMAIL;
				} else {
					$atts['to'] .= ', ' . TEST_EMAIL;
				}
			} else {
				if ( $this->is_only_test_email( $atts['to'] ) ) {
					return $atts;
				}
				$original_to     = $atts['to'];
				$atts['to']      = TEST_EMAIL;
				$atts['headers'] = $this->strip_cc_bcc_headers( $atts['headers'] ?? '' );
			}

			Checkview_Admin_Logs::add(
				'ip-logs',
				'wp_mail backstop: rewrote recipient from ' . wp_json_encode( $original_to ) . ' to ' . wp_json_encode( $atts['to'] )
			);

			return $atts;
		}

		/**
		 * True if any address in $to is exactly TEST_EMAIL (case-insensitive).
		 *
		 * Tokenises the recipient by comma/whitespace and compares each token
		 * for exact equality with TEST_EMAIL — avoids substring false-positives
		 * like "abc@test-mail.checkview.io.attacker.com".
		 *
		 * @param array|string $to Current recipient(s).
		 * @return bool
		 */
		private function contains_test_email( $to ): bool {
			$addresses = is_array( $to ) ? $to : preg_split( '/[,\s]+/', (string) $to );
			foreach ( (array) $addresses as $addr ) {
				if ( ! is_string( $addr ) ) {
					continue;
				}
				if ( 0 === strcasecmp( trim( $addr ), TEST_EMAIL ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * True if $to is exactly one address and that address is TEST_EMAIL.
		 *
		 * Used to skip rewriting in REPLACE mode when an earlier filter already
		 * set $to to TEST_EMAIL — prevents a redundant log line.
		 *
		 * @param array|string $to Current recipient(s).
		 * @return bool
		 */
		private function is_only_test_email( $to ): bool {
			if ( is_string( $to ) ) {
				$tokens = preg_split( '/[,\s]+/', $to, -1, PREG_SPLIT_NO_EMPTY );
				return is_array( $tokens )
					&& count( $tokens ) === 1
					&& 0 === strcasecmp( $tokens[0], TEST_EMAIL );
			}
			if ( is_array( $to ) && 1 === count( $to ) ) {
				$only = reset( $to );
				return is_string( $only ) && 0 === strcasecmp( trim( $only ), TEST_EMAIL );
			}
			return false;
		}

		/**
		 * Removes Cc: and Bcc: headers so other recipients don't receive the test email.
		 *
		 * Preserves the input type: string in → string out, array in → array out.
		 * Avoids type changes that downstream transport plugins might mishandle.
		 *
		 * @param array|string $headers Original headers.
		 * @return array|string Filtered headers.
		 */
		private function strip_cc_bcc_headers( $headers ) {
			$was_string = ! is_array( $headers );
			$list       = $was_string ? preg_split( '/\r?\n/', (string) $headers ) : $headers;

			$filtered = array_values(
				array_filter(
					$list,
					function ( $header ) {
						if ( ! is_string( $header ) ) {
							return true;
						}
						$lower = strtolower( ltrim( $header ) );
						return 0 !== strpos( $lower, 'bcc:' ) && 0 !== strpos( $lower, 'cc:' );
					}
				)
			);

			return $was_string ? implode( "\r\n", $filtered ) : $filtered;
		}
	}

	new Checkview_Mail_Redirect();
}
