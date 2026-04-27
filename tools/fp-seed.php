<?php
/**
 * FlarePress test data seeder
 *
 * Creates mock Cloudflare Images attachments and posts for stress-testing
 * the Migrate-to-Local wizard.
 *
 * Usage (from WordPress root):
 *   wp eval-file wp-content/plugins/flare-press/tools/fp-seed.php
 *   wp eval-file wp-content/plugins/flare-press/tools/fp-seed.php cleanup
 *
 * What it creates:
 *   - FP_SEED_POSTS posts (default 5000), each with one attachment
 *   - Every attachment has a fake fp_cf_image_id and a CF URL in post_content
 *   - FP_SEED_LOCAL attachments (default 1000) also have a real local file on
 *     disk so getLocalFile() returns a path → migration uses "local_copy" path
 *   - The remaining posts have CF meta only → migration tries to download from CF
 *     (will fail for fake IDs, useful for testing error/retry handling)
 *
 * Cleanup removes every post/attachment that has _fp_seed=1 meta.
 */

// ── Config ────────────────────────────────────────────────────────────────────
const FP_SEED_POSTS = 5000;
const FP_SEED_LOCAL = 1000;
const FP_SEED_META  = '_fp_seed';

// ── Dispatch ──────────────────────────────────────────────────────────────────
$mode = in_array('cleanup', array_slice($GLOBALS['argv'] ?? [], 1)) ? 'cleanup' : 'seed';

if ($mode === 'cleanup') {
    fp_seed_cleanup();
} else {
    fp_seed_run();
}

// ── Seed ──────────────────────────────────────────────────────────────────────
function fp_seed_run(): void
{
    $accountHash = get_option('fp_cf_account_hash', 'TESTHASH');
    $uploadDir   = wp_upload_dir();
    $seedDir     = $uploadDir['basedir'] . '/fp-seed';

    if (!wp_mkdir_p($seedDir)) {
        WP_CLI::error("Could not create seed directory: {$seedDir}");
    }

    // Prepare a real 100×100 JPEG to use as local image / thumbnail
    $samplePath = fp_seed_sample_image($seedDir);
    WP_CLI::log("Sample image: {$samplePath}");
    WP_CLI::log("Account hash in use: {$accountHash}");
    WP_CLI::log('Creating ' . FP_SEED_POSTS . ' posts (' . FP_SEED_LOCAL . ' with local copies)…');

    // Pre-compute which indices get a local copy (first FP_SEED_LOCAL)
    $localIndices = array_flip(range(1, FP_SEED_LOCAL));

    $bar = WP_CLI\Utils\make_progress_bar('Seeding', FP_SEED_POSTS);

    for ($i = 1; $i <= FP_SEED_POSTS; $i++) {
        $cfId       = sprintf('fp-test-%05d', $i);
        $isLocal    = isset($localIndices[$i]);
        $filename   = "fp-seed-image-{$i}.jpg";
        $thumbFile  = "fp-seed-thumb-{$i}.jpg";
        $thumbPath  = $seedDir . '/' . $thumbFile;
        $cfBaseUrl  = "https://imagedelivery.net/{$accountHash}/{$cfId}";

        // ── Local file ───────────────────────────────────────────────────────
        // The thumbnail is always copied so the wizard can show a preview.
        // For local-copy attachments, the full-size file is also present so
        // getLocalFile() finds it at dirname(thumbPath)/filename.
        copy($samplePath, $thumbPath);
        if ($isLocal) {
            copy($samplePath, $seedDir . '/' . $filename);
        }

        // ── Post ─────────────────────────────────────────────────────────────
        $postId = wp_insert_post([
            'post_title'   => "FP Seed Post #{$i}",
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_content' => '', // filled below after we have attachment ID
        ], true);

        if (is_wp_error($postId)) {
            WP_CLI::warning("Post #{$i} failed: " . $postId->get_error_message());
            $bar->tick();
            continue;
        }

        update_post_meta($postId, FP_SEED_META, 1);

        // ── Attachment ───────────────────────────────────────────────────────
        $attachmentId = wp_insert_post([
            'post_title'     => "FP Seed Image #{$i}",
            'post_status'    => 'inherit',
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'post_parent'    => $postId,
            'guid'           => $cfBaseUrl . '/public',
        ], true);

        if (is_wp_error($attachmentId)) {
            WP_CLI::warning("Attachment #{$i} failed: " . $attachmentId->get_error_message());
            $bar->tick();
            continue;
        }

        update_post_meta($attachmentId, FP_SEED_META, 1);
        update_post_meta($attachmentId, 'fp_cf_image_id', $cfId);

        // _wp_attachment_metadata — mirrors real plugin upload structure
        $meta = [
            'width'          => 100,
            'height'         => 100,
            'file'           => 'fp-seed/' . ($isLocal ? $filename : "placeholder-{$i}.jpg"),
            'fp_cf_file_name'    => $filename,
            'fp_cf_thumbnail'    => ['path' => $thumbPath],
        ];
        wp_update_attachment_metadata($attachmentId, $meta);

        // ── Post content with embedded CF URL ────────────────────────────────
        // Mirrors what the block editor saves so updatePostContent() can rewrite it.
        $blockContent = sprintf(
            "<!-- wp:image {\"id\":%d,\"sizeSlug\":\"full\"} -->\n" .
            "<figure class=\"wp-block-image size-full\">" .
            "<img src=\"%s/public\" alt=\"\" class=\"wp-image-%d\"/>" .
            "</figure>\n<!-- /wp:image -->",
            $attachmentId,
            $cfBaseUrl,
            $attachmentId
        );

        wp_update_post(['ID' => $postId, 'post_content' => $blockContent]);

        $bar->tick();

        // Avoid memory creep on long runs
        if ($i % 500 === 0) {
            wp_cache_flush();
        }
    }

    $bar->finish();

    $localLabel  = number_format(FP_SEED_LOCAL);
    $remoteLabel = number_format(FP_SEED_POSTS - FP_SEED_LOCAL);
    WP_CLI::success(
        'Seeding complete. ' .
        FP_SEED_POSTS . ' posts created. ' .
        "{$localLabel} attachments have a local copy (will use local_copy path), " .
        "{$remoteLabel} CF-only (will attempt remote download → expect failures for fake IDs)."
    );
}

