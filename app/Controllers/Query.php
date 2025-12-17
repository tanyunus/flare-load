<?php

namespace FlarePress\Controllers;

use DOMDocument;
use FlarePress\Data\Constants;
use FlarePress\Util\Utils;

class Query
{
    public static function updateQueriedAttachmentUrl(int $attachmentId, string $html): string
    {
        $cfUrl = get_the_guid($attachmentId);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $img = $dom->getElementsByTagName('img')->item(0);
        $img->setAttribute('src', $cfUrl);
        $img->setAttribute('srcset', '');

        return $dom->saveHTML($img);
    }

    public static function updateAjaxQueryResponse(array $response, object $attachment): array
    {
        $cfImageId = Utils::getCloudflareIdOfAttachment($attachment->ID);

        if ($cfImageId) {
            $imgUrl = $attachment->guid;

            error_log($imgUrl);

            $response['url'] = $imgUrl;

            if (isset($response['sizes']['full'])) {
                $response['sizes']['full']['url'] = $imgUrl;
            }

            if (isset($response['sizes']['medium'])) {
                $response['sizes']['medium']['url'] = $imgUrl;
            }

            if (isset($response['sizes']['thumbnail'])) {
                $response['sizes']['thumbnail']['url'] = $imgUrl;
            }

            $response[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $cfImageId;
            $response['filename'] = Utils::getAttachmentFileName($attachment->ID);
        }

        return $response;
    }
}