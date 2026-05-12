<?php

namespace FlarePress\Controllers;

defined('ABSPATH') || exit;

use DOMDocument;
use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Logger;
use FlarePress\Util\Utils;
use WP_Post;

class AttachmentController
{
    /**
     * Handles a CF upload: generates a local thumbnail, pushes the file to Cloudflare,
     * swaps guid and _wp_attached_file for the CF image ID, then optionally deletes the local copy.
     */
    public static function handleAddAttachment($attachmentId): void
    {
        if (!self::shouldUploadToCloudflare()) {
            return;
        }

        try {
            $imageFile = get_attached_file($attachmentId);
            $imageFileName = basename($imageFile);
            $imageFileSize = wp_filesize($imageFile);

            $thumbnail = self::createThumbnailSizeOfImage($imageFile);

            if (is_array($thumbnail) && isset($thumbnail['path'])) {
                $thumbnail['path'] = wp_normalize_path($thumbnail['path']);
            }

            $cloudFlareUploadResult = CloudflareImagesApi::uploadImage($imageFile, $imageFileName);

            self::updateAttachmentGuid($attachmentId, $cloudFlareUploadResult['result']['id']);
            self::updateAttachedFile($attachmentId, $cloudFlareUploadResult['result']['id']);

            $newMetaData = [
                'fileName' => $imageFileName,
                'fileSize' => $imageFileSize,
                'cloudFlareId' => $cloudFlareUploadResult['result']['id'],
                'thumbnail' => $thumbnail,
            ];

            add_filter('wp_generate_attachment_metadata', function ($metadata, $attachmentId, $context) use ($newMetaData) {
                return self::updateAttachmentMeta(
                    $attachmentId,
                    $metadata,
                    $newMetaData
                );
            }, 1, 3);

            if (self::shouldDeleteLocalFile()) {
                Utils::deleteFileFromDisk($imageFile);
            }
        } catch (Exception $e) {
            Logger::log(0, $e->getMessage());
            update_post_meta($attachmentId, '_flarep_upload_error', $e->getMessage());
            set_transient('flarep_upload_error_' . get_current_user_id(), true, 120);
        }
    }

    public static function handleDeleteAttachment(WP_Post|false|null $delete, WP_Post $post): WP_Post|false|null
    {
        $cfImageId = self::getCloudflareIdOfAttachment($post->ID);

        if ($cfImageId && self::shouldDeleteCloudflareFile()) {
            try {
                CloudflareImagesApi::deleteImage($cfImageId);
            } catch (Exception $e) {
                Logger::log(0, '[ATTACHMENT][DELETE_FROM_DISK] ' . $e->getMessage());
            }
        }

        self::deleteCfThumbnail($post->ID);

        return $delete;
    }


    /**
     * Returns true if the upload was flagged for Cloudflare by the frontend.
     * JS injects flarep_upload_to_cf into the multipart body before sending the media request.
     */
    private static function shouldUploadToCloudflare(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WordPress core media upload handler before this filter runs.
        return !empty(sanitize_key(wp_unslash($_POST[Constants::UPLOAD_TO_CF_INDICATOR] ?? '')));
    }

