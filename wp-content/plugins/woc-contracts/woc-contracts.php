<?php
/**
 * Plugin Name: 線上合約
 * Description: 客製線上合約與簽署系統
 * Version:     0.1.0
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


// 1. CPT & 共用函式：不分前後台都要載入
require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-cpt.php';
require_once WOC_CONTRACTS_PATH . 'includes/woc-contracts-functions.php';

// 一律載入：因為 admin-post.php 也需要處理簽署
require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-frontend.php';

// 後台才需要的再載入
if ( is_admin() ) {
    require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-admin.php';
    require_once WOC_CONTRACTS_PATH . 'includes/class-woc-contracts-backup.php';
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
        // 之後 Phase 1+ 的程式都從這裡往外 require
        // 例如：$this->load_cpts(); $this->load_frontend(); 等等。

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
