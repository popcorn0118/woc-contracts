<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_PDF {

    const META_PDF_PATH         = '_woc_pdf_path';
    const META_PDF_GENERATED_AT = '_woc_pdf_generated_at';
    const META_PDF_HASH         = '_woc_pdf_hash';

    const NONCE_DOWNLOAD = 'woc_download_pdf';
    const NONCE_REPAIR   = 'woc_repair_pdf';

    public static function init() {

        // 列表列操作（下載 / 異常提示 / 立即生成）
        add_filter( 'post_row_actions', [ __CLASS__, 'filter_post_row_actions' ], 10, 2 );

        // 下載 / 修復 action
        add_action( 'admin_post_woc_download_pdf', [ __CLASS__, 'handle_download' ] );
        add_action( 'admin_post_woc_repair_pdf',   [ __CLASS__, 'handle_repair' ] );

        // 刪除合約時同步刪除 PDF
        add_action( 'before_delete_post', [ __CLASS__, 'handle_before_delete_post' ] );
    }

    /**
     * 後台列表：row actions
     */
    public static function filter_post_row_actions( $actions, $post ) {

        if ( ! $post || empty( $post->ID ) ) {
            return $actions;
        }

        if ( ! self::is_contract_post_type( $post->post_type ) ) {
            return $actions;
        }

        // 未簽署：不顯示任何 PDF 相關動作
        if ( ! self::is_signed( $post->ID ) ) {
            return $actions;
        }

        $has_pdf = self::pdf_exists( $post->ID );

        if ( $has_pdf ) {

            if ( current_user_can( 'edit_post', $post->ID ) ) {

                $url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=woc_download_pdf&post_id=' . absint( $post->ID ) ),
                    self::NONCE_DOWNLOAD . ':' . absint( $post->ID )
                );

                $actions['woc_download_pdf'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( '下載PDF', 'woc-contracts' ) . '</a>';
            }

            return $actions;
        }

        // 已簽署但 PDF 不存在（異常）
        if ( current_user_can( 'manage_options' ) ) {

            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=woc_repair_pdf&post_id=' . absint( $post->ID ) ),
                self::NONCE_REPAIR . ':' . absint( $post->ID )
            );

            $actions['woc_repair_pdf'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( '立即生成PDF', 'woc-contracts' ) . '</a>';

        } else {

            $actions['woc_pdf_not_ready'] = '<span style="color:#999;">' . esc_html__( 'PDF 尚未就緒，請聯絡管理員', 'woc-contracts' ) . '</span>';
        }

        return $actions;
    }

    /**
     * 下載 PDF（admin-post）
     */
    public static function handle_download() {

        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Invalid post id.', 'woc-contracts' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Permission denied.', 'woc-contracts' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_DOWNLOAD . ':' . $post_id ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'woc-contracts' ) );
        }

        $abs = self::get_pdf_abs_path( $post_id );
        if ( ! $abs || ! file_exists( $abs ) ) {
            wp_die( esc_html__( 'PDF not found.', 'woc-contracts' ) );
        }

        $filename = basename( $abs );

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $abs ) );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        readfile( $abs );
        exit;
    }

    /**
     * 立即生成 PDF（異常修復；admin-post；僅管理員）
     */
    public static function handle_repair() {

        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Invalid post id.', 'woc-contracts' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'woc-contracts' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_REPAIR . ':' . $post_id ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'woc-contracts' ) );
        }

        $res = self::ensure_pdf( $post_id, true );

        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = admin_url( 'edit.php?post_type=' . self::get_contract_post_type() );
        }

        if ( is_wp_error( $res ) ) {
            $redirect = add_query_arg( [ 'woc_pdf' => 'error' ], $redirect );
        } else {
            $redirect = add_query_arg( [ 'woc_pdf' => 'generated' ], $redirect );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * 刪除合約時同步刪除 PDF
     */
    public static function handle_before_delete_post( $post_id ) {

        $post_id = absint( $post_id );
        if ( ! $post_id ) return;

        $post = get_post( $post_id );
        if ( ! $post ) return;

        if ( ! self::is_contract_post_type( $post->post_type ) ) {
            return;
        }

        self::delete_pdf( $post_id );
    }

    /**
     * 確保 PDF 存在（不存在就生成；force=true 強制重建）
     */
    public static function ensure_pdf( $contract_id, $force = false ) {

        $contract_id = absint( $contract_id );
        if ( ! $contract_id ) {
            return new WP_Error( 'invalid_id', 'Invalid contract id.' );
        }

        $post = get_post( $contract_id );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Contract not found.' );
        }

        if ( ! self::is_contract_post_type( $post->post_type ) ) {
            return new WP_Error( 'invalid_type', 'Invalid post type.' );
        }

        // 若已存在且不強制，直接回傳
        if ( ! $force && self::pdf_exists( $contract_id ) ) {
            return get_post_meta( $contract_id, self::META_PDF_PATH, true );
        }

        // 產 HTML（提供 filter 讓你之後換成正式列印模板）
        $html = self::render_pdf_html( $contract_id );
        if ( ! $html ) {
            return new WP_Error( 'empty_html', 'Empty PDF HTML.' );
        }

        // 確保資料夾
        $dirs = self::get_upload_dirs();
        if ( ! file_exists( $dirs['pdf_dir'] ) ) {
            wp_mkdir_p( $dirs['pdf_dir'] );
        }
        if ( ! file_exists( $dirs['tmp_dir'] ) ) {
            wp_mkdir_p( $dirs['tmp_dir'] );
        }

        // 產檔名
        $filename = self::build_filename( $contract_id );
        $abs_path = trailingslashit( $dirs['pdf_dir'] ) . $filename;

        // mPDF
        if ( ! class_exists( '\Mpdf\Mpdf' ) ) {
            return new WP_Error( 'mpdf_missing', 'mPDF not loaded.' );
        }

        try {

            $mpdf = new \Mpdf\Mpdf( [
                'tempDir' => $dirs['tmp_dir'],
            ] );

            $mpdf->WriteHTML( $html );
            $mpdf->Output( $abs_path, \Mpdf\Output\Destination::FILE );

        } catch ( \Throwable $e ) {

            return new WP_Error( 'mpdf_error', $e->getMessage() );
        }

        // 存 meta（存相對路徑）
        $rel_path = 'woc-contracts/pdfs/' . $filename;

        update_post_meta( $contract_id, self::META_PDF_PATH, $rel_path );
        update_post_meta( $contract_id, self::META_PDF_GENERATED_AT, time() );
        update_post_meta( $contract_id, self::META_PDF_HASH, self::hash_file_safe( $abs_path ) );

        return $rel_path;
    }

    /**
     * 刪除該合約的 PDF（含 meta）
     */
    public static function delete_pdf( $contract_id ) {

        $contract_id = absint( $contract_id );
        if ( ! $contract_id ) return;

        $abs = self::get_pdf_abs_path( $contract_id );
        if ( $abs && file_exists( $abs ) ) {
            @unlink( $abs );
        }

        delete_post_meta( $contract_id, self::META_PDF_PATH );
        delete_post_meta( $contract_id, self::META_PDF_GENERATED_AT );
        delete_post_meta( $contract_id, self::META_PDF_HASH );
    }

    /**
     * PDF 是否存在（meta 有且檔案存在）
     */
    public static function pdf_exists( $contract_id ) {

        $abs = self::get_pdf_abs_path( $contract_id );
        return ( $abs && file_exists( $abs ) );
    }

    /**
     * 取 PDF 絕對路徑
     */
    public static function get_pdf_abs_path( $contract_id ) {

        $rel = get_post_meta( absint( $contract_id ), self::META_PDF_PATH, true );
        if ( ! $rel ) return '';

        $u = wp_upload_dir();
        return trailingslashit( $u['basedir'] ) . ltrim( $rel, '/' );
    }

    /**
     * 產 PDF 用 HTML（先給最小可跑版本；你之後用 filter 換成正式模板）
     */
    public static function render_pdf_html( $contract_id ) {

        $post = get_post( $contract_id );
        if ( ! $post ) return '';

        $title   = get_the_title( $contract_id );
        $content = apply_filters( 'the_content', $post->post_content );

        $html  = '<!doctype html><html><head><meta charset="utf-8">';
        $html .= '<style>body{font-family: sans-serif; font-size: 12pt;} h1{font-size:18pt;margin:0 0 12pt;} .meta{color:#666;font-size:10pt;margin:0 0 16pt;}</style>';
        $html .= '</head><body>';
        $html .= '<h1>' . esc_html( $title ) . '</h1>';
        $html .= '<div class="meta">Contract ID: ' . intval( $contract_id ) . '</div>';
        $html .= $content;
        $html .= '</body></html>';

        /**
         * 你之後正式版在這裡接：回傳完整 HTML（含 inline css）
         * apply_filters( 'woc_contracts_pdf_html', $html, $contract_id )
         */
        return apply_filters( 'woc_contracts_pdf_html', $html, $contract_id );
    }

    /**
     * 判斷是否已簽署（提供 filter 讓你對接你實際狀態值）
     */
    public static function is_signed( $contract_id ) {

        // 預設：狀態 meta key 優先使用 WOC_Contracts_CPT::META_STATUS
        $status_key = defined( 'WOC_Contracts_CPT::META_STATUS' ) ? WOC_Contracts_CPT::META_STATUS : '_woc_status';
        $status     = get_post_meta( absint( $contract_id ), $status_key, true );

        // 預設已簽署值：signed（你可用 filter 改）
        $signed_value = apply_filters( 'woc_contracts_signed_status_value', 'signed', absint( $contract_id ) );

        return ( (string) $status === (string) $signed_value );
    }

    /**
     * 合約 post type 判斷
     */
    public static function is_contract_post_type( $post_type ) {

        $pt = self::get_contract_post_type();
        return ( (string) $post_type === (string) $pt );
    }

    public static function get_contract_post_type() {

        if ( defined( 'WOC_Contracts_CPT::POST_TYPE_CONTRACT' ) ) {
            return WOC_Contracts_CPT::POST_TYPE_CONTRACT;
        }

        return 'woc_contract';
    }

    /**
     * uploads 目錄
     */
    public static function get_upload_dirs() {

        $u = wp_upload_dir();

        return [
            'pdf_dir' => trailingslashit( $u['basedir'] ) . 'woc-contracts/pdfs',
            'tmp_dir' => trailingslashit( $u['basedir'] ) . 'woc-contracts/mpdf-tmp',
        ];
    }

    public static function build_filename( $contract_id ) {

        $contract_id = absint( $contract_id );
        $ts          = gmdate( 'Ymd-His' );
        $rand        = wp_generate_password( 8, false, false );

        return 'contract-' . $contract_id . '-' . $ts . '-' . $rand . '.pdf';
    }

    public static function hash_file_safe( $abs_path ) {

        if ( ! $abs_path || ! file_exists( $abs_path ) ) return '';
        $hash = @hash_file( 'sha256', $abs_path );
        return $hash ? $hash : '';
    }
}
