<?php

namespace FlarePress\Controllers;

use FlarePress\Data\Constants;
use FlarePress\Util\Utils;

class Dashboard {
    public function __construct() {
        $this->addSettingsPage();
        $this->addSection();
        $this->registerAccountIDField();
        $this->registerAccountHashField();
        $this->registerAPITokenField();
    }

    private function addSettingsPage(): void {
        add_options_page(
            Constants::DASHBOARD_PAGE_TITLE,
            Constants::DASHBOARD_MENU_TITLE,
            'manage_options',
            Constants::DASHBOARD_MENU_SLUG,
            [$this, 'fpAdminDashboardView'],
            5
        );
    }

    public function fpAdminDashboardView(): void {
        Utils::renderTemplate(Constants::DASHBOARD_VIEW);
    }

    private function addSection(): void {
        add_settings_section(
            'fp_settings_section_id',
            '',
            '',
            Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function registerAccountIDField(): void {
        register_setting(Constants::DASHBOARD_SETTINGS_GROUP_NAME, Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
            Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME,
            'Cloudflare Account ID',
            function() {
                $this->renderInputField(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);
            },
            Constants::DASHBOARD_MENU_SLUG,
            Constants::DASHBOARD_SECTION_ID,
        );
    }

    private function registerAccountHashField(): void {
        register_setting(Constants::DASHBOARD_SETTINGS_GROUP_NAME, Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
                Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
                'Cloudflare Account Hash',
                function() {
                    $description = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/images/hosted</i>';
                    $this->renderInputField(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME, $description);
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_SECTION_ID,
        );
    }

    private function registerApiTokenField(): void {
        register_setting(Constants::DASHBOARD_SETTINGS_GROUP_NAME, Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
            Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME,
            'Cloudflare Account API Token',
            function() {
                $description = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/api-tokens</i>';
                $this->renderInputField(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, $description);
            },
            Constants::DASHBOARD_MENU_SLUG,
            Constants::DASHBOARD_SECTION_ID,
        );
    }

    private function renderInputField(string $optionName, string $description = '', bool $hideValue = false): void {
        $value = get_option($optionName);

        ?>
        <label>
            <input
                value="<?php echo !$hideValue ? esc_attr(get_option($optionName)) : ''; ?>"
                name="<?php echo $optionName ?>"
                id="<?php echo $optionName ?>"
                type="text"
                class="regular-text" />
        </label>
        <?php
        if(!empty($description)){
            ?>
            <p class="description"><?php echo $description ?></p>
            <?php
        }
    }

    public static function addLocationInfoToListViewRow(string $columnName, $attachmentId): void {
        if ($columnName === Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID && Utils::getCloudflareIdOfAttachment($attachmentId)) {
            echo '<span 
                data-fp-file-name="' . Utils::getAttachmentFileName($attachmentId) . '"
                data-fp-url="'. get_the_guid($attachmentId)  .'"
                >
                <img title="'. Utils::translate(Constants::DASHBOARD_CF_BADGE_TITLE) .'" alt="Cloudflare logo" height="18" src="/wordpress/wp-content/plugins/flare-press/includes/assets/images/cf_logo.png"></span>';
        } else {
            echo Utils::translate(Constants::DASHBOARD_CF_LOCATION_THIS_SERVER);
        }
    }
}