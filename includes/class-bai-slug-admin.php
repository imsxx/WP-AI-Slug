<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Admin {
    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'current_screen', [ $this, 'maybe_add_columns' ] );
    }

    public function enqueue( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) { return; }
        $context = '';
        $post_id = 0;
        if ( $screen->base === 'post' ) { $context = 'single'; $post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0; }
        if ( $screen->base === 'edit' ) { $context = 'list'; }
        if ( $context === '' ) { return; }

        wp_enqueue_style( 'bai-slug-admin', BAI_SLUG_URL . 'assets/admin.css', [], BAI_SLUG_VERSION );
        wp_enqueue_script( 'bai-slug-post', BAI_SLUG_URL . 'assets/post-admin.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
        wp_localize_script( 'bai-slug-post', 'BAISlugPost', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce_regen' => wp_create_nonce( 'bai_slug_regen' ),
            'nonce_update'=> wp_create_nonce( 'bai_slug_manage' ),
            'context' => $context,
            'post_id' => $post_id,
            'assets' => [ 'loading' => BAI_SLUG_URL . 'assets/loading.gif' ],
            'i18n' => [
                'regenerate' => BAI_Slug_I18n::lang() === 'en' ? 'Regenerate' : '重新生成',
                'generated'  => BAI_Slug_I18n::lang() === 'en' ? 'Generated' : '已生成',
                'error_generic'=> BAI_Slug_I18n::t('error_generic'),
                'network_error'=> BAI_Slug_I18n::t('network_error'),
                'saved'        => BAI_Slug_I18n::lang() === 'en' ? 'Saved' : '已保存',
                'source_ai'    => BAI_Slug_I18n::t('attr_ai'),
                'source_user'  => BAI_Slug_I18n::t('attr_user'),
            ],
        ] );
    }

    public function maybe_add_columns( $screen ) {
        if ( ! $screen || $screen->base !== 'edit' ) { return; }
        $pt = $screen->post_type;
        if ( ! $pt ) { return; }
        $settings = method_exists( 'BAI_Slug_Settings', 'get_settings' ) ? BAI_Slug_Settings::get_settings() : [];
        $enabled = (array) ( $settings['enabled_post_types'] ?? [ 'post','page' ] );
        if ( ! in_array( $pt, $enabled, true ) ) { return; }
        add_filter( "manage_edit-{$pt}_columns", function( $cols ) {
            $new = [];
            $inserted = false;
            foreach ( $cols as $k => $v ) {
                $new[ $k ] = $v;
                if ( $k === 'title' && ! isset( $new['bai_slug_tools'] ) ) {
                    $new['bai_slug_tools'] = 'WP-AI-Slug';
                    $inserted = true;
                }
            }
            if ( ! $inserted ) { $new['bai_slug_tools'] = 'WP-AI-Slug'; }
            return $new;
        } );
        add_action( "manage_{$pt}_posts_custom_column", function( $col, $post_id ) {
            if ( $col !== 'bai_slug_tools' ) { return; }
            $post = get_post( $post_id ); if ( ! $post ) { return; }
            $slug = $post->post_name;
            $src  = get_post_meta( $post_id, '_slug_source', true );
            $src_label = $src === 'ai' ? BAI_Slug_I18n::t('attr_ai') : ( $src === 'user-edited' ? BAI_Slug_I18n::t('attr_user') : '—' );
            echo '<div class="bai-inline-slug" data-id="' . (int) $post_id . '">';
            echo '<div class="slug-row">'
                . '<code class="slug-display">' . esc_html( $slug ) . '</code>'
                . '<input class="slug-input slug-input" type="text" value="' . esc_attr( $slug ) . '" style="display:none;max-width:220px;" /> '
                . '<span class="spinner" style="float:none;"></span>'
                . '</div>';
            echo '<div class="action-row" style="margin-top:6px;">'
                . '<button type="button" class="button-link bai-inline-edit">' . esc_html( BAI_Slug_I18n::t('btn_edit') ) . '</button> '
                . '<button type="button" class="button-link bai-inline-save" style="display:none;">' . esc_html( BAI_Slug_I18n::t('btn_save') ) . '</button> '
                . '<button type="button" class="button-link bai-inline-cancel" style="display:none;">' . esc_html__( '取消', 'wp-ai-slug' ) . '</button> '
                . '<button type="button" class="button-link bai-inline-regenerate">' . esc_html( BAI_Slug_I18n::lang() === 'en' ? 'Regenerate' : '重新生成' ) . '</button> '
                . '<span class="source-label">' . esc_html( $src_label ) . '</span>'
                . '</div>';
            echo '</div>';
        }, 10, 2 );
    }
}

?>
