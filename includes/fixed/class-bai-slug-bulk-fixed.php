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
        wp_enqueue_script( 'bai-slug-bulk', BAI_SLUG_URL . 'assets/bulk.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
                        wp_localize_script( 'bai-slug-bulk', 'BAISlugBulk', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_queue' ),
            'i18n'     => [
                'request_failed' => '����ʧ��',
                'done'           => '�������',
                'network_error'  => '�������',
                'cursor_reset'   => '�α�������',
            ],
        ] );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $settings = BAI_Slug_Settings::get_settings();
        $pts = get_post_types( [ 'show_ui' => true ], 'objects' );
        $cursor = (int) get_option( 'bai_slug_bulk_cursor', 0 );
        ?>
        <div class="wrap bai-slug-bulk">
            <h1><?php echo esc_html( BAI_Slug_I18n::t('bulk_title') ); ?></h1>
            <div class="notice notice-info"><p><?php echo esc_html( BAI_Slug_I18n::t('bulk_notice') ); ?></p></div>
            <p><?php echo esc_html( BAI_Slug_I18n::t('bulk_desc') ); ?></p>

            <div class="card">
                <h2><?php echo esc_html__( '任务配置', 'wp-ai-slug' ); ?></h2>
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
                        <p class="description">5�?0 建议�?/p>
                    </td></tr>
                    <tr><th><?php echo esc_html( BAI_Slug_I18n::t('label_filters') ); ?></th><td>
                        <label><input type="checkbox" id="bai-only-non-ascii" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_only_non_ascii') ); ?></label><br>
                        <label><input type="checkbox" id="bai-only-eq-sanitized" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_only_eq_sanitized') ); ?></label><br>
                        <label><input type="checkbox" id="bai-skip-user-edited" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_skip_user') ); ?></label>
                    </td></tr>
                </table>
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
        </div>
        <?php
    }

    private function has_non_ascii( $str ) { return (bool) preg_match( '/[^\x00-\x7F]/', (string) $str ); }

    public function ajax_batch() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Ȩ�޲���' ], 403 );
        wp_send_json_error( [ 'message' => '�첽������ӿ���ͣ�ã���ʹ���µĶ��д���' ], 400 );
    }

    // 内嵌到设置页的渲染（不包含外�?wrap），用于 Tab 面板直接展示
    public function render_inner() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $settings = BAI_Slug_Settings::get_settings();
        $pts = get_post_types( [ 'show_ui' => true ], 'objects' );
        $cursor = (int) get_option( 'bai_slug_bulk_cursor', 0 );
        ?>
        <div class="card">
            <h2><?php echo esc_html__( '任务配置', 'wp-ai-slug' ); ?></h2>
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
                    <input type="number" id="bai-batch-size" min="1" max="50" value="10" />
                    <p class="description">5�?0 建议�?/p>
                </td></tr>
                <tr><th><?php echo esc_html( BAI_Slug_I18n::t('label_filters') ); ?></th><td>
                    <label><input type="checkbox" id="bai-only-non-ascii" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_only_non_ascii') ); ?></label><br>
                    <label><input type="checkbox" id="bai-only-eq-sanitized" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_only_eq_sanitized') ); ?></label><br>
                    <label><input type="checkbox" id="bai-skip-user-edited" checked /> <?php echo esc_html( BAI_Slug_I18n::t('filter_skip_user') ); ?></label>
                </td></tr>
            </table>
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












