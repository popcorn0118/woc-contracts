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
    const FIXED_VAR_YEAR  = 'current_year';
    const FIXED_VAR_MONTH = 'current_month';
    const FIXED_VAR_DAY   = 'current_day';


    /**
     * 掛 hook
     */
    public static function init() {
        // CPT 一定在 init 早期就註冊
        add_action( 'init',       [ __CLASS__, 'register_post_types' ], 0 );
        // 等 CPT 註冊好之後再掛選單
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menus' ] );

        //只跑一次塞 caps 給管理員
        add_action( 'init', [ __CLASS__, 'add_template_caps_once' ], 20 );
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
                // 'show_in_rest'  => true,   // ✅ 開 Gutenberg
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
                // 'show_in_rest'  => true,   // ✅ 開 Gutenberg
                'public'          => false,
                'show_ui'         => true,
                'show_in_menu'    => false,
                'supports'        => [ 'title', 'editor' ],
                'has_archive'     => false,
                'rewrite'         => false,
            
                'capability_type' => [ 'woc_template', 'woc_templates' ],
                'map_meta_cap'    => true,
                'capabilities'    => [
                  'create_posts' => 'edit_woc_templates',
                ],
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
            'edit_woc_templates',         // 權限
            $submenu_slug         // 導向的頁面 slug
        );

        add_submenu_page(
            $parent_slug,
            '合約變數',          // 頁面標題
            '合約變數',          // 選單文字
            'manage_options',    // 權限
            'woc-contract-vars', // slug
            [ __CLASS__, 'render_vars_page' ]
        );
    }
    

    /**
     * 合約變數設定頁
     * - 儲存在 options: woc_contract_global_vars
     * - 變數代碼用 sanitize_key() 正規化
     *   資料結構：
     *   [
     *     'company_name' => [ 'label' => '公司名稱', 'value' => 'xxx有限公司' ],
     *     ...
     *   ]
     */
    public static function render_vars_page() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '權限不足。' );
        }

        $errors = [];
        $rows   = [];

        // 儲存
        if (
            isset( $_POST['woc_vars_nonce'] ) &&
            wp_verify_nonce( $_POST['woc_vars_nonce'], 'woc_save_vars' )
        ) {

            $keys    = isset( $_POST['woc_var_key'] )    ? (array) $_POST['woc_var_key']    : [];
            $labels  = isset( $_POST['woc_var_label'] )  ? (array) $_POST['woc_var_label']  : [];
            $values  = isset( $_POST['woc_var_value'] )  ? (array) $_POST['woc_var_value']  : [];
            $deletes = isset( $_POST['woc_var_delete'] ) ? (array) $_POST['woc_var_delete'] : [];

            $vars = [];

            $max = max( count( $keys ), count( $labels ), count( $values ), count( $deletes ) );

            for ( $i = 0; $i < $max; $i++ ) {

                $raw_key = isset( $keys[ $i ] ) ? trim( wp_unslash( $keys[ $i ] ) ) : '';
                $label   = isset( $labels[ $i ] ) ? wp_unslash( $labels[ $i ] ) : '';
                $value   = isset( $values[ $i ] ) ? wp_unslash( $values[ $i ] ) : '';
                $delete  = ! empty( $deletes[ $i ] );

                // 回填用（就算有錯也不要把列弄丟）
                $rows[] = [
                    'key'    => $raw_key,
                    'label'  => $label,
                    'value'  => $value,
                    'delete' => $delete ? '1' : '0',
                ];

                // 刪除的列：不驗證、不存
                if ( $delete ) {
                    continue;
                }

                $label_trim = trim( (string) $label );
                $value_trim = trim( (string) $value );

                // 整列都空：視為空白模板列，允許存在、不擋存、也不寫入
                if ( $raw_key === '' && $label_trim === '' && $value_trim === '' ) {
                    continue;
                }

                // 代碼必填：只要這列有填任何東西但代碼空，就擋存
                if ( $raw_key === '' ) {
                    $errors[] = '儲存失敗，第 ' . ( $i + 1 ) . ' 列：變數代碼必填。';
                    continue;
                }

                $key = sanitize_key( $raw_key );
                if ( $key === '' ) {
                    $errors[] = '第 ' . ( $i + 1 ) . ' 列：變數代碼格式不正確（只能英文/數字/底線）。';
                    continue;
                }

                // 禁止覆蓋固定系統變數
                if ( in_array( $key, self::get_reserved_var_keys(), true ) ) {
                    $errors[] = '第 ' . ( $i + 1 ) . ' 列：' . $key . ' 是固定系統變數，不能使用。';
                    continue;
                }

                $vars[ $key ] = [
                    'label' => sanitize_text_field( $label ),
                    'value' => wp_kses_post( $value ),
                ];
            }

            if ( empty( $errors ) ) {
                update_option( 'woc_contract_global_vars', $vars );
                echo '<div class="updated"><p>已儲存合約變數。</p></div>';

                // 成功後用 DB 結果重建 rows（避免殘留 delete=1）
                $rows = [];
                foreach ( $vars as $k => $row ) {
                    $rows[] = [
                        'key'    => $k,
                        'label'  => $row['label'] ?? '',
                        'value'  => $row['value'] ?? '',
                        'delete' => '0',
                    ];
                }
            } else {
                echo '<div class="notice notice-error"><p>' . implode( '<br>', array_map( 'esc_html', $errors ) ) . '</p></div>';
                // 有錯就用 $rows 回填（不要再從 DB 抓，不然你那列又會「消失」）
            }
        }

        // 沒有 POST（第一次載入）才從 DB 讀
        if ( empty( $rows ) ) {

            $vars = get_option( 'woc_contract_global_vars', [] );
            if ( ! is_array( $vars ) ) {
                $vars = [];
            }

            foreach ( $vars as $key => $row ) {

                if ( is_array( $row ) ) {
                    $label = isset( $row['label'] ) ? $row['label'] : '';
                    $val   = isset( $row['value'] ) ? $row['value'] : '';
                } else {
                    $label = '';
                    $val   = $row;
                }

                $rows[] = [
                    'key'    => (string) $key,
                    'label'  => (string) $label,
                    'value'  => (string) $val,
                    'delete' => '0',
                ];
            }

            if ( empty( $rows ) ) {
                $rows[] = [ 'key' => '', 'label' => '', 'value' => '', 'delete' => '0' ];
            }
        }

        $fixed_vars = self::get_fixed_vars();

        ?>
        <div class="wrap">
            <h1>合約變數</h1>

            <p>
                這裡設定的是「全站共用」的合約變數，例如公司名稱、統編、地址、電話等。<br>
                在合約範本或合約內容中，可用 <code>{變數代碼}</code> 插入，例如：
                <code>{company_name}</code>、<code>{company_address}</code>。
            </p>

            <h2 style="margin-top:20px;">固定系統變數（不可編輯）</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:20%;">識別文字</th>
                        <th style="width:25%;">變數代碼</th>
                        <th>目前值（依網站時區）</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $fixed_vars as $k => $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['label'] ); ?></td>
                            <td><code>{<?php echo esc_html( $k ); ?>}</code></td>
                            <td><?php echo esc_html( $row['value'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post">
                <?php wp_nonce_field( 'woc_save_vars', 'woc_vars_nonce' ); ?>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 20%;">識別文字</th>
                            <th style="width: 25%;">變數代碼（英文/數字/底線）</th>
                            <th>內容</th>
                            <th style="width: 80px; text-align:center;">刪除</th>
                        </tr>
                    </thead>
                    <tbody id="woc-vars-rows">
                        <?php foreach ( $rows as $row ) :

                            $key    = isset( $row['key'] ) ? (string) $row['key'] : '';
                            $label  = isset( $row['label'] ) ? (string) $row['label'] : '';
                            $val    = isset( $row['value'] ) ? (string) $row['value'] : '';
                            $delete = ! empty( $row['delete'] );

                            $tr_style = $delete ? 'display:none;' : '';
                            ?>
                            <tr style="<?php echo esc_attr( $tr_style ); ?>">
                                <td>
                                    <input type="text"
                                        name="woc_var_label[]"
                                        value="<?php echo esc_attr( $label ); ?>"
                                        class="regular-text"
                                        placeholder="公司名稱、公司地址…">
                                </td>
                                <td>
                                    <input type="text"
                                        name="woc_var_key[]"
                                        value="<?php echo esc_attr( $key ); ?>"
                                        class="regular-text"
                                        placeholder="例如 company_name">
                                </td>
                                <td>
                                    <textarea name="woc_var_value[]"
                                            rows="2"
                                            class="large-text"
                                            placeholder="例如 xx有限公司"><?php
                                        echo esc_textarea( $val );
                                    ?></textarea>
                                </td>
                                <td style="text-align:center;">
                                    <input type="hidden" name="woc_var_delete[]" value="<?php echo $delete ? '1' : '0'; ?>">
                                    <button type="button" class="button woc-var-delete">刪除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- 空白模板列，之後 JS 會 clone -->
                        <tr class="woc-vars-empty-row" style="display:none;">
                            <td>
                                <input type="text"
                                    name="woc_var_label[]"
                                    value=""
                                    class="regular-text"
                                    placeholder="公司名稱、公司地址…"
                                    disabled>
                            </td>
                            <td>
                                <input type="text"
                                    name="woc_var_key[]"
                                    value=""
                                    class="regular-text"
                                    placeholder="例如 company_office"
                                    disabled>
                            </td>
                            <td>
                                <textarea name="woc_var_value[]"
                                        rows="2"
                                        class="large-text"
                                        disabled></textarea>
                            </td>
                            <td style="text-align:center;">
                                <input type="hidden" name="woc_var_delete[]" value="0" disabled>
                                <button type="button" class="button woc-var-delete" disabled>刪除</button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="woc-add-var-row">新增一行</button>
                </p>

                <p>
                    <button type="submit" class="button button-primary">儲存變數</button>
                </p>
            </form>
        </div>

        <script>
        (function($){
            $('#woc-add-var-row').on('click', function(e){
                e.preventDefault();
                var $tmpl  = $('.woc-vars-empty-row').first();
                var $clone = $tmpl.clone();
                $clone.removeClass('woc-vars-empty-row').show();
                $clone.find('input, textarea, button').prop('disabled', false);
                $clone.find('input[type="text"], textarea').val('');
                $clone.find('input[name="woc_var_delete[]"]').val('0');
                $('#woc-vars-rows').append($clone);
            });

            $(document).on('click', '.woc-var-delete', function(e){
                e.preventDefault();
                var $tr = $(this).closest('tr');
                $tr.find('input[name="woc_var_delete[]"]').val('1');
                $tr.hide();
            });
        })(jQuery);
        </script>
        <?php
    }



    /**
     * 固定變數定義（label + value）
     */
    public static function get_fixed_vars() {
        $ts = current_time( 'timestamp' );

        return [
            self::FIXED_VAR_YEAR => [
                'label' => '當前年分',
                'value' => date_i18n( 'Y', $ts ),
            ],
            self::FIXED_VAR_MONTH => [
                'label' => '當前月份',
                'value' => date_i18n( 'm', $ts ),
            ],
            self::FIXED_VAR_DAY => [
                'label' => '當前日',
                'value' => date_i18n( 'd', $ts ),
            ],
        ];
    }

    /**
     * 固定變數保留碼清單
     */
    public static function get_reserved_var_keys() {
        return [ self::FIXED_VAR_YEAR, self::FIXED_VAR_MONTH, self::FIXED_VAR_DAY ];
    }

    /**
     * 管理員權限
     */
    public static function add_template_caps_once() {
        if ( get_option('woc_template_caps_added') ) return;
      
        $role = get_role('administrator');
        if ( ! $role ) return;
      
        $caps = [
          'edit_woc_template',
          'read_woc_template',
          'delete_woc_template',
          'edit_woc_templates',
          'edit_others_woc_templates',
          'publish_woc_templates',
          'read_private_woc_templates',
          'delete_woc_templates',
          'delete_private_woc_templates',
          'delete_published_woc_templates',
          'delete_others_woc_templates',
          'edit_private_woc_templates',
          'edit_published_woc_templates',
        ];
      
        foreach ($caps as $cap) $role->add_cap($cap);
      
        update_option('woc_template_caps_added', 1);
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
