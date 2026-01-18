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
     * 讀取 PDF 專用 CSS（mPDF 會吃的那份）
     */
    private static function get_pdf_css() {

        $css_file = trailingslashit( WOC_CONTRACTS_PATH ) . 'assets/css/woc-contracts-pdf.css';
        if ( ! file_exists( $css_file ) ) {
            return '';
        }

        $css = file_get_contents( $css_file );
        return $css ? trim( $css ) : '';
    }

    /**
     * 取合約 meta key（有常數就用常數，沒有就 fallback）
     */
    private static function get_contract_meta_key( $which ) {

        if ( 'signature' === $which ) {
            if ( class_exists( 'WOC_Contracts_CPT' ) && defined( 'WOC_Contracts_CPT::META_SIGNATURE_IMAGE' ) ) {
                return WOC_Contracts_CPT::META_SIGNATURE_IMAGE;
            }
            return '_woc_signature_image';
        }

        if ( 'signed_at' === $which ) {
            if ( class_exists( 'WOC_Contracts_CPT' ) && defined( 'WOC_Contracts_CPT::META_SIGNED_AT' ) ) {
                return WOC_Contracts_CPT::META_SIGNED_AT;
            }
            return '_woc_signed_at';
        }

        if ( 'signed_ip' === $which ) {
            if ( class_exists( 'WOC_Contracts_CPT' ) && defined( 'WOC_Contracts_CPT::META_SIGNED_IP' ) ) {
                return WOC_Contracts_CPT::META_SIGNED_IP;
            }
            return '_woc_signed_ip';
        }

        return '';
    }

    /**
     * 將簽名 meta 轉成 mPDF 可用的 img src
     */
    private static function get_signature_src( $contract_id ) {

        $key = self::get_contract_meta_key( 'signature' );
        if ( ! $key ) return '';

        $raw = get_post_meta( absint( $contract_id ), $key, true );
        if ( ! $raw ) return '';

        $raw = trim( (string) $raw );

        // 1) 最穩：data URI
        if ( 0 === strpos( $raw, 'data:image/' ) ) {
            return $raw;
        }

        // 2) URL
        if ( preg_match( '#^https?://#i', $raw ) ) {
            return $raw;
        }

        // 3) uploads 相對路徑 / 絕對路徑
        $u = wp_upload_dir();

        // 3-1) 以 uploads baseurl 開頭
        if ( isset( $u['baseurl'] ) && $u['baseurl'] && 0 === strpos( $raw, $u['baseurl'] ) ) {
            $maybe_rel = ltrim( str_replace( $u['baseurl'], '', $raw ), '/' );
            $abs = trailingslashit( $u['basedir'] ) . $maybe_rel;
            if ( file_exists( $abs ) ) {
                return 'file://' . $abs;
            }
        }

        // 3-2) 以 uploads basedir 開頭的絕對路徑
        if ( isset( $u['basedir'] ) && $u['basedir'] && 0 === strpos( $raw, $u['basedir'] ) ) {
            if ( file_exists( $raw ) ) {
                return 'file://' . $raw;
            }
        }

        // 3-3) 當成 uploads 下的相對路徑
        $abs2 = trailingslashit( $u['basedir'] ) . ltrim( $raw, '/' );
        if ( file_exists( $abs2 ) ) {
            return 'file://' . $abs2;
        }

        return '';
    }

    /**
     * 格式化簽約時間（支援 timestamp 或字串）
     */
    private static function format_signed_time( $contract_id ) {

        $key = self::get_contract_meta_key( 'signed_at' );
        if ( ! $key ) return '';

        $raw = get_post_meta( absint( $contract_id ), $key, true );
        if ( '' === $raw || null === $raw ) return '';

        if ( is_numeric( $raw ) ) {
            return date_i18n( 'Y-m-d H:i:s', (int) $raw );
        }

        return trim( (string) $raw );
    }

    /**
     * ✅ 從一段 HTML 中移除所有「可能被塞進去的簽名區塊」
     */
    private static function strip_signed_blocks_from_html( $html ) {

        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        $patterns = [
            // class="... woc-contract-signed ..." (雙引號)
            '~<div\b[^>]*\bclass\s*=\s*"[^"]*\bwoc-contract-signed\b[^"]*"[^>]*>.*?</div>\s*~is',
            // class='... woc-contract-signed ...' (單引號)
            "~<div\\b[^>]*\\bclass\\s*=\\s*'[^']*\\bwoc-contract-signed\\b[^']*'[^>]*>.*?</div>\\s*~is",
            // class=...woc-contract-signed... (無引號，少見但防一下)
            '~<div\b[^>]*\bclass\s*=\s*[^\s>]*\bwoc-contract-signed\b[^\s>]*[^>]*>.*?</div>\s*~is',

            // 另外拆開塞的子區塊（就算外層不是 woc-contract-signed，也砍掉）
            '~<div\b[^>]*\bclass\s*=\s*"[^"]*\bwoc-signature-image-box\b[^"]*"[^>]*>.*?</div>\s*~is',
            "~<div\\b[^>]*\\bclass\\s*=\\s*'[^']*\\bwoc-signature-image-box\\b[^']*'[^>]*>.*?</div>\\s*~is",
            '~<div\b[^>]*\bclass\s*=\s*"[^"]*\bwoc-signature-info\b[^"]*"[^>]*>.*?</div>\s*~is',
            "~<div\\b[^>]*\\bclass\\s*=\\s*'[^']*\\bwoc-signature-info\\b[^']*'[^>]*>.*?</div>\\s*~is",
        ];

        return preg_replace( $patterns, '', $html );
    }

    /**
     * ✅ 最終保險：整份 HTML 若仍有多個 woc-contract-signed，只保留最後一個
     */
    private static function keep_last_signed_block_only( $html ) {

        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        $re = '~<div\b[^>]*\bclass\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)[^>]*\bwoc-contract-signed\b[^>]*>.*?</div>\s*~is';

        if ( ! preg_match_all( $re, $html, $m, PREG_OFFSET_CAPTURE ) ) {
            return $html;
        }

        if ( count( $m[0] ) <= 1 ) {
            return $html;
        }

        // 保留最後一個，其它全部移除
        $last = end( $m[0] );
        $last_pos = $last[1];
        $last_len = strlen( $last[0] );

        $before = substr( $html, 0, $last_pos );
        $middle = substr( $html, $last_pos, $last_len );
        $after  = substr( $html, $last_pos + $last_len );

        $before = preg_replace( $re, '', $before );

        return $before . $middle . $after;
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

        // 產 HTML
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
                'mode'    => 'utf-8',
                'tempDir' => $dirs['tmp_dir'],

                'autoScriptToLang' => true,
                'autoLangToFont'   => true,

                'default_font' => 'sun-exta',
            ] );

            // 先載入 PDF CSS
            $pdf_css = self::get_pdf_css();
            if ( $pdf_css ) {
                $mpdf->WriteHTML( $pdf_css, \Mpdf\HTMLParserMode::HEADER_CSS );
            }

            // 再輸出 HTML
            $mpdf->WriteHTML( $html, \Mpdf\HTMLParserMode::HTML_BODY );
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
     * 產 PDF 用 HTML（由 woc-contracts-pdf.css 控制）
     * - Contract ID 移到底部資訊
     * - 加上簽名圖 + 簽約時間 + IP
     * - ✅ 強制去除任何既有的簽名區塊，避免重複
     */
    public static function render_pdf_html( $contract_id ) {

        $post = get_post( $contract_id );
        if ( ! $post ) return '';

        $title = get_the_title( $contract_id );

        /**
         * 這裡故意用 the_content：
         * 因為很多站的合約內容可能靠 shortcode / block render 出來。
         * 但代價是：某些 filter 可能會偷塞簽名區塊 => 我們後面「強制剷掉」
         */
        $content = apply_filters( 'the_content', $post->post_content );

        // ✅ 先把內容內部的簽名區塊剷掉（不管是誰塞的）
        $content = self::strip_signed_blocks_from_html( $content );

        // === 簽名資料 ===
        $sig_src     = self::get_signature_src( $contract_id );
        $signed_time = self::format_signed_time( $contract_id );

        $signed_ip = '';
        $signed_ip_key = self::get_contract_meta_key( 'signed_ip' );
        if ( $signed_ip_key ) {
            $signed_ip = trim( (string) get_post_meta( absint( $contract_id ), $signed_ip_key, true ) );
        }

        // === 組 HTML ===
        $html  = '<!doctype html><html><head><meta charset="utf-8"></head><body>';
        $html .= '<div class="woc-contract-wrap-print">';

        $html .= '<h1>' . esc_html( $title ) . '</h1>';

        $html .= '<div class="woc-contract-content">';
        $html .= $content;
        $html .= '</div>';

        // ✅ 我們唯一允許存在的一份簽名區
        $signed_block  = '<div class="woc-pdf-signed">';

if ( $sig_src ) {
    $signed_block .= '<div class="woc-pdf-signed__sig">';
    $signed_block .= '<img src="' . esc_attr( $sig_src ) . '" alt="Signature">';
    $signed_block .= '</div>';
}

$signed_block .= '<div class="woc-pdf-signed__meta">';
$signed_block .= '<div>Contract ID: ' . intval( $contract_id ) . '</div>';

if ( $signed_time !== '' ) {
    $signed_block .= '<div>已簽約時間：' . esc_html( $signed_time ) . '</div>';
}
if ( $signed_ip !== '' ) {
    $signed_block .= '<div>簽署 IP：' . esc_html( $signed_ip ) . '</div>';
}

$signed_block .= '</div>';
$signed_block .= '</div>';

$html .= $signed_block;


        $html .= '</div></body></html>';

        // ✅ 最後再保險：如果整份 HTML 還是出現兩份，只留最後一份
        $html = self::keep_last_signed_block_only( $html );

        // === DEBUG: 看看 HTML 最後長什麼樣 + 簽名區塊出現幾次 ===
if ( defined('WP_DEBUG') && WP_DEBUG ) {
    $cnt = 0;
    if ( is_string( $html ) && $html !== '' ) {
        $cnt = preg_match_all( '~\bwoc-contract-signed\b~i', $html, $mm );
    }

    error_log( 'WOC_PDF_SIGNED_COUNT=' . intval( $cnt ) );

    // 末段 2500 字：通常足夠涵蓋 footer / 簽名區
    error_log( 'WOC_PDF_HTML_SNIP=' . substr( $html, -2500 ) );
}

        return apply_filters( 'woc_contracts_pdf_html', $html, $contract_id );
    }

    /**
     * 判斷是否已簽署（提供 filter 讓你對接你實際狀態值）
     */
    public static function is_signed( $contract_id ) {

        $status_key = defined( 'WOC_Contracts_CPT::META_STATUS' ) ? WOC_Contracts_CPT::META_STATUS : '_woc_status';
        $status     = get_post_meta( absint( $contract_id ), $status_key, true );

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
