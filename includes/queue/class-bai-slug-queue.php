<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Queue {
    const OPTION_JOB   = 'bai_slug_job';
    const CHUNK_PREFIX = 'bai_slug_queue_chunk_';
    const TERMS_JOB    = 'bai_slug_terms_job';
    const TERMS_IDS    = 'bai_slug_terms_ids';
    private static $current_posts_search = '';
    private static $current_terms_search = '';

    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );
        add_action( 'bai_slug_tick', [ __CLASS__, 'tick' ] );

        add_action( 'wp_ajax_bai_slug_queue_start', [ __CLASS__, 'ajax_start' ] );
        add_action( 'wp_ajax_bai_slug_queue_pause', [ __CLASS__, 'ajax_pause' ] );
        add_action( 'wp_ajax_bai_slug_queue_reset', [ __CLASS__, 'ajax_reset' ] );
        add_action( 'wp_ajax_bai_slug_queue_progress', [ __CLASS__, 'ajax_progress' ] );
        add_action( 'wp_ajax_bai_slug_queue_apply', [ __CLASS__, 'ajax_apply' ] );
        add_action( 'wp_ajax_bai_slug_queue_reject', [ __CLASS__, 'ajax_reject' ] );
        // Single on-demand generation for Manage page
        add_action( 'wp_ajax_bai_slug_generate_one', [ __CLASS__, 'ajax_generate_one' ] );

        // Terms batch (simple polling; no cron)
        add_action( 'wp_ajax_bai_slug_terms_start', [ __CLASS__, 'ajax_terms_start' ] );
        add_action( 'wp_ajax_bai_slug_terms_progress', [ __CLASS__, 'ajax_terms_progress' ] );
        add_action( 'wp_ajax_bai_slug_terms_reset', [ __CLASS__, 'ajax_terms_reset' ] );
        // Visible proposals fetch for current page rows
        add_action( 'wp_ajax_bai_slug_get_proposals', [ __CLASS__, 'ajax_get_proposals' ] );
        // Global pending proposals count
        add_action( 'wp_ajax_bai_slug_count_proposals', [ __CLASS__, 'ajax_count_proposals' ] );
        // Pending-slug count (no _slug_source and no _proposed_slug)
        add_action( 'wp_ajax_bai_slug_count_pending', [ __CLASS__, 'ajax_count_pending' ] );
        // Terms list + single-term actions (for 标签处理 列表视图)
        add_action( 'wp_ajax_bai_terms_list', [ __CLASS__, 'ajax_terms_list' ] );
        add_action( 'wp_ajax_bai_term_generate_one', [ __CLASS__, 'ajax_term_generate_one' ] );
        add_action( 'wp_ajax_bai_term_apply', [ __CLASS__, 'ajax_term_apply' ] );
        add_action( 'wp_ajax_bai_term_reject', [ __CLASS__, 'ajax_term_reject' ] );
        // Posts list for 文章类处理 AJAX 视图
        add_action( 'wp_ajax_bai_posts_list', [ __CLASS__, 'ajax_posts_list' ] );
    }

    public static function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['bai_slug_every_minute'] ) ) {
            $schedules['bai_slug_every_minute'] = [ 'interval' => 60, 'display' => 'Every Minute (BAI Slug)' ];
        }
        return $schedules;
    }

    private static function schedule_if_needed() {
        if ( ! wp_next_scheduled( 'bai_slug_tick' ) ) {
            wp_schedule_event( time() + 10, 'bai_slug_every_minute', 'bai_slug_tick' );
        }
    }

    private static function clear_schedule() {
        if ( wp_next_scheduled( 'bai_slug_tick' ) ) {
            wp_clear_scheduled_hook( 'bai_slug_tick' );
        }
    }

    public static function ajax_start() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        check_ajax_referer( 'bai_slug_queue', 'nonce' );

        $batch = max( 1, min( 50, intval( $_POST['batch_size'] ?? 5 ) ) );
        $post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : [];
        if ( empty( $post_types ) ) {
            $settings = method_exists( 'BAI_Slug_Settings', 'get_settings' ) ? BAI_Slug_Settings::get_settings() : [];
            $post_types = (array) ( $settings['enabled_post_types'] ?? [ 'post', 'page' ] );
        }
        // Options from UI (optional)
        $scheme = in_array( (string) ( $_POST['scheme'] ?? 'title' ), [ 'title','content','custom' ], true ) ? (string) $_POST['scheme'] : 'title';
        $custom_prompt = sanitize_textarea_field( wp_unslash( (string) ( $_POST['custom_prompt'] ?? '' ) ) );
        $delimiter = in_array( (string) ( $_POST['delimiter'] ?? '-' ), [ '-', '_' , 'custom' ], true ) ? (string) $_POST['delimiter'] : '-';
        $delimiter_custom = sanitize_text_field( wp_unslash( (string) ( $_POST['delimiter_custom'] ?? '' ) ) );
        $collision = in_array( (string) ( $_POST['collision'] ?? 'append_date' ), [ 'append_date','mark' ], true ) ? (string) $_POST['collision'] : 'append_date';
        $skip_ai = ! empty( $_POST['skip_ai'] );
        $skip_user = ! empty( $_POST['skip_user'] );
        $pending_only = ! empty( $_POST['pending_only'] );

        // Build queue once
        self::reset_job_and_queue();
        $ids = self::collect_candidate_ids( $post_types, $skip_ai, $skip_user, $pending_only );
        if ( empty( $ids ) ) {
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '队列启动失败：无待处理条目（pending_only=' . ( $pending_only ? '1' : '0' ) . '）' ); }
            wp_send_json_error( [ 'message' => '无待处理条目' ] );
        }
        self::store_queue_chunks( $ids );

        $job = [
            'running'        => true,
            'post_types'     => $post_types,
            'batch'          => (int) $batch,
            'chunks'         => (int) self::count_chunks(),
            'chunk_size'     => 1000,
            'current_chunk'  => 1,
            'offset'         => 0,
            'total'          => (int) count( $ids ),
            'processed'      => 0,
            'cursor'         => 0,
            'started_at'     => time(),
            'updated_at'     => time(),
            'finished_at'    => 0,
            'logs'           => [],
            'last_error'     => '',
            // generation preferences
            'scheme'         => $scheme,
            'custom_prompt'  => $custom_prompt,
            'delimiter'      => $delimiter,
            'delimiter_custom'=> $delimiter_custom,
            'collision'      => $collision,
            'skip_ai'        => $skip_ai ? 1 : 0,
        ];
        update_option( self::OPTION_JOB, $job, false );
        self::schedule_if_needed();
        wp_send_json_success( self::progress_payload() );
    }

    public static function ajax_pause() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        check_ajax_referer( 'bai_slug_queue', 'nonce' );
        $job = get_option( self::OPTION_JOB, [] );
        $job['running'] = false;
        $job['updated_at'] = time();
        update_option( self::OPTION_JOB, $job, false );
        self::clear_schedule();
        wp_send_json_success( self::progress_payload() );
    }

    public static function ajax_reset() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        check_ajax_referer( 'bai_slug_queue', 'nonce' );
        self::reset_job_and_queue();
        self::clear_schedule();
        wp_send_json_success( self::progress_payload() );
    }

    public static function ajax_progress() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        check_ajax_referer( 'bai_slug_queue', 'nonce' );
        // Ensure cron exists if running；若未排程则直接小步执行一次，避免站点未触发 WP-Cron 时卡住
        $job = get_option( self::OPTION_JOB, [] );
        if ( ! empty( $job['running'] ) ) {
            self::schedule_if_needed();
            if ( ! wp_next_scheduled( 'bai_slug_tick' ) ) {
                // 后备：立刻处理一小步
                self::tick();
            }
        }
        wp_send_json_success( self::progress_payload() );
    }

    private static function push_log( &$job, $msg ) {
        if ( ! isset( $job['logs'] ) || ! is_array( $job['logs'] ) ) $job['logs'] = [];
        array_unshift( $job['logs'], (string) $msg );
        if ( count( $job['logs'] ) > 50 ) { $job['logs'] = array_slice( $job['logs'], 0, 50 ); }
    }

    private static function progress_payload() {
        $job = get_option( self::OPTION_JOB, [] );
        $payload = [
            'running'   => (bool) ( $job['running'] ?? false ),
            'processed' => (int) ( $job['processed'] ?? 0 ),
            'total'     => (int) ( $job['total'] ?? 0 ),
            'cursor'    => (int) ( $job['cursor'] ?? 0 ),
            'done'      => (bool) ( ! empty( $job['finished_at'] ) || ( (int) ( $job['processed'] ?? 0 ) >= (int) ( $job['total'] ?? 0 ) && (int) ( $job['total'] ?? 0 ) > 0 ) ),
            'log'       => (array) ( $job['logs'] ?? [] ),
            'last_error'=> (string) ( $job['last_error'] ?? '' ),
        ];
        return $payload;
    }

    private static function reset_job_and_queue() {
        // Delete chunks
        global $wpdb;
        $like = $wpdb->esc_like( self::CHUNK_PREFIX ) . '%';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
        delete_option( self::OPTION_JOB );
        // Also clear cron schedule
        self::clear_schedule();
    }

    private static function count_chunks() {
        global $wpdb;
        $like = $wpdb->esc_like( self::CHUNK_PREFIX ) . '%';
        $sql  = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like );
        return (int) $wpdb->get_var( $sql );
    }

    private static function store_queue_chunks( $ids ) {
        $chunk_size = 1000;
        $chunks = array_chunk( array_values( array_map( 'intval', (array) $ids ) ), $chunk_size );
        $i = 1;
        foreach ( $chunks as $chunk ) {
            update_option( self::CHUNK_PREFIX . $i, $chunk, false );
            $i++;
        }
    }

    private static function collect_candidate_ids( $post_types, $skip_ai = false, $skip_user = true, $pending_only = false ) {
        global $wpdb;
        $statuses = [ 'publish', 'future', 'draft', 'pending', 'private' ];
        if ( empty( $post_types ) ) $post_types = [ 'post', 'page' ];
        $st_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
        $pt_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

        // Meta joins: slug source + proposed slug
        $sql_base = "FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_slug_source')
             LEFT JOIN {$wpdb->postmeta} pr ON (pr.post_id = p.ID AND pr.meta_key = '_proposed_slug')
             WHERE p.post_status IN ($st_placeholders)
               AND p.post_type   IN ($pt_placeholders)";

        if ( $pending_only ) {
            // 待生成：没有来源标记，且没有提案
            $cond = " AND m.post_id IS NULL AND pr.post_id IS NULL";
        } else {
            // 可选跳过：人工/AI
            if ( $skip_user && $skip_ai ) {
                $cond = " AND (m.meta_value IS NULL OR (m.meta_value <> 'user-edited' AND m.meta_value <> 'ai'))";
            } elseif ( $skip_user ) {
                $cond = " AND (m.meta_value IS NULL OR m.meta_value <> 'user-edited')";
            } elseif ( $skip_ai ) {
                $cond = " AND (m.meta_value IS NULL OR m.meta_value <> 'ai')";
            } else {
                $cond = '';
            }
        }

        $sql = $wpdb->prepare( "SELECT p.ID " . $sql_base . $cond . " ORDER BY p.ID DESC",
            array_merge( $statuses, $post_types ) );
        $ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

        // Strict mode: 仅当 slug 为空或等于标准化标题时才处理（高级保护）
        $settings = method_exists( 'BAI_Slug_Settings', 'get_settings' ) ? BAI_Slug_Settings::get_settings() : [];
        $strict = ! empty( $settings['strict_mode'] );
        if ( $strict && $pending_only && ! empty( $ids ) ) {
            $filtered = [];
            foreach ( $ids as $id ) {
                $p = get_post( $id ); if ( ! $p ) { continue; }
                $slug = (string) $p->post_name;
                $san  = sanitize_title( (string) $p->post_title );
                if ( $slug === '' || $slug === $san ) { $filtered[] = $id; }
            }
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '严格模式生效：候选从 ' . count( $ids ) . ' 过滤到 ' . count( $filtered ) ); }
            $ids = $filtered;
        }
        return $ids;
    }

    public static function tick() {
        $job = get_option( self::OPTION_JOB, [] );
        if ( empty( $job ) || empty( $job['running'] ) ) return;

        $processed_this_tick = 0;
        $max = max( 1, min( 50, (int) ( $job['batch'] ?? 5 ) ) );
        $settings = method_exists( 'BAI_Slug_Settings', 'get_settings' ) ? BAI_Slug_Settings::get_settings() : [];

        for ( $i = 0; $i < $max; $i++ ) {
            $id = self::next_id( $job );
            if ( ! $id ) break; // queue empty

            $post = get_post( $id );
            $job['cursor'] = (int) $id;
            if ( ! $post ) continue;
            if ( get_post_meta( $id, '_slug_source', true ) === 'user-edited' ) continue;

            // 生成提案（不直接应用）
            $proposal = self::generate_proposal( $post, $settings, $job );
            if ( $proposal['ok'] ) {
                update_post_meta( $id, '_proposed_slug_raw', $proposal['raw'] );
                update_post_meta( $id, '_proposed_slug', $proposal['slug'] );
                update_post_meta( $id, '_proposed_meta', [
                    'scheme'    => (string) ( $job['scheme'] ?? 'title' ),
                    'delimiter' => (string) ( $job['delimiter'] ?? '-' ),
                    'conflict'  => (string) $proposal['conflict'],
                    'at'        => time(),
                ] );
                self::push_log( $job, '提案: ID ' . $id . ' -> ' . $proposal['slug'] . ( $proposal['conflict'] === 'auto' ? '（冲突已自动处理）' : ( $proposal['conflict'] === 'conflict' ? '（存在冲突）' : '' ) ) );
                $job['processed'] = (int) ( $job['processed'] ?? 0 ) + 1;
                $processed_this_tick++;
            } else {
                $job['last_error'] = $proposal['error'];
                self::push_log( $job, '生成失败（无结果）: ' . $post->post_title );
            }
        }

        // Determine completion
        if ( (int) ( $job['processed'] ?? 0 ) >= (int) ( $job['total'] ?? 0 ) ) {
            $job['running']    = false;
            $job['finished_at']= time();
            // Stop scheduled cron on completion
            self::clear_schedule();
        }
        $job['updated_at'] = time();
        update_option( self::OPTION_JOB, $job, false );
    }

    private static function next_id( &$job ) {
        $chunk_index = (int) ( $job['current_chunk'] ?? 1 );
        $offset      = (int) ( $job['offset'] ?? 0 );
        $chunks      = (int) ( $job['chunks'] ?? 0 );
        if ( $chunks <= 0 || $chunk_index <= 0 ) return 0;

        $chunk = get_option( self::CHUNK_PREFIX . $chunk_index, [] );
        if ( ! is_array( $chunk ) || empty( $chunk ) ) return 0;

        if ( $offset >= count( $chunk ) ) {
            // move to next chunk
            $chunk_index++;
            $job['current_chunk'] = $chunk_index;
            $job['offset'] = 0;
            if ( $chunk_index > $chunks ) return 0;
            $chunk = get_option( self::CHUNK_PREFIX . $chunk_index, [] );
            if ( ! is_array( $chunk ) || empty( $chunk ) ) return 0;
        }

        $id = (int) $chunk[ $offset ];
        $job['offset'] = $offset + 1;
        return $id;
    }

    private static function ai_call( $messages, $settings ) {
        $endpoint = class_exists( 'BAI_Slug_Helpers' ) ? BAI_Slug_Helpers::build_endpoint( $settings ) : '';
        $api_key  = (string) ( $settings['api_key'] ?? '' );
        $model    = (string) ( $settings['model'] ?? 'gpt-4o-mini' );
        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $api_key !== '' ) { $headers['Authorization'] = 'Bearer ' . $api_key; }
        $resp = wp_remote_post( $endpoint, [
            'timeout' => 20,
            'headers' => $headers,
            'body'    => wp_json_encode( [ 'model' => $model, 'messages' => $messages ] ),
        ] );
        if ( is_wp_error( $resp ) ) return [ 'ok' => false, 'message' => $resp->get_error_message() ];
        $code = wp_remote_retrieve_response_code( $resp );
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'HTTP ' . $code . ' - ' . wp_json_encode( $data ) ];
        $content = $data['choices'][0]['message']['content'] ?? '';
        return [ 'ok' => ( $content !== '' ), 'content' => (string) $content ];
    }

    private static function apply_delimiter( $slug, $delimiter, $custom ) {
        $slug = strtolower( preg_replace( '/[^a-z0-9\-_ ]+/', '', (string) $slug ) );
        $rep  = ($delimiter === 'custom') ? ( $custom !== '' ? $custom : '-' ) : ( $delimiter === '_' ? '_' : '-' );
        $slug = preg_replace( '/[\s\-_]+/', $rep, $slug );
        $slug = trim( $slug, $rep );
        // No hard length enforcement here; prompt controls length semantics.
        return $slug;
    }

    private static function slug_conflict( $slug, $post_id ) {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND ID<>%d LIMIT 1", $slug, (int) $post_id ) );
        return $exists ? (int) $exists : 0;
    }

    public static function generate_proposal( $post, $settings, $job ) {
        $scheme = (string) ( $job['scheme'] ?? 'title' );
        $delimiter = (string) ( $job['delimiter'] ?? '-' );
        $delimiter_custom = (string) ( $job['delimiter_custom'] ?? '' );
        $collision = (string) ( $job['collision'] ?? 'append_date' );

        // Build messages per scheme
        $messages = [];
        $glossary_tip = ( class_exists( 'BAI_Slug_Helpers' ) && method_exists( 'BAI_Slug_Helpers', 'glossary_hint' ) )
            ? BAI_Slug_Helpers::glossary_hint( $post->post_title, $settings )
            : '';
        $custom_system = isset( $settings['system_prompt'] ) ? trim( (string) $settings['system_prompt'] ) : '';
        if ( $custom_system === '' ) {
            $system = ( class_exists( 'BAI_Slug_Helpers' ) && method_exists( 'BAI_Slug_Helpers', 'default_system_prompt' ) )
                ? BAI_Slug_Helpers::default_system_prompt( 0, 'title' )
                : 'Return a single English URL slug in 1-4 words, lowercase, hyphen-separated.';
            if ( $glossary_tip ) { $system .= ' ' . $glossary_tip; }
        } else {
            $system = $custom_system . ( $glossary_tip ? ( ' ' . $glossary_tip ) : '' );
        }
        $messages[] = [ 'role' => 'system', 'content' => $system ];
        if ( $scheme === 'content' ) {
            $content = wp_strip_all_tags( (string) $post->post_content );
            if ( strlen( $content ) > 2000 ) { $content = substr( $content, 0, 2000 ); }
            $user = "Title: {$post->post_title}\nContent: {$content}\nTask: Generate a concise English slug (1-5 words), lowercase, words separated by spaces. Output slug only.";
            $messages[] = [ 'role' => 'user', 'content' => $user ];
        } elseif ( $scheme === 'custom' ) {
            $custom = (string) ( $job['custom_prompt'] ?? '' );
            $user = "Title: {$post->post_title}\nTask: {$custom}\nOutput: English slug only, words separated by spaces.";
            $messages[] = [ 'role' => 'user', 'content' => $user ];
        } else { // title
            $user = "Translate the following title into a concise English slug (1-4 words), lowercase, words separated by spaces. Output slug only: \"{$post->post_title}\"";
            $messages[] = [ 'role' => 'user', 'content' => $user ];
        }

        $resp = self::ai_call( $messages, $settings );
        if ( ! $resp['ok'] ) return [ 'ok' => false, 'error' => $resp['message'] ];
        $raw = sanitize_text_field( $resp['content'] );
        $slug = self::apply_delimiter( $raw, $delimiter, $delimiter_custom );
        if ( $slug === '' ) {
            // Fallback to sanitized title to avoid hard failure on rare cases
            $fallback = sanitize_title( (string) $post->post_title );
            if ( $fallback !== '' ) { $slug = $fallback; }
            else { return [ 'ok' => false, 'error' => 'empty' ]; }
        }

        $conflict = self::slug_conflict( $slug, $post->ID ) ? 'conflict' : 'none';
        if ( $conflict === 'conflict' && $collision === 'append_date' ) {
            $date = date_i18n( 'y-m-d', strtotime( $post->post_date ) );
            $slug2 = $slug . '-' . $date;
            if ( self::slug_conflict( $slug2, $post->ID ) ) {
                // ensure uniqueness by adding short id
                $slug2 .= '-' . substr( (string) $post->ID, -3 );
            }
            $slug = $slug2; $conflict = 'auto';
        }

        return [ 'ok' => true, 'raw' => $raw, 'slug' => $slug, 'conflict' => $conflict ];
    }

    private static function verify_nonce_flexible( $nonce ) {
        if ( ! $nonce ) return false;
        if ( wp_verify_nonce( $nonce, 'bai_slug_queue' ) ) return true;
        if ( wp_verify_nonce( $nonce, 'bai_slug_manage' ) ) return true;
        if ( wp_verify_nonce( $nonce, 'bai_slug_test' ) ) return true;
        return false;
    }

    public static function ajax_apply() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => '无效令牌' ], 403 );
        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : [];
        if ( empty( $ids ) ) wp_send_json_error( [ 'message' => '无选择' ], 400 );
        $job = get_option( self::OPTION_JOB, [] );
        $collision = (string) ( $job['collision'] ?? 'append_date' );
        $result = [];
        $ok_count = 0; $fail_count = 0;
        foreach ( $ids as $id ) {
            $slug = (string) get_post_meta( $id, '_proposed_slug', true );
            if ( $slug === '' ) { $result[$id] = [ 'ok' => false, 'message' => '无提案' ]; $fail_count++; continue; }
            // collision check at apply time
            if ( self::slug_conflict( $slug, $id ) ) {
                if ( $collision === 'append_date' ) {
                    $post = get_post( $id );
                    $date = date_i18n( 'y-m-d', strtotime( $post->post_date ) );
                    $slug2 = $slug . '-' . $date;
                    if ( self::slug_conflict( $slug2, $id ) ) { $slug2 .= '-' . substr( (string) $id, -3 ); }
                    $slug = $slug2;
                } else {
                    $result[$id] = [ 'ok' => false, 'message' => '冲突未处理' ]; $fail_count++; continue;
                }
            }
            $res = wp_update_post( [ 'ID' => $id, 'post_name' => $slug ], true );
            if ( is_wp_error( $res ) ) { $result[$id] = [ 'ok' => false, 'message' => $res->get_error_message() ]; $fail_count++; continue; }
            update_post_meta( $id, '_generated_slug', $slug );
            update_post_meta( $id, '_slug_source', 'ai' );
            delete_post_meta( $id, '_proposed_slug' );
            delete_post_meta( $id, '_proposed_slug_raw' );
            delete_post_meta( $id, '_proposed_meta' );
            if ( method_exists( 'BAI_Slug_Settings', 'increment_counter' ) ) { BAI_Slug_Settings::increment_counter(); }
            $result[$id] = [ 'ok' => true, 'slug' => $slug ]; $ok_count++;
        }
        if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '批量应用完成：成功 ' . $ok_count . ' 条，失败 ' . $fail_count . ' 条' ); }
        wp_send_json_success( [ 'result' => $result ] );
    }

    public static function ajax_reject() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => '无效令牌' ], 403 );
        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : [];
        if ( empty( $ids ) ) wp_send_json_error( [ 'message' => '无选择' ], 400 );
        $removed = 0;
        foreach ( $ids as $id ) {
            delete_post_meta( $id, '_proposed_slug' );
            delete_post_meta( $id, '_proposed_slug_raw' );
            delete_post_meta( $id, '_proposed_meta' );
            $removed++;
        }
        if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '批量拒绝提案：' . (int) $removed . ' 条' ); }
        wp_send_json_success( [ 'result' => 'ok' ] );
    }

    public static function ajax_generate_one() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        $id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'invalid id' ], 400 );
        $post = get_post( $id );
        if ( ! $post ) wp_send_json_error( [ 'message' => 'not found' ], 404 );

        $settings = method_exists( 'BAI_Slug_Settings', 'get_settings' ) ? BAI_Slug_Settings::get_settings() : [];
        $job = [
            'scheme' => in_array( (string) ( $_POST['scheme'] ?? 'title' ), [ 'title','content','custom' ], true ) ? (string) $_POST['scheme'] : 'title',
            'custom_prompt' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['custom_prompt'] ?? '' ) ) ),
            'delimiter' => in_array( (string) ( $_POST['delimiter'] ?? '-' ), [ '-', '_' , 'custom' ], true ) ? (string) $_POST['delimiter'] : '-',
            'delimiter_custom' => sanitize_text_field( wp_unslash( (string) ( $_POST['delimiter_custom'] ?? '' ) ) ),
            'collision' => in_array( (string) ( $_POST['collision'] ?? 'append_date' ), [ 'append_date','mark' ], true ) ? (string) $_POST['collision'] : 'append_date',
        ];

        $proposal = self::generate_proposal( $post, $settings, $job );
        if ( ! $proposal['ok'] ) wp_send_json_error( [ 'message' => $proposal['error'] ?: 'failed' ] );
        update_post_meta( $id, '_proposed_slug_raw', $proposal['raw'] );
        update_post_meta( $id, '_proposed_slug', $proposal['slug'] );
        update_post_meta( $id, '_proposed_meta', [ 'scheme' => (string) $job['scheme'], 'delimiter' => (string) $job['delimiter'], 'conflict' => (string) $proposal['conflict'], 'at' => time() ] );
        wp_send_json_success( [ 'slug' => $proposal['slug'] ] );
    }

    // ---------- Simple Terms Batch (polling) ----------
    public static function ajax_terms_start() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        check_ajax_referer( 'bai_slug_queue', 'nonce' );
        $batch = max( 1, min( 50, intval( $_POST['batch_size'] ?? 5 ) ) );
        $taxonomies = isset( $_POST['taxonomies'] ) && is_array( $_POST['taxonomies'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['taxonomies'] ) ) : [];
        if ( empty( $taxonomies ) ) {
            $settings = method_exists( 'BAI_Slug_Settings', 'get_settings' ) ? BAI_Slug_Settings::get_settings() : [];
            $taxonomies = (array) ( $settings['enabled_taxonomies'] ?? [] );
        }
        $skip_ai = ! empty( $_POST['skip_ai'] );
        $skip_user = ! empty( $_POST['skip_user'] );
        $ids = [];
        if ( ! empty( $taxonomies ) ) {
            $terms = get_terms( [ 'taxonomy' => $taxonomies, 'hide_empty' => false, 'fields' => 'ids' ] );
            if ( ! is_wp_error( $terms ) && is_array( $terms ) ) { $ids = array_map( 'intval', $terms ); }
        }
        update_option( self::TERMS_IDS, $ids, false );
        $job = [ 'running' => true, 'batch' => (int) $batch, 'taxonomies' => $taxonomies, 'total' => (int) count( $ids ), 'index' => 0, 'processed' => 0, 'cursor' => 0, 'logs' => [], 'skip_ai' => $skip_ai ? 1 : 0, 'skip_user' => $skip_user ? 1 : 0, 'started_at' => time(), 'updated_at' => time(), 'finished_at' => 0 ];
        update_option( self::TERMS_JOB, $job, false );
        wp_send_json_success( self::terms_progress_payload() );
    }

    public static function ajax_terms_reset() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        check_ajax_referer( 'bai_slug_queue', 'nonce' );
        delete_option( self::TERMS_JOB );
        delete_option( self::TERMS_IDS );
        wp_send_json_success( self::terms_progress_payload() );
    }

    public static function ajax_terms_progress() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        check_ajax_referer( 'bai_slug_queue', 'nonce' );
        $job = get_option( self::TERMS_JOB, [] );
        $ids = (array) get_option( self::TERMS_IDS, [] );
        if ( empty( $job ) || empty( $job['running'] ) || empty( $ids ) ) { wp_send_json_success( self::terms_progress_payload() ); }
        $batch = max( 1, min( 50, (int) ( $job['batch'] ?? 5 ) ) );
        $settings = method_exists( 'BAI_Slug_Settings', 'get_settings' ) ? BAI_Slug_Settings::get_settings() : [];
        for ( $i = 0; $i < $batch; $i++ ) {
            $idx = (int) ( $job['index'] ?? 0 );
            if ( $idx >= count( $ids ) ) { break; }
            $term_id = (int) $ids[ $idx ];
            $job['index'] = $idx + 1;
            $job['cursor'] = $term_id;
            $term = get_term( $term_id );
            if ( is_wp_error( $term ) || ! $term ) { continue; }
            if ( ! in_array( (string) $term->taxonomy, (array) ( $job['taxonomies'] ?? [] ), true ) ) { continue; }
            if ( (int) ( $job['skip_ai'] ?? 0 ) === 1 && get_term_meta( $term_id, '_slug_source', true ) === 'ai' ) { continue; }
            if ( (int) ( $job['skip_user'] ?? 0 ) === 1 && get_term_meta( $term_id, '_slug_source', true ) === 'user-edited' ) { continue; }

            $default_slug = sanitize_title( $term->name );
            if ( $term->slug !== '' && $term->slug !== $default_slug ) { continue; }

            $ctx = class_exists( 'BAI_Slug_Helpers' ) && method_exists( 'BAI_Slug_Helpers', 'context_from_term' ) ? BAI_Slug_Helpers::context_from_term( $term ) : [];
            $slug = class_exists( 'BAI_Slug_Helpers' ) ? BAI_Slug_Helpers::request_slug( $term->name, $settings, $ctx ) : '';
            if ( ! $slug ) { $job['logs'] = array_merge( [ '术语生成失败：' . $term->name ], (array) ( $job['logs'] ?? [] ) ); continue; }
            // ensure unique
            $unique = $slug; $n = 1;
            while ( true ) {
                $exists = term_exists( $unique, $term->taxonomy );
                if ( ! $exists ) { break; }
                $exists_id = is_array( $exists ) ? (int) ( $exists['term_id'] ?? 0 ) : (int) $exists;
                if ( $exists_id === $term_id ) { break; }
                $unique = $slug . '-' . $n; $n++;
            }
            wp_update_term( $term_id, $term->taxonomy, [ 'slug' => $unique ] );
            update_term_meta( $term_id, '_slug_source', 'ai' );
            if ( method_exists( 'BAI_Slug_Settings', 'increment_counter' ) ) { BAI_Slug_Settings::increment_counter(); }
            $job['processed'] = (int) ( $job['processed'] ?? 0 ) + 1;
            $job['logs'] = array_merge( [ '术语生成成功: ' . $unique ], (array) ( $job['logs'] ?? [] ) );
        }
        if ( (int) ( $job['index'] ?? 0 ) >= count( $ids ) ) { $job['running'] = false; $job['finished_at'] = time(); }
        $job['updated_at'] = time();
        update_option( self::TERMS_JOB, $job, false );
        wp_send_json_success( self::terms_progress_payload() );
    }

    private static function terms_progress_payload() {
        $job = get_option( self::TERMS_JOB, [] );
        $ids = (array) get_option( self::TERMS_IDS, [] );
        return [
            'running' => (bool) ( $job['running'] ?? false ),
            'processed' => (int) ( $job['processed'] ?? 0 ),
            'total' => (int) count( $ids ),
            'cursor' => (int) ( $job['cursor'] ?? 0 ),
            'done' => (bool) ( empty( $job['running'] ) && (int) ( $job['processed'] ?? 0 ) >= (int) count( $ids ) && (int) count( $ids ) > 0 ),
            'log' => (array) ( $job['logs'] ?? [] ),
        ];
    }

    public static function ajax_get_proposals() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : [];
        if ( empty( $ids ) ) wp_send_json_success( [] );
        $out = [];
        foreach ( $ids as $id ) {
            $slug = get_post_meta( $id, '_proposed_slug', true );
            if ( is_string( $slug ) && $slug !== '' ) { $out[ (string) $id ] = $slug; }
        }
        wp_send_json_success( $out );
    }

    public static function ajax_count_proposals() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        $post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : [];
        global $wpdb;
        $statuses = [ 'publish', 'future', 'draft', 'pending', 'private' ];
        $st_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
        if ( empty( $post_types ) ) {
            $post_types = get_post_types( [ 'public' => true ], 'names' );
        }
        $pt_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
        $sql = $wpdb->prepare(
            "SELECT COUNT(1) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_proposed_slug')
             WHERE p.post_status IN ($st_placeholders)
               AND p.post_type   IN ($pt_placeholders)",
            array_merge( $statuses, $post_types )
        );
        $count = (int) $wpdb->get_var( $sql );
        wp_send_json_success( [ 'count' => $count ] );
    }

    public static function ajax_count_pending() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        $post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : [];
        global $wpdb;
        $statuses = [ 'publish', 'future', 'draft', 'pending', 'private' ];
        $st_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
        if ( empty( $post_types ) ) { $post_types = get_post_types( [ 'public' => true ], 'names' ); }
        $pt_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
        $sql = $wpdb->prepare(
            "SELECT COUNT(1) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_slug_source')
             LEFT JOIN {$wpdb->postmeta} pr ON (pr.post_id = p.ID AND pr.meta_key = '_proposed_slug')
             WHERE p.post_status IN ($st_placeholders)
               AND p.post_type   IN ($pt_placeholders)
               AND m.post_id IS NULL
               AND pr.post_id IS NULL",
            array_merge( $statuses, $post_types )
        );
        $count = (int) $wpdb->get_var( $sql );
        wp_send_json_success( [ 'count' => $count ] );
    }

    private static function extend_posts_search_with_slug( $search, $wp_query ) {
        if ( empty( self::$current_posts_search ) ) { return $search; }
        if ( empty( $wp_query ) || empty( $wp_query->query_vars['bai_slug_search'] ) ) { return $search; }
        global $wpdb;
        $like = '%' . $wpdb->esc_like( self::$current_posts_search ) . '%';
        $condition = $wpdb->prepare( "{$wpdb->posts}.post_name LIKE %s", $like );
        if ( $search === '' ) {
            return ' AND (' . $condition . ')';
        }
        $pattern = '/\)\s*\)\s*$/';
        $replacement = ' OR (' . $condition . '))';
        $new = preg_replace( $pattern, $replacement, $search, 1, $count );
        if ( $count > 0 ) {
            return $new;
        }
        return $search . ' AND (' . $condition . ')';
    }

    private static function extend_terms_search_with_slug( $clauses, $taxonomies, $args ) {
        if ( empty( self::$current_terms_search ) ) { return $clauses; }
        if ( empty( $args['bai_slug_search'] ) ) { return $clauses; }
        global $wpdb;
        $like = '%' . $wpdb->esc_like( self::$current_terms_search ) . '%';
        $condition = $wpdb->prepare( "{$wpdb->terms}.slug LIKE %s", $like );
        $where = isset( $clauses['where'] ) ? $clauses['where'] : '';
        if ( $where !== '' ) {
            $pattern = '/\)\s*$/';
            $replacement = ' OR ' . $condition . ')';
            $new_where = preg_replace( $pattern, $replacement, $where, 1, $count );
            if ( $count > 0 ) {
                $clauses['where'] = $new_where;
            } else {
                $clauses['where'] .= ' AND (' . $condition . ')';
            }
        } else {
            $clauses['where'] = ' AND (' . $condition . ')';
        }
        return $clauses;
    }

    public static function ajax_posts_list() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        $ptype = isset( $_POST['ptype'] ) ? sanitize_text_field( wp_unslash( $_POST['ptype'] ) ) : 'post';
        $attr  = isset( $_POST['attr'] ) ? sanitize_text_field( wp_unslash( $_POST['attr'] ) ) : '';
        $s     = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
        $paged = max( 1, intval( $_POST['paged'] ?? 1 ) );
        $per   = max( 5, min( 100, intval( $_POST['per_page'] ?? 20 ) ) );

        $args = [
            'post_type'      => $ptype,
            'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'posts_per_page' => $per,
            'paged'          => $paged,
        ];
        if ( $s !== '' ) {
            $args['s'] = $s;
            $args['bai_slug_search'] = 1;
            self::$current_posts_search = $s;
            add_filter( 'posts_search', [ __CLASS__, 'extend_posts_search_with_slug' ], 10, 2 );
        }
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

        $q = new WP_Query( $args );
        if ( $s !== '' ) {
            remove_filter( 'posts_search', [ __CLASS__, 'extend_posts_search_with_slug' ], 10 );
            self::$current_posts_search = '';
        }
        $items = [];
        foreach ( $q->posts as $p ) {
            $id   = (int) $p->ID;
            $src  = get_post_meta( $id, '_slug_source', true );
            $prop = get_post_meta( $id, '_proposed_slug', true );
            $items[] = [
                'id'       => $id,
                'title'    => get_the_title( $id ),
                'slug'     => (string) get_post_field( 'post_name', $id ),
                'attr'     => (string) $src,
                'proposed' => (string) $prop,
            ];
        }
        wp_send_json_success( [ 'items' => $items, 'total' => (int) $q->found_posts, 'paged' => (int) $paged, 'per_page' => (int) $per ] );
    }

    // ---------- Terms list + single/batch actions for 分类法处理 ----------
    public static function ajax_terms_list() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );

        $tax = isset( $_POST['tax'] ) ? sanitize_text_field( wp_unslash( $_POST['tax'] ) ) : '';
        $attr = isset( $_POST['attr'] ) ? sanitize_text_field( wp_unslash( $_POST['attr'] ) ) : '';
        $search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
        $paged = max( 1, intval( $_POST['paged'] ?? 1 ) );
        $per   = max( 5, min( 100, intval( $_POST['per_page'] ?? 20 ) ) );

        $args = [
            'hide_empty'   => false,
            'orderby'      => 'term_id',
            'order'        => 'DESC',
            'number'       => $per,
            'offset'       => ( $paged - 1 ) * $per,
            'count_total'  => true,
        ];
        if ( $tax && $tax !== 'all' ) { $args['taxonomy'] = [ $tax ]; }
        if ( $search !== '' ) {
            $args['search'] = $search;
            $args['bai_slug_search'] = 1;
            self::$current_terms_search = $search;
            add_filter( 'terms_clauses', [ __CLASS__, 'extend_terms_search_with_slug' ], 10, 3 );
        }
        if ( in_array( $attr, [ 'ai', 'user-edited', 'pending-slug', 'proposed' ], true ) ) {
            if ( $attr === 'pending-slug' ) {
                $args['meta_query'] = [
                    'relation' => 'AND',
                    [ 'key' => '_slug_source', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => '_proposed_slug', 'compare' => 'NOT EXISTS' ],
                ];
            } elseif ( $attr === 'proposed' ) {
                $args['meta_query'] = [ [ 'key' => '_proposed_slug', 'compare' => 'EXISTS' ] ];
            } else {
                $args['meta_query'] = [ [ 'key' => '_slug_source', 'value' => $attr, 'compare' => '=' ] ];
            }
        }
        $terms = get_terms( $args );
        if ( $search !== '' ) {
            remove_filter( 'terms_clauses', [ __CLASS__, 'extend_terms_search_with_slug' ], 10 );
            self::$current_terms_search = '';
        }
        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( [ 'message' => $terms->get_error_message() ] );
        }
        $items = [];
        foreach ( (array) $terms as $t ) {
            if ( ! ( $t instanceof WP_Term ) ) continue;
            $items[] = [
                'id'       => (int) $t->term_id,
                'name'     => (string) $t->name,
                'taxonomy' => (string) $t->taxonomy,
                'slug'     => (string) $t->slug,
                'attr'     => (string) get_term_meta( $t->term_id, '_slug_source', true ),
                'proposed' => (string) get_term_meta( $t->term_id, '_proposed_slug', true ),
            ];
        }
        $total = 0;
        if ( is_array( $terms ) && function_exists( 'wp_list_pluck' ) ) {
            // When count_total true, get_terms() sets total via $GLOBALS['wp_object_cache'] internals; to avoid complexity, run a light count query
            $args2 = $args; unset( $args2['number'], $args2['offset'] );
            $args2['fields'] = 'count';
            if ( $search !== '' ) {
                $args2['bai_slug_search'] = 1;
                self::$current_terms_search = $search;
                add_filter( 'terms_clauses', [ __CLASS__, 'extend_terms_search_with_slug' ], 10, 3 );
            }
            $total = (int) get_terms( $args2 );
            if ( $search !== '' ) {
                remove_filter( 'terms_clauses', [ __CLASS__, 'extend_terms_search_with_slug' ], 10 );
                self::$current_terms_search = '';
            }
        }
        wp_send_json_success( [ 'items' => $items, 'total' => $total, 'paged' => (int) $paged, 'per_page' => (int) $per ] );
    }

    public static function ajax_term_generate_one() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        $term_id = intval( $_POST['term_id'] ?? 0 );
        if ( ! $term_id ) wp_send_json_error( [ 'message' => 'invalid id' ], 400 );
        $term = get_term( $term_id );
        if ( is_wp_error( $term ) || ! $term ) wp_send_json_error( [ 'message' => 'not found' ], 404 );
        $settings = class_exists( 'BAI_Slug_Settings' ) ? BAI_Slug_Settings::get_settings() : [];
        $ctx = class_exists( 'BAI_Slug_Helpers' ) && method_exists( 'BAI_Slug_Helpers', 'context_from_term' ) ? BAI_Slug_Helpers::context_from_term( $term ) : [];
        $slug = class_exists( 'BAI_Slug_Helpers' ) ? BAI_Slug_Helpers::request_slug( $term->name, $settings, $ctx ) : '';
        if ( ! $slug ) wp_send_json_error( [ 'message' => 'failed' ] );
        update_term_meta( $term_id, '_proposed_slug', $slug );
        wp_send_json_success( [ 'slug' => $slug ] );
    }

    public static function ajax_term_apply() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : [];
        if ( empty( $ids ) ) wp_send_json_error( [ 'message' => 'no selection' ], 400 );
        $result = [];
        foreach ( $ids as $term_id ) {
            $term = get_term( (int) $term_id );
            if ( is_wp_error( $term ) || ! $term ) { $result[ $term_id ] = [ 'ok' => false, 'message' => 'not found' ]; continue; }
            $slug = (string) get_term_meta( (int) $term_id, '_proposed_slug', true );
            if ( $slug === '' ) { $result[ $term_id ] = [ 'ok' => false, 'message' => 'no proposal' ]; continue; }
            // ensure unique under taxonomy
            $unique = $slug; $n = 1;
            while ( true ) {
                $exists = term_exists( $unique, $term->taxonomy );
                if ( ! $exists ) { break; }
                $exists_id = is_array( $exists ) ? (int) ( $exists['term_id'] ?? 0 ) : (int) $exists;
                if ( $exists_id === (int) $term_id ) { break; }
                $unique = $slug . '-' . $n; $n++;
            }
            $res = wp_update_term( (int) $term_id, $term->taxonomy, [ 'slug' => $unique ] );
            if ( is_wp_error( $res ) ) { $result[ $term_id ] = [ 'ok' => false, 'message' => $res->get_error_message() ]; continue; }
            update_term_meta( (int) $term_id, '_slug_source', 'ai' );
            delete_term_meta( (int) $term_id, '_proposed_slug' );
            if ( class_exists( 'BAI_Slug_Settings' ) && method_exists( 'BAI_Slug_Settings', 'increment_counter' ) ) { BAI_Slug_Settings::increment_counter(); }
            $result[ $term_id ] = [ 'ok' => true, 'slug' => $unique ];
        }
        wp_send_json_success( [ 'result' => $result ] );
    }

    public static function ajax_term_reject() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'denied' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => 'invalid nonce' ], 403 );
        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : [];
        if ( empty( $ids ) ) wp_send_json_error( [ 'message' => 'no selection' ], 400 );
        foreach ( $ids as $term_id ) {
            delete_term_meta( (int) $term_id, '_proposed_slug' );
        }
        wp_send_json_success( [ 'result' => 'ok' ] );
    }
}
