<?php
/**
 * Plugin Name: 線上合約
 * Description: 客製線上合約與簽署系統
 * Version:     1.1.0
 * Author:      popcorn
 * Text Domain: woc-contracts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 基本常數（加防呆，避免重複定義）
 */
if ( ! defined( 'WOC_CONTRACTS_VERSION' ) ) {
    define( 'WOC_CONTRACTS_VERSION', '0.1.0' );
}

if ( ! defined( 'WOC_CONTRACTS_FILE' ) ) {
    define( 'WOC_CONTRACTS_FILE', __FILE__ );
}

if ( ! defined( 'WOC_CONTRACTS_PATH' ) ) {
    define( 'WOC_CONTRACTS_PATH', plugin_dir_path( __FILE__ ) );
}

/* 給之前用到 WOC_CONTRACTS_DIR 的地方用，同值 alias */
if ( ! defined( 'WOC_CONTRACTS_DIR' ) ) {
    define( 'WOC_CONTRACTS_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WOC_CONTRACTS_URL' ) ) {
    define( 'WOC_CONTRACTS_URL', plugin_dir_url( __FILE__ ) );
}


// 不分前後台都要載入
require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-cpt.php';        // 註冊 CPT / meta key / 基礎常數
require_once WOC_CONTRACTS_PATH . 'includes/woc-contracts-functions.php';        // 共用函式（前後台都可能用到）

// 一律載入：因為 admin-post.php 也需要處理簽署
require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-frontend.php';   // 前台簽署流程 + admin-post 簽署入口

// 後台才需要的再載入
if ( is_admin() ) {
    require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-limits.php';     // 方案限制引擎（合約/範本/使用者上限）
    require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-admin.php';  // 後台 UI / metabox / ajax 等
    require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-backup.php'; // 備份/匯入匯出（後台頁面 + admin-post）
}





/**
 * 主插件類別（之後所有功能都掛在這裡）
 */
final class WOC_Contracts_Plugin {

    /**
     * 單例
     *
     * @var WOC_Contracts_Plugin|null
     */
    private static $instance = null;

    /**
     * 取得實例
     *
     * @return WOC_Contracts_Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 建構子：掛所有 hook
     */
    private function __construct() {

        /**
         * 模組集中啟動（檔案只負責宣告 class；init 由這裡統一呼叫）
         * 注意：這裡只負責 init，不做任何業務邏輯。
         */

        // CPT / 基礎結構：優先啟動
        if ( class_exists( 'WOC_Contracts_CPT' ) && method_exists( 'WOC_Contracts_CPT', 'init' ) ) {
            WOC_Contracts_CPT::init();
        }

                // 前台/簽署流程（含 admin-post 簽署入口）
        if ( class_exists( 'WOC_Contracts_Frontend' ) && method_exists( 'WOC_Contracts_Frontend', 'init' ) ) {
            WOC_Contracts_Frontend::init();
        }

        // 後台模組（只在後台啟動）
        if ( is_admin() ) {

            // 方案限制（範本數、合約數、使用者數...等）
            if ( class_exists( 'WOC_Contracts_Limits' ) && method_exists( 'WOC_Contracts_Limits', 'init' ) ) {
                WOC_Contracts_Limits::init();
            }

            if ( class_exists( 'WOC_Contracts_Admin' ) && method_exists( 'WOC_Contracts_Admin', 'init' ) ) {
                WOC_Contracts_Admin::init();
            }

            // 備份/匯入匯出（外層檔是舊路徑相容轉接，真正 class 在 includes/backup/ 內）
            if ( class_exists( 'WOC_Contracts_Backup' ) && method_exists( 'WOC_Contracts_Backup', 'init' ) ) {
                WOC_Contracts_Backup::init();
            }
        }

        add_action( 'admin_notices', [ $this, 'admin_notice_phase0' ] );
    }


    /**
     * 外掛啟用時執行
     */
    public static function activate() {
        // 之後有 CPT / Rewrite 時會在這裡 flush_rewrite_rules()
        // 目前 Phase 0 不做任何事
    }

    /**
     * 外掛停用時執行
     */
    public static function deactivate() {
        // 目前不做事，保留掛點
    }

    /**
     * 後台顯示一個一次性的訊息，確認外掛有載入
     */
    public function admin_notice_phase0() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
    }
}

/**
 * 啟動外掛
 */
function woc_contracts_bootstrap() {
    return WOC_Contracts_Plugin::instance();
}
woc_contracts_bootstrap();

/**
 * 註冊啟用／停用 hook
 */
register_activation_hook( __FILE__, [ 'WOC_Contracts_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WOC_Contracts_Plugin', 'deactivate' ] );
