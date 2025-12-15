<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_Frontend {

    public static function init() {
        add_filter( 'template_include', [ __CLASS__, 'maybe_use_contract_template' ] );

        // 前台簽名送出
        add_action( 'admin_post_nopriv_woc_sign_contract', [ __CLASS__, 'handle_sign_contract' ] );
        add_action( 'admin_post_woc_sign_contract',        [ __CLASS__, 'handle_sign_contract' ] );
    }

    public static function maybe_use_contract_template( $template ) {
        if ( is_singular( WOC_Contracts_CPT::POST_TYPE_CONTRACT ) ) {
            $custom = WOC_CONTRACTS_DIR . 'templates/contract-public.php';
            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }
        return $template;
    }

    public static function handle_sign_contract() {

        if ( empty( $_POST['woc_contract_id'] ) || empty( $_POST['woc_signature_data'] ) || empty( $_POST['woc_token'] ) ) {
            wp_die( '簽名資料不完整。', '簽名錯誤', 400 );
        }

        $contract_id = (int) $_POST['woc_contract_id'];
        $token       = sanitize_text_field( $_POST['woc_token'] );

        if ( ! isset( $_POST['woc_sign_nonce'] ) ||
             ! wp_verify_nonce( $_POST['woc_sign_nonce'], 'woc_sign_contract_' . $contract_id ) ) {
            wp_die( '驗證失敗，請重新嘗試。', '簽名錯誤', 400 );
        }

        $contract = get_post( $contract_id );
        if ( ! $contract || $contract->post_type !== WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            wp_die( '找不到合約。', '簽名錯誤', 404 );
        }

        $required_token = get_post_meta( $contract_id, WOC_Contracts_CPT::META_VIEW_TOKEN, true );
        if ( empty( $required_token ) || ! hash_equals( $required_token, $token ) ) {
            wp_die( '合約連結已失效或無效。', '簽名錯誤', 400 );
        }

        $status = get_post_meta( $contract_id, WOC_Contracts_CPT::META_STATUS, true );
        if ( $status === 'signed' ) {
            wp_die( '此合約已簽署，無法重複簽署。', '簽名錯誤', 400 );
        }

        $data_url = $_POST['woc_signature_data'];

        if ( ! preg_match( '#^data:image/png;base64,#', $data_url ) ) {
            wp_die( '簽名格式錯誤。', '簽名錯誤', 400 );
        }

        $base64 = substr( $data_url, strpos( $data_url, ',' ) + 1 );
        $binary = base64_decode( $base64 );

        if ( ! $binary ) {
            wp_die( '簽名資料無法解析。', '簽名錯誤', 400 );
        }

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

        update_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, esc_url_raw( $file_url ) );
        update_post_meta( $contract_id, WOC_Contracts_CPT::META_STATUS, 'signed' );
        update_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_AT, current_time( 'mysql' ) );

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        update_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_IP, $ip );

        // 目前先保留 token，之後如果要「簽完就失效」再改成 delete_post_meta
        // delete_post_meta( $contract_id, WOC_Contracts_CPT::META_VIEW_TOKEN );

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
