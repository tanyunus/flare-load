<?php

namespace FlarePress\Controllers;

defined('ABSPATH') || exit;

use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Logger;
use FlarePress\Util\Utils;

class OptionController
{
    private array $variantNames;

    public function __construct(bool $addMigratePage = false)
    {
        $this->variantNames = self::getVariantNamesAsArray();

        $this->addSettingsPage();

        $this->addUploadSettingsSection();
        $this->addApiSettingsSection();
        $this->addVariantSettingsSection();
        $this->registerAccountIDField();
        $this->registerAccountHashField();
        $this->registerAPITokenField();
        $this->registerSigningKeyField();
        $this->registerFileManagementField();
        $this->registerDefaultVariantField();

        if ($addMigratePage) {
            self::addMigratePage();
        }

        $this->addLogPage();
        $this->addLogViewerSection();
        $this->registerLogViewer();
    }

    private function addSettingsPage(): void
    {
        add_menu_page(
                __('FlarePress Settings', 'flare-press'),
                __('FlarePress', 'flare-press'),
                'manage_options',
                Constants::DASHBOARD_MENU_SLUG,
                [$this, 'fpAdminDashboardView'],
                FLARE_PRESS_URL . 'dist/images/fp_dashboard_icon.svg',
                5
        );
    }

    public static function addMigratePage(): void
    {
        add_submenu_page(
            Constants::DASHBOARD_MENU_SLUG,
            __('Migrate to Local', 'flare-press'),
            __('Migrate to Local', 'flare-press'),
            'manage_options',
            Constants::DASHBOARD_MIGRATE_PAGE_SLUG,
            [self::class, 'fpAdminMigrateView'],
        );
    }

    public static function fpAdminMigrateView(): void
    {
        Utils::renderTemplate(Constants::MIGRATE_VIEW);
    }

    private function addLogPage(): void
    {
        add_submenu_page(
                Constants::DASHBOARD_MENU_SLUG,
                __('FlarePress Logs', 'flare-press'),
                __('Logs', 'flare-press'),
                'manage_options',
                Constants::DASHBOARD_LOG_PAGE_SLUG,
                [$this, 'fpAdminLogView'],
        );
    }

    public function fpAdminDashboardView(): void
    {
        Utils::renderTemplate(Constants::DASHBOARD_VIEW);
    }

    public function fpAdminLogView(): void
    {
        Utils::renderTemplate(Constants::LOG_VIEW);
    }

