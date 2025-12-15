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
        add_action( 'wp_ajax_woc_load_template', [ __CLASS__, 'ajax_load_template' ] );

         // 後台 UI 相關 filter
        add_filter( 'get_sample_permalink_html', [ __CLASS__, 'filter_sample_permalink_html' ], 10, 5 );
        add_filter( 'post_row_actions',          [ __CLASS__, 'filter_post_row_actions' ], 10, 2 );
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
            'normal',
            'high'
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
                   id="woc-contract-link-url"
                   class="widefat"
                   value="<?php echo esc_attr( $url ); ?>">
        </p>
    
        <p>
            <button type="button"
                    class="button"
                    id="woc-copy-link-btn"
                    data-link="<?php echo esc_attr( $url ); ?>">
                複製連結
            </button>
    
            <button type="button"
                    class="button button-secondary"
                    id="woc-open-link-btn"
                    data-link="<?php echo esc_attr( $url ); ?>">
                開啟連結
            </button>
        </p>
    
        <p class="description">複製後貼給客戶，或直接點「開啟連結」檢視。</p>
        <?php
    }
    

    /**
     * Meta Box HTML：合約範本選擇 + 載入按鈕
     */
    public static function render_contract_meta_box( $post ) {

        // 目前已選範本
        $current_template_id = get_post_meta( $post->ID, WOC_Contracts_CPT::META_TEMPLATE_ID, true );

        // 取得所有已發布的範本
        $templates = get_posts( [
            'post_type'      => WOC_Contracts_CPT::POST_TYPE_TEMPLATE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        wp_nonce_field( 'woc_contract_meta', 'woc_contract_meta_nonce' );
        ?>

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
                    class="button button-secondary"
                    id="woc-load-template-btn"
                    data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'woc_load_template' ) ); ?>">
                載入範本內容
            </button>
        </p>

        <p class="description">
            選擇合約範本後，按「載入範本內容」，系統會將範本內容覆蓋到目前合約的內容欄位。<br>
            建議在客戶簽名前先確認內容，簽名後不再更換範本。
        </p>
        <?php
    }

    /**
     * 儲存合約的 meta（目前只存範本 ID）
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

        // nonce
        if ( ! isset( $_POST['woc_contract_meta_nonce'] ) ||
             ! wp_verify_nonce( $_POST['woc_contract_meta_nonce'], 'woc_contract_meta' ) ) {
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
            // 亂數：32 字元就夠用
            $token = wp_generate_password( 32, false, false );
            update_post_meta( $post_id, WOC_Contracts_CPT::META_VIEW_TOKEN, $token );
        }
    }

    /**
     * 後台載入 JS（只在「線上合約」編輯畫面）
     */
    public static function enqueue_admin_assets( $hook_suffix ) {
        global $post;

        if ( ! isset( $post ) || $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return;
        }

        wp_enqueue_script(
            'woc-contracts-admin',
            WOC_CONTRACTS_URL . 'assets/js/woc-contracts-admin.js',
            [ 'jquery' ],
            WOC_CONTRACTS_VERSION,
            true
        );
    }

    /**
     * AJAX：讀取範本內容
     */
    public static function ajax_load_template() {

        check_ajax_referer( 'woc_load_template', 'nonce' );

        $template_id = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
        $post_id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

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

        // 之後你要加：自動帶入日期、公司章…可以在這裡做字串替換

        wp_send_json_success( [
            'content' => $content,
        ] );
    }

    /**
     * 處理永久連結區塊
     */
    public static function filter_sample_permalink_html( $html, $post_id, $new_title, $new_slug, $post ) {

        if ( $post->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            return $html;
        }
    
        // 直接不顯示整個永久連結區塊
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
    

}

WOC_Contracts_Admin::init();
