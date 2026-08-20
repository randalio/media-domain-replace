# Media Domain Replace

A local development plugin for WordPress. It serves media from a remote site when the files
are not present locally, keeps locally uploaded files on the local domain, and stamps those
local uploads so they are never mistaken for real content.

## Why

Pull a production database down to a local site and every media URL points at the local host,
where the uploads folder is empty. This rewrites those URLs to the live server at output time,
without touching the database.

## What it does

- **Rewrites media URLs** from the local host to a configured remote host. Nothing is written
  to the database; removing the plugin restores local URLs immediately.
- **Leaves local files alone.** If a file exists in the local uploads folder, its URL is not
  rewritten, so anything uploaded locally keeps working.
- **Watermarks local uploads** across the full size image and every generated size, so a test
  image is obvious at a glance.

## Settings

Media → Media Domain.

| Setting | Notes |
| --- | --- |
| Local sites only | Refuses to do anything on hosts that don't look like development. On by default. |
| Additional local hosts | Hosts to treat as local anyway, one per line. |
| Remote media domain | Host the media actually lives on. Scheme and path are stripped on save. |
| Local domain override | Blank auto-detects host and port from `home_url()`. |
| Uploads only | Restricts rewriting to the uploads path. Recommended. |
| Media Library and editor | Loads remote media in the gallery, media modal and block editor. |
| Restore local URLs on save | Swaps remote uploads URLs back to local before anything hits the database. |
| Preserve local files | Skips rewriting when the file exists on disk. |
| Watermark style | Text band, uploaded image, or both. |
| Watermark text | Drawn in upper case. |
| Bands | Diagonal, bottom, centre, top. Stackable. |
| Diagonal angle | Default 13 degrees. |
| Opacity | Band darkness and overlay strength. |
| Watermark image | PNG, GIF, WebP, JPEG, or SVG where Imagick can rasterise it. |
| Font | Bundled Poppins SemiBold, a custom TTF, or GD's bitmap font. |

## Requirements

- WordPress 5.6+, PHP 7.2+
- GD for watermarking, with FreeType for TrueType text
- Imagick with an SVG delegate, only if you want to use an SVG watermark

## Hooks

```php
// Decide for yourself what counts as a development site.
add_filter( 'mdr_is_local_environment', fn( $local, $host ) => $local, 10, 2 );

// Add hostname suffixes that count as local.
add_filter( 'mdr_local_suffixes', fn( $suffixes ) => array_merge( $suffixes, array( '.box' ) ) );

// Skip rewriting on a given request.
add_filter( 'mdr_should_run', '__return_false' );

// Swap the watermark font.
add_filter( 'mdr_watermark_font', fn( $path ) => '/path/to/Other.ttf' );

// Rewrite URLs in your own fields.
add_action( 'mdr_filters_registered', function () {
    add_filter( 'my_custom_field', array( 'MDR_Media_Domain_Replace', 'filter_content' ) );
} );
```

## Safety

Both halves of this plugin are destructive in the wrong place, so by default it refuses to run
anywhere that does not look like a development site. Recognised as local:

- `localhost` and private or reserved IP addresses
- The suffixes `.local`, `.test`, `.localhost`, `.invalid`, `.example`, `.internal`,
  `.ddev.site`, `.lndo.site`, `.vagrant`, `.wip`
- Any site declaring `WP_ENVIRONMENT_TYPE` as `local` or `development`
- Anything listed in the Additional local hosts setting

A site declaring `WP_ENVIRONMENT_TYPE` as `production` or `staging` is never treated as local,
even on a `.local` hostname. When the check fails, the plugin does nothing and says so in an
admin notice.

This is an allow list rather than a list of blocked public suffixes, because a blocked list
would have to cover well over a thousand entries and any omission fails open on a live site.

## Notes

- Watermarking edits files in place and cannot be undone.
- Remote images cannot be cropped or rotated with the built in image editor, since the file is
  not on this machine.
- The watermark image is stored in `uploads/mdr-watermark/` rather than the media library, so
  it never gets watermarked itself.

## License

GPL-2.0-or-later.

Bundles Poppins SemiBold by the Poppins Project Authors under the SIL Open Font License 1.1.
See `fonts/OFL.txt`.
