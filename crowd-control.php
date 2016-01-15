<?php
/*
Plugin Name: Crowd Control by Postmatic
Plugin Script: crowd-control.php
Plugin URI: http://wordpress.org/extend/plugins/crowd-control/
Description: Crowd Control gives your users the ability to report comments as inappropriate with a single click. If a comment gets flagged multiple times it'll be removed from the post and marked as pending moderation. We'll even send you an email to let you know. Now you can still go away on vacation and rest assured the trolls won't overrun your site.
Version: 1.1
Author: Ronald Huereca, Jason Lemieux
Author URI: https://gopostmatic.com/
Text Domain: crowd-control
Domain Path: /languages
Forked from: http://wordpress.org/extend/plugins/safe-report-comments/
Original Authors: http://wordpress.org/extend/plugins/safe-report-comments/
*/

if ( !class_exists( "Crowd_Control" ) ) {

	class Crowd_Control {

		private $_plugin_prefix = 'pmcc';
		private $_admin_notices = array();
		private $_nonce_key = 'flag_comment_nonce';
		private $_auto_init = true;
		private $_storagecookie = 'pmcc_flags';
		private $comment_owner = false;
		private $errors;
		public $plugin_url = false;

		// amount of possible attempts transient hits per comment before a COOKIE enabled negative check is considered invalid
		// transient hits will be counted up per ip any time a user flags a comment
		// this number should be always lower than your threshold to avoid manipulation
		public $no_cookie_grace = 3;
		public $transient_lifetime = 86400; // lifetime of fallback transients. lower to keep things usable and c

		public function __construct( $auto_init = true ) {
			/* Initialize Errors */
			$this->errors = new WP_Error();
			$this->errors->add( 'thank_you_message', __( 'Reported.', 'crowd-control' ) );
			$this->errors->add( 'invalid_nonce_message', __( 'It seems you already reported this comment.', 'crowd-control' ) );
			$this->errors->add( 'invalid_values_message', __( 'Cheating huh?', 'crowd-control' ) );
			$this->errors->add( 'already_flagged_message', __( 'It seems you already reported this comment.', 'crowd-conrol' ) );
			$this->errors->add( 'already_flagged_note', __( 'Comment has been flagged already.', 'crowd-control' ) );

			/* Allow others to customize messages */
			$error_codes = $this->errors->get_error_codes();
			foreach ( $error_codes as $index => $error_code ) {
				$message = $this->errors->get_error_message( $error_code );
				/**
				 * Filter: pmcc_errors
				 *
				 * Modify error message
				 *
				 * @since 1.0.0
				 *
				 * @param string Error
				 * @param string Error code
				 */
				$new_message = apply_filters( 'pmcc_errors', $message, $error_code );
				if ( $new_message !== $message ) {
					$this->errors->remove( $error_code );
					$this->errors->add( $error_code, $new_message );
				}
			}

			$this->_admin_notices = get_transient( $this->_plugin_prefix . '_notices' );
			if ( !is_array( $this->_admin_notices ) )
				$this->_admin_notices = array();
			$this->_admin_notices = array_unique( $this->_admin_notices );
			$this->_auto_init = $auto_init;

			if ( !is_admin() || ( defined( 'DOING_AJAX' ) && true === DOING_AJAX ) ) {
				add_action( 'init', array( $this, 'frontend_init' ) );
			} else if ( is_admin() ) {
				add_action( 'admin_init', array( $this, 'backend_init' ) );
			}
			add_action( 'comment_unapproved_to_approved', array( $this, 'mark_comment_moderated' ), 10, 1 );
		}

		public function __destruct() {

		}

		/**
		 * action_enqueue_scripts - Enqueue scripts
		 *
		 * Enqueue ajax scripts
		 *
		 * @since 1.0
		 *
		 * @users wp_enqueue_scripts
		 *
		 */
		public function action_enqueue_scripts() {

			// Use home_url() if domain mapped to avoid cross-domain issues
			if ( home_url() != site_url() )
				$ajaxurl = home_url( '/wp-admin/admin-ajax.php' );
			else
				$ajaxurl = admin_url( 'admin-ajax.php' );

			/**
			 * Filter: pmcc_report_comments_ajax_url
			 *
			 * Ajax URL
			 *
			 * @since 1.0.0
			 *
			 * @param string ajax URL
			 */
			$ajaxurl = apply_filters( 'pmcc_report_comments_ajax_url', $ajaxurl );

			wp_enqueue_script( $this->_plugin_prefix . '-ajax-request', $this->plugin_url . '/js/ajax.js', array( 'jquery' ), '20150929', true );

			/* Localize ajaxurl and error messages */
			$localize_script_vars = array(
				'ajaxurl' => $ajaxurl,
				'errors' => array()
			);
			$error_codes = $this->errors->get_error_codes();
			foreach ( $error_codes as $index => $error_code ) {
				$localize_script_vars['errors'][$error_code] = $this->errors->get_error_message( $error_code );
			}
			wp_localize_script( $this->_plugin_prefix . '-ajax-request', 'pmcc_ajax', $localize_script_vars ); // slightly dirty but needed due to possible problems with mapped domains
		}

		/**
		 * add_admin_notice - Add admin error messages
		 *
		 * Add admin error messages
		 *
		 * @since 1.0
		 *
		 * @param string $message The admin message
		 *
		 */
		protected function add_admin_notice( $message ) {
			$this->_admin_notices[] = $message;
			set_transient( $this->_plugin_prefix . '_notices', $this->_admin_notices, 3600 );
		}

		/**
		 * admin_header - Add admin error notifications
		 *
		 * Add admin error notifications
		 *
		 * @since 1.0
		 *
		 *
		 */
		public function admin_header() {
			// print admin notice in case of notice strings given
			if ( !empty( $this->_admin_notices ) ) {
				add_action( 'admin_notices', array( $this, 'print_admin_notice' ) );
			}
			?>
			<style type="text/css">
				.column-comment_reported {
					width: 8em;
				}
			</style>
			<?php

		}


		/**
		 * wp_header - Add minor front end styles
		 *
		 * @since 1.0.1
		 *
		 */
		public function wp_header() {
			?>
			<style type="text/css">
				.pmcc-comments-report-link {
					font: 10px sans-serif;
					display:block;
					float:right;
					clear: left;
					margin-top: 10px;
				}
				.pmcc-comments-report-link a {
					color: #9C3E3E;
					padding: 2px 5px;
					margin: 2px 0 0 5px;
					border: 1px solid #ddd;
				}
				
				.pmcc-comments-report-link strong {
				    color: white;
				    background: #c0392b;
				    padding-top: 2px;
				    border-radius: 7px;
				    display: block;
				    width: 15px;
				    height: 15px;
				    text-align: center;
				    margin-right: 10px;
				}
			</style>
			<?php
		}

		/**
		 * admin_header - Add reported column to admin
		 *
		 * Callback function to add the report counter to comments screen. Remove action manage_edit-comments_columns if not desired
		 *
		 * @since 1.0
		 *
		 *
		 */
		public function add_comment_reported_column( $comment_columns ) {
			$comment_columns['comment_reported'] = _x( 'Reported', 'column name' );
			return $comment_columns;
		}

		/**
		 * add_flagging_link_comment - Add report link to comment content
		 *
		 * Add report link to comment content
		 *
		 * @since 1.0
		 *
		 * @param string $comment_text
		 * @param object $comment
		 *
		 * @returns string $comment_test
		 */
		public function add_flagging_link_comment( $comment_text, $comment = '' ) {
    		//Do not show if users must be logged in to comment
    		if ( get_option( 'comment_registration' ) && !is_user_logged_in() ) return $comment_text;
    		
    		//Check to make sure comment is an object
    		if ( !is_object( $comment ) ) return $comment_text;
    		
    		//Do not show in admin panel or when comment has already been flagged by user
			if ( $this->is_admin() || $this->already_flagged( $comment->comment_ID ) ) return $comment_text;

			$nonce = wp_create_nonce( 'pmcc_comment_' . $comment->comment_ID );

			$html = $comment_text . sprintf( '<span class="pmcc-comments-report-link" id="comment-%1$d"><a class="hide-if-no-js" href="javascript:void(0);" onclick="crowd_control_comments_flag_comment( \'%1$d\', \'%4$s\', \'%2$d\');">%3$s</a></span>', $comment->comment_ID, $comment->comment_post_ID, esc_html__( 'Report', 'crowd-control' ), $nonce );
			return $html;
		}

		/**
		 * admin_notification - Alert admin via email
		 *
		 * Alert admin via email when comment has been moderated
		 *
		 * @since 1.0
		 *
		 * @param int $comment_id
		 *
		 */
		public function admin_notification( $comment_id ) {
			if ( !$this->is_admin_notification_enabled() ) return;

			$comment = get_comment( $comment_id );

			$admin_email = get_option( 'admin_email' );
			$subject = sprintf( __( 'A comment by %s %s', 'crowd-control' ), esc_html( $comment->comment_author ), esc_html__( 'has been flagged by Crowd Control and sent back to moderation', 'crowd-control' ) );
			$headers = sprintf( 'From: %s <%s>', esc_html( get_bloginfo( 'site' ) ), get_option( 'admin_email' ) ) . "\r\n\r\n";
			$message = __( 'Users of your site have flagged a comment and it has been sent to moderation.', 'crowd-control' ) . "\r\n\r\n";
			$message .= __( 'You are welcome to view the comment yourself at your earliest convenience.', 'crowd-control' ) . "\r\n\r\n";
			$message .= esc_url_raw( add_query_arg( array( 'action' => 'editcomment', 'c' => absint( $comment_id ) ), admin_url( 'comment.php' ) ) );
			wp_mail( $admin_email, $subject, $message, $headers );
		}


		/**
		 * already_flagged - Check if a commment has already been reported
		 *
		 * Check if a commment has already been reported
		 *
		 * @since 1.0
		 *
		 * @param int $comment_id
		 *
		 * @returns true if flagged, false if not
		 */
		public function already_flagged( $comment_id ) {
			// check if cookies are enabled and use cookie store
			if ( isset( $_COOKIE['cc_report_' . $comment_id] ) && 'true' == $_COOKIE['cc_report_' . $comment_id] ) {

				return true;
			}

			// in case we don't have cookies. fall back to transients, block based on IP/User Agent
			$transient = get_transient( md5( $this->_storagecookie . $comment_id . $_SERVER['REMOTE_ADDR'] ) );
			if ( $transient ) {
				return true;
			}
			return false;
		}


		/**
		 * backend_init - Add settings screen to discussion settings
		 *
		 * Add settings screen to discussion settings
		 *
		 * @since 1.0
		 *
		 *
		 */
		public function backend_init() {
			do_action( 'pmcc_report_comments_backend_init' );

			add_settings_field( $this->_plugin_prefix . '_enabled', __( 'Enable Crowd Control moderation', 'crowd-control' ), array( $this, 'comment_flag_enable' ), 'discussion', 'default' );
			register_setting( 'discussion', $this->_plugin_prefix . '_enabled' );

			if ( !$this->is_enabled() )
				return;


			add_settings_field( $this->_plugin_prefix . '_admin_notification', __( 'Administrator notifications', 'crowd-control' ), array( $this, 'comment_admin_notification_setting' ), 'discussion', 'default' );
			register_setting( 'discussion', $this->_plugin_prefix . '_admin_notification' );

			add_settings_field( $this->_plugin_prefix . '_threshold', __( 'Crowd Control threshold', 'crowd-control' ), array( $this, 'comment_flag_threshold' ), 'discussion', 'default' );
			register_setting( 'discussion', $this->_plugin_prefix . '_threshold', array( $this, 'check_threshold' ) );
			add_filter( 'manage_edit-comments_columns', array( $this, 'add_comment_reported_column' ) );
			add_action( 'manage_comments_custom_column', array( $this, 'manage_comment_reported_column' ), 10, 2 );

			add_action( 'admin_menu', array( $this, 'register_admin_panel' ) );
			add_action( 'admin_head', array( $this, 'admin_header' ) );
		}


		/**
		 * check_threshold - Validate threshold
		 *
		 * Validate threshold, callback for settings field
		 *
		 * @since 1.0
		 *
		 * @param check_threshold
		 *
		 * @returns int sanitized threshold
		 */
		public function check_threshold( $value ) {
			if ( (int)$value <= 0 || (int)$value > 100 )
				$this->add_admin_notice( __( 'Please revise your flagging threshold and enter a number between 1 and 100', 'crowd-control' ) );
			return (int)$value;
		}

		/**
		 * clean_cookie_data - Clean cookie data
		 *
		 * Clean cookie data
		 *
		 * @since 1.0
		 *
		 * @access private
		 *
		 * @param array $data
		 *
		 * @returns array Cleaned data
		 */
		private function clean_cookie_data( $data ) {
			$clean_data = array();

			if ( !is_array( $data ) ) {
				$data = array();
			}

			foreach ( $data as $comment_id => $count ) {
				if ( is_numeric( $comment_id ) && is_numeric( $count ) ) {
					$clean_data[$comment_id] = $count;
				}
			}

			return $clean_data;
		}

		/*
		 * Callback for settings field
		 */

		/**
		 * comment_admin_notification_setting - Discussions settiing
		 *
		 * Discussions setting
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 */
		public function comment_admin_notification_setting() {
			$enabled = $this->is_admin_notification_enabled();
			?>
			<label for="<?php echo $this->_plugin_prefix; ?>_admin_notification">
				<input name="<?php echo $this->_plugin_prefix; ?>_admin_notification"
				       id="<?php echo $this->_plugin_prefix; ?>_admin_notification" type="checkbox"
				       value="1" <?php checked( true, $enabled ); ?> />
				<?php _e( "Send administrators an email when the crowd has sent a comment to moderation.", 'crowd-control' ); ?>
			</label>
			<?php
		}

		/**
		 * comment_flag_enable - Discussions settiing
		 *
		 * Discussions setting
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 */
		public function comment_flag_enable() {
			$enabled = $this->is_enabled();
			?>
			<label for="<?php echo $this->_plugin_prefix; ?>_enabled">
				<input name="<?php echo $this->_plugin_prefix; ?>_enabled"
				       id="<?php echo $this->_plugin_prefix; ?>_enabled" type="checkbox"
				       value="1" <?php checked( true, $enabled ); ?> />
				<?php _e( "Let site users mark comments as inappropriate.", 'crowd-control' ); ?>
			</label>
			<?php
		}

		/**
		 * comment_flag_threshold - Discussions settiing
		 *
		 * Discussions setting
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 */
		public function comment_flag_threshold() {
			$threshold = (int)get_option( $this->_plugin_prefix . '_threshold', 3 );
			?>
			<label for="<?php echo $this->_plugin_prefix; ?>_threshold">
				<input size="2" name="<?php echo $this->_plugin_prefix; ?>_threshold"
				       id="<?php echo $this->_plugin_prefix; ?>_threshold" type="text"
				       value="<?php echo $threshold; ?>"/>
				<?php _e( "How many reports until a comment gets sent to moderation?", 'crowd-control' ); ?>
			</label>
			<?php
		}


		/**
		 * flag_comment - Ajax flag comment check
		 *
		 * Ajax flag comment check
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 */
		public function flag_comment() {
			if ( isset( $_REQUEST['comment_id'] ) && (int)$_REQUEST['comment_id'] != $_REQUEST['comment_id'] || empty( $_REQUEST['comment_id'] ) ) {
				$return['code'] = 'already_flagged_message';
				$return['errors'] = true;
				wp_send_json( $return );
				exit();
			}

			$comment_id = absint( $_REQUEST['comment_id'] );
			if ( $this->already_flagged( $comment_id ) ) {
				$return = array();
				$return['code'] = 'already_flagged_message';
				$return['errors'] = true;
				wp_send_json( $return );
				exit();
			}

			$nonce = isset( $_REQUEST['sc_nonce'] ) ? $_REQUEST['sc_nonce'] : '';
			// checking if nonces help
			if ( !wp_verify_nonce( $nonce, 'pmcc_comment_' . $comment_id ) ) {
				$return = array();
				$return['code'] = 'invalid_values_message';
				$return['errors'] = true;
				wp_send_json( $return );
				exit();
			} else {
				$this->mark_flagged( $comment_id );
				$return = array();
				$return['code'] = 'thank_you_message';
				$return['errors'] = false;
				wp_send_json( $return );
				exit();
			}

		}

		/*
		 * Initialize frontend functions
		 */

		/**
		 * frontend_init - Enable actions/filters
		 *
		 * Enable actions/filters
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 */
		public function frontend_init() {

			if ( !$this->is_enabled() )
				return;

			if ( !$this->plugin_url )
				$this->plugin_url = plugins_url( false, __FILE__ );

			do_action( 'pmcc_report_comments_frontend_init' );

			add_action( 'wp_ajax_pmcc_report_comments_flag_comment', array( $this, 'flag_comment' ) );
			add_action( 'wp_ajax_nopriv_pmcc_report_comments_flag_comment', array( $this, 'flag_comment' ) );

			add_action( 'wp_head', array( $this, 'wp_header' ) );

			add_action( 'pmcc_report_comments_mark_flagged', array( $this, 'admin_notification' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'action_enqueue_scripts' ) );  
            
            $admin_can_be_flagged = false;
			if ( $this->is_admin() ) {
    			    /***
            		* Filter: pmcc_admin_marked_flagged
            		*
            		* Whether admins flagged
            		*
            		* @since 1.0.1
            		*
            		* @param bool true flag, false if not
            		*/
    		    	$admin_can_be_flagged = apply_filters( 'pmcc_admin_marked_flagged', false );
            }
			if ( $this->_auto_init && !$admin_can_be_flagged ) {
                add_filter( 'comment_text', array( &$this, 'add_flagging_link_comment' ), 15, 2 );
            }

			add_action( 'comment_report_abuse_link', array( $this, 'print_flagging_link' ) );
		}

		/**
		 * get_flagging_link - Return flagging link
		 *
		 * Return flagging link
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 * @param int $comment_id
		 * @param int $result_id
		 * @param string $text
		 *
		 * @returns flagging link
		 */
		public function get_flagging_link( $comment_id = '', $result_id = '', $text = '' ) {
			global $in_comment_loop;
			if ( empty( $text ) ) {
				$text = __( 'Report', 'crowd-control' );
			}
			if ( empty( $comment_id ) && !$in_comment_loop ) {
				return __( 'Wrong usage of print_flagging_link().', 'crowd-control' );
			}
			if ( empty( $comment_id ) ) {
				$comment_id = get_comment_ID();
			} else {
				$comment_id = (int)$comment_id;
				if ( !get_comment( $comment_id ) ) {
					return __( 'This comment does not exist.', 'crowd-control' );
				}
			}
			if ( empty( $result_id ) )
				$result_id = 'pmcc-comments-result-' . $comment_id;

			/**
			 * Filter: pmcc_report_comments_result_id
			 *
			 * Result ID
			 *
			 * @since 1.0.0
			 *
			 * @param int $result_id
			 */
			$result_id = apply_filters( 'pmcc_report_comments_result_id', $result_id );

			/***
			 * Filter: pmcc_report_comments_flagging_link_text
			 *
			 * Result ID
			 *
			 * @since 1.0.0
			 *
			 * @param string $text
			 */
			$text = apply_filters( 'pmcc_report_comments_flagging_link_text', $text );

			$nonce = wp_create_nonce( $this->_plugin_prefix . '_' . $this->_nonce_key );
			$params = array(
				'action' => 'pmcc_report_comments_flag_comment',
				'sc_nonce' => $nonce,
				'comment_id' => $comment_id,
				'result_id' => $result_id,
				'no_js' => true,
			);

			if ( $this->already_flagged( $comment_id ) )
				return $this->errors->get_error_message( 'already_flagged_note' );

			/**
			 * Filter: pmcc_report_comments_flagging_link
			 *
			 * Modify report HTML
			 *
			 * @since 1.0.0
			 *
			 * @param string flagging link
			 */
			return apply_filters( 'pmcc_report_comments_flagging_link', '
			<span id="' . $result_id . '"><a class="hide-if-no-js" href="javascript:void(0);" onclick="crowd_control_comments_flag_comment( \'' . $comment_id . '\', \'' . esc_html( $nonce ) . '\', \'' . absint( $result_id ) . '\');">' . esc_html( $text ) . '</a></span>' );
			/***
			 * Filter: pmcc_report_comments_flagging_link
			 *
			 * Result ID
			 *
			 * @since 1.0.0
			 *
			 * @param string $html Link
			 */
			return apply_filters( 'pmcc_report_comments_flagging_link', '
			<span id="' . $result_id . '"><a class="hide-if-no-js" href="javascript:void(0);" onclick="crowd_control_comments_flag_comment( \'' . $comment_id . '\', \'' . $nonce . '\', \'' . $result_id . '\');">' . __( $text ) . '</a></span>' );

		}

		/**
		 * is_admin - Whether a user is admin or not and can see comments
		 *
		 * Whether a user is admin or not and can see comments
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 * @returns true if admin, false if not
		 */
		private function is_admin() {
			if ( ( current_user_can( 'manage_network' ) || current_user_can( 'manage_options' ) || current_user_can( 'moderate_comments' ) ) ) {
				return true;
			} else {
				return false;
			}
		}

		/**
		 * is_admin_notification_enabled - Is the admin notification or not
		 *
		 * Is the admin notification or not
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 * @returns true if yes, false if not
		 */
		public function is_admin_notification_enabled() {
			$enabled = get_option( $this->_plugin_prefix . '_admin_notification', 1 );
			if ( $enabled == 1 )
				$enabled = true;
			else
				$enabled = false;
			return $enabled;
		}
		
		/**
    	 * is_comment_owner - if user is comment author
    	 * 
    	 * Is the admin notification or not
    	 *
    	 * @since 1.0
    	 *
    	 * @access public
    	 *
    	 * @returns true if yes, false if not
    	 */
		public function is_comment_owner( $comment_id ) {
			if ( $this->is_admin()  ) {
    		    return true;	
            }
            
            $comment = get_comment( $comment_id );
            $post = get_post( $comment->comment_post_ID );
            if ( $comment->user_id == $post->post_author ) {
                return true;
            }
            return false;
		}
        
         /**
    	 * is_enabled - Is the threshold enabled or not
    	 * 
    	 *  Is the threshold enabled or not
    	 *
    	 * @since 1.0
    	 *
    	 * @access public
    	 *
    	 * @returns true if yes, false if not
    	 */

		public function is_enabled() {
			$enabled = get_option( $this->_plugin_prefix . '_enabled' );
			if ( $enabled == 1 )
				$enabled = true;
			else
				$enabled = false;
			return $enabled;
		}

		/* 
		 * 
		 */

		/**
		 * manage_comment_reported_column - Handle custom column
		 *
		 * Callback function to handle custom column. remove action manage_comments_custom_column if not desired
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 * @param string column_name
		 * @param int $comment_id
		 *
		 */
		public function manage_comment_reported_column( $column_name, $comment_id ) {
			switch ( $column_name ) {
				case 'comment_reported':
					$reports = 0;
					$already_reported = get_comment_meta( $comment_id, $this->_plugin_prefix . '_reported', true );
					if ( $already_reported > 0 )
						$reports = (int)$already_reported;
					echo $reports;
					break;
				default:
					break;
			}
		}


		/**
		 * manage_comment_reported_column - Mark a comment as being moderated
		 *
		 * Mark a comment as being moderated so it will not be autoflagged again
		 * called via comment transient from unapproved to approved
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 * @param object $comment
		 *
		 */
		public function mark_comment_moderated( $comment ) {
			if ( isset( $comment->comment_ID ) ) {
				update_comment_meta( $comment->comment_ID, $this->_plugin_prefix . '_moderated', true );
			}
		}

		/**
		 * mark_flagged - Mark a comment as flagged
		 *
		 * Mark a comment has flagged and set transient as a backup
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 * @param int $comment_id
		 *
		 */
		public function mark_flagged( $comment_id ) {
			$data = array();

			// in case we don't have cookies. fall back to transients, block based on IP, shorter timeout to keep mem usage low and don't lock out whole companies
			$transient = get_transient( md5( $this->_storagecookie . $comment_id . $_SERVER['REMOTE_ADDR'] ) );
			if ( !$transient ) {
				set_transient( md5( $this->_storagecookie . $comment_id . $_SERVER['REMOTE_ADDR'] ), array( $comment_id => 1 ), $this->transient_lifetime );
			}

				
			$threshold = (int) get_option( $this->_plugin_prefix . '_threshold' );
			if ( $this->is_comment_owner( $comment_id )  ) {
    		    $threshold = $threshold * 2;	
    		     /***
        		* Filter: pmcc_admin_marked_flagged
        		*
        		* Whether admins flagged
        		*
        		* @since 1.0.1
        		*
        		* @param bool true flag, false if not
        		*/
		    	$threshold = apply_filters( 'pmcc_comment_owner_threshold' , $threshold );
            }

			$current_reports = get_comment_meta( $comment_id, $this->_plugin_prefix . '_reported', true );
			$current_reports++;
			update_comment_meta( $comment_id, $this->_plugin_prefix . '_reported', $current_reports );


			// we will not flag a comment twice. the moderator is the boss here.
			$already_reported = get_comment_meta( $comment_id, $this->_plugin_prefix . '_reported', true );
			$already_moderated = get_comment_meta( $comment_id, $this->_plugin_prefix . '_moderated', true );
			if ( true == $already_reported && true == $already_moderated ) {
				// But maybe the boss wants to allow comments to be reflagged

				/***
				 * Filter: pmcc_report_comments_allow_moderated_to_be_reflagged
				 *
				 * Result ID
				 *
				 * @since 1.0.0
				 *
				 * @param bool true reflag, false if not
				 */
				if ( !apply_filters( 'pmcc_report_comments_allow_moderated_to_be_reflagged', false ) )
					return;
			}
			
			$admin_can_be_flagged = false;
			if ( $this->is_admin() ) {
    			    /***
            		* Filter: pmcc_admin_marked_flagged
            		*
            		* Whether admins flagged
            		*
            		* @since 1.0.1
            		*
            		* @param bool true flag, false if not
            		*/
    		    	$admin_can_be_flagged = apply_filters( 'pmcc_admin_marked_flagged', false );
            }

			if ( $current_reports >= $threshold && !$admin_can_be_flagged  ) {
				do_action( 'pmcc_report_comments_mark_flagged', $comment_id );
				wp_set_comment_status( $comment_id, 'hold' );
			}
		}

		/**
		 * print_admin_notice - Print admin notification
		 *
		 * Print a notification / error msg
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 * @param int $comment_id
		 *
		 */
		public function print_admin_notice() {
			?>
			<div id="message" class="updated fade"><h3>Safe Comments:</h3><?php

			foreach ( (array)$this->_admin_notices as $notice ) {
				?>
				<p><?php echo $notice ?></p>
				<?php
			}
			?></div><?php
			$this->_admin_notices = array();
			delete_transient( $this->_plugin_prefix . '_notices' );
		}

		/**
		 * print_flagging_link - Print flagging link
		 *
		 * Print flagging link
		 *
		 * @since 1.0
		 *
		 * @access public
		 *
		 * @param int $comment_id
		 *
		 */
		public function print_flagging_link( $comment_id = '', $result_id = '', $text = '' ) {
			$text = __( 'Report comment', 'crowd-control' );
			echo $this->get_flagging_link( $comment_id = '', $result_id = '', $text );
		}

	}
}
add_action( 'plugins_loaded', 'pmcc_activate' );
function pmcc_activate() {
	new Crowd_Control( true );
}
