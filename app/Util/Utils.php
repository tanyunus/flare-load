<?php

namespace FlarePress\Util;

defined('ABSPATH') || exit;

use FlarePress\Data\Constants;

class Utils
{
    public static function isAdminPage(string $pageSlug): bool
    {
        global $pagenow;

        $currentAdminPage = basename(admin_url($pagenow));

        if (empty($currentAdminPage)) {
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
        $file = FLAREP_PATH . 'app/Views/' . $template . '.php';

        if (file_exists($file)) {
            include $file;
        }
    }

    public static function isFpOptionsPage(): bool {
        if(!isset($_GET['page'])) {
            return false;
        }

        return is_admin() && sanitize_key(wp_unslash($_GET['page'])) === Constants::DASHBOARD_MENU_SLUG;
    }

    public static function isFpMigratePage(): bool {
        if (!isset($_GET['page'])) {
            return false;
        }

        return is_admin() && sanitize_key(wp_unslash($_GET['page'])) === Constants::DASHBOARD_MIGRATE_PAGE_SLUG;
    }

    public static function isPostEditPage(): bool {
        return self::isAdminPage('post.php')
            && !empty($_GET['post'])
            && absint($_GET['post']) > 0
            && sanitize_key(wp_unslash($_GET['action'] ?? '')) === 'edit';
    }

    public static function isMediaEditPage(): bool {
        global $post;

        return self::isPostEditPage() && $post->post_type === 'attachment';
    }
}
