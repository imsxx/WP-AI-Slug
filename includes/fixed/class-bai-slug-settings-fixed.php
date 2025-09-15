<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Settings {
    private static $option_name = 'bai_slug_settings';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_bai_test_connectivity', [ $this, 'ajax_test_connectivity' ] );
        add_action( 'wp_ajax_bai_save_and_test', [ $this, 'ajax_save_and_test' ] );
        add_action( 'wp_ajax_bai_set_lang', [ $this, 'ajax_set_lang' ] );
    }

    public static function get_settings() {
        $defaults = [
            'provider' => 'openai',
            'api_base' => 'https://api.openai.com',
            'api_path' => '/v1/chat/completions',
            'api_key'  => '',
            'model'    => 'gpt-4o-mini',
            'enabled_post_types' => [ 'post', 'page' ],
            'enabled_taxonomies' => [],
            'custom_headers' => '',
            'use_glossary' => 0,
            'glossary_text' => '',
            'ui_lang' => 'zh',
        ];
        $opt = get_option( self::$option_name, [] );
        return wp_parse_args( $opt, $defaults );
    }
    // Keep a simple generated-slug counter like the original class
        public static function increment_counter() {
        $k = '_bai_slug_generated_counter';
        $c = (int) get_option( $k, 0 );
        update_option( $k, $c + 1 );
    }

    public function add_settings_page() {
        add_options_page(
            BAI_Slug_I18n::t('page_settings_title'),
            BAI_Slug_I18n::t('menu_settings'),
            'manage_options',
            'bai-slug-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'settings_page_bai-slug-settings' ) return;
        wp_enqueue_style( 'bai-slug-admin', BAI_SLUG_URL . 'assets/admin.css', [], BAI_SLUG_VERSION );
        wp_enqueue_script( 'bai-slug-settings', BAI_SLUG_URL . 'assets/settings.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
        wp_localize_script( 'bai-slug-settings', 'BAISlug', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_test' ),
            'i18n'     => [
                'testing' => 'Testing...',
                'test'    => 'Test Connectivity',
                'request_failed' => 'Request failed',
            ],
        ] );

        // Embedded tabs need these too
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

        wp_enqueue_script( 'bai-slug-manage', BAI_SLUG_URL . 'assets/manage.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
                wp_localize_script( 'bai-slug-manage', 'BAISlugManage', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_manage' ),
            'i18n'     => [
                'edit' => '�༭',
                'done' => '���',
                'saving' => '������...',
                'save_failed' => '����ʧ��',
                'network_error' => '�������',
                'applied' => '��Ӧ��',
                'rejected' => '�Ѿܾ�',
                'no_selection' => 'δѡ���κ���Ŀ'
            ],
        ] );
    }

    public function render_settings_page() {
        if ( isset( $_POST['bai_slug_save'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'bai_slug_settings' );
            $saved = self::get_settings();
            $opt = $saved; // merge updates from POST only

            $fields_text = [ 'provider', 'api_base', 'api_path', 'model', 'custom_headers', 'ui_lang' ];
            foreach ( $fields_text as $f ) {
                if ( array_key_exists( $f, $_POST ) ) {
                    $v = is_string( $_POST[ $f ] ) ? $_POST[ $f ] : '';
                    $opt[ $f ] = ( $f === 'api_base' ) ? esc_url_raw( wp_unslash( $v ) ) : sanitize_text_field( wp_unslash( $v ) );
                }
            }

            if ( array_key_exists( 'api_key', $_POST ) ) {
                $submitted = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
                $opt['api_key'] = ( strpos( $submitted, '*' ) === false ) ? $submitted : ( $saved['api_key'] ?? '' );
            }

            if ( array_key_exists( 'enabled_post_types', $_POST ) ) {
                $opt['enabled_post_types'] = array_map( 'sanitize_text_field', (array) ( $_POST['enabled_post_types'] ?? [] ) );
            }
            if ( array_key_exists( 'enabled_taxonomies', $_POST ) ) {
                $opt['enabled_taxonomies'] = array_map( 'sanitize_text_field', (array) ( $_POST['enabled_taxonomies'] ?? [] ) );
            }
            if ( array_key_exists( 'use_glossary', $_POST ) ) {
                $opt['use_glossary'] = ! empty( $_POST['use_glossary'] ) ? 1 : 0;
            }
            if ( array_key_exists( 'glossary_text', $_POST ) ) {
                $opt['glossary_text'] = sanitize_textarea_field( wp_unslash( $_POST['glossary_text'] ) );
            }
            if ( isset( $opt['ui_lang'] ) && ! in_array( $opt['ui_lang'], [ 'zh', 'en' ], true ) ) {
                $opt['ui_lang'] = 'zh';
            }

            update_option( self::$option_name, $opt );
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $opt = self::get_settings();

        echo '<div class="wrap bai-slug-settings">';
        echo '<h1>' . esc_html( BAI_Slug_I18n::t('page_settings_title') ) . '</h1>';
        echo '<div class="bai-lang-bar" style="margin:6px 0 12px;">'
            . '<label style="margin-right:6px;">Language</label>'
            . '<select id="bai-lang-switch">'
                . '<option value="zh"' . selected( BAI_Slug_I18n::lang(), 'zh', false ) . '>中文</option>'
                . '<option value="en"' . selected( BAI_Slug_I18n::lang(), 'en', false ) . '>English</option>'
            . '</select>'
        . '</div>';

        echo '<h2 class="nav-tab-wrapper" id="bai-tabs">'
            . '<a href="javascript:;" class="nav-tab nav-tab-active" data-target="tab-config">基础配置</a>'
            . '<a href="javascript:;" class="nav-tab" data-target="tab-glossary">固定词库</a>'
            . '<a href="javascript:;" class="nav-tab" data-target="tab-bulk">异步处理</a>'
            . '<a href="javascript:;" class="nav-tab" data-target="tab-manual">手动处理</a>'
            . '<a href="javascript:;" class="nav-tab" data-target="tab-logs">运行日志</a>'
            . '<a href="javascript:;" class="nav-tab" data-target="tab-guide">使用说明</a>'
        . '</h2>';

        // 配置 Tab
        echo '<div id="tab-config" class="bai-tab" style="display:block">';
        echo '<form method="post">';
        wp_nonce_field( 'bai_slug_settings' );
        echo '<table class="form-table">';
        echo '<tr><th>服务提供�?/th><td>';
        echo '<select name="provider" id="bai-provider">'
            . '<option value="openai"' . selected( $opt['provider'], 'openai', false ) . '>OpenAI</option>'
            . '<option value="deepseek"' . selected( $opt['provider'], 'deepseek', false ) . '>DeepSeek</option>'
            . '<option value="custom"' . selected( $opt['provider'], 'custom', false ) . '>自定义兼容端�?/option>'
            . '</select>';
        echo '</td></tr>';

        echo '<tr><th>API 基地址</th><td><input type="url" class="regular-text" id="bai-api-base" name="api_base" value="' . esc_attr( $opt['api_base'] ) . '" placeholder="https://api.openai.com" /></td></tr>';
        echo '<tr><th>端点路径</th><td><input type="text" class="regular-text" id="bai-api-path" name="api_path" value="' . esc_attr( $opt['api_path'] ) . '" placeholder="/v1/chat/completions" /></td></tr>';
        $display_key = $opt['api_key'] ? str_repeat( '*', 12 ) : '';
        echo '<tr><th>API Key</th><td><input type="text" class="regular-text" id="bai-api-key" name="api_key" value="' . esc_attr( $display_key ) . '" placeholder="sk-..." /></td></tr>';
        echo '<tr><th>模型</th><td><input type="text" class="regular-text" id="bai-model" name="model" value="' . esc_attr( $opt['model'] ) . '" placeholder="gpt-4o-mini / deepseek-chat" /></td></tr>';

        echo '<tr><th>' . esc_html( BAI_Slug_I18n::t('label_post_types') ) . '</th><td>';
        foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $pt => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px;">';
            echo '<input type="checkbox" name="enabled_post_types[]" value="' . esc_attr( $pt ) . '" ' . checked( in_array( $pt, (array) $opt['enabled_post_types'], true ), true, false ) . ' /> ' . esc_html( $obj->labels->singular_name );
            echo '</label>';
        }
        echo '</td></tr>';

        echo '<tr><th>启用的分类法</th><td>';
        foreach ( get_taxonomies( [ 'show_ui' => true ], 'objects' ) as $tx => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px;">';
            echo '<input type="checkbox" name="enabled_taxonomies[]" value="' . esc_attr( $tx ) . '" ' . checked( in_array( $tx, (array) $opt['enabled_taxonomies'], true ), true, false ) . ' /> ' . esc_html( $obj->labels->singular_name );
            echo '</label>';
        }
        echo '</td></tr>';

        echo '<tr><th>自定义请求头</th><td><textarea class="large-text" name="custom_headers" rows="4" placeholder="X-API-Key: your-proxy-key\nUser-Agent: WordPress-WP-AI-Slug">' . esc_textarea( $opt['custom_headers'] ) . '</textarea></td></tr>';

        echo '<input type="hidden" name="ui_lang" value="' . esc_attr( $opt['ui_lang'] ) . '" />';
        echo '</table>';
        echo '<p>';
        submit_button( BAI_Slug_I18n::t('btn_save'), 'primary', 'bai_slug_save', false );
        echo ' <button type="button" id="bai-test-connectivity" class="button">' . esc_html( BAI_Slug_I18n::t('btn_test') ) . '</button>';
        echo '</p>';
        echo '<div id="bai-test-result" class="notice" style="display:none;"></div>';
        echo '</form>';
        echo '</div>';

        // 固定词库 Tab（独立表单）
        echo '<div id="tab-glossary" class="bai-tab" style="display:none">';
        echo '<form method="post">';
        wp_nonce_field( 'bai_slug_settings' );
        echo '<table class="form-table">';
        echo '<tr><th>启用固定词库</th><td><label><input type="checkbox" name="use_glossary" value="1" ' . checked( ! empty( $opt['use_glossary'] ), true, false ) . ' /> 启用</label></td></tr>';
        echo '<tr><th>固定词库内容</th><td><textarea class="large-text" name="glossary_text" rows="6" id="bai-glossary-text" placeholder="termA=translation-a\ntermB=translation-b\n">' . esc_textarea( $opt['glossary_text'] ) . '</textarea></td></tr>';
        // keep other values unchanged on save
        echo '<input type="hidden" name="provider" value="' . esc_attr( $opt['provider'] ) . '" />';
        echo '<input type="hidden" name="api_base" value="' . esc_attr( $opt['api_base'] ) . '" />';
        echo '<input type="hidden" name="api_path" value="' . esc_attr( $opt['api_path'] ) . '" />';
        echo '<input type="hidden" name="api_key" value="' . esc_attr( $display_key ) . '" />';
        echo '<input type="hidden" name="model" value="' . esc_attr( $opt['model'] ) . '" />';
        echo '<input type="hidden" name="custom_headers" value="' . esc_attr( $opt['custom_headers'] ) . '" />';
        echo '<input type="hidden" name="ui_lang" value="' . esc_attr( $opt['ui_lang'] ) . '" />';
        echo '</table>';
        submit_button( BAI_Slug_I18n::t('btn_save'), 'primary', 'bai_slug_save', false );
        echo '</form>';
        echo '</div>';

        // 异步批处�?Tab（内嵌）
        echo '<div id="tab-bulk" class="bai-tab" style="display:none">';
        if ( class_exists( 'BAI_Slug_Bulk_Fixed' ) ) { ( new BAI_Slug_Bulk_Fixed() )->render_inner(); }
        echo '</div>';

        // 手动编辑 Tab（内嵌）
        echo '<div id="tab-manual" class="bai-tab" style="display:none">';
        if ( class_exists( 'BAI_Slug_Manage_Fixed' ) ) { ( new BAI_Slug_Manage_Fixed() )->render_inner(); }
        echo '</div>';

        // 日志 Tab
        echo '<div id="tab-logs" class="bai-tab" style="display:none">';
        echo '<h2>运行日志</h2>';
        if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::display(); }
        echo '</div>';

        // 使用说明 Tab（简版，详细内容另行优化�?        echo '<div id="tab-guide" class="bai-tab" style="display:none">';
        echo '<p>开源地址�?a href="https://github.com/imsxx/wp-ai-slug" target="_blank" rel="noopener">https://github.com/imsxx/wp-ai-slug</a></p>';
        echo '</div>';

        echo '</div>'; // wrap
    }

    public function ajax_test_connectivity() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        check_ajax_referer( 'bai_slug_test', 'nonce' );
        $status = BAI_Slug_Helpers::test_connectivity( self::get_settings() );
        if ( $status['ok'] ) {
            wp_send_json_success( [ 'message' => 'OK: ' . $status['message'] ] );
        }
        wp_send_json_error( [ 'message' => 'ERR: ' . $status['message'] ] );
    }

    public function ajax_save_and_test() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        check_ajax_referer( 'bai_slug_test', 'nonce' );

        $incoming = isset( $_POST['options'] ) ? (array) $_POST['options'] : [];
        $existing = self::get_settings();
        $opt = $existing;
        foreach ( [ 'provider','api_base','api_path','model','custom_headers' ] as $f ) {
            if ( array_key_exists( $f, $incoming ) ) {
                $v = is_string( $incoming[ $f ] ) ? $incoming[ $f ] : '';
                $opt[ $f ] = ( $f === 'api_base' ) ? esc_url_raw( wp_unslash( $v ) ) : sanitize_text_field( wp_unslash( $v ) );
            }
        }
        if ( array_key_exists( 'api_key', $incoming ) ) {
            $submitted = sanitize_text_field( wp_unslash( $incoming['api_key'] ) );
            $opt['api_key'] = ( strpos( $submitted, '*' ) === false ) ? $submitted : ( $existing['api_key'] ?? '' );
        }
        foreach ( [ 'enabled_post_types', 'enabled_taxonomies' ] as $arr ) {
            if ( array_key_exists( $arr, $incoming ) && is_array( $incoming[ $arr ] ) ) {
                $opt[ $arr ] = array_map( 'sanitize_text_field', wp_unslash( $incoming[ $arr ] ) );
            }
        }
        if ( array_key_exists( 'use_glossary', $incoming ) ) {
            $opt['use_glossary'] = ! empty( $incoming['use_glossary'] ) ? 1 : 0;
        }
        if ( array_key_exists( 'glossary_text', $incoming ) ) {
            $opt['glossary_text'] = sanitize_textarea_field( wp_unslash( $incoming['glossary_text'] ) );
        }

        update_option( self::$option_name, $opt );

        $status = BAI_Slug_Helpers::test_connectivity( $opt );
        if ( $status['ok'] ) {
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '测试连通性成功：' . $status['message'] ); }
            wp_send_json_success( [ 'message' => 'OK: ' . $status['message'] ] );
        }
        if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '测试连通性失败：' . $status['message'] ); }
        wp_send_json_error( [ 'message' => 'ERR: ' . $status['message'] ] );
    }

    public function ajax_set_lang() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        check_ajax_referer( 'bai_slug_test', 'nonce' );
        $lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'zh';
        if ( ! in_array( $lang, [ 'zh', 'en' ], true ) ) { $lang = 'zh'; }
        $settings = self::get_settings();
        $settings['ui_lang'] = $lang;
        update_option( self::$option_name, $settings );
        wp_send_json_success( [ 'message' => 'ok' ] );
    }
}





