<?php

namespace FlarePress\Api;

defined('ABSPATH') || exit;

use CURLFile;
use Exception;
use FlarePress\Data\Constants;

class CloudflareImagesApi
{
    public static function uploadImage(string $imageFile, string $imageFileName): array {
        $mimeType = mime_content_type($imageFile);

        if(!$mimeType) {
            throw new Exception('[IMAGES_API][UPLOAD] Cannot get mime type from given image file.');
        }

        $payload = ['file' => new CURLFile($imageFile, $mimeType, $imageFileName)];

        $requestUrl = self::buildRequestUrl();

        if(empty($requestUrl)) {
            throw new Exception('[IMAGES_API][UPLOAD] Cannot construct api request url.');
        }

        $response = self::sendData($payload, $requestUrl, 'POST');

        if(empty($response)) {
            throw new Exception('[IMAGES_API][UPLOAD] Empty response body from request.');
        }

        $response = json_decode($response, true);

        if(json_last_error() !== JSON_ERROR_NONE){
            throw new Exception('[IMAGES_API][UPLOAD] Json error: ' . json_last_error_msg());
        }

        if(!$response['success']){
            throw new Exception('[IMAGES_API][UPLOAD] ' . $response['errors'][0]['message']);
        }

        return $response;
    }

    public static function deleteImage(string $cloudFlareImageId): array {
        $requestUrl = self::buildRequestUrl($cloudFlareImageId);

        if(empty($requestUrl)) {
            throw new Exception('[IMAGES_API][DELETE] Cannot construct api request url.');
        }

        $response = self::sendData([], $requestUrl, 'DELETE');

        $response = json_decode($response, true);

        if(json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('[IMAGES_API][DELETE] Json error: ' . json_last_error_msg());
        }

        if(!$response['success']){
            throw new Exception('[IMAGES_API][DELETE] ' . $response['errors'][0]['message']);
        }

        return $response;
    }

    public static function getVariants(?string $token = null): array {
        $requestUrl = self::buildRequestUrl(Constants::CF_API_MODULE_VARIANTS);

        if(empty($requestUrl)) {
            throw new Exception('[IMAGES_API][VARIANT_RETRIEVAL] Cannot construct api request url.');
        }

        $response = self::sendData([], self::buildRequestUrl(Constants::CF_API_MODULE_VARIANTS), 'GET', [], $token);
        $response = json_decode($response, true);

        if(json_last_error() !== JSON_ERROR_NONE){
            throw new Exception('[IMAGES_API][VARIANT_RETRIEVAL] Json error: ' . json_last_error_msg());
        }

        if(!$response['success']){
            throw new Exception('[IMAGES_API][VARIANT_RETRIEVAL] ' . $response['errors'][0]['message']);
        }

        return $response['result']['variants'];
    }

    /** Supports GET, POST, DELETE. */
    private static function sendData(array $payload, string $url, string $method = '', array $headers = [], ?string $tokenOverride = null): string
    {
        try {
            $ch = curl_init();
            $apiToken = $tokenOverride ?? self::getApiToken();
            $headers[] = "Authorization: Bearer {$apiToken}";

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            switch ($method) {
                case 'POST':
                    curl_setopt($ch, CURLOPT_POST, 1);

                    if(!empty($payload)){
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    }
                    break;
                case 'DELETE':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                    break;
                default:
            }

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                curl_close($ch);

                throw new Exception("[IMAGES_API][cURL]: " . curl_error($ch));
            } else {
                curl_close($ch);

                return $response;
            }
        } catch (Exception $e) {
            curl_close($ch);

            throw new Exception($e->getMessage());
        }
    }

    private static function buildRequestUrl(string $additional = ''): string {
        $accountId = get_option(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);

        if(empty($accountId)) {
            return '';
        }

        $url = Constants::CF_API_URL . $accountId . '/images/' . Constants::CF_API_VERSION;

        if(!empty($additional)) {
            $url .= '/' . $additional;
        }

        return $url;
    }

    private static function getApiToken(): string {
        return get_option(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME) ?? '';
    }
}
