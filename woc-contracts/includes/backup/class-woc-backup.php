<?php
/**
 * WOC Contracts Backup (Entry)
 * - 入口檔：載入備份/匯入匯出相關 traits，定義 WOC_Contracts_Backup class，並呼叫 init()
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/trait-woc-page.php';
require_once __DIR__ . '/trait-woc-export.php';
require_once __DIR__ . '/trait-woc-import.php';

class WOC_Contracts_Backup {

    use WOC_Contracts_Backup_Page;
    use WOC_Contracts_Backup_Export;
    use WOC_Contracts_Backup_Import;

    const NONCE_EXPORT = 'woc_export_json';
    const NONCE_IMPORT = 'woc_import_json';

    // 匯入上限
    const MAX_IMPORT_JSON_BYTES = 10485760;   // 10MB
    const MAX_IMPORT_ZIP_BYTES  = 104857600;  // 100MB

    // 舊版 WP Online Contract 範本 CPT（相容用）
    const LEGACY_POST_TYPE_TEMPLATE = 'woc_contract_template';

    private static function uuid_key() {
        return '_woc_uuid';
    }

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );

        add_action( 'admin_post_woc_export_json', [ __CLASS__, 'handle_export' ] );
        add_action( 'admin_post_woc_import_json', [ __CLASS__, 'handle_import' ] );
    }

    public static function register_menu() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! class_exists( 'WOC_Contracts_CPT' ) ) return;

        $parent_slug = 'edit.php?post_type=' . WOC_Contracts_CPT::POST_TYPE_CONTRACT;

        add_submenu_page(
            $parent_slug,
            '備份 / 匯入匯出',
            '備份 / 匯入匯出',
            'manage_options',
            'woc-backup',
            [ __CLASS__, 'render_page' ]
        );
    }

    private static function get_template_post_types() {
        if ( ! class_exists( 'WOC_Contracts_CPT' ) ) {
            return [ self::LEGACY_POST_TYPE_TEMPLATE ];
        }
        $types = [ (string) WOC_Contracts_CPT::POST_TYPE_TEMPLATE ];
        if ( self::LEGACY_POST_TYPE_TEMPLATE && self::LEGACY_POST_TYPE_TEMPLATE !== WOC_Contracts_CPT::POST_TYPE_TEMPLATE ) {
            $types[] = self::LEGACY_POST_TYPE_TEMPLATE;
        }
        $types = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
        return $types;
    }

    private static function get_or_create_uuid( $post_id, $post_type = '' ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) return '';

        $key = self::uuid_key();
        $uuid = get_post_meta( $post_id, $key, true );
        $uuid = is_string( $uuid ) ? trim( $uuid ) : '';

        if ( $uuid !== '' ) return $uuid;

        if ( $post_type === '' ) {
            $post_type = (string) get_post_type( $post_id );
        }

        // 合約：優先用 view_token 做穩定 uuid（避免同檔重匯一直新增）
        if ( class_exists( 'WOC_Contracts_CPT' ) && $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            $vt = get_post_meta( $post_id, WOC_Contracts_CPT::META_VIEW_TOKEN, true );
            $vt = is_string( $vt ) ? trim( $vt ) : '';
            if ( $vt !== '' ) {
                $uuid = 'vt-' . sha1( $vt );
            }
        }

        if ( $uuid === '' ) {
            $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ( 'u-' . sha1( uniqid( '', true ) ) );
        }

        update_post_meta( $post_id, $key, $uuid );
        return $uuid;
    }

    private static function pick_meta( $post_id ) {

        $allow = [
            WOC_Contracts_CPT::META_TEMPLATE_ID,
            WOC_Contracts_CPT::META_STATUS,
            WOC_Contracts_CPT::META_VIEW_TOKEN,
            WOC_Contracts_CPT::META_SIGNED_AT,
            WOC_Contracts_CPT::META_SIGNED_IP,
            WOC_Contracts_CPT::META_SIGNATURE_IMAGE,
            '_woc_uuid',
            '_woc_backup_uuid',
        ];

        if ( defined( 'WOC_Contracts_CPT::META_AUDIT_LOG' ) ) {
            $allow[] = WOC_Contracts_CPT::META_AUDIT_LOG;
        }

        $out = [];
        foreach ( $allow as $k ) {
            $v = get_post_meta( $post_id, $k, true );
            if ( $v !== '' && $v !== null ) {
                $out[ $k ] = $v;
            }
        }
        return $out;
    }

    private static function download_json( $filename, $data ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        if ( $json === false ) {
            wp_die( 'JSON 編碼失敗。' );
        }

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json;
        exit;
    }
}

WOC_Contracts_Backup::init();
