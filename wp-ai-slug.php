<?php
/**
 * Plugin Name: WP-AI-Slug
 * Plugin URI: https://imsxx.com
 * Description: 基于 haayal-ai-slug-translator 构建的整合增强插件（由 GPT‑4/5 协助开发）。支持自定义大模型服务（OpenAI、DeepSeek 或兼容端点）、一键测试连通性，以及异步批量为历史文章与术语生成英文 Slug。
 * Version: 1.0
 * Author: 梦随乡兮
 * Author URI: https://imsxx.com
 * Text Domain: wp-ai-slug
 * Domain Path: /languages
 * License: GPLv2 or later
 *
 * 本插件基于 GPLv2-or-later 许可的 haayal-ai-slug-translator 衍生整合，保留原作者版权与声明。
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'BAI_SLUG_VERSION', '1.0' );
define( 'BAI_SLUG_DIR', plugin_dir_path( __FILE__ ) );
define( 'BAI_SLUG_URL', plugin_dir_url( __FILE__ ) );

// 自动加载
spl_autoload_register( function( $class ) {
    if ( strpos( $class, 'BAI_Slug_' ) === 0 ) {
        $file = BAI_SLUG_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
        if ( file_exists( $file ) ) { require_once $file; }
    }
} );

// 加载国际化
add_action( 'init', function() {
    load_plugin_textdomain( 'wp-ai-slug', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
} );

// 初始化并加载模块
add_action( 'plugins_loaded', function() {
    // Load fixed implementations (prefer UTF-8 versions; avoid redeclare)
    $pairs = [
        [ 'includes/fixed/class-bai-slug-settings-fixed-utf8.php', 'includes/fixed/class-bai-slug-settings-fixed.php' ],
        [ 'includes/fixed/class-bai-slug-bulk-fixed-utf8.php',     'includes/fixed/class-bai-slug-bulk-fixed.php' ],
        [ 'includes/fixed/class-bai-slug-manage-fixed-utf8.php',   'includes/fixed/class-bai-slug-manage-fixed.php' ],
    ];
    foreach ( $pairs as $pair ) {
        $utf8 = BAI_SLUG_DIR . $pair[0];
        $fallback = BAI_SLUG_DIR . $pair[1];
        if ( file_exists( $utf8 ) ) {
            require_once $utf8;
        } elseif ( file_exists( $fallback ) ) {
            require_once $fallback;
        }
    }
    // queue module
    $queue_file = BAI_SLUG_DIR . 'includes/queue/class-bai-slug-queue.php';
    if ( file_exists( $queue_file ) ) { require_once $queue_file; }

    new BAI_Slug_Settings();
    new BAI_Slug_Posts();
    new BAI_Slug_Terms();
    new BAI_Slug_Admin();
    if ( class_exists( 'BAI_Slug_Queue' ) ) { BAI_Slug_Queue::init(); }
    if ( class_exists( 'BAI_Slug_Bulk_Fixed' ) ) { new BAI_Slug_Bulk_Fixed(); }
    if ( class_exists( 'BAI_Slug_Manage_Fixed' ) ) { new BAI_Slug_Manage_Fixed(); }

    // 冲突提示：如检测到原版插件，建议停用避免重复处理
    if ( class_exists( 'Haayal_AI_Slug_Helpers' ) ) {
        add_action( 'admin_notices', function(){
            echo '<div class="notice notice-warning"><p>检测到已启用 <strong>Ailo - AI Slug Translator</strong> 原版插件，建议停用以避免重复生成 Slug。</p></div>';
        } );
    }
} );

// 插件页设置入口
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $settings_link = '<a href="options-general.php?page=bai-slug-settings">' . __( '设置', 'wp-ai-slug' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );

?>



