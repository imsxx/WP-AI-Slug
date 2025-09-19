<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Terms {
    public function __construct() {
        add_action( 'created_term', [ $this, 'maybe_generate_term_slug' ], 10, 3 );
    }

    public function maybe_generate_term_slug( $term_id, $tt_id, $taxonomy ) {
        $settings = class_exists( 'BAI_Slug_Settings' ) ? BAI_Slug_Settings::get_settings() : [];
        if ( empty( $settings['auto_generate_terms'] ) ) { return; }
        if ( ! in_array( (string) $taxonomy, (array) ( $settings['enabled_taxonomies'] ?? [] ), true ) ) { return; }

        // 若用户表单已提交 slug 则不覆盖（WP 已有 nonce 校验）
        if ( isset( $_POST['slug'] ) && ! empty( $_POST['slug'] ) ) { return; }

        $term = get_term( $term_id, $taxonomy );
        if ( is_wp_error( $term ) || ! $term ) return;

        $default_slug = sanitize_title( $term->name );
        if ( $term->slug !== $default_slug ) { return; }

        $ctx  = method_exists( 'BAI_Slug_Helpers', 'context_from_term' ) ? BAI_Slug_Helpers::context_from_term( $term ) : [];
        $slug = BAI_Slug_Helpers::request_slug( $term->name, $settings, $ctx );
        if ( ! $slug ) {
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '未得到有效 slug', $term->name ); }
            return;
        }

        // 确保唯一，避免冲突
        $unique = $slug; $i = 1;
        while ( true ) {
            $exists = term_exists( $unique, $taxonomy );
            if ( ! $exists ) { break; }
            $exists_id = is_array( $exists ) ? (int) ( $exists['term_id'] ?? 0 ) : (int) $exists;
            if ( $exists_id === (int) $term_id ) { break; }
            $unique = $slug . '-' . $i; $i++;
        }

        wp_update_term( $term_id, $taxonomy, [ 'slug' => $unique ] );
        update_term_meta( $term_id, '_slug_source', 'ai' );
        BAI_Slug_Settings::increment_counter();
        if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '术语生成成功: ' . $unique, $term->name ); }
    }
}

?>

