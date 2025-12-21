<?php
/**
 * 轉接入口（相容舊路徑）
 * - 保留原路徑，避免外部 require_once 斷掉
 * - 真正實作搬到 includes/backup/ 內
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once WOC_CONTRACTS_PATH . 'includes/backup/class-woc-backup.php';
