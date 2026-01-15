<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_Limits {

    /**
     * 初始化（之後所有 hook 都從這裡進）
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
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
            'woc-contracts-settings',   // menu slug（加前綴避免撞名）
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( '設定', 'woc-contracts' ) . '</h1>';
        echo '<p>' . esc_html__( '（此頁面尚未加入設定項目）', 'woc-contracts' ) . '</p>';
        echo '</div>';
    }

}

// WOC_Contracts_Limits::init();
