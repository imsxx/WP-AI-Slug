<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Bulk_Fixed {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function add_menu() {
        add_submenu_page(
            'options-general.php',
            BAI_Slug_I18n::t('bulk_title'),
            BAI_Slug_I18n::t('bulk_title'),
            'manage_options',
            'bai-slug-bulk',
            [ $this, 'render' ]
        );
    }

    public function enqueue( $hook ) {
        if ( $hook !== 'settings_page_bai-slug-bulk' ) return;
        wp_enqueue_style( 'bai-slug-admin', BAI_SLUG_URL . 'assets/admin.css', [], BAI_SLUG_VERSION );
        $zh = ( BAI_Slug_I18n::lang() === 'zh' );
        wp_enqueue_script( 'bai-slug-bulk', BAI_SLUG_URL . 'assets/bulk.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
        wp_localize_script( 'bai-slug-bulk', 'BAISlugBulk', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_queue' ),
            'i18n'     => [
                'request_failed' => $zh ? '请求失败' : 'Request failed',
                'done'           => $zh ? '已完成' : 'Done',
                'network_error'  => BAI_Slug_I18n::t('network_error'),
                'cursor_reset'   => $zh ? '游标已重置' : 'Cursor reset',
            ],
        ] );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $settings = BAI_Slug_Settings::get_settings();
        $pts = get_post_types( [ 'show_ui' => true ], 'objects' );
        $taxes = get_taxonomies( [ 'show_ui' => true ], 'objects' );
        $cursor = (int) get_option( 'bai_slug_bulk_cursor', 0 );
        ?>
        <div class="wrap bai-slug-bulk">
            <h1><?php echo esc_html( BAI_Slug_I18n::t('bulk_title') ); ?></h1>
            <div class="notice notice-info"><p><?php echo esc_html( BAI_Slug_I18n::t('bulk_notice') ); ?></p></div>
            <p class="description">说明：选择文章类型作为处理范围，设置每批数量。启用“跳过已由 AI 生成”以避免重复。可随时“重置游标”。</p>

            <div class="card">
                <h2><?php echo esc_html__( '文章', 'wp-ai-slug' ); ?></h2>
                <table class="form-table">
                    <tr><th><?php echo esc_html( BAI_Slug_I18n::t('label_post_types') ); ?></th><td>
                        <?php foreach ( $pts as $pt => $obj ) : ?>
                            <label style="display:inline-block;margin-right:12px;">
                                <input type="checkbox" class="bai-pt" value="<?php echo esc_attr( $pt ); ?>" <?php checked( in_array( $pt, (array) $settings['enabled_post_types'], true ) ); ?> />
                                <?php echo esc_html( $obj->labels->singular_name ); ?>
                            </label>
                        <?php endforeach; ?>
                    </td></tr>
                    <tr><th><?php echo esc_html( BAI_Slug_I18n::t('label_batch_size') ); ?></th><td>
                        <input type="number" id="bai-batch-size" min="1" max="50" value="5" />
                        <p class="description">建议 5~10。批量越大越快，但对服务端 API 压力越高。</p>
                    </td></tr>
                </table>
                <p>
                    <label><input type="checkbox" id="bai-skip-ai" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_skip_ai') ); ?></label>
                </p>
                <p>
                    <button class="button button-primary" id="bai-start"><?php echo esc_html( BAI_Slug_I18n::t('btn_start') ); ?></button>
                    <button class="button" id="bai-stop" disabled><?php echo esc_html( BAI_Slug_I18n::t('btn_stop') ); ?></button>
                    <button class="button" id="bai-reset"><?php echo esc_html( BAI_Slug_I18n::t('btn_reset_cursor') ); ?></button>
                </p>
                <p class="description"><?php echo esc_html( BAI_Slug_I18n::t('cursor') ); ?><code id="bai-cursor"><?php echo (int) $cursor; ?></code></p>
            </div>

            <div class="card">
                <h2><?php echo esc_html__( '进度', 'wp-ai-slug' ); ?></h2>
                <p><?php printf( esc_html( BAI_Slug_I18n::t('progress') ), '<strong id="bai-processed">0</strong>', '<strong id="bai-scanned">0</strong>' ); ?></p>
                <div id="bai-log" class="bgi-log"></div>
            </div>

            <div class="card">
                <h2><?php echo esc_html__( '术语（分类/标签等）', 'wp-ai-slug' ); ?></h2>
                <p class="description">用于对分类、标签等术语进行 slug 生成或完善。</p>
                <table class="form-table">
                    <tr><th><?php echo esc_html__( '分类法', 'wp-ai-slug' ); ?></th><td>
                        <?php foreach ( $taxes as $tx => $obj ) : ?>
                            <label style="display:inline-block;margin-right:12px;">
                                <input type="checkbox" class="bai-tax" value="<?php echo esc_attr( $tx ); ?>" <?php checked( in_array( $tx, (array) $settings['enabled_taxonomies'], true ) ); ?> />
                                <?php echo esc_html( $obj->labels->singular_name ); ?>
                            </label>
                        <?php endforeach; ?>
                    </td></tr>
                </table>
                <p>
                    <label><input type="checkbox" id="bai-terms-skip-ai" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_skip_ai_terms') ); ?></label>
                </p>
                <p>
                    <button class="button" id="bai-terms-start"><?php echo esc_html__( '开始处理术语', 'wp-ai-slug' ); ?></button>
                    <button class="button" id="bai-terms-reset"><?php echo esc_html__( '重置术语游标', 'wp-ai-slug' ); ?></button>
                </p>
                <p class="description"><?php echo esc_html__( '已处理', 'wp-ai-slug' ); ?>: <strong id="bai-terms-processed">0</strong> / <strong id="bai-terms-scanned">0</strong>；Cursor: <code id="bai-terms-cursor">0</code></p>
                <div id="bai-log-terms" class="bgi-log"></div>
            </div>
        </div>
        <?php
    }

    // 嵌入设置页 Tab 内部渲染
    public function render_inner() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $settings = BAI_Slug_Settings::get_settings();
        $pts = get_post_types( [ 'show_ui' => true ], 'objects' );
        $cursor = (int) get_option( 'bai_slug_bulk_cursor', 0 );
        ?>
        <div class="card">
            <h2><?php echo esc_html__( '文章', 'wp-ai-slug' ); ?></h2>
            <p class="description"><?php echo esc_html( BAI_Slug_I18n::t('bulk_notice') ); ?></p>
            <table class="form-table">
                <tr><th><?php echo esc_html( BAI_Slug_I18n::t('label_post_types') ); ?></th><td>
                    <?php foreach ( $pts as $pt => $obj ) : ?>
                        <label style="display:inline-block;margin-right:12px;">
                            <input type="checkbox" class="bai-pt" value="<?php echo esc_attr( $pt ); ?>" <?php checked( in_array( $pt, (array) $settings['enabled_post_types'], true ) ); ?> />
                            <?php echo esc_html( $obj->labels->singular_name ); ?>
                        </label>
                    <?php endforeach; ?>
                </td></tr>
                <tr><th><?php echo esc_html( BAI_Slug_I18n::t('label_batch_size') ); ?></th><td>
                    <input type="number" id="bai-batch-size" min="1" max="50" value="5" />
                    <p class="description">建议 5~10。批量越大越快，但对服务端 API 压力越高。</p>
                </td></tr>
            </table>
            <p>
                <label><input type="checkbox" id="bai-skip-ai" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_skip_ai') ); ?></label>
            </p>
            <p>
                <button class="button button-primary" id="bai-start"><?php echo esc_html( BAI_Slug_I18n::t('btn_start') ); ?></button>
                <button class="button" id="bai-stop" disabled><?php echo esc_html( BAI_Slug_I18n::t('btn_stop') ); ?></button>
                <button class="button" id="bai-reset"><?php echo esc_html( BAI_Slug_I18n::t('btn_reset_cursor') ); ?></button>
            </p>
            <p class="description"><?php echo esc_html( BAI_Slug_I18n::t('cursor') ); ?><code id="bai-cursor"><?php echo (int) $cursor; ?></code></p>
        </div>

        <div class="card">
            <h2><?php echo esc_html__( '进度', 'wp-ai-slug' ); ?></h2>
            <p><?php printf( esc_html( BAI_Slug_I18n::t('progress') ), '<strong id="bai-processed">0</strong>', '<strong id="bai-scanned">0</strong>' ); ?></p>
            <div id="bai-log" class="bgi-log"></div>
        </div>
        <?php
    }
}

?>
