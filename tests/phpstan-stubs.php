<?php
/**
 * Abilities API symbols absent from the locked WordPress 6.7 analysis stubs.
 * Only loaded by PHPStan, never at runtime.
 *
 * @package PP_Cloudflare_Utils
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter
 */

/**
 * Register an ability on WordPress versions that provide the API.
 *
 * @param string              $name Ability name.
 * @param array<string,mixed> $args Ability definition.
 *
 * @return object|null
 */
function wp_register_ability( string $name, array $args ): ?object {
	return null;
}