    private function addApiSettingsSection(): void
    {
        add_settings_section(
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
                __('API Settings', 'flare-press'),
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function addVariantSettingsSection(): void
    {
        add_settings_section(
                Constants::DASHBOARD_VARIANT_SETTINGS_SECTION_ID,
                __('Variant Settings', 'flare-press'),
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function addUploadSettingsSection(): void
    {
        add_settings_section(
                Constants::DASHBOARD_UPLOAD_SETTINGS_SECTION_ID,
                __('Upload Settings', 'flare-press'),
                '',
                Constants::DASHBOARD_MENU_SLUG
        );
    }

    private function addLogViewerSection(): void
    {
        add_settings_section(
                Constants::LOG_VIEWER_SECTION_ID,
                __('Log Viewer', 'flare-press'),
                null,
                Constants::DASHBOARD_LOG_PAGE_SLUG
        );
    }

    private function renderLogViewer(): void
    {
        ?>
        <label>
            <textarea
                    class="fp-log-viewer-textarea"
                    id="<?php echo esc_attr(Constants::LOG_VIEWER_FIELD_NAME) ?>"
                    name="<?php echo esc_attr(Constants::LOG_VIEWER_FIELD_NAME) ?>"
                    rows="20"
                    disabled
            ><?php echo esc_textarea(Logger::getLogFile()) ?></textarea>
        </label>
        <p class="description"></p>
        <?php
    }

    private function registerLogViewer(): void
    {
        register_setting(Constants::LOG_SETTINGS_GROUP_NAME, Constants::LOG_VIEWER_FIELD_NAME, [
            'sanitize_callback' => 'sanitize_textarea_field',
        ]);

        add_settings_field(
                Constants::LOG_VIEWER_FIELD_NAME,
                __('Logs', 'flare-press'),
                function () {
                    $this->renderLogViewer();
                },
                Constants::DASHBOARD_LOG_PAGE_SLUG,
                Constants::LOG_VIEWER_SECTION_ID,
        );
    }

    private function registerAccountIDField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME,
                [
                        'sanitize_callback' => 'sanitize_text_field',
                        'default' => ''
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME,
                __('Cloudflare Account ID', 'flare-press'),
                function () {
                    $this->renderTextField(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerAccountHashField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
                [
                        'sanitize_callback' => 'sanitize_text_field',
                        'default' => ''
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
                __('Cloudflare Account Hash', 'flare-press'),
                function () {
                    $this->renderTextField(
                        Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
                        __('You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/images/hosted</i>', 'flare-press')
                    );
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerApiTokenField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME,
                [
                        'sanitize_callback' => 'sanitize_text_field',
                        'default' => ''
                ]
        );

        add_settings_field(
                Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME,
                __('Cloudflare Account API Token', 'flare-press'),
                function () {
                    $this->renderApiTokenField();
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function registerSigningKeyField(): void
    {
        register_setting(
            Constants::DASHBOARD_SETTINGS_GROUP_NAME,
            Constants::DASHBOARD_CF_SIGNING_KEY_FIELD_NAME,
            [
                'sanitize_callback' => function ($input) {
                    // Explicit remove action submitted via checkbox
                    $action = sanitize_key(wp_unslash($_POST['fp_cf_signing_key_action'] ?? 'keep'));
                    if ($action === 'remove') {
                        return '';
                    }
                    $cleaned = preg_replace('/[^0-9a-fA-F]/', '', sanitize_text_field($input));
                    // Empty submission keeps the existing key
                    if (empty($cleaned)) {
                        return get_option(Constants::DASHBOARD_CF_SIGNING_KEY_FIELD_NAME, '');
                    }
                    return $cleaned;
                },
                'default' => '',
            ]
        );

        add_settings_field(
            Constants::DASHBOARD_CF_SIGNING_KEY_FIELD_NAME,
            __('URL Signing Key', 'flare-press'),
            function () {
                $this->renderSigningKeyField();
            },
            Constants::DASHBOARD_MENU_SLUG,
            Constants::DASHBOARD_API_SETTINGS_SECTION_ID,
        );
    }

    private function renderSigningKeyField(): void
    {
        $hasKey = !empty(get_option(Constants::DASHBOARD_CF_SIGNING_KEY_FIELD_NAME));
        ?>
        <input
            type="password"
            value=""
            name="<?php echo esc_attr(Constants::DASHBOARD_CF_SIGNING_KEY_FIELD_NAME) ?>"
            id="<?php echo esc_attr(Constants::DASHBOARD_CF_SIGNING_KEY_FIELD_NAME) ?>"
            placeholder="<?php echo $hasKey ? esc_attr(__('Key configured — leave blank to keep, enter new to replace', 'flare-press')) : '' ?>"
            autocomplete="new-password"
            class="regular-text"/>
        <?php if ($hasKey) { ?>
        <div style="margin-top:6px;">
            <input type="hidden" name="fp_cf_signing_key_action" value="keep">
            <label>
                <input type="checkbox" name="fp_cf_signing_key_action" value="remove">
                <?php esc_html_e('Remove signing key', 'flare-press'); ?>
            </label>
        </div>
        <?php } ?>
        <p class="description"><?php echo wp_kses(__('Optional. Hex-encoded key for Cloudflare Images signed URLs. Generate one at <em>Cloudflare Dashboard → Images → Keys</em>. When set, all image delivery URLs include a time-limited signature. Leave empty to disable.', 'flare-press'), ['em' => []]); ?></p>
        <?php
    }

    private function registerDefaultVariantField(): void
    {
        register_setting(
                Constants::DASHBOARD_SETTINGS_GROUP_NAME,
                Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME,
                array(
                        'type' => 'string',
                        'sanitize_callback' => function ($input) {
                            return $this->sanitizeDefaultVariantField($input);
                        },
                        'default' => $this->getVariantNamesAsArray()[0] ?? ''
                )
        );

        add_settings_field(
                Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME,
                __('Default Variant', 'flare-press'),
                function () {
                    $this->renderDefaultVariantField();
                },
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_VARIANT_SETTINGS_SECTION_ID,
        );
    }

    private function renderDefaultVariantField(): void
    {
        $currentValue = get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME, $this->getVariantNamesAsArray()[0] ?? '');
        $options      = self::getVariantOptions();

        ?>
        <div class="fp-sync-button-and-spinner">
            <select name="<?php echo esc_attr(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME) ?>"
                    id="<?php echo esc_attr(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME) ?>">
                <?php foreach ($options as $opt) : ?>
                    <option value="<?php echo esc_attr($opt['name']); ?>" <?php selected($currentValue, $opt['name']); ?>>
                        <?php echo esc_html($opt['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button id="fp_variant_sync_button" type="button" role="button"
                    class="fp-variant-sync-button button button-secondary">
                <span class="dashicons dashicons-update-alt"></span>
                <?php echo esc_html(__('Sync Variants', 'flare-press')); ?>
            </button>
            <span id="fp_sync_variant_spinner" class="spinner"></span>
        </div>
        <p class="description"><?php echo wp_kses(__('Choose the largest variant without cropping as the default. <br/>This ensures the full image is clearly visible and recognizable.', 'flare-press'), ['br' => []]); ?></p>
        <?php
    }

    private function sanitizeDefaultVariantField($input): string
    {
        $allowedValues = $this->getVariantNamesAsArray();

        if (in_array($input, $allowedValues, true)) {
            return $input;
        }

        return $allowedValues[0] ?? '';
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
                __('File management', 'flare-press'),
                [$this, 'renderFileManagementCheckboxFields'],
                Constants::DASHBOARD_MENU_SLUG,
                Constants::DASHBOARD_UPLOAD_SETTINGS_SECTION_ID,
        );
    }

    private function renderApiTokenField(): void
    {
        $optionVal = trim(esc_attr(get_option(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME)));
        ?>
        <label class="fp-api-token-field-label">
            <input
                    type="password"
                    value="<?php echo !empty($optionVal) ? '••••••••••••••••••••••' : '' ?>"
                    data-field-name="<?php echo esc_attr(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME) ?>"
                    <?php echo empty($optionVal) ? 'name="' . esc_attr(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME) . '"' : '' ?>
                    <?php echo empty($optionVal) ? 'id="' . esc_attr(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME) . '"' : '' ?>
                    <?php echo !empty($optionVal) ? 'disabled' : '' ?>
                    <?php echo empty($optionVal) ? 'required' : '' ?>
                    autocomplete="new-password"
                    class="regular-text"/>

            <?php
            if (!empty($optionVal)) {
                ?>
                <button id="fp_change_api_token_button" class="button button-secondary fp-change-api-token-button"
                        type="button" role="button">
                    <span class="dashicons dashicons-edit"></span>
                </button>
                <?php
            }
            ?>
        </label>
        <?php if (!empty($optionVal)) { ?>
        <div class="fp-test-connection-wrap" style="margin-top:8px;">
            <button id="fp_test_connection_button" class="button button-secondary" type="button">
                <?php echo esc_html(__('Test Connection', 'flare-press')); ?>
            </button>
            <span id="fp_test_connection_result" style="margin-left:8px;"></span>
        </div>
        <?php } ?>
        <p class="description"><?php echo wp_kses(__('You can find it under <i>https://dash.cloudflare.com/<b>your-account-id-here</b>/api-tokens</i>', 'flare-press'), ['i' => [], 'b' => []]); ?></p>
        <?php
    }

    private function renderTextField(string $optionName, string $description = ''): void
    {
        $optionVal = trim(esc_attr(get_option($optionName)));

        ?>
        <label>
            <input
                    value="<?php echo esc_attr($optionVal) ?>"
                    name="<?php echo esc_attr($optionName) ?>"
                    id="<?php echo esc_attr($optionName) ?>"
                    type="text"
                    autocomplete="new-password"
                    class="regular-text"/>
        </label>
        <?php
        if (!empty($description)) {
            ?>
            <p class="description"><?php echo wp_kses($description, ['i' => [], 'b' => [], 'em' => [], 'br' => [], 'a' => ['href' => [], 'target' => []]]) ?></p>
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
                       name="<?php echo esc_attr(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME) ?>[<?php echo esc_attr(Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME) ?>]"
                       value="1"
                        <?php checked(!empty($options[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME])); ?> />
                <?php echo esc_html(__('Keep a local copy of the uploaded media', 'flare-press')); ?>
            </label>
            <p class="description"><?php echo esc_html(__('Enable this setting if you prefer to keep a local copy of the uploaded media. The copy of the file will be kept on default upload folder', 'flare-press')); ?></p>
            <br/>
            <label>
                <input type="checkbox"
                       name="<?php echo esc_attr(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME) ?>[<?php echo esc_attr(Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME) ?>]"
                       value="1"
                        <?php checked(!empty($options[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME])); ?> />
                <?php echo esc_html(__('Keep files on Cloudflare after delete', 'flare-press')); ?>
            </label>
            <p class="description"><?php echo wp_kses(__('FlarePress deletes the copy of the attachment from Cloudflare during the deletion process.<br/>Enable this setting if you prefer to keep the file on Cloudflare.', 'flare-press'), ['br' => []]); ?></p>
        </fieldset>

        <?php
    }

    public static function addLocationInfoToListViewRow(string $columnName, $attachmentId): void
    {
        if ($columnName === Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID && AttachmentController::getCloudflareIdOfAttachment($attachmentId)) {
            echo '<span
                data-fp-file-name="' . esc_attr(AttachmentController::getAttachmentFileName($attachmentId)) . '"
                data-fp-url="' . esc_attr(get_the_guid($attachmentId)) . '"
                >
                <img title="' . esc_attr(__('Uploaded to Cloudflare', 'flare-press')) . '" alt="Cloudflare logo" height="18" src="' . esc_url(FLARE_PRESS_URL . 'dist/images/cf_logo.png') . '"></span>';
        } else {
            echo esc_html(__('This server', 'flare-press'));
        }
    }

    /**
     * Returns variants cached in the DB from the last manual sync.
     * These aren't fetched live from Cloudflare — the user syncs them via the settings page.
     */
    public static function getVariants(): array
    {
        $variantsAsEncodedString = get_option(Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME);

        if (empty($variantsAsEncodedString)) {
            return [];
        }

        $variantsArray = json_decode($variantsAsEncodedString, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            Logger::log(0, '[OPTIONS] Error retrieving variants options: ' . json_last_error_msg());

            return [];
        }

        return $variantsArray;
    }

    private function getVariantNamesAsArray(): array
    {
        if (empty($this->variantNames)) {
            $this->variantNames = self::getVariantNames();
        }

        return $this->variantNames;
    }

    public static function getVariantNames(): array
    {
        $variantNamesArray = array_keys(self::getVariants());

        sort($variantNamesArray);

        return $variantNamesArray;
    }

    /** e.g. "blob (10000×10000, Watermarked)" */
    public static function buildVariantLabel(string $name, array $variantData): string
    {
        $options = $variantData['options'] ?? [];
        $width   = isset($options['width'])  ? (int) $options['width']  : null;
        $height  = isset($options['height']) ? (int) $options['height'] : null;
        $draw    = $options['draw'] ?? [];

        $parts = [];
        if ($width && $height) {
            $parts[] = $width . '×' . $height;
        }
        if (!empty($draw)) {
            $parts[] = 'Watermarked';
        }

        return $name . (!empty($parts) ? ' (' . implode(', ', $parts) . ')' : '');
    }

    public static function getVariantOptions(): array
    {
        $variants = self::getVariants();
        $options  = [];

        foreach ($variants as $name => $data) {
            $options[] = [
                'name'  => $name,
                'label' => self::buildVariantLabel($name, is_array($data) ? $data : []),
            ];
        }

        usort($options, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $options;
    }

    public static function syncVariants(): array
    {
        try {
            $variantsFromCloudflare = CloudflareImagesApi::getVariants();
            $jsonEncodedVariants = json_encode($variantsFromCloudflare);

            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new Exception(json_last_error_msg());
            }

            update_option(Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME, $jsonEncodedVariants);
        } catch (Exception $e) {
            Logger::log(0, '[OPTIONS] Unable to update variants option: ' . $e->getMessage());

            return [];
        }

        return $variantsFromCloudflare;
    }

    public static function getAccountHash(): string
    {
        return get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME) ?? '';
    }
}
