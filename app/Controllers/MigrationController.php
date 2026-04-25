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
     * Returns analysis of attachments that would be affected by migration.
     * Used to show a pre-migration summary to the user.
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

            $result['images'][] = [
                'id'           => $id,
                'title'        => get_the_title($id) ?: self::getOriginalFilename($id),
                'thumbnail'    => self::getThumbnailUrl($id),
                'status'       => $status,
                'parent_title' => $parentId ? get_the_title($parentId) : '',
            ];
        }

        return $result;
    }

    /**
     * Migrates a single attachment to local storage.
     * Uses local copy if available, otherwise downloads the selected variant from Cloudflare.
     * Restores the attachment as a native WordPress attachment (thumbnails regenerated).
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

            Logger::log($attachmentId, "[MIGRATION] Success ({$source}): attachment #{$attachmentId}");

            return ['status' => $source];

        } catch (Exception $e) {
            Logger::log($attachmentId, '[MIGRATION] Error: ' . $e->getMessage());
            return ['status' => 'error', 'reason' => $e->getMessage()];
        }
    }

    /**
     * Checks whether a local copy of the original image exists on disk.
     * Derives the expected path from the stored thumbnail directory and original filename.
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

    /**
     * Downloads the chosen variant from Cloudflare to the current uploads directory.
     */
    private static function downloadFromCloudflare(int $attachmentId, string $variant, string $cfId): string
    {
        $variantUrl = AttachmentController::getVariantUrl($variant, $cfId);

        if (!$variantUrl) {
            throw new Exception('Could not build variant URL. Check Account Hash setting.');
        }

        $tempFile = download_url($variantUrl, 30);

        if (is_wp_error($tempFile)) {
            throw new Exception('Download failed: ' . $tempFile->get_error_message());
        }

        $uploadDir = wp_upload_dir();
        $filename  = wp_unique_filename($uploadDir['path'], self::getOriginalFilename($attachmentId));
        $destPath  = $uploadDir['path'] . '/' . $filename;

        if (!rename($tempFile, $destPath)) {
            @unlink($tempFile);
            throw new Exception('Failed to move downloaded file to uploads directory.');
        }

        return $destPath;
    }

    /**
     * Restores a file as a native WordPress attachment:
     * updates _wp_attached_file, guid, and regenerates all thumbnail sizes.
     */
    private static function restoreAsWordPressAttachment(int $attachmentId, string $filePath): void
    {
        $uploadDir    = wp_upload_dir();
        $relativePath = ltrim(str_replace($uploadDir['basedir'], '', $filePath), '/\\');

        update_attached_file($attachmentId, $relativePath);

        $properUrl = $uploadDir['baseurl'] . '/' . $relativePath;
        AttachmentController::updateAttachmentGuid($attachmentId, $properUrl);

        // Regenerate metadata and all thumbnail sizes exactly like a fresh WordPress upload
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachmentId, $filePath);
        wp_update_attachment_metadata($attachmentId, $metadata);
    }

    /**
     * Removes CF-specific post meta and thumbnail, optionally deletes from Cloudflare.
     */
    private static function cleanupCFData(int $attachmentId, string $cfId, bool $deleteFromCF): void
    {
        delete_post_meta($attachmentId, Constants::UPLOADED_IMAGE_CF_ID_NAME);
        AttachmentController::deleteCfThumbnail($attachmentId);

        if ($deleteFromCF) {
            try {
                CloudflareImagesApi::deleteImage($cfId);
            } catch (Exception $e) {
                Logger::log($attachmentId, '[MIGRATION] CF delete failed (image migrated locally): ' . $e->getMessage());
            }
        }
    }

    /**
     * Resolves which attachment IDs to include based on the chosen scope.
     */
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
                fn($id) => (int) get_post($id)?->post_parent !== 0
            ));
        }

        return $ids;
    }

    /**
     * Returns the original filename stored at upload time, with a sanitized fallback.
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
     * Returns a usable thumbnail URL for the migration UI.
     * Prefers the local CF thumbnail; falls back to standard WP thumbnail.
     */
    private static function getThumbnailUrl(int $attachmentId): string
    {
        $thumbnail = AttachmentController::getCfThumbnail($attachmentId);

        if (!empty($thumbnail['path'])) {
            $uploadDir = wp_upload_dir();
            $relative  = ltrim(str_replace($uploadDir['basedir'], '', $thumbnail['path']), '/\\');
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
