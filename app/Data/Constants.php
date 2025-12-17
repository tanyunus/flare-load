<?php

namespace FlarePress\Data;

class Constants
{
    //UPLOAD
    public const UPLOAD_TO_CF_INDICATOR = 'fp_upload_to_cf';
    public const UPLOADED_IMAGE_CF_ID_NAME = 'fp_cf_image_id';
    public const UPLOADED_IMAGE_CF_FILE_NAME = 'fp_cf_file_name';

    // CF API
    public const CF_API_URL = 'https://api.cloudflare.com/client/v4/accounts/';
    public const CF_CDN_URL = 'https://imagedelivery.net/';
    public const CF_API_VERSION = 'v1';
    public const CF_API_MODULE_VARIANTS = '/variants';

    // Views
    public const DASHBOARD_VIEW = 'admin/dashboard';
    public const DASHBOARD_MENU_SLUG = 'flare-press-settings';
    public const DASHBOARD_MENU_TITLE = 'FlarePress';
    public const DASHBOARD_SETTINGS_GROUP_NAME = 'fp_settings_group';
    public const DASHBOARD_SECTION_ID = 'fp_settings_section_id';
    public const DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME = 'fp_cf_account_id';
    public const DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME = 'fp_cf_account_hash';
    public const DASHBOARD_CF_API_TOKEN_FIELD_NAME = 'fp_cf_api_token';
    public const DASHBOARD_CF_LIST_VIEW_COLUMN_ID = 'fp_cf_badge_column';

    // Translatable strings
    public const DASHBOARD_PAGE_TITLE = 'FlarePress Settings';
    public const DASHBOARD_CF_BADGE_TITLE = 'Uploaded to Cloudflare';

    // Other
    public const FP_TRANSLATION_DOMAIN = 'fp_translations';
}