<?php

namespace FP\Controllers;

use DOMDocument;
use FP\Utils\AdminPage;

class FPQueryController
{
    public function __construct() {
        // Modifying ajax response of attachment query
        add_action('wp_ajax_query-attachments', function() {
            add_action('wp_prepare_attachment_for_js', function($response, $attachment, $meta) {
                if($this->getCfImageCfId($attachment->ID)) {
                    $response = $this->updateAjaxQueryResponse($response, $attachment);
                }

                return $response;
            }, 10, 3);
        }, 1);

        // Modifying ajax response right after upload
        add_filter('wp_prepare_attachment_for_js', function ($response, $attachment, $meta) {
            if($this->getCfImageCfId($attachment->ID)) {
                $response = $this->updateAjaxQueryResponse($response, $attachment);
            }

            return $response;
        }, 10, 3);

        // Modifying attachment query
        add_action('wp_get_attachment_image', function ($html, $attachment_id, $size, $icon, $attr) {
            if($this->getCfImageCfId($attachment_id)) {
                $html = $this->updateQueriedAttachmentUrl($attachment_id, $html);
            }

            return $html;
        }, 10, 5);
    }

    private function getCfImageCfId(int $attachmentId): string|false {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return $attachmentMeta[FPConstants::UPLOADED_IMAGE_CF_ID_NAME] ?? false;
    }

    private function getCfImageFileName(int $attachmentId): string|false {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return $attachmentMeta[FPConstants::UPLOADED_IMAGE_CF_FILE_NAME] ?? false;
    }

    private function getCfImages(): array {
        $attachments = get_posts(array(
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
            'meta_query'     => array(
                array(
                    'key'     => '_wp_attachment_metadata',
                    'value'   => FPConstants::UPLOADED_IMAGE_CF_ID_NAME,
                    'compare' => 'LIKE'
                )
            )
        ));

        $filteredAttachments = [];

        foreach($attachments as $attachment) {
            $metaData = wp_get_attachment_metadata($attachment->ID);

            if(isset($metaData[FPConstants::UPLOADED_IMAGE_CF_ID_NAME])) {
                $filteredAttachments[] = $attachment;
            }
        }

        return $filteredAttachments;
    }

    private function getCfImageIds(): array {
        $imageIds = [];

        foreach ($this->getCfImages() as $image) {
            $imageIds[] = $image->ID;
        }

        return $imageIds;
    }

    private function updateQueriedAttachmentUrl(int $attachmentId, string $html): string {
        $cfUrl = get_the_guid($attachmentId);

        $dom  = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $img = $dom->getElementsByTagName('img')->item(0);
        $img->setAttribute('src', $cfUrl);

        return $dom->saveHTML($img);
    }

    private function updateAjaxQueryResponse(array $response, object $attachment): array {
        $cfImageId = $this->getCfImageCfId($attachment->ID);

        if($cfImageId) {
            $imgUrl = $attachment->guid;

            $response['url'] = $imgUrl;

            if(isset($response['sizes']['full'])) {
                $response['sizes']['full']['url'] = $imgUrl;
            }

            if(isset($response['sizes']['medium'])) {
                $response['sizes']['medium']['url'] = $imgUrl;
            }

            if(isset($response['sizes']['thumbnail'])) {
                $response['sizes']['thumbnail']['url'] = $imgUrl;
            }

            $response[FPConstants::UPLOADED_IMAGE_CF_ID_NAME] = $cfImageId;
            $response['filename'] = $this->getCfImageFileName($attachment->ID);
        }

        return $response;
    }
}