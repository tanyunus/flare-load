<?php

defined('ABSPATH') || exit;

/**
 * FlareLoad test data seeder
 *
 * Creates 5000 posts for migration stress testing:
 *   - 2500 posts each with an image genuinely uploaded to Cloudflare Images
 *   - 2500 posts each with a standard local WordPress attachment
 *
 * Usage (from WordPress root):
 *   wp eval-file wp-content/plugins/flare-load/tools/flareload-seed.php
 *   wp eval-file wp-content/plugins/flare-load/tools/flareload-seed.php cleanup
 *
 * Cleanup deletes seeded WP posts/attachments, local files,
 * and the corresponding Cloudflare Images.
 */

// ── Config ────────────────────────────────────────────────────────────────────
const FLARELOAD_SEED_CF          = 2500;
const FLARELOAD_SEED_LOCAL       = 2500;
const FLARELOAD_SEED_META        = '_FLARELOAD_seed';
const FLARELOAD_SEED_CF_DELAY_US = 250000; // 250 ms between CF uploads ≈ 4 req/s

// ── Dispatch ──────────────────────────────────────────────────────────────────
$mode = in_array('cleanup', array_slice($GLOBALS['argv'] ?? [], 1)) ? 'cleanup' : 'seed';
$mode === 'cleanup' ? FLARELOAD_seed_cleanup() : FLARELOAD_seed_run();

