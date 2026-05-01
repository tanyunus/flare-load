<?php

namespace FlarePress\Api;

defined('ABSPATH') || exit;

use Exception;
use FlarePress\Data\Constants;

class CloudflareImagesApi
{
    public static function uploadImage(string $imageFile, string $imageFileName): array
    {
        $mimeType = mime_content_type($imageFile);

        if (!$mimeType) {
            throw new Exception('[IMAGES_API][UPLOAD] Cannot get mime type from given image file.');
        }

        $requestUrl = self::buildRequestUrl();

        if (empty($requestUrl)) {
            throw new Exception('[IMAGES_API][UPLOAD] Cannot construct api request url.');
        }

        $fileContent = file_get_contents($imageFile);

        if ($fileContent === false) {
            throw new Exception('[IMAGES_API][UPLOAD] Cannot read image file.');
        }

        $boundary = wp_generate_password(24, false);
        $body     = "--{$boundary}\r\n"
                  . "Content-Disposition: form-data; name=\"file\"; filename=\"{$imageFileName}\"\r\n"
                  . "Content-Type: {$mimeType}\r\n\r\n"
                  . $fileContent . "\r\n"
                  . "--{$boundary}--\r\n";

        $response = wp_remote_post($requestUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . self::getApiToken(),
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body'    => $body,
            'timeout' => 60,
        ]);

        return self::parseResponse($response, '[IMAGES_API][UPLOAD]');
    }

    public static function deleteImage(string $cloudFlareImageId): array
    {
        $requestUrl = self::buildRequestUrl($cloudFlareImageId);

        if (empty($requestUrl)) {
            throw new Exception('[IMAGES_API][DELETE] Cannot construct api request url.');
        }

        $response = wp_remote_request($requestUrl, [
            'method'  => 'DELETE',
            'headers' => ['Authorization' => 'Bearer ' . self::getApiToken()],
            'timeout' => 30,
        ]);

        return self::parseResponse($response, '[IMAGES_API][DELETE]');
    }

    public static function getVariants(?string $token = null): array
    {
        $requestUrl = self::buildRequestUrl(Constants::CF_API_MODULE_VARIANTS);

        if (empty($requestUrl)) {
            throw new Exception('[IMAGES_API][VARIANT_RETRIEVAL] Cannot construct api request url.');
        }

        $response = wp_remote_get($requestUrl, [
            'headers' => ['Authorization' => 'Bearer ' . ($token ?? self::getApiToken())],
            'timeout' => 30,
        ]);

        $data = self::parseResponse($response, '[IMAGES_API][VARIANT_RETRIEVAL]');

        return $data['result']['variants'];
    }

    private static function parseResponse(mixed $response, string $context): array
    {
        if (is_wp_error($response)) {
            throw new Exception("{$context} HTTP error: " . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("{$context} JSON error: " . json_last_error_msg());
        }

        if (empty($data['success'])) {
            $message = $data['errors'][0]['message'] ?? 'Unknown error';
            throw new Exception("{$context} {$message}");
        }

        return $data;
    }

    private static function buildRequestUrl(string $additional = ''): string
    {
        $accountId = get_option(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);

        if (empty($accountId)) {
            return '';
        }

        $url = Constants::CF_API_URL . $accountId . '/images/' . Constants::CF_API_VERSION;

        if (!empty($additional)) {
            $url .= '/' . $additional;
        }

        return $url;
    }

    private static function getApiToken(): string
    {
        return get_option(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME) ?? '';
    }
}
