<?php

namespace FlareLoad\Util;

defined('ABSPATH') || exit;

use FlareLoad\Data\Constants;

class Utils
{
    public static function isAdminPage(string $pageSlug): bool
    {
        global $pagenow;

        $currentAdminPage = basename(admin_url($pagenow));

        if (empty($currentAdminPage)) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- REQUEST_URI is sanitized immediately via sanitize_text_field; used only for page routing.
            $currentAdminPage = basename(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])));
        }

        return $currentAdminPage === $pageSlug;
    }

    public static function getCloudflareImages(): array
    {
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_status' => 'inherit',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter CF attachments by metadata key; no alternative.
            'meta_query' => array(
                array(
                    'key' => '_wp_attachment_metadata',
                    'value' => Constants::UPLOADED_IMAGE_CF_ID_NAME,
                    'compare' => 'LIKE'
                )
            )
        ));

        $filteredAttachments = [];

        foreach ($attachments as $attachment) {
            $metaData = wp_get_attachment_metadata($attachment->ID);

            if (isset($metaData[Constants::UPLOADED_IMAGE_CF_ID_NAME])) {
                $filteredAttachments[] = $attachment;
            }
        }

        return $filteredAttachments;
    }

    public static function deleteFileFromDisk(string $filePath): bool
    {
        $fileRealPath = realpath($filePath);

        if (!$fileRealPath) {
            return false;
        }

        wp_delete_file($fileRealPath);

        return !file_exists($fileRealPath);
    }

    public static function renderTemplate(string $template, array $data = []): void
    {
        extract($data);
        $file = FLARELOAD_PATH . 'app/Views/' . $template . '.php';

        if (file_exists($file)) {
            include $file;
        }
    }

    public static function isFpOptionsPage(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading $_GET['page'] for admin page routing only; no data is modified.
        if(!isset($_GET['page'])) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return is_admin() && sanitize_key(wp_unslash($_GET['page'])) === Constants::DASHBOARD_MENU_SLUG;
    }

    public static function isFpMigratePage(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading $_GET['page'] for admin page routing only; no data is modified.
        if (!isset($_GET['page'])) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return is_admin() && sanitize_key(wp_unslash($_GET['page'])) === Constants::DASHBOARD_MIGRATE_PAGE_SLUG;
    }

    public static function isPostEditPage(): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading $_GET params for admin page routing only; no data is modified.
        return self::isAdminPage('post.php')
            && !empty($_GET['post'])
            && absint($_GET['post']) > 0
            && sanitize_key(wp_unslash($_GET['action'] ?? '')) === 'edit';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    public static function isMediaEditPage(): bool {
        global $post;

        return self::isPostEditPage() && $post->post_type === 'attachment';
    }
}
