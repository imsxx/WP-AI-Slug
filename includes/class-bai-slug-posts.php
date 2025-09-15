<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Posts {
    private $meta_source = '_slug_source';
    private static $pending = [];

    public function __construct() {
        add_filter( 'wp_insert_post_data', [ $this, 'maybe_generate_on_insert' ], 10, 2 );
        add_action( 'save_post', [ $this, 'track_user_edit' ], 10, 2 );
    }

    public function maybe_generate_on_insert( $data, $postarr ) {
        if ( wp_is_post_autosave( $postarr['ID'] ) || wp_is_post_revision( $postarr['ID'] ) ) {
            return $data;
        }
        if ( ( $data['post_status'] ?? '' ) === 'auto-draft' ) {
            return $data;
        }

        $settings = BAI_Slug_Settings::get_settings();
        if ( ! in_array( $data['post_type'], (array) $settings['enabled_post_types'], true ) ) {
            return $data;
        }

        // 用户已手填 slug 则不覆盖
        if ( ! empty( $postarr['post_name'] ) ) {
            return $data;
        }

        $title = $data['post_title'];
        if ( $title === '' ) {
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '标题为空，跳过', 'Unknown Title' ); }
            return $data;
        }

        $slug = BAI_Slug_Helpers::request_slug( $title, $settings );
        if ( $slug ) {
            $data['post_name'] = $slug;
            // 记录一次映射，供后续 save_post 写入元字段
            $key = md5( (string) ( $data['post_type'] ?? '' ) . '|' . (string) $title );
            self::$pending[ $key ] = (string) $slug;
            // 若已有有效 ID 则写入元字段；新建时可能 post_id=0 无法存元数据
            if ( ! empty( $postarr['ID'] ) ) {
                update_post_meta( $postarr['ID'], '_generated_slug', $slug );
                update_post_meta( $postarr['ID'], $this->meta_source, 'ai' );
            }
            BAI_Slug_Settings::increment_counter();
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '自动生成成功: ' . $slug, $title ); }
        } else {
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '未得到有效 slug', $title ); }
        }

        return $data;
    }

    public function track_user_edit( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        // 首次保存：若前面程序设置了 slug，而此时才有 ID，则在此写入来源
        $src = get_post_meta( $post_id, $this->meta_source, true );
        $gen = get_post_meta( $post_id, '_generated_slug', true );
        if ( empty( $src ) && empty( $gen ) && ! empty( $post->post_name ) ) {
            $key = md5( (string) $post->post_type . '|' . (string) $post->post_title );
            if ( isset( self::$pending[ $key ] ) && self::$pending[ $key ] === $post->post_name ) {
                update_post_meta( $post_id, '_generated_slug', $post->post_name );
                update_post_meta( $post_id, $this->meta_source, 'ai' );
            }
        }
        $prev = get_post_meta( $post_id, '_generated_slug', true );
        if ( $prev && $post->post_name !== $prev ) {
            update_post_meta( $post_id, $this->meta_source, 'user-edited' );
        }
    }
}

?>
