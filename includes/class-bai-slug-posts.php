<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Posts {
    private $meta_source = '_slug_source';
    private static $pending = [];

    public function __construct() {
        add_filter( 'wp_insert_post_data', [ $this, 'maybe_generate_on_insert' ], 10, 2 );
        add_action( 'save_post', [ $this, 'track_user_edit' ], 10, 2 );
        add_action( 'wp_ajax_bai_slug_regenerate_post', [ $this, 'ajax_regenerate_post' ] );
    }

    public function maybe_generate_on_insert( $data, $postarr ) {
        if ( wp_is_post_autosave( $postarr['ID'] ) || wp_is_post_revision( $postarr['ID'] ) ) {
            return $data;
        }
        if ( ( $data['post_status'] ?? '' ) === 'auto-draft' ) {
            return $data;
        }
        $settings = class_exists( 'BAI_Slug_Settings' ) ? BAI_Slug_Settings::get_settings() : [];
        if ( empty( $settings['auto_generate_posts'] ) ) {
            return $data;
        }
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

    private function verify_nonce_flexible( $nonce ) {
        if ( ! $nonce ) return false;
        if ( wp_verify_nonce( $nonce, 'bai_slug_regen' ) ) return true;
        if ( wp_verify_nonce( $nonce, 'bai_slug_manage' ) ) return true;
        if ( wp_verify_nonce( $nonce, 'bai_slug_queue' ) ) return true;
        if ( wp_verify_nonce( $nonce, 'bai_slug_test' ) ) return true;
        return false;
    }

    public function ajax_regenerate_post() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        $nonce = isset( $_POST['nonce'] ) ? (string) $_POST['nonce'] : '';
        if ( ! $this->verify_nonce_flexible( $nonce ) ) {
            wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        }

        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'invalid id' ], 400 );
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'not found' ], 404 );
        }

        $settings = class_exists( 'BAI_Slug_Settings' ) ? BAI_Slug_Settings::get_settings() : [];
        $context  = method_exists( 'BAI_Slug_Helpers', 'context_from_post' ) ? BAI_Slug_Helpers::context_from_post( $post, $settings ) : [];
        $slug     = BAI_Slug_Helpers::request_slug( $post->post_title, $settings, $context );
        if ( ! $slug ) {
            wp_send_json_error( [ 'message' => 'failed to generate' ] );
        }
        $res = wp_update_post( [ 'ID' => $post_id, 'post_name' => $slug ], true );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( [ 'message' => $res->get_error_message() ] );
        }
        update_post_meta( $post_id, '_generated_slug', $slug );
        update_post_meta( $post_id, '_slug_source', 'ai' );
        if ( method_exists( 'BAI_Slug_Settings', 'increment_counter' ) ) { BAI_Slug_Settings::increment_counter(); }

        wp_send_json_success( [ 'slug' => $slug, 'permalink' => get_permalink( $post_id ) ] );
    }
}

?>

