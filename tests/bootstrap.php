<?php
/**
 * Load WordPress core libraries without a database or a live site.
 *
 * @package PP_Cloudflare_Utils
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 */

$pp_cf_utils_core = getenv( 'WP_CORE_DIR' );
if ( ! $pp_cf_utils_core || ! is_file( $pp_cf_utils_core . '/wp-includes/abilities-api.php' ) ) {
	fwrite( STDERR, "Set WP_CORE_DIR to a WordPress 6.9+ source directory. No site database is used.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';

define( 'ABSPATH', rtrim( $pp_cf_utils_core, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
require_once ABSPATH . WPINC . '/compat.php';
require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/class-wp-error.php';
require_once ABSPATH . WPINC . '/load.php';
require_once ABSPATH . WPINC . '/formatting.php';
require_once ABSPATH . WPINC . '/functions.php';
require_once ABSPATH . WPINC . '/rest-api.php';
require_once ABSPATH . WPINC . '/http.php';
foreach ( [ 'class-wp-ability', 'class-wp-ability-category', 'class-wp-ability-categories-registry', 'class-wp-abilities-registry' ] as $pp_cf_utils_class ) {
	require_once ABSPATH . WPINC . '/abilities-api/' . $pp_cf_utils_class . '.php';
}
require_once ABSPATH . WPINC . '/abilities-api.php';
require_once dirname( __DIR__ ) . '/classes/class-base.php';
require_once dirname( __DIR__ ) . '/classes/class-abilities.php';
