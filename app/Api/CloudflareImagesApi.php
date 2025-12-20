<?php

namespace FlarePress\Api;

use CURLFile;
use Exception;
use FlarePress\Data\Constants;

class CloudflareImagesApi
{
    /**
     * @throws Exception
     */
    public static function uploadImage(string $imageFile, string $imageFileName): array {
        $payload = ['file' => new CURLFile($imageFile, mime_content_type($imageFile), $imageFileName)];

        $response = self::sendData($payload, self::buildRequestUrl(), 'POST');
        $response = json_decode($response, true);

        if(json_last_error() !== JSON_ERROR_NONE){
            throw new Exception('[FlarePress] Cloudflare image upload error: Json error: ' . json_last_error_msg());
        }

        if(!$response['success']){
            throw new Exception('[FlarePress] Cloudflare image upload error: ' . $response['errors'][0]['message']);
        }

        return $response;
    }

    /**
     * @throws Exception
     */
    public static function deleteImage(string $cloudFlareImageId): array {
        $response = self::sendData([], self::buildRequestUrl($cloudFlareImageId), 'DELETE');

        $response = json_decode($response, true);

        if(json_last_error() !== JSON_ERROR_NONE){
            throw new Exception('[FlarePress] Cloudflare image delete error: Json error: ' . json_last_error_msg());
        }

        if(!$response['success']){
            throw new Exception('[FlarePress] Cloudflare image delete error: ' . $response['errors'][0]['message']);
        }

        return $response;
    }

    public static function uploadMultipleImages(array $imageUrls): array|false {
        return [];
    }

    /**
     * @throws Exception
     */
    public static function getVariants(): array {
        $response = self::sendData([], self::buildRequestUrl(Constants::CF_API_MODULE_VARIANTS), 'GET');
        $response = json_decode($response, true);

        if(json_last_error() !== JSON_ERROR_NONE){
            throw new Exception('[FlarePress] Variant retrieval error: Json error: ' . json_last_error_msg());
        }

        if(!$response['success']){
            throw new Exception('[FlarePress] Variant retrieval error: ' . $response['errors'][0]['message']);
        }

        return $response['result']['variants'];
    }

    /**
     * Makes a request to the given URL and returns the response.
     *
     * @param array $payload
     * @param string $url
     * @param string $method
     * @param array $headers
     * @return string
     * @throws Exception
     */
    private static function sendData(array $payload, string $url, string $method = '', array $headers = []): string
    {
        try {
            $ch = curl_init();
            $apiToken = self::getApiToken();
            $headers[] = "Authorization: Bearer {$apiToken}";

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            switch ($method) {
                case 'POST':
                    curl_setopt($ch, CURLOPT_POST, 1);

                    if(!empty($payload)){
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    }
                    break;
                case 'DELETE':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                default;
            }

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                curl_close($ch);

                throw new Exception("cURL Error: " . curl_error($ch));
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

        $url = Constants::CF_API_URL . $accountId . '/images/' . Constants::CF_API_VERSION;

        if(!empty($additional)) {
            $url .= '/' . $additional;
        }

        return $url;
    }

    private static function getApiToken(): string {
        return get_option(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME);
    }
}