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
            'use_glossary' => 1,
            'glossary_text' => '',
            'ui_lang' => 'zh',
            'system_prompt' => '',
            'slug_max_chars' => 60,
        ];
        $opt = get_option( self::$option_name, [] );
        return wp_parse_args( $opt, $defaults );
    }

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
                'testing' => ( BAI_Slug_I18n::lang() === 'en' ? 'Testing...' : '正在测试...' ),
                'test'    => ( BAI_Slug_I18n::lang() === 'en' ? 'Test Connectivity' : '测试连通性' ),
                'request_failed' => ( BAI_Slug_I18n::lang() === 'en' ? 'Request failed' : '请求失败' ),
            ],
        ] );

        // Bulk & Manage scripts for embedded tabs
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

    private function select_options( $current, $options ) {
        $html = '';
        foreach ( $options as $val => $label ) {
            $html .= '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        return $html;
    }

    public function render_settings_page() {
        if ( isset( $_POST['bai_slug_save'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'bai_slug_settings' );
            $saved = self::get_settings();
            $opt = $saved;

            $fields_text = [ 'provider','api_base','api_path','model','ui_lang' ];
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
            if ( array_key_exists( 'slug_max_chars', $_POST ) ) {
                $opt['slug_max_chars'] = max( 10, min( 120, intval( $_POST['slug_max_chars'] ) ) );
            }
            if ( array_key_exists( 'system_prompt', $_POST ) ) {
                $opt['system_prompt'] = sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) );
            }
            if ( array_key_exists( 'enabled_post_types', $_POST ) && is_array( $_POST['enabled_post_types'] ) ) {
                $opt['enabled_post_types'] = array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_post_types'] ) );
            }
            if ( array_key_exists( 'enabled_taxonomies', $_POST ) && is_array( $_POST['enabled_taxonomies'] ) ) {
                $opt['enabled_taxonomies'] = array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_taxonomies'] ) );
            }
            $opt['use_glossary'] = ! empty( $_POST['use_glossary'] ) ? 1 : 0;
            if ( array_key_exists( 'glossary_text', $_POST ) ) {
                $opt['glossary_text'] = sanitize_textarea_field( wp_unslash( $_POST['glossary_text'] ) );
            }

            update_option( self::$option_name, $opt );
            echo '<div class="notice notice-success"><p>设置已保存。</p></div>';
        }

        $opt = self::get_settings();
        $pts = get_post_types( [ 'show_ui' => true ], 'objects' );
        $taxes = get_taxonomies( [ 'show_ui' => true ], 'objects' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( BAI_Slug_I18n::t('page_settings_title') ) . '</h1>';

        // Language quick switch
        echo '<p><label>界面语言： <select id="bai-lang-switch">'
            . $this->select_options( $opt['ui_lang'], [ 'zh' => '中文', 'en' => 'English' ] )
            . '</select></label></p>';

        echo '<h2 class="nav-tab-wrapper" id="bai-tabs">';
        echo '<a href="#tab-basic" class="nav-tab nav-tab-active">基本设置</a>';
        echo '<a href="#tab-glossary" class="nav-tab">术语表</a>';
        echo '<a href="#tab-bulk" class="nav-tab">批量</a>';
        echo '<a href="#tab-manual" class="nav-tab">手动</a>';
        echo '<a href="#tab-logs" class="nav-tab">日志</a>';
        echo '<a href="#tab-guide" class="nav-tab">指南</a>';
        echo '</h2>';

        echo '<div id="tab-basic" class="bai-tab" style="">';
        echo '<form method="post">';
        wp_nonce_field( 'bai_slug_settings' );
        echo '<table class="form-table">';
        echo '<tr><th>服务提供商</th><td>';
        echo '<select id="bai-provider" name="provider">'
            . $this->select_options( $opt['provider'], [ 'openai'=>'OpenAI 兼容', 'deepseek'=>'DeepSeek', 'custom'=>'自定义' ] )
            . '</select>';
        echo '</td></tr>';
        echo '<tr><th>API 基址</th><td><input id="bai-api-base" type="text" name="api_base" value="' . esc_attr( $opt['api_base'] ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>接口路径</th><td><input id="bai-api-path" type="text" name="api_path" value="' . esc_attr( $opt['api_path'] ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>API Key</th><td><input id="bai-api-key" type="password" name="api_key" value="' . esc_attr( $opt['api_key'] ? str_repeat( '*', 8 ) : '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>模型</th><td><input id="bai-model" type="text" name="model" value="' . esc_attr( $opt['model'] ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>Slug 最大长度</th><td><input type="number" min="10" max="120" name="slug_max_chars" value="' . (int) $opt['slug_max_chars'] . '" /> <span class="description">默认 60</span></td></tr>';
        echo '<tr><th>系统提示词</th><td><textarea name="system_prompt" rows="4" class="large-text">' . esc_textarea( $opt['system_prompt'] ) . '</textarea><p class="description">可选：覆盖默认的生成规则。</p></td></tr>';

        echo '<tr><th>' . esc_html( BAI_Slug_I18n::t('label_post_types') ) . '</th><td>';
        foreach ( $pts as $pt => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="enabled_post_types[]" value="' . esc_attr( $pt ) . '" ' . checked( in_array( $pt, (array) $opt['enabled_post_types'], true ), true, false ) . ' /> ' . esc_html( $obj->labels->singular_name ) . '</label>';
        }
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t('desc_enabled_post_types') ) . '</p>';
        echo '</td></tr>';

        echo '<tr><th>启用的分类法</th><td>';
        foreach ( $taxes as $tx => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="enabled_taxonomies[]" value="' . esc_attr( $tx ) . '" ' . checked( in_array( $tx, (array) $opt['enabled_taxonomies'], true ), true, false ) . ' /> ' . esc_html( $obj->labels->singular_name ) . '</label>';
        }
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t('desc_enabled_taxonomies') ) . '</p>';
        echo '</td></tr>';
        echo '</table>';

        echo '<p>';
        echo '<button type="button" class="button" id="bai-test-connectivity">' . esc_html( BAI_Slug_I18n::t('btn_test') ) . '</button> ';
        echo '<span id="bai-test-result" class="notice" style="display:none"></span>';
        echo '</p>';
        echo '<p>';
        submit_button( BAI_Slug_I18n::t('btn_save'), 'primary', 'bai_slug_save', false );
        echo '</p>';
        echo '</form>';
        echo '</div>'; // tab-basic

        echo '<div id="tab-glossary" class="bai-tab" style="display:none">';
        echo '<form method="post">';
        wp_nonce_field( 'bai_slug_settings' );
        echo '<table class="form-table">';
        echo '<tr><th>启用术语表</th><td><label><input type="checkbox" name="use_glossary" value="1" ' . checked( ! empty( $opt['use_glossary'] ), true, false ) . ' /> 启用</label></td></tr>';
        echo '<tr><th>术语表内容</th><td><textarea id="bai-glossary-text" name="glossary_text" rows="12" class="large-text" placeholder="中文=English\n关键词|Another">' . esc_textarea( $opt['glossary_text'] ) . '</textarea><p class="description">每行“源=译”或“源|译”。仅在标题包含时提示模型使用准确翻译。</p></td></tr>';
        echo '</table>';
        submit_button( BAI_Slug_I18n::t('btn_save'), 'primary', 'bai_slug_save', false );
        echo '</form>';
        echo '</div>'; // tab-glossary

        echo '<div id="tab-bulk" class="bai-tab" style="display:none">';
        if ( class_exists( 'BAI_Slug_Bulk_Fixed' ) ) { ( new BAI_Slug_Bulk_Fixed() )->render_inner(); }
        echo '</div>';

        echo '<div id="tab-manual" class="bai-tab" style="display:none">';
        if ( class_exists( 'BAI_Slug_Manage_Fixed' ) ) { ( new BAI_Slug_Manage_Fixed() )->render_inner(); }
        echo '</div>';

        echo '<div id="tab-logs" class="bai-tab" style="display:none">';
        echo '<h2>错误日志</h2>';
        if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::display(); }
        echo '</div>';

        echo '<div id="tab-guide" class="bai-tab" style="display:none">';
        echo '<p>源码地址：<a href="https://github.com/imsxx/wp-ai-slug" target="_blank" rel="noopener">https://github.com/imsxx/wp-ai-slug</a></p>';
        echo '</div>';

        echo '</div>'; // wrap
    }

    public function ajax_test_connectivity() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        check_ajax_referer( 'bai_slug_test', 'nonce' );
        $status = BAI_Slug_Helpers::test_connectivity( self::get_settings() );
        if ( $status['ok'] ) { wp_send_json_success( [ 'message' => 'OK: ' . $status['message'] ] ); }
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
        foreach ( [ 'provider','api_base','api_path','model' ] as $f ) {
            if ( array_key_exists( $f, $incoming ) ) {
                $v = is_string( $incoming[ $f ] ) ? $incoming[ $f ] : '';
                $opt[ $f ] = ( $f === 'api_base' ) ? esc_url_raw( wp_unslash( $v ) ) : sanitize_text_field( wp_unslash( $v ) );
            }
        }
        if ( array_key_exists( 'api_key', $incoming ) ) {
            $submitted = sanitize_text_field( wp_unslash( $incoming['api_key'] ) );
            $opt['api_key'] = ( strpos( $submitted, '*' ) === false ) ? $submitted : ( $existing['api_key'] ?? '' );
        }
        if ( array_key_exists( 'slug_max_chars', $incoming ) ) {
            $opt['slug_max_chars'] = max( 10, min( 120, intval( $incoming['slug_max_chars'] ) ) );
        }
        if ( array_key_exists( 'system_prompt', $incoming ) ) {
            $opt['system_prompt'] = sanitize_textarea_field( wp_unslash( $incoming['system_prompt'] ) );
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

?>
