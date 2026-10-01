<?php
/**
 * Abilities integration tests using real WordPress registration and schemas.
 *
 * @package PP_Cloudflare_Utils
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PP_Cloudflare_Utils\Base;

/**
 * Exercise abilities without a database or real Cloudflare requests.
 */
class AbilitiesTest extends PHPUnit\Framework\TestCase {
	/**
	 * In-memory settings.
	 *
	 * @var array<string,string>
	 */
	private $settings;

	/**
	 * Mock HTTP response.
	 *
	 * @var array|WP_Error
	 */
	private $response;

	/**
	 * Captured HTTP request bodies.
	 *
	 * @var array
	 */
	private $requests;

	/**
	 * Administrator flag.
	 *
	 * @var bool
	 */
	private $admin;

	/**
	 * Set up core hooks, API registries and mocked environment services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['wp_filter']         = [];
		$GLOBALS['wp_actions']        = [];
		$GLOBALS['wp_current_filter'] = [];
		foreach ( [ WP_Abilities_Registry::class, WP_Ability_Categories_Registry::class ] as $class ) {
			$property = new ReflectionProperty( $class, 'instance' );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
		$this->settings = [
			'zone-id'   => str_repeat( 'a', 32 ),
			'email'     => 'admin@example.com',
			'api-token' => 'test-secret',
		];
		$this->response = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"success":true}',
		];
		$this->requests = [];
		$this->admin    = true;
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 1 );
		Functions\when( '_doing_it_wrong' )->alias(
			function ( $function_name, $message ) {
				throw new RuntimeException( esc_html( $function_name . ': ' . $message ) );
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) {
				return $capability === 'manage_options' && $this->admin;
			}
		);
		Functions\when( 'get_option' )->alias(
			function () {
				return $this->settings;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->settings = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->requests[] = [
					'endpoint' => $url,
					'body'     => json_decode( $args['body'], true ),
				];
				return $this->response;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return $response['body'];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'];
			}
		);
		new Base();
		do_action( 'init' );
	}

	/**
	 * Restore mocked functions.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Check lazy registration, discovery metadata and administrator enforcement.
	 *
	 * @return void
	 */
	public function test_registration_and_permissions(): void {
		$abilities = wp_get_abilities();
		$this->assertCount( 5, $abilities );
		foreach ( $abilities as $ability ) {
			$this->assertSame( 'cloudflare-utils', $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
			$this->assertTrue( $ability->get_meta_item( 'mcp' )['public'] );
			$this->assertTrue( $ability->check_permissions() );
			$this->admin = false;
			$this->assertFalse( $ability->check_permissions() );
			$this->admin = true;
		}
		$this->admin = false;
		$result      = wp_get_ability( 'cloudflare-utils/purge-all' )->execute( [ 'confirm' => true ] );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Check read outputs, partial settings updates and schema validation.
	 *
	 * @return void
	 */
	public function test_settings_and_behavior(): void {
		$read   = wp_get_ability( 'cloudflare-utils/get-settings' );
		$update = wp_get_ability( 'cloudflare-utils/update-settings' );
		$this->assertTrue( $read->execute()['configured'] );
		$this->assertStringNotContainsString( 'test-secret', wp_json_encode( $read->execute() ) );
		$result = $update->execute( [ 'email' => 'new@example.com' ] );
		$this->assertSame( 'new@example.com', $result['email'] );
		$this->assertSame( 'test-secret', $this->settings['api-token'] );
		$this->assertSame( $result, $update->execute( [ 'email' => 'new@example.com' ] ) );
		$this->assertSame( 'cloudflare_invalid_email', $update->execute( [ 'email' => 'bad' ] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $update->execute( [ 'zone-id' => 'bad' ] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $update->execute( [ 'unknown' => 'bad' ] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $update->execute( [] )->get_error_code() );
		$this->assertFalse( $update->execute( [ 'api-token' => '' ] )['configured'] );
		$this->assertSame( 'cloudflare_not_configured', wp_get_ability( 'cloudflare-utils/purge-all' )->execute( [ 'confirm' => true ] )->get_error_code() );
		$this->assertSame( 31536000, wp_get_ability( 'cloudflare-utils/get-behavior' )->execute()['public_cache_seconds'] );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Check purge scopes, validation and errors returned by Cloudflare.
	 *
	 * @return void
	 */
	public function test_purges(): void {
		$url = wp_get_ability( 'cloudflare-utils/purge-url' );
		$all = wp_get_ability( 'cloudflare-utils/purge-all' );
		foreach ( [ '', 'https://other.example/post', 'ftp://example.com/post', 'https://user:pass@example.com/post', 'https://example.com/post#fragment', 'https://example.com:1234/post' ] as $invalid ) {
			$this->assertInstanceOf( WP_Error::class, $url->execute( [ 'url' => $invalid ] ) );
		}
		foreach ( [
			[],
			[ 'confirm' => false ],
			[ 'confirm' => 'true' ],
			[
				'confirm' => true,
				'extra'   => true,
			],
		] as $invalid ) {
			$this->assertInstanceOf( WP_Error::class, $all->execute( $invalid ) );
		}
		$this->assertSame( [], $this->requests );
		$this->assertSame( 'url', $url->execute( [ 'url' => 'https://example.com/post?x=1' ] )['scope'] );
		$this->assertSame( [ 'files' => [ 'https://example.com/post?x=1' ] ], $this->requests[0]['body'] );
		$this->assertSame( 'zone', $all->execute( [ 'confirm' => true ] )['scope'] );
		$this->assertSame( [ 'purge_everything' => true ], $this->requests[1]['body'] );
		foreach ( [ '{"success":false}', 'invalid', '{}' ] as $body ) {
			$this->response['body'] = $body;
			$this->assertSame( 'cloudflare_purge_failed', $all->execute( [ 'confirm' => true ] )->get_error_code() );
		}
		$this->response['response']['code'] = 403;
		$this->assertSame( 'cloudflare_purge_failed', $all->execute( [ 'confirm' => true ] )->get_error_code() );
		$this->response = new WP_Error( 'http_request_failed', 'Connection failed' );
		$this->assertSame( 'cloudflare_purge_failed', $all->execute( [ 'confirm' => true ] )->get_error_code() );
	}

	/**
	 * Check Cloudflare's error details reach the error log without credentials.
	 *
	 * @return void
	 */
	public function test_purge_errors_are_logged(): void {
		$all      = wp_get_ability( 'cloudflare-utils/purge-all' );
		$log_file = (string) tempnam( sys_get_temp_dir(), 'cf-utils-log' );
		$previous = ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$this->response = [
			'response' => [ 'code' => 403 ],
			'body'     => '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}',
		];
		$all->execute( [ 'confirm' => true ] );
		$this->response['response']['code'] = 200;
		$this->response['body']             = '{"success":false,"errors":[{"code":1012,"message":"Request must contain one of purge_everything or files"}]}';
		$all->execute( [ 'confirm' => true ] );
		$this->response['body'] = 'invalid';
		$all->execute( [ 'confirm' => true ] );

		ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$log = (string) file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertStringContainsString( 'response code: 403 - [10000] Authentication error', $log );
		$this->assertStringContainsString( 'not confirmed by Cloudflare - [1012] Request must contain', $log );
		$this->assertStringContainsString( 'not confirmed by Cloudflare (response body is not valid JSON)', $log );
		$this->assertStringNotContainsString( 'test-secret', $log );
		$this->assertStringNotContainsString( 'admin@example.com', $log );
	}

	/**
	 * Check effective constant precedence and reject a locked update atomically.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_constant_settings(): void {
		define( 'CLOUDFLARE_ZONE_ID', str_repeat( 'b', 32 ) );
		define( 'CLOUDFLARE_API_TOKEN', 'constant-secret' );
		$read = wp_get_ability( 'cloudflare-utils/get-settings' )->execute();
		$this->assertSame( CLOUDFLARE_ZONE_ID, $read['zone-id'] );
		$this->assertSame( [ 'zone-id', 'api-token' ], $read['locked-fields'] );
		$this->assertStringNotContainsString( 'constant-secret', wp_json_encode( $read ) );
		$result = wp_get_ability( 'cloudflare-utils/update-settings' )->execute(
			[
				'email'     => 'new@example.com',
				'api-token' => 'replacement',
			]
		);
		$this->assertSame( 'cloudflare_settings_locked', $result->get_error_code() );
		$this->assertSame( 'admin@example.com', $this->settings['email'] );
	}
}
