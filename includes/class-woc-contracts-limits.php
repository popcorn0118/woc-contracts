<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_Limits {

    const OPT_CONTRACT_LIMIT  = 'woc_contracts_contract_limit';
    const OPT_TEMPLATE_LIMIT  = 'woc_contracts_template_limit';
    const OPT_USER_LIMIT      = 'woc_contracts_user_limit';

    const SETTINGS_GROUP      = 'woc_contracts_settings_group';

    /**
     * 初始化
     */
    public static function init() {

        // Settings API（讓 options.php 能正常儲存，不會跳去「全部設定」）
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        // 設定頁選單
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );

        // 1) UI 層：進「新增」頁就擋
        add_action( 'load-post-new.php', [ __CLASS__, 'maybe_block_post_new' ] );

        // 2) 寫入層：就算硬打 post.php 也擋
        add_filter( 'wp_insert_post_data', [ __CLASS__, 'maybe_block_post_insert' ], 10, 2 );

        // 使用者上限：UI 層（進 user-new.php 就擋）
        add_action( 'load-user-new.php', [ __CLASS__, 'maybe_block_user_new' ] );

        // 使用者上限：寫入層（後台新增使用者送出前擋）
        add_action( 'user_profile_update_errors', [ __CLASS__, 'maybe_block_user_admin_create' ], 10, 3 );

        // 使用者上限：前台註冊（有開放註冊時）
        add_filter( 'registration_errors', [ __CLASS__, 'maybe_block_user_registration' ], 10, 3 );
    }

    /**
     * 註冊設定（0 = 不限）
     */
    public static function register_settings() {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPT_CONTRACT_LIMIT,
            [
                'type'              => 'integer',
                'sanitize_callback' => [ __CLASS__, 'sanitize_limit' ],
                'default'           => 0,
            ]
        );

        register_setting(
            self::SETTINGS_GROUP,
            self::OPT_TEMPLATE_LIMIT,
            [
                'type'              => 'integer',
                'sanitize_callback' => [ __CLASS__, 'sanitize_limit' ],
                'default'           => 0,
            ]
        );

        register_setting(
            self::SETTINGS_GROUP,
            self::OPT_USER_LIMIT,
            [
                'type'              => 'integer',
                'sanitize_callback' => [ __CLASS__, 'sanitize_limit' ],
                'default'           => 0,
            ]
        );

        add_action( 'admin_notices', function() {
            if ( ! current_user_can( 'manage_options' ) ) return;
            if ( empty( $_GET['page'] ) || $_GET['page'] !== 'woc-contracts-settings' ) return;
            if ( empty( $_GET['settings-updated'] ) ) return;

            add_settings_error(
                'woc_contracts_settings',
                'woc_contracts_settings_saved',
                '設定已儲存。',
                'updated'
            );
        } );

    }

    public static function sanitize_limit( $value ) {
        $value = is_numeric( $value ) ? (int) $value : 0;
        return max( 0, $value );
    }

    public static function register_menu() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! class_exists( 'WOC_Contracts_CPT' ) ) {
            return;
        }

        $parent_slug = 'edit.php?post_type=' . WOC_Contracts_CPT::POST_TYPE_CONTRACT;

        add_submenu_page(
            $parent_slug,
            '設定',
            '設定',
            'manage_options',
            'woc-contracts-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * 取得上限（0 = 不限）
     */
    private static function get_limit_for_post_type( $post_type ) {
        $post_type = (string) $post_type;

        if ( class_exists( 'WOC_Contracts_CPT' ) ) {
            if ( $post_type === (string) WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
                return (int) get_option( self::OPT_CONTRACT_LIMIT, 0 );
            }
            if ( $post_type === (string) WOC_Contracts_CPT::POST_TYPE_TEMPLATE ) {
                return (int) get_option( self::OPT_TEMPLATE_LIMIT, 0 );
            }
        }

        return 0;
    }

    /**
     *  Total：
     * Total = All（後台「全部」看到的所有狀態，排除 trash） + Trash
     * => DB 上等於「這個 post_type 全部文章」但排除 auto-draft（避免暫存稿亂跳）
     */
    private static function count_total_including_trash( $post_type ) {
        global $wpdb;

        $post_type = (string) $post_type;
        if ( $post_type === '' ) return 0;

        $sql = "
            SELECT COUNT(1)
            FROM {$wpdb->posts}
            WHERE post_type = %s
              AND post_status <> %s
        ";

        return (int) $wpdb->get_var(
            $wpdb->prepare( $sql, $post_type, 'auto-draft' )
        );
    }

    /**
     * 使用者計數（排除 administrator / super admin）
     * - 單站：排除 role=administrator
     * - 多站：排除 blog 管理員 + super admin（site_admins）
     */
    private static function count_users_excluding_admins() {
        global $wpdb;

        $cap_key = $wpdb->get_blog_prefix() . 'capabilities';

        // multisite 的 super admins（若非 multisite 會回空）
        $super_admins = [];
        if ( is_multisite() ) {
            $super_admins = (array) get_site_option( 'site_admins', [] );
            $super_admins = array_filter( array_map( 'sanitize_user', $super_admins ) );
        }

        $where_super_admin = '';
        if ( ! empty( $super_admins ) ) {
            // 用 user_login 排除 super admin
            $placeholders = implode( ',', array_fill( 0, count( $super_admins ), '%s' ) );
            $where_super_admin = " AND u.user_login NOT IN ($placeholders) ";
        }

        // capabilities 是序列化字串，直接 LIKE '%\"administrator\";b:1%'
        $like_admin = '%"administrator"%';

        $sql = "
            SELECT COUNT(DISTINCT u.ID)
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um
                   ON um.user_id = u.ID
                  AND um.meta_key = %s
            WHERE ( um.meta_value IS NULL OR um.meta_value NOT LIKE %s )
            $where_super_admin
        ";

        $params = [ $cap_key, $like_admin ];
        if ( ! empty( $super_admins ) ) {
            $params = array_merge( $params, $super_admins );
        }

        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * 使用者上限（0 = 不限）
     */
    private static function get_user_limit() {
        return (int) get_option( self::OPT_USER_LIMIT, 0 );
    }

    /**
     * UI 層攔截：進新增使用者頁就擋
     */
    public static function maybe_block_user_new() {
        if ( ! is_admin() ) return;
        if ( ! current_user_can( 'create_users' ) ) return;

        $limit = self::get_user_limit();
        if ( $limit <= 0 ) return;

        $total = self::count_users_excluding_admins();

        if ( $total >= $limit ) {
            $list_url = admin_url( 'users.php' );
            $title    = '使用者上限已達';

            $msg = sprintf(
                '%s<br><br>上限：%d<br>目前數量：%d（排除管理員）<br><br>請刪除不需要的使用者後再新增。<br><br><a href="%s">回到使用者列表</a>',
                esc_html( $title ),
                (int) $limit,
                (int) $total,
                esc_url( $list_url )
            );

            wp_die( $msg, esc_html( $title ), [ 'response' => 403 ] );
        }
    }

    /**
     * 寫入層攔截：後台新增使用者送出前擋（只擋新增，不擋更新）
     */
    public static function maybe_block_user_admin_create( $errors, $update, $user ) {
        if ( $update ) return;
        if ( ! is_admin() ) return;
        if ( ! current_user_can( 'create_users' ) ) return;

        $limit = self::get_user_limit();
        if ( $limit <= 0 ) return;

        // 如果這次要建立的就是 administrator，就不計入限制，也不擋（因為你規則是排除管理員）
        $new_roles = [];
        if ( isset( $_POST['role'] ) ) {
            $new_roles[] = sanitize_text_field( wp_unslash( $_POST['role'] ) );
        }
        if ( in_array( 'administrator', $new_roles, true ) ) {
            return;
        }

        $total = self::count_users_excluding_admins();

        if ( $total >= $limit ) {
            $errors->add(
                'woc_user_limit_reached',
                sprintf(
                    '使用者上限已達（上限：%d，目前：%d，排除管理員）。請先刪除不需要的使用者。',
                    (int) $limit,
                    (int) $total
                )
            );
        }
    }

    /**
     * 前台註冊攔截（有開放註冊時）
     */
    public static function maybe_block_user_registration( $errors, $sanitized_user_login, $user_email ) {

        $limit = self::get_user_limit();
        if ( $limit <= 0 ) return $errors;

        // 前台註冊通常不會是 administrator，但保守起見仍照同一規則計數
        $total = self::count_users_excluding_admins();

        if ( $total >= $limit ) {
            $errors->add(
                'woc_user_limit_reached',
                sprintf(
                    '已達使用者上限（上限：%d，目前：%d，排除管理員）。目前暫停註冊。',
                    (int) $limit,
                    (int) $total
                )
            );
        }

        return $errors;
    }

    /**
     * UI 層攔截：進新增頁就擋
     */
    public static function maybe_block_post_new() {
        if ( ! is_admin() ) return;
        if ( ! class_exists( 'WOC_Contracts_CPT' ) ) return;

        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';

        $contract_pt = (string) WOC_Contracts_CPT::POST_TYPE_CONTRACT;
        $template_pt = (string) WOC_Contracts_CPT::POST_TYPE_TEMPLATE;

        if ( $post_type !== $contract_pt && $post_type !== $template_pt ) {
            return;
        }

        $limit = self::get_limit_for_post_type( $post_type );
        if ( $limit <= 0 ) return;

        $total = self::count_total_including_trash( $post_type );

        if ( $total >= $limit ) {
            $list_url = admin_url( 'edit.php?post_type=' . $post_type );
            $title    = ( $post_type === $contract_pt ) ? '合約上限已達' : '範本上限已達';

            $msg = sprintf(
                '%s<br><br>上限：%d<br>目前數量：%d（含回收桶）<br><br>請刪除不需要的內容並清空回收桶後再新增。<br><br><a href="%s">回到列表</a>',
                esc_html( $title ),
                (int) $limit,
                (int) $total,
                esc_url( $list_url )
            );

            wp_die( $msg, esc_html( $title ), [ 'response' => 403 ] );
        }
    }

    /**
     * 寫入層攔截：送出新增也擋（防繞過）
     */
    public static function maybe_block_post_insert( $data, $postarr ) {
        if ( ! is_admin() ) return $data;
        if ( ! class_exists( 'WOC_Contracts_CPT' ) ) return $data;

        // 只擋新增，不擋更新
        $id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
        if ( $id > 0 ) return $data;

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return $data;

        $post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';

        $contract_pt = (string) WOC_Contracts_CPT::POST_TYPE_CONTRACT;
        $template_pt = (string) WOC_Contracts_CPT::POST_TYPE_TEMPLATE;

        if ( $post_type !== $contract_pt && $post_type !== $template_pt ) {
            return $data;
        }

        $limit = self::get_limit_for_post_type( $post_type );
        if ( $limit <= 0 ) return $data;

        $total = self::count_total_including_trash( $post_type );

        if ( $total >= $limit ) {
            $list_url = admin_url( 'edit.php?post_type=' . $post_type );
            $title    = ( $post_type === $contract_pt ) ? '合約上限已達' : '範本上限已達';

            $msg = sprintf(
                '%s<br><br>上限：%d<br>目前數量：%d（含回收桶）<br><br>請刪除不需要的內容並清空回收桶後再新增。<br><br><a href="%s">回到列表</a>',
                esc_html( $title ),
                (int) $limit,
                (int) $total,
                esc_url( $list_url )
            );

            wp_die( $msg, esc_html( $title ), [ 'response' => 403 ] );
        }

        return $data;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $contract_limit = (int) get_option( self::OPT_CONTRACT_LIMIT, 0 );
        $template_limit = (int) get_option( self::OPT_TEMPLATE_LIMIT, 0 );
        $user_limit     = (int) get_option( self::OPT_USER_LIMIT, 0 );

        $contract_total = 0;
        $template_total = 0;

        if ( class_exists( 'WOC_Contracts_CPT' ) ) {
            $contract_total = self::count_total_including_trash( WOC_Contracts_CPT::POST_TYPE_CONTRACT );
            $template_total = self::count_total_including_trash( WOC_Contracts_CPT::POST_TYPE_TEMPLATE );
        }

        // 使用者目前數量（排除管理員）
        $user_total = self::count_users_excluding_admins();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( '設定', 'woc-contracts' ) . '</h1>';
        settings_errors( 'woc_contracts_settings' );

        echo '<form method="post" action="options.php">';
        settings_fields( self::SETTINGS_GROUP );

        echo '<table class="form-table" role="presentation">';

        // 合約上限
        echo '<tr>';
        echo '<th scope="row">';
        echo esc_html__( '合約上限', 'woc-contracts' ) . ' ';
        echo '<span class="dashicons dashicons-editor-help" title="0 = 不限制。計數規則：Total = 全部(不含回收桶) + 回收桶；排除 auto-draft。"></span>';
        echo '</th>';
        echo '<td>';
        echo '<input type="number" min="0" step="1" name="' . esc_attr( self::OPT_CONTRACT_LIMIT ) . '" value="' . esc_attr( $contract_limit ) . '" class="small-text" />';
        echo '<p class="description">' . sprintf( '目前數量：%d（含回收桶）', (int) $contract_total ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // 範本上限
        echo '<tr>';
        echo '<th scope="row">';
        echo esc_html__( '範本上限', 'woc-contracts' ) . ' ';
        echo '<span class="dashicons dashicons-editor-help" title="0 = 不限制。計數規則同上（含回收桶；排除 auto-draft）。"></span>';
        echo '</th>';
        echo '<td>';
        echo '<input type="number" min="0" step="1" name="' . esc_attr( self::OPT_TEMPLATE_LIMIT ) . '" value="' . esc_attr( $template_limit ) . '" class="small-text" />';
        echo '<p class="description">' . sprintf( '目前數量：%d（含回收桶）', (int) $template_total ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // 使用者上限
        echo '<tr>';
        echo '<th scope="row">';
        echo esc_html__( '使用者上限', 'woc-contracts' ) . ' ';
        echo '<span class="dashicons dashicons-editor-help" title="0 = 不限制。計數規則：排除 administrator / super admin。"></span>';
        echo '</th>';
        echo '<td>';
        echo '<input type="number" min="0" step="1" name="' . esc_attr( self::OPT_USER_LIMIT ) . '" value="' . esc_attr( $user_limit ) . '" class="small-text" />';
        echo '<p class="description">' . sprintf( '目前數量：%d（排除管理員）', (int) $user_total ) . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        submit_button( '儲存設定' );

        echo '</form>';
        echo '</div>';
    }

}
