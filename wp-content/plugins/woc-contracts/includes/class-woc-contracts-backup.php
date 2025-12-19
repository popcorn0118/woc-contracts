<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_Backup {

    const META_UUID = '_woc_uuid';

    const EXPORT_TYPE_CONTRACTS = 'contracts';
    const EXPORT_TYPE_TEMPLATES = 'templates';
    const EXPORT_TYPE_VARS      = 'vars';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 20 );

        // Export
        add_action( 'admin_post_woc_export_contracts', [ __CLASS__, 'handle_export_contracts' ] );
        add_action( 'admin_post_woc_export_templates', [ __CLASS__, 'handle_export_templates' ] );
        add_action( 'admin_post_woc_export_vars',      [ __CLASS__, 'handle_export_vars' ] );

        // Import
        add_action( 'admin_post_woc_import_json', [ __CLASS__, 'handle_import_json' ] );

        // 每站固定 site_id（用於識別來源，可不必先用到）
        add_action( 'init', [ __CLASS__, 'ensure_site_id' ], 20 );
    }

    public static function ensure_site_id() {
        if ( ! get_option( 'woc_site_id' ) ) {
            update_option( 'woc_site_id', wp_generate_uuid4() );
        }
    }

    public static function register_menu() {
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '權限不足。' );
        }

        $export_contracts_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=woc_export_contracts' ),
            'woc_export_contracts'
        );
        $export_templates_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=woc_export_templates' ),
            'woc_export_templates'
        );
        $export_vars_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=woc_export_vars' ),
            'woc_export_vars'
        );

        $import_action = admin_url( 'admin-post.php?action=woc_import_json' );
        ?>
        <div class="wrap">
            <h1>備份 / 匯入匯出</h1>

            <h2>匯出</h2>
            <p>匯出為 JSON，可用於外掛環境導入。</p>

            <p>
                <a class="button button-primary" href="<?php echo esc_url( $export_contracts_url ); ?>">匯出合約（JSON）</a>
                <a class="button" href="<?php echo esc_url( $export_templates_url ); ?>">匯出範本（JSON）</a>
                <a class="button" href="<?php echo esc_url( $export_vars_url ); ?>">匯出變數（JSON）</a>
            </p>

            <hr>

            <h2>匯入</h2>
            <p>上傳 JSON（對應上面的匯出檔）。</p>

            <form method="post" action="<?php echo esc_url( $import_action ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'woc_import_json', 'woc_import_nonce' ); ?>
                <input type="file" name="woc_json_file" accept="application/json" required>
                <button type="submit" class="button button-primary">開始匯入</button>
            </form>
        </div>
        <?php
    }

    /* =========================================
     * Export
     * ========================================= */

    public static function handle_export_contracts() {
        self::must_be_admin();
        check_admin_referer( 'woc_export_contracts' );

        $args = [
            'post_type'      => WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ];

        $posts = get_posts( $args );

        $items = [];
        foreach ( $posts as $p ) {
            $contract_id = (int) $p->ID;

            // UUID：沒有就補，避免你之後要合併時沒 key
            $uuid = get_post_meta( $contract_id, self::META_UUID, true );
            if ( empty( $uuid ) ) {
                $uuid = wp_generate_uuid4();
                update_post_meta( $contract_id, self::META_UUID, $uuid );
            }

            $required_token = get_post_meta( $contract_id, WOC_Contracts_CPT::META_VIEW_TOKEN, true );
            $status         = get_post_meta( $contract_id, WOC_Contracts_CPT::META_STATUS, true );
            $signed_at      = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_AT, true );
            $signed_ip      = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_IP, true );
            $signature_url  = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );

            $signature_payload = self::read_signature_as_base64( $signature_url );

            $items[] = [
                'uuid' => $uuid,
                'post' => [
                    'post_title'        => $p->post_title,
                    'post_content'      => $p->post_content,
                    'post_status'       => $p->post_status,
                    'post_password'     => $p->post_password,
                    'post_date_gmt'     => $p->post_date_gmt,
                    'post_modified_gmt' => $p->post_modified_gmt,
                ],
                'meta' => [
                    WOC_Contracts_CPT::META_VIEW_TOKEN      => (string) $required_token,
                    WOC_Contracts_CPT::META_STATUS          => (string) $status,
                    WOC_Contracts_CPT::META_SIGNED_AT       => (string) $signed_at,
                    WOC_Contracts_CPT::META_SIGNED_IP       => (string) $signed_ip,
                    WOC_Contracts_CPT::META_SIGNATURE_IMAGE => (string) $signature_url,
                ],
                'signature' => $signature_payload, // null 或 ['filename'=>..., 'mime'=>..., 'base64'=>...]
            ];
        }

        $payload = [
            'type'      => self::EXPORT_TYPE_CONTRACTS,
            'version'   => 1,
            'site_id'   => (string) get_option( 'woc_site_id', '' ),
            'exported'  => current_time( 'mysql' ),
            'count'     => count( $items ),
            'items'     => $items,
        ];

        self::send_json_download( 'woc-contracts-' . date( 'Ymd-His' ) . '.json', $payload );
    }

    public static function handle_export_templates() {
        self::must_be_admin();
        check_admin_referer( 'woc_export_templates' );

        $posts = get_posts([
            'post_type'      => WOC_Contracts_CPT::POST_TYPE_TEMPLATE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        $items = [];
        foreach ( $posts as $p ) {
            $items[] = [
                'post' => [
                    'post_title'        => $p->post_title,
                    'post_content'      => $p->post_content,
                    'post_status'       => $p->post_status,
                    'post_date_gmt'     => $p->post_date_gmt,
                    'post_modified_gmt' => $p->post_modified_gmt,
                ],
            ];
        }

        $payload = [
            'type'      => self::EXPORT_TYPE_TEMPLATES,
            'version'   => 1,
            'site_id'   => (string) get_option( 'woc_site_id', '' ),
            'exported'  => current_time( 'mysql' ),
            'count'     => count( $items ),
            'items'     => $items,
        ];

        self::send_json_download( 'woc-templates-' . date( 'Ymd-His' ) . '.json', $payload );
    }

    public static function handle_export_vars() {
        self::must_be_admin();
        check_admin_referer( 'woc_export_vars' );

        $vars = get_option( 'woc_contract_global_vars', [] );
        if ( ! is_array( $vars ) ) {
            $vars = [];
        }

        $payload = [
            'type'      => self::EXPORT_TYPE_VARS,
            'version'   => 1,
            'site_id'   => (string) get_option( 'woc_site_id', '' ),
            'exported'  => current_time( 'mysql' ),
            'vars'      => $vars,
        ];

        self::send_json_download( 'woc-vars-' . date( 'Ymd-His' ) . '.json', $payload );
    }

    /* =========================================
     * Import
     * ========================================= */

    public static function handle_import_json() {
        self::must_be_admin();

        if (
            ! isset( $_POST['woc_import_nonce'] ) ||
            ! wp_verify_nonce( $_POST['woc_import_nonce'], 'woc_import_json' )
        ) {
            wp_die( 'nonce 錯誤。' );
        }

        if ( empty( $_FILES['woc_json_file']['tmp_name'] ) ) {
            wp_die( '沒有檔案。' );
        }

        $raw = file_get_contents( $_FILES['woc_json_file']['tmp_name'] );
        if ( $raw === false || $raw === '' ) {
            wp_die( '檔案讀取失敗。' );
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_die( 'JSON 格式錯誤。' );
        }

        $type = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
        if ( $type === self::EXPORT_TYPE_CONTRACTS ) {
            self::import_contracts( $data );
        } elseif ( $type === self::EXPORT_TYPE_TEMPLATES ) {
            self::import_templates( $data );
        } elseif ( $type === self::EXPORT_TYPE_VARS ) {
            self::import_vars( $data );
        } else {
            wp_die( '不支援的匯入檔類型。' );
        }

        wp_safe_redirect( admin_url( 'edit.php?post_type=' . WOC_Contracts_CPT::POST_TYPE_CONTRACT . '&page=woc-backup&imported=1' ) );
        exit;
    }

    private static function import_contracts( array $data ) {
        $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : [];
        if ( empty( $items ) ) return;

        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) continue;

            $uuid = isset( $row['uuid'] ) ? sanitize_text_field( $row['uuid'] ) : '';
            if ( $uuid === '' ) {
                // 最少也要補一個，避免重複匯入每次都新增
                $uuid = wp_generate_uuid4();
            }

            $existing_id = self::find_post_id_by_uuid( $uuid );

            $post = isset( $row['post'] ) && is_array( $row['post'] ) ? $row['post'] : [];
            $meta = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : [];
            $sig  = isset( $row['signature'] ) && is_array( $row['signature'] ) ? $row['signature'] : null;

            $incoming_status = isset( $meta[ WOC_Contracts_CPT::META_STATUS ] ) ? (string) $meta[ WOC_Contracts_CPT::META_STATUS ] : '';
            $incoming_signed = ( $incoming_status === 'signed' );

            if ( $existing_id ) {

                $local_status = get_post_meta( $existing_id, WOC_Contracts_CPT::META_STATUS, true );
                $local_signed = ( $local_status === 'signed' );

                // ✅ 已簽不可被未簽覆蓋
                if ( $local_signed && ! $incoming_signed ) {
                    continue;
                }

                // Update post fields
                wp_update_post([
                    'ID'           => $existing_id,
                    'post_title'   => isset( $post['post_title'] ) ? (string) $post['post_title'] : '',
                    'post_content' => isset( $post['post_content'] ) ? (string) $post['post_content'] : '',
                    'post_status'  => isset( $post['post_status'] ) ? (string) $post['post_status'] : 'publish',
                    'post_password'=> isset( $post['post_password'] ) ? (string) $post['post_password'] : '',
                ]);

                update_post_meta( $existing_id, self::META_UUID, $uuid );
                self::import_contract_meta_and_signature( $existing_id, $meta, $sig, $local_signed );

            } else {

                $new_id = wp_insert_post([
                    'post_type'    => WOC_Contracts_CPT::POST_TYPE_CONTRACT,
                    'post_title'   => isset( $post['post_title'] ) ? (string) $post['post_title'] : '',
                    'post_content' => isset( $post['post_content'] ) ? (string) $post['post_content'] : '',
                    'post_status'  => isset( $post['post_status'] ) ? (string) $post['post_status'] : 'publish',
                    'post_password'=> isset( $post['post_password'] ) ? (string) $post['post_password'] : '',
                ]);

                if ( is_wp_error( $new_id ) || ! $new_id ) {
                    continue;
                }

                update_post_meta( $new_id, self::META_UUID, $uuid );
                self::import_contract_meta_and_signature( $new_id, $meta, $sig, false );
            }
        }
    }

    private static function import_contract_meta_and_signature( $post_id, array $meta, $sig, $already_signed ) {

        $token = isset( $meta[ WOC_Contracts_CPT::META_VIEW_TOKEN ] ) ? (string) $meta[ WOC_Contracts_CPT::META_VIEW_TOKEN ] : '';
        $status = isset( $meta[ WOC_Contracts_CPT::META_STATUS ] ) ? (string) $meta[ WOC_Contracts_CPT::META_STATUS ] : '';
        $signed_at = isset( $meta[ WOC_Contracts_CPT::META_SIGNED_AT ] ) ? (string) $meta[ WOC_Contracts_CPT::META_SIGNED_AT ] : '';
        $signed_ip = isset( $meta[ WOC_Contracts_CPT::META_SIGNED_IP ] ) ? (string) $meta[ WOC_Contracts_CPT::META_SIGNED_IP ] : '';

        if ( $token !== '' ) {
            update_post_meta( $post_id, WOC_Contracts_CPT::META_VIEW_TOKEN, $token );
        }

        if ( $status !== '' ) {
            update_post_meta( $post_id, WOC_Contracts_CPT::META_STATUS, $status );
        }

        if ( $signed_at !== '' ) {
            update_post_meta( $post_id, WOC_Contracts_CPT::META_SIGNED_AT, $signed_at );
        }

        if ( $signed_ip !== '' ) {
            update_post_meta( $post_id, WOC_Contracts_CPT::META_SIGNED_IP, $signed_ip );
        }

        // ✅ 簽名檔：只有在「匯入資料是 signed」才嘗試落地
        $incoming_signed = ( $status === 'signed' );

        if ( $incoming_signed && $sig && ! empty( $sig['base64'] ) ) {

            // 已經簽過且已有簽名 URL：通常不覆蓋（避免踩資料）
            if ( $already_signed ) {
                $existing = get_post_meta( $post_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );
                if ( ! empty( $existing ) ) {
                    return;
                }
            }

            $new_url = self::write_signature_from_base64( (string) $sig['base64'], (string) ($sig['filename'] ?? '') );
            if ( $new_url ) {
                update_post_meta( $post_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, esc_url_raw( $new_url ) );
            }
        }
    }

    private static function import_templates( array $data ) {
        $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : [];
        if ( empty( $items ) ) return;

        // 最簡單：全部新增一份（避免覆蓋你現場正在用的範本）
        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) continue;
            $post = isset( $row['post'] ) && is_array( $row['post'] ) ? $row['post'] : [];

            wp_insert_post([
                'post_type'    => WOC_Contracts_CPT::POST_TYPE_TEMPLATE,
                'post_title'   => isset( $post['post_title'] ) ? (string) $post['post_title'] : '',
                'post_content' => isset( $post['post_content'] ) ? (string) $post['post_content'] : '',
                'post_status'  => isset( $post['post_status'] ) ? (string) $post['post_status'] : 'publish',
            ]);
        }
    }

    private static function import_vars( array $data ) {
        $vars = isset( $data['vars'] ) && is_array( $data['vars'] ) ? $data['vars'] : [];
        update_option( 'woc_contract_global_vars', $vars );
    }

    /* =========================================
     * Signature helpers
     * ========================================= */

    private static function read_signature_as_base64( $url ) {
        $url = (string) $url;
        if ( $url === '' ) return null;

        $path = self::url_to_local_path_if_possible( $url );
        if ( ! $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        // 避免匯出巨大檔案（你可以自己調）
        if ( filesize( $path ) > 5 * 1024 * 1024 ) {
            return null;
        }

        $bin = file_get_contents( $path );
        if ( $bin === false || $bin === '' ) {
            return null;
        }

        return [
            'filename' => basename( $path ),
            'mime'     => 'image/png',
            'base64'   => base64_encode( $bin ),
        ];
    }

    private static function write_signature_from_base64( $base64, $filename_hint = '' ) {
        $base64 = trim( (string) $base64 );
        if ( $base64 === '' ) return '';

        $bin = base64_decode( $base64, true );
        if ( $bin === false ) return '';

        if ( strlen( $bin ) > 5 * 1024 * 1024 ) return '';

        // 檢查圖片可解析
        $img = @imagecreatefromstring( $bin );
        if ( ! $img ) return '';
        imagedestroy( $img );

        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) return '';

        $subdir = '/woc-signatures';
        $dir    = trailingslashit( $upload['basedir'] ) . ltrim( $subdir, '/' );
        if ( ! wp_mkdir_p( $dir ) ) return '';

        $base_name = $filename_hint ? sanitize_file_name( $filename_hint ) : ( 'signature-' . time() . '.png' );
        if ( substr( $base_name, -4 ) !== '.png' ) {
            $base_name .= '.png';
        }

        $filename  = wp_unique_filename( $dir, $base_name );
        $file_path = trailingslashit( $dir ) . $filename;

        if ( file_put_contents( $file_path, $bin ) === false ) {
            return '';
        }

        return trailingslashit( $upload['baseurl'] ) . ltrim( $subdir, '/' ) . '/' . $filename;
    }

    private static function url_to_local_path_if_possible( $url ) {
        $upload = wp_upload_dir();
        if ( empty( $upload['baseurl'] ) || empty( $upload['basedir'] ) ) return '';

        $baseurl = $upload['baseurl'];
        $basedir = $upload['basedir'];

        if ( strpos( $url, $baseurl ) !== 0 ) {
            return '';
        }

        $relative = ltrim( substr( $url, strlen( $baseurl ) ), '/' );
        $path = trailingslashit( $basedir ) . $relative;

        return $path;
    }

    /* =========================================
     * UUID find
     * ========================================= */

    private static function find_post_id_by_uuid( $uuid ) {
        $uuid = (string) $uuid;
        if ( $uuid === '' ) return 0;

        $q = new WP_Query([
            'post_type'      => WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => self::META_UUID,
                    'value' => $uuid,
                ],
            ],
            'no_found_rows'  => true,
        ]);

        if ( ! empty( $q->posts[0] ) ) {
            return (int) $q->posts[0];
        }

        return 0;
    }

    /* =========================================
     * Common helpers
     * ========================================= */

    private static function must_be_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '權限不足。' );
        }
    }

    private static function send_json_download( $filename, $payload ) {
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }
}

WOC_Contracts_Backup::init();
