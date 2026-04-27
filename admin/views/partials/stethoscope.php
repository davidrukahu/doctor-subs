<?php
/**
 * Doctor Subs brand mark.
 *
 * A circle with a pulse waveform inside - reads as "subscription cycle +
 * health signal" in a single line mark. Replaces the earlier literal
 * stethoscope. Filename kept for backwards compat during v2 integration.
 *
 * @package Dr_Subs
 * @since   2.0.0
 *
 * @var int $size Optional size in pixels. Defaults to 22.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Partial is included from DR_Subs_Admin::load_view(); $size is scoped to that
// method's call frame, not global.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$size = isset( $size ) ? absint( $size ) : 22;
?>
<svg width="<?php echo esc_attr( $size ); ?>" height="<?php echo esc_attr( $size ); ?>"
	viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
	stroke-linecap="round" stroke-linejoin="round"
	aria-hidden="true" class="ds-mark">
	<circle cx="12" cy="12" r="9.5" />
	<path d="M6.5 12 H9 L10.5 8.5 L13 15.5 L14.5 11 L16 12 H17.5" />
</svg>
