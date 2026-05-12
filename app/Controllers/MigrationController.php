<?php

namespace FlarePress\Controllers;

defined('ABSPATH') || exit;

use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Logger;

class MigrationController
{
    /**
     * Builds a summary of which CF attachments have a local copy, need downloading,
     * or have no variant — shown on the pre-migration review screen.
     */
    public static function analyzeImages(string $scope, array $selectedIds = []): array
    {
        $ids = self::resolveAttachmentIds($scope, $selectedIds);

        $result = [
            'total'           => count($ids),
            'local_copy'      => 0,
            'download_needed' => 0,
            'no_variant'      => 0,
            'images'          => [],
        ];

        foreach ($ids as $id) {
            $localFile = self::getLocalFile($id);
            $cfId      = AttachmentController::getCloudflareIdOfAttachment($id);

            if ($localFile) {
                $status = 'local_copy';
                $result['local_copy']++;
            } elseif ($cfId) {
                $status = 'download_needed';
                $result['download_needed']++;
            } else {
                $status = 'no_variant';
                $result['no_variant']++;
            }

            $post         = get_post($id);
            $parentId     = $post ? (int) $post->post_parent : 0;
            $parent       = $parentId ? get_post($parentId) : null;

            if (!$parent && $cfId) {
                $parent   = self::findPostUsingCfImage($cfId);
                $parentId = $parent ? (int) $parent->ID : 0;
            }

            if (!$parent) {
                $parent   = self::findPostUsingAttachmentId($id);
                $parentId = $parent ? (int) $parent->ID : 0;
            }

            $result['images'][] = [
                'id'           => $id,
                'title'        => get_the_title($id) ?: self::getOriginalFilename($id),
                'thumbnail'    => self::getThumbnailUrl($id),
                'status'       => $status,
                'parent_title' => $parent ? ($parent->post_title ?: '#' . $parent->ID) : '',
            ];
        }

        return $result;
    }

    /**
     * Returns one page of CF attachments for the image selector.
     * Loads all IDs upfront (a cheap integer-only query), then slices to the requested page.
     */
    public static function listImages(string $scope, int $page, int $perPage): array
    {
        $allIds     = self::resolveAttachmentIds($scope, []);
        $total      = count($allIds);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = max(1, min($page, $totalPages));
        $pageIds    = array_slice($allIds, ($page - 1) * $perPage, $perPage);

        $images = [];
        foreach ($pageIds as $id) {
            $localFile = self::getLocalFile($id);
            $cfId      = AttachmentController::getCloudflareIdOfAttachment($id);

            if ($localFile) {
                $status = 'local_copy';
            } elseif ($cfId) {
                $status = 'download_needed';
            } else {
                $status = 'no_variant';
            }

            $post     = get_post($id);
            $parentId = $post ? (int) $post->post_parent : 0;
            $parent   = $parentId ? get_post($parentId) : null;

            if (!$parent && $cfId) {
                $parent   = self::findPostUsingCfImage($cfId);
                $parentId = $parent ? (int) $parent->ID : 0;
            }

            if (!$parent) {
                $parent   = self::findPostUsingAttachmentId($id);
                $parentId = $parent ? (int) $parent->ID : 0;
            }

            $images[] = [
                'id'           => $id,
                'title'        => get_the_title($id) ?: self::getOriginalFilename($id),
                'thumbnail'    => self::getThumbnailUrl($id),
                'status'       => $status,
                'parent_id'    => $parentId,
                'parent_title' => $parent ? ($parent->post_title ?: '#' . $parent->ID) : '',
                'parent_url'   => $parentId ? (string) get_edit_post_link($parentId, 'raw') : '',
            ];
        }

        return [
            'total'       => $total,
            'total_pages' => $totalPages,
            'page'        => $page,
            'images'      => $images,
        ];
    }

    /**
     * Migrates one attachment: uses the local original if it still exists on disk,
     * otherwise downloads the chosen CF variant. Restores as a standard WordPress attachment.
     */
    public static function processImage(int $attachmentId, string $variant, bool $deleteFromCF): array
    {
        try {
            $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

            if (!$cfId) {
                return ['status' => 'skip', 'reason' => 'No Cloudflare image ID found.'];
            }

            $localFile = self::getLocalFile($attachmentId);

            if ($localFile) {
                $filePath = $localFile;
                $source   = 'local_copy';
            } else {
                $filePath = self::downloadFromCloudflare($attachmentId, $variant, $cfId);
                $source   = 'downloaded';
            }

            self::restoreAsWordPressAttachment($attachmentId, $filePath);
            self::cleanupCFData($attachmentId, $cfId, $deleteFromCF);

            Logger::log(3, "[MIGRATION] #{$attachmentId}: success ({$source})");

            return ['status' => $source];

        } catch (Exception $e) {
            Logger::log(0, "[MIGRATION] #{$attachmentId}: " . $e->getMessage());
            return ['status' => 'error', 'reason' => $e->getMessage()];
        }
    }

