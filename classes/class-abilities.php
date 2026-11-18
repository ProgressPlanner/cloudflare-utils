<?php
/**
 * WordPress abilities for Cloudflare Utils.
 *
 * @package PP_Cloudflare_Utils
 */

namespace PP_Cloudflare_Utils;

/**
 * Exposes administrative actions and describes automatic request behavior.
 */
class Abilities {
	/**
	 * Plugin service shared with the existing hooks and toolbar.
	 *
	 * @var Base
	 */
	private $base;

	/**
	 * Register hooks without requiring the Abilities API on older WordPress versions.
	 *
	 * @param Base $base Plugin service.
	 */
	public function __construct( Base $base ) {
		$this->base = $base;
		\add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		\add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the plugin's ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			\wp_register_ability_category(
				'cloudflare-utils',
				[
					'label'       => \__( 'Cloudflare Utils', 'pp-cf-utils' ),
					'description' => \__( 'Manage Cloudflare cache and connection settings, and inspect automatic plugin behavior.', 'pp-cf-utils' ),
				]
			);
		}
	}

	/**
	 * Register all externally useful plugin functionality.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$settings_schema = $this->settings_schema();
		$this->register(
			'get-settings',
			\__( 'Get Cloudflare settings', 'pp-cf-utils' ),
			\__( 'Read effective zone and email settings, token presence, and fields controlled by constants. Never returns the API token. Configuration presence does not prove Cloudflare connectivity.', 'pp-cf-utils' ),
			'get_settings',
			null,
			$settings_schema,
			true,
			false,
			true
		);
		$this->register(
			'update-settings',
			\__( 'Update Cloudflare settings', 'pp-cf-utils' ),
			\__( 'Update supplied connection fields; omitted fields are preserved. Empty strings clear fields. Fields defined by constants cannot be changed. The token is write-only.', 'pp-cf-utils' ),
			'update_settings',
			[
				'type'                 => 'object',
				'properties'           => [
					'zone-id'   => [
						'type'    => 'string',
						'pattern' => '^([a-fA-F0-9]{32})?$',
					],
					'email'     => [ 'type' => 'string' ],
					'api-token' => [ 'type' => 'string' ],
				],
				'minProperties'        => 1,
				'additionalProperties' => false,
			],
			$settings_schema,
			false,
			true,
			true
		);
		$this->register(
			'get-behavior',
			\__( 'Get Cloudflare plugin behavior', 'pp-cf-utils' ),
			\__( 'Describe cache headers and tags, private response exclusions, automatic post and comment purges, comment cookie handling, and exposed credential protection. These are automatic hooks, not configurable switches or a live Cloudflare audit.', 'pp-cf-utils' ),
			'get_behavior',
			null,
			[
				'type'                 => 'object',
				'properties'           => [
					'public_cache_seconds' => [ 'type' => 'integer' ],
					'cache_tags'           => [ 'type' => 'string' ],
					'private_responses'    => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'redirects'            => [ 'type' => 'string' ],
					'automatic_purges'     => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'comments'             => [ 'type' => 'string' ],
					'credentials'          => [ 'type' => 'string' ],
				],
				'required'             => [ 'public_cache_seconds', 'cache_tags', 'private_responses', 'redirects', 'automatic_purges', 'comments', 'credentials' ],
				'additionalProperties' => false,
			],
			true,
			false,
			true
		);

		$purge_schema = [
			'type'                 => 'object',
			'properties'           => [
				'success' => [ 'type' => 'boolean' ],
				'scope'   => [
					'type' => 'string',
					'enum' => [ 'url', 'zone' ],
				],
				'url'     => [ 'type' => 'string' ],
			],
			'required'             => [ 'success', 'scope', 'url' ],
			'additionalProperties' => false,
		];
		$this->register(
			'purge-url',
			\__( 'Purge Cloudflare cache for a URL', 'pp-cf-utils' ),
			\__( 'Purge one absolute HTTP or HTTPS URL on this WordPress site. Cloudflare must confirm success. Custom cache keys may require additional purges outside this plugin.', 'pp-cf-utils' ),
			'purge_url',
			[
				'type'                 => 'object',
				'properties'           => [
					'url' => [
						'type'      => 'string',
						'format'    => 'uri',
						'minLength' => 1,
					],
				],
				'required'             => [ 'url' ],
				'additionalProperties' => false,
			],
			$purge_schema,
			false,
			false,
			true
		);
		$this->register(
			'purge-all',
			\__( 'Purge the entire Cloudflare zone cache', 'pp-cf-utils' ),
			\__( 'Clear ALL cached content in the configured Cloudflare zone, including other sites sharing it. Requires confirm=true. Prefer purge-url when possible.', 'pp-cf-utils' ),
			'purge_all',
			[
				'type'                 => 'object',
				'properties'           => [
					'confirm' => [
						'type' => 'boolean',
						'enum' => [ true ],
					],
				],
				'required'             => [ 'confirm' ],
				'additionalProperties' => false,
			],
			$purge_schema,
			false,
			true,
			true
		);
	}

	/**
	 * Register an ability with shared permissions and metadata.
	 *
	 * @param string                   $name        Ability suffix.
	 * @param string                   $label       Ability label.
	 * @param string                   $description Ability description.
	 * @param string                   $callback    Callback method.
	 * @param array<string,mixed>|null $input       Input schema.
	 * @param array<string,mixed>      $output      Output schema.
	 * @param bool                     $read_only   Whether the ability only reads.
	 * @param bool                     $destructive Whether the ability may cause hard to undo changes, so clients should confirm first.
	 * @param bool                     $idempotent  Whether repeat execution has no additional effect.
	 *
	 * @return void
	 */
	private function register( $name, $label, $description, $callback, $input, $output, $read_only, $destructive, $idempotent ) {
		\wp_register_ability(
			'cloudflare-utils/' . $name,
			[
				'label'               => $label,
				'description'         => $description,
				'category'            => 'cloudflare-utils',
				'input_schema'        => $input ?? [],
				'output_schema'       => $output,
				'execute_callback'    => [ $this, $callback ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => $read_only,
						'destructive' => $destructive,
						'idempotent'  => $idempotent,
					],
				],
			]
		);
	}

	/**
	 * Require the same capability as the settings and toolbar UI.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return \current_user_can( 'manage_options' );
	}

	/**
	 * Get the redacted settings output schema.
	 *
	 * @return array<string,mixed>
	 */
	private function settings_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'zone-id'       => [ 'type' => 'string' ],
				'email'         => [ 'type' => 'string' ],
				'has-api-token' => [ 'type' => 'boolean' ],
				'configured'    => [ 'type' => 'boolean' ],
				'locked-fields' => [
					'type'  => 'array',
					'items' => [
						'type' => 'string',
						'enum' => [ 'zone-id', 'email', 'api-token' ],
					],
				],
			],
			'required'             => [ 'zone-id', 'email', 'has-api-token', 'configured', 'locked-fields' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Read effective settings without revealing credentials.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$locked = [];
		foreach ( [ 'zone-id', 'email', 'api-token' ] as $field ) {
			if ( defined( $this->constant_name( $field ) ) ) {
				$locked[] = $field;
			}
		}
		return [
			'zone-id'       => (string) $this->base->get_cloudflare_zone_id(),
			'email'         => (string) $this->base->get_cloudflare_email(),
			'has-api-token' => (bool) $this->base->get_cloudflare_api_token(),
			'configured'    => (bool) ( $this->base->get_cloudflare_zone_id() && $this->base->get_cloudflare_api_token() ),
			'locked-fields' => $locked,
		];
	}

	/**
	 * Partially update settings, validating everything before saving.
	 *
	 * @param array<string,string> $input Fields to update.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update_settings( $input ) {
		foreach ( $input as $field => $value ) {
			if ( defined( $this->constant_name( $field ) ) ) {
				return new \WP_Error( 'cloudflare_settings_locked', \__( 'A supplied field is controlled by a constant and cannot be changed.', 'pp-cf-utils' ) );
			}
			if ( $field === 'email' && $value !== '' && ! \is_email( $value ) ) {
				return new \WP_Error( 'cloudflare_invalid_email', \__( 'Supply a valid email address or an empty string.', 'pp-cf-utils' ) );
			}
		}
		$settings = \get_option( 'pp_cf_utils_settings', [] );
		$settings = array_merge(
			[
				'zone-id'   => '',
				'email'     => '',
				'api-token' => '',
			],
			$settings,
			$input
		);
		$settings = $this->base->sanitize_settings( $settings );
		\update_option( 'pp_cf_utils_settings', $settings );
		if ( \get_option( 'pp_cf_utils_settings' ) !== $settings ) {
			return new \WP_Error( 'cloudflare_settings_save_failed', \__( 'Cloudflare settings could not be saved.', 'pp-cf-utils' ) );
		}
		return $this->get_settings();
	}

	/**
	 * Get the constant corresponding to a settings field.
	 *
	 * @param string $field Settings field.
	 *
	 * @return string
	 */
	private function constant_name( $field ) {
		return 'CLOUDFLARE_' . strtoupper( str_replace( '-', '_', $field ) );
	}

	/**
	 * Describe existing automatic behavior without running request hooks.
	 *
	 * @return array<string,mixed>
	 */
	public function get_behavior() {
		return [
			'public_cache_seconds' => 365 * DAY_IN_SECONDS,
			'cache_tags'           => 'WordPress body classes; PP-Cache-Tag is also emitted when WP_DEBUG is enabled.',
			'private_responses'    => [ 'Logged-in users', 'Admin and login requests', 'EDD checkout', 'WooCommerce cart, checkout, account and endpoints', 'Explicit send_no_cache_headers action' ],
			'redirects'            => '301 redirects receive one-year public cache headers and have Content-Type and cache tags removed.',
			'automatic_purges'     => [ 'Published post updates purge the post URL and home URL', 'Immediately approved new comments purge the post URL', 'Comment approval purges the post URL' ],
			'comments'             => 'Commenter values are blank server-side; browser JavaScript stores and fills comment form cookies. Held comments redirect to the post permalink.',
			'credentials'          => 'An Exposed-Credential-Check request header at login logs the user out and redirects to password reset with a breach notice.',
		];
	}

	/**
	 * Purge one URL belonging to this site.
	 *
	 * @param array<string,string> $input URL input.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function purge_url( $input ) {
		$url   = $input['url'];
		$parts = \wp_parse_url( $url );
		$home  = \wp_parse_url( \home_url() );
		if ( ! is_array( $parts ) || ! is_array( $home ) ||
			! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) ||
			strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ||
			( $parts['port'] ?? null ) !== ( $home['port'] ?? null )
		) {
			return new \WP_Error( 'cloudflare_invalid_url', \__( 'Supply an HTTP or HTTPS URL on this site without credentials or a fragment.', 'pp-cf-utils' ) );
		}
		return $this->purge( $url );
	}

	/**
	 * Purge the entire configured zone after explicit confirmation.
	 *
	 * @param array<string,bool> $input Confirmation input.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function purge_all( $input ) {
		if ( ( $input['confirm'] ?? false ) !== true ) {
			return new \WP_Error( 'cloudflare_confirmation_required', \__( 'Confirm the full zone purge with confirm=true.', 'pp-cf-utils' ) );
		}
		return $this->purge();
	}

	/**
	 * Execute a purge using the shared service and return a structured result.
	 *
	 * @param string|null $url URL or null for the whole zone.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function purge( $url = null ) {
		if ( ! $this->base->get_cloudflare_zone_id() || ! $this->base->get_cloudflare_api_token() ) {
			return new \WP_Error( 'cloudflare_not_configured', \__( 'Set a Cloudflare zone ID and API token before purging.', 'pp-cf-utils' ) );
		}
		if ( $this->base->clear_cloudflare_cache( $url ) !== 200 ) {
			return new \WP_Error( 'cloudflare_purge_failed', \__( 'Cloudflare did not confirm the cache purge. Check connection settings and token permissions.', 'pp-cf-utils' ) );
		}
		return [
			'success' => true,
			'scope'   => $url === null ? 'zone' : 'url',
			'url'     => $url ?? '',
		];
	}
}