// ── Cleanup ───────────────────────────────────────────────────────────────────
function fp_seed_cleanup(): void
{
    global $wpdb;

    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            FP_SEED_META
        )
    );

    if (empty($ids)) {
        WP_CLI::success('Nothing to clean up — no seeded posts found.');
        return;
    }

    WP_CLI::log('Deleting ' . count($ids) . ' seeded posts/attachments…');
    $bar = WP_CLI\Utils\make_progress_bar('Cleanup', count($ids));

    foreach ($ids as $id) {
        wp_delete_post((int) $id, true); // force-delete, skip trash
        $bar->tick();
    }

    $bar->finish();

    // Remove leftover seed files
    $uploadDir = wp_upload_dir();
    $seedDir   = $uploadDir['basedir'] . '/fp-seed';
    if (is_dir($seedDir)) {
        fp_seed_rmdir($seedDir);
        WP_CLI::log("Removed seed directory: {$seedDir}");
    }

    WP_CLI::success('Cleanup complete.');
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Returns path to a 100×100 sample JPEG, creating it if needed.
 * Uses GD if available; falls back to a hardcoded minimal JPEG.
 */
function fp_seed_sample_image(string $dir): string
{
    $path = $dir . '/fp-seed-sample.jpg';
    if (file_exists($path)) {
        return $path;
    }

    if (function_exists('imagecreatetruecolor')) {
        $img   = imagecreatetruecolor(100, 100);
        $color = imagecolorallocate($img, 99, 179, 237); // light blue
        imagefill($img, 0, 0, $color);
        imagejpeg($img, $path, 85);
        imagedestroy($img);
        return $path;
    }

    // Fallback: minimal valid JPEG (1×1 grey pixel, ~200 bytes)
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
function fp_seed_rmdir(string $dir): void
{
    foreach (glob($dir . '/*') as $item) {
        is_dir($item) ? fp_seed_rmdir($item) : unlink($item);
    }
    rmdir($dir);
}
