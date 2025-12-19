<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WOC_Contracts_Backup {

    const NONCE_EXPORT = 'woc_export_json';
    const NONCE_IMPORT = 'woc_import_json';

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

        $export_contracts = wp_nonce_url(
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
            <p>匯出為 JSON，可用於外掛環境導入。</p>

            <p>
                <a class="button button-primary" href="<?php echo esc_url( $export_contracts ); ?>">匯出合約（JSON）</a>
                <a class="button" href="<?php echo esc_url( $export_templates ); ?>">匯出範本（JSON）</a>
                <a class="button" href="<?php echo esc_url( $export_vars ); ?>">匯出變數（JSON）</a>
            </p>

            <p style="margin-top:10px;color:#666;">
                合約匯出<strong>不含簽名圖片 base64</strong>，只會輸出 files 清單（upload_relpath）。<br>
                要搬簽名檔：請把來源站 <code>/wp-content/uploads/woc-signatures/</code> 整包複製到目標站相同位置。
            </p>

            <hr>

            <h2>匯入</h2>
            <p>上傳 JSON（對應上面的匯出檔）。</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( self::NONCE_IMPORT, 'woc_import_nonce' ); ?>
                <input type="hidden" name="action" value="woc_import_json">

                <p>
                    <input type="file" name="woc_json_file" accept=".json,application/json" required>
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

        $max = 10 * 1024 * 1024; // 10MB
        $size = isset($_FILES['woc_json_file']['size']) ? (int) $_FILES['woc_json_file']['size'] : 0;

        if ( $size <= 0 ) {
            wp_die('檔案大小異常。');
        }
        if ( $size > $max ) {
            wp_die('JSON 檔過大，已拒絕（上限 10MB）。');
        }

        // 可選：提高 admin 記憶體上限
        if ( function_exists('wp_raise_memory_limit') ) {
            wp_raise_memory_limit('admin');
        }
        @set_time_limit(60);


        $tmp = $_FILES['woc_json_file']['tmp_name'];
        $raw = file_get_contents( $tmp );
        if ( $raw === false || $raw === '' ) {
            wp_die( '讀取 JSON 失敗或內容空白。' );
        }

        // 去 BOM（很多 JSON 檔是 UTF-8 BOM）
        if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw = substr( $raw, 3 );
        }

        // 這裡「絕對不要 sanitize 原始 JSON」
        try {
            $data = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
        } catch ( Throwable $e ) {
            wp_die( 'JSON 格式錯誤：' . esc_html( $e->getMessage() ) );
        }

        $type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : '';
        if ( ! $type ) wp_die( 'JSON 缺少 type。' );

        $overwrite = ! empty( $_POST['woc_import_overwrite'] );

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
            'post_type'      => WOC_Contracts_CPT::POST_TYPE_TEMPLATE,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);

        $items = [];
        foreach ( $q->posts as $p ) {
            $uuid = get_post_meta( $p->ID, '_woc_uuid', true );

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
        $q = new WP_Query([
            'post_type'      => WOC_Contracts_CPT::POST_TYPE_CONTRACT,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
    
        $upload  = wp_upload_dir();
        $baseurl = isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '';
    
        $files = [];
        $items = [];
    
        foreach ( $q->posts as $p ) {
            $uuid = get_post_meta( $p->ID, '_woc_uuid', true );
    
            $meta = self::pick_meta( $p->ID );
    
            // ✅ 合約匯出：不要把 _woc_signature_image（可能是 data:image base64）塞進 meta
            if ( isset( $meta[ WOC_Contracts_CPT::META_SIGNATURE_IMAGE ] ) ) {
                unset( $meta[ WOC_Contracts_CPT::META_SIGNATURE_IMAGE ] );
            }
    
            // ===== 取簽名 URL（另外輸出到 signature 區塊）
            $sig_url = get_post_meta( $p->ID, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );
            $sig_url = is_string( $sig_url ) ? $sig_url : '';
    
            // ✅ 如果是 data:image...base64，直接不匯出
            if ( $sig_url && strpos( $sig_url, 'data:image/' ) === 0 ) {
                $sig_url = '';
            }
    
            // ===== 用 PATH 算 upload_relpath（較不吃網域/https/cdn）
            $relpath = '';
            if ( $sig_url && $baseurl ) {
                $sig_path     = (string) parse_url( $sig_url, PHP_URL_PATH );   // /wp-content/uploads/...
                $uploads_path = (string) parse_url( $baseurl, PHP_URL_PATH );   // /wp-content/uploads
    
                if ( $sig_path && $uploads_path && strpos( $sig_path, $uploads_path ) === 0 ) {
                    $relpath = ltrim( substr( $sig_path, strlen( $uploads_path ) ), '/' );
                }
            }
    
            if ( $relpath ) {
                $files[ $relpath ] = true;
            }
    
            // ===== ✅ 取合約使用的範本（跨站要靠 uuid）
            $template_id = 0;
            if ( isset( $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ] ) ) {
                $template_id = (int) $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ];
            }
    
            $template_uuid  = '';
            $template_title = '';
    
            if ( $template_id > 0 && get_post_type( $template_id ) === WOC_Contracts_CPT::POST_TYPE_TEMPLATE ) {
                $template_uuid  = (string) get_post_meta( $template_id, '_woc_uuid', true );
                $template_title = (string) get_the_title( $template_id );
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
    
                // ✅ 新增：範本資訊（給 B 匯入時做 mapping）
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
    
        $payload = [
            'type'     => 'contracts',
            'version'  => 2, // ✅ 有新增 template 區塊，版本往上
            'exported' => current_time( 'c' ),
            'count'    => count( $items ),
            'files'    => array_values( array_keys( $files ) ),
            'items'    => $items,
        ];
    
        self::download_json( 'woc-contracts-contracts-' . gmdate( 'Ymd-His' ) . '.json', $payload );
    }
    
    

    private static function import_vars( array $data ) {
        if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
            wp_die( 'vars JSON items 格式不正確。' );
        }
    
        $incoming = $data['items'];
    
        $current  = get_option( 'woc_contract_global_vars', [] );
        if ( ! is_array( $current ) ) $current = [];
    
        $parsed = [];
    
        // 支援兩種格式：
        // A) {"company_name":{"label":"公司名稱","value":"xxx"}}
        // B) [{"key":"company_name","label":"公司名稱","value":"xxx"}]
        foreach ( $incoming as $k => $row ) {
    
            // 格式 B：list
            if ( is_int( $k ) && is_array( $row ) && isset( $row['key'] ) ) {
                $key = sanitize_key( (string) $row['key'] );
                if ( $key === '' ) continue;
    
                $parsed[ $key ] = [
                    'label' => isset( $row['label'] ) ? (string) $row['label'] : '',
                    'value' => $row['value'] ?? '',
                ];
                continue;
            }
    
            // 格式 A：assoc object
            if ( ! is_string( $k ) || $k === '' ) continue;
            if ( ! is_array( $row ) ) continue;
    
            $key = sanitize_key( (string) $k );
            if ( $key === '' ) continue;
    
            $parsed[ $key ] = [
                'label' => isset( $row['label'] ) ? (string) $row['label'] : '',
                'value' => $row['value'] ?? '',
            ];
        }
    
        // ✅ 沒解析到任何有效資料：直接擋掉，不寫入（避免清空只剩一列）
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
    
        // ===== 一次性 map：目標站現有 posts 的 uuid => post_id（避免每筆 WP_Query）
        $existing_uuid_to_id = [];
        $statuses = [ 'publish', 'draft', 'pending', 'private', 'trash' ];
        $in_status = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";
    
        $sql_existing = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_woc_uuid'
               AND p.post_type = %s
               AND p.post_status IN ($in_status)",
            $post_type
        );
        $rows_existing = $wpdb->get_results( $sql_existing ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        if ( $rows_existing ) {
            foreach ( $rows_existing as $r ) {
                $u = is_string( $r->meta_value ) ? $r->meta_value : '';
                if ( $u !== '' ) {
                    $existing_uuid_to_id[ $u ] = (int) $r->post_id;
                }
            }
        }
    
        // ===== contracts 專用：一次性 map templates（uuid=>id、title=>id(唯一)）
        $tpl_uuid_to_id = [];
        $tpl_title_to_id = [];
        $tpl_title_dupe  = [];
    
        if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
    
            // uuid => id
            $sql_tpl_uuid = $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_woc_uuid'
                   AND p.post_type = %s
                   AND p.post_status IN ($in_status)",
                WOC_Contracts_CPT::POST_TYPE_TEMPLATE
            );
            $rows_tpl_uuid = $wpdb->get_results( $sql_tpl_uuid ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            if ( $rows_tpl_uuid ) {
                foreach ( $rows_tpl_uuid as $r ) {
                    $u = is_string( $r->meta_value ) ? $r->meta_value : '';
                    if ( $u !== '' ) {
                        $tpl_uuid_to_id[ $u ] = (int) $r->post_id;
                    }
                }
            }
    
            // title => id（只收唯一 title，避免撞名誤配）
            $sql_tpl_title = $wpdb->prepare(
                "SELECT ID, post_title
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status IN ($in_status)",
                WOC_Contracts_CPT::POST_TYPE_TEMPLATE
            );
            $rows_tpl_title = $wpdb->get_results( $sql_tpl_title ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            if ( $rows_tpl_title ) {
                foreach ( $rows_tpl_title as $r ) {
                    $t = is_string( $r->post_title ) ? $r->post_title : '';
                    $id = (int) $r->ID;
                    if ( $t === '' ) continue;
    
                    if ( isset( $tpl_title_to_id[ $t ] ) ) {
                        // duplicate：移除，並標記為重複，不再用 title 對應
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
    
            $uuid = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
            $post = ( isset( $item['post'] ) && is_array( $item['post'] ) ) ? $item['post'] : [];
            $meta = ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) ? $item['meta'] : [];
    
            if ( empty( $post ) ) continue;
    
            // 目標站是否已存在同 uuid
            $existing_id = 0;
            if ( $uuid !== '' && isset( $existing_uuid_to_id[ $uuid ] ) ) {
                $existing_id = (int) $existing_uuid_to_id[ $uuid ];
            }
    
            if ( $existing_id && ! $overwrite ) {
                continue; // 預設跳過，避免蓋到另一邊新增/修改
            }
    
            // contracts：安全起見，不信任 meta 內的 signature（舊檔可能有 base64 或舊站 URL）
            if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT && isset( $meta[ WOC_Contracts_CPT::META_SIGNATURE_IMAGE ] ) ) {
                unset( $meta[ WOC_Contracts_CPT::META_SIGNATURE_IMAGE ] );
            }
    
            // contracts：template 映射（uuid/title → B站 template ID；必要時保留 B站既有有效 template_id）
            if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
    
                $tpl_uuid  = isset( $item['template']['uuid'] )  ? (string) $item['template']['uuid']  : '';
                $tpl_title = isset( $item['template']['title'] ) ? (string) $item['template']['title'] : '';
                $mapped_tpl_id = 0;
    
                // 1) uuid 最優先
                if ( $tpl_uuid !== '' && isset( $tpl_uuid_to_id[ $tpl_uuid ] ) ) {
                    $mapped_tpl_id = (int) $tpl_uuid_to_id[ $tpl_uuid ];
                }
    
                // 2) title fallback（只用「唯一」title）
                if ( ! $mapped_tpl_id && $tpl_title !== '' && isset( $tpl_title_to_id[ $tpl_title ] ) ) {
                    $mapped_tpl_id = (int) $tpl_title_to_id[ $tpl_title ];
                }
    
                // 3) 如果 mapping 失敗、且是更新既有合約：保留 B 站原本有效的 template_id（避免被清空變 —）
                if ( ! $mapped_tpl_id && $existing_id ) {
                    $current_tpl_id = (int) get_post_meta( $existing_id, WOC_Contracts_CPT::META_TEMPLATE_ID, true );
                    if ( $current_tpl_id > 0 && get_post_type( $current_tpl_id ) === WOC_Contracts_CPT::POST_TYPE_TEMPLATE ) {
                        $mapped_tpl_id = $current_tpl_id;
                    }
                }
    
                if ( $mapped_tpl_id ) {
                    $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ] = (string) $mapped_tpl_id;
                } else {
                    // 找不到：把舊 template_id 轉存備援，並移除（避免留下錯 ID）
                    if ( isset( $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ] ) ) {
                        $meta['_woc_backup_template_id'] = (string) $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ];
                        unset( $meta[ WOC_Contracts_CPT::META_TEMPLATE_ID ] );
                    }
                    if ( $tpl_uuid )  $meta['_woc_backup_template_uuid']  = $tpl_uuid;
                    if ( $tpl_title ) $meta['_woc_backup_template_title'] = $tpl_title;
                }
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
    
            // 重要：含 HTML 的內容要 wp_slash 才穩
            $postarr = wp_slash( $postarr );
    
            $new_id = wp_insert_post( $postarr, true );
            if ( is_wp_error( $new_id ) ) {
                continue;
            }
            $new_id = (int) $new_id;
    
            // meta：只寫我們自己要的那批（避免垃圾 meta）
            foreach ( $meta as $k => $v ) {
                if ( ! is_string( $k ) || $k === '' ) continue;
                if ( strpos( $k, '_woc_' ) !== 0 && $k !== '_woc_uuid' && $k !== '_woc_backup_uuid' ) continue;
                update_post_meta( $new_id, $k, $v );
            }
    
            if ( $uuid !== '' ) {
                update_post_meta( $new_id, '_woc_uuid', $uuid );
            }
    
            // contracts：簽名（relpath 優先；沒有就 fallback 用 url；排除 base64）
            if ( $post_type === WOC_Contracts_CPT::POST_TYPE_CONTRACT ) {
    
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
    
            // 更新 map（同一次匯入檔裡如果有重複 uuid，後面能正確指到最新那筆）
            if ( $uuid !== '' ) {
                $existing_uuid_to_id[ $uuid ] = $new_id;
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
            WOC_Contracts_CPT::META_AUDIT_LOG,
            '_woc_uuid',
            '_woc_backup_uuid',
        ];

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
