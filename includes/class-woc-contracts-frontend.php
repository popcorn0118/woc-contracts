<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_Frontend {

    /**
     * 掛前台相關 hook
     */
    public static function init() {

        // 單篇線上合約使用外掛 template
        add_filter( 'template_include', [ __CLASS__, 'maybe_use_contract_template' ] );

        // 前台樣式 / JS
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        // 合約頁禁快取（尤其帶 ?t=）
        add_action( 'template_redirect', [ __CLASS__, 'disable_cache_for_contract' ] );

        // 前台簽名送出（未登入 / 已登入）
        add_action( 'admin_post_nopriv_woc_sign_contract', [ __CLASS__, 'handle_sign_contract' ] );
        add_action( 'admin_post_woc_sign_contract',        [ __CLASS__, 'handle_sign_contract' ] );
    }

    /**
     * 只在單篇合約頁載入前台 CSS / JS
     */
    public static function enqueue_assets() {

        if ( ! is_singular( WOC_Contracts_CPT::POST_TYPE_CONTRACT ) ) {
            return;
        }

        // 前台樣式（控制 header/footer 隱藏、合約排版等）
        wp_enqueue_style(
            'woc-contracts-frontend',
            WOC_CONTRACTS_URL . 'assets/css/woc-contracts-frontend.css',
            [],
            WOC_CONTRACTS_VERSION
        );

        // 前台 JS（簽名板、送出簽名用）
        // wp_enqueue_script(
        //     'woc-contracts-frontend',
        //     WOC_CONTRACTS_URL . 'assets/js/woc-contracts-frontend.js',
        //     [ 'jquery' ],
        //     WOC_CONTRACTS_VERSION,
        //     true
        // );
    }

    /**
     * 單篇線上合約使用外掛自己的 template
     */
    public static function maybe_use_contract_template( $template ) {

        if ( is_singular( WOC_Contracts_CPT::POST_TYPE_CONTRACT ) ) {

            $custom = WOC_CONTRACTS_PATH . 'templates/contract-public.php';
            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }

        return $template;
    }

    /**
     * 前台簽名送出處理
     */
    public static function handle_sign_contract() {

        if (
            empty( $_POST['woc_contract_id'] ) ||
            empty( $_POST['woc_signature_data'] ) ||
            empty( $_POST['woc_token'] )
        ) {
            wp_die( '簽名資料不完整。', '簽名錯誤', 400 );
        }

        $contract_id = (int) $_POST['woc_contract_id'];
        $token       = sanitize_text_field( wp_unslash( $_POST['woc_token'] ) );

        // nonce
        if (
            ! isset( $_POST['woc_sign_nonce'] ) ||
            ! wp_verify_nonce( $_POST['woc_sign_nonce'], 'woc_sign_contract_' . $contract_id )
        ) {
            $redirect = add_query_arg(
                [
                    't'   => $token,
                    'err' => 'nonce',
                ],
                get_permalink( $contract_id )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        $contract = get_post( $contract_id );
        if ( ! $contract || $contract->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            wp_die( '找不到合約。', '簽名錯誤', 404 );
        }

        //密碼保護：沒輸入密碼（沒有 wp-postpass cookie）就禁止簽名寫入
        if ( post_password_required( $contract ) ) {
            wp_die( '需要先輸入合約密碼。', '簽名錯誤', 403 );
        }

        $required_token = get_post_meta( $contract_id, WOC_Contracts_CPT::META_VIEW_TOKEN, true );
        if ( empty( $required_token ) || ! hash_equals( $required_token, $token ) ) {
            wp_die( '合約連結已失效或無效。', '簽名錯誤', 400 );
        }

        $status = get_post_meta( $contract_id, WOC_Contracts_CPT::META_STATUS, true );
        if ( $status === 'signed' ) {
            wp_die( '此合約已簽署，無法重複簽署。', '簽名錯誤', 400 );
        }

        $data_url = wp_unslash( $_POST['woc_signature_data'] );

        if ( ! preg_match( '#^data:image/png;base64,#', $data_url ) ) {
            wp_die( '簽名格式錯誤。', '簽名錯誤', 400 );
        }

        $base64 = substr( $data_url, strpos( $data_url, ',' ) + 1 );
        if ( $base64 === '' ) {
            wp_die( '簽名資料不完整。', '簽名錯誤', 400 );
        }

        $binary = base64_decode( $base64, true );
        if ( $binary === false ) {
            wp_die( '簽名資料無法解析。', '簽名錯誤', 400 );
        }

        // 用「解碼後」大小判斷（base64 會膨脹）
        if ( strlen( $binary ) > 5 * 1024 * 1024 ) {
            wp_die( '簽名資料過大。', '簽名錯誤', 413 );
        }

        // 檢查圖片可解析（拿到 GD resource 後釋放）
        $img = @imagecreatefromstring( $binary );
        if ( ! $img ) {
            wp_die( '簽名圖片無法辨識。', '簽名錯誤', 400 );
        }
        imagedestroy( $img );

        // uploads 路徑
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            wp_die( '無法儲存簽名檔案：' . esc_html( $upload['error'] ), '簽名錯誤', 500 );
        }

        $subdir = '/woc-signatures';
        $dir    = trailingslashit( $upload['basedir'] ) . ltrim( $subdir, '/' );

        if ( ! wp_mkdir_p( $dir ) ) {
            wp_die( '無法建立簽名資料夾。', '簽名錯誤', 500 );
        }

        // 唯一檔名避免撞檔
        $base_name = 'contract-' . $contract_id . '-' . time() . '.png';
        $filename  = wp_unique_filename( $dir, $base_name );
        $file_path = trailingslashit( $dir ) . $filename;

        $written = ( file_put_contents( $file_path, $binary ) !== false );
        if ( ! $written ) {
            wp_die( '簽名檔案寫入失敗。', '簽名錯誤', 500 );
        }

        $file_url = trailingslashit( $upload['baseurl'] ) . ltrim( $subdir, '/' ) . '/' . $filename;

        update_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, esc_url_raw( $file_url ) );
        update_post_meta( $contract_id, WOC_Contracts_CPT::META_STATUS, 'signed' );
        update_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_AT, current_time( 'mysql' ) );

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        update_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_IP, $ip );

        $message = '客戶使用以下方式在線上簽訂合約';
        if ( $ip ) {
            $message .= ' ' . $ip;
        }
        WOC_Contracts_CPT::add_audit_log( $contract_id, $message );

        $redirect = add_query_arg(
            [
                't'      => $token,
                'signed' => '1',
            ],
            get_permalink( $contract_id )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    
    

    /**
     * 合約頁禁快取
     */
    public static function disable_cache_for_contract() {
        if ( ! is_singular( WOC_Contracts_CPT::POST_TYPE_CONTRACT ) ) return;
        if ( empty( $_GET['t'] ) ) return;

        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
    }
}

// WOC_Contracts_Frontend::init();
