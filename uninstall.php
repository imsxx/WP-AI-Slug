<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// 删除选项
delete_option( 'bai_slug_settings' );
delete_option( 'bai_slug_bulk_cursor' );
delete_option( '_bai_slug_error_log' );
delete_option( '_bai_slug_generated_counter' );
delete_option( 'bai_slug_job' );
delete_option( 'bai_slug_terms_job' );
delete_option( 'bai_slug_terms_ids' );

// 清理队列分块选项
global $wpdb;
$like = esc_sql( 'bai_slug_queue_chunk_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

// 清理计划任务
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'bai_slug_tick' );
}

// 可选：清理已写入的 post meta（如不希望清理可注释掉）
$meta_keys = [ '_slug_source', '_generated_slug' ];
foreach ( $meta_keys as $meta_key ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) );
}

// 清理批处理提案相关元数据
$proposed_meta = [ '_proposed_slug', '_proposed_slug_raw', '_proposed_meta' ];
foreach ( $proposed_meta as $meta_key ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) );
}
