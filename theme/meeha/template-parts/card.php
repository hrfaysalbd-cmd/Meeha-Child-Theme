<?php
/**
 * Story card (`m-story`). Called through meeha_card() — never include directly.
 * One link per card (the title, stretched with ::after); the image link is aria-hidden + tabindex -1.
 *
 * @package meeha
 *
 * @var array $args See meeha_card().
 */

defined( 'ABSPATH' ) || exit;

$meeha_p = isset( $args['post'] ) ? get_post( $args['post'] ) : null;
if ( ! $meeha_p ) {
	return;
}
$meeha_a    = meeha_card_args( $args );
$meeha_vars = preg_split( '/\s+/', trim( (string) $meeha_a['variant'] ) );
$meeha_cls  = 'm-story';
foreach ( $meeha_vars as $meeha_v ) {
	if ( '' !== $meeha_v && 'sm' !== $meeha_v ) {
		$meeha_cls .= ' m-story--' . sanitize_html_class( $meeha_v );
	}
}
if ( in_array( 'sm', $meeha_vars, true ) ) {
	$meeha_cls .= ' m-story--sm';
}
if ( $meeha_a['class'] ) {
	$meeha_cls .= ' ' . $meeha_a['class'];
}
$meeha_url   = get_permalink( $meeha_p );
$meeha_title = get_the_title( $meeha_p );
$meeha_inv   = meeha_is_investigative( $meeha_p );
$meeha_vf    = null === $meeha_a['viewfinder'] ? ( $meeha_inv && (bool) array_intersect( $meeha_vars, array( 'lead', 'big', 'side-lg', 'feature' ) ) ) : (bool) $meeha_a['viewfinder'];
$meeha_flag  = (string) $meeha_a['flag'];
if ( '' === $meeha_flag && 'meeha_video' === $meeha_p->post_type ) {
	$meeha_flag = meeha_icon( 'play', array( 'size' => 'sm' ) ) . 'ভিডিও';
} elseif ( '' !== $meeha_flag ) {
	$meeha_flag = esc_html( $meeha_flag );
}
$meeha_img = '';
if ( $meeha_a['show_image'] ) {
	$meeha_img = meeha_post_img( $meeha_p, $meeha_a['img'], $meeha_a['eager'] ? array( 'eager' => true ) : array() );
}
$meeha_h = 'h' . (int) $meeha_a['heading_level'];
?>
<article class="<?php echo esc_attr( $meeha_cls ); ?>">
<?php if ( $meeha_img ) : ?>
	<a class="m-story__media" href="<?php echo esc_url( $meeha_url ); ?>" tabindex="-1" aria-hidden="true"><?php
	if ( '' !== $meeha_flag ) {
		echo '<span class="m-story__flag">' . $meeha_flag . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}
	?><div class="m-media<?php echo $meeha_a['ratio'] ? ' m-media--' . esc_attr( $meeha_a['ratio'] ) : ''; ?><?php echo $meeha_vf ? ' m-viewfinder' : ''; ?>"><?php echo $meeha_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></div></a>
<?php endif; ?>
	<div class="m-story__body">
	<?php
	if ( $meeha_a['show_kicker'] ) {
		$meeha_k = meeha_post_kicker( $meeha_p );
		if ( '' !== $meeha_k ) {
			$meeha_cat  = meeha_primary_category( $meeha_p );
			$meeha_kcls = 'm-kicker m-story__kicker' . ( $meeha_inv ? ' m-kicker--inv' : '' );
			if ( $meeha_cat ) {
				printf( '<a class="%s" href="%s">%s</a>', esc_attr( $meeha_kcls ), esc_url( get_category_link( $meeha_cat ) ), esc_html( $meeha_k ) );
			} else {
				printf( '<span class="%s">%s</span>', esc_attr( $meeha_kcls ), esc_html( $meeha_k ) );
			}
		}
	}
	printf( '<%1$s class="m-story__title"><a href="%2$s">%3$s</a></%1$s>', $meeha_h, esc_url( $meeha_url ), esc_html( $meeha_title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( $meeha_a['show_dek'] ) {
		$meeha_dek = meeha_post_dek( $meeha_p, (int) $meeha_a['dek_words'] );
		if ( '' !== $meeha_dek ) {
			echo '<p class="m-story__dek">' . esc_html( $meeha_dek ) . '</p>';
		}
	}
	if ( $meeha_a['show_meta'] ) {
		echo '<div class="m-meta">';
		printf( '<time datetime="%s">%s</time>', esc_attr( get_post_time( 'c', true, $meeha_p ) ), esc_html( meeha_post_time_ago( $meeha_p ) ) );
		if ( 'author' === $meeha_a['show_meta'] ) {
			echo '<span>' . esc_html( meeha_post_byline( $meeha_p )['name'] ) . '</span>'; // 2.0.3: guest author aware.
		}
		echo '</div>';
	}
	?>
	</div>
</article>
