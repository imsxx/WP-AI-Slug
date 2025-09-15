<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Settings {
    private static $option_name = 'bai_slug_settings';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX: 测试连通�?        add_action( 'wp_ajax_bai_test_connectivity', [ $this, 'ajax_test_connectivity' ] );
        // AJAX: 先保存配置再测试
        add_action( 'wp_ajax_bai_save_and_test', [ $this, 'ajax_save_and_test' ] );
        // AJAX: 快速切换界面语言
        add_action( 'wp_ajax_bai_set_lang', [ $this, 'ajax_set_lang' ] );
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
                'testing' => ( BAI_Slug_I18n::lang() === 'en' ? 'Testing�? : '测试�?..' ),
                'test'    => ( BAI_Slug_I18n::lang() === 'en' ? 'Test Connectivity' : '测试连通�? ),
                'request_failed' => ( BAI_Slug_I18n::lang() === 'en' ? 'Request failed' : '请求失败' ),
            ],
        ] );
    }

    public function register_settings() {
        register_setting( 'bai_slug_settings', self::$option_name );

        add_settings_section( 'bai_slug_main', '基础配置', function(){
            echo '<p>' . esc_html__( '配置大模型服务与生成规则。支�?OpenAI、DeepSeek 或兼�?OpenAI API 的自定义端点�?, 'wp-ai-slug' ) . '</p>';
        }, 'bai-slug-settings' );

        add_settings_field( 'provider', esc_html__( '服务提供�?, 'wp-ai-slug' ), [ $this, 'field_provider' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'api_base', esc_html__( 'API 基地址', 'wp-ai-slug' ), [ $this, 'field_api_base' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'api_path', esc_html__( '端点路径', 'wp-ai-slug' ), [ $this, 'field_api_path' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'api_key', esc_html__( 'API Key', 'wp-ai-slug' ), [ $this, 'field_api_key' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'model', esc_html__( '模型', 'wp-ai-slug' ), [ $this, 'field_model' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'max_tokens', esc_html__( '最�?tokens', 'wp-ai-slug' ), [ $this, 'field_max_tokens' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'enabled_post_types', esc_html__( '启用的文章类�?, 'wp-ai-slug' ), [ $this, 'field_enabled_post_types' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'enabled_taxonomies', esc_html__( '启用的分类法', 'wp-ai-slug' ), [ $this, 'field_enabled_taxonomies' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'custom_headers', esc_html__( '自定义请求头', 'wp-ai-slug' ), [ $this, 'field_custom_headers' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'ui_lang', esc_html__( '界面语言', 'wp-ai-slug' ), [ $this, 'field_ui_lang' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'use_glossary', '启用固定词库', [ $this, 'field_use_glossary' ], 'bai-slug-settings', 'bai_slug_main' );
        add_settings_field( 'glossary_text', '固定词库内容', [ $this, 'field_glossary_text' ], 'bai-slug-settings', 'bai_slug_main' );
    }

    public function render_settings_page() {
        if ( isset( $_POST['submit'] ) ) {
            check_admin_referer( 'bai_slug_settings-options' );

            $saved = self::get_settings();
            $submitted_key = isset( $_POST[ self::$option_name ]['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST[ self::$option_name ]['api_key'] ) ) : '';
            $api_key = ( strpos( $submitted_key, '*' ) === false ) ? $submitted_key : ( $saved['api_key'] ?? '' );

            $ui_lang = sanitize_text_field( wp_unslash( $_POST[ self::$option_name ]['ui_lang'] ?? 'zh' ) );
            $ui_lang = in_array( $ui_lang, [ 'zh', 'en' ], true ) ? $ui_lang : 'zh';
            $options = [
                'provider' => sanitize_text_field( wp_unslash( $_POST[ self::$option_name ]['provider'] ?? 'openai' ) ),
                'api_base' => esc_url_raw( wp_unslash( $_POST[ self::$option_name ]['api_base'] ?? '' ) ),
                'api_path' => sanitize_text_field( wp_unslash( $_POST[ self::$option_name ]['api_path'] ?? '/v1/chat/completions' ) ),
                'api_key'  => $api_key,
                'model'    => sanitize_text_field( wp_unslash( $_POST[ self::$option_name ]['model'] ?? '' ) ),
                'max_tokens' => max(1, intval( $_POST[ self::$option_name ]['max_tokens'] ?? 20 ) ),
                'enabled_post_types' => array_map( 'sanitize_text_field', (array) ( $_POST[ self::$option_name ]['enabled_post_types'] ?? [] ) ),
                'enabled_taxonomies' => array_map( 'sanitize_text_field', (array) ( $_POST[ self::$option_name ]['enabled_taxonomies'] ?? [] ) ),
                'custom_headers' => sanitize_textarea_field( wp_unslash( $_POST[ self::$option_name ]['custom_headers'] ?? '' ) ),
                'use_glossary' => ! empty( $_POST[ self::$option_name ]['use_glossary'] ) ? 1 : 0,
                'glossary_text' => sanitize_textarea_field( wp_unslash( $_POST[ self::$option_name ]['glossary_text'] ?? '' ) ),
                'ui_lang' => $ui_lang,
            ];

            update_option( self::$option_name, $options );
            add_settings_error( 'bai_slug_settings', 'saved', '设置已保存�?, 'updated' );
        }

        settings_errors( 'bai_slug_settings' );

        echo '<div class="wrap bai-slug-settings">';
        echo '<h1>' . esc_html( BAI_Slug_I18n::t('page_settings_title') ) . '</h1>';
        echo '<div class="bai-lang-bar" style="margin:6px 0 12px;">'
            . '<label style="margin-right:6px;">语言/Language</label>'
            . '<select id="bai-lang-switch">'
                . '<option value="zh"' . selected( BAI_Slug_I18n::lang(), 'zh', false ) . '>中文</option>'
                . '<option value="en"' . selected( BAI_Slug_I18n::lang(), 'en', false ) . '>English</option>'
            . '</select>'
        . '</div>';
        echo '<h2 class="nav-tab-wrapper" id="bai-tabs">'
            . '<a href="#tab-config" class="nav-tab nav-tab-active">基础配置</a>'
            . '<a href="#tab-glossary" class="nav-tab">固定词库</a>'
            . '<a href="#tab-bulk" class="nav-tab">异步处理</a>'
            . '<a href="#tab-manual" class="nav-tab">手动处理</a>'
            . '<a href="#tab-logs" class="nav-tab">运行日志</a>'
            . '<a href="#tab-guide" class="nav-tab">使用说明</a>'
        . '</h2>';
        echo '<p><a href="https://github.com/imsxx/wp-ai-slug" target="_blank" rel="noopener">https://github.com/imsxx/wp-ai-slug</a></p>';
        echo '<div id="tab-config" class="bai-tab" style="display:block">';
        echo '<p>' . esc_html__( '本插件基�?haayal-ai-slug-translator 构建，由 GPT5 实施开发�?, 'wp-ai-slug' ) . '</p>';

        echo '<form method="post" action="">';
        settings_fields( 'bai_slug_settings' );
        wp_nonce_field( 'bai_slug_settings-options' );
        do_settings_sections( 'bai-slug-settings' );

        echo '<p>';
        submit_button( esc_html( BAI_Slug_I18n::t('btn_save') ), 'primary', 'submit', false );
        echo ' <button type="button" id="bai-test-connectivity" class="button">' . esc_html( BAI_Slug_I18n::t('btn_test') ) . '</button>';
        echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=bai-slug-bulk' ) ) . '" class="button button-secondary">' . esc_html( BAI_Slug_I18n::t('btn_go_bulk') ) . '</a>';
        echo '</p>';
        echo '<div id="bai-test-result" class="notice" style="display:none;"></div>';

        echo '</form>';
        echo '</div>'; // end tab-config
        // Additional tabs
        echo '<div id="tab-glossary" class="bai-tab" style="display:none">';
        echo '<div class="card" style="margin-top:16px">';
        echo '<h2>固定词库</h2>';
        echo '<p>固定词库设置位于“基础配置”表单中。可点击下方按钮快速定位�?/p>';
        echo '<p><a href="#bai-glossary-text" class="button">定位到固定词库字�?/a></p>';
        echo '</div>';
        echo '</div>';

        echo '<div id="tab-bulk" class="bai-tab" style="display:none">';
        echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=bai-slug-bulk' ) ) . '" class="button button-primary">打开异步批处理页�?/a></p>';
        echo '</div>';

        echo '<div id="tab-manual" class="bai-tab" style="display:none">';
        echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=bai-slug-manage' ) ) . '" class="button button-primary">打开手动编辑页面</a></p>';
        echo '</div>';

        // 使用说明区域
        echo '<div class="card" style="margin-top:16px">';
        echo '<h2 id="guide">' . esc_html__( '使用说明', 'wp-ai-slug' ) . '</h2>';
        echo '<nav><ul style="display:flex;gap:12px;flex-wrap:wrap;list-style:none;padding-left:0;">'
            . '<li><a href="#quickstart">' . esc_html( BAI_Slug_I18n::t('guide_quickstart') ) . '</a></li>'
            . '<li><a href="#providers">' . esc_html( BAI_Slug_I18n::t('guide_providers') ) . '</a></li>'
            . '<li><a href="#glossary">' . esc_html( BAI_Slug_I18n::t('guide_glossary') ) . '</a></li>'
            . '<li><a href="#bulk">' . esc_html( BAI_Slug_I18n::t('guide_bulk') ) . '</a></li>'
            . '<li><a href="#manual">' . esc_html( BAI_Slug_I18n::t('guide_manual') ) . '</a></li>'
            . '<li><a href="#logs">' . esc_html( BAI_Slug_I18n::t('guide_logs') ) . '</a></li>'
            . '<li><a href="#privacy">' . esc_html( BAI_Slug_I18n::t('guide_privacy') ) . '</a></li>'
            . '<li><a href="#opensource">' . esc_html( BAI_Slug_I18n::t('guide_oss') ) . '</a></li>'
            . '</ul></nav>';

        echo '<h3 id="quickstart">快速开�?/h3>';
        echo '<ol>'
            . '<li>选择服务提供商（OpenAI / DeepSeek / 自定义兼容端点）�?/li>'
            . '<li>填写 API 基地址、端点路径（通常 /v1/chat/completions）、API Key、模型名�?/li>'
            . '<li>点击“测试连通性”验证配置有效�?/li>'
            . '<li>勾选需要自动生成的文章类型/分类法，保存设置�?/li>'
            . '<li>新内容在你未手动填写 slug 时自动生成英�?slug�?/li>'
            . '</ol>';

        echo '<h3 id="providers">模型�?API</h3>';
        echo '<ul>'
            . '<li><strong>OpenAI</strong>：基地址 https://api.openai.com，端�?/v1/chat/completions，示例模�?gpt-4o-mini�?/li>'
            . '<li><strong>DeepSeek</strong>：基地址 https://api.deepseek.com，端�?/v1/chat/completions，示例模�?deepseek-chat�?/li>'
            . '<li><strong>自定�?/strong>：需兼容 OpenAI Chat Completions 协议；可在“自定义请求头”中补充代理所需头部�?/li>'
            . '</ul>';

        echo '<h3 id="glossary">固定词库</h3>';
        echo '<p>用于行业/领域专有名词的统一翻译，避�?AI 自由发挥导致前后不一致�?/p>';
        echo '<ul>'
            . '<li>格式：每行一个映射，�?“火球术=fireball�?�?“神圣之锤|holy-hammer”�?/li>'
            . '<li>令牌开销：仅对“标题中命中的词条”做提示注入，通常只增加极少量 tokens�?/li>'
            . '<li>建议仅放必要的条目，保持短小精炼�?/li>'
            . '</ul>';

        echo '<h3 id="bulk">' . esc_html( BAI_Slug_I18n::t('guide_bulk') ) . '</h3>';
        echo '<p>' . esc_html( '在“AI Slug 异步批处理”页面，支持小批量、间隔执行的历史文章处理�? ) . '</p>';
        echo '<ul>'
            . '<li>支持按文章类型、每批数量（建议 5�?0）与筛选条件（�?ASCII、等于标准化、跳过人工）进行控制�?/li>'
            . '<li>处理时串行、小批量、带节流，避免拖垮服务器�?/li>'
            . '</ul>';

        echo '<h3 id="manual">手动编辑</h3>';
        echo '<p>“手动编�?Slug”页面展�?ID、标题、slug、属性，可行内编辑并保存�?/p>';
        echo '<ul>'
            . '<li>属性含义：ai（AI 生成�? user-edited（人工修改）。人工修改会被保护，不会被后续覆盖�?/li>'
            . '<li>支持按文章类型与属性筛选，默认�?ID 倒序（越新越靠前）�?/li>'
            . '</ul>';

        echo '<h3 id="logs">日志与排�?/h3>';
        echo '<ul>'
            . '<li>设置页底部提供错误日志展示与一键清空�?/li>'
            . '<li>常见问题：API Key 是否有效、模型名是否正确、端点是否可达、代理是否需要额外请求头�?/li>'
            . '</ul>';

        echo '<h3 id="privacy">隐私与费�?/h3>';
        echo '<ul>'
            . '<li>为生�?slug，本插件会向你配置的模型服务发送文章标�?术语名等内容�?/li>'
            . '<li>请遵守相应服务的使用条款，并留意账单与令牌用量；固定词库仅对命中词条注入提示以降低开销�?/li>'
            . '</ul>';

        echo '<h3 id="opensource">开源与许可</h3>';
        echo '<ul>'
            . '<li>GitHub 仓库�?a href="https://github.com/imsxx/wp-ai-slug" target="_blank" rel="noopener">https://github.com/imsxx/wp-ai-slug</a></li>'
            . '<li>本插件基�?GPLv2 or later 许可�?haayal-ai-slug-translator 衍生整合，保留原作者版权与声明�?/li>'
            . '<li>本插件由 GPT5 实施开发与本地化�?/li>'
            . '</ul>';

        echo '</div>';

        echo '</div>';
        echo '<div id="tab-logs" class="bai-tab" style="display:none">';
        echo '<h2>运行日志</h2>';
        BAI_Slug_Log::display();
        echo '</div>';
        echo '</div>';
    }

    // 字段渲染
    public function field_provider() {
        $opt = self::get_settings();
        $provider = $opt['provider'] ?? 'openai';
        ?>
        <select name="<?php echo esc_attr( self::$option_name ); ?>[provider]" id="bai-provider">
            <option value="openai" <?php selected( $provider, 'openai' ); ?>>OpenAI</option>
            <option value="deepseek" <?php selected( $provider, 'deepseek' ); ?>>DeepSeek</option>
            <option value="custom" <?php selected( $provider, 'custom' ); ?>>自定义兼容端�?/option>
        </select>
        <p class="description">选择服务提供商。自定义必须兼容 OpenAI Chat Completions 接口�?/p>
        <?php
    }

    public function field_api_base() {
        $opt = self::get_settings();
        $val = $opt['api_base'] ?? 'https://api.openai.com';
        ?>
        <input type="url" name="<?php echo esc_attr( self::$option_name ); ?>[api_base]" id="bai-api-base" value="<?php echo esc_attr( $val ); ?>" class="regular-text" placeholder="https://api.openai.com" />
        <p class="description">OpenAI: https://api.openai.com；DeepSeek: https://api.deepseek.com；自定义请填写你的代�?中转基地址�?/p>
        <?php
    }

    public function field_api_path() {
        $opt = self::get_settings();
        $val = $opt['api_path'] ?? '/v1/chat/completions';
        ?>
        <input type="text" name="<?php echo esc_attr( self::$option_name ); ?>[api_path]" id="bai-api-path" value="<?php echo esc_attr( $val ); ?>" class="regular-text" />
        <p class="description">通常�?/v1/chat/completions�?/p>
        <?php
    }

    public function field_api_key() {
        $opt = self::get_settings();
        $val = $opt['api_key'] ?? '';
        $display = $val ? str_repeat( '*', 12 ) : '';
        ?>
        <input type="text" name="<?php echo esc_attr( self::$option_name ); ?>[api_key]" id="bai-api-key" value="<?php echo esc_attr( $display ); ?>" class="regular-text" placeholder="sk-..." />
        <p class="description">留空表示不设置；若显示为星号表示沿用已保存的值�?/p>
        <?php
    }

    public function field_model() {
        $opt = self::get_settings();
        $provider = $opt['provider'] ?? 'openai';
        $model = $opt['model'] ?? '';
        $openai_models = [ 'gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo' ];
        $deepseek_models = [ 'deepseek-chat', 'deepseek-reasoner' ];
        $list = $provider === 'deepseek' ? $deepseek_models : ($provider === 'openai' ? $openai_models : []);
        ?>
        <input type="text" name="<?php echo esc_attr( self::$option_name ); ?>[model]" id="bai-model" value="<?php echo esc_attr( $model ?: ( $list[0] ?? '' ) ); ?>" class="regular-text" placeholder="gpt-4o-mini / deepseek-chat / 自定义模�? />
        <p class="description">可直接填写模型名。常见：OpenAI: gpt-4o-mini；DeepSeek: deepseek-chat�?/p>
        <?php
    }

    public function field_max_tokens() {
        $opt = self::get_settings();
        $val = max(1, intval( $opt['max_tokens'] ?? 20 ) );
        ?>
        <input type="number" name="<?php echo esc_attr( self::$option_name ); ?>[max_tokens]" id="bai-max-tokens" min="1" max="50" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">建议 10�?0。数值越大越耗时/费额�?/p>
        <?php
    }

    public function field_enabled_post_types() {
        $opt = self::get_settings();
        $enabled = (array) ( $opt['enabled_post_types'] ?? [] );
        $pts = get_post_types( [ 'show_ui' => true ], 'objects' );
        foreach ( $pts as $pt => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px;">';
            echo '<input type="checkbox" name="' . esc_attr( self::$option_name ) . '[enabled_post_types][]" value="' . esc_attr( $pt ) . '" ' . checked( in_array( $pt, $enabled, true ), true, false ) . ' /> ' . esc_html( $obj->labels->singular_name );
            echo '</label>';
        }
    }

    public function field_enabled_taxonomies() {
        $opt = self::get_settings();
        $enabled = (array) ( $opt['enabled_taxonomies'] ?? [] );
        $taxes = get_taxonomies( [ 'show_ui' => true ], 'objects' );
        foreach ( $taxes as $tx => $obj ) {
            echo '<label style="display:inline-block;margin-right:12px;">';
            echo '<input type="checkbox" name="' . esc_attr( self::$option_name ) . '[enabled_taxonomies][]" value="' . esc_attr( $tx ) . '" ' . checked( in_array( $tx, $enabled, true ), true, false ) . ' /> ' . esc_html( $obj->labels->singular_name );
            echo '</label>';
        }
    }

    public function field_custom_headers() {
        $opt = self::get_settings();
        $val = $opt['custom_headers'] ?? '';
        ?>
        <textarea name="<?php echo esc_attr( self::$option_name ); ?>[custom_headers]" rows="4" cols="60" class="large-text" placeholder="X-API-Key: your-proxy-key\nUser-Agent: WordPress-wp-ai-slug\n..."><?php echo esc_textarea( $val ); ?></textarea>
        <p class="description">每行一�?Header，格式：Header-Name: Header-Value。将�?Authorization 合并发送�?/p>
        <?php
    }

    public function field_ui_lang() {
        $opt = self::get_settings();
        $val = $opt['ui_lang'] ?? 'zh';
        ?>
        <select name="<?php echo esc_attr( self::$option_name ); ?>[ui_lang]" id="bai-ui-lang">
            <option value="zh" <?php selected( $val, 'zh' ); ?>>中文</option>
            <option value="en" <?php selected( $val, 'en' ); ?>>English</option>
        </select>
        <p class="description">切换后保存设置立即生效（仅影响本插件界面）�?/p>
        <?php
    }

    public function field_use_glossary() {
        $opt = self::get_settings();
        $val = ! empty( $opt['use_glossary'] );
        ?>
        <label><input type="checkbox" name="<?php echo esc_attr( self::$option_name ); ?>[use_glossary]" value="1" <?php checked( $val, true ); ?> /> 启用固定词库（仅在标题命中词库时注入提示，尽量节�?token�?/label>
        <?php
    }

    public function field_glossary_text() {
        $opt = self::get_settings();
        $val = $opt['glossary_text'] ?? '';
        ?>
        <textarea name="<?php echo esc_attr( self::$option_name ); ?>[glossary_text]" rows="8" cols="80" class="large-text" placeholder="每行一个映射，示例：\n火球�?fireball\n轻型护甲=light-armor\n神圣之锤=holy-hammer\nAI=ai\n"><?php echo esc_textarea( $val ); ?></textarea>
        <p class="description">用于确保行业术语/专有名词的固定翻译，避免 AI 自由发挥导致不一致。建议仅填写必要词条以控�?token 消耗�?/p>
        <?php
    }

    public static function get_settings() {
        $defaults = [
            'provider' => 'openai',
            'api_base' => 'https://api.openai.com',
            'api_path' => '/v1/chat/completions',
            'api_key'  => '',
            'model'    => 'gpt-4o-mini',
            'max_tokens' => 20,
            'enabled_post_types' => [ 'post', 'page' ],
            'enabled_taxonomies' => [],
            'custom_headers' => '',
            'use_glossary' => 1,
            'glossary_text' => '',
            'ui_lang' => 'zh',
        ];
        $opt = get_option( self::$option_name, [] );
        return wp_parse_args( $opt, $defaults );
    }

    public static function increment_counter() {
        $k = '_bai_slug_generated_counter';
        $c = (int) get_option( $k, 0 );
        update_option( $k, $c + 1 );
    }

    public function ajax_test_connectivity() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        check_ajax_referer( 'bai_slug_test', 'nonce' );

        $settings = self::get_settings();
        $status = BAI_Slug_Helpers::test_connectivity( $settings );
        if ( $status['ok'] ) {
            wp_send_json_success( [ 'message' => '连通性正常：' . $status['message'] ] );
        } else {
            wp_send_json_error( [ 'message' => '连通性失败：' . $status['message'] ] );
        }
    }

    // 保存当前表单中传来的 options 再进行测�?    public function ajax_save_and_test() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'permission denied' ], 403 );
        }
        check_ajax_referer( 'bai_slug_test', 'nonce' );

        $incoming = isset( $_POST['options'] ) ? (array) $_POST['options'] : [];
        $existing = self::get_settings();

        $api_key = isset( $incoming['api_key'] ) ? sanitize_text_field( wp_unslash( $incoming['api_key'] ) ) : '';
        if ( strpos( $api_key, '*' ) !== false ) {
            $api_key = $existing['api_key'] ?? '';
        }

        $merged = $existing;
        $merged['provider'] = isset( $incoming['provider'] ) ? sanitize_text_field( wp_unslash( $incoming['provider'] ) ) : $existing['provider'];
        $merged['api_base'] = isset( $incoming['api_base'] ) ? esc_url_raw( wp_unslash( $incoming['api_base'] ) ) : $existing['api_base'];
        $merged['api_path'] = isset( $incoming['api_path'] ) ? sanitize_text_field( wp_unslash( $incoming['api_path'] ) ) : $existing['api_path'];
        $merged['api_key']  = $api_key;
        $merged['model']    = isset( $incoming['model'] ) ? sanitize_text_field( wp_unslash( $incoming['model'] ) ) : $existing['model'];
        $merged['custom_headers'] = isset( $incoming['custom_headers'] ) ? sanitize_textarea_field( wp_unslash( $incoming['custom_headers'] ) ) : $existing['custom_headers'];
        $merged['use_glossary']   = ! empty( $incoming['use_glossary'] ) ? 1 : 0;
        $merged['glossary_text']  = isset( $incoming['glossary_text'] ) ? sanitize_textarea_field( wp_unslash( $incoming['glossary_text'] ) ) : $existing['glossary_text'];
        $merged['enabled_post_types'] = isset( $incoming['enabled_post_types'] ) && is_array( $incoming['enabled_post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $incoming['enabled_post_types'] ) ) : (array) ($existing['enabled_post_types'] ?? []);
        $merged['enabled_taxonomies'] = isset( $incoming['enabled_taxonomies'] ) && is_array( $incoming['enabled_taxonomies'] ) ? array_map( 'sanitize_text_field', wp_unslash( $incoming['enabled_taxonomies'] ) ) : (array) ($existing['enabled_taxonomies'] ?? []);

        update_option( self::$option_name, $merged );

        $status = BAI_Slug_Helpers::test_connectivity( $merged );
        if ( $status['ok'] ) {
            BAI_Slug_Log::add( '测试连通性成功：' . $status['message'] );
            wp_send_json_success( [ 'message' => '连通性正常：' . $status['message'] ] );
        }
        BAI_Slug_Log::add( '测试连通性失败：' . $status['message'] );
        wp_send_json_error( [ 'message' => '连通性失败：' . $status['message'] ] );
    }

    // 快速切�?UI 语言
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