// ── Seed ──────────────────────────────────────────────────────────────────────
function FLARELOAD_seed_run(): void
{
    $accountHash = get_option('FLARELOAD_cf_account_hash', '');
    $accountId   = get_option('FLARELOAD_cf_account_id', '');
    $apiToken    = get_option('FLARELOAD_cf_api_token', '');

    if (!$accountHash || !$accountId || !$apiToken) {
        WP_CLI::error('Missing Cloudflare credentials. Configure the FlareLoad plugin settings first.');
    }

    $uploadDir = wp_upload_dir();
    $seedDir   = $uploadDir['basedir'] . '/flareload-seed';

    if (!wp_mkdir_p($seedDir)) {
        WP_CLI::error("Could not create seed directory: {$seedDir}");
    }

    $samplePath = FLARELOAD_seed_sample_image($seedDir);
    WP_CLI::log("Sample image : {$samplePath}");
    WP_CLI::log("Account hash : {$accountHash}");

    // ── Phase 1: Cloudflare uploads ───────────────────────────────────────────
    WP_CLI::log('Phase 1/2 — Uploading ' . FLARELOAD_SEED_CF . ' images to Cloudflare (~' . round(FLARELOAD_SEED_CF * FLARELOAD_SEED_CF_DELAY_US / 1e6 / 60, 0) . ' min)…');
    $bar = WP_CLI\Utils\make_progress_bar('CF uploads', FLARELOAD_SEED_CF);

    for ($i = 1; $i <= FLARELOAD_SEED_CF; $i++) {
        $filename = "flareload-seed-cf-{$i}.jpg";

        try {
            $result = \FlareLoad\Api\CloudflareImagesApi::uploadImage($samplePath, $filename);
        } catch (\Exception $e) {
            WP_CLI::warning("CF upload #{$i} failed: " . $e->getMessage());
            $bar->tick();
            usleep(FLARELOAD_SEED_CF_DELAY_US);
            continue;
        }

        $cfId      = $result['result']['id'];
        $cfBaseUrl = "https://imagedelivery.net/{$accountHash}/{$cfId}";
        $thumbPath = FLARELOAD_seed_make_thumb($samplePath, $seedDir, "flareload-seed-cf-thumb-{$i}.jpg");

        $postId = FLARELOAD_seed_insert([
            'post_title'   => "FP Seed CF Post #{$i}",
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_content' => '',
        ]);

        if (is_wp_error($postId)) {
            WP_CLI::warning("Post #{$i} (CF) failed: " . $postId->get_error_message());
            FLARELOAD_seed_cf_delete($cfId);
            $bar->tick();
            usleep(FLARELOAD_SEED_CF_DELAY_US);
            continue;
        }

        update_post_meta($postId, FLARELOAD_SEED_META, 1);

        $attachmentId = FLARELOAD_seed_insert([
            'post_title'     => "FP Seed CF Image #{$i}",
            'post_status'    => 'inherit',
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'post_parent'    => $postId,
            'guid'           => $cfId,
        ]);

        if (is_wp_error($attachmentId)) {
            WP_CLI::warning("Attachment #{$i} (CF) failed: " . $attachmentId->get_error_message());
            wp_delete_post($postId, true);
            FLARELOAD_seed_cf_delete($cfId);
            $bar->tick();
            usleep(FLARELOAD_SEED_CF_DELAY_US);
            continue;
        }

        update_post_meta($attachmentId, FLARELOAD_SEED_META, 1);
        update_post_meta($attachmentId, 'FLARELOAD_cf_image_id', $cfId);
        update_attached_file($attachmentId, $cfId);

        wp_update_attachment_metadata($attachmentId, [
            'file'            => '',
            'width'           => 100,
            'height'          => 100,
            'FLARELOAD_cf_image_id'  => $cfId,
            'FLARELOAD_cf_file_name' => $filename,
            'FLARELOAD_cf_thumbnail' => $thumbPath ? ['path' => $thumbPath, 'width' => 100, 'height' => 100] : false,
            'filesize'        => filesize($samplePath),
            'sizes'           => [],
        ]);

        $blockContent = sprintf(
            "<!-- wp:image {\"id\":%d,\"sizeSlug\":\"full\"} -->\n" .
            "<figure class=\"wp-block-image size-full\">" .
            "<img src=\"%s/public\" alt=\"\" class=\"wp-image-%d\"/>" .
            "</figure>\n<!-- /wp:image -->",
            $attachmentId, $cfBaseUrl, $attachmentId
        );
        wp_update_post(['ID' => $postId, 'post_content' => $blockContent]);

        $bar->tick();
        usleep(FLARELOAD_SEED_CF_DELAY_US);

        if ($i % 250 === 0) {
            wp_cache_flush();
        }
    }

    $bar->finish();
    WP_CLI::success('Phase 1 complete — ' . FLARELOAD_SEED_CF . ' CF posts created.');

    // ── Phase 2: Local uploads ────────────────────────────────────────────────
    WP_CLI::log('Phase 2/2 — Creating ' . FLARELOAD_SEED_LOCAL . ' local-upload posts…');
    $bar = WP_CLI\Utils\make_progress_bar('Local uploads', FLARELOAD_SEED_LOCAL);

    $localDir = $uploadDir['basedir'] . '/flareload-seed-local';
    $localUrl = $uploadDir['baseurl'] . '/flareload-seed-local';

    if (!wp_mkdir_p($localDir)) {
        WP_CLI::error("Could not create local seed directory: {$localDir}");
    }

    for ($i = 1; $i <= FLARELOAD_SEED_LOCAL; $i++) {
        $filename = "flareload-seed-local-{$i}.jpg";
        $filePath = $localDir . '/' . $filename;
        $fileUrl  = $localUrl . '/' . $filename;

        copy($samplePath, $filePath);

        $postId = FLARELOAD_seed_insert([
            'post_title'   => "FP Seed Local Post #{$i}",
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_content' => '',
        ]);

        if (is_wp_error($postId)) {
            WP_CLI::warning("Post #{$i} (local) failed: " . $postId->get_error_message());
            wp_delete_file($filePath);
            $bar->tick();
            continue;
        }

        update_post_meta($postId, FLARELOAD_SEED_META, 1);

        $attachmentId = wp_insert_attachment([
            'post_title'     => "FP Seed Local Image #{$i}",
            'post_status'    => 'inherit',
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'post_parent'    => $postId,
            'guid'           => $fileUrl,
        ], $filePath, $postId, true);

        if (is_wp_error($attachmentId)) {
            WP_CLI::warning("Attachment #{$i} (local) failed: " . $attachmentId->get_error_message());
            wp_delete_post($postId, true);
            wp_delete_file($filePath);
            $bar->tick();
            continue;
        }

        update_post_meta($attachmentId, FLARELOAD_SEED_META, 1);

        wp_update_attachment_metadata($attachmentId, [
            'width'    => 100,
            'height'   => 100,
            'file'     => 'flareload-seed-local/' . $filename,
            'filesize' => filesize($filePath),
            'sizes'    => [],
        ]);

        $blockContent = sprintf(
            "<!-- wp:image {\"id\":%d,\"sizeSlug\":\"full\"} -->\n" .
            "<figure class=\"wp-block-image size-full\">" .
            "<img src=\"%s\" alt=\"\" class=\"wp-image-%d\"/>" .
            "</figure>\n<!-- /wp:image -->",
            $attachmentId, $fileUrl, $attachmentId
        );
        wp_update_post(['ID' => $postId, 'post_content' => $blockContent]);

        $bar->tick();
        usleep(5000);

        if ($i % 500 === 0) {
            wp_cache_flush();
        }
    }

    $bar->finish();
    WP_CLI::success(
        'Seeding complete — ' . FLARELOAD_SEED_CF . ' CF posts + ' . FLARELOAD_SEED_LOCAL . ' local posts = ' .
        (FLARELOAD_SEED_CF + FLARELOAD_SEED_LOCAL) . ' total.'
    );
}

