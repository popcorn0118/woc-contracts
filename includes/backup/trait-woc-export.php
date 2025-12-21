<?php
/**
 * WOC Contracts Backup (Export)
 * - 匯出流程：handle_export() + export_* + build_contracts_payload() + download_zip()
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait WOC_Contracts_Backup_Export {

    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );
        check_admin_referer( self::NONCE_EXPORT );

        $type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
        if ( ! $type ) wp_die( '缺少 type。' );

        if ( ! class_exists( 'WOC_Contracts_CPT' ) ) wp_die( 'CPT 尚未載入。' );

        switch ( $type ) {
            case 'vars':
                self::export_vars();
                break;
            case 'templates':
                self::export_templates();
                break;
            case 'contracts':
                self::export_contracts();
                break;
            case 'contracts_zip':
                self::export_contracts_zip();
                break;
            default:
                wp_die( '未知 type。' );
        }
    }

    private static function export_vars() {
        $items = get_option( 'woc_contract_global_vars', [] );
        if ( ! is_array( $items ) ) $items = [];

        $payload = [
            'type'     => 'vars',
            'version'  => 1,
            'exported' => current_time( 'c' ),
            'items'    => $items,
        ];

        self::download_json( 'woc-contracts-vars-' . gmdate( 'Ymd-His' ) . '.json', $payload );
    }

    private static function export_templates() {
        $q = new WP_Query([
            'post_type'      => self::get_template_post_types(),
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);

        $items = [];
        foreach ( $q->posts as $p ) {

            $uuid = self::get_or_create_uuid( $p->ID, (string) $p->post_type );

            $items[] = [
                'uuid' => $uuid ? (string) $uuid : '',
                'post' => [
                    'post_title'        => $p->post_title,
                    'post_content'      => $p->post_content,
                    'post_excerpt'      => $p->post_excerpt,
                    'post_status'       => $p->post_status,
                    'post_password'     => $p->post_password,
                    'post_date_gmt'     => $p->post_date_gmt,
                    'post_modified_gmt' => $p->post_modified_gmt,
                ],
                'meta' => self::pick_meta( $p->ID ),
            ];
        }

        $payload = [
            'type'     => 'templates',
            'version'  => 1,
            'exported' => current_time( 'c' ),
            'count'    => count( $items ),
            'items'    => $items,
        ];

        self::download_json( 'woc-contracts-templates-' . gmdate( 'Ymd-His' ) . '.json', $payload );
    }

    private static function export_contracts() {
        $upload  = wp_upload_dir();
        $basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';

        list( $payload ) = self::build_contracts_payload( $basedir );

        self::download_json(
            'woc-contracts-contracts-' . gmdate( 'Ymd-His' ) . '.json',
            $payload
        );
    }

    private static function export_contracts_zip() {

        $upload  = wp_upload_dir();
        $basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
        if ( ! $basedir || ! is_dir( $basedir ) ) {
            wp_die( 'uploads 目錄不存在，無法打包簽名檔。' );
        }

        list( $payload, $file_relpaths ) = self::build_contracts_payload( $basedir );

        $zipname = 'woc-contracts-contracts-' . gmdate( 'Ymd-His' ) . '.zip';
        self::download_zip( $zipname, 'contracts.json', $payload, $basedir, $file_relpaths );
    }

    private static function build_contracts_payload( $uploads_basedir ) {

        $q = new WP_Query([
            'post_type'      => WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);

        $upload  = wp_upload_dir();
        $baseurl = isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '';

        $tpl_post_types = self::get_template_post_types();

        $items = [];
        $files_map = [];

        foreach ( $q->posts as $p ) {

            $uuid = self::get_or_create_uuid( $p->ID, WOC_Contracts_CPT::POST_TYPE_CONTRACT );

            $meta = self::pick_meta( $p->ID );

            if ( isset( $meta[ WOC_Contracts_CPT::META_SIGNATURE_IMAGE ] ) ) {
                unset( $meta[ WOC_Contracts_CPT::META_SIGNATURE_IMAGE ] );
            }

            $sig_url = get_post_meta( $p->ID, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );
            $sig_url = is_string( $sig_url ) ? $sig_url : '';

            if ( $sig_url && strpos( $sig_url, 'data:image/' ) === 0 ) {
                $sig_url = '';
            }

            $relpath = '';
            if ( $sig_url && $baseurl ) {
                $sig_path     = (string) parse_url( $sig_url, PHP_URL_PATH );
                $uploads_path = (string) parse_url( $baseurl, PHP_URL_PATH );

                if ( $sig_path && $uploads_path && strpos( $sig_path, $uploads_path ) === 0 ) {
                    $relpath = ltrim( substr( $sig_path, strlen( $uploads_path ) ), '/' );
                }
            }

            if ( $relpath ) {
                $files_map[ $relpath ] = true;
            }

            $template_id = 0;
            if ( isset( $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ] ) ) {
                $template_id = (int) $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ];
            }

            $template_uuid  = '';
            $template_title = '';

            if ( $template_id > 0 ) {
                $pt = (string) get_post_type( $template_id );
                if ( $pt && in_array( $pt, $tpl_post_types, true ) ) {
                    $template_uuid  = (string) self::get_or_create_uuid( $template_id, $pt );
                    $template_title = (string) get_the_title( $template_id );
                }
            }

            $items[] = [
                'uuid' => $uuid ? (string) $uuid : '',
                'post' => [
                    'post_title'        => $p->post_title,
                    'post_content'      => $p->post_content,
                    'post_excerpt'      => $p->post_excerpt,
                    'post_status'       => $p->post_status,
                    'post_password'     => $p->post_password,
                    'post_date_gmt'     => $p->post_date_gmt,
                    'post_modified_gmt' => $p->post_modified_gmt,
                ],
                'meta' => $meta,
                'template' => [
                    'id'    => $template_id,
                    'uuid'  => $template_uuid,
                    'title' => $template_title,
                ],
                'signature' => [
                    'url'            => $sig_url ? (string) $sig_url : '',
                    'upload_relpath' => $relpath,
                ],
            ];
        }

        $uploads_real = $uploads_basedir ? realpath( $uploads_basedir ) : false;
        $ok = [];
        $missing = [];

        foreach ( array_keys( $files_map ) as $rel ) {
            $rel = ltrim( (string) $rel, '/' );
            if ( $rel === '' ) continue;

            $abs  = wp_normalize_path( trailingslashit( $uploads_basedir ) . $rel );
            $real = realpath( $abs );

            if ( $uploads_real && $real && strpos( wp_normalize_path( $real ), wp_normalize_path( $uploads_real ) ) === 0 && is_file( $real ) ) {
                $ok[] = $rel;
            } else {
                $missing[] = $rel;
            }
        }

        $payload = [
            'type'          => 'contracts',
            'version'       => 2,
            'exported'      => current_time( 'c' ),
            'count'         => count( $items ),
            'files'         => array_values( $ok ),
            'files_missing' => array_values( $missing ),
            'items'         => $items,
        ];

        return [ $payload, $ok ];
    }

    private static function download_zip( $zip_filename, $json_filename, $payload, $uploads_basedir, array $file_relpaths ) {

        $tmp = wp_tempnam( 'woc-contracts-bundle' );
        if ( ! $tmp ) {
            $tmp = tempnam( sys_get_temp_dir(), 'woc' );
        }
        if ( ! $tmp ) {
            wp_die( '無法建立暫存檔，ZIP 產生失敗。' );
        }
        @unlink( $tmp );
        $tmp_zip = $tmp . '.zip';

        $json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        if ( $json === false ) {
            wp_die( 'JSON 編碼失敗，ZIP 產生中止。' );
        }

        $uploads_real = realpath( $uploads_basedir );

        if ( class_exists( 'ZipArchive' ) ) {

            $zip = new ZipArchive();
            $res = $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
            if ( $res !== true ) {
                @unlink( $tmp_zip );
                wp_die( 'ZIP 建立失敗（ZipArchive open error: ' . (int) $res . '）。' );
            }

            $zip->addFromString( $json_filename, $json );

            foreach ( $file_relpaths as $rel ) {
                $rel = ltrim( (string) $rel, '/' );
                if ( $rel === '' ) continue;

                $abs  = wp_normalize_path( trailingslashit( $uploads_basedir ) . $rel );
                $real = realpath( $abs );

                if ( $uploads_real && $real && strpos( wp_normalize_path( $real ), wp_normalize_path( $uploads_real ) ) === 0 && is_file( $real ) ) {
                    $zip->addFile( $real, $rel );
                }
            }

            $zip->close();

        } else {

            if ( ! class_exists( 'PclZip' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }

            $tmp_dir = trailingslashit( $uploads_basedir ) . 'woc-zip-' . wp_generate_password( 10, false, false );
            if ( ! wp_mkdir_p( $tmp_dir ) ) {
                @unlink( $tmp_zip );
                wp_die( '無法建立暫存資料夾，ZIP 產生失敗。' );
            }

            $json_path = trailingslashit( $tmp_dir ) . $json_filename;
            $w = file_put_contents( $json_path, $json );
            if ( $w === false ) {
                self::rrmdir( $tmp_dir );
                @unlink( $tmp_zip );
                wp_die( '寫入 JSON 失敗，ZIP 產生中止。' );
            }

            $archive = new PclZip( $tmp_zip );

            $r = $archive->create(
                $json_path,
                PCLZIP_OPT_REMOVE_PATH, $tmp_dir
            );

            if ( $r === 0 ) {
                self::rrmdir( $tmp_dir );
                @unlink( $tmp_zip );
                wp_die( 'ZIP 建立失敗（PclZip）：' . esc_html( $archive->errorInfo( true ) ) );
            }

            $abs_files = [];
            foreach ( $file_relpaths as $rel ) {
                $rel = ltrim( (string) $rel, '/' );
                if ( $rel === '' ) continue;

                $abs  = wp_normalize_path( trailingslashit( $uploads_basedir ) . $rel );
                $real = realpath( $abs );

                if ( $uploads_real && $real && strpos( wp_normalize_path( $real ), wp_normalize_path( $uploads_real ) ) === 0 && is_file( $real ) ) {
                    $abs_files[] = $real;
                }
            }

            if ( ! empty( $abs_files ) ) {
                $remove_base = $uploads_real ? $uploads_real : $uploads_basedir;

                $r2 = $archive->add(
                    $abs_files,
                    PCLZIP_OPT_REMOVE_PATH, $remove_base
                );

                if ( $r2 === 0 ) {
                    self::rrmdir( $tmp_dir );
                    @unlink( $tmp_zip );
                    wp_die( 'ZIP 加入簽名檔失敗（PclZip）：' . esc_html( $archive->errorInfo( true ) ) );
                }
            }

            self::rrmdir( $tmp_dir );
        }

        while ( function_exists('ob_get_level') && ob_get_level() ) { @ob_end_clean(); }

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename=' . $zip_filename );
        header( 'Content-Length: ' . filesize( $tmp_zip ) );

        readfile( $tmp_zip );
        @unlink( $tmp_zip );
        exit;
    }
}
