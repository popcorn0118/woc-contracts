<?php
/**
 * WOC Contracts Backup (Page)
 * - 後台頁面 UI：render_page()
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait WOC_Contracts_Backup_Page {

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
}
