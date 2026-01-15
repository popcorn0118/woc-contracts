<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_Limits {

    const OPTION_KEY = 'woc_contracts_limits';

    /**
     * 初始化（之後所有 hook 都從這裡進）
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_save' ] );
    }

    public static function register_menu() {

        // 先只讓最高權限可見（跟你現有備份頁一致）
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // 依附在「合約」CPT 的選單底下（跟備份/匯入匯出同層）
        if ( ! class_exists( 'WOC_Contracts_CPT' ) ) {
            return;
        }

        $parent_slug = 'edit.php?post_type=' . WOC_Contracts_CPT::POST_TYPE_CONTRACT;

        add_submenu_page(
            $parent_slug,
            '設定',                     // page title
            '設定',                     // menu title
            'manage_options',
            'woc-contracts-settings',   // menu slug
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * 儲存設定（只存，不啟用任何限制）
     */
    public static function handle_save() {
        if ( ! is_admin() ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( empty( $_POST['woc_contracts_limits_action'] ) || $_POST['woc_contracts_limits_action'] !== 'save' ) {
            return;
        }

        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'woc_contracts_limits_save' ) ) {
            wp_die( 'Nonce 驗證失敗。' );
        }

        $contracts_limit  = isset( $_POST['contracts_limit'] ) ? (int) $_POST['contracts_limit'] : 0;
        $templates_limit  = isset( $_POST['templates_limit'] ) ? (int) $_POST['templates_limit'] : 0;
        $users_limit      = isset( $_POST['users_limit'] ) ? (int) $_POST['users_limit'] : 0;

        // 允許 0 表示「不限制」
        $data = [
            'contracts_limit' => max( 0, $contracts_limit ),
            'templates_limit' => max( 0, $templates_limit ),
            'users_limit'     => max( 0, $users_limit ),
            'updated_at'      => time(),
        ];

        update_option( self::OPTION_KEY, $data, false );

        // Redirect 避免重送表單
        $url = add_query_arg(
            [ 'page' => 'woc-contracts-settings', 'updated' => '1' ],
            admin_url( 'edit.php?post_type=' . WOC_Contracts_CPT::POST_TYPE_CONTRACT )
        );
        wp_safe_redirect( $url );
        exit;
    }

    private static function get_settings() {
        $defaults = [
            'contracts_limit' => 0,
            'templates_limit' => 0,
            'users_limit'     => 0,
        ];

        $opt = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $opt ) ) {
            $opt = [];
        }

        return array_merge( $defaults, $opt );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s = self::get_settings();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( '設定', 'woc-contracts' ) . '</h1>';

        if ( isset( $_GET['updated'] ) && $_GET['updated'] === '1' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '已儲存。', 'woc-contracts' ) . '</p></div>';
        }

        echo '<form method="post" action="">';
        wp_nonce_field( 'woc_contracts_limits_save' );

        echo '<input type="hidden" name="woc_contracts_limits_action" value="save" />';

        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';

        // 合約上限
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="contracts_limit">' . esc_html__( '合約上限', 'woc-contracts' ) . '</label> ';
        echo '<span class="dashicons dashicons-editor-help" style="vertical-align:middle; cursor:help;" title="計算規則：所有狀態都算（含回收桶 trash）。狀態：publish/draft/pending/private/future/trash/auto-draft/inherit。0 = 不限制。"></span>';
        echo '</th>';
        echo '<td>';
        echo '<input name="contracts_limit" id="contracts_limit" type="number" min="0" step="1" value="' . esc_attr( (int) $s['contracts_limit'] ) . '" class="small-text" />';
        echo '</td>';
        echo '</tr>';

        // 範本上限
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="templates_limit">' . esc_html__( '範本上限', 'woc-contracts' ) . '</label> ';
        echo '<span class="dashicons dashicons-editor-help" style="vertical-align:middle; cursor:help;" title="計算規則：所有狀態都算（含回收桶 trash）。狀態：publish/draft/pending/private/future/trash/auto-draft/inherit。0 = 不限制。"></span>';
        echo '</th>';
        echo '<td>';
        echo '<input name="templates_limit" id="templates_limit" type="number" min="0" step="1" value="' . esc_attr( (int) $s['templates_limit'] ) . '" class="small-text" />';
        echo '</td>';
        echo '</tr>';

        // 使用者上限
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="users_limit">' . esc_html__( '使用者上限', 'woc-contracts' ) . '</label> ';
        echo '<span class="dashicons dashicons-editor-help" style="vertical-align:middle; cursor:help;" title="計算規則：排除網站管理員（administrator），其餘全部計入。0 = 不限制。"></span>';
        echo '</th>';
        echo '<td>';
        echo '<input name="users_limit" id="users_limit" type="number" min="0" step="1" value="' . esc_attr( (int) $s['users_limit'] ) . '" class="small-text" />';
        echo '</td>';
        echo '</tr>';

        echo '</tbody>';
        echo '</table>';

        submit_button( __( '儲存設定', 'woc-contracts' ) );

        echo '</form>';
        echo '</div>';
    }
}