    private static function updateAttachmentMeta(int $attachmentId, array $metaData, array $newMetaData): array
    {
        $metaData['file'] = '';
        $metaData[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $newMetaData['cloudFlareId'];
        $metaData[Constants::UPLOADED_IMAGE_CF_FILE_NAME] = $newMetaData['fileName'];
        $metaData[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME] = $newMetaData['thumbnail'];
        $metaData['filesize'] = $newMetaData['fileSize'];
        $metaData['sizes'] = [];

        update_post_meta($attachmentId, Constants::UPLOADED_IMAGE_CF_ID_NAME, $newMetaData['cloudFlareId']);

        clean_attachment_cache($attachmentId);

        return $metaData;
    }


    /**
     * Rewrites the <img> HTML for a CF-hosted attachment.
     * On upload.php, swaps in the local thumbnail path instead of the CDN URL
     * to avoid burning CF delivery quota for media library previews.
     */
    public static function updateQueriedAttachmentHtml(int $attachmentId, string $cfId, string $html): string
    {
        $cfUrl = self::getDefaultVariantUrl($cfId);

        if (Utils::isAdminPage('upload.php')) {
            $path = self::getCfThumbnail($attachmentId)['path'] ?? '';
            if ($path) {
                $uploads = wp_get_upload_dir();
                $cfUrl   = str_replace(wp_normalize_path($uploads['basedir']), $uploads['baseurl'], wp_normalize_path($path));
            }
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $img = $dom->getElementsByTagName('img')->item(0);

        if(!$img->setAttribute('src', $cfUrl)) {
            Logger::log(0, '[ATTACHMENT] Cannot update -src- attribute of queried attachment html.');

            return $html;
        }

        if(!$img->setAttribute('srcset', '')) {
            Logger::log(0, '[ATTACHMENT] Cannot update -srcset- attribute of queried attachment html.');

            return $html;
        }

        if(!$img->setAttribute('width', '60')) {
            Logger::log(0, '[ATTACHMENT] Cannot update -width- attribute of queried attachment html.');

            return $html;
        }

        if(!$img->setAttribute('height', '60')) {
            Logger::log(0, '[ATTACHMENT] Cannot update -height- attribute of queried attachment html.');

            return $html;
        }

        $savedHTML = $dom->saveHTML($img);

        if(!$savedHTML) {
            Logger::log(0, '[ATTACHMENT] Cannot save updated queried attachment html.');

            return $html;
        }

        return $dom->saveHTML($img);
    }

    public static function updateAjaxQueryResponse(array $response, object $attachment): array
    {
        $cloudflareImageId = self::getCloudflareIdOfAttachment($attachment->ID);

        if ($cloudflareImageId) {
            $defaultVariantUrl = self::getDefaultVariantUrl($cloudflareImageId);

            $response['url'] = $defaultVariantUrl;
            $response['sizes'] = self::updateSizes($response['sizes'], $defaultVariantUrl, $attachment->ID);
            $response[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $cloudflareImageId;
            $response['filename'] = self::getAttachmentFileName($attachment->ID);
        }

        return $response;
    }


    /**
     * 'medium' points to the local disk thumbnail rather than CDN —
     * dashboard previews stay fast without consuming CF delivery quota.
     */
    private static function updateSizes(array $sizeArray, string $imgUrl, int $attachmentId): array
    {
        if (isset($sizeArray['full'])) {
            $sizeArray['full']['url'] = $imgUrl;
        }

        $path      = self::getCfThumbnail($attachmentId)['path'] ?? '';
        $uploads   = wp_get_upload_dir();
        $thumbnail = $path
            ? str_replace(wp_normalize_path($uploads['basedir']), $uploads['baseurl'], wp_normalize_path($path))
            : $imgUrl;
        $sizeArray['medium']['url'] = $thumbnail;

        return $sizeArray;
    }

    private static function shouldDeleteLocalFile(): bool
    {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);

        return empty($options[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME]);
    }

    private static function shouldDeleteCloudflareFile(): bool
    {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);

        return empty($options[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME]);
    }

    /**
     * Creates a 300×300 thumbnail before uploading to Cloudflare and saves it to disk.
     * Dashboard previews serve from this local copy instead of the CDN,
     * which avoids consuming CF delivery quota for every media library view.
     */
    private static function createThumbnailSizeOfImage(string $image): array|false
    {
        $editor = wp_get_image_editor($image);

        if (is_wp_error($editor)) {
            Logger::log(0, '[ATTACHMENT] Thumbnail creation error: ' . $editor->get_error_message());

            return false;
        }

        $editor->resize(300, 300, true);

        if (is_wp_error($editor)) {
            Logger::log(0, '[ATTACHMENT] Thumbnail resize error: ' . $editor->get_error_message());

            return false;
        }

        $saveResult = $editor->save($editor->generate_filename(Constants::UPLOADED_IMAGE_CF_THUMBNAIL_SUFFIX));

        if (is_wp_error($editor)) {
            Logger::log(0, '[ATTACHMENT] Thumbnail save error: ' . $editor->get_error_message());

            return false;
        }

        return $saveResult;
    }

    public static function deleteCfThumbnail(int $attachmentId): void
    {
        $thumbnail = self::getCfThumbnail($attachmentId)['path'] ?? '';

        if (empty($thumbnail)) {
            return;
        }

        Utils::deleteFileFromDisk($thumbnail);
    }

    public static function getCfThumbnail(int $attachmentId): array
    {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        if (!$attachmentMeta) {
            return [];
        }

        return $attachmentMeta[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME] ?? [];
    }

    /**
     * guid in wp_posts is where WordPress stores the file's canonical URL.
     * For CF images we store the CF image ID there instead.
     *
     * @throws Exception
     */
    public static function updateAttachmentGuid(int $attachmentId, string $newGuid): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- guid update; wp_update_post() fires revision/date hooks that must not run during attachment migration.
        if (!$wpdb->update($wpdb->posts, ['guid' => $newGuid], ['ID' => $attachmentId])) {
            throw new Exception("[ATTACHMENT] Unable to update attachment guid");
        }
    }

    public static function getAttachmentFileName(int $attachmentId): string|false
    {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return $attachmentMeta[Constants::UPLOADED_IMAGE_CF_FILE_NAME] ?? false;
    }

    public static function getCloudflareIdOfAttachment(int $attachmentId): string|false
    {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return $attachmentMeta[Constants::UPLOADED_IMAGE_CF_ID_NAME] ?? false;
    }

    /**
     * @throws Exception
     */
    public static function updateAttachedFile(int $attachmentId, string $newValue): void
    {
        if (!update_attached_file($attachmentId, $newValue)) {
            throw new Exception("[ATTACHMENT] Unable to update attachment file value");
        }
    }

    public static function getDefaultVariantUrl(string $cloudflareImageId): string {
        $defaultVariant = get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME);

        return self::getVariantUrl($defaultVariant, $cloudflareImageId);
    }

    /**
     * Builds the CDN URL for a variant. If a signing key is configured,
     * appends a time-limited HMAC-SHA256 token for CF's "Require Signed URLs" variants:
     *   ?token=<expiry>-<base64url_hmac>
     */
    public static function getVariantUrl(string $variant, string $imageId): string|false {
        $accountHash = get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME);

        if (!$accountHash) {
            return false;
        }

        $pathname = '/' . $accountHash . '/' . $imageId . '/' . $variant;
        $baseUrl  = Constants::CF_CDN_URL . ltrim($pathname, '/');

        $signingKeyHex = get_option(Constants::DASHBOARD_CF_SIGNING_KEY_FIELD_NAME, '');
        if (empty($signingKeyHex)) {
            return $baseUrl;
        }

        $keyBytes = @hex2bin($signingKeyHex);
        if ($keyBytes === false) {
            return $baseUrl;
        }

        $expiry = (string) (time() + (int) apply_filters('flarep_signed_url_expiry', 604800));
        $hmac   = hash_hmac('sha256', $expiry . $pathname, $keyBytes, true);
        $token  = rtrim(strtr(base64_encode($hmac), '+/', '-_'), '=');

        return $baseUrl . '?token=' . $expiry . '-' . $token;
    }
}
