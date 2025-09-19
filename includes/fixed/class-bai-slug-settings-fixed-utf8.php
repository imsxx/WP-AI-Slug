<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Settings {
    private static $option_name = 'bai_slug_settings';
    private static $glossary_notice_key = 'glossary_notice';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_bai_test_connectivity', [ $this, 'ajax_test_connectivity' ] );
        add_action( 'wp_ajax_bai_save_and_test', [ $this, 'ajax_save_and_test' ] );
        add_action( 'wp_ajax_bai_set_lang', [ $this, 'ajax_set_lang' ] );
        add_action( 'wp_ajax_bai_set_flags', [ $this, 'ajax_set_flags' ] );
        add_action( 'wp_ajax_bai_site_topic', [ $this, 'ajax_site_topic' ] );
        // Glossary import/export
        add_action( 'wp_ajax_bai_glossary_export', [ $this, 'ajax_glossary_export' ] );
        add_action( 'wp_ajax_bai_glossary_import', [ $this, 'ajax_glossary_import' ] );
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
            'prompt_template_choice' => 'custom',
            // taxonomy prompt split and toggles
            'taxonomy_system_prompt' => '',
            'taxonomy_prompt_template_choice' => 'custom',
            'site_topic' => '',
            'site_topic_mode' => 'auto',
            'auto_generate_posts' => 1,
            'auto_generate_terms' => 1,
            'strict_mode' => 0,
        ];
        $opt = get_option( self::$option_name, [] );
        $opt = wp_parse_args( $opt, $defaults );
        if ( (string) ( $opt['site_topic_mode'] ?? 'auto' ) === 'auto' ) {
            $detected = self::detect_site_topic();
            if ( is_string( $detected ) && $detected !== '' ) {
                $opt['site_topic'] = $detected;
            }
        }
        return $opt;
    }

    public static function increment_counter() {
        $k = '_bai_slug_generated_counter';
        $c = (int) get_option( $k, 0 );
        update_option( $k, $c + 1 );
    }

    public function add_settings_page() {
        $page_title = BAI_Slug_I18n::t( 'page_settings_title' );
        $menu_title = BAI_Slug_I18n::t( 'menu_root' );
        $cap        = 'manage_options';
        $slug       = 'bai-slug-settings';
        $icon       = 'dashicons-translation';

        // Place near Posts menu (Posts is at 5). Use position 6.
        add_menu_page( $page_title, $menu_title, $cap, $slug, [ $this, 'render_settings_page' ], $icon, 6 );
        add_submenu_page( $slug, BAI_Slug_I18n::t( 'menu_settings' ), BAI_Slug_I18n::t( 'menu_settings' ), $cap, $slug, [ $this, 'render_settings_page' ] );

        add_action( 'admin_head', function() use ( $slug ) {
            global $submenu;
            if ( isset( $submenu[ $slug ] ) && isset( $submenu[ $slug ][0] ) ) {
                $submenu[ $slug ][0][0] = BAI_Slug_I18n::t( 'menu_settings' );
            }
        } );
    }

    public function enqueue_assets( $hook ) {
        $allowed = [
            'toplevel_page_bai-slug-settings',
            'wp-ai-slug_page_bai-slug-bulk',
            'wp-ai-slug_page_bai-slug-manage',
        ];
        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }

        wp_enqueue_style( 'bai-slug-admin', BAI_SLUG_URL . 'assets/admin.css', [], BAI_SLUG_VERSION );
        wp_enqueue_script( 'bai-slug-settings', BAI_SLUG_URL . 'assets/settings.js', [ 'jquery' ], BAI_SLUG_VERSION, true );

        $templates = $this->load_prompt_templates();
        $opt = self::get_settings();
        wp_localize_script( 'bai-slug-settings', 'BAISlug', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_test' ),
            'lang'     => BAI_Slug_I18n::lang(),
            'i18n'     => [
                'testing'        => BAI_Slug_I18n::lang() === 'en' ? 'Testing...' : '正在测试...',
                'test'           => BAI_Slug_I18n::lang() === 'en' ? 'Test Connectivity' : '测试连通性',
                'request_failed' => BAI_Slug_I18n::lang() === 'en' ? 'Request failed' : '请求失败',
                'template_help'  => BAI_Slug_I18n::t( 'prompt_template_help' ),
                'site_topic_help'=> BAI_Slug_I18n::t( 'site_topic_help' ),
                'site_topic_customize' => ( BAI_Slug_I18n::lang() === 'en' ? 'Customize' : '自定义' ),
                'site_topic_save'      => ( BAI_Slug_I18n::lang() === 'en' ? 'Save' : '保存' ),
                'site_topic_reset'     => ( BAI_Slug_I18n::lang() === 'en' ? 'Reset' : '初始化' ),
                'site_topic_auto_tip'  => ( BAI_Slug_I18n::lang() === 'en' ? 'Currently sourced from the homepage keyword; click “Customize” to edit and save.' : '当前来自首页 Keyword，可点击“自定义”进行编辑保存。' ),
            ],
            'siteTopic' => [ 'mode' => (string) ( $opt['site_topic_mode'] ?? 'auto' ), 'value' => (string) ( $opt['site_topic'] ?? '' ) ],
            'templates' => $templates,
            'auto' => [
                'label' => ( BAI_Slug_I18n::lang() === 'en' ? 'Auto-generate' : '自动生成' ),
                'desc'  => ( BAI_Slug_I18n::lang() === 'en' ? 'When off, new items will not auto-request AI on create; page actions still work.' : '关闭后，新增内容不再自动请求 AI；本页操作不受影响。' ),
                'posts' => (int) ( $opt['auto_generate_posts'] ?? 1 ),
                'terms' => (int) ( $opt['auto_generate_terms'] ?? 1 ),
            ],
            'taxonomies' => array_values( array_map( function( $obj ) {
                return [ 'name' => $obj->name, 'label' => $obj->labels->singular_name, 'show_ui' => (bool) $obj->show_ui ];
            }, get_taxonomies( [ 'show_ui' => true ], 'objects' ) ) ),
        ] );

        $zh = ( BAI_Slug_I18n::lang() === 'zh' );
        wp_enqueue_script( 'bai-slug-bulk', BAI_SLUG_URL . 'assets/bulk.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
        wp_localize_script( 'bai-slug-bulk', 'BAISlugBulk', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_queue' ),
            'i18n'     => [
                'request_failed' => $zh ? '请求失败' : 'Request failed',
                'done'           => $zh ? '已完成' : 'Done',
                'network_error'  => BAI_Slug_I18n::t( 'network_error' ),
                'cursor_reset'   => $zh ? '进度已重置' : 'Progress reset',
            ],
        ] );

        wp_enqueue_script( 'bai-slug-manage', BAI_SLUG_URL . 'assets/manage.js', [ 'jquery' ], BAI_SLUG_VERSION, true );
        wp_localize_script( 'bai-slug-manage', 'BAISlugManage', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bai_slug_manage' ),
            'queue_nonce' => wp_create_nonce( 'bai_slug_queue' ),
            'i18n'     => [
                'edit'          => BAI_Slug_I18n::t( 'btn_edit' ),
                'done'          => BAI_Slug_I18n::t( 'btn_done' ),
                'saving'        => BAI_Slug_I18n::t( 'saving' ),
                'save_failed'   => BAI_Slug_I18n::t( 'save_failed' ),
                'network_error' => BAI_Slug_I18n::t( 'network_error' ),
                'applied'       => BAI_Slug_I18n::t( 'applied' ),
                'rejected'      => BAI_Slug_I18n::t( 'rejected' ),
                'no_selection'  => BAI_Slug_I18n::t( 'no_selection' ),
                'applying'      => BAI_Slug_I18n::t( 'applying' ),
                'apply_done'    => BAI_Slug_I18n::t( 'apply_done' ),
                'failed'        => BAI_Slug_I18n::t( 'failed' ),
                'error_generic' => BAI_Slug_I18n::t( 'error_generic' ),
                'generate'      => BAI_Slug_I18n::t( 'generate' ),
                'generate_done' => BAI_Slug_I18n::t( 'generate_done' ),
                'start'         => BAI_Slug_I18n::t( 'btn_start' ),
                'pause'         => BAI_Slug_I18n::t( 'btn_stop' ),
                'reset'         => BAI_Slug_I18n::t( 'btn_reset_cursor' ),
            ],
        ] );
    }

    private function load_prompt_templates() {
        $dir = trailingslashit( BAI_SLUG_DIR ) . 'prompts/';
        $files = [
            'zh-default' => 'zh2en.md',
            'en-default' => 'en2en.md',
            'term-zh'    => 'Term-zh2en.md',
        ];
        $templates = [];
        foreach ( $files as $key => $file ) {
            $path = $dir . $file;
            $templates[ $key ] = file_exists( $path ) ? file_get_contents( $path ) : '';
        }
        return $templates;
    }

    private function select_options( $current, $options ) {
        $html = '';
        foreach ( $options as $val => $label ) {
            $html .= '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        return $html;
    }

    private static function glossary_redirect_url( $args = [] ) {
        $base = admin_url( 'admin.php?page=bai-slug-settings' );
        if ( empty( $args ) ) {
            return $base . '#tab-glossary';
        }
        $clean = [];
        foreach ( (array) $args as $key => $value ) {
            $clean[ sanitize_key( $key ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
        }
        $url = add_query_arg( $clean, $base );
        return $url . '#tab-glossary';
    }

    private static function output_glossary_notice() {
        $notice = isset( $_GET[ self::$glossary_notice_key ] ) ? sanitize_key( wp_unslash( $_GET[ self::$glossary_notice_key ] ) ) : '';
        if ( $notice === '' ) {
            return;
        }
        $class   = 'notice notice-info';
        $message = '';
        $count   = isset( $_GET['glossary_count'] ) ? intval( $_GET['glossary_count'] ) : 0;
        switch ( $notice ) {
            case 'imported':
                $class   = 'notice notice-success';
                $message = sprintf( esc_html( BAI_Slug_I18n::t( 'glossary_import_success' ) ), max( 0, $count ) );
                break;
            case 'no_file':
                $class   = 'notice notice-error';
                $message = esc_html( BAI_Slug_I18n::t( 'glossary_import_missing' ) );
                break;
            case 'open_failed':
                $class   = 'notice notice-error';
                $message = esc_html( BAI_Slug_I18n::t( 'glossary_import_failed' ) );
                break;
            case 'invalid':
                $class   = 'notice notice-warning';
                $message = esc_html( BAI_Slug_I18n::t( 'glossary_import_invalid' ) );
                break;
        }
        if ( $message !== '' ) {
            echo '<div class="' . esc_attr( $class ) . '"><p>' . $message . '</p></div>';
        }
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
            // slug_max_chars removed (controlled by prompt)
            if ( array_key_exists( 'prompt_template_choice', $_POST ) ) {
                $opt['prompt_template_choice'] = sanitize_text_field( wp_unslash( $_POST['prompt_template_choice'] ) );
            }
            if ( array_key_exists( 'taxonomy_prompt_template_choice', $_POST ) ) {
                $opt['taxonomy_prompt_template_choice'] = sanitize_text_field( wp_unslash( $_POST['taxonomy_prompt_template_choice'] ) );
            }
            if ( array_key_exists( 'site_topic_mode', $_POST ) ) {
                $mode = sanitize_text_field( wp_unslash( $_POST['site_topic_mode'] ) );
                if ( ! in_array( $mode, [ 'auto', 'custom' ], true ) ) { $mode = 'auto'; }
                $opt['site_topic_mode'] = $mode;
            }
            if ( array_key_exists( 'site_topic', $_POST ) ) {
                $opt['site_topic'] = sanitize_text_field( wp_unslash( $_POST['site_topic'] ) );
            }
            if ( array_key_exists( 'system_prompt', $_POST ) ) {
                $opt['system_prompt'] = sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) );
            }
            if ( array_key_exists( 'taxonomy_system_prompt', $_POST ) ) {
                $opt['taxonomy_system_prompt'] = sanitize_textarea_field( wp_unslash( $_POST['taxonomy_system_prompt'] ) );
            }
            if ( array_key_exists( 'enabled_post_types', $_POST ) && is_array( $_POST['enabled_post_types'] ) ) {
                $opt['enabled_post_types'] = array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_post_types'] ) );
            }
            if ( array_key_exists( 'enabled_taxonomies', $_POST ) && is_array( $_POST['enabled_taxonomies'] ) ) {
                $opt['enabled_taxonomies'] = array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_taxonomies'] ) );
            }
            $opt['use_glossary'] = ! empty( $_POST['use_glossary'] ) ? 1 : 0;
            $opt['strict_mode']  = ! empty( $_POST['strict_mode'] ) ? 1 : 0;
            if ( array_key_exists( 'glossary_text', $_POST ) ) {
                $opt['glossary_text'] = sanitize_textarea_field( wp_unslash( $_POST['glossary_text'] ) );
            }

            update_option( self::$option_name, $opt );
            echo '<div class="notice notice-success"><p>设置已保存。</p></div>';
        }

        self::output_glossary_notice();

        $opt = self::get_settings();
        $pts = get_post_types( [ 'show_ui' => true ], 'objects' );
        $taxes = get_taxonomies( [ 'show_ui' => true ], 'objects' );

        echo '<div class="wrap bai-slug-settings">';
        echo '<h1>' . esc_html( BAI_Slug_I18n::t('page_settings_title') ) . '</h1>';

        // Language quick switch
        echo '<p><label>界面语言： <select id="bai-lang-switch">'
            . $this->select_options( $opt['ui_lang'], [ 'zh' => '中文', 'en' => 'English' ] )
            . '</select></label></p>';

        echo '<h2 class="nav-tab-wrapper" id="bai-tabs">';
        echo '<a href="#tab-basic" class="nav-tab nav-tab-active">基本设置</a>';
        echo '<a href="#tab-glossary" class="nav-tab">术语表</a>';
        echo '<a href="#tab-manual" class="nav-tab">文章类处理</a>';
        echo '<a href="#tab-terms" class="nav-tab">分类法处理</a>';
        echo '<a href="#tab-logs" class="nav-tab">日志</a>';
        echo '<a href="#tab-guide" class="nav-tab">指南</a>';
        echo '</h2>';

        echo '<div id="tab-basic" class="bai-tab" style="">';
        echo '<form method="post">';
        wp_nonce_field( 'bai_slug_settings' );
        echo '<div class="card"><h2>AI 配置</h2><table class="form-table">';
        echo '<tr><th>服务提供商</th><td>';
        echo '<select id="bai-provider" name="provider">'
            . $this->select_options( $opt['provider'], [ 'openai'=>'OpenAI 兼容', 'deepseek'=>'DeepSeek', 'custom'=>'自定义' ] )
            . '</select>';
        echo '</td></tr>';
        echo '<tr><th>API 基址</th><td><input id="bai-api-base" type="text" name="api_base" value="' . esc_attr( $opt['api_base'] ) . '" class="regular-text" autocomplete="off" data-bwignore="true" data-lpignore="true" /></td></tr>';
        echo '<tr><th>接口路径</th><td><input id="bai-api-path" type="text" name="api_path" value="' . esc_attr( $opt['api_path'] ) . '" class="regular-text" autocomplete="off" data-bwignore="true" data-lpignore="true" /></td></tr>';
        echo '<tr><th>API Key</th><td><input id="bai-api-key" type="text" name="api_key" value="' . esc_attr( $opt['api_key'] ) . '" class="regular-text" autocomplete="off" data-bwignore="true" data-lpignore="true" /></td></tr>';
        echo '<tr><th>模型</th><td><input id="bai-model" type="text" name="model" value="' . esc_attr( $opt['model'] ) . '" class="regular-text" /></td></tr>';
        // 测试连通性放在 AI 配置里
        echo '<tr><th></th><td><button type="button" class="button" id="bai-test-connectivity">' . esc_html( BAI_Slug_I18n::t('btn_test') ) . '</button> <span id="bai-test-result" class="notice" style="display:none"></span></td></tr>';
        // 全局开关
        echo '<tr><th>全局开关</th><td><label style="margin-right:12px"><input type="checkbox" id="bai-auto-posts" ' . checked( ! empty( $opt['auto_generate_posts'] ), true, false ) . ' /> 自动生成文章类</label> <label><input type="checkbox" id="bai-auto-terms" ' . checked( ! empty( $opt['auto_generate_terms'] ), true, false ) . ' /> 自动生成分类法</label><p class="description">关闭后，新建文章/术语不会自动请求 AI。本页“生成/应用/拒绝”不受影响。</p></td></tr>';
        echo '</table></div>';

        // 全局变量（网站主要领域）
        echo '<div class="card"><h2>全局变量</h2><table class="form-table">';
        echo '<tr><th>网站主要领域</th><td>';
        echo '<div class="bai-template-row">';
        echo '<label class="screen-reader-text" for="bai-site-topic">' . esc_html( BAI_Slug_I18n::t( 'site_topic' ) ) . '</label>';
        echo '<input type="text" id="bai-site-topic" name="site_topic" class="regular-text" value="' . esc_attr( $opt['site_topic'] ) . '" placeholder="' . esc_attr( BAI_Slug_I18n::t( 'site_topic' ) ) . '" ' . ( ( (string) ( $opt['site_topic_mode'] ?? 'auto' ) === 'auto' ) ? 'readonly' : '' ) . ' />';
        echo '<input type="hidden" id="bai-site-topic-mode" name="site_topic_mode" value="' . esc_attr( (string) ( $opt['site_topic_mode'] ?? 'auto' ) ) . '" />';
        echo '<button type="button" class="button" id="bai-site-topic-customize">' . ( BAI_Slug_I18n::lang() === 'en' ? 'Customize' : '自定义' ) . '</button> ';
        echo '<button type="button" class="button" id="bai-site-topic-reset">' . ( BAI_Slug_I18n::lang() === 'en' ? 'Reset' : '初始化' ) . '</button>';
        echo '</div>';
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t( 'site_topic_help' ) ) . '</p>';
        echo '<div class="description" style="margin-top:8px;">'
            . '<strong>' . esc_html( BAI_Slug_I18n::t( 'vars_help_heading' ) ) . '</strong><br/>'
            . '<span>' . esc_html( BAI_Slug_I18n::t( 'vars_help_intro' ) ) . '</span>'
            . '<ul style="margin:6px 0 0 18px;list-style:disc;">'
            . '<li><code>[SITE_TOPIC]</code> – ' . esc_html( BAI_Slug_I18n::t( 'vars_help_site_topic' ) ) . '</li>'
            . '<li><code>[TITLE]</code> – ' . esc_html( BAI_Slug_I18n::t( 'vars_help_title' ) ) . '</li>'
            . '<li><code>[INDUSTRY]</code> – ' . esc_html( BAI_Slug_I18n::t( 'vars_help_industry' ) ) . '</li>'
            . '<li><code>[BODY_EXCERPT]</code> – ' . esc_html( BAI_Slug_I18n::t( 'vars_help_excerpt' ) ) . '</li>'
            . '<li><code>[POST_TYPE]</code> – ' . esc_html( BAI_Slug_I18n::t( 'vars_help_post_type' ) ) . '</li>'
            . '<li><code>[RELATED_TITLES]</code> – 当前术语下相关的文章标题集合（最多 6 条）。</li>'
            . '</ul>'
            . ( ( (string) ( $opt['site_topic_mode'] ?? 'auto' ) === 'auto' ) ? '<p class="description" id="bai-site-topic-tip">' . ( BAI_Slug_I18n::lang() === 'en' ? 'Currently sourced from the homepage keyword; click “Customize” to edit and save.' : '当前来自首页 Keyword，可点击“自定义”进行编辑保存。' ) . '</p>' : '' )
            . '</div>';
        echo '</td></tr>';
        echo '</table></div>';

        // 高级选项
        echo '<div class="card"><h2>高级选项</h2><table class="form-table">';
        echo '<tr><th>严格模式</th><td>';
        echo '<label><input type="checkbox" id="bai-strict-mode" name="strict_mode" value="1" ' . checked( ! empty( $opt['strict_mode'] ), true, false ) . ' /> 仅在“批处理”中处理以下条目：</label>';
        echo '<ul style="margin:6px 0 0 18px;list-style:disc;">'
             . '<li>slug 为空；或</li>'
             . '<li>slug 等于标题的标准化结果（sanitize_title(标题)）。</li>'
             . '</ul>';
        echo '<p class="description">用途：批处理用于老内容的 slug 生成。开启严格模式后，<strong>仅</strong>处理“待生成 slug”的严格子集，尽量避免改动已有、可能已经用于 SEO 的 slug。单条手动生成与新内容自动生成不受此设置影响。</p>';
        echo '<p class="description">运行原理：在收集“待生成 slug”候选时进一步用上述规则做二次过滤，过滤掉已有非空且与标准化标题不同的 slug 条目，并在队列启动时记录过滤结果（候选总数 → 过滤后总数）。</p>';
        echo '</td></tr>';
        echo '</table></div>';

        // 文章类配置
        echo '<div class="card"><h2>文章类配置</h2><table class="form-table">';
        // 文章类 prompt 模板 + 文章类 prompt
        $template_options = [
            'zh-default' => BAI_Slug_I18n::t( 'prompt_template_zh' ),
            'en-default' => BAI_Slug_I18n::t( 'prompt_template_en' ),
            'custom'     => BAI_Slug_I18n::t( 'prompt_template_custom' ),
        ];
        if ( ! isset( $template_options[ $opt['prompt_template_choice'] ] ) ) { $opt['prompt_template_choice'] = 'custom'; }
        echo '<tr><th>文章类 prompt 模板</th><td>';
        echo '<select id="bai-post-template-select" name="prompt_template_choice" class="bai-template-select">' . $this->select_options( $opt['prompt_template_choice'], $template_options ) . '</select>';
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t( 'prompt_template_help' ) ) . '</p>';
        echo '</td></tr>';
        echo '<tr><th>文章类 prompt</th><td><textarea id="bai-system-prompt" name="system_prompt" rows="6" class="large-text">' . esc_textarea( (string) $opt['system_prompt'] ) . '</textarea></td></tr>';

        // 文章类型（与文章类 prompt 归为一组）
        echo '<tr><th>' . esc_html( BAI_Slug_I18n::t('label_post_types') ) . '</th><td>';
        foreach ( $pts as $pt => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="enabled_post_types[]" value="' . esc_attr( $pt ) . '" ' . checked( in_array( $pt, (array) $opt['enabled_post_types'], true ), true, false ) . ' /> ' . esc_html( $obj->labels->singular_name ) . '</label>';
        }
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t('desc_enabled_post_types') ) . '</p>';
        echo '</td></tr>';

        echo '</table></div>';

        // 分类法配置
        echo '<div class="card"><h2>分类法配置</h2><table class="form-table">';
        // Taxonomy prompt section (分类法 Prompt)
        echo '<tr><th>分类法 prompt 模板</th><td>';
        $is_zh = ( BAI_Slug_I18n::lang() === 'zh' );
        $term_tpl_opts = $is_zh ? [ 'term-zh' => '标签/分类（中文→英文）', 'custom' => BAI_Slug_I18n::t( 'prompt_template_custom' ) ] : [ 'custom' => BAI_Slug_I18n::t( 'prompt_template_custom' ) ];
        if ( ! isset( $term_tpl_opts[ $opt['taxonomy_prompt_template_choice'] ?? 'custom' ] ) ) { $opt['taxonomy_prompt_template_choice'] = 'custom'; }
        echo '<select id="bai-term-template-select" name="taxonomy_prompt_template_choice" class="bai-template-select">' . $this->select_options( (string) ( $opt['taxonomy_prompt_template_choice'] ?? 'custom' ), $term_tpl_opts ) . '</select>';
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t( 'prompt_template_help' ) ) . '</p>';
        echo '<p><label for="bai-taxonomy-system-prompt">分类法 prompt</label></p>';
        echo '<p><textarea id="bai-taxonomy-system-prompt" name="taxonomy_system_prompt" rows="6" class="large-text">' . esc_textarea( (string) ( $opt['taxonomy_system_prompt'] ?? '' ) ) . '</textarea></p>';
        echo '</td></tr>';

        echo '<tr><th>启用的分类法</th><td>';
        foreach ( $taxes as $tx => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="enabled_taxonomies[]" value="' . esc_attr( $tx ) . '" ' . checked( in_array( $tx, (array) $opt['enabled_taxonomies'], true ), true, false ) . ' /> ' . esc_html( $obj->labels->singular_name ) . '</label>';
        }
        echo '<p class="description">' . esc_html( BAI_Slug_I18n::t('desc_enabled_taxonomies') ) . '</p>';
        echo '</td></tr>';
        echo '</table></div>';

        echo '<p>';
        submit_button( BAI_Slug_I18n::t('btn_save'), 'primary', 'bai_slug_save', false );
        echo '</p>';
        echo '</form>';
        echo '</div>'; // tab-basic

        echo '<div id="tab-glossary" class="bai-tab" style="display:none">';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
        wp_nonce_field( 'bai_slug_settings' );
        echo '<table class="form-table">';
        echo '<tr><th>启用术语表</th><td><label><input type="checkbox" name="use_glossary" value="1" ' . checked( ! empty( $opt['use_glossary'] ), true, false ) . ' /> 启用</label></td></tr>';
        echo '<tr><th>术语表内容</th><td><textarea id="bai-glossary-text" name="glossary_text" rows="12" class="large-text" placeholder="中文=English\n关键词|Another\n魔法师-巫师-法师=Sorcerer">' . esc_textarea( $opt['glossary_text'] ) . '</textarea><p class="description">每行“源=译”或“源|译”。多个源词用“-”连接，保留连字符可写成 <code>\-</code>。仅在标题包含时提示模型使用准确翻译。</p></td></tr>';
        echo '<tr><th>导入/导出</th><td>';
        $dl = esc_url( admin_url( 'admin-ajax.php?action=bai_glossary_export&nonce=' . urlencode( wp_create_nonce( 'bai_slug_test' ) ) ) );
        echo '<p><a class="button" href="' . $dl . '">导出 CSV</a></p>';
        echo '<p><label>导入 CSV：<input type="file" name="glossary_csv" accept=".csv" /></label> '
            . '<input type="hidden" name="action" value="bai_glossary_import" />'
            . '<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'bai_slug_test' ) ) . '" />'
            . '<button type="submit" name="bai_glossary_import_btn" class="button">导入 CSV</button></p>';
        echo '<p class="description">仅支持 CSV 文件导入，第一列为“源”，第二列为“译”。过长的术语表会占用更多服务器资源并增加 AI token 消耗，请仅保留必要术语。</p>';
        echo '</td></tr>';
        echo '</table>';
        submit_button( BAI_Slug_I18n::t('btn_save'), 'primary', 'bai_slug_save', false );
        echo '</form>';
        echo '</div>'; // tab-glossary


        echo '<div id="tab-manual" class="bai-tab" style="display:none">';
        // 全局开关已移至“AI 配置”卡片
        if ( class_exists( 'BAI_Slug_Manage_Fixed' ) ) { ( new BAI_Slug_Manage_Fixed() )->render_inner(); }
        echo '</div>';

        echo '<div id="tab-terms" class="bai-tab" style="display:none">';
        // 全局开关已移至“AI 配置”卡片
        echo '<div class="card" id="bai-terms-filters">';
        echo '<h2>' . esc_html__( '分类法处理', 'wp-ai-slug' ) . '</h2>';
        echo '<div class="filters" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">';
        echo '<label>分类法 <select id="bai-terms-tax"></select></label>';
        echo '<label>状态筛选 <select id="bai-terms-attr">'
            . '<option value="">全部</option>'
            . '<option value="pending-slug">待生成Slug</option>'
            . '<option value="proposed">待处理提案</option>'
            . '<option value="ai">AI 生成</option>'
            . '<option value="user-edited">人工修改</option>'
            . '</select></label>';
        $term_search_placeholder = BAI_Slug_I18n::t( 'search_terms_placeholder' );
        echo '<label>搜索 <input type="text" id="bai-terms-search" value="" placeholder="' . esc_attr( $term_search_placeholder ) . '" style="min-width:180px;"></label>';
        echo '<label>每页 <input type="number" id="bai-terms-per" value="20" min="5" max="100" style="width:80px;"></label>';
        echo '<button class="button" id="bai-terms-refresh">刷新</button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="card" id="bai-terms-list">';
        echo '<div class="toolbar" style="margin-bottom:8px;">'
            . '<label><input type="checkbox" id="bai-terms-select-all"> 全选</label> '
            . '<button class="button" id="bai-term-generate-selected">生成</button> '
            . '<button class="button button-primary" id="bai-term-apply-selected">应用</button> '
            . '<button class="button" id="bai-term-reject-selected">拒绝</button>'
            . '</div>';
        echo '<table class="widefat striped"><thead><tr><th width="28"><input type="checkbox" id="bai-terms-select-all-top"></th><th width="80">ID</th><th>术语</th><th width="140">分类法</th><th>Slug</th><th width="100">属性</th><th width="200">提案</th><th width="180">操作</th></tr></thead><tbody id="bai-terms-tbody"><tr><td colspan="8">加载中…</td></tr></tbody></table>';
        echo '<div class="tablenav"><div class="tablenav-pages" id="bai-terms-pagination"></div></div>';
        echo '</div>';
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
        // slug_max_chars removed
        if ( array_key_exists( 'prompt_template_choice', $incoming ) ) {
            $opt['prompt_template_choice'] = sanitize_text_field( wp_unslash( $incoming['prompt_template_choice'] ) );
        }
        if ( array_key_exists( 'site_topic_mode', $incoming ) ) {
            $mode = sanitize_text_field( wp_unslash( $incoming['site_topic_mode'] ) );
            if ( ! in_array( $mode, [ 'auto', 'custom' ], true ) ) { $mode = 'auto'; }
            $opt['site_topic_mode'] = $mode;
        }
        if ( array_key_exists( 'site_topic', $incoming ) ) {
            $opt['site_topic'] = sanitize_text_field( wp_unslash( $incoming['site_topic'] ) );
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

    // Merge partial flags/options from UI
    public function ajax_set_flags() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        check_ajax_referer( 'bai_slug_test', 'nonce' );
        $incoming = isset( $_POST['options'] ) ? (array) $_POST['options'] : [];
        $opt = self::get_settings();
        // booleans
        foreach ( [ 'use_glossary','auto_generate_posts','auto_generate_terms' ] as $f ) {
            if ( array_key_exists( $f, $incoming ) ) { $opt[ $f ] = ! empty( $incoming[ $f ] ) ? 1 : 0; }
        }
        // text/numbers
        foreach ( [ 'provider','api_base','api_path','api_key','model','prompt_template_choice','taxonomy_prompt_template_choice','site_topic_mode','site_topic' ] as $f ) {
            if ( array_key_exists( $f, $incoming ) ) {
                $v = is_string( $incoming[ $f ] ) ? $incoming[ $f ] : '';
                $opt[ $f ] = ( $f === 'api_base' ) ? esc_url_raw( wp_unslash( $v ) ) : sanitize_text_field( wp_unslash( $v ) );
            }
        }
        // slug_max_chars removed
        if ( array_key_exists( 'enabled_post_types', $incoming ) && is_array( $incoming['enabled_post_types'] ) ) {
            $opt['enabled_post_types'] = array_map( 'sanitize_text_field', wp_unslash( $incoming['enabled_post_types'] ) );
        }
        if ( array_key_exists( 'enabled_taxonomies', $incoming ) && is_array( $incoming['enabled_taxonomies'] ) ) {
            $opt['enabled_taxonomies'] = array_map( 'sanitize_text_field', wp_unslash( $incoming['enabled_taxonomies'] ) );
        }
        if ( array_key_exists( 'system_prompt', $incoming ) ) {
            $opt['system_prompt'] = sanitize_textarea_field( wp_unslash( $incoming['system_prompt'] ) );
        }
        if ( array_key_exists( 'taxonomy_system_prompt', $incoming ) ) {
            $opt['taxonomy_system_prompt'] = sanitize_textarea_field( wp_unslash( $incoming['taxonomy_system_prompt'] ) );
        }
        if ( array_key_exists( 'glossary_text', $incoming ) ) {
            $opt['glossary_text'] = sanitize_textarea_field( wp_unslash( $incoming['glossary_text'] ) );
        }
        if ( array_key_exists( 'strict_mode', $incoming ) ) {
            $opt['strict_mode'] = ! empty( $incoming['strict_mode'] ) ? 1 : 0;
        }
        update_option( self::$option_name, $opt );
        wp_send_json_success( [ 'message' => 'OK' ] );
    }

    // ---------- Glossary import/export ----------
    public function ajax_glossary_export() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'denied' ); }
        check_admin_referer( 'bai_slug_test', 'nonce' );
        $opt  = self::get_settings();
        $text = (string) ( $opt['glossary_text'] ?? '' );
        $rows = [];
        if ( $text !== '' ) {
            $lines = preg_split( "/\r?\n/", $text );
            foreach ( (array) $lines as $line ) {
                $line = trim( (string) $line );
                if ( $line === '' ) { continue; }
                if ( strpos( $line, '=' ) !== false ) {
                    list( $src, $dst ) = array_map( 'trim', explode( '=', $line, 2 ) );
                } elseif ( strpos( $line, '|' ) !== false ) {
                    list( $src, $dst ) = array_map( 'trim', explode( '|', $line, 2 ) );
                } else {
                    continue;
                }
                if ( $src === '' || $dst === '' ) { continue; }
                $rows[] = [ $src, $dst ];
            }
        }
        $csv = "source,dest\n";
        foreach ( $rows as $row ) {
            list( $src, $dst ) = $row;
            $csv .= '"' . str_replace( '"', '""', $src ) . '",' . '"' . str_replace( '"', '""', $dst ) . '"' . "\n";
        }
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="glossary.csv"' );
        echo $csv; exit;
    }

    public function ajax_glossary_import() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'denied' ); }
        check_admin_referer( 'bai_slug_test', 'nonce' );
        if ( empty( $_FILES['glossary_csv']['tmp_name'] ) ) {
            wp_safe_redirect( self::glossary_redirect_url( [ self::$glossary_notice_key => 'no_file' ] ) );
            exit;
        }
        $fh = fopen( $_FILES['glossary_csv']['tmp_name'], 'r' );
        if ( ! $fh ) {
            wp_safe_redirect( self::glossary_redirect_url( [ self::$glossary_notice_key => 'open_failed' ] ) );
            exit;
        }
        $lines = [];
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( ! is_array( $row ) || count( $row ) < 2 ) { continue; }
            $src = trim( (string) $row[0] );
            $dst = trim( (string) $row[1] );
            if ( $src === '' || $dst === '' ) { continue; }
            if ( empty( $lines ) && strcasecmp( $src, 'source' ) === 0 && strcasecmp( $dst, 'dest' ) === 0 ) { continue; }
            $lines[] = $src . '=' . $dst;
        }
        fclose( $fh );
        $opt = self::get_settings();
        $opt['glossary_text'] = implode( "\n", $lines );
        update_option( self::$option_name, $opt );
        $count = count( BAI_Slug_Helpers::safe_parse_glossary_lines( implode( "\n", $lines ) ) );
        $args = [ self::$glossary_notice_key => ( $count > 0 ? 'imported' : 'invalid' ) ];
        if ( $count > 0 ) {
            $args['glossary_count'] = $count;
        }
        wp_safe_redirect( self::glossary_redirect_url( $args ) );
        exit;
    }

    private static function detect_site_topic() {
        // Cache to avoid repeated fetch during settings navigation
        $cached = get_transient( 'bai_slug_detected_keywords' );
        if ( is_string( $cached ) && $cached !== '' ) { return $cached; }

        $topic = '';
        // 1) Try parse from rendered homepage <meta name="keywords" content="...">
        $home = home_url( '/' );
        $resp = wp_remote_get( $home, [ 'timeout' => 5, 'redirection' => 3 ] );
        if ( ! is_wp_error( $resp ) ) {
            $code = wp_remote_retrieve_response_code( $resp );
            if ( $code >= 200 && $code < 300 ) {
                $html = (string) wp_remote_retrieve_body( $resp );
                if ( $html !== '' ) {
                    if ( preg_match( '/<meta[^>]*\bname=["\']keywords["\'][^>]*\bcontent=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
                        $topic = html_entity_decode( trim( $m[1 ] ), ENT_QUOTES );
                    }
                }
            }
        }

        // 2) Fallback to common SEO plugin metas on front page
        if ( $topic === '' ) {
            $front_id = 0;
            if ( get_option( 'show_on_front' ) === 'page' ) {
                $front_id = (int) get_option( 'page_on_front' );
            }
            $get_meta_kw = function( $post_id, $key ) {
                $v = get_post_meta( $post_id, $key, true );
                if ( is_string( $v ) && $v !== '' ) { return trim( $v ); }
                if ( is_array( $v ) ) { return trim( implode( ', ', array_filter( array_map( 'trim', $v ) ) ) ); }
                return '';
            };
            if ( $front_id ) {
                foreach ( [ 'rank_math_focus_keyword', '_aioseo_keywords', '_seopress_analysis_target_kw', '_yoast_wpseo_metakeywords' ] as $mk ) {
                    $val = $get_meta_kw( $front_id, $mk );
                    if ( $val !== '' ) { $topic = $val; break; }
                }
                if ( $topic !== '' && strpos( $topic, '[' ) !== false ) {
                    $maybe = json_decode( $topic, true );
                    if ( is_array( $maybe ) ) {
                        $topic = trim( implode( ', ', array_filter( array_map( 'trim', $maybe ) ) ) );
                    }
                }
            }
        }

        // 3) Final fallback: site tagline
        if ( $topic === '' ) {
            $topic = (string) get_option( 'blogdescription', '' );
        }
        $topic = trim( (string) $topic );
        set_transient( 'bai_slug_detected_keywords', $topic, 15 * MINUTE_IN_SECONDS );
        return $topic;
    }

    public function ajax_site_topic() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        check_ajax_referer( 'bai_slug_test', 'nonce' );
        $op = isset( $_POST['op'] ) ? sanitize_text_field( wp_unslash( $_POST['op'] ) ) : 'detect';
        $opt = get_option( self::$option_name, [] );
        if ( $op === 'set' ) {
            $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'auto';
            if ( ! in_array( $mode, [ 'auto', 'custom' ], true ) ) { $mode = 'auto'; }
            $opt['site_topic_mode'] = $mode;
            if ( $mode === 'custom' ) {
                $val = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
                $opt['site_topic'] = $val;
            } else {
                delete_transient( 'bai_slug_detected_keywords' );
            }
            update_option( self::$option_name, $opt );
        }
        $current = self::get_settings();
        wp_send_json_success( [ 'mode' => (string) ( $current['site_topic_mode'] ?? 'auto' ), 'site_topic' => (string) ( $current['site_topic'] ?? '' ) ] );
    }
}

?>









