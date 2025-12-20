<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOC_Contracts_Backup {

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

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );

        $export_contracts_zip = wp_nonce_url(
            admin_url( 'admin-post.php?action=woc_export_json&type=contracts_zip' ),
            self::NONCE_EXPORT
        );

        $export_contracts_json = wp_nonce_url(
            admin_url( 'admin-post.php?action=woc_export_json&type=contracts' ),
            self::NONCE_EXPORT
        );

        $export_templates = wp_nonce_url(
            admin_url( 'admin-post.php?action=woc_export_json&type=templates' ),
            self::NONCE_EXPORT
        );
        $export_vars = wp_nonce_url(
            admin_url( 'admin-post.php?action=woc_export_json&type=vars' ),
            self::NONCE_EXPORT
        );

        ?>
        <div class="wrap">
            <h1>備份 / 匯入匯出</h1>

            <h2>匯出</h2>
            <p>合約預設匯出為 ZIP（含 JSON + 簽名檔）；範本/變數仍為 JSON。</p>

            <p>
                <a class="button button-primary" href="<?php echo esc_url( $export_contracts_zip ); ?>">匯出合約（ZIP）</a>
                <a class="button" href="<?php echo esc_url( $export_templates ); ?>">匯出範本（JSON）</a>
                <a class="button" href="<?php echo esc_url( $export_vars ); ?>">匯出變數（JSON）</a>

                <a class="button button-link-delete" style="margin-left:10px;" href="<?php echo esc_url( $export_contracts_json ); ?>">（備援）匯出合約（JSON）</a>
            </p>

            <p style="margin-top:10px;color:#666;">
                合約 ZIP 內含 <code>contracts.json</code> + <code>uploads</code> 相對路徑的簽名檔（<code>woc-signatures/...</code>）。<br>
                若某些簽名檔在來源站本來就缺檔，JSON 會列在 <code>files_missing</code> 方便你追。
            </p>

            <hr>

            <h2>匯入</h2>
            <p>上傳 JSON 或 ZIP（ZIP 會自動解壓簽名檔到 <code>uploads</code>，並讀取內部 JSON 匯入）。</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( self::NONCE_IMPORT, 'woc_import_nonce' ); ?>
                <input type="hidden" name="action" value="woc_import_json">

                <p>
                    <input type="file" name="woc_json_file" accept=".json,.zip,application/json,application/zip" required>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="woc_import_overwrite" value="1">
                        若目標站已存在同 uuid，允許覆蓋更新（不勾＝只新增缺的，避免蓋掉另一邊新資料）
                    </label>
                </p>

                <p>
                    <button type="submit" class="button button-primary">開始匯入</button>
                </p>
            </form>
        </div>
        <?php
    }

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

    public static function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );
        $nonce = isset($_POST['woc_import_nonce']) ? wp_unslash($_POST['woc_import_nonce']) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE_IMPORT ) ) {
            wp_die( 'Nonce 驗證失敗。' );
        }

        if ( empty( $_FILES['woc_json_file'] ) || ! isset( $_FILES['woc_json_file']['tmp_name'] ) ) {
            wp_die( '未上傳檔案。' );
        }
        if ( ! empty( $_FILES['woc_json_file']['error'] ) ) {
            wp_die( '上傳失敗（' . (int) $_FILES['woc_json_file']['error'] . '）。' );
        }

        $name = isset($_FILES['woc_json_file']['name']) ? (string) $_FILES['woc_json_file']['name'] : '';
        $tmp  = (string) $_FILES['woc_json_file']['tmp_name'];
        $size = isset($_FILES['woc_json_file']['size']) ? (int) $_FILES['woc_json_file']['size'] : 0;

        if ( $size <= 0 ) wp_die('檔案大小異常。');

        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

        if ( function_exists('wp_raise_memory_limit') ) {
            wp_raise_memory_limit('admin');
        }
        @set_time_limit(120);

        $overwrite = ! empty( $_POST['woc_import_overwrite'] );

        if ( $ext === 'zip' ) {

            if ( $size > self::MAX_IMPORT_ZIP_BYTES ) {
                wp_die('ZIP 檔過大，已拒絕（上限 100MB）。');
            }

            $data = self::read_bundle_zip_to_data( $tmp );

        } else {

            if ( $size > self::MAX_IMPORT_JSON_BYTES ) {
                wp_die('JSON 檔過大，已拒絕（上限 10MB）。');
            }

            $raw = file_get_contents( $tmp );
            if ( $raw === false || $raw === '' ) {
                wp_die( '讀取 JSON 失敗或內容空白。' );
            }

            if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
                $raw = substr( $raw, 3 );
            }

            try {
                $data = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
            } catch ( Throwable $e ) {
                wp_die( 'JSON 格式錯誤：' . esc_html( $e->getMessage() ) );
            }
        }

        $type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : '';
        if ( ! $type ) wp_die( 'JSON 缺少 type。' );

        if ( ! class_exists( 'WOC_Contracts_CPT' ) ) wp_die( 'CPT 尚未載入。' );

        switch ( $type ) {
            case 'vars':
                self::import_vars( $data );
                break;
            case 'templates':
                self::import_posts( $data, WOC_Contracts_CPT::POST_TYPE_TEMPLATE, $overwrite );
                break;
            case 'contracts':
                self::import_posts( $data, WOC_Contracts_CPT::POST_TYPE_CONTRACT, $overwrite );
                break;
            default:
                wp_die( '未知 type：' . esc_html( $type ) );
        }

        wp_safe_redirect( admin_url( 'edit.php?post_type=' . WOC_Contracts_CPT::POST_TYPE_CONTRACT . '&page=woc-backup&imported=1' ) );
        exit;
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

    private static function read_bundle_zip_to_data( $zip_tmp_path ) {

        $upload  = wp_upload_dir();
        $basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
        if ( ! $basedir || ! is_dir( $basedir ) ) {
            wp_die( 'uploads 目錄不存在，無法解壓簽名檔。' );
        }

        if ( class_exists( 'ZipArchive' ) ) {

            $zip = new ZipArchive();
            $res = $zip->open( $zip_tmp_path );
            if ( $res !== true ) {
                wp_die( 'ZIP 開啟失敗（' . (int) $res . '）。' );
            }

            $json_candidates = [];

            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $stat = $zip->statIndex( $i );
                if ( ! $stat || empty( $stat['name'] ) ) continue;

                $name = self::normalize_zip_name( (string) $stat['name'] );
                if ( $name === '' ) continue;
                if ( substr( $name, -1 ) === '/' ) continue;

                if ( preg_match( '/\.json$/i', $name ) ) {
                    $json_candidates[] = $name;
                    continue;
                }

                if ( strpos( $name, 'woc-signatures/' ) === 0 ) {
                    $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
                    if ( ! in_array( $ext, [ 'png', 'jpg', 'jpeg', 'webp' ], true ) ) {
                        continue;
                    }
                    self::zip_extract_entry_to_uploads( $zip, $name, $basedir );
                }
            }

            $json_name = '';
            if ( in_array( 'contracts.json', $json_candidates, true ) ) {
                $json_name = 'contracts.json';
            } elseif ( ! empty( $json_candidates ) ) {
                $json_name = $json_candidates[0];
            }

            if ( $json_name === '' ) {
                $zip->close();
                wp_die( 'ZIP 內找不到 JSON（建議放 contracts.json）。' );
            }

            $raw = $zip->getFromName( $json_name );
            $zip->close();

            if ( $raw === false || $raw === '' ) {
                wp_die( '讀取 ZIP 內 JSON 失敗或內容空白。' );
            }

            if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
                $raw = substr( $raw, 3 );
            }

            try {
                return json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
            } catch ( Throwable $e ) {
                wp_die( 'ZIP 內 JSON 格式錯誤：' . esc_html( $e->getMessage() ) );
            }
        }

        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $archive = new PclZip( $zip_tmp_path );
        $contents = $archive->listContent();
        if ( $contents === 0 || ! is_array( $contents ) ) {
            wp_die( 'ZIP 讀取失敗（PclZip）：' . esc_html( $archive->errorInfo( true ) ) );
        }

        $json_candidates = [];
        foreach ( $contents as $c ) {
            $n = '';
            if ( isset( $c['stored_filename'] ) ) $n = (string) $c['stored_filename'];
            elseif ( isset( $c['filename'] ) ) $n = (string) $c['filename'];

            $n = self::normalize_zip_name( $n );
            if ( $n === '' ) continue;
            if ( ! empty( $c['folder'] ) ) continue;
            if ( substr( $n, -1 ) === '/' ) continue;

            if ( preg_match( '/\.json$/i', $n ) ) {
                $json_candidates[] = $n;
            }
        }

        $json_name = '';
        if ( in_array( 'contracts.json', $json_candidates, true ) ) {
            $json_name = 'contracts.json';
        } elseif ( ! empty( $json_candidates ) ) {
            $json_name = $json_candidates[0];
        }

        if ( $json_name === '' ) {
            wp_die( 'ZIP 內找不到 JSON（建議放 contracts.json）。' );
        }

        $json_extract = $archive->extract(
            PCLZIP_OPT_BY_NAME, $json_name,
            PCLZIP_OPT_EXTRACT_AS_STRING
        );

        if ( $json_extract === 0 || empty( $json_extract[0]['content'] ) ) {
            wp_die( '讀取 ZIP 內 JSON 失敗（PclZip）：' . esc_html( $archive->errorInfo( true ) ) );
        }

        $raw = (string) $json_extract[0]['content'];

        if ( $raw === '' ) {
            wp_die( '讀取 ZIP 內 JSON 失敗或內容空白。' );
        }

        if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw = substr( $raw, 3 );
        }

        $tmp_dir = trailingslashit( $basedir ) . 'woc-import-' . wp_generate_password( 10, false, false );
        if ( ! wp_mkdir_p( $tmp_dir ) ) {
            wp_die( '無法建立暫存資料夾，ZIP 解壓失敗。' );
        }

        $pat = '#^woc-signatures/(?!.*\.\.).+\.(png|jpe?g|webp)$#i';
        $sig_extract = $archive->extract(
            PCLZIP_OPT_PATH, $tmp_dir,
            PCLZIP_OPT_BY_PREG, $pat,
            PCLZIP_OPT_REPLACE_NEWER
        );

        if ( $sig_extract !== 0 ) {
            $sig_src = trailingslashit( $tmp_dir ) . 'woc-signatures';
            if ( is_dir( $sig_src ) ) {
                self::copy_signatures_dir_to_uploads( $sig_src, $basedir );
            }
        }

        self::rrmdir( $tmp_dir );

        try {
            return json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
        } catch ( Throwable $e ) {
            wp_die( 'ZIP 內 JSON 格式錯誤：' . esc_html( $e->getMessage() ) );
        }
    }

    private static function normalize_zip_name( $name ) {
        $name = str_replace( '\\', '/', (string) $name );
        $name = ltrim( $name, '/' );

        if ( $name === '' ) return '';
        if ( strpos( $name, '../' ) !== false || strpos( $name, '/..' ) !== false || strpos( $name, '..\\' ) !== false ) return '';
        if ( preg_match( '/^[a-zA-Z]:\//', $name ) ) return '';
        if ( strpos( $name, "\0" ) !== false ) return '';

        return $name;
    }

    private static function copy_signatures_dir_to_uploads( $sig_src_dir, $uploads_basedir ) {

        $uploads_real = realpath( $uploads_basedir );
        $src_real     = realpath( $sig_src_dir );
        if ( ! $uploads_real || ! $src_real ) return;

        $dest_root = wp_normalize_path( trailingslashit( $uploads_basedir ) . 'woc-signatures' );
        if ( ! wp_mkdir_p( $dest_root ) ) return;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $sig_src_dir, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $it as $f ) {
            if ( ! $f->isFile() ) continue;

            $ext = strtolower( pathinfo( $f->getFilename(), PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, [ 'png', 'jpg', 'jpeg', 'webp' ], true ) ) continue;

            $src_path = $f->getPathname();
            $src_real_file = realpath( $src_path );
            if ( ! $src_real_file ) continue;

            $src_norm = wp_normalize_path( $src_real_file );
            $src_base = wp_normalize_path( $src_real );

            if ( strpos( $src_norm, $src_base ) !== 0 ) continue;

            $rel = ltrim( substr( $src_norm, strlen( $src_base ) ), '/' );

            $dest = wp_normalize_path( trailingslashit( $dest_root ) . $rel );
            $dest_dir = wp_normalize_path( dirname( $dest ) );

            if ( strpos( $dest_dir, wp_normalize_path( $uploads_real ) ) !== 0 ) continue;

            if ( ! wp_mkdir_p( $dest_dir ) ) continue;

            @copy( $src_path, $dest );

            if ( function_exists( 'wp_chmod' ) ) {
                @wp_chmod( $dest );
            }
        }
    }

    private static function rrmdir( $dir ) {
        $dir = (string) $dir;
        if ( $dir === '' || ! is_dir( $dir ) ) return;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $it as $f ) {
            if ( $f->isDir() ) {
                @rmdir( $f->getPathname() );
            } else {
                @unlink( $f->getPathname() );
            }
        }
        @rmdir( $dir );
    }

    private static function zip_extract_entry_to_uploads( $zip, $entry_name, $uploads_basedir ) {
        if ( ! class_exists( 'ZipArchive' ) || ! ( $zip instanceof ZipArchive ) ) return;

        $entry_name = self::normalize_zip_name( $entry_name );
        if ( $entry_name === '' ) return;

        $dest = wp_normalize_path( trailingslashit( $uploads_basedir ) . $entry_name );

        $uploads_real = realpath( $uploads_basedir );
        $dest_dir = wp_normalize_path( dirname( $dest ) );

        if ( $uploads_real && strpos( $dest_dir, wp_normalize_path( $uploads_real ) ) !== 0 ) {
            return;
        }

        if ( ! wp_mkdir_p( $dest_dir ) ) {
            return;
        }

        $stream = $zip->getStream( $entry_name );
        if ( ! $stream ) return;

        $out = fopen( $dest, 'wb' );
        if ( ! $out ) {
            fclose( $stream );
            return;
        }

        while ( ! feof( $stream ) ) {
            $buf = fread( $stream, 8192 );
            if ( $buf === false ) break;
            fwrite( $out, $buf );
        }

        fclose( $out );
        fclose( $stream );

        if ( function_exists( 'wp_chmod' ) ) {
            @wp_chmod( $dest );
        }
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

    private static function import_vars( array $data ) {
        if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
            wp_die( 'vars JSON items 格式不正確。' );
        }

        $incoming = $data['items'];

        $current  = get_option( 'woc_contract_global_vars', [] );
        if ( ! is_array( $current ) ) $current = [];

        $parsed = [];

        foreach ( $incoming as $k => $row ) {

            if ( is_int( $k ) && is_array( $row ) && isset( $row['key'] ) ) {
                $key = sanitize_key( (string) $row['key'] );
                if ( $key === '' ) continue;

                $parsed[ $key ] = [
                    'label' => isset( $row['label'] ) ? (string) $row['label'] : '',
                    'value' => $row['value'] ?? '',
                ];
                continue;
            }

            if ( ! is_string( $k ) || $k === '' ) continue;
            if ( ! is_array( $row ) ) continue;

            $key = sanitize_key( (string) $k );
            if ( $key === '' ) continue;

            $parsed[ $key ] = [
                'label' => isset( $row['label'] ) ? (string) $row['label'] : '',
                'value' => $row['value'] ?? '',
            ];
        }

        if ( empty( $parsed ) ) {
            wp_die( 'vars JSON 沒有任何可匯入資料，已取消（避免洗掉原本資料）。' );
        }

        foreach ( $parsed as $k => $row ) {
            $current[ $k ] = $row;
        }

        update_option( 'woc_contract_global_vars', $current );
    }

    private static function import_posts( array $data, $post_type, $overwrite ) {
        if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
            wp_die( 'JSON items 空或格式錯誤。' );
        }

        global $wpdb;

        $upload  = wp_upload_dir();
        $baseurl = isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '';

        $existing_uuid_to_id = [];
        $statuses = [ 'publish', 'draft', 'pending', 'private', 'trash' ];
        $in_status = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

        $uuid_key = self::uuid_key();

        $sql_existing = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = %s
               AND p.post_status IN ($in_status)",
            $uuid_key,
            $post_type
        );
        $rows_existing = $wpdb->get_results( $sql_existing ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        if ( $rows_existing ) {
            foreach ( $rows_existing as $r ) {
                $u = is_string( $r->meta_value ) ? trim( $r->meta_value ) : '';
                if ( $u !== '' ) {
                    $existing_uuid_to_id[ $u ] = (int) $r->post_id;
                }
            }
        }

        // 合約：額外用 view_token 當去重 key（處理缺 uuid 的歷史資料）
        $existing_vt_to_id = [];
        if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
            $sql_vt = $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND p.post_type = %s
                   AND p.post_status IN ($in_status)",
                WOC_Contracts_CPT::META_VIEW_TOKEN,
                $post_type
            );
            $rows_vt = $wpdb->get_results( $sql_vt ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            if ( $rows_vt ) {
                foreach ( $rows_vt as $r ) {
                    $vt = is_string( $r->meta_value ) ? trim( $r->meta_value ) : '';
                    if ( $vt !== '' && ! isset( $existing_vt_to_id[ $vt ] ) ) {
                        $existing_vt_to_id[ $vt ] = (int) $r->post_id;
                    }
                }
            }
        }

        $tpl_uuid_to_id = [];
        $tpl_title_to_id = [];
        $tpl_title_dupe  = [];

        if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {

            $tpl_types = self::get_template_post_types();
            $in_tpl_types = "'" . implode( "','", array_map( 'esc_sql', $tpl_types ) ) . "'";

            $sql_tpl_uuid = "
                SELECT pm.post_id, pm.meta_value
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '{$uuid_key}'
                  AND p.post_type IN ($in_tpl_types)
                  AND p.post_status IN ($in_status)
            ";
            $rows_tpl_uuid = $wpdb->get_results( $sql_tpl_uuid ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            if ( $rows_tpl_uuid ) {
                foreach ( $rows_tpl_uuid as $r ) {
                    $u = is_string( $r->meta_value ) ? trim( $r->meta_value ) : '';
                    if ( $u !== '' ) {
                        $tpl_uuid_to_id[ $u ] = (int) $r->post_id;
                    }
                }
            }

            $sql_tpl_title = "
                SELECT ID, post_title
                FROM {$wpdb->posts}
                WHERE post_type IN ($in_tpl_types)
                  AND post_status IN ($in_status)
            ";
            $rows_tpl_title = $wpdb->get_results( $sql_tpl_title ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            if ( $rows_tpl_title ) {
                foreach ( $rows_tpl_title as $r ) {
                    $t  = is_string( $r->post_title ) ? trim( $r->post_title ) : '';
                    $id = (int) $r->ID;
                    if ( $t === '' ) continue;

                    if ( isset( $tpl_title_to_id[ $t ] ) ) {
                        unset( $tpl_title_to_id[ $t ] );
                        $tpl_title_dupe[ $t ] = true;
                        continue;
                    }
                    if ( isset( $tpl_title_dupe[ $t ] ) ) {
                        continue;
                    }
                    $tpl_title_to_id[ $t ] = $id;
                }
            }
        }

        foreach ( $data['items'] as $item ) {
            if ( ! is_array( $item ) ) continue;

            $post = ( isset( $item['post'] ) && is_array( $item['post'] ) ) ? $item['post'] : [];
            $meta = ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) ? $item['meta'] : [];

            if ( empty( $post ) ) continue;

            $uuid = isset( $item['uuid'] ) ? trim( (string) $item['uuid'] ) : '';
            if ( $uuid === '' && isset( $meta[ $uuid_key ] ) ) {
                $uuid = trim( (string) $meta[ $uuid_key ] );
            }

            $existing_id = 0;

            // 先用 uuid 判斷是否已存在
            if ( $uuid !== '' && isset( $existing_uuid_to_id[ $uuid ] ) ) {
                $existing_id = (int) $existing_uuid_to_id[ $uuid ];
            }

            // 合約：若 uuid 缺，改用 view_token 做去重
            $vt = '';
            if ( ! $existing_id && $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
                if ( isset( $meta[ WOC_Contracts_CPT::META_VIEW_TOKEN ] ) ) {
                    $vt = trim( (string) $meta[ WOC_Contracts_CPT::META_VIEW_TOKEN ] );
                }
                if ( $vt !== '' && isset( $existing_vt_to_id[ $vt ] ) ) {
                    $existing_id = (int) $existing_vt_to_id[ $vt ];

                    // 若既有資料沒 uuid，補一個穩定 uuid（用 view_token）
                    if ( $uuid === '' ) {
                        $uuid = (string) self::get_or_create_uuid( $existing_id, $post_type );
                    }
                }
            }

            // 最後保底：title + post_date_gmt（只在 uuid/view_token 都缺時）
            if ( ! $existing_id && $uuid === '' && $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
                $t = isset( $post['post_title'] ) ? trim( (string) $post['post_title'] ) : '';
                $d = isset( $post['post_date_gmt'] ) ? trim( (string) $post['post_date_gmt'] ) : '';
                if ( $t !== '' && $d !== '' ) {
                    $sql_match = $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                         WHERE post_type = %s
                           AND post_title = %s
                           AND post_date_gmt = %s
                           AND post_status IN ($in_status)
                         LIMIT 2",
                        $post_type,
                        $t,
                        $d
                    );
                    $ids = $wpdb->get_col( $sql_match ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    if ( is_array( $ids ) && count( $ids ) === 1 ) {
                        $existing_id = (int) $ids[0];
                    }
                }
            }

            if ( $existing_id && ! $overwrite ) {
                continue;
            }

            if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT && isset( $meta[ WOC_Contracts_CPT::META_SIGNATURE_IMAGE ] ) ) {
                unset( $meta[ WOC_Contracts_CPT::META_SIGNATURE_IMAGE ] );
            }

            // 合約：範本綁定（用 template.uuid/title 映射到目標站）
            if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {

                $tpl_uuid  = '';
                $tpl_title = '';

                if ( isset( $item['template'] ) && is_array( $item['template'] ) ) {
                    $tpl_uuid  = isset( $item['template']['uuid'] )  ? trim( (string) $item['template']['uuid'] )  : '';
                    $tpl_title = isset( $item['template']['title'] ) ? trim( (string) $item['template']['title'] ) : '';
                }

                $mapped_tpl_id = 0;

                if ( $tpl_uuid !== '' && isset( $tpl_uuid_to_id[ $tpl_uuid ] ) ) {
                    $mapped_tpl_id = (int) $tpl_uuid_to_id[ $tpl_uuid ];
                }

                if ( ! $mapped_tpl_id && $tpl_title !== '' && isset( $tpl_title_to_id[ $tpl_title ] ) ) {
                    $mapped_tpl_id = (int) $tpl_title_to_id[ $tpl_title ];
                }

                if ( ! $mapped_tpl_id && $existing_id ) {
                    $current_tpl_id = (int) get_post_meta( $existing_id, WOC_Contracts_CPT::META_TEMPLATE_ID, true );
                    if ( $current_tpl_id > 0 ) {
                        $pt = (string) get_post_type( $current_tpl_id );
                        if ( $pt && in_array( $pt, self::get_template_post_types(), true ) ) {
                            $mapped_tpl_id = $current_tpl_id;
                        }
                    }
                }

                if ( $mapped_tpl_id ) {
                    $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ] = (string) $mapped_tpl_id;
                } else {
                    if ( isset( $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ] ) ) {
                        $meta['_woc_backup_template_id'] = (string) $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ];
                        unset( $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ] );
                    }
                    if ( $tpl_uuid )  $meta['_woc_backup_template_uuid']  = $tpl_uuid;
                    if ( $tpl_title ) $meta['_woc_backup_template_title'] = $tpl_title;
                }

                // 合約 uuid：缺的話用 view_token 派生（同檔重匯可去重）
                if ( $uuid === '' ) {
                    if ( $vt === '' && isset( $meta[ WOC_Contracts_CPT::META_VIEW_TOKEN ] ) ) {
                        $vt = trim( (string) $meta[ WOC_Contracts_CPT::META_VIEW_TOKEN ] );
                    }
                    if ( $vt !== '' ) {
                        $uuid = 'vt-' . sha1( $vt );
                    } else {
                        $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ( 'u-' . sha1( uniqid( '', true ) ) );
                    }
                }

                $meta[ $uuid_key ] = $uuid;
            } else {
                // 範本 uuid：缺就補
                if ( $uuid === '' ) {
                    $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ( 'u-' . sha1( uniqid( '', true ) ) );
                }
                $meta[ $uuid_key ] = $uuid;
            }

            $postarr = [
                'ID'           => $existing_id,
                'post_type'    => $post_type,
                'post_title'   => isset( $post['post_title'] ) ? $post['post_title'] : '',
                'post_content' => isset( $post['post_content'] ) ? $post['post_content'] : '',
                'post_excerpt' => isset( $post['post_excerpt'] ) ? $post['post_excerpt'] : '',
                'post_status'  => isset( $post['post_status'] ) ? $post['post_status'] : 'publish',
                'post_password'=> isset( $post['post_password'] ) ? $post['post_password'] : '',
            ];

            if ( ! empty( $post['post_date_gmt'] ) )     $postarr['post_date_gmt'] = $post['post_date_gmt'];
            if ( ! empty( $post['post_modified_gmt'] ) ) $postarr['post_modified_gmt'] = $post['post_modified_gmt'];

            $postarr = wp_slash( $postarr );

            $new_id = wp_insert_post( $postarr, true );
            if ( is_wp_error( $new_id ) ) {
                continue;
            }
            $new_id = (int) $new_id;

            foreach ( $meta as $k => $v ) {
                if ( ! is_string( $k ) || $k === '' ) continue;
                if ( strpos( $k, '_woc_' ) !== 0 && $k !== $uuid_key && $k !== '_woc_backup_uuid' ) continue;
                update_post_meta( $new_id, $k, $v );
            }

            // 一律寫回 uuid（確保後續匯入能對到）
            if ( $uuid !== '' ) {
                update_post_meta( $new_id, $uuid_key, $uuid );
                $existing_uuid_to_id[ $uuid ] = $new_id;
            }

            if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
                if ( $vt === '' && isset( $meta[ WOC_Contracts_CPT::META_VIEW_TOKEN ] ) ) {
                    $vt = trim( (string) $meta[ WOC_Contracts_CPT::META_VIEW_TOKEN ] );
                }
                if ( $vt !== '' ) {
                    $existing_vt_to_id[ $vt ] = $new_id;
                }

                if ( isset( $item['signature'] ) && is_array( $item['signature'] ) ) {

                    if ( ! empty( $item['signature']['upload_relpath'] ) && $baseurl ) {
                        $rel = ltrim( (string) $item['signature']['upload_relpath'], '/' );
                        $url = trailingslashit( $baseurl ) . $rel;
                        update_post_meta( $new_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, esc_url_raw( $url ) );

                    } elseif ( ! empty( $item['signature']['url'] ) ) {
                        $u = (string) $item['signature']['url'];
                        if ( strpos( $u, 'data:image/' ) !== 0 ) {
                            update_post_meta( $new_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, esc_url_raw( $u ) );
                        }
                    }
                }
            }
        }
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
