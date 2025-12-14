<?php

namespace FP\Controllers;

use FP\Utils\Template;

class FPDashboard {
    const DASHBOARD_VIEW = 'admin/dashboard';
    const MENU_SLUG = 'flare-press-settings';
    const MENU_TITLE = 'FlarePress';
    const PAGE_TITLE = 'FlarePress Settings';
    const SETTINGS_GROUP_NAME = 'fp_settings_group';
    const SECTION_ID = 'fp_settings_section_id';
    const CF_ACCOUNT_ID_FIELD_NAME = 'fp_cf_account_id';
    const CF_API_TOKEN_FIELD_NAME = 'fp_cf_api_token';

    public function __construct() {
        add_action( 'admin_menu', [$this, 'initFPSettings'] );
    }

    public function initFPSettings(): void {
        $this->addSettingsPage();
        $this->addSection();
        $this->registerAccountIDField();
        $this->registerAPITokenField();
    }

    private function addSettingsPage(): void {
        add_options_page(
            self::PAGE_TITLE,
            self::MENU_TITLE,
            'manage_options',
            self::MENU_SLUG,
            [$this, 'fpAdminDashboardView'],
            5
        );
    }

    public function fpAdminDashboardView(): void {
        Template::render(self::DASHBOARD_VIEW);
    }

    private function addSection(): void {
        add_settings_section(
            'fp_settings_section_id',
            '',
            '',
            self::MENU_SLUG
        );
    }

    private function registerAccountIDField(): void {
        register_setting(self::SETTINGS_GROUP_NAME, self::CF_ACCOUNT_ID_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
            self::CF_ACCOUNT_ID_FIELD_NAME,
            'Cloudflare Account ID',
            function() {
                $this->renderInputField(self::CF_ACCOUNT_ID_FIELD_NAME);
            },
            self::MENU_SLUG,
            self::SECTION_ID,
        );
    }

    private function registerApiTokenField(): void {
        register_setting(self::SETTINGS_GROUP_NAME, self::CF_API_TOKEN_FIELD_NAME, [$this, 'sanitizeText']);

        add_settings_field(
            self::CF_API_TOKEN_FIELD_NAME,
            'Cloudflare Account API Token',
            function() {
                $description = 'You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/api-tokens</i>';
                $this->renderInputField(self::CF_API_TOKEN_FIELD_NAME, $description);
            },
            self::MENU_SLUG,
            self::SECTION_ID,
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
}