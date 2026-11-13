<?php
/**
 * Sermons: a post type with speakers and series, an audio file per sermon, an archive shortcode, and a podcast feed
 * that Apple Podcasts and Spotify accept (RSS 2.0 with iTunes tags and an enclosure per episode).
 *
 * @package Congregation
 */

namespace Congregation;

/**
 * Sermons.
 */
final class Sermons {

	public const TYPE = 'congregation_sermon';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'types' ) );
		add_action(
			'init',
			static function (): void {
				add_feed( 'sermons', array( self::class, 'feed' ) );
			}
		);
		add_shortcode( 'congregation_sermons', array( self::class, 'shortcode' ) );
		add_action( 'add_meta_boxes', array( self::class, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( self::class, 'save' ) );
	}

	/**
	 * Post type and taxonomies.
	 */
	public static function types(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Sermons', 'congregation' ),
					'singular_name' => __( 'Sermon', 'congregation' ),
					'add_new_item'  => __( 'Add sermon', 'congregation' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'sermons' ),
				'menu_icon'    => 'dashicons-microphone',
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
				'show_in_rest' => true,
			)
		);
		foreach ( array(
			'congregation_series'  => array( __( 'Series', 'congregation' ), 'series' ),
			'congregation_speaker' => array( __( 'Speakers', 'congregation' ), 'speaker' ),
		) as $tax => [ $label, $slug ] ) {
			register_taxonomy(
				$tax,
				self::TYPE,
				array(
					'label'             => $label,
					'rewrite'           => array( 'slug' => $slug ),
					'show_admin_column' => true,
					'show_in_rest'      => true,
				)
			);
		}
		foreach ( array( 'audio_url', 'scripture' ) as $key ) {
			register_post_meta(
				self::TYPE,
				'_congregation_' . $key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				)
			);
		}
		register_post_meta(
			self::TYPE,
			'_congregation_audio_bytes',
			array(
				'type'          => 'integer',
				'single'        => true,
				'auth_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			)
		);
	}

	/**
	 * Audio details box.
	 */
	public static function meta_box(): void {
		add_meta_box(
			'congregation-audio',
			__( 'Audio and scripture', 'congregation' ),
			static function ( \WP_Post $post ): void {
				wp_nonce_field( 'congregation_sermon', 'congregation_sermon_nonce' );
				echo '<p><label>' . esc_html__( 'Audio file URL (MP3)', 'congregation' ) . '<br><input type="url" class="widefat" name="congregation_audio_url" value="' . esc_attr( (string) get_post_meta( $post->ID, '_congregation_audio_url', true ) ) . '"></label></p>';
				echo '<p><label>' . esc_html__( 'File size in bytes (needed by podcast apps; filled in automatically for files in the media library)', 'congregation' ) . '<br><input type="number" min="0" name="congregation_audio_bytes" value="' . esc_attr( (string) get_post_meta( $post->ID, '_congregation_audio_bytes', true ) ) . '"></label></p>';
				echo '<p><label>' . esc_html__( 'Scripture', 'congregation' ) . '<br><input class="widefat" name="congregation_scripture" placeholder="John 3:1-21" value="' . esc_attr( (string) get_post_meta( $post->ID, '_congregation_scripture', true ) ) . '"></label></p>';
			},
			self::TYPE,
			'side'
		);
	}

	/**
	 * Save the box.
	 *
	 * @param int $post_id Post.
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST['congregation_sermon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['congregation_sermon_nonce'] ) ), 'congregation_sermon' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$url   = isset( $_POST['congregation_audio_url'] ) ? esc_url_raw( wp_unslash( $_POST['congregation_audio_url'] ) ) : '';
		$bytes = isset( $_POST['congregation_audio_bytes'] ) ? absint( $_POST['congregation_audio_bytes'] ) : 0;
		if ( '' !== $url && 0 === $bytes ) {
			$att = attachment_url_to_postid( $url );
			$fil = $att ? get_attached_file( $att ) : false;
			if ( is_string( $fil ) && is_readable( $fil ) ) {
				$bytes = (int) filesize( $fil );
			}
		}
		update_post_meta( $post_id, '_congregation_audio_url', $url );
		update_post_meta( $post_id, '_congregation_audio_bytes', $bytes );
		update_post_meta( $post_id, '_congregation_scripture', isset( $_POST['congregation_scripture'] ) ? sanitize_text_field( wp_unslash( $_POST['congregation_scripture'] ) ) : '' );
	}

	/**
	 * Names of a post's terms.
	 *
	 * @param int    $id  Post.
	 * @param string $tax Taxonomy.
	 */
	private static function terms( int $id, string $tax ): string {
		$t = get_the_terms( $id, $tax );
		return is_array( $t ) ? implode( ', ', wp_list_pluck( $t, 'name' ) ) : '';
	}

	/**
	 * [congregation_sermons series="slug" count="10"]: latest sermons with a player.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$a     = shortcode_atts(
			array(
				'series' => '',
				'count'  => '10',
			),
			is_array( $atts ) ? $atts : array(),
			'congregation_sermons'
		);
		$query = array(
			'post_type'      => self::TYPE,
			'posts_per_page' => max( 1, min( 50, (int) $a['count'] ) ),
			'post_status'    => 'publish',
		);
		if ( '' !== $a['series'] ) {
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filtering by series is the point.
				array(
					'taxonomy' => 'congregation_series',
					'field'    => 'slug',
					'terms'    => $a['series'],
				),
			);
		}
		$out = '<div class="congregation-sermons">';
		foreach ( get_posts( $query ) as $p ) {
			$audio = (string) get_post_meta( $p->ID, '_congregation_audio_url', true );
			$meta  = array_filter( array( self::terms( $p->ID, 'congregation_speaker' ), self::terms( $p->ID, 'congregation_series' ), (string) get_post_meta( $p->ID, '_congregation_scripture', true ), get_the_date( '', $p ) ) );
			$out  .= '<article class="congregation-sermon"><h3><a href="' . esc_url( (string) get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3><p class="congregation-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>' . ( '' !== $audio ? wp_audio_shortcode( array( 'src' => $audio ) ) : '' ) . '</article>';
		}
		return $out . '<p><a href="' . esc_url( get_feed_link( 'sermons' ) ) . '">' . esc_html__( 'Subscribe as a podcast', 'congregation' ) . '</a></p></div>';
	}

	/**
	 * The podcast feed at /feed/sermons/.
	 */
	public static function feed(): void {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'posts_per_page' => 100,
				'post_status'    => 'publish',
			)
		);
		$name  = Settings::get()['church'];
		$icon  = get_site_icon_url( 1400 );
		header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );
		$x = new \XMLWriter();
		$x->openMemory();
		$x->startDocument( '1.0', 'UTF-8' );
		$x->startElement( 'rss' );
		$x->writeAttribute( 'version', '2.0' );
		$x->writeAttribute( 'xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
		$x->writeAttribute( 'xmlns:atom', 'http://www.w3.org/2005/Atom' );
		$x->startElement( 'channel' );
		$x->writeElement( 'title', $name . ' ' . __( 'Sermons', 'congregation' ) );
		$x->writeElement( 'link', home_url( '/' ) );
		$x->writeElement( 'language', str_replace( '_', '-', get_locale() ) );
		$tagline = (string) get_bloginfo( 'description' );
		$x->writeElement( 'description', '' !== $tagline ? $tagline : $name );
		$x->startElement( 'atom:link' );
		$x->writeAttribute( 'href', get_feed_link( 'sermons' ) );
		$x->writeAttribute( 'rel', 'self' );
		$x->writeAttribute( 'type', 'application/rss+xml' );
		$x->endElement();
		$x->writeElement( 'itunes:author', $name );
		$x->writeElement( 'itunes:explicit', 'false' );
		$x->startElement( 'itunes:category' );
		$x->writeAttribute( 'text', 'Religion & Spirituality' );
		$x->startElement( 'itunes:category' );
		$x->writeAttribute( 'text', 'Christianity' );
		$x->endElement();
		$x->endElement();
		if ( $icon ) {
			$x->startElement( 'itunes:image' );
			$x->writeAttribute( 'href', $icon );
			$x->endElement();
		}
		foreach ( $posts as $p ) {
			$audio = (string) get_post_meta( $p->ID, '_congregation_audio_url', true );
			if ( '' === $audio ) {
				continue; // Podcast apps reject items without media.
			}
			$x->startElement( 'item' );
			$x->writeElement( 'title', get_the_title( $p ) );
			$x->writeElement( 'link', (string) get_permalink( $p ) );
			$x->startElement( 'guid' );
			$x->writeAttribute( 'isPermaLink', 'false' );
			$x->text( 'congregation-sermon-' . $p->ID );
			$x->endElement();
			$x->writeElement( 'pubDate', (string) get_post_time( 'D, d M Y H:i:s +0000', true, $p ) );
			$x->writeElement( 'description', wp_strip_all_tags( get_the_excerpt( $p ) ) );
			$speaker = self::terms( $p->ID, 'congregation_speaker' );
			$x->writeElement( 'itunes:author', '' !== $speaker ? $speaker : $name );
			$x->startElement( 'enclosure' );
			$x->writeAttribute( 'url', $audio );
			$x->writeAttribute( 'length', (string) (int) get_post_meta( $p->ID, '_congregation_audio_bytes', true ) );
			$x->writeAttribute( 'type', 'audio/mpeg' );
			$x->endElement();
			$x->endElement();
		}
		$x->endElement();
		$x->endElement();
		echo $x->outputMemory(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XMLWriter escapes every value.
	}
}
