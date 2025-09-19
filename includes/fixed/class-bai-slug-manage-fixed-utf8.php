<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Manage_Fixed {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_bai_update_slug', [ $this, 'ajax_update_slug' ] );
    }

    public function add_menu() {
        // Menu integrated into top-level WP-AI-Slug page tabs; no separate Settings submenu.
        return;
    }

    public function enqueue( $hook ) {
        if ( $hook !== 'settings_page_bai-slug-manage' ) return;
        wp_enqueue_style( 'bai-slug-admin', BAI_SLUG_URL . 'assets/admin.css', [], BAI_SLUG_VERSION );
        wp_enqueue_script( 'bai-slug-manage', BAI_SLUG_URL . 'assets/manage.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
        wp_localize_script( 'bai-slug-manage', 'BAISlugManage', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_manage' ),
            'i18n'     => [
                'edit'           => BAI_Slug_I18n::t('btn_edit'),
                'done'           => BAI_Slug_I18n::t('btn_done'),
                'saving'         => BAI_Slug_I18n::t('saving'),
                'save_failed'    => BAI_Slug_I18n::t('save_failed'),
                'network_error'  => BAI_Slug_I18n::t('network_error'),
                'applied'        => BAI_Slug_I18n::t('applied'),
                'rejected'       => BAI_Slug_I18n::t('rejected'),
                'no_selection'   => BAI_Slug_I18n::t('no_selection'),
                'applying'       => BAI_Slug_I18n::t('applying'),
                'apply_done'     => BAI_Slug_I18n::t('apply_done'),
                'failed'         => BAI_Slug_I18n::t('failed'),
                'error_generic'  => BAI_Slug_I18n::t('error_generic'),
                'generate'       => BAI_Slug_I18n::t('generate'),
                'generate_done'  => BAI_Slug_I18n::t('generate_done'),
            ],
        ] );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $post_type = sanitize_text_field( $_GET['ptype'] ?? ( $_GET['post_type'] ?? 'post' ) );
        $attr      = sanitize_text_field( $_GET['attr'] ?? '' );
        $paged     = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page  = 20;

        $query = $this->query_posts( $post_type, $attr, $paged, $per_page );
        $pts   = get_post_types( [ 'show_ui' => true ], 'objects' );

        echo '<div class="wrap bai-slug-manage">';
        echo '<h1>' . esc_html( BAI_Slug_I18n::t('manual_title') ) . '</h1>';

        $this->render_filters(
            admin_url( 'options-general.php' ),
            [ 'page' => 'bai-slug-manage', 'paged' => 1 ],
            $post_type,
            $attr,
            $pts
        );

        echo '<div id="bai-manage-notice" style="display:none"></div>';
        // Batch toolbar (merged bulk controls)
        $settings = class_exists( 'BAI_Slug_Settings' ) ? BAI_Slug_Settings::get_settings() : [];
        echo '<div class="card" id="bai-batch-toolbar" style="margin:12px 0; padding:10px;">';
        echo '<div style="margin-bottom:8px;">' . esc_html__( '文章类型', 'wp-ai-slug' ) . '： ';
        foreach ( $pts as $pt => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px">'
                . '<input type="checkbox" class="bai-batch-pt" value="' . esc_attr( $pt ) . '" ' . checked( in_array( $pt, (array) ( $settings['enabled_post_types'] ?? [] ), true ), true, false ) . ' /> '
                . esc_html( $obj->labels->singular_name ) . '</label>';
        }
        echo '</div>';
        echo '<div style="margin-bottom:8px;">'
            . '<label style="margin-right:12px"><input type="checkbox" id="bai-batch-skip-ai" checked /> ' . esc_html( BAI_Slug_I18n::t('filter_skip_ai') ) . '</label>'
            . '<label><input type="checkbox" id="bai-batch-skip-user" checked /> ' . esc_html( BAI_Slug_I18n::t('filter_skip_user') ) . '</label>'
            . '</div>';
        echo '<div style="margin-bottom:8px;">'
            . '<label>' . esc_html__( '批处理大小', 'wp-ai-slug' ) . ' '
            . '<input type="number" id="bai-batch-size" min="1" max="50" value="5" style="width:80px;" />'
            . '</label>'
            . '</div>';
        echo '<div class="actions">'
            . '<button type="button" class="button button-primary" id="bai-batch-start">' . esc_html( BAI_Slug_I18n::t('btn_start') ) . '</button> '
            . '<button type="button" class="button" id="bai-batch-pause">' . esc_html( BAI_Slug_I18n::t('btn_stop') ) . '</button> '
            . '<button type="button" class="button" id="bai-batch-reset">' . esc_html( BAI_Slug_I18n::t('btn_reset_cursor') ) . '</button>'
            . '</div>';
        echo '<p class="description" id="bai-batch-progress">' . esc_html__( '进度：已处理', 'wp-ai-slug' ) . ' <strong id="bai-batch-processed">0</strong> / ' . esc_html__( '总计', 'wp-ai-slug' ) . ' <strong id="bai-batch-total">0</strong> (' . esc_html__( '待处理提案', 'wp-ai-slug' ) . ': <strong id="bai-batch-pending">0</strong>)</p>';
        echo '</div>';
        $this->render_toolbar();
        $this->render_table( $query );
        $this->render_pagination(
            $query,
            $paged,
            admin_url( 'options-general.php' ),
            [ 'page' => 'bai-slug-manage', 'ptype' => $post_type, 'attr' => $attr ]
        );
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t('manual_desc') ) . '</p>';
        echo '</div>';
    }

    public function render_inner() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $post_type = sanitize_text_field( $_GET['ptype'] ?? ( $_GET['post_type'] ?? 'post' ) );
        $attr      = sanitize_text_field( $_GET['attr'] ?? '' );
        $paged     = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page  = 20;

        $query = $this->query_posts( $post_type, $attr, $paged, $per_page );
        $pts   = get_post_types( [ 'show_ui' => true ], 'objects' );

        echo '<div class="wrap">';
        echo '<h2>' . esc_html( BAI_Slug_I18n::t('manual_title') ) . '</h2>';
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t('manual_desc') ) . '</p>';

        $this->render_filters(
            admin_url( 'options-general.php' ),
            [
                'page' => 'bai-slug-settings',
                'active_tab' => 'tab-manual',
                'paged' => 1,
            ],
            $post_type,
            $attr,
            $pts
        );

        echo '<div id="bai-manage-notice" style="display:none"></div>';
        // Batch toolbar inside settings tab as well
        $settings = class_exists( 'BAI_Slug_Settings' ) ? BAI_Slug_Settings::get_settings() : [];
        echo '<div class="card" id="bai-batch-toolbar" style="margin:12px 0; padding:10px;">';
        echo '<div style="margin-bottom:8px;">' . esc_html__( '文章类型', 'wp-ai-slug' ) . '： ';
        foreach ( $pts as $pt => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px">'
                . '<input type="checkbox" class="bai-batch-pt" value="' . esc_attr( $pt ) . '" ' . checked( in_array( $pt, (array) ( $settings['enabled_post_types'] ?? [] ), true ), true, false ) . ' /> '
                . esc_html( $obj->labels->singular_name ) . '</label>';
        }
        echo '</div>';
        echo '<div style="margin-bottom:8px;">'
            . '<label><input type="checkbox" id="bai-batch-skip-ai" checked /> ' . esc_html( BAI_Slug_I18n::t('filter_skip_ai') ) . '</label>'
            . '</div>';
        echo '<div style="margin-bottom:8px;">'
            . '<label>' . esc_html__( '批处理大小', 'wp-ai-slug' ) . ' '
            . '<input type="number" id="bai-batch-size" min="1" max="50" value="5" style="width:80px;" />'
            . '</label>'
            . '</div>';
        echo '<div class="actions">'
            . '<button type="button" class="button button-primary" id="bai-batch-start">' . esc_html( BAI_Slug_I18n::t('btn_start') ) . '</button> '
            . '<button type="button" class="button" id="bai-batch-pause">' . esc_html( BAI_Slug_I18n::t('btn_stop') ) . '</button> '
            . '<button type="button" class="button" id="bai-batch-reset">' . esc_html( BAI_Slug_I18n::t('btn_reset_cursor') ) . '</button>'
            . '</div>';
        echo '<p class="description" id="bai-batch-progress">' . esc_html__( '进度：已处理', 'wp-ai-slug' ) . ' <strong id="bai-batch-processed">0</strong> / ' . esc_html__( '总计', 'wp-ai-slug' ) . ' <strong id="bai-batch-total">0</strong> (' . esc_html__( '待处理提案', 'wp-ai-slug' ) . ': <strong id="bai-batch-pending">0</strong>)</p>';
        echo '</div>';
        $this->render_toolbar();
        $this->render_table( null );
        echo '<div class="tablenav"><div class="tablenav-pages" id="bai-posts-pagination"></div></div>';
        echo '</div>';
    }

    private function query_posts( $post_type, $attr, $paged, $per_page ) {
        $args = [
            'post_type'      => $post_type,
            'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
        ];

        if ( $attr === 'ai' ) {
            $args['meta_query'] = [ [ 'key' => '_slug_source', 'value' => 'ai', 'compare' => '=' ] ];
        } elseif ( $attr === 'user-edited' ) {
            $args['meta_query'] = [ [ 'key' => '_slug_source', 'value' => 'user-edited', 'compare' => '=' ] ];
        } elseif ( $attr === 'proposed' ) {
            $args['meta_query'] = [ [ 'key' => '_proposed_slug', 'compare' => 'EXISTS' ] ];
        } elseif ( $attr === 'pending-slug' ) {
            $args['meta_query'] = [
                'relation' => 'AND',
                [ 'key' => '_slug_source', 'compare' => 'NOT EXISTS' ],
                [ 'key' => '_proposed_slug', 'compare' => 'NOT EXISTS' ],
            ];
        }

        return new WP_Query( $args );
    }

    private function render_filters( $action, $hidden, $post_type, $attr, $pts ) {
        echo '<form method="get" style="margin:10px 0;" action="' . esc_url( $action ) . '">';
        foreach ( $hidden as $key => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
        }
        echo '<label>' . esc_html( BAI_Slug_I18n::t('label_post_types') ) . ' <select name="ptype" id="bai-posts-ptype">';
        foreach ( $pts as $pt => $obj ) {
            echo '<option value="' . esc_attr( $pt ) . '"' . selected( $post_type, $pt, false ) . '>' . esc_html( $obj->labels->singular_name ) . '</option>';
        }
        echo '</select></label> ';
        $attr_options = [
            '' => esc_html__( '全部', 'wp-ai-slug' ),
            'pending-slug' => esc_html__( '待生成Slug', 'wp-ai-slug' ),
            'proposed' => esc_html__( '待处理提案', 'wp-ai-slug' ),
            'ai' => esc_html__( 'AI 生成', 'wp-ai-slug' ),
            'user-edited' => esc_html__( '人工修改', 'wp-ai-slug' ),
        ];
        echo '<label>' . esc_html__( '状态筛选', 'wp-ai-slug' ) . ' <select name="attr" id="bai-posts-attr">';
        foreach ( $attr_options as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $attr, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>搜索 <input type="text" id="bai-posts-search" placeholder="按标题搜索" style="min-width:180px;" /></label> ';
        echo '<label>每页 <input type="number" id="bai-posts-per" value="20" min="5" max="100" style="width:80px;" /></label> ';
        echo '<button type="button" class="button" id="bai-posts-refresh">刷新</button>';
        echo '</form>';
    }

    private function render_toolbar() {
        echo '<div class="bai-manage-toolbar" style="margin:12px 0;">';
        echo '<label><input type="checkbox" id="bai-select-all-top" /> ' . esc_html__( '全选', 'wp-ai-slug' ) . '</label> ';
        echo '<button type="button" class="button" id="bai-generate-selected">' . esc_html__( '批量生成', 'wp-ai-slug' ) . '</button> ';
        echo '<button type="button" class="button" id="bai-apply-selected">' . esc_html__( '应用选中', 'wp-ai-slug' ) . '</button> ';
        echo '<button type="button" class="button" id="bai-reject-selected">' . esc_html__( '拒绝选中', 'wp-ai-slug' ) . '</button>';
        echo '</div>';
    }

    private function render_table( $query ) {
        echo '<table class="widefat striped bai-slug-manage-table">';
        echo '<thead><tr>'
            . '<th width="26"><input type="checkbox" id="bai-select-all" /></th>'
            . '<th width="80">' . esc_html( BAI_Slug_I18n::t('col_id') ) . '</th>'
            . '<th>' . esc_html( BAI_Slug_I18n::t('col_title') ) . '</th>'
            . '<th>' . esc_html( BAI_Slug_I18n::t('col_slug') ) . '</th>'
            . '<th>' . esc_html__( '提案 / 状态', 'wp-ai-slug' ) . '</th>'
            . '<th width="120">' . esc_html( BAI_Slug_I18n::t('col_attr') ) . '</th>'
            . '<th width="120">' . esc_html( BAI_Slug_I18n::t('col_actions') ) . '</th>'
            . '</tr></thead><tbody id="bai-posts-tbody"><tr><td colspan="7">加载中…</td></tr></tbody></table>';
    }

    private function render_pagination( $query, $paged, $action, $args ) {
        $total = (int) $query->max_num_pages;
        if ( $total <= 1 ) {
            return;
        }

        $base_args = [];
        foreach ( $args as $key => $value ) {
            if ( $key === 'paged' ) {
                continue;
            }
            if ( $value === '' ) {
                continue;
            }
            $base_args[ $key ] = $value;
        }

        $base = add_query_arg( array_merge( $base_args, [ 'paged' => '%#%' ] ), $action );

        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo '<span class="pagination-links">' . paginate_links( [
            'base'      => $base,
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'current'   => $paged,
            'total'     => $total,
        ] ) . '</span>';
        echo '</div></div>';
    }

    public function ajax_update_slug() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        check_ajax_referer( 'bai_slug_manage', 'nonce' );

        $post_id   = intval( $_POST['post_id'] ?? 0 );
        $new_title = isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '';
        $new_slug  = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        $attr      = sanitize_text_field( $_POST['attr'] ?? 'user-edited' );

        if ( ! $post_id || $new_slug === '' ) wp_send_json_error( [ 'message' => '数据无效' ] );

        $update = [ 'ID' => $post_id, 'post_name' => $new_slug ];
        if ( $new_title !== '' ) $update['post_title'] = $new_title;
        $res = wp_update_post( $update, true );
        if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );

        update_post_meta( $post_id, '_slug_source', $attr ?: 'user-edited' );
        if ( $attr === 'ai' ) update_post_meta( $post_id, '_generated_slug', $new_slug );

        $post = get_post( $post_id );
        if ( class_exists( 'BAI_Slug_Log' ) ) {
            BAI_Slug_Log::add( '手动更新成功: ' . $new_slug, $new_title !== '' ? $new_title : $post->post_title );
        }
        wp_send_json_success( [ 'slug' => $post->post_name ] );
    }
}
?>
