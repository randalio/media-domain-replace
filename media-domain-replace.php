<?php
/**
 * Plugin Name:       Media Domain Replace
 * Description:       Rewrites media URLs from your local domain to a remote (staging/production) domain, so a local site can display uploads that only exist on the live server. Files uploaded locally keep their own URLs and can be watermarked so they are obvious at a glance.
 * Version:           1.4.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Randal Traicoff
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-domain-replace
 *
 * Bundles Poppins SemiBold by the Poppins Project Authors, used under the
 * SIL Open Font License 1.1. See fonts/OFL.txt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDR_PLUGIN_FILE', __FILE__ );
define( 'MDR_VERSION', '1.4.0' );

final class MDR_Media_Domain_Replace {

	const OPTION      = 'mdr_settings';
	const META_LOCAL  = '_mdr_local_upload';
	const META_MARKED = '_mdr_watermarked';

	/**
	 * Runtime caches.
	 */
	private static $local_host     = null;
	private static $remote_host    = null;
	private static $uploads_path   = null;
	private static $uploads_dir    = null;
	private static $should_run     = null;
	private static $file_exists    = array();

	/**
	 * Default settings.
	 */
	public static function defaults() {
		return array(
			'enabled'             => 1,
			'remote_domain'       => '',
			'local_domain'        => '',
			'uploads_only'        => 1,
			'apply_in_admin'      => 1,
			'preserve_local'      => 1,
			'watermark_enabled'      => 1,
			'watermark_style'        => 'text',
			'watermark_text'         => 'LOCAL ONLY',
			'watermark_bands'        => array( 'diagonal' ),
			'watermark_angle'        => 13,
			'watermark_opacity'      => 55,
			'watermark_image'        => '',
			'watermark_image_scale'  => 40,
			'watermark_image_place'  => 'center',
			'watermark_image_rotate' => 0,
			'watermark_original'     => 1,
			'watermark_font_face'    => 'poppins',
			'watermark_font'         => '',
			'migrate_theme'          => '',
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_action( 'after_switch_theme', array( __CLASS__, 'migrate_menu_locations' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MDR_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'add_filters' ) );

		// Local upload handling.
		add_action( 'add_attachment', array( __CLASS__, 'flag_local_upload' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'watermark_on_upload' ), 10, 3 );

		// Media library indicator + batch tool.
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column_content' ), 10, 2 );
		add_action( 'admin_post_mdr_watermark_batch', array( __CLASS__, 'handle_watermark_batch' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings helpers
	 * ------------------------------------------------------------------ */

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		// Upgrade from the single position setting used before 1.3.0.
		if ( ! isset( $saved['watermark_bands'] ) && isset( $saved['watermark_position'] ) ) {
			$saved['watermark_bands'] = array( $saved['watermark_position'] );
		}

		return array_merge( self::defaults(), $saved );
	}

	/**
	 * One time housekeeping when the plugin version changes.
	 */
	public static function maybe_upgrade() {
		$stored = get_option( 'mdr_version', '0' );

		if ( version_compare( $stored, MDR_VERSION, '>=' ) ) {
			return;
		}

		// 1.4.0 moved to a centred diagonal band on its own.
		if ( version_compare( $stored, '1.4.0', '<' ) && '0' !== $stored ) {
			$saved = get_option( self::OPTION, array() );
			if ( is_array( $saved ) && ! empty( $saved['watermark_bands'] ) ) {
				$saved['watermark_bands'] = array_values( array_diff( (array) $saved['watermark_bands'], array( 'bottom' ) ) );
				if ( empty( $saved['watermark_bands'] ) ) {
					$saved['watermark_bands'] = array( 'diagonal' );
				}
				update_option( self::OPTION, $saved );
			}
		}

		update_option( 'mdr_version', MDR_VERSION );
	}

	/**
	 * Which bands to draw, in the order they are painted.
	 */
	public static function bands() {
		$bands = self::get( 'watermark_bands' );
		if ( ! is_array( $bands ) ) {
			$bands = array( $bands );
		}

		$bands = array_values( array_intersect( array( 'top', 'center', 'bottom', 'diagonal' ), $bands ) );

		return $bands;
	}

	public static function get( $key ) {
		$settings = self::settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Normalise user input down to "host" or "host:port".
	 */
	public static function sanitize_host( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false === strpos( $value, '//' ) ) {
			$value = '//' . $value;
		}
		$parts = wp_parse_url( $value );
		$host  = isset( $parts['host'] ) ? $parts['host'] : '';
		if ( ! empty( $parts['port'] ) ) {
			$host .= ':' . $parts['port'];
		}
		return strtolower( $host );
	}

	/* ---------------------------------------------------------------------
	 * Paths and hosts
	 * ------------------------------------------------------------------ */

	/**
	 * The host (plus port, if any) this site is currently served from.
	 * Uses home_url() so the port from DevKinsta / Local / MAMP is included.
	 */
	public static function local_host() {
		if ( null !== self::$local_host ) {
			return self::$local_host;
		}

		$override = self::sanitize_host( self::get( 'local_domain' ) );
		if ( '' !== $override ) {
			self::$local_host = $override;
			return self::$local_host;
		}

		$parts = wp_parse_url( home_url() );
		$host  = isset( $parts['host'] ) ? $parts['host'] : '';
		if ( ! empty( $parts['port'] ) ) {
			$host .= ':' . $parts['port'];
		}

		self::$local_host = strtolower( $host );
		return self::$local_host;
	}

	public static function remote_host() {
		if ( null === self::$remote_host ) {
			self::$remote_host = self::sanitize_host( self::get( 'remote_domain' ) );
		}
		return self::$remote_host;
	}

	/**
	 * Path portion of the uploads base URL, e.g. "/wp-content/uploads".
	 */
	public static function uploads_path() {
		if ( null !== self::$uploads_path ) {
			return self::$uploads_path;
		}
		$dir  = wp_get_upload_dir();
		$path = ! empty( $dir['baseurl'] ) ? wp_parse_url( $dir['baseurl'], PHP_URL_PATH ) : '';
		self::$uploads_path = $path ? untrailingslashit( $path ) : '/uploads';
		return self::$uploads_path;
	}

	/**
	 * Absolute filesystem path of the uploads folder.
	 */
	public static function uploads_dir() {
		if ( null !== self::$uploads_dir ) {
			return self::$uploads_dir;
		}
		$dir = wp_get_upload_dir();
		self::$uploads_dir = ! empty( $dir['basedir'] ) ? untrailingslashit( $dir['basedir'] ) : '';
		return self::$uploads_dir;
	}

	/**
	 * Does the file behind this URL path exist in the local uploads folder?
	 * This is what keeps locally uploaded media pointing at the local site.
	 */
	public static function file_exists_locally( $url_path ) {
		if ( ! is_string( $url_path ) || '' === $url_path ) {
			return false;
		}

		$uploads = self::uploads_path();
		if ( 0 !== strpos( $url_path, $uploads . '/' ) ) {
			return false;
		}

		$relative = ltrim( urldecode( substr( $url_path, strlen( $uploads ) ) ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return false;
		}

		if ( isset( self::$file_exists[ $relative ] ) ) {
			return self::$file_exists[ $relative ];
		}

		$base = self::uploads_dir();
		$hit  = ( '' !== $base ) && file_exists( $base . '/' . $relative );

		self::$file_exists[ $relative ] = $hit;
		return $hit;
	}

	private static function is_admin_context() {
		return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Should any rewriting happen on this request?
	 */
	public static function should_run() {
		if ( null !== self::$should_run ) {
			return self::$should_run;
		}

		$run = true;

		if ( empty( self::get( 'enabled' ) ) ) {
			$run = false;
		}

		$local  = self::local_host();
		$remote = self::remote_host();

		if ( '' === $local || '' === $remote || $local === $remote ) {
			$run = false;
		}

		if ( $run && self::is_admin_context() && empty( self::get( 'apply_in_admin' ) ) ) {
			$run = false;
		}

		/**
		 * Filter whether media domain replacement runs on this request.
		 */
		self::$should_run = (bool) apply_filters( 'mdr_should_run', $run );
		return self::$should_run;
	}

	/**
	 * Decide whether a single URL path should be swapped to the remote host.
	 */
	public static function should_rewrite_path( $path ) {
		$uploads_only = ! empty( self::get( 'uploads_only' ) );

		if ( ! is_string( $path ) || '' === $path ) {
			// A bare host with no path. Only touched when rewriting everything.
			return ! $uploads_only;
		}

		if ( $uploads_only && 0 !== strpos( $path, self::uploads_path() . '/' ) ) {
			return false;
		}

		if ( ! empty( self::get( 'preserve_local' ) ) && self::file_exists_locally( $path ) ) {
			return false;
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * URL rewriting
	 * ------------------------------------------------------------------ */

	public static function add_filters() {
		// Attachment URLs.
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_url' ), 10, 1 );
		add_filter( 'wp_get_attachment_thumb_url', array( __CLASS__, 'filter_url' ), 10, 1 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 10, 1 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 10, 1 );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'filter_attachment_js' ), 10, 1 );

		// Rendered markup.
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20, 1 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'filter_content' ), 20, 1 );
		add_filter( 'acf_the_content', array( __CLASS__, 'filter_content' ), 20, 1 );
		add_filter( 'widget_text', array( __CLASS__, 'filter_content' ), 20, 1 );
		add_filter( 'render_block', array( __CLASS__, 'filter_content' ), 20, 1 );

		/**
		 * Fires after the plugin's own filters are registered, so themes can
		 * add extra filters, e.g.
		 * add_filter( 'my_custom_field', array( 'MDR_Media_Domain_Replace', 'filter_content' ) );
		 */
		do_action( 'mdr_filters_registered' );
	}

	/**
	 * Rewrite a single URL.
	 */
	public static function filter_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || ! self::should_run() ) {
			return $url;
		}

		$local = self::local_host();

		if ( false === strpos( $url, '//' . $local ) ) {
			return $url;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! self::should_rewrite_path( $path ) ) {
			return $url;
		}

		return str_replace( '//' . $local, '//' . self::remote_host(), $url );
	}

	/**
	 * Rewrite a block of HTML, one URL at a time so local files can be skipped.
	 */
	public static function filter_content( $content ) {
		if ( ! is_string( $content ) || '' === $content || ! self::should_run() ) {
			return $content;
		}

		$local = self::local_host();

		if ( false === strpos( $content, $local ) ) {
			return $content;
		}

		$host  = preg_quote( $local, '~' );
		$chars = '[^\s"\'<>()\\[\\]{}]';

		// URLs with escaped slashes, as stored in block comment attributes.
		$content = preg_replace_callback(
			'~\\\\/\\\\/' . $host . '((?:\\\\/' . $chars . '*)?)~',
			array( __CLASS__, 'replace_escaped_match' ),
			$content
		);

		// Ordinary URLs.
		$content = preg_replace_callback(
			'~//' . $host . '((?:/' . $chars . '*)?)~',
			array( __CLASS__, 'replace_plain_match' ),
			$content
		);

		return $content;
	}

	private static function replace_plain_match( $matches ) {
		$path = self::strip_query( $matches[1] );

		if ( ! self::should_rewrite_path( $path ) ) {
			return $matches[0];
		}

		return '//' . self::remote_host() . $matches[1];
	}

	private static function replace_escaped_match( $matches ) {
		$path = self::strip_query( str_replace( '\/', '/', $matches[1] ) );

		if ( ! self::should_rewrite_path( $path ) ) {
			return $matches[0];
		}

		return '\/\/' . self::remote_host() . $matches[1];
	}

	private static function strip_query( $path ) {
		$path = (string) $path;
		$cut  = strcspn( $path, '?#' );
		return substr( $path, 0, $cut );
	}

	/**
	 * wp_get_attachment_image_src() returns array( url, width, height, is_intermediate ).
	 */
	public static function filter_image_src( $image ) {
		if ( is_array( $image ) && isset( $image[0] ) ) {
			$image[0] = self::filter_url( $image[0] );
		}
		return $image;
	}

	/**
	 * wp_calculate_image_srcset() returns an array of source arrays.
	 */
	public static function filter_srcset( $sources ) {
		if ( is_array( $sources ) ) {
			foreach ( $sources as $key => $source ) {
				if ( isset( $source['url'] ) ) {
					$sources[ $key ]['url'] = self::filter_url( $source['url'] );
				}
			}
		}
		return $sources;
	}

	/**
	 * Media library / block editor attachment data.
	 */
	public static function filter_attachment_js( $response ) {
		if ( ! is_array( $response ) ) {
			return $response;
		}

		foreach ( array( 'url', 'link', 'icon' ) as $key ) {
			if ( ! empty( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
				$response[ $key ] = self::filter_url( $response[ $key ] );
			}
		}

		if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			foreach ( $response['sizes'] as $name => $size ) {
				if ( ! empty( $size['url'] ) ) {
					$response['sizes'][ $name ]['url'] = self::filter_url( $size['url'] );
				}
			}
		}

		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Local uploads: flagging and watermarking
	 * ------------------------------------------------------------------ */

	/**
	 * Anything uploaded while this plugin is active came from this machine.
	 */
	public static function flag_local_upload( $attachment_id ) {
		update_post_meta( $attachment_id, self::META_LOCAL, 1 );
	}

	public static function is_local_attachment( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, self::META_LOCAL, true );
	}

	/**
	 * Is image watermarking possible and switched on right now?
	 */
	public static function watermarking_available() {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagecopyresampled' );
	}

	public static function watermarking_active() {
		if ( empty( self::get( 'watermark_enabled' ) ) || ! self::watermarking_available() ) {
			return false;
		}

		// Safety net: never stamp files if this install *is* the remote site.
		$remote = self::remote_host();
		if ( '' !== $remote && $remote === self::local_host() ) {
			return false;
		}

		return true;
	}

	/**
	 * Runs after WordPress has generated the intermediate sizes.
	 */
	public static function watermark_on_upload( $metadata, $attachment_id, $context = 'create' ) {
		if ( 'create' !== $context ) {
			return $metadata;
		}

		update_post_meta( $attachment_id, self::META_LOCAL, 1 );

		if ( ! self::watermarking_active() ) {
			return $metadata;
		}

		return self::watermark_attachment( $attachment_id, $metadata );
	}

	/**
	 * Stamp the full size file, the untouched original, and every generated size.
	 *
	 * @return array The (possibly updated) attachment metadata.
	 */
	public static function watermark_attachment( $attachment_id, $metadata = null ) {
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		if ( get_post_meta( $attachment_id, self::META_MARKED, true ) ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! self::is_supported_image( $file ) ) {
			return $metadata;
		}

		$dir     = dirname( $file );
		$touched = false;

		if ( ! empty( self::get( 'watermark_original' ) ) ) {
			if ( self::watermark_file( $file ) ) {
				$touched = true;
				if ( isset( $metadata['filesize'] ) ) {
					$metadata['filesize'] = (int) filesize( $file );
				}
			}

			// WordPress 5.3+ keeps an untouched original alongside the scaled file.
			if ( function_exists( 'wp_get_original_image_path' ) ) {
				$original = wp_get_original_image_path( $attachment_id );
				if ( $original && $original !== $file ) {
					self::watermark_file( $original );
				}
			}
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$path = $dir . '/' . $size['file'];
				if ( self::watermark_file( $path ) ) {
					$touched = true;
					if ( isset( $metadata['sizes'][ $name ]['filesize'] ) ) {
						$metadata['sizes'][ $name ]['filesize'] = (int) filesize( $path );
					}
				}
			}
		}

		if ( $touched ) {
			update_post_meta( $attachment_id, self::META_MARKED, 1 );
		}

		return $metadata;
	}

	private static function is_supported_image( $file ) {
		$type = wp_check_filetype( $file );
		$mime = isset( $type['type'] ) ? $type['type'] : '';

		$supported = array( 'image/jpeg', 'image/png', 'image/gif' );
		if ( function_exists( 'imagecreatefromwebp' ) ) {
			$supported[] = 'image/webp';
		}

		return in_array( $mime, $supported, true );
	}

	/**
	 * Draw the watermark band onto one image file, in place.
	 */
	public static function watermark_file( $path ) {
		if ( ! self::watermarking_available() || ! file_exists( $path ) || ! is_writable( $path ) ) {
			return false;
		}

		$info = @getimagesize( $path );
		if ( ! $info ) {
			return false;
		}

		list( $width, $height ) = $info;
		$type = $info[2];

		// Too small to carry a legible mark.
		if ( $width < 40 || $height < 24 ) {
			return false;
		}

		switch ( $type ) {
			case IMAGETYPE_JPEG:
				$image = @imagecreatefromjpeg( $path );
				break;
			case IMAGETYPE_PNG:
				$image = @imagecreatefrompng( $path );
				break;
			case IMAGETYPE_GIF:
				$image = @imagecreatefromgif( $path );
				break;
			case IMAGETYPE_WEBP:
				$image = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
				break;
			default:
				return false;
		}

		if ( ! $image ) {
			return false;
		}

		if ( function_exists( 'imagepalettetotruecolor' ) ) {
			imagepalettetotruecolor( $image );
		}
		imagealphablending( $image, true );

		$style = self::get( 'watermark_style' );

		$opacity = (int) self::get( 'watermark_opacity' );
		$opacity = max( 0, min( 100, $opacity ) );
		$alpha   = (int) round( 127 - ( 127 * $opacity / 100 ) );

		if ( 'text' === $style || 'both' === $style ) {
			$text = (string) self::get( 'watermark_text' );
			if ( '' === trim( $text ) ) {
				$text = 'LOCAL ONLY';
			}
			$text = strtoupper( $text );

			$band_height = (int) max( 16, min( round( $height * 0.16 ), 110 ) );

			foreach ( self::bands() as $band ) {
				if ( 'diagonal' === $band ) {
					self::draw_diagonal_band( $image, $width, $height, $band_height, $text, $alpha );
				} else {
					self::draw_straight_band( $image, $width, $height, $band_height, $text, $alpha, $band );
				}
			}
		}

		if ( 'image' === $style || 'both' === $style ) {
			self::draw_image_overlay( $image, $width, $height, $opacity );
		}

		$saved = false;

		switch ( $type ) {
			case IMAGETYPE_JPEG:
				$quality = (int) apply_filters( 'jpeg_quality', 82, 'mdr_watermark' );
				$saved   = imagejpeg( $image, $path, $quality );
				break;
			case IMAGETYPE_PNG:
				imagesavealpha( $image, true );
				$saved = imagepng( $image, $path );
				break;
			case IMAGETYPE_GIF:
				$saved = imagegif( $image, $path );
				break;
			case IMAGETYPE_WEBP:
				$saved = function_exists( 'imagewebp' ) ? imagewebp( $image, $path, 85 ) : false;
				break;
		}

		imagedestroy( $image );

		if ( $saved ) {
			clearstatcache( true, $path );
		}

		return (bool) $saved;
	}

	/**
	 * Base URL of the uploads folder.
	 */
	public static function uploads_url() {
		$dir = wp_get_upload_dir();
		return ! empty( $dir['baseurl'] ) ? untrailingslashit( $dir['baseurl'] ) : '';
	}

	/**
	 * Move an uploaded watermark file into uploads/mdr-watermark/.
	 *
	 * It deliberately does not become a media library attachment, so the
	 * watermarker never stamps the watermark itself.
	 *
	 * @return string|null Path relative to the uploads folder, or null.
	 */
	private static function handle_image_upload() {
		if ( empty( $_FILES['mdr_watermark_image']['name'] ) ) {
			return null;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$mimes = array(
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
		);

		$is_svg = ( 'svg' === strtolower( pathinfo( $_FILES['mdr_watermark_image']['name'], PATHINFO_EXTENSION ) ) );

		if ( $is_svg ) {
			if ( ! self::svg_supported() ) {
				add_settings_error(
					self::OPTION,
					'mdr_svg_unsupported',
					__( 'SVG needs the Imagick extension with an SVG delegate, which is not installed here. Export the logo to a transparent PNG and upload that instead.', 'media-domain-replace' ),
					'error'
				);
				return null;
			}
			$mimes['svg'] = 'image/svg+xml';
		}

		add_filter( 'upload_dir', array( __CLASS__, 'watermark_upload_dir' ) );

		$result = wp_handle_upload(
			$_FILES['mdr_watermark_image'],
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);

		remove_filter( 'upload_dir', array( __CLASS__, 'watermark_upload_dir' ) );

		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			add_settings_error(
				self::OPTION,
				'mdr_upload_failed',
				isset( $result['error'] ) ? esc_html( $result['error'] ) : __( 'The watermark image could not be uploaded.', 'media-domain-replace' ),
				'error'
			);
			return null;
		}

		if ( $is_svg && ! self::rasterize_svg( $result['file'] ) ) {
			@unlink( $result['file'] );
			add_settings_error(
				self::OPTION,
				'mdr_svg_failed',
				__( 'That SVG could not be rendered. Export it to a transparent PNG and upload that instead.', 'media-domain-replace' ),
				'error'
			);
			return null;
		}

		return ltrim( str_replace( self::uploads_dir(), '', $result['file'] ), '/' );
	}

	/**
	 * Keep watermark images in their own folder, away from the media library.
	 */
	public static function watermark_upload_dir( $dirs ) {
		$dirs['subdir'] = '/mdr-watermark';
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	public static function delete_watermark_image() {
		$relative = trim( (string) self::get( 'watermark_image' ) );
		if ( '' === $relative ) {
			return;
		}

		$path = self::uploads_dir() . '/' . ltrim( $relative, '/' );

		// Only ever touch files inside our own folder.
		if ( false === strpos( $path, '/mdr-watermark/' ) ) {
			return;
		}

		if ( file_exists( $path ) ) {
			@unlink( $path );
		}

		$cached = preg_replace( '/\.svg$/i', '.png', $path );
		if ( $cached && $cached !== $path && file_exists( $cached ) ) {
			@unlink( $cached );
		}
	}

	/**
	 * Absolute path to the uploaded watermark image, or an empty string.
	 * SVGs are rasterised to a cached PNG the first time they are used.
	 */
	public static function watermark_image_path() {
		$relative = trim( (string) self::get( 'watermark_image' ) );
		if ( '' === $relative ) {
			return '';
		}

		$path = self::uploads_dir() . '/' . ltrim( $relative, '/' );
		if ( ! file_exists( $path ) ) {
			return '';
		}

		if ( 'svg' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return $path;
		}

		$cached = preg_replace( '/\.svg$/i', '.png', $path );
		if ( $cached && file_exists( $cached ) && filemtime( $cached ) >= filemtime( $path ) ) {
			return $cached;
		}

		$rendered = self::rasterize_svg( $path );
		return $rendered ? $rendered : '';
	}

	/**
	 * Turn an SVG into a PNG using Imagick, which is the only way GD can
	 * composite it. Returns false when Imagick has no SVG delegate.
	 */
	public static function rasterize_svg( $path ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}

		try {
			if ( ! Imagick::queryFormats( 'SVG' ) ) {
				return false;
			}

			$svg = new Imagick();
			$svg->setBackgroundColor( new ImagickPixel( 'transparent' ) );
			$svg->setResolution( 300, 300 );
			$svg->readImage( $path );
			$svg->setImageFormat( 'png32' );

			// Render generously so it still looks clean on large images.
			if ( $svg->getImageWidth() < 1400 ) {
				$svg->resizeImage( 1400, 0, Imagick::FILTER_LANCZOS, 1 );
			}

			$out = preg_replace( '/\.svg$/i', '.png', $path );
			$svg->writeImage( $out );
			$svg->clear();

			return file_exists( $out ) ? $out : false;
		} catch ( Exception $e ) {
			return false;
		}
	}

	public static function svg_supported() {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			return (bool) Imagick::queryFormats( 'SVG' );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Composite the uploaded logo over the image.
	 */
	private static function draw_image_overlay( $image, $width, $height, $opacity ) {
		$file = self::watermark_image_path();
		if ( '' === $file ) {
			return;
		}

		$info = @getimagesize( $file );
		if ( ! $info ) {
			return;
		}

		switch ( $info[2] ) {
			case IMAGETYPE_PNG:
				$source = @imagecreatefrompng( $file );
				break;
			case IMAGETYPE_JPEG:
				$source = @imagecreatefromjpeg( $file );
				break;
			case IMAGETYPE_GIF:
				$source = @imagecreatefromgif( $file );
				break;
			case IMAGETYPE_WEBP:
				$source = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $file ) : false;
				break;
			default:
				return;
		}

		if ( ! $source ) {
			return;
		}

		if ( function_exists( 'imagepalettetotruecolor' ) ) {
			imagepalettetotruecolor( $source );
		}

		$source_width  = imagesx( $source );
		$source_height = imagesy( $source );

		$scale = (int) self::get( 'watermark_image_scale' );
		$scale = max( 5, min( 100, $scale ) ) / 100;

		$target_width  = max( 1, (int) round( $width * $scale ) );
		$target_height = max( 1, (int) round( $source_height * ( $target_width / $source_width ) ) );

		// Never let it grow taller than the image it is sitting on.
		$max_height = (int) round( $height * 0.9 );
		if ( $target_height > $max_height ) {
			$target_height = max( 1, $max_height );
			$target_width  = max( 1, (int) round( $source_width * ( $target_height / $source_height ) ) );
		}

		$layer = imagecreatetruecolor( $target_width, $target_height );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $target_width, $target_height, imagecolorallocatealpha( $layer, 0, 0, 0, 127 ) );
		imagecopyresampled( $layer, $source, 0, 0, 0, 0, $target_width, $target_height, $source_width, $source_height );
		imagedestroy( $source );

		// Knock the whole thing back to the configured opacity.
		if ( $opacity < 100 && function_exists( 'imagefilter' ) ) {
			$knock = (int) round( 127 * ( 1 - ( $opacity / 100 ) ) );
			imagefilter( $layer, IMG_FILTER_COLORIZE, 0, 0, 0, $knock );
		}

		if ( ! empty( self::get( 'watermark_image_rotate' ) ) && function_exists( 'imagerotate' ) ) {
			$angle = (float) self::get( 'watermark_angle' );
			if ( abs( $angle ) > 0.01 ) {
				$rotated = imagerotate( $layer, max( -60, min( 60, $angle ) ), imagecolorallocatealpha( $layer, 0, 0, 0, 127 ) );
				if ( $rotated ) {
					imagedestroy( $layer );
					$layer         = $rotated;
					$target_width  = imagesx( $layer );
					$target_height = imagesy( $layer );
					imagealphablending( $layer, false );
					imagesavealpha( $layer, true );
				}
			}
		}

		$margin = (int) round( min( $width, $height ) * 0.04 );
		$place  = self::get( 'watermark_image_place' );

		switch ( $place ) {
			case 'top-left':
				$x = $margin;
				$y = $margin;
				break;
			case 'top-right':
				$x = $width - $target_width - $margin;
				$y = $margin;
				break;
			case 'bottom-left':
				$x = $margin;
				$y = $height - $target_height - $margin;
				break;
			case 'bottom-right':
				$x = $width - $target_width - $margin;
				$y = $height - $target_height - $margin;
				break;
			default:
				$x = (int) round( ( $width - $target_width ) / 2 );
				$y = (int) round( ( $height - $target_height ) / 2 );
				break;
		}

		// Clip rather than letting a large overlay fall off the canvas.
		$src_x = 0;
		$src_y = 0;
		if ( $x < 0 ) {
			$src_x = -$x;
			$x     = 0;
		}
		if ( $y < 0 ) {
			$src_y = -$y;
			$y     = 0;
		}

		imagealphablending( $image, true );
		imagecopy(
			$image,
			$layer,
			(int) $x,
			(int) $y,
			$src_x,
			$src_y,
			min( $target_width - $src_x, $width - $x ),
			min( $target_height - $src_y, $height - $y )
		);

		imagedestroy( $layer );
	}

	/**
	 * A horizontal band pinned to the top, middle or bottom of the image.
	 */
	private static function draw_straight_band( $image, $width, $height, $band_height, $text, $alpha, $position ) {
		if ( 'top' === $position ) {
			$top = 0;
		} elseif ( 'center' === $position ) {
			$top = (int) round( ( $height - $band_height ) / 2 );
		} else {
			$top = $height - $band_height;
		}

		$band = imagecolorallocatealpha( $image, 0, 0, 0, $alpha );
		imagefilledrectangle( $image, 0, $top, $width, $top + $band_height, $band );

		self::draw_text(
			$image,
			$text,
			(int) round( $width * 0.05 ),
			$top + (int) round( $band_height * 0.15 ),
			(int) round( $width * 0.90 ),
			(int) round( $band_height * 0.70 )
		);
	}

	/**
	 * A band across the middle of the image, tilted by the configured angle.
	 *
	 * The band is built on its own layer long enough to cover the image
	 * diagonal, rotated, then dropped over the centre of the image so it runs
	 * off both edges cleanly.
	 */
	private static function draw_diagonal_band( $image, $width, $height, $band_height, $text, $alpha ) {
		if ( ! function_exists( 'imagerotate' ) ) {
			self::draw_straight_band( $image, $width, $height, $band_height, $text, $alpha, 'center' );
			return;
		}

		$angle = (float) self::get( 'watermark_angle' );
		$angle = max( -60, min( 60, $angle ) );

		$span  = (int) ceil( sqrt( ( $width * $width ) + ( $height * $height ) ) );
		$layer = imagecreatetruecolor( $span, $band_height );

		if ( ! $layer ) {
			return;
		}

		// Write the band colour straight into the layer, alpha and all.
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		$band = imagecolorallocatealpha( $layer, 0, 0, 0, $alpha );
		imagefilledrectangle( $layer, 0, 0, $span, $band_height, $band );

		// Then blend the text on top of it.
		imagealphablending( $layer, true );

		$text_width = (int) round( min( $width, $height, $span ) * 0.82 );
		self::draw_text(
			$layer,
			$text,
			(int) round( ( $span - $text_width ) / 2 ),
			(int) round( $band_height * 0.15 ),
			$text_width,
			(int) round( $band_height * 0.70 )
		);

		imagealphablending( $layer, false );

		$clear   = imagecolorallocatealpha( $layer, 0, 0, 0, 127 );
		$rotated = imagerotate( $layer, $angle, $clear );
		imagedestroy( $layer );

		if ( ! $rotated ) {
			return;
		}

		imagealphablending( $rotated, false );
		imagesavealpha( $rotated, true );

		$rotated_width  = imagesx( $rotated );
		$rotated_height = imagesy( $rotated );

		// Centre the rotated band, cropping whatever hangs off the edges.
		$src_x = (int) max( 0, round( ( $rotated_width - $width ) / 2 ) );
		$src_y = (int) max( 0, round( ( $rotated_height - $height ) / 2 ) );
		$dst_x = (int) max( 0, round( ( $width - $rotated_width ) / 2 ) );
		$dst_y = (int) max( 0, round( ( $height - $rotated_height ) / 2 ) );

		imagealphablending( $image, true );
		imagecopy(
			$image,
			$rotated,
			$dst_x,
			$dst_y,
			$src_x,
			$src_y,
			min( $width, $rotated_width ),
			min( $height, $rotated_height )
		);

		imagedestroy( $rotated );
	}

	/**
	 * Path to the TrueType font in use, or an empty string to fall back to
	 * GD's built in bitmap font.
	 */
	public static function font_path() {
		if ( ! function_exists( 'imagettftext' ) || ! function_exists( 'imagettfbbox' ) ) {
			return '';
		}

		$face = self::get( 'watermark_font_face' );

		if ( 'gd' === $face ) {
			return '';
		}

		if ( 'custom' === $face ) {
			$custom = trim( (string) self::get( 'watermark_font' ) );
			return ( '' !== $custom && file_exists( $custom ) ) ? $custom : '';
		}

		$bundled = plugin_dir_path( MDR_PLUGIN_FILE ) . 'fonts/Poppins-SemiBold.ttf';

		/**
		 * Filter the font file used for the watermark.
		 */
		$bundled = apply_filters( 'mdr_watermark_font', $bundled );

		return file_exists( $bundled ) ? $bundled : '';
	}

	/**
	 * Centre some text inside a box. Uses a TrueType font when one is
	 * available, otherwise scales up GD's built in bitmap font so the mark
	 * stays readable either way.
	 */
	private static function draw_text( $image, $text, $x, $y, $box_width, $box_height ) {
		$font = self::font_path();

		if ( '' !== $font && self::draw_truetype( $image, $text, $x, $y, $box_width, $box_height, $font ) ) {
			return;
		}

		$font_id      = 5;
		$char_width   = imagefontwidth( $font_id );
		$char_height  = imagefontheight( $font_id );
		$text_width   = max( 1, $char_width * strlen( $text ) );
		$text_height  = max( 1, $char_height );

		$layer = imagecreatetruecolor( $text_width, $text_height );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		$clear = imagecolorallocatealpha( $layer, 0, 0, 0, 127 );
		imagefilledrectangle( $layer, 0, 0, $text_width, $text_height, $clear );
		imagealphablending( $layer, true );
		$white = imagecolorallocate( $layer, 255, 255, 255 );
		imagestring( $layer, $font_id, 0, 0, $text, $white );

		$scale = min( $box_width / $text_width, $box_height / $text_height );
		$draw_w = max( 1, (int) round( $text_width * $scale ) );
		$draw_h = max( 1, (int) round( $text_height * $scale ) );

		imagecopyresampled(
			$image,
			$layer,
			$x + (int) round( ( $box_width - $draw_w ) / 2 ),
			$y + (int) round( ( $box_height - $draw_h ) / 2 ),
			0,
			0,
			$draw_w,
			$draw_h,
			$text_width,
			$text_height
		);

		imagedestroy( $layer );
	}

	/**
	 * Draw the label with a TrueType font, sized to fill the band and spaced
	 * out a little the way a stamp would be set.
	 *
	 * @return bool False if the font could not be measured, so the caller can
	 *              fall back to the bitmap font.
	 */
	private static function draw_truetype( $image, $text, $x, $y, $box_width, $box_height, $font ) {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $chars ) ) {
			$chars = str_split( $text );
		}

		// Measure at a large reference size, then scale to fit the band.
		$probe   = 100;
		$metrics = self::measure_text( $probe, $font, $chars, $probe * 0.10 );

		if ( ! $metrics || $metrics['width'] <= 0 || $metrics['height'] <= 0 ) {
			return false;
		}

		$scale = min( $box_width / $metrics['width'], $box_height / $metrics['height'] );
		$size  = max( 6, $probe * $scale );

		$metrics = self::measure_text( $size, $font, $chars, $size * 0.10 );
		if ( ! $metrics || $metrics['width'] <= 0 ) {
			return false;
		}

		$start_x  = $x + ( ( $box_width - $metrics['width'] ) / 2 ) - $metrics['left'];
		$baseline = $y + ( ( $box_height - $metrics['height'] ) / 2 ) - $metrics['top'];

		$white  = imagecolorallocate( $image, 255, 255, 255 );
		$shadow = imagecolorallocatealpha( $image, 0, 0, 0, 75 );

		foreach ( $chars as $i => $char ) {
			$char_x = (int) round( $start_x + $metrics['offsets'][ $i ] );
			$char_y = (int) round( $baseline );

			// A soft shadow keeps the text readable if the band is very light.
			imagettftext( $image, $size, 0, $char_x + 1, $char_y + 1, $shadow, $font, $char );
			imagettftext( $image, $size, 0, $char_x, $char_y, $white, $font, $char );
		}

		return true;
	}

	/**
	 * Work out where each character sits, and how big the whole run is.
	 */
	private static function measure_text( $size, $font, $chars, $tracking ) {
		$first = imagettfbbox( $size, 0, $font, $chars[0] );
		if ( ! is_array( $first ) ) {
			return false;
		}

		$offsets = array();
		$prefix  = '';
		$right   = 0;

		foreach ( $chars as $i => $char ) {
			$offsets[ $i ] = $right + ( $i * $tracking );

			$prefix .= $char;
			$box     = imagettfbbox( $size, 0, $font, $prefix );
			if ( ! is_array( $box ) ) {
				return false;
			}
			$right = $box[2];
		}

		$full = imagettfbbox( $size, 0, $font, implode( '', $chars ) );
		if ( ! is_array( $full ) ) {
			return false;
		}

		return array(
			'offsets' => $offsets,
			'left'    => $first[0],
			'top'     => $full[7],
			'width'   => ( $full[2] - $first[0] ) + ( ( count( $chars ) - 1 ) * $tracking ),
			'height'  => $full[1] - $full[7],
		);
	}

	/* ---------------------------------------------------------------------
	 * Media library column
	 * ------------------------------------------------------------------ */

	public static function media_column( $columns ) {
		$columns['mdr_local'] = __( 'Source', 'media-domain-replace' );
		return $columns;
	}

	public static function media_column_content( $column, $attachment_id ) {
		if ( 'mdr_local' !== $column ) {
			return;
		}

		if ( ! self::is_local_attachment( $attachment_id ) ) {
			echo '<span style="color:#787c82;">' . esc_html__( 'Remote', 'media-domain-replace' ) . '</span>';
			return;
		}

		echo '<span style="display:inline-block;padding:1px 7px;border-radius:9px;background:#b32d2e;color:#fff;font-size:11px;">' . esc_html__( 'Local', 'media-domain-replace' ) . '</span>';

		if ( get_post_meta( $attachment_id, self::META_MARKED, true ) ) {
			echo '<br><span style="color:#787c82;font-size:11px;">' . esc_html__( 'watermarked', 'media-domain-replace' ) . '</span>';
		}
	}

	/**
	 * Watermark local uploads that predate the feature being switched on.
	 */
	public static function handle_watermark_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'media-domain-replace' ) );
		}

		check_admin_referer( 'mdr_watermark_batch' );

		$done = 0;

		if ( self::watermarking_active() ) {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => self::META_LOCAL,
							'value' => '1',
						),
						array(
							'key'     => self::META_MARKED,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);

			foreach ( $ids as $id ) {
				$metadata = self::watermark_attachment( $id );
				if ( get_post_meta( $id, self::META_MARKED, true ) ) {
					wp_update_attachment_metadata( $id, $metadata );
					$done++;
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'media-domain-replace',
					'mdr_processed' => $done,
				),
				admin_url( 'upload.php' )
			)
		);
		exit;
	}

	/**
	 * Optional: copy nav menu locations across when switching themes.
	 */
	public static function migrate_menu_locations() {
		$slug = trim( (string) self::get( 'migrate_theme' ) );
		if ( '' === $slug ) {
			return;
		}

		$old = get_option( 'theme_mods_' . $slug );
		if ( ! empty( $old['nav_menu_locations'] ) ) {
			set_theme_mod( 'nav_menu_locations', $old['nav_menu_locations'] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	public static function action_links( $links ) {
		$url = admin_url( 'upload.php?page=media-domain-replace' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'media-domain-replace' ) . '</a>' );
		return $links;
	}

	public static function add_settings_page() {
		add_submenu_page(
			'upload.php',
			__( 'Media Domain Replace', 'media-domain-replace' ),
			__( 'Media Domain', 'media-domain-replace' ),
			'manage_options',
			'media-domain-replace',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'mdr_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'mdr_main',
			__( 'Domain settings', 'media-domain-replace' ),
			array( __CLASS__, 'section_intro' ),
			'media-domain-replace'
		);

		add_settings_section(
			'mdr_local',
			__( 'Local uploads', 'media-domain-replace' ),
			array( __CLASS__, 'section_local_intro' ),
			'media-domain-replace'
		);

		add_settings_section(
			'mdr_extras',
			__( 'Extras', 'media-domain-replace' ),
			'__return_false',
			'media-domain-replace'
		);

		$fields = array(
			'enabled'            => array( __( 'Enable rewriting', 'media-domain-replace' ), 'mdr_main' ),
			'remote_domain'      => array( __( 'Remote media domain', 'media-domain-replace' ), 'mdr_main' ),
			'local_domain'       => array( __( 'Local domain override', 'media-domain-replace' ), 'mdr_main' ),
			'uploads_only'       => array( __( 'Uploads only', 'media-domain-replace' ), 'mdr_main' ),
			'apply_in_admin'     => array( __( 'Apply in admin', 'media-domain-replace' ), 'mdr_main' ),
			'preserve_local'     => array( __( 'Preserve local files', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_enabled'  => array( __( 'Watermark new uploads', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_style'    => array( __( 'Watermark style', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_text'     => array( __( 'Watermark text', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_bands'    => array( __( 'Bands', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_angle'    => array( __( 'Diagonal angle', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_opacity'  => array( __( 'Opacity', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_image'    => array( __( 'Watermark image', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_image_scale'  => array( __( 'Image size', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_image_place'  => array( __( 'Image position', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_image_rotate' => array( __( 'Rotate image', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_original' => array( __( 'Include full size', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_font_face' => array( __( 'Font', 'media-domain-replace' ), 'mdr_local' ),
			'watermark_font'     => array( __( 'Custom font path', 'media-domain-replace' ), 'mdr_local' ),
			'migrate_theme'      => array( __( 'Migrate menus from theme', 'media-domain-replace' ), 'mdr_extras' ),
		);

		foreach ( $fields as $key => $field ) {
			add_settings_field(
				'mdr_field_' . $key,
				$field[0],
				array( __CLASS__, 'render_field' ),
				'media-domain-replace',
				$field[1],
				array( 'key' => $key, 'label_for' => 'mdr_' . $key )
			);
		}
	}

	public static function sanitize_settings( $input ) {
		$clean = self::defaults();

		$clean['enabled']            = empty( $input['enabled'] ) ? 0 : 1;
		$clean['uploads_only']       = empty( $input['uploads_only'] ) ? 0 : 1;
		$clean['apply_in_admin']     = empty( $input['apply_in_admin'] ) ? 0 : 1;
		$clean['preserve_local']     = empty( $input['preserve_local'] ) ? 0 : 1;
		$clean['watermark_enabled']  = empty( $input['watermark_enabled'] ) ? 0 : 1;
		$clean['watermark_original'] = empty( $input['watermark_original'] ) ? 0 : 1;
		$clean['remote_domain']      = self::sanitize_host( isset( $input['remote_domain'] ) ? $input['remote_domain'] : '' );
		$clean['local_domain']       = self::sanitize_host( isset( $input['local_domain'] ) ? $input['local_domain'] : '' );
		$clean['migrate_theme']      = isset( $input['migrate_theme'] ) ? sanitize_key( $input['migrate_theme'] ) : '';

		$text = isset( $input['watermark_text'] ) ? sanitize_text_field( $input['watermark_text'] ) : '';
		$clean['watermark_text'] = ( '' === trim( $text ) ) ? 'LOCAL ONLY' : $text;

		$style = isset( $input['watermark_style'] ) ? $input['watermark_style'] : 'text';
		$clean['watermark_style'] = in_array( $style, array( 'text', 'image', 'both' ), true ) ? $style : 'text';

		$scale = isset( $input['watermark_image_scale'] ) ? (int) $input['watermark_image_scale'] : 40;
		$clean['watermark_image_scale'] = max( 5, min( 100, $scale ) );

		$place = isset( $input['watermark_image_place'] ) ? $input['watermark_image_place'] : 'center';
		$clean['watermark_image_place'] = in_array( $place, array( 'center', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ), true ) ? $place : 'center';

		$clean['watermark_image_rotate'] = empty( $input['watermark_image_rotate'] ) ? 0 : 1;
		$clean['watermark_image']        = (string) self::get( 'watermark_image' );

		if ( ! empty( $input['watermark_image_remove'] ) ) {
			self::delete_watermark_image();
			$clean['watermark_image'] = '';
		}

		$uploaded = self::handle_image_upload();
		if ( null !== $uploaded ) {
			self::delete_watermark_image();
			$clean['watermark_image'] = $uploaded;
		}

		if ( 'text' !== $clean['watermark_style'] && '' === $clean['watermark_image'] ) {
			add_settings_error(
				self::OPTION,
				'mdr_no_image',
				__( 'No watermark image is set yet, so only the text band will be drawn until you upload one.', 'media-domain-replace' ),
				'warning'
			);
		}

		$bands = isset( $input['watermark_bands'] ) ? (array) $input['watermark_bands'] : array();
		$bands = array_values( array_intersect( array( 'top', 'center', 'bottom', 'diagonal' ), $bands ) );
		if ( empty( $bands ) ) {
			$bands = array( 'bottom' );
			add_settings_error(
				self::OPTION,
				'mdr_no_bands',
				__( 'At least one band is needed, so the bottom band has been kept.', 'media-domain-replace' ),
				'warning'
			);
		}
		$clean['watermark_bands'] = $bands;

		$angle = isset( $input['watermark_angle'] ) ? (int) $input['watermark_angle'] : 13;
		$clean['watermark_angle'] = max( -60, min( 60, $angle ) );

		$opacity = isset( $input['watermark_opacity'] ) ? (int) $input['watermark_opacity'] : 55;
		$clean['watermark_opacity'] = max( 0, min( 100, $opacity ) );

		$face = isset( $input['watermark_font_face'] ) ? $input['watermark_font_face'] : 'poppins';
		$clean['watermark_font_face'] = in_array( $face, array( 'poppins', 'custom', 'gd' ), true ) ? $face : 'poppins';

		$font = isset( $input['watermark_font'] ) ? trim( wp_unslash( $input['watermark_font'] ) ) : '';
		if ( 'custom' === $clean['watermark_font_face'] && '' !== $font && ! file_exists( $font ) ) {
			add_settings_error(
				self::OPTION,
				'mdr_font_missing',
				__( 'That font file could not be found, so Poppins will be used instead.', 'media-domain-replace' ),
				'warning'
			);
			$clean['watermark_font_face'] = 'poppins';
		}
		$clean['watermark_font'] = $font;

		if ( '' !== $clean['remote_domain'] && $clean['remote_domain'] === self::local_host() ) {
			add_settings_error(
				self::OPTION,
				'mdr_same_domain',
				__( 'The remote domain matches this site&#8217;s own domain, so nothing will be rewritten.', 'media-domain-replace' ),
				'warning'
			);
		}

		return $clean;
	}

	public static function section_intro() {
		echo '<p>' . esc_html__( 'Media URLs pointing at this site will be rewritten to the remote domain below. Nothing in the database is changed; the swap happens at output time only.', 'media-domain-replace' ) . '</p>';
	}

	public static function section_local_intro() {
		echo '<p>' . esc_html__( 'Files that live on this machine are left pointing at the local site, and can be stamped so you never mistake a local test image for real content.', 'media-domain-replace' ) . '</p>';

		if ( ! self::watermarking_available() ) {
			echo '<p><strong>' . esc_html__( 'The GD image library is not available in this PHP install, so watermarking is switched off.', 'media-domain-replace' ) . '</strong></p>';
		}
	}

	public static function render_field( $args ) {
		$key      = $args['key'];
		$settings = self::settings();
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		$name     = self::OPTION . '[' . $key . ']';
		$id       = 'mdr_' . $key;

		switch ( $key ) {
			case 'enabled':
				self::checkbox( $id, $name, $value, __( 'Rewrite media URLs on this site', 'media-domain-replace' ) );
				echo '<p class="description">' . esc_html__( 'Turn this off to disable the plugin without deactivating it.', 'media-domain-replace' ) . '</p>';
				break;

			case 'remote_domain':
				self::text( $id, $name, $value, 'example.kinsta.cloud' );
				echo '<p class="description">' . esc_html__( 'Host where the media actually lives, for example uavionix2026reboot.kinsta.cloud. Leave off http:// and any trailing slash; a port is allowed.', 'media-domain-replace' ) . '</p>';
				break;

			case 'local_domain':
				self::text( $id, $name, $value, self::local_host() );
				echo '<p class="description">' . esc_html__( 'Optional. Leave blank to detect the host and port automatically from the Site Address.', 'media-domain-replace' ) . '</p>';
				break;

			case 'uploads_only':
				self::checkbox( $id, $name, $value, __( 'Only rewrite URLs inside the uploads folder', 'media-domain-replace' ) );
				printf(
					'<p class="description">%s <code>%s</code></p>',
					esc_html__( 'Recommended. Detected uploads path:', 'media-domain-replace' ),
					esc_html( self::uploads_path() )
				);
				break;

			case 'apply_in_admin':
				self::checkbox( $id, $name, $value, __( 'Also rewrite in the admin area and REST requests', 'media-domain-replace' ) );
				echo '<p class="description">' . esc_html__( 'Needed for thumbnails to show in the Media Library and block editor. Be aware that inserting an image into a post while this is on can store the remote URL in your content.', 'media-domain-replace' ) . '</p>';
				break;

			case 'preserve_local':
				self::checkbox( $id, $name, $value, __( 'Never rewrite a URL when the file exists in the local uploads folder', 'media-domain-replace' ) );
				echo '<p class="description">' . esc_html__( 'Keeps anything you upload locally on the local domain, since the remote server has no copy of it. Turn off only if your local uploads folder holds a partial copy of the live media.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_enabled':
				self::checkbox( $id, $name, $value, __( 'Stamp images uploaded on this site', 'media-domain-replace' ) );
				echo '<p class="description">' . esc_html__( 'Applies to JPEG, PNG, GIF and WebP at upload time, across the full size image and every generated size. Existing files are untouched until you run the tool below.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_text':
				self::text( $id, $name, $value, 'LOCAL ONLY' );
				echo '<p class="description">' . esc_html__( 'Whatever you type here is drawn on the band, in upper case. Keep it short so it stays legible on thumbnails.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_style':
				$styles = array(
					'text'  => __( 'Text band', 'media-domain-replace' ),
					'image' => __( 'Uploaded image', 'media-domain-replace' ),
					'both'  => __( 'Both', 'media-domain-replace' ),
				);
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $styles as $style => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $style ),
						selected( $style, $value, false ),
						esc_html( $label )
					);
				}
				echo '</select>';
				echo '<p class="description">' . esc_html__( 'The text options below apply to the band; the image options apply to the uploaded file.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_image':
				$current = self::watermark_image_path();

				if ( '' !== $current ) {
					$url = self::uploads_url() . '/' . ltrim( str_replace( self::uploads_dir(), '', $current ), '/' );
					printf(
						'<p><img src="%s" alt="" style="max-width:260px;max-height:120px;background:#f0f0f1;border:1px solid #dcdcde;padding:6px;"></p>',
						esc_url( $url )
					);
					printf(
						'<p><code>%s</code></p>',
						esc_html( basename( (string) self::get( 'watermark_image' ) ) )
					);
					printf(
						'<label><input type="checkbox" name="%s" value="1"> %s</label></p><p>',
						esc_attr( self::OPTION . '[watermark_image_remove]' ),
						esc_html__( 'Remove this image', 'media-domain-replace' )
					);
				}

				echo '<input type="file" name="mdr_watermark_image" accept="image/png,image/webp,image/gif,image/jpeg,image/svg+xml">';

				$formats = self::svg_supported()
					? __( 'PNG with transparency works best. GIF, WebP, JPEG and SVG are also accepted.', 'media-domain-replace' )
					: __( 'PNG with transparency works best. GIF, WebP and JPEG are also accepted. SVG needs the Imagick extension with an SVG delegate, which this server does not have, so export it to PNG first.', 'media-domain-replace' );

				echo '<p class="description">' . esc_html( $formats ) . '</p>';
				echo '<p class="description">' . esc_html__( 'Stored outside the media library, so it never gets watermarked itself.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_image_scale':
				printf(
					'<input type="number" min="5" max="100" step="5" class="small-text" id="%1$s" name="%2$s" value="%3$s"> %%',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				echo '<p class="description">' . esc_html__( 'Width of the overlay as a share of the image width. It is scaled down further if it would not fit vertically.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_image_place':
				$places = array(
					'center'       => __( 'Centred', 'media-domain-replace' ),
					'bottom-right' => __( 'Bottom right', 'media-domain-replace' ),
					'bottom-left'  => __( 'Bottom left', 'media-domain-replace' ),
					'top-right'    => __( 'Top right', 'media-domain-replace' ),
					'top-left'     => __( 'Top left', 'media-domain-replace' ),
				);
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $places as $place => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $place ),
						selected( $place, $value, false ),
						esc_html( $label )
					);
				}
				echo '</select>';
				break;

			case 'watermark_image_rotate':
				self::checkbox( $id, $name, $value, __( 'Tilt the overlay by the diagonal angle above', 'media-domain-replace' ) );
				break;

			case 'watermark_bands':
				$choices = array(
					'diagonal' => __( 'Diagonal band across the centre', 'media-domain-replace' ),
					'bottom'   => __( 'Bottom band', 'media-domain-replace' ),
					'center'   => __( 'Centre band', 'media-domain-replace' ),
					'top'      => __( 'Top band', 'media-domain-replace' ),
				);
				$selected = self::bands();

				echo '<fieldset>';
				foreach ( $choices as $choice => $label ) {
					printf(
						'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
						esc_attr( $name ),
						esc_attr( $choice ),
						checked( true, in_array( $choice, $selected, true ), false ),
						esc_html( $label )
					);
				}
				echo '</fieldset>';
				echo '<p class="description">' . esc_html__( 'Pick as many as you like. They are drawn in the order listed, so the diagonal sits underneath the others.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_angle':
				printf(
					'<input type="number" min="-60" max="60" step="1" class="small-text" id="%1$s" name="%2$s" value="%3$s"> %4$s',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_html__( 'degrees', 'media-domain-replace' )
				);
				echo '<p class="description">' . esc_html__( 'Tilt of the diagonal band. Positive angles rise to the right; 0 gives a straight band through the centre.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_opacity':
				printf(
					'<input type="number" min="0" max="100" step="5" class="small-text" id="%1$s" name="%2$s" value="%3$s"> %%',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				echo '<p class="description">' . esc_html__( 'Darkness of the band behind the text, and the strength of the uploaded image. Band text itself stays solid white.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_original':
				self::checkbox( $id, $name, $value, __( 'Stamp the full size file as well as the thumbnails', 'media-domain-replace' ) );
				echo '<p class="description">' . esc_html__( 'This edits the uploaded file in place and cannot be undone, which is the point on a local site. Turn it off to leave full size images clean.', 'media-domain-replace' ) . '</p>';
				break;

			case 'watermark_font_face':
				$faces = array(
					'poppins' => __( 'Poppins SemiBold (bundled)', 'media-domain-replace' ),
					'custom'  => __( 'Custom TrueType file', 'media-domain-replace' ),
					'gd'      => __( 'Built in bitmap font', 'media-domain-replace' ),
				);
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $faces as $face => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $face ),
						selected( $face, $value, false ),
						esc_html( $label )
					);
				}
				echo '</select>';

				if ( ! function_exists( 'imagettftext' ) ) {
					echo '<p class="description"><strong>' . esc_html__( 'FreeType support is missing from this PHP install, so the bitmap font will be used whatever is selected here.', 'media-domain-replace' ) . '</strong></p>';
				} else {
					echo '<p class="description">' . esc_html__( 'Poppins is bundled with the plugin under the SIL Open Font License. Text is fitted to the band and letter spaced automatically.', 'media-domain-replace' ) . '</p>';
				}
				break;

			case 'watermark_font':
				self::text( $id, $name, $value, '/path/to/font.ttf' );
				echo '<p class="description">' . esc_html__( 'Absolute path to a .ttf file. Only used when the font above is set to Custom.', 'media-domain-replace' ) . '</p>';
				break;

			case 'migrate_theme':
				self::text( $id, $name, $value, 'old-theme-slug' );
				echo '<p class="description">' . esc_html__( 'Optional and unrelated to media. When you switch themes, copy nav menu locations from this theme slug&#8217;s saved theme mods. Leave blank to skip.', 'media-domain-replace' ) . '</p>';
				break;
		}
	}

	private static function checkbox( $id, $name, $value, $label ) {
		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
			esc_attr( $id ),
			esc_attr( $name ),
			checked( 1, $value, false ),
			esc_html( $label )
		);
	}

	private static function text( $id, $name, $value, $placeholder = '' ) {
		printf(
			'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s">',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$local   = self::local_host();
		$remote  = self::remote_host();
		$enabled = ! empty( self::get( 'enabled' ) );
		$active  = $enabled && '' !== $remote && '' !== $local && $local !== $remote;

		$processed = isset( $_GET['mdr_processed'] ) ? (int) $_GET['mdr_processed'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Media Domain Replace', 'media-domain-replace' ); ?></h1>

			<?php if ( $processed >= 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of attachments */
							esc_html( _n( 'Watermarked %d attachment.', 'Watermarked %d attachments.', $processed, 'media-domain-replace' ) ),
							(int) $processed
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="notice inline <?php echo $active ? 'notice-success' : 'notice-warning'; ?>" style="margin:15px 0;padding:10px 12px;">
				<p style="margin:0;">
					<strong><?php echo $active ? esc_html__( 'Active.', 'media-domain-replace' ) : esc_html__( 'Not active.', 'media-domain-replace' ); ?></strong>
					<?php if ( $active ) : ?>
						<?php
						printf(
							/* translators: 1: local host, 2: remote host */
							esc_html__( 'Media requested from %1$s is being served from %2$s, except for files that exist locally.', 'media-domain-replace' ),
							'<code>' . esc_html( $local ) . '</code>',
							'<code>' . esc_html( $remote ) . '</code>'
						);
						?>
					<?php elseif ( ! $enabled ) : ?>
						<?php esc_html_e( 'Rewriting is switched off below.', 'media-domain-replace' ); ?>
					<?php elseif ( '' === $remote ) : ?>
						<?php esc_html_e( 'Set a remote media domain below to get started.', 'media-domain-replace' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'The local and remote domains are the same, so there is nothing to rewrite.', 'media-domain-replace' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<form action="options.php" method="post" enctype="multipart/form-data">
				<?php
				settings_fields( 'mdr_settings_group' );
				do_settings_sections( 'media-domain-replace' );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Tools', 'media-domain-replace' ); ?></h2>
			<p><?php esc_html_e( 'Apply the watermark to local uploads that were added before you switched the feature on. Runs in batches of 50.', 'media-domain-replace' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="mdr_watermark_batch">
				<?php wp_nonce_field( 'mdr_watermark_batch' ); ?>
				<?php submit_button( __( 'Watermark existing local uploads', 'media-domain-replace' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Detected values', 'media-domain-replace' ); ?></h2>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr>
						<td style="width:220px;"><?php esc_html_e( 'Site Address (home_url)', 'media-domain-replace' ); ?></td>
						<td><code><?php echo esc_html( home_url() ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Local host in use', 'media-domain-replace' ); ?></td>
						<td><code><?php echo esc_html( $local ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Uploads path', 'media-domain-replace' ); ?></td>
						<td><code><?php echo esc_html( self::uploads_path() ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Uploads folder', 'media-domain-replace' ); ?></td>
						<td><code><?php echo esc_html( self::uploads_dir() ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'GD image library', 'media-domain-replace' ); ?></td>
						<td><?php echo self::watermarking_available() ? esc_html__( 'Available', 'media-domain-replace' ) : esc_html__( 'Missing', 'media-domain-replace' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function uninstall() {
		self::delete_watermark_image();
		delete_option( self::OPTION );
		delete_option( 'mdr_version' );
	}
}

MDR_Media_Domain_Replace::init();
register_uninstall_hook( MDR_PLUGIN_FILE, array( 'MDR_Media_Domain_Replace', 'uninstall' ) );
