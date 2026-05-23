<?php

namespace FlareLoad\Data;

defined('ABSPATH') || exit;

class Constants
{
    //UPLOAD
    public const UPLOAD_TO_CF_INDICATOR = 'FLARELOAD_upload_to_cf';
    public const UPLOADED_IMAGE_CF_ID_NAME = 'FLARELOAD_cf_image_id';
    public const UPLOADED_IMAGE_CF_FILE_NAME = 'FLARELOAD_cf_file_name';
    public const UPLOADED_IMAGE_CF_THUMBNAIL_SUFFIX = '_FLARELOAD_cf_thumbnail';
    public const UPLOADED_IMAGE_CF_THUMBNAIL_NAME = 'FLARELOAD_cf_thumbnail';

    // CF API
    public const CF_API_URL = 'https://api.cloudflare.com/client/v4/accounts/';
    public const CF_CDN_URL = 'https://imagedelivery.net/';
    public const CF_API_VERSION = 'v1';
    public const CF_API_MODULE_VARIANTS = '/variants';

    // Views
    public const DASHBOARD_VIEW = 'dashboard';
    public const LOG_VIEW = 'logs';
    public const MIGRATE_VIEW = 'migrate';
    public const DASHBOARD_MENU_SLUG = 'flare-load-settings';
    public const DASHBOARD_LOG_PAGE_SLUG = 'flare-load-logs';
    public const DASHBOARD_MIGRATE_PAGE_SLUG = 'flare-load-migrate';
    public const DASHBOARD_MENU_TITLE = 'FlareLoad';
    public const LOG_MENU_TITLE = 'Logs';
    public const MIGRATE_MENU_TITLE = 'Migrate to Local';
    public const LOG_VIEWER_FIELD_NAME = 'FLARELOAD_log_viewer';
    public const DASHBOARD_SETTINGS_GROUP_NAME = 'FLARELOAD_settings_group';
    public const DASHBOARD_VARIANT_SETTINGS_GROUP_NAME = 'FLARELOAD_variant_settings_group';
    public const DASHBOARD_UPLOAD_SETTINGS_NAME = 'FLARELOAD_upload_settings';
    public const DASHBOARD_VARIANT_SETTINGS_NAME = 'FLARELOAD_variant_settings';
    public const DASHBOARD_UPLOAD_SETTINGS_SECTION_ID = 'FLARELOAD_upload_settings_section';
    public const DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME = 'FLARELOAD_keep_files_on_disk_after_upload';
    public const DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME = 'FLARELOAD_keep_files_on_cf_after_delete';
    public const DASHBOARD_FILE_MANAGEMENT_FIELD_NAME = 'FLARELOAD_keep_files_on_disk_after_delete';
    public const DASHBOARD_API_SETTINGS_SECTION_ID = 'FLARELOAD_api_settings_section';
    public const DASHBOARD_VARIANT_SETTINGS_SECTION_ID = 'FLARELOAD_variant_settings_section';
    public const DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME = 'FLARELOAD_cf_account_id';
    public const DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME = 'FLARELOAD_cf_account_hash';
    public const DASHBOARD_CF_API_TOKEN_FIELD_NAME = 'FLARELOAD_cf_api_token';
    public const DASHBOARD_CF_SIGNING_KEY_FIELD_NAME = 'FLARELOAD_cf_signing_key';
    public const DASHBOARD_VARIANT_LIST_FIELD_NAME = 'FLARELOAD_cf_variant_list';
    public const DASHBOARD_DEFAULT_VARIANT_FIELD_NAME = 'FLARELOAD_cf_default_variant';
    public const DASHBOARD_CF_LIST_VIEW_COLUMN_ID = 'FLARELOAD_cf_badge_column';

