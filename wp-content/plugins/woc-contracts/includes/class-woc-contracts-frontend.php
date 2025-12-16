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
        wp_enqueue_script(
            'woc-contracts-frontend',
            WOC_CONTRACTS_URL . 'assets/js/woc-contracts-frontend.js',
            [ 'jquery' ],
            WOC_CONTRACTS_VERSION,
            true
        );
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

        // 基本欄位檢查
        if (
            empty( $_POST['woc_contract_id'] ) ||
            empty( $_POST['woc_signature_data'] ) ||
            empty( $_POST['woc_token'] )
        ) {
            wp_die( '簽名資料不完整。', '簽名錯誤', 400 );
        }

        $contract_id = (int) $_POST['woc_contract_id'];
        $token       = sanitize_text_field( wp_unslash( $_POST['woc_token'] ) );

        // Nonce 驗證
        if (
            ! isset( $_POST['woc_sign_nonce'] ) ||
            ! wp_verify_nonce( $_POST['woc_sign_nonce'], 'woc_sign_contract_' . $contract_id )
        ) {
            wp_die( '驗證失敗，請重新嘗試。', '簽名錯誤', 400 );
        }

        // 確認是我們的 CPT
        $contract = get_post( $contract_id );
        if ( ! $contract || $contract->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            wp_die( '找不到合約。', '簽名錯誤', 404 );
        }

        // 檢查 token 是否仍有效
        $required_token = get_post_meta( $contract_id, WOC_Contracts_CPT::META_VIEW_TOKEN, true );
        if ( empty( $required_token ) || ! hash_equals( $required_token, $token ) ) {
            wp_die( '合約連結已失效或無效。', '簽名錯誤', 400 );
        }

        // 確認尚未簽署
        $status = get_post_meta( $contract_id, WOC_Contracts_CPT::META_STATUS, true );
        if ( $status === 'signed' ) {
            wp_die( '此合約已簽署，無法重複簽署。', '簽名錯誤', 400 );
        }

        // 解析簽名圖（data URL）
        $data_url = wp_unslash( $_POST['woc_signature_data'] );

        if ( ! preg_match( '#^data:image/png;base64,#', $data_url ) ) {
            wp_die( '簽名格式錯誤。', '簽名錯誤', 400 );
        }

        $base64 = substr( $data_url, strpos( $data_url, ',' ) + 1 );
        $binary = base64_decode( $base64, true );

        if ( $binary === false ) {
            wp_die( '簽名資料無法解析。', '簽名錯誤', 400 );
        }


        // 上傳目錄
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            wp_die( '無法儲存簽名檔案：' . esc_html( $upload['error'] ), '簽名錯誤', 500 );
        }

        $dir = trailingslashit( $upload['basedir'] ) . 'woc-signatures';
        if ( ! wp_mkdir_p( $dir ) ) {
            wp_die( '無法建立簽名資料夾。', '簽名錯誤', 500 );
        }

        $filename  = 'contract-' . $contract_id . '-' . time() . '.png';
        $file_path = trailingslashit( $dir ) . $filename;

        if ( file_put_contents( $file_path, $binary ) === false ) {
            wp_die( '簽名檔案寫入失敗。', '簽名錯誤', 500 );
        }

        $file_url = trailingslashit( $upload['baseurl'] ) . 'woc-signatures/' . $filename;

        // 寫入 meta
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


        // 目前保留 token，保留檢視用；之後若要「簽完立即失效」再改成刪除 token。
        // delete_post_meta( $contract_id, WOC_Contracts_CPT::META_VIEW_TOKEN );

        // 簽完導回同一份合約頁，帶上 signed=1
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
}

WOC_Contracts_Frontend::init();
