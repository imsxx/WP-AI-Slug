<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_I18n {
    public static function lang() {
        $opt = BAI_Slug_Settings::get_settings();
        $l = isset( $opt['ui_lang'] ) ? $opt['ui_lang'] : 'zh';
        return in_array( $l, [ 'zh', 'en' ], true ) ? $l : 'zh';
    }

    public static function t( $key ) {
        $zh = [
            'menu_settings' => 'WP-AI-Slug',
            'page_settings_title' => 'WP-AI-Slug 设置',
            'btn_save' => '保存设置',
            'btn_test' => '测试连通性',
            'btn_go_bulk' => '打开批量处理',
            'guide_quickstart' => '快速开始',
            'guide_providers' => '模型与 API',
            'guide_glossary' => '术语表',
            'guide_bulk' => '异步批量',
            'guide_manual' => '手动编辑',
            'guide_logs' => '日志与排查',
            'guide_privacy' => '隐私与成本',
            'guide_oss' => '开源与许可',
            'bulk_title' => 'AI Slug 异步批量',
            'bulk_desc' => '以小批次、节流方式处理，降低服务器压力。可先用默认过滤器。',
            'bulk_notice' => '执行过程中请保持本页打开；关闭或离开会暂停。可稍后从“游标”处继续。',
            'label_post_types' => '文章类型',
            'label_batch_size' => '批量大小',
            'label_filters' => '过滤条件',
            'filter_only_non_ascii' => '仅处理包含非 ASCII 的 slug（或为空）',
            'filter_only_eq_sanitized' => '仅当 slug 等于标题的标准化值时替换',
            'filter_skip_user' => '跳过标记为“人工修改”的内容',
            'filter_skip_ai' => '跳过已由 AI 生成的内容',
            'filter_skip_ai_terms' => '跳过已由 AI 生成的术语',
            'desc_enabled_post_types' => '勾选后：(1) 新建/保存这些类型时自动生成英文 Slug；(2) 批量处理默认范围。包含主题/插件的自定义类型。',
            'desc_enabled_taxonomies' => '勾选后：(1) 新建这些分类法的术语时自动生成英文 Slug；(2) 术语批量处理默认范围。适用于分类/标签等非文章内容。',
            'btn_start' => '开始',
            'btn_stop' => '停止',
            'btn_reset_cursor' => '重置游标',
            'progress' => '已处理：%1$s；已扫描：%2$s',
            'cursor' => '当前游标：',
            'manual_title' => '手动编辑 Slug',
            'manual_desc' => '默认按 ID 倒序（新到旧）。保存后会将属性标记为“人工修改”，避免被覆盖。',
            'col_id' => 'ID',
            'col_title' => '标题',
            'col_slug' => 'Slug',
            'col_attr' => '属性',
            'col_actions' => '操作',
            'attr_ai' => 'AI 生成',
            'attr_user' => '人工修改',
            'btn_edit' => '编辑',
            'btn_done' => '完成',
            'saving' => '保存中...',
            'save_failed' => '保存失败',
            'network_error' => '网络错误',
            'applied' => '已应用',
            'rejected' => '已拒绝',
            'no_selection' => '未选择任何项',
        ];

        $en = [
            'menu_settings' => 'WP-AI-Slug',
            'page_settings_title' => 'WP-AI-Slug Settings',
            'btn_save' => 'Save Settings',
            'btn_test' => 'Test Connectivity',
            'btn_go_bulk' => 'Open Bulk Processor',
            'guide_quickstart' => 'Quick Start',
            'guide_providers' => 'Models & API',
            'guide_glossary' => 'Glossary',
            'guide_bulk' => 'Async Bulk',
            'guide_manual' => 'Manual Edit',
            'guide_logs' => 'Logs & Troubleshooting',
            'guide_privacy' => 'Privacy & Costs',
            'guide_oss' => 'Open Source & License',
            'bulk_title' => 'AI Slug Async Bulk',
            'bulk_desc' => 'Process in small batches with throttling to reduce server load. Try default filters first.',
            'bulk_notice' => 'Keep this page open during execution; closing or leaving pauses the job. You can resume later from the cursor.',
            'label_post_types' => 'Post Types',
            'label_batch_size' => 'Batch Size',
            'label_filters' => 'Filters',
            'filter_only_non_ascii' => 'Only slugs with non-ASCII (or empty)',
            'filter_only_eq_sanitized' => 'Replace only if slug equals sanitized title',
            'filter_skip_user' => 'Skip posts marked as user-edited',
            'filter_skip_ai' => 'Skip posts already AI-generated',
            'filter_skip_ai_terms' => 'Skip terms already AI-generated',
            'desc_enabled_post_types' => 'Checked: (1) Auto-generate slugs when creating/saving these post types; (2) Default scope for post bulk processing. Includes custom post types from themes/plugins.',
            'desc_enabled_taxonomies' => 'Checked: (1) Auto-generate slugs when creating terms of these taxonomies; (2) Default scope for term bulk processing. Applies to non-post content such as categories/tags.',
            'btn_start' => 'Start',
            'btn_stop' => 'Stop',
            'btn_reset_cursor' => 'Reset Cursor',
            'progress' => 'Processed: %1$s; Scanned: %2$s',
            'cursor' => 'Cursor:',
            'manual_title' => 'Manual Edit Slug',
            'manual_desc' => 'Sorted by ID desc (newest first). Saving marks attribute as "user-edited" to prevent overrides.',
            'col_id' => 'ID',
            'col_title' => 'Title',
            'col_slug' => 'Slug',
            'col_attr' => 'Attribute',
            'col_actions' => 'Actions',
            'attr_ai' => 'AI Generated',
            'attr_user' => 'User Edited',
            'btn_edit' => 'Edit',
            'btn_done' => 'Done',
            'saving' => 'Saving',
            'save_failed' => 'Save failed',
            'network_error' => 'Network error',
            'applied' => 'Applied',
            'rejected' => 'Rejected',
            'no_selection' => 'No selection',
        ];

        $dict = self::lang() === 'en' ? $en : $zh;
        return isset( $dict[ $key ] ) ? $dict[ $key ] : $key;
    }
}

?>
