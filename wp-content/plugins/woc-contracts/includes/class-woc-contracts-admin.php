<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_Admin {

    /**
     * 掛 hook
     */
    public static function init() {
        add_action( 'add_meta_boxes',        [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post',             [ __CLASS__, 'save_contract_meta' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
        add_filter( 'mce_css', [ __CLASS__, 'filter_mce_css' ] );
        add_action( 'wp_ajax_woc_load_template', [ __CLASS__, 'ajax_load_template' ] );

        // 後台 UI 相關 filter
        add_filter( 'get_sample_permalink_html', [ __CLASS__, 'filter_sample_permalink_html' ], 10, 5 );
        add_filter( 'post_row_actions',          [ __CLASS__, 'filter_post_row_actions' ], 10, 2 );

        // 後台列表欄位
        add_filter(
            'manage_edit-' . WOC_Contracts_CPT::POST_TYPE_CONTRACT . '_columns',
            [ __CLASS__, 'filter_contract_columns' ]
        );
        add_action(
            'manage_' . WOC_Contracts_CPT::POST_TYPE_CONTRACT . '_posts_custom_column',
            [ __CLASS__, 'render_contract_column' ],
            10,
            2
        );

        // 已簽署合約鎖定邏輯
        add_filter( 'wp_insert_post_data', [ __CLASS__, 'lock_signed_contract_post_data' ], 10, 2 );
        add_action( 'admin_notices',       [ __CLASS__, 'show_signed_lock_notice' ] );

        //後台紀錄 操作紀錄
        add_action( 'transition_post_status', [ __CLASS__, 'log_contract_created' ], 10, 3 );

        // 發布區塊下方顯示可用變數
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_vars_helper_meta_box' ] );

    }

    /**
     * 在「線上合約」編輯畫面加 Meta Box
     */
    public static function add_meta_boxes( $post_type ) {
        if ( $post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return;
        }

        // 合約明細（範本選擇）
        add_meta_box(
            'woc_contract_details',
            '合約明細',
            [ __CLASS__, 'render_contract_meta_box' ],
            WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            'side',
            'high'
        );

        // 簽署資訊（顯示簽名、時間、IP、清除按鈕）
        add_meta_box(
            'woc_contract_signature',
            '簽署資訊',
            [ __CLASS__, 'render_signature_meta_box' ],
            WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            'normal',
            'default'
        );

        // 簽署連結
        add_meta_box(
            'woc_contract_link',
            '簽署連結',
            [ __CLASS__, 'render_contract_link_box' ],
            WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            'side',
            'high'
        );

        // 操作紀錄
        add_meta_box(
            'woc-contract-audit-log',
            __( '操作紀錄', 'woc-contracts' ),
            [ __CLASS__, 'render_audit_log_meta_box' ],
            WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            'normal',
            'default'
        );

        
    }

    /**
     * 顯示簽署連結（後台側邊欄）
     */
    public static function render_contract_link_box( $post ) {

        $token = get_post_meta( $post->ID, WOC_Contracts_CPT::META_VIEW_TOKEN, true );

        // 還沒產 token？提示先儲存
        if ( empty( $token ) ) {
            echo '<p>請先儲存一次合約，系統會自動產生專屬簽署連結。</p>';
            return;
        }

        // 前台簽署網址
        $url = add_query_arg(
            [ 't' => $token ],
            get_permalink( $post )
        );
        ?>
        <p>將此連結傳給客戶，即可線上檢視並簽署此合約。</p>
        <p>
            <input type="text"
                readonly
                class="widefat"
                value="<?php echo esc_attr( $url ); ?>"
                onclick="this.select();">
        </p>
        <p>
            <button type="button"
                    class="button button-secondary"
                    id="woc-copy-link-btn"
                    data-link="<?php echo esc_attr( $url ); ?>">
                複製連結
            </button>
            <a href="<?php echo esc_url( $url ); ?>" class="button" target="_blank">
                開啟簽署頁面
            </a>
        </p>
        <?php
    }

    /**
     * Meta Box HTML：合約範本選擇 + 載入按鈕
     */

    public static function render_contract_meta_box( $post ) {

        // 目前已選範本
        $current_template_id = get_post_meta( $post->ID, WOC_Contracts_CPT::META_TEMPLATE_ID, true );
        $status              = get_post_meta( $post->ID, WOC_Contracts_CPT::META_STATUS, true );

        // 取得所有已發布的範本（只有未簽署時才會用到）
        $templates = get_posts( [
            'post_type'      => WOC_Contracts_CPT::POST_TYPE_TEMPLATE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        wp_nonce_field( 'woc_contract_meta', 'woc_contract_meta_nonce' );

        // === 若已簽署：只顯示範本名稱，不能再換 ===
        if ( $status === 'signed' ) : 
            ?>
            <p>
                <strong>合約範本：</strong>
                <?php
                if ( $current_template_id ) {
                    echo esc_html( get_the_title( $current_template_id ) );
                } else {
                    echo '—';
                }
                ?>
            </p>
            <p class="description">
                此合約已完成簽署，範本資訊僅供檢視，無法再變更。
                若需更換範本，請先在下方「簽署資訊」清除簽名並重新開放簽署。
            </p>
            <?php
            return;
        endif;
        ?>

        <!-- 未簽署狀態：顯示原本的下拉 + 按鈕 -->
        <p>
            <label for="woc_template_id"><strong>合約範本</strong></label><br>
            <select name="woc_template_id" id="woc_template_id" style="min-width:260px;">
                <option value="">— 請選擇範本 —</option>
                <?php foreach ( $templates as $template ) : ?>
                    <option value="<?php echo esc_attr( $template->ID ); ?>"
                        <?php selected( (int) $current_template_id, (int) $template->ID ); ?>>
                        <?php echo esc_html( $template->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="button"
                    style="margin-top: 10px"
                    class="button button-secondary"
                    id="woc-load-template-btn"
                    data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'woc_load_template' ) ); ?>">
                載入範本內容
            </button>
        </p>

        <p class="description">
            選擇合約範本後，按「載入範本內容」，系統會將範本內容覆蓋到目前合約的內容欄位。
        </p>
        <?php
    }


    /**
     * 簽署資訊 Meta Box
     */
    public static function render_signature_meta_box( $post ) {

        $status    = get_post_meta( $post->ID, WOC_Contracts_CPT::META_STATUS, true );
        $img_url   = get_post_meta( $post->ID, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );
        $signed_at = get_post_meta( $post->ID, WOC_Contracts_CPT::META_SIGNED_AT, true );
        $signed_ip = get_post_meta( $post->ID, WOC_Contracts_CPT::META_SIGNED_IP, true );

        if ( $status !== 'signed' ) {
            echo '<p>目前尚未簽署。</p>';
            return;
        }

        wp_nonce_field( 'woc_remove_signature', 'woc_remove_signature_nonce' );
        ?>

        <p><strong>簽名：</strong></p>

        <?php if ( ! empty( $img_url ) ) : ?>
            <p>
                <img src="<?php echo esc_url( $img_url ); ?>"
                     alt="Signature"
                     style="max-width:100%; height:auto; border:1px solid #ccc; background:#fff;">
            </p>
        <?php else : ?>
            <p>（找不到簽名圖片，但合約狀態為已簽署。）</p>
        <?php endif; ?>

        <p>已簽約時間：<?php echo esc_html( $signed_at ); ?></p>
        <p>簽署 IP：<?php echo esc_html( $signed_ip ); ?></p>

        <p class="description">
            此合約已完成簽署，內容僅供檢視與列印。若需讓客戶重新簽署，可清除簽名並產生新的簽署連結。
        </p>

        <p>
            <button type="submit"
                    name="woc_remove_signature"
                    value="1"
                    class="button button-secondary"
                    onclick="return confirm('確定要清除簽名並重新開放此合約簽署？');">
                清除簽名並重新開放簽署
            </button>
        </p>

        <?php
    }

    /**
     * 儲存合約的 meta
     */
    public static function save_contract_meta( $post_id, $post ) {

        // 只處理我們的 CPT
        if ( $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return;
        }

        // 自動儲存 / 無權限略過
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // nonce（合約明細）
        if ( ! isset( $_POST['woc_contract_meta_nonce'] ) ||
             ! wp_verify_nonce( $_POST['woc_contract_meta_nonce'], 'woc_contract_meta' ) ) {
            return;
        }

        // --- 情境 A：清除簽名，重新開放簽署 --------------------------
        if (
            isset( $_POST['woc_remove_signature'] ) &&
            isset( $_POST['woc_remove_signature_nonce'] ) &&
            wp_verify_nonce( $_POST['woc_remove_signature_nonce'], 'woc_remove_signature' )
        ) {
            // 清除簽署相關 meta
            delete_post_meta( $post_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE );
            delete_post_meta( $post_id, WOC_Contracts_CPT::META_SIGNED_AT );
            delete_post_meta( $post_id, WOC_Contracts_CPT::META_SIGNED_IP );
            update_post_meta( $post_id, WOC_Contracts_CPT::META_STATUS, 'draft' );

            // 重新產生 token，讓舊連結失效
            $new_token = wp_generate_password( 32, false, false );
            update_post_meta( $post_id, WOC_Contracts_CPT::META_VIEW_TOKEN, $new_token );

            // === 寫入「誰清除簽名」的操作紀錄 ===
            $user = wp_get_current_user();
            $name = ( $user && $user->ID ) ? $user->user_login : 'unknown';

            WOC_Contracts_CPT::add_audit_log(
                $post_id,
                '簽名已由 ' . $name . ' 清除，合約重新開放簽署'
            );

            return;
        }

        // --- 情境 B：一般儲存（尚未簽署或簽署前的調整）--------------

        // 若已簽署，不再允許修改任何 meta（維持鎖定狀態）
        $status = get_post_meta( $post_id, WOC_Contracts_CPT::META_STATUS, true );
        if ( $status === 'signed' ) {
            return;
        }

        // 1. 儲存範本 ID（允許空值）
        $template_id = isset( $_POST['woc_template_id'] ) ? (int) $_POST['woc_template_id'] : 0;

        if ( $template_id > 0 ) {
            update_post_meta( $post_id, WOC_Contracts_CPT::META_TEMPLATE_ID, $template_id );
        } else {
            delete_post_meta( $post_id, WOC_Contracts_CPT::META_TEMPLATE_ID );
        }

        // 2. 若尚未有 view_token，產一組
        $existing_token = get_post_meta( $post_id, WOC_Contracts_CPT::META_VIEW_TOKEN, true );

        if ( empty( $existing_token ) ) {
            $token = wp_generate_password( 32, false, false );
            update_post_meta( $post_id, WOC_Contracts_CPT::META_VIEW_TOKEN, $token );
        }
    }

    /**
     * 後台載入 JS、CSS
     */
    public static function enqueue_admin_assets( $hook_suffix ) {
        global $post;
    
        // 只在「線上合約」編輯畫面
        // if ( ! isset( $post ) || $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
        //     return;
        // }
    
        // 狀態：是否已簽署
        $status   = get_post_meta( $post->ID, WOC_Contracts_CPT::META_STATUS, true );
        $is_signed = ( $status === 'signed' );
    
        // JS
        wp_enqueue_script(
            'woc-contracts-admin',
            WOC_CONTRACTS_URL . 'assets/js/woc-contracts-admin.js',
            [ 'jquery' ],
            WOC_CONTRACTS_VERSION,
            true
        );
    
        // 把狀態丟給 JS 用
        wp_localize_script(
            'woc-contracts-admin',
            'wocContractsAdmin',
            [
                'is_signed' => $is_signed,
            ]
        );
    
        // 專用後台樣式
        wp_enqueue_style(
            'woc-contracts-admin',
            WOC_CONTRACTS_URL . 'assets/css/woc-contracts-admin.css',
            [],
            WOC_CONTRACTS_VERSION
        );
    }

    /**
     * TinyMCE iframe 內載入 editor CSS（讓編輯器內容吃得到）
     */
    public static function filter_mce_css( $mce_css ) {

        // 只針對「合約 / 範本」編輯頁
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'post' ) {
            return $mce_css;
        }

        $allowed = [
            WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            WOC_Contracts_CPT::POST_TYPE_TEMPLATE,
        ];

        if ( empty( $screen->post_type ) || ! in_array( $screen->post_type, $allowed, true ) ) {
            return $mce_css;
        }

        $css = WOC_CONTRACTS_URL . 'assets/css/woc-editor.css';

        if ( ! empty( $mce_css ) ) {
            $mce_css .= ',';
        }

        return $mce_css . $css;
    }

    

    /**
     * AJAX：讀取範本內容
     */
    public static function ajax_load_template() {

        check_ajax_referer( 'woc_load_template', 'nonce' );

        $template_id = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
        $post_id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

        // 多檢查一次這個 post 是不是合約 CPT
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            wp_send_json_error( [ 'message' => '合約不存在。' ] );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => '權限不足。' ] );
        }

        if ( ! $template_id ) {
            wp_send_json_error( [ 'message' => '未選擇範本。' ] );
        }

        $template = get_post( $template_id );

        if ( ! $template || $template->post_type !== WOC_Contracts_CPT::POST_TYPE_TEMPLATE ) {
            wp_send_json_error( [ 'message' => '範本不存在。' ] );
        }

        $content = $template->post_content;

        // 用全站合約變數做一次替換
        $content = woc_replace_contract_vars( $content );

        wp_send_json_success( [
            'content' => $content,
        ] );
    }

    /**
     * 處理永久連結區塊（線上合約不顯示預設 permalink）
     */
    public static function filter_sample_permalink_html( $html, $post_id, $new_title, $new_slug, $post ) {

        if ( $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return $html;
        }

        return '';
    }

    /**
     * 列表「檢視」改成簽署連結
     */
    public static function filter_post_row_actions( $actions, $post ) {

        if ( $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return $actions;
        }

        $token = get_post_meta( $post->ID, WOC_Contracts_CPT::META_VIEW_TOKEN, true );

        if ( ! empty( $token ) ) {
            $url = add_query_arg( 't', $token, get_permalink( $post ) );

            $actions['view'] = sprintf(
                '<a href="%s" target="_blank">%s</a>',
                esc_url( $url ),
                '檢視（簽署連結）'
            );
        } else {
            unset( $actions['view'] );
        }

        return $actions;
    }

    /**
     * 後台列表欄位：插入「合約範本」「是否簽署」
     */
    public static function filter_contract_columns( $columns ) {

        $new = [];

        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;

            if ( 'title' === $key ) {
                $new['woc_template'] = '合約範本';
                $new['woc_signed']   = '是否簽署';
            }
        }

        return $new;
    }

    /**
     * 後台列表欄位內容
     */
    public static function render_contract_column( $column, $post_id ) {

        switch ( $column ) {

            case 'woc_template':
                $template_id = (int) get_post_meta( $post_id, WOC_Contracts_CPT::META_TEMPLATE_ID, true );
                if ( $template_id ) {
                    echo esc_html( get_the_title( $template_id ) );
                } else {
                    echo '—';
                }
                break;

            case 'woc_signed':
                $status = get_post_meta( $post_id, WOC_Contracts_CPT::META_STATUS, true );
                if ( $status === 'signed' ) {
                    echo '<span class="signed">已簽約</span>';
                } else {
                    echo '<span class="unsigned">未簽約</span>';
                }
                break;
        }
    }

    /**
     * 已簽署合約：鎖定內容，阻止被修改
     */
    public static function lock_signed_contract_post_data( $data, $postarr ) {

        if ( ! isset( $data['post_type'] ) || $data['post_type'] !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return $data;
        }

        $post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
        if ( ! $post_id ) {
            return $data;
        }

        $status    = get_post_meta( $post_id, WOC_Contracts_CPT::META_STATUS, true );
        $signature = get_post_meta( $post_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );

        if ( $status !== 'signed' || empty( $signature ) ) {
            return $data;
        }

        $current = get_post( $post_id );
        if ( $current && $current->post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            $data['post_title']   = $current->post_title;
            $data['post_name']    = $current->post_name;
            $data['post_content'] = $current->post_content;
            $data['post_excerpt'] = $current->post_excerpt;
        }

        return $data;
    }

    /**
     * 已簽署合約：在編輯畫面顯示提示訊息
     */
    public static function show_signed_lock_notice() {
        global $pagenow, $post;

        if ( $pagenow !== 'post.php' || ! $post || $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return;
        }

        $status    = get_post_meta( $post->ID, WOC_Contracts_CPT::META_STATUS, true );
        $signature = get_post_meta( $post->ID, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );

        if ( $status !== 'signed' || empty( $signature ) ) {
            return;
        }
        ?>
        <div class="notice notice-info">
            <p>
                此合約已完成線上簽署，內容已鎖定。
                若需修改內容，請先在「簽署資訊」區塊按下「清除簽名並重新開放簽署」。
            </p>
        </div>
        <?php
    }

    public static function log_contract_created( $new_status, $old_status, $post ) {
        if ( $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return;
        }
    
        // 只在第一次從「非 publish」→「publish」時記錄
        if ( $old_status === 'publish' || $new_status !== 'publish' ) {
            return;
        }
    
        // 避免重複記錄：看有沒有已經存在「創建」這種開頭
        $logs = get_post_meta( $post->ID, WOC_Contracts_CPT::META_AUDIT_LOG, true );
        if ( is_array( $logs ) ) {
            foreach ( $logs as $row ) {
                if ( ! empty( $row['message'] ) && strpos( $row['message'], '合約創建人' ) === 0 ) {
                    return;
                }
            }
        }
    
        $user = wp_get_current_user();
        $name = $user && $user->ID ? $user->user_login : 'unknown';
    
        WOC_Contracts_CPT::add_audit_log(
            $post->ID,
            '合約創建人 ' . $name
        );
    }

    /**
     * 操作紀錄：顯示合約操作紀錄
     */
    public static function render_audit_log_meta_box( $post ) {

        // 取出 log 陣列
        $logs = get_post_meta( $post->ID, WOC_Contracts_CPT::META_AUDIT_LOG, true );

        if ( ! is_array( $logs ) || empty( $logs ) ) {
            echo '<p>目前尚無紀錄。</p>';
            return;
        }

        // 新的在上面看起來比較直覺
        $logs = array_reverse( $logs );
        ?>
        <div class="woc-audit-log-wrap">
            <ul class="woc-audit-log-list">
                <?php foreach ( $logs as $row ) :

                    $raw_time = isset( $row['time'] ) ? $row['time'] : '';
                    $message  = isset( $row['message'] ) ? $row['message'] : '';

                    if ( $raw_time ) {
                        $ts = strtotime( $raw_time );
                        $time_str = $ts ? date_i18n( 'Y-m-d H:i:s', $ts ) : $raw_time;
                    } else {
                        $time_str = '';
                    }
                    ?>
                    <li>
                        <span class="woc-log-time"><?php echo esc_html( $time_str ); ?></span>
                        <?php if ( $message !== '' ) : ?>
                            ，<span class="woc-log-message"><?php echo esc_html( $message ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * 合約變數
     */
    public static function render_vars_page() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
    
        // 儲存
        if ( isset( $_POST['woc_vars_nonce'] )
             && wp_verify_nonce( $_POST['woc_vars_nonce'], 'woc_save_vars' ) ) {
    
            $keys   = isset( $_POST['woc_vars']['key'] )   ? (array) $_POST['woc_vars']['key']   : [];
            $labels = isset( $_POST['woc_vars']['label'] ) ? (array) $_POST['woc_vars']['label'] : [];
            $values = isset( $_POST['woc_vars']['value'] ) ? (array) $_POST['woc_vars']['value'] : [];
    
            $new = [];
    
            foreach ( $keys as $i => $raw_key ) {
                $key = sanitize_key( $raw_key ); // 只允許 a-z0-9_，自動轉小寫
    
                if ( $key === '' ) {
                    continue;
                }
    
                $label = isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '';
                $value = isset( $values[ $i ] ) ? wp_kses_post( $values[ $i ] ) : '';
    
                $new[ $key ] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
    
            update_option( 'woc_contract_global_vars', $new );
    
            echo '<div class="updated"><p>已儲存變數。</p></div>';
        }
    
        $vars = get_option( 'woc_contract_global_vars', [] );
        if ( ! is_array( $vars ) ) {
            $vars = [];
        }
    
        ?>
        <div class="wrap">
            <h1>合約變數</h1>
    
            <form method="post">
                <?php wp_nonce_field( 'woc_save_vars', 'woc_vars_nonce' ); ?>
    
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th style="width:20%">變數代碼（{code}）</th>
                        <th style="width:20%">顯示名稱</th>
                        <th>值</th>
                    </tr>
                    </thead>
                    <tbody id="woc-vars-rows">
                    <?php if ( $vars ) : ?>
                        <?php foreach ( $vars as $key => $row ) : ?>
                            <tr>
                                <td>
                                    <input type="text"
                                           name="woc_vars[key][]"
                                           value="<?php echo esc_attr( $key ); ?>"
                                           class="regular-text" />
                                    <p class="description">請填英文小寫與底線，例如 company_office。</p>
                                </td>
                                <td>
                                    <input type="text"
                                           name="woc_vars[label][]"
                                           value="<?php echo esc_attr( $row['label'] ?? '' ); ?>"
                                           class="regular-text" />
                                </td>
                                <td>
                                    <textarea name="woc_vars[value][]" rows="2" class="large-text"><?php
                                        echo esc_textarea( $row['value'] ?? '' );
                                    ?></textarea>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
    
                    <!-- 空白模板列：讓 JS clone 用 -->
                    <tr class="woc-var-template" style="<?php echo $vars ? 'display:none;' : ''; ?>">
                        <td>
                            <input type="text" name="woc_vars[key][]" value="" class="regular-text" />
                            <p class="description">請填英文小寫與底線，例如 company_office。</p>
                        </td>
                        <td>
                            <input type="text" name="woc_vars[label][]" value="" class="regular-text" />
                        </td>
                        <td>
                            <textarea name="woc_vars[value][]" rows="2" class="large-text"></textarea>
                        </td>
                    </tr>
                    </tbody>
                </table>
    
                <p>
                    <button type="button" class="button" id="woc-add-var-row">新增一列</button>
                </p>
    
                <?php submit_button( '儲存變數' ); ?>
            </form>
        </div>
    
        <script>
        (function($){
            $('#woc-add-var-row').on('click', function(){
                var $tpl = $('.woc-var-template').first();
                var $row = $tpl.clone().removeClass('woc-var-template').show();
                $('#woc-vars-rows').append($row);
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * 在合約 / 合約範本側邊欄新增「可用合約變數」 meta box
     */
    public static function add_vars_helper_meta_box( $post_type ) {

        if ( $post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT
             && $post_type !== WOC_Contracts_CPT::POST_TYPE_TEMPLATE ) {
            return;
        }

        add_meta_box(
            'woc-vars-helper',
            '可用合約變數',
            [ __CLASS__, 'render_vars_helper_meta_box' ],
            $post_type,
            'side',
            'high'
        );
    }

    /**
     * meta box 內容：列出可用變數，點擊可複製
     */
    public static function render_vars_helper_meta_box( $post ) {

        $vars  = get_option( 'woc_contract_global_vars', [] );
        $fixed = WOC_Contracts_CPT::get_fixed_vars();

        // 統一成同一種結構：key => [label,value]
        if ( ! is_array( $vars ) ) $vars = [];
        $vars = $vars + $fixed; // 注意：固定碼不覆蓋使用者（而且你已禁止存入保留碼）
        
        if ( ! is_array( $vars ) || empty( $vars ) ) {
            echo '<p>目前尚未設定任何合約變數。</p>';
            return;
        }

        echo '<div id="woc-vars-helper-box">';

        foreach ( $vars as $key => $row ) {

            $key = sanitize_key( $key );
            if ( $key === '' ) {
                continue;
            }

            if ( is_array( $row ) ) {
                $label = isset( $row['label'] ) && $row['label'] !== '' ? $row['label'] : $key;
            } else {
                $label = $key;
            }

            $placeholder = '{' . $key . '}';

            printf(
                '<p><button type="button" class="button button-small woc-copy-var" data-copy="%s">%s：%s</button></p>',
                esc_attr( $placeholder ),
                esc_html( $label ),
                esc_html( $placeholder )
            );
        }

        echo '<p class="description">點按上方按鈕可複製變數代碼，在內容中貼上使用。</p>';
        echo '</div>';
    }


    


    

}

WOC_Contracts_Admin::init();