    // UI strings — page & section titles
    public const UI_PAGE_TITLE = 'FlareLoad Settings';
    public const UI_LOG_PAGE_TITLE = 'FlareLoad Logs';
    public const UI_API_SETTINGS_SECTION_TITLE = 'API Settings';
    public const UI_VARIANT_SETTINGS_SECTION_TITLE = 'Variant Settings';
    public const UI_UPLOAD_SETTINGS_SECTION_TITLE = 'Upload Settings';
    public const UI_LOG_VIEWER_SECTION_TITLE = 'Log Viewer';

    // UI strings — field labels
    public const UI_CF_BADGE_TITLE = 'Uploaded to Cloudflare';
    public const UI_LOCATION_FILTER_ALL = 'All locations';
    public const UI_CF_LOCATION_THIS_SERVER = 'This server';
    public const UI_CF_LOCATION_COLUMN_NAME = 'Location';
    public const UI_FILE_MANAGEMENT_FIELD_TITLE = 'File management';
    public const UI_KEEP_FILES_AFTER_UPLOAD_FIELD_LABEL = 'Keep a local copy of the uploaded media';
    public const UI_KEEP_FILES_ON_CF_AFTER_DELETE_FIELD_LABEL = 'Keep files on Cloudflare after delete';
    public const UI_LOGS_FIELD_LABEL = 'Logs';
    public const UI_CF_ACCOUNT_ID_FIELD_LABEL = 'Cloudflare Account ID';
    public const UI_CF_ACCOUNT_HASH_FIELD_LABEL = 'Cloudflare Account Hash';
    public const UI_CF_API_TOKEN_FIELD_LABEL = 'Cloudflare Account API Token';
    public const UI_CF_SIGNING_KEY_FIELD_LABEL = 'URL Signing Key';
    public const UI_CF_SIGNING_KEY_DESCRIPTION = 'Optional. Hex-encoded key for Cloudflare Images signed URLs. Generate one at <em>Cloudflare Dashboard → Images → Keys</em>. When set, all image delivery URLs include a time-limited signature. Leave empty to disable.';
    public const UI_VARIANTS_FIELD_LABEL = 'Variants';
    public const UI_SYNC_VARIANTS_BUTTON = 'Sync Variants';
    public const UI_TEST_CONNECTION_BUTTON = 'Test Connection';
    public const UI_TEST_CONNECTION_SUCCESS = 'Connection successful.';
    public const UI_TEST_CONNECTION_FAIL = 'Connection failed. Please check your API token.';
    public const UI_NO_VARIANTS_SYNCED = 'No variants synced yet.';
    public const UI_DEFAULT_VARIANT_FIELD_LABEL = 'Default Variant';

    // UI strings — field descriptions
    public const UI_CF_ACCOUNT_HASH_DESCRIPTION = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/images/hosted</i>';
    public const UI_CF_API_TOKEN_DESCRIPTION = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/api-tokens</i>';
    public const UI_DEFAULT_VARIANT_DESCRIPTION = 'Choose the largest variant without cropping as the default. <br/>This ensures the full image is clearly visible and recognizable.';
    public const UI_KEEP_FILES_AFTER_UPLOAD_DESCRIPTION = 'Enable this setting if you prefer to keep a local copy of the uploaded media. The copy of the file will be kept on default upload folder';
    public const UI_KEEP_ON_CF_AFTER_DELETE_DESCRIPTION = 'FlareLoad deletes the copy of the attachment from Cloudflare during the deletion process.<br/>Enable this setting if you prefer to keep the file on Cloudflare.';

    // UI strings — header
    public const UI_HEADER_TAGLINE = 'DIRECT<br>CLOUDFLARE IMAGES<br>INTEGRATION';
    public const UI_HEADER_WEBSITE_LINK_TITLE = 'FlareLoad plugin website';
    public const UI_HEADER_GITHUB_LINK_TITLE = 'FlareLoad developer Github profile';

    // Internal IDs
    public const LOG_VIEWER_SECTION_ID = 'FLARELOAD_log_viewer_section';
    public const LOG_SETTINGS_GROUP_NAME = 'FLARELOAD_log_field_group';

    // Other
    public const FLARELOAD_TRANSLATION_DOMAIN = 'flare-load';
}