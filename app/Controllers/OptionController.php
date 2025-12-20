<?php

namespace FlarePress\Controllers;

use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Utils;

class OptionController
{
    public function __construct()
    {
        // Add page
        $this->addSettingsPage();

        $this->addUploadSettingsSection();
        $this->addApiSettingsSection();
        $this->addVariantSettingsSection();
        $this->registerAccountIDField();
        $this->registerAccountHashField();
        $this->registerAPITokenField();
        $this->registerFileManagementField();
        $this->registerVariantListField();
    }

    private function addSettingsPage(): void
    {
        add_options_page(
                Constants::UI_PAGE_TITLE,
                Constants::DASHBOARD_MENU_TITLE,
                'manage_options',
                Constants::DASHBOARD_MENU_SLUG,
                [$this, 'fpAdminDashboardView'],
                5
        );
    }

    public function fpAdminDashboardView(): void
    {
        Utils::renderTemplate(Constants::DASHBOARD_VIEW);
    }

    private function addApiSettingsSection(): void
    {
        add_settings_section(
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
                'API Settings',
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function addVariantSettingsSection(): void
    {
        add_settings_section(
                Constants::DASHBOARD_VARIANT_SETTINGS_SECTION_ID,
                'Variant Settings',
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function addUploadSettingsSection(): void
    {
        add_settings_section(
                'fp_upload_settings_section',
                'Upload Settings',
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function registerAccountIDField(): void
    {
        register_setting(Constants::DASHBOARD_SETTINGS_GROUP_NAME, Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
                Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME,
                'Cloudflare Account ID',
                function () {
                    $this->renderTextField(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerAccountHashField(): void
    {
        register_setting(Constants::DASHBOARD_SETTINGS_GROUP_NAME, Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
                Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
                'Cloudflare Account Hash',
                function () {
                    $description = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/images/hosted</i>';
                    $this->renderTextField(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME, $description);
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerApiTokenField(): void
    {
        register_setting(Constants::DASHBOARD_SETTINGS_GROUP_NAME, Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
                Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME,
                'Cloudflare Account API Token',
                function () {
                    $description = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/api-tokens</i>';
                    $this->renderTextField(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, $description);
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerVariantListField(): void
    {
        register_setting(
                Constants::DASHBOARD_VARIANT_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME
        );

        add_settings_field(
                Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME,
                'Variants',
                function () {
                    $this->renderVariantListField();
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_VARIANT_SETTINGS_SECTION_ID,
        );
    }

    private function renderVariantListField(): void
    {
        $variantsArray = self::getVariantNamesAsArray();

        if(empty($variantsArray)) {
            $variantsArray = array_keys(self::syncVariants());
        }

        ?>
        <div id="fp_variant_list_field" class="fp-variant-list-field">
            <?php
            if (!empty($variantsArray)) {
                foreach ($variantsArray as $variant) {
                    ?>
                    <code><?php echo $variant ?></code>
                    <?php
                }
            } else {
                ?> <p>'No variants synced yet.'</p><?php
            }
            ?>

        </div>
        <div class="fp-sync-button-and-spinner">
            <button id="fp_variant_sync_button" type="button" role="button" class="fp-variant-sync-button button button-secondary">
                <span class="dashicons dashicons-update-alt"></span>
                Sync Variants
            </button>
            <span id="fp_sync_variant_spinner" class="spinner"></span>
        </div>
        <?php
    }

    private function registerFileManagementField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_UPLOAD_SETTINGS_NAME,
                [
                        'type' => 'array',
                        'sanitize_callback' => function ($input) {
                            $sanitized = [];
                            $sanitized[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME] = isset($input[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME]) ? 1 : 0;
                            $sanitized[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME] = isset($input[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME]) ? 1 : 0;
                            return $sanitized;
                        }
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_FILE_MANAGEMENT_FIELD_NAME,
                Utils::localize(Constants::UI_FILE_MANAGEMENT_FIELD_TITLE),
                [$this, 'renderFileManagementCheckboxFields'],
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_UPLOAD_SETTINGS_SECTION_ID,
        );
    }

    private function renderTextField(string $optionName, string $description = '', bool $hideValue = false): void
    {
        ?>
        <label>
            <input
                    value="<?php echo !$hideValue ? esc_attr(get_option($optionName)) : ''; ?>"
                    name="<?php echo $optionName ?>"
                    id="<?php echo $optionName ?>"
                    type="text"
                    class="regular-text"/>
        </label>
        <?php
        if (!empty($description)) {
            ?>
            <p class="description"><?php echo $description ?></p>
            <?php
        }
    }

    public function renderFileManagementCheckboxFields(): void
    {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);
        ?>
        <fieldset>
            <label>
                <input type="checkbox"
                       name="<?php echo Constants::DASHBOARD_UPLOAD_SETTINGS_NAME ?>[<?php echo Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME ?>]"
                       value="1"
                        <?php checked(!empty($options[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME])); ?> />
                <?php echo Utils::localize(Constants::UI_KEEP_FILES_AFTER_UPLOAD_FIELD_LABEL); ?>
            </label>
            <p class="description">FlarePress deletes local attachment file after uploading it to Cloudflare.<br/>Enable
                this setting if you prefer to keep the local copy.</p>
            <br/>
            <label>
                <input type="checkbox"
                       name="<?php echo Constants::DASHBOARD_UPLOAD_SETTINGS_NAME ?>[<?php echo Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME ?>]"
                       value="1"
                        <?php checked(!empty($options[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME])); ?> />
                <?php echo Utils::localize(Constants::UI_KEEP_FILES_ON_CF_AFTER_DELETE_FIELD_LABEL); ?>
            </label>
            <p class="description">FlarePress deletes the copy of the attachment from Cloudflare during the deletion
                process.<br/>Enable this setting if you prefer to keep the file on Cloudflare.</p>
        </fieldset>

        <?php
    }

    public static function addLocationInfoToListViewRow(string $columnName, $attachmentId): void
    {
        if ($columnName === Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID && Utils::getCloudflareIdOfAttachment($attachmentId)) {
            echo '<span 
                data-fp-file-name="' . Utils::getAttachmentFileName($attachmentId) . '"
                data-fp-url="' . get_the_guid($attachmentId) . '"
                >
                <img title="' . Utils::localize(Constants::UI_CF_BADGE_TITLE) . '" alt="Cloudflare logo" height="18" src="/wordpress/wp-content/plugins/flare-press/includes/assets/images/cf_logo.png"></span>';
        } else {
            echo Utils::localize(Constants::UI_CF_LOCATION_THIS_SERVER);
        }
    }

    public static function getVariantsAsArray(): array {
        $variantsAsEncodedString = get_option(Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME);

        if(empty($variantsAsEncodedString)) {
            return [];
        }

        $variantsArray = json_decode($variantsAsEncodedString, true);

        if(JSON_ERROR_NONE !== json_last_error()) {
           error_log('[FlarePress] Error retrieving variants options: '.json_last_error_msg());

           return [];
        }

        return $variantsArray;
    }

    public static function getVariantNamesAsArray(): array
    {
        $variantOptions = self::getVariantsAsArray();

        if(empty($variantOptions)) {
            return [];
        }

        return array_keys($variantOptions);
    }

    public static function syncVariants(): array {
        try {
            $variantsFromCloudflare = CloudflareImagesApi::getVariants();
            $jsonEncodedVariants = json_encode($variantsFromCloudflare);

            if(JSON_ERROR_NONE !== json_last_error()) {
                throw new Exception(json_last_error_msg());
            }

            update_option(Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME, $jsonEncodedVariants);
        } catch (Exception $e) {
            error_log('[FlarePress] Unable to update variants option: ' . $e->getMessage());

            return [];
        }

        return $variantsFromCloudflare;
    }
}