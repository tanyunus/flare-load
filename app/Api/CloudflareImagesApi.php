<?php

namespace FlarePress\Api;

use Exception;
use FlarePress\Data\Constants;

class CloudflareImagesApi
{
    /**
     * @throws Exception
     */
    public static function uploadSingleImage(string $imageFile, string $imageFileName): array {
        $payload = ['file' => new \CURLFile($imageFile, mime_content_type($imageFile), $imageFileName)];

        $response = self::sendData($payload);
        $response = json_decode($response, true);

        if(json_last_error() !== JSON_ERROR_NONE){
            throw new Exception('Single image upload error: Json error: ' . json_last_error_msg());
        }

        if(!$response['success']){
            throw new Exception('Single image upload error: ' . $response['errors'][0]['message']);
        }

        return $response;
    }

    public static function uploadMultipleImages(array $imageUrls): array|false {
        return [];
    }

    /**
     * Constructs variant url by given variant name in format:
     *   https://imagedelivery.net/<account-hash>/<image-id>/<variant>
     *
     * @param string $variant Variant slug/id as string
     * @param string $imageId Image id of the image uploaded to Cloudflare
     *
     * @return string|false URL constructed to serve desired variant or false.
     */
    public static function getVariantUrl(string $variant, string $imageId): string|false {
        $accountHash = self::getAccountHash();

        if(!$accountHash){
            return false;
        }

        return Constants::CF_CDN_URL . self::getAccountHash() . '/' . $imageId . '/' . $variant;
    }

    /**
     * @throws Exception
     */
    public static function getVariants(): array {
        $response = self::sendData([], Constants::CF_API_MODULE_VARIANTS);
        $response = json_decode($response, true);

        if(json_last_error() !== JSON_ERROR_NONE){
            throw new Exception(json_last_error_msg());
        }

        if(!$response['success']){
            throw new Exception($response['errors'][0]['message']);
        }

        return $response['result']['variants'];
    }

    /**
     * Makes a POST request to the given URL and returns the response.
     *
     * @param array $payload
     * @param string $module
     * @param array $headers
     * @return string
     * @throws Exception
     */
    private static function sendData(array $payload = [], string $module = '', array $headers = []): string
    {
        try {
            $ch = curl_init();
            $apiToken = self::getApiToken();
            $headers[] = "Authorization: Bearer {$apiToken}";

            curl_setopt($ch, CURLOPT_URL, self::buildRequestUrl($module));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if(!empty($payload)){
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
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

    private static function buildRequestUrl(string $module): string {
        $accountId = get_option(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);

        $url = Constants::CF_API_URL . $accountId . '/images/' . Constants::CF_API_VERSION;

        if(!empty($module)) {
            $url .= '/' . $module;
        }

        return $url;
    }

    private static function getApiToken(): string {
        return get_option(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME);
    }

    private static function getAccountHash(): string|false {
        return get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME);
    }
}