    /**
     * Returns the original file path if it still exists on disk.
     * Derived from the stored thumbnail directory and the original filename.
     */
    public static function getLocalFile(int $attachmentId): string|false
    {
        $meta      = wp_get_attachment_metadata($attachmentId);
        $filename  = $meta[Constants::UPLOADED_IMAGE_CF_FILE_NAME] ?? '';
        $thumbPath = $meta[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME]['path'] ?? '';

        if (empty($filename) || empty($thumbPath)) {
            return false;
        }

        $fullPath = trailingslashit(dirname($thumbPath)) . $filename;

        return file_exists($fullPath) ? $fullPath : false;
    }

    private static function downloadFromCloudflare(int $attachmentId, string $variant, string $cfId): string
    {
        $variantUrl = AttachmentController::getVariantUrl($variant, $cfId);

        if (!$variantUrl) {
            throw new Exception('Could not build variant URL. Check Account Hash setting.');
        }

        $tempFile = download_url($variantUrl, 30);

        if (is_wp_error($tempFile)) {
            throw new Exception( esc_html( 'Download failed: ' . $tempFile->get_error_message() ) );
        }

        $uploadDir = wp_upload_dir();
        $filename  = wp_unique_filename($uploadDir['path'], self::getOriginalFilename($attachmentId));
        $destPath  = $uploadDir['path'] . '/' . $filename;

        if (!copy($tempFile, $destPath)) {
            wp_delete_file($tempFile);
            throw new Exception('Failed to move downloaded file to uploads directory.');
        }

        wp_delete_file($tempFile);

        return $destPath;
    }

    private static function restoreAsWordPressAttachment(int $attachmentId, string $filePath): void
    {
        $uploadDir    = wp_upload_dir();
        $relativePath = ltrim(str_replace($uploadDir['basedir'], '', $filePath), '/\\');

        update_attached_file($attachmentId, $relativePath);

        $properUrl = $uploadDir['baseurl'] . '/' . $relativePath;
        AttachmentController::updateAttachmentGuid($attachmentId, $properUrl);

        // Regenerate metadata and thumbnail sizes exactly as WordPress does on a fresh upload.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_raise_memory_limit('image');
        $metadata = wp_generate_attachment_metadata($attachmentId, $filePath);
        wp_update_attachment_metadata($attachmentId, $metadata);
    }

