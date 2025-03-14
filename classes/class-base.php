<?php
/**
 * Base class for the Cloudflare Utils plugin.
 *
 * @package PP_Cloudflare_Utils
 */

namespace PP_Cloudflare_Utils;

/**
 * Base class for the Cloudflare Utils plugin.
 */
class Base {
	/**
	 * Constructor to initialize the plugin.
	 */
	public function __construct() {
		\add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init() {
		// Leaked credentials.
		\add_action( 'wp_login', [ $this, 'check_leaked_credentials' ] );
		\add_filter( 'login_message', [ $this, 'password_reset_notice' ] );

		// Add cache tags.
		\add_action( 'send_headers', [ $this, 'add_cache_headers' ] );
		\add_action( 'send_no_cache_headers', [ $this, 'private_cache_headers' ] );

		// Cache redirects.
		\add_filter( 'x_redirect_by', [ $this, 'add_cache_control_to_redirects' ], 11, 3 );

		// Cache clear on publish & update.
		\add_action( 'post_updated', [ $this, 'clear_cloudflare_cache_on_publish' ] );

		// Toolbar buttons to clear the cache.
		\add_action( 'admin_bar_menu', [ $this, 'add_toolbar_button' ], 20 );
		\add_action( 'wp_ajax_clear_cloudflare_cache', [ $this, 'ajax_clear_cloudflare_cache' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'toolbar_icon_styles' ] );
		\add_action( 'wp_enqueue_scripts', [ $this, 'toolbar_icon_styles' ] );

		// Comment cache handling.
		\add_action( 'wp_insert_comment', [ $this, 'clear_cache_on_new_comment' ], 10, 2 );
		\add_action( 'wp_set_comment_status', [ $this, 'clear_cache_on_comment_approve' ], 10, 2 );

		// Unhook comment cookies stuff from core.
		\remove_action( 'set_comment_cookies', 'wp_set_comment_cookies' );
		\add_action( 'wp_footer', [ $this, 'print_custom_comment_script' ] );
		\add_filter( 'wp_get_current_commenter', [ $this, 'ignore_comment_cookies_serverside' ] );
		\add_filter( 'comment_post_redirect', [ $this, 'comment_moderation_redirect' ], 10, 2 );

		// Register admin menu.
		\add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );

		// Register settings using the Settings API.
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Filter the comment moderation redirect.
	 *
	 * @param string      $location The location to redirect to.
	 * @param \WP_Comment $comment  The comment object.
	 *
	 * @return string The location to redirect to.
	 */
	public function comment_moderation_redirect( $location, $comment ) {

		// Check if the comment is held for moderation.
		if ( $comment->comment_approved === '0' ||
			$comment->comment_approved === 'hold'
		) {
			/*
			Redirect to a custom "held for moderation" page.
			return home_url( '/comment-moderation/' );
			*/
			return (string) \get_permalink( (int) $comment->comment_post_ID );
		}

		return $location;
	}

	/**
	 * To prevent this data from being cached, we don't want to show it server side.
	 *
	 * @param array<string, string> $commenter Ignored.
	 *
	 * @return array<string, string> With empty values for all three keys.
	 */
	public function ignore_comment_cookies_serverside( $commenter ) { // phpcs:ignore
		return [
			'comment_author'       => '',
			'comment_author_email' => '',
			'comment_author_url'   => '',
		];
	}

	/**
	 * Prints a script that sets cookies for comment form field values on comment submission,
	 * and reads them from a cookie if it's there.
	 *
	 * @return void
	 */
	public function print_custom_comment_script() {
		if ( ! \is_singular() || ! \comments_open() ) {
			return;
		}
		?>
		<script>
			// Function to set comment cookies.
			function setCommentCookies( name, email, url ) {
				const oneYear = 365 * 24 * 60 * 60 * 1000;
				const expiryDate = new Date( Date.now() + oneYear ).toUTCString();

				// Set cookies for comment author data.
				document.cookie = `comment_author=${encodeURIComponent(name)}; expires=${expiryDate}; path=/`;
				document.cookie = `comment_author_email=${encodeURIComponent(email)}; expires=${expiryDate}; path=/`;
				document.cookie = `comment_author_url=${encodeURIComponent(url)}; expires=${expiryDate}; path=/`;
			}

			// Function to read cookies.
			function getCommentCookies() {
				const cookies = document.cookie.split( '; ' ).reduce(( acc, cookie ) => {
					const [key, value] = cookie.split( '=' );
					acc[key]           = decodeURIComponent(value);

					return acc;
				}, {} );

				return {
					name:  cookies['comment_author'] || '',
					email: cookies['comment_author_email'] || '',
					url:   cookies['comment_author_url'] || ''
				}
			}

			document.addEventListener( 'DOMContentLoaded', function () {
				// Populate comment form fields with cookie data.
				const cookies    = getCommentCookies();
				const nameField  = document.getElementById( 'author' );
				const emailField = document.getElementById( 'email' );
				const urlField   = document.getElementById( 'url' );

				if (nameField)  nameField.value  = cookies.name;
				if (emailField) emailField.value = cookies.email;
				if (urlField)   urlField.value   = cookies.url;

				// Set cookies on form submission.
				const commentForm = document.getElementById( 'commentform' );
				if ( commentForm ) {
					commentForm.addEventListener( 'submit', function() {
						if ( nameField && emailField && urlField ) {
							setCommentCookies( nameField.value, emailField.value, urlField.value );
						}
					});
				}
			});
		</script>
		<?php
	}

	/**
	 * Clear cache for a post when a new comment is posted that is immediately approved.
	 *
	 * @param int         $comment_id      The comment ID.
	 * @param \WP_Comment $comment_object The comment object.
	 *
	 * @return void
	 */
	public function clear_cache_on_new_comment( $comment_id, $comment_object ) {
		if ( $comment_object->comment_approved === '1' ) {
			$this->clear_cloudflare_cache( (string) \get_permalink( (int) $comment_object->comment_post_ID ) );
		}
	}

	/**
	 * Clear cache for a post when a comment is approved.
	 *
	 * @param int    $comment_id The comment ID.
	 * @param string $new_status The new status.
	 *
	 * @return void
	 */
	public function clear_cache_on_comment_approve( $comment_id, $new_status ) {
		if ( $new_status === 'approve' || $new_status === '1' ) {
			$comment_object = \get_comment( $comment_id );
			if ( ! $comment_object ) {
				return;
			}
			$this->clear_cloudflare_cache( (string) \get_permalink( (int) $comment_object->comment_post_ID ) );
		}
	}

	/**
	 * Sends private cache headers.
	 *
	 * @return void
	 */
	public function private_cache_headers() {
		$this->timed_cache_headers( 0 );
	}

	/**
	 * Sends cache headers to cache for a year.
	 *
	 * @return void
	 */
	public function year_cache_headers() {
		$this->timed_cache_headers( 365 * DAY_IN_SECONDS );
	}

	/**
	 * Adds cache headers for $cache_time.
	 *
	 * @param int $cache_time Cache time in seconds.
	 *
	 * @return void
	 */
	private function timed_cache_headers( $cache_time ) {
		// If cache time is 0, we want to set expire date in the past and cache control to private.
		if ( $cache_time === 0 ) {
			header( 'Cache-Control: private, max-age=' . $cache_time, true );
			$expire_time = 0;
		} else {
			header( sprintf( 'Cache-Control: public, max-age=%1$d, s-maxage=%1$d', $cache_time ), true );
			$expire_time = time() + $cache_time;
		}
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', $expire_time ) . ' GMT', true );
	}

	/**
	 * Adds Cache-Tag HTTP header to every page.
	 *
	 * @return void
	 */
	public function add_cache_headers() {
		// If a user is logged in, we never want to cache their responses as they might contain personal info.
		if ( \is_user_logged_in() ) {
			$this->private_cache_headers();
			return;
		}
		// Note that is_admin() is also true for requests to admin-ajax, which here is "by design".
		if ( \is_admin() || \is_login() ) {
			$this->private_cache_headers();
			return;
		}
		// We don't want to cache checkout pages, as that causes issues.
		if ( ( function_exists( 'edd_is_checkout' ) && \edd_is_checkout() ) ||
			( function_exists( 'is_cart' ) && \is_cart() ) ||
			( function_exists( 'is_checkout' ) && \is_checkout() ) ||
			( function_exists( 'is_account_page' ) && \is_account_page() ) ||
			( function_exists( 'is_wc_endpoint_url' ) && \is_wc_endpoint_url() )
		) {
			$this->private_cache_headers();
			return;
		}

		// If there was an explicit try to send no cache headers, we shouldn't override them.
		if ( \did_action( 'send_no_cache_headers' ) ) {
			return;
		}

		// We've gotten this far, which should mean that we can safely cache this result.
		header( sprintf( 'Cache-Control: public, max-age=%1$d, s-maxage=%1$d', ( 365 * DAY_IN_SECONDS ) ), true );
		header( sprintf( 'Expires: %1$s GMT', gmdate( 'D, d M Y H:i:s', time() + ( 365 * DAY_IN_SECONDS ) ) ), true );

		$body_classes = \get_body_class();
		$cache_tags   = \implode( ',', $body_classes );
		$this->set_cache_tag( $cache_tags );
	}

	/**
	 * Clears the Cloudflare cache for the entire domain when a post or page is published.
	 *
	 * @param int $post_id The ID of the post being published.
	 *
	 * @return void
	 */
	public function clear_cloudflare_cache_on_publish( $post_id ) {
		// Check if this is a post or page being published.
		if ( \get_post_status( $post_id ) !== 'publish' ) {
			return;
		}

		$this->clear_cloudflare_cache( (string) \get_permalink( $post_id ) );
		$this->clear_cloudflare_cache( (string) \get_home_url() );
	}

	/**
	 * Clears the Cloudflare cache for the entire domain or a specific URL.
	 *
	 * @param string|null $url_to_purge Optional. The URL to clear from the cache. Defaults to null, which clears the entire domain.
	 *
	 * @return int|string|void
	 */
	public function clear_cloudflare_cache( $url_to_purge = null ) {
		if ( ! $this->get_cloudflare_zone_id() ) {
			return;
		}

		// Define Cloudflare API endpoint and headers.
		$url     = 'https://api.cloudflare.com/client/v4/zones/' . $this->get_cloudflare_zone_id() . '/purge_cache';
		$headers = [
			'x-auth-email'  => $this->get_cloudflare_email(),
			'authorization' => 'bearer ' . $this->get_cloudflare_api_token(),
			'content-type'  => 'application/json',
		];

		// Prepare the request body based on whether a specific URL is provided.
		$body = $url_to_purge
			? (string) \wp_json_encode( [ 'files' => [ $url_to_purge ] ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: (string) \wp_json_encode( [ 'purge_everything' => true ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// Send the request to Cloudflare API.
		$response = \wp_remote_post(
			$url,
			[
				'headers' => $headers,
				'body'    => $body,
			]
		);

		// Log the request and response.
		$log_entry = sprintf(
			"[%s] Request: %s\nHeaders: %s\nBody: %s\nResponse: %s\n\n",
			gmdate( 'Y-m-d H:i:s' ),
			$url,
			\wp_json_encode( $headers, JSON_UNESCAPED_SLASHES ),
			$body,
			\wp_remote_retrieve_body( $response )
		);
		file_put_contents( WP_CONTENT_DIR . '/cloudflare-api.log', $log_entry, FILE_APPEND ); // phpcs:ignore

		// Check for errors in the response.
		if ( \is_wp_error( $response ) ) {
			error_log( 'Cloudflare cache purge failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $response->get_error_message();
		} else {
			$response_code = \wp_remote_retrieve_response_code( $response );
			if ( $response_code !== 200 ) {
				error_log( 'Cloudflare cache purge failed with response code: ' . $response_code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return $response_code;
		}
	}

	/**
	 * Adds Cache-Control header to 301 redirects.
	 *
	 * @param string $redirect_by The redirect by string (unused).
	 * @param int    $status      The HTTP status code.
	 * @param string $location    The path to redirect to (unused).
	 *
	 * @return string The (unaltered) redirect by string.
	 */
	public function add_cache_control_to_redirects( $redirect_by, $status, $location ) { // phpcs:ignore
		if ( $status === 301 ) {
			$this->year_cache_headers();
			header_remove( 'Content-Type' );
			header_remove( 'Cache-Tag' );
			header_remove( 'PP-Cache-Tag' );
		}

		return $redirect_by;
	}

	/**
	 * Sets the cache tag header.
	 *
	 * @param string $tag The cache tag to set.
	 *
	 * @return void
	 */
	public function set_cache_tag( $tag ) {
		header( 'Cache-Tag: ' . $tag );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			header( 'PP-Cache-Tag: ' . $tag );
		}
	}

	/**
	 * Check for the 'Exposed-Credential-Check' header and force password reset if detected.
	 *
	 * @return void
	 */
	public function check_leaked_credentials() {
		if ( isset( $_SERVER['HTTP_EXPOSED_CREDENTIAL_CHECK'] ) ) {
			// Log the user out and redirect to the lost password page with a reason parameter.
			\wp_logout();
			\wp_safe_redirect( \wp_lostpassword_url() . '&reason=leaked_credentials' );
			exit;
		}
	}

	/**
	 * Adds a notice on the lost password page if leaked credentials are detected.
	 *
	 * @param string $message The existing login message.
	 *
	 * @return string The modified login message.
	 */
	public function password_reset_notice( $message ) {
		if ( isset( $_GET['reason'] ) && $_GET['reason'] === 'leaked_credentials' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = '<p class="message" style="border-left-color:red;">' . sprintf(
				/* translators: %1$s is have i been pwned. */
				\esc_html__( 'According to %1$s your login credentials have been exposed in a data breach. Please reset your password to secure your account.', 'pp-cf-utils' ),
				'<a href="https://haveibeenpwned.com/">have i been pwned</a> &amp; <a href="https://developers.cloudflare.com/waf/detections/leaked-credentials/">Cloudflare</a>'
			) . '</p>' . $message;
		}
		return $message;
	}

	/**
	 * Adds a button to the WordPress toolbar to clear Cloudflare cache.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The WordPress Admin Bar object.
	 *
	 * @return void
	 */
	public function add_toolbar_button( $wp_admin_bar ) {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		// Add a top-level icon to the toolbar.
		$wp_admin_bar->add_node(
			[
				'id'    => 'cloudflare',
				'title' => '<span class="cf-icon"></span>',
				'href'  => '#',
				'meta'  => [
					'title' => \esc_html__( 'Cloudflare', 'pp-cf-utils' ), // Tooltip text.
				],
			]
		);

		if ( ! $this->get_cloudflare_zone_id() ) {
			$wp_admin_bar->add_node(
				[
					'id'     => 'set_cloudflare_zone_id',
					'title'  => \esc_html__( 'Set Cloudflare zone settings', 'pp-cf-utils' ),
					'parent' => 'cloudflare',
					'href'   => \admin_url( 'options-general.php?page=pp-cf-utils' ),
				]
			);
			return;
		}

		$wp_admin_bar->add_node(
			[
				'id'     => 'clear_cloudflare_cache',
				'title'  => \esc_html__( 'Clear full CF cache', 'pp-cf-utils' ),
				'parent' => 'cloudflare',
				'href'   => '#',
				'meta'   => [
					'onclick' => 'clearCloudflareCache(); return false;',
				],
			]
		);
		if ( \is_singular() ) {
			$wp_admin_bar->add_node(
				[
					'id'     => 'clear_cloudflare_cache_url',
					'title'  => \esc_html__( 'Clear CF cache for this URL', 'pp-cf-utils' ),
					'parent' => 'cloudflare',
					'href'   => '#',
					'meta'   => [
						'onclick' => 'clearCloudflareCache("' . \get_permalink() . '"); return false;',
					],
				]
			);
		}

		$this->add_toolbar_button_script();
	}

	/**
	 * Adds the toolbar icon styles.
	 *
	 * @return void
	 */
	public function toolbar_icon_styles() {
		if ( ! \is_admin_bar_showing() ) {
			return;
		}

		\wp_add_inline_style( // Adds to the admin bar's stylesheet.
			'admin-bar',
			'#wp-admin-bar-cloudflare .ab-item .cf-icon {
				width: 15px;
				height: 20px;
				padding: 4px;
				display: inline-block;
				background-image: url(' . \esc_url( \plugin_dir_url( __FILE__ ) . 'cloudflare_icon.svg' ) . ') !important;
				background-size: contain;
			}'
		);
	}

	/**
	 * Adds the JavaScript for the toolbar button.
	 *
	 * @return void
	 */
	public function add_toolbar_button_script() {
		$l10n = [
			'confirm_msg'     => \__( 'Are you sure you want to clear the Cloudflare cache?', 'pp-cf-utils' ),
			/* translators: %s is the URL of the page to clear the cache for. */
			'confirm_msg_url' => \__( 'Are you sure you want to clear the Cloudflare cache for %s ?', 'pp-cf-utils' ),
		];
		?>
		<script type="text/javascript">
			function clearCloudflareCache( url = '' ) {
				let confirm_msg = '<?php echo \esc_html( $l10n['confirm_msg'] ); ?>';
				if ( url !== '' ) {
					confirm_msg = '<?php echo \esc_html( $l10n['confirm_msg_url'] ); ?>'.replace( '%s', url );
				}
				if ( confirm( confirm_msg ) ) {
					fetch('<?php echo \esc_url( \admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: new URLSearchParams({
							url: url,
							action: 'clear_cloudflare_cache',
							nonce: '<?php echo \esc_js( \wp_create_nonce( 'clear_cloudflare_cache_nonce' ) ); ?>'
						})
					})
					.then(response => response.json())
					.then(data => {
						alert(data.data.message);
					})
					.catch(error => console.error('Error:', error));
				}
			}
		</script>
		<?php
	}

	/**
	 * AJAX handler to clear Cloudflare cache.
	 *
	 * @return void
	 */
	public function ajax_clear_cloudflare_cache() {
		\check_ajax_referer( 'clear_cloudflare_cache_nonce', 'nonce' );

		if ( isset( $_POST['url'] ) && ! empty( $_POST['url'] ) ) {
			$this->clear_cloudflare_cache( \sanitize_text_field( \wp_unslash( $_POST['url'] ) ) );
		} else {
			$this->clear_cloudflare_cache();
		}
		\wp_send_json_success( [ 'message' => \esc_html__( 'Cloudflare cache cleared successfully.', 'pp-cf-utils' ) ] );
	}

	/**
	 * Retrieves the Cloudflare Zone ID.
	 *
	 * @return string|null The Cloudflare Zone ID or null if not defined.
	 */
	public function get_cloudflare_zone_id() {
		if ( defined( 'CLOUDFLARE_ZONE_ID' ) ) {
			return CLOUDFLARE_ZONE_ID;
		}
		$settings = \get_option( 'pp_cf_utils_settings', [] );
		return isset( $settings['zone-id'] ) ? $settings['zone-id'] : null;
	}

	/**
	 * Retrieves the Cloudflare Email.
	 *
	 * @return string|null The Cloudflare Email or null if not defined.
	 */
	public function get_cloudflare_email() {
		if ( defined( 'CLOUDFLARE_EMAIL' ) ) {
			return CLOUDFLARE_EMAIL;
		}
		$settings = \get_option( 'pp_cf_utils_settings', [] );
		return isset( $settings['email'] ) ? $settings['email'] : null;
	}

	/**
	 * Retrieves the Cloudflare API Token.
	 *
	 * @return string|null The Cloudflare API Token or null if not defined.
	 */
	public function get_cloudflare_api_token() {
		if ( defined( 'CLOUDFLARE_API_TOKEN' ) ) {
			return CLOUDFLARE_API_TOKEN;
		}
		$settings = get_option( 'pp_cf_utils_settings', [] );
		return isset( $settings['api-token'] ) ? $settings['api-token'] : null;
	}

	/**
	 * Registers the admin menu for the plugin settings.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		\add_options_page(
			\esc_html__( 'Cloudflare', 'pp-cf-utils' ),
			\esc_html__( 'Cloudflare', 'pp-cf-utils' ),
			'manage_options',
			'pp-cloudflare-utils',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Registers settings, sections, and fields using the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		\register_setting(
			'pp_cf_utils_settings_group',
			'pp_cf_utils_settings',
			[
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);

		\add_settings_section(
			'pp_cf_utils_main_section',
			\esc_html__( 'Zone settings', 'pp-cf-utils' ),
			'__return_null',
			'pp-cloudflare-utils'
		);

		add_settings_field(
			'zone-id',
			\esc_html__( 'Zone ID', 'pp-cf-utils' ),
			[ $this, 'render_zone_id_field' ],
			'pp-cloudflare-utils',
			'pp_cf_utils_main_section'
		);

		add_settings_field(
			'email',
			\esc_html__( 'Email', 'pp-cf-utils' ),
			[ $this, 'render_email_field' ],
			'pp-cloudflare-utils',
			'pp_cf_utils_main_section'
		);

		add_settings_field(
			'api-token',
			\esc_html__( 'API Token', 'pp-cf-utils' ),
			[ $this, 'render_api_token_field' ],
			'pp-cloudflare-utils',
			'pp_cf_utils_main_section'
		);
	}

	/**
	 * Sanitizes the settings input.
	 *
	 * @param array<string, string> $input The input data to sanitize.
	 *
	 * @return array<string, string> The sanitized input data.
	 */
	public function sanitize_settings( $input ) {
		return [
			'zone-id'   => \sanitize_text_field( $input['zone-id'] ),
			'email'     => \sanitize_email( $input['email'] ),
			'api-token' => \sanitize_text_field( $input['api-token'] ),
		];
	}

	/**
	 * Renders a settings field.
	 *
	 * @param string $field The field name.
	 * @param string $type  The input type.
	 *
	 * @return void
	 */
	private function render_settings_field( $field, $type = 'text' ) {
		$settings = \get_option( 'pp_cf_utils_settings' );

		$constant_name = 'CLOUDFLARE_' . \strtoupper( \str_replace( '-', '_', $field ) );
		$value         = defined( $constant_name )
			? constant( $constant_name )
			: (
				isset( $settings[ $field ] )
					? \esc_attr( $settings[ $field ] )
					: ''
			);

		echo '<input
			type="' . \esc_attr( $type ) . '"
			name="pp_cf_utils_settings[' . \esc_attr( $field ) . ']"
			value="' . \esc_attr( $value ) . '"
			class="regular-text" ' . ( defined( $constant_name ) ? 'disabled' : '' ) . '
		/>';
	}

	/**
	 * Renders the Zone ID field.
	 *
	 * @return void
	 */
	public function render_zone_id_field() {
		$this->render_settings_field( 'zone-id' );
	}

	/**
	 * Renders the Email field.
	 *
	 * @return void
	 */
	public function render_email_field() {
		$this->render_settings_field( 'email', 'email' );
	}

	/**
	 * Renders the API Token field.
	 *
	 * @return void
	 */
	public function render_api_token_field() {
		$this->render_settings_field( 'api-token', 'password' );
	}

	/**
	 * Renders the settings page for the plugin.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Cloudflare settings', 'pp-cf-utils' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				\settings_fields( 'pp_cf_utils_settings_group' );
				\do_settings_sections( 'pp-cloudflare-utils' );
				\submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
