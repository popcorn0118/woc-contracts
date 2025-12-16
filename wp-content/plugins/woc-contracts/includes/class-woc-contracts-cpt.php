<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_CPT {

    // 合約實體 CPT
    const POST_TYPE_CONTRACT = 'woc_contract';

    // 合約範本 CPT（改一個簡短、不易撞名的 slug）
    const POST_TYPE_TEMPLATE = 'woc_ct_template';

    const META_TEMPLATE_ID     = '_woc_template_id';
    const META_STATUS          = '_woc_status';
    const META_VIEW_TOKEN      = '_woc_view_token';
    const META_SIGNED_AT       = '_woc_signed_at';
    const META_SIGNED_IP       = '_woc_signed_ip';
    const META_SIGNATURE_IMAGE = '_woc_signature_image';
    // const META_SNAPSHOT_FILE   = '_woc_snapshot_file';
    // const META_SNAPSHOT_HASH   = '_woc_snapshot_hash';
    // const META_CLIENT_NAME     = '_woc_client_name';
    // const META_CLIENT_PHONE    = '_woc_client_phone';
    const META_AUDIT_LOG       = '_woc_audit_log';


    /**
     * 掛 hook
     */
    public static function init() {
        // CPT 一定在 init 早期就註冊
        add_action( 'init',       [ __CLASS__, 'register_post_types' ], 0 );
        // 等 CPT 註冊好之後再掛選單
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menus' ] );
    }

    /**
     * 註冊 CPT
     */
    public static function register_post_types() {

        // 1. 合約實體
        register_post_type(
            self::POST_TYPE_CONTRACT,
            [
                'labels' => [
                    'name'               => '線上合約',
                    'singular_name'      => '線上合約',
                    'add_new'            => '新增合約',
                    'add_new_item'       => '新增合約',
                    'edit_item'          => '編輯合約',
                    'new_item'           => '新增合約',
                    'view_item'          => '檢視合約',
                    'search_items'       => '搜尋合約',
                    'not_found'          => '沒有找到合約',
                    'not_found_in_trash' => '資源回收筒中沒有合約',
                    'menu_name'          => '線上合約',
                ],
                'public'              => true, //post type 不是只有後台在用，我也要前台網址。
                'publicly_queryable'  => true, //表示這個 CPT 可以被前台 query 到，也就是 /contract/12/ 這種 URL 會被當成「合法的單篇頁」。
                'exclude_from_search' => true,  // 雖然可以用 URL 直接看這篇合約，但它 不會出現在站內搜尋結果，這樣合約就不會被一般訪客「搜尋挖出來」。
                'has_archive'         => false, //不建立 /contract/ 這種「合約列表頁」的 archive，避免有人用列表方式掃所有合約
                
                'show_ui'         => true,
                'show_in_menu'    => true,
                'menu_position'   => 25,
                'menu_icon'       => 'dashicons-media-text',
                'supports'        => [ 'title', 'editor' ],
                'rewrite'         => [
                    'slug'       => 'contract',
                    'with_front' => false,
                ],
                'capability_type' => 'post',
                'map_meta_cap'    => true,
            ]
        );

        // 2. 合約範本（獨立 CPT，不直接出選單）
        register_post_type(
            self::POST_TYPE_TEMPLATE,
            [
                'labels' => [
                    'name'               => '合約範本',
                    'singular_name'      => '合約範本',
                    'add_new'            => '新增範本',
                    'add_new_item'       => '新增範本',
                    'edit_item'          => '編輯範本',
                    'new_item'           => '新增範本',
                    'view_item'          => '檢視範本',
                    'search_items'       => '搜尋範本',
                    'not_found'          => '沒有找到範本',
                    'not_found_in_trash' => '資源回收筒中沒有範本',
                    'menu_name'          => '合約範本',
                ],
                'public'          => false,
                'show_ui'         => true,
                'show_in_menu'    => false,              // 選單待會手動掛
                'supports'        => [ 'title', 'editor' ],
                'has_archive'     => false,
                'rewrite'         => false,
                'capability_type' => 'post',
                'map_meta_cap'    => true,
            ]
        );
    }

    /**
     * 把「合約範本」掛在「線上合約」底下
     */
    public static function register_admin_menus() {

        $parent_slug  = 'edit.php?post_type=' . self::POST_TYPE_CONTRACT;
        // 這裡要跟上面 CPT slug 完全一致
        $submenu_slug = 'edit.php?post_type=' . self::POST_TYPE_TEMPLATE;

        add_submenu_page(
            $parent_slug,
            '合約範本',           // 頁面標題
            '合約範本',           // 選單文字
            'edit_posts',         // 權限
            $submenu_slug         // 導向的頁面 slug
        );
    }

    /**
     * 後台紀錄 操作紀錄log
     */
    public static function add_audit_log( $contract_id, $message ) {
        $contract_id = (int) $contract_id;
        $message     = trim( (string) $message );
    
        if ( ! $contract_id || $message === '' ) {
            return;
        }
    
        $logs = get_post_meta( $contract_id, self::META_AUDIT_LOG, true );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }
    
        $logs[] = array(
            'time'    => current_time( 'mysql' ),
            'message' => $message,
        );

        // 最多保留 50 筆
        if ( count( $logs ) > 50 ) {
            $logs = array_slice( $logs, -50 );
        }
    
        update_post_meta( $contract_id, self::META_AUDIT_LOG, $logs );
    }

    

}

WOC_Contracts_CPT::init();