    /**
     * CF meta is deleted before updatePostContent() runs so wp_get_attachment_url()
     * already returns the new local URL by the time we rewrite post_content.
     */
    private static function cleanupCFData(int $attachmentId, string $cfId, bool $deleteFromCF): void
    {
        delete_post_meta($attachmentId, Constants::UPLOADED_IMAGE_CF_ID_NAME);
        AttachmentController::deleteCfThumbnail($attachmentId);

        self::updatePostContent($attachmentId, $cfId);

        if ($deleteFromCF) {
            try {
                CloudflareImagesApi::deleteImage($cfId);
            } catch (Exception $e) {
                Logger::log(1, "[MIGRATION] #{$attachmentId}: CF delete failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Replaces all CF delivery URLs for this image in post_content with the new local URL.
     * Matches any variant and signed URL query strings:
     *   https://imagedelivery.net/{hash}/{cfId}/{variant}(?token=...)
     */
    private static function updatePostContent(int $attachmentId, string $cfId): void
    {
        global $wpdb;

        $accountHash = get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME);
        if (!$accountHash) {
            return;
        }

        $newUrl = wp_get_attachment_url($attachmentId);
        if (!$newUrl) {
            return;
        }

        $cfUrlPrefix = 'imagedelivery.net/' . $accountHash . '/' . $cfId . '/';

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE post_content LIKE %s
                   AND post_status NOT IN ('auto-draft', 'trash')",
                '%' . $wpdb->esc_like($cfUrlPrefix) . '%'
            )
        );

        if (empty($posts)) {
            return;
        }

        $pattern = '#https?://imagedelivery\.net/'
            . preg_quote($accountHash, '#') . '/'
            . preg_quote($cfId, '#')
            . '/[^"\'\\s<>]+#';

        foreach ($posts as $post) {
            $newContent = preg_replace($pattern, $newUrl, $post->post_content);

            if ($newContent !== null && $newContent !== $post->post_content) {
                $wpdb->update(
                    $wpdb->posts,
                    ['post_content' => $newContent],
                    ['ID' => $post->ID],
                    ['%s'],
                    ['%d']
                );
                clean_post_cache((int) $post->ID);
                Logger::log(3, "[MIGRATION] #{$attachmentId}: updated post_content in post #{$post->ID}");
            }
        }
    }

    /**
     * Falls back to a post_content search when post_parent is 0.
     * Matches any CF delivery URL for this image ID, regardless of variant.
     */
    private static function findPostUsingCfImage(string $cfId): ?\WP_Post
    {
        global $wpdb;

        $accountHash = get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME);
        if (!$accountHash) {
            return null;
        }

        $prefix = 'imagedelivery.net/' . $accountHash . '/' . $cfId . '/';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_content LIKE %s
                   AND post_status NOT IN ('auto-draft', 'trash')
                 LIMIT 1",
                '%' . $wpdb->esc_like($prefix) . '%'
            )
        );

        return $row ? get_post((int) $row->ID) : null;
    }

    /**
     * Fallback: finds a post that references this attachment by its WordPress image class
     * (wp-image-{id}) in post_content, or has it set as a featured image (_thumbnail_id).
     * Catches cases where the image was inserted before the CF upload, so post_content
     * holds the local URL rather than the CF delivery URL.
     */
    private static function findPostUsingAttachmentId(int $attachmentId): ?\WP_Post
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 WHERE (
                     p.post_content LIKE %s
                     OR EXISTS (
                         SELECT 1 FROM {$wpdb->postmeta} pm
                         WHERE pm.post_id = p.ID
                           AND pm.meta_key = '_thumbnail_id'
                           AND pm.meta_value = %s
                     )
                 )
                 AND p.post_status NOT IN ('auto-draft', 'trash')
                 LIMIT 1",
                '%' . $wpdb->esc_like('wp-image-' . $attachmentId) . '%',
                (string) $attachmentId
            )
        );

        return $row ? get_post((int) $row->ID) : null;
    }

    private static function resolveAttachmentIds(string $scope, array $selectedIds): array
    {
        if ($scope === 'selected') {
            return array_values(array_filter(
                array_map('intval', $selectedIds),
                fn($id) => (bool) AttachmentController::getCloudflareIdOfAttachment($id)
            ));
        }

        $ids = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
        ]);

        if ($scope === 'posts') {
            $ids = array_values(array_filter(
                $ids,
                function ($id) {
                    if ((int) get_post($id)?->post_parent !== 0) {
                        return true;
                    }
                    $cfId = AttachmentController::getCloudflareIdOfAttachment($id);
                    if ($cfId && self::findPostUsingCfImage($cfId) !== null) {
                        return true;
                    }
                    return self::findPostUsingAttachmentId($id) !== null;
                }
            ));
        }

        return $ids;
    }

    /**
     * Falls back to mime-type + sanitized post title if the filename wasn't stored at upload time.
     */
    private static function getOriginalFilename(int $attachmentId): string
    {
        $meta     = wp_get_attachment_metadata($attachmentId);
        $filename = $meta[Constants::UPLOADED_IMAGE_CF_FILE_NAME] ?? '';

        if (!empty($filename)) {
            return $filename;
        }

        $ext   = self::mimeToExtension((string) get_post_mime_type($attachmentId));
        $title = sanitize_file_name(get_the_title($attachmentId) ?: 'image');

        return $title . '.' . $ext;
    }

    /**
     * Prefers the local CF thumbnail; falls back to WordPress's standard attachment thumbnail.
     */
    private static function getThumbnailUrl(int $attachmentId): string
    {
        $thumbnail = AttachmentController::getCfThumbnail($attachmentId);

        if (!empty($thumbnail['path'])) {
            $uploadDir = wp_upload_dir();
            $relative  = ltrim(str_replace(wp_normalize_path($uploadDir['basedir']), '', wp_normalize_path($thumbnail['path'])), '/\\');
            return $uploadDir['baseurl'] . '/' . $relative;
        }

        return (string) wp_get_attachment_image_url($attachmentId, 'thumbnail');
    }

    private static function mimeToExtension(string $mimeType): string
    {
        return [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/avif'    => 'avif',
            'image/svg+xml' => 'svg',
        ][$mimeType] ?? 'jpg';
    }
}