// ── Cleanup ───────────────────────────────────────────────────────────────────
function FLARELOAD_seed_cleanup(): void
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev-only seed cleanup tool; direct query required to find all seeded posts by meta key.
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            FLARELOAD_SEED_META
        )
    );

    if (empty($ids)) {
        WP_CLI::success('Nothing to clean up — no seeded posts found.');
        return;
    }

    WP_CLI::log('Deleting ' . count($ids) . ' seeded posts/attachments…');
    $bar = WP_CLI\Utils\make_progress_bar('Cleanup', count($ids));

    foreach ($ids as $id) {
        $cfId = get_post_meta((int) $id, 'FLARELOAD_cf_image_id', true);
        if ($cfId) {
            FLARELOAD_seed_cf_delete($cfId);
            usleep(50000); // 50 ms rate limiting for CF deletes
        }
        wp_delete_post((int) $id, true);
        $bar->tick();
    }

    $bar->finish();

    $uploadDir = wp_upload_dir();
    foreach (['flareload-seed', 'flareload-seed-local'] as $dir) {
        $path = $uploadDir['basedir'] . '/' . $dir;
        if (is_dir($path)) {
            FLARELOAD_seed_rmdir($path);
            WP_CLI::log("Removed: {$path}");
        }
    }

    WP_CLI::success('Cleanup complete.');
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** wp_insert_post with exponential-backoff retry for transient SQLite errors. */
function FLARELOAD_seed_insert(array $postarr)
{
    $delays = [50000, 150000, 400000, 1000000];
    $result = wp_insert_post($postarr, true);
    foreach ($delays as $delay) {
        if (!is_wp_error($result)) {
            return $result;
        }
        usleep($delay);
        $result = wp_insert_post($postarr, true);
    }
    return $result;
}

/** Delete a CF image, silently ignoring errors (already deleted, not found, etc.). */
function FLARELOAD_seed_cf_delete(string $cfId): void
{
    try {
        \FlareLoad\Api\CloudflareImagesApi::deleteImage($cfId);
    } catch (\Exception $_) {}
}

/** Copy sample image to a new path and return the destination path, or false on failure. */
function FLARELOAD_seed_make_thumb(string $src, string $dir, string $name): string|false
{
    $dest = $dir . '/' . $name;
    return copy($src, $dest) ? $dest : false;
}

/**
 * Returns path to a 100×100 sample JPEG, creating it once if needed.
 * Uses GD if available; falls back to a hardcoded minimal JPEG.
 */
function FLARELOAD_seed_sample_image(string $dir): string
{
    $path = $dir . '/flareload-seed-sample.jpg';
    if (file_exists($path)) {
        return $path;
    }

    if (function_exists('imagecreatetruecolor')) {
        $img   = imagecreatetruecolor(100, 100);
        $color = imagecolorallocate($img, 99, 179, 237);
        imagefill($img, 0, 0, $color);
        imagejpeg($img, $path, 85);
        imagedestroy($img);
        return $path;
    }

    // Fallback: minimal valid JPEG (~200 bytes, 1×1 grey pixel)
    file_put_contents($path, base64_decode(
        '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8U' .
        'HRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAARC' .
        'AABAAEDASIA' .
        'AhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAABgUEB' .
        'v/EAB8QAAIBBAMBAAAAAAAAAAAAAAABAgMEERIhMf/a' .
        'AAwDAQACEQMRAD8AreRXFtbTyGNpCuAzqMkDPbNeU1q' .
        '8neV2kYljkk96KKbGjNs//9k='
    ));
    return $path;
}

/** Recursively remove a directory. */
function FLARELOAD_seed_rmdir(string $dir): void
{
    foreach (glob($dir . '/*') as $item) {
        is_dir($item) ? FLARELOAD_seed_rmdir($item) : wp_delete_file($item);
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    rmdir($dir);
}
