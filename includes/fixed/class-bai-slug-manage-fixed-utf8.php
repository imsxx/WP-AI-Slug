<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Manage_Fixed {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_bai_update_slug', [ $this, 'ajax_update_slug' ] );
    }

    public function add_menu() {
        add_submenu_page(
            'options-general.php',
            BAI_Slug_I18n::t('manual_title'),
            BAI_Slug_I18n::t('manual_title'),
            'manage_options',
            'bai-slug-manage',
            [ $this, 'render' ]
        );
    }

    public function enqueue( $hook ) {
        if ( $hook !== 'settings_page_bai-slug-manage' ) return;
        wp_enqueue_style( 'bai-slug-admin', BAI_SLUG_URL . 'assets/admin.css', [], BAI_SLUG_VERSION );
        wp_enqueue_script( 'bai-slug-manage', BAI_SLUG_URL . 'assets/manage.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
        wp_localize_script( 'bai-slug-manage', 'BAISlugManage', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_manage' ),
            'i18n'     => [
                'edit' => BAI_Slug_I18n::t('btn_edit'),
                'done' => BAI_Slug_I18n::t('btn_done'),
                'saving' => BAI_Slug_I18n::t('saving'),
                'save_failed' => BAI_Slug_I18n::t('save_failed'),
                'network_error' => BAI_Slug_I18n::t('network_error'),
                'applied' => BAI_Slug_I18n::t('applied'),
                'rejected' => BAI_Slug_I18n::t('rejected'),
                'no_selection' => BAI_Slug_I18n::t('no_selection'),
            ],
        ] );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $post_type = sanitize_text_field( $_GET['post_type'] ?? 'post' );
        $attr = sanitize_text_field( $_GET['attr'] ?? '' ); // 'ai' | 'user-edited' | ''
        $paged = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page = 20;

        $args = [
            'post_type' => $post_type,
            'post_status' => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'orderby' => 'ID',
            'order' => 'DESC',
            'posts_per_page' => $per_page,
            'paged' => $paged,
        ];
        if ( $attr === 'ai' ) {
            $args['meta_query'] = [ [ 'key' => '_slug_source', 'value' => 'ai', 'compare' => '=' ] ];
        } elseif ( $attr === 'user-edited' ) {
            $args['meta_query'] = [ [ 'key' => '_slug_source', 'value' => 'user-edited', 'compare' => '=' ] ];
        }

        $q = new WP_Query( $args );
        $pts = get_post_types( [ 'show_ui' => true ], 'objects' );

        echo '<div class="wrap bai-slug-manage">';
        echo '<h1>' . esc_html( BAI_Slug_I18n::t('manual_title') ) . '</h1>';

        echo '<form method="get" style="margin:10px 0;">';
        echo '<input type="hidden" name="page" value="bai-slug-manage" />';
        echo '<input type="hidden" name="paged" value="1" />';
        echo '<label>' . esc_html( BAI_Slug_I18n::t('label_post_types') ) . ' <select name="post_type">';
        foreach ( $pts as $pt => $obj ) {
            echo '<option value="' . esc_attr( $pt ) . '"' . selected( $post_type, $pt, false ) . '>' . esc_html( $obj->labels->singular_name ) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>' . esc_html__( '属性筛选', 'wp-ai-slug' ) . ' <select name="attr">';
        echo '<option value=""' . selected( $attr, '', false ) . '>全部</option>';
        echo '<option value="ai"' . selected( $attr, 'ai', false ) . '>AI 生成</option>';
        echo '<option value="user-edited"' . selected( $attr, 'user-edited', false ) . '>人工修改</option>';
        echo '</select></label> ';
        submit_button( '筛选', 'secondary', '', false );
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr><th width="80">' . esc_html( BAI_Slug_I18n::t('col_id') ) . '</th><th>' . esc_html( BAI_Slug_I18n::t('col_title') ) . '</th><th>' . esc_html( BAI_Slug_I18n::t('col_slug') ) . '</th><th width="120">' . esc_html( BAI_Slug_I18n::t('col_attr') ) . '</th><th width="120">' . esc_html( BAI_Slug_I18n::t('col_actions') ) . '</th></tr></thead><tbody>';
        if ( $q->have_posts() ) {
            while ( $q->have_posts() ) { $q->the_post();
                $pid = get_the_ID();
                $title = get_the_title();
                $slug = get_post_field( 'post_name', $pid );
                $src  = get_post_meta( $pid, '_slug_source', true );
                echo '<tr data-id="' . esc_attr( $pid ) . '">';
                echo '<td>' . (int) $pid . '</td>';
                echo '<td class="col-title"><span class="text">' . esc_html( $title ) . '</span><input class="edit-title" type="text" value="' . esc_attr( $title ) . '" style="display:none;width:100%" /></td>';
                echo '<td class="col-slug"><code class="text">' . esc_html( $slug ) . '</code><input class="edit-slug" type="text" value="' . esc_attr( $slug ) . '" style="display:none;width:100%" /></td>';
                echo '<td class="col-attr">'
                    . '<span class="text">' . ( $src ? esc_html( $src ) : '-' ) . '</span>'
                    . '<select class="edit-attr" style="display:none;">'
                    . '<option value="ai"' . selected( $src, 'ai', false ) . '>' . esc_html( BAI_Slug_I18n::t('attr_ai') ) . '</option>'
                    . '<option value="user-edited"' . selected( $src, 'user-edited', false ) . '>' . esc_html( BAI_Slug_I18n::t('attr_user') ) . '</option>'
                    . '</select>'
                    . '</td>';
                echo '<td class="col-actions"><button class="button bai-edit">' . esc_html( BAI_Slug_I18n::t('btn_edit') ) . '</button></td>';
                echo '</tr>';
            }
            wp_reset_postdata();
        } else {
            echo '<tr><td colspan="5">暂无数据</td></tr>';
        }
        echo '</tbody></table>';

        // 分页
        $total = (int) $q->max_num_pages;
        if ( $total > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links( [
                'base' => add_query_arg( [ 'page' => 'bai-slug-manage', 'post_type' => $post_type, 'attr' => $attr, 'paged' => '%#%' ], admin_url( 'options-general.php' ) ),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'current' => $paged,
                'total' => $total,
            ] );
            echo '</div></div>';
        }

        echo '<p class="description">默认按 ID 倒序（新到旧）。保存后会将属性标记为“人工修改”，避免被覆盖。</p>';
        echo '</div>';
    }

    public function render_inner() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $_GET['page'] = 'bai-slug-settings';
        $_GET['active_tab'] = 'tab-manual';
        echo '<div class="wrap">';
        $this->render();
        echo '</div>';
    }

    public function ajax_update_slug() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        check_ajax_referer( 'bai_slug_manage', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $new_title = isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '';
        $new_slug  = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        $attr      = sanitize_text_field( $_POST['attr'] ?? 'user-edited' );

        if ( ! $post_id || $new_slug === '' ) wp_send_json_error( [ 'message' => '参数不合法' ] );

        $update = [ 'ID' => $post_id, 'post_name' => $new_slug ];
        if ( $new_title !== '' ) $update['post_title'] = $new_title;
        $res = wp_update_post( $update, true );
        if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );

        update_post_meta( $post_id, '_slug_source', $attr ?: 'user-edited' );
        if ( $attr === 'ai' ) update_post_meta( $post_id, '_generated_slug', $new_slug );

        $post = get_post( $post_id );
        if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '手动更新成功: ' . $new_slug, $new_title !== '' ? $new_title : $post->post_title ); }
        wp_send_json_success( [ 'slug' => $post->post_name ] );
    }
}

?>
