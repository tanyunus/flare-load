=== FlarePress ===
Contributors: tanyunus
Tags: cloudflare, images, cdn, media, upload
Requires at least: 5.9
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload media directly to Cloudflare Images from the WordPress media library.

== Description ==

FlarePress integrates Cloudflare Images as a direct upload destination alongside the default WordPress media uploader. Instead of storing images on your server, you can send them directly to Cloudflare's global CDN — with automatic delivery optimization and variant support built in.

**Features:**

* Upload images directly to Cloudflare Images from the media library and block editor
* Keep or delete local copies after upload — your choice
* Automatic local thumbnail generation (avoids counting against Cloudflare delivery quotas)
* Cloudflare image variant support with per-image variant selection in the block editor
* Visual indicator in the media library list view for Cloudflare-hosted images
* Sync variants from your Cloudflare account with a single click
* Activity log viewer for debugging

== Installation ==

1. Upload the `flare-press` folder to the `/wp-content/plugins/` directory, or install it via the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **FlarePress** in the admin menu and enter your Cloudflare credentials.
4. Click **Sync Variants** to import your Cloudflare image variants.
5. Set a default variant and save.

== Frequently Asked Questions ==

= Where do I find my Cloudflare Account ID? =

Log in to the Cloudflare dashboard. Your Account ID is displayed on the right-hand side of the main dashboard page, under **API**.

= Where do I find my Account Hash? =

In the Cloudflare dashboard, go to **Images**. The delivery URL shown in the sidebar contains your Account Hash in the format `https://imagedelivery.net/{account_hash}/`.

= How do I create an API Token? =

Go to **My Profile → API Tokens** in the Cloudflare dashboard. Create a token with the **Cloudflare Images: Edit** permission scoped to your account.

= Will my existing media be affected? =

No. FlarePress only intercepts uploads where you explicitly choose to upload to Cloudflare. Existing media and standard uploads are unaffected.

= What happens to local files after upload? =

By default, local files are deleted after a successful Cloudflare upload to save disk space. You can change this behaviour in the plugin settings under **File Management**.

= What happens to Cloudflare images when I delete from the media library? =

By default, deleting an attachment from the WordPress media library also removes it from Cloudflare Images. You can disable this in the plugin settings.

== Screenshots ==

1. Plugin settings page with API credentials and variant management.
2. Media library list view showing the Cloudflare location badge.
3. Block editor with the "Upload to Cloudflare" button on image blocks.

== Third-party Services ==

This plugin communicates with the **Cloudflare Images API** to upload, retrieve, and delete images. Requests are made when you upload an image to Cloudflare from the media library, delete a Cloudflare-hosted image, or sync your image variants from the plugin settings page.

Your Cloudflare Account ID, API Token, and uploaded image files are transmitted to Cloudflare's servers.

* [Cloudflare Images documentation](https://developers.cloudflare.com/images/)
* [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)
* [Cloudflare Terms of Service](https://www.cloudflare.com/terms/)

== Changelog ==

= 1.0.0 =
* Initial release.
* Direct upload to Cloudflare Images from the media library and block editor.
* Cloudflare image variant support with per-image variant selection.
* Visual location badge in media library list view.
* Activity log viewer for debugging.
* Migrate to Local tool to move Cloudflare-hosted images back to WordPress.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
