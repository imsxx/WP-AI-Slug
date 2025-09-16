<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Queue {
    const OPTION_JOB   = 'bai_slug_job';
    const CHUNK_PREFIX = 'bai_slug_queue_chunk_';
    const TERMS_JOB    = 'bai_slug_terms_job';
    const TERMS_IDS    = 'bai_slug_terms_ids';

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

        // Build queue once
        self::reset_job_and_queue();
        $ids = self::collect_candidate_ids( $post_types, $skip_ai );
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

    private static function collect_candidate_ids( $post_types, $skip_ai = false ) {
        global $wpdb;
        $statuses = [ 'publish', 'future', 'draft', 'pending', 'private' ];
        if ( empty( $post_types ) ) $post_types = [ 'post', 'page' ];
        $st_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
        $pt_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

        // Skip user-edited always; optionally skip ai-generated
        $cond_meta = $skip_ai
            ? "(m.meta_value IS NULL OR (m.meta_value <> 'user-edited' AND m.meta_value <> 'ai'))"
            : "(m.meta_value IS NULL OR m.meta_value <> 'user-edited')";

        $sql = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_slug_source')
             WHERE p.post_status IN ($st_placeholders)
               AND p.post_type   IN ($pt_placeholders)
               AND $cond_meta
             ORDER BY p.ID DESC",
            array_merge( $statuses, $post_types )
        );
        $ids = $wpdb->get_col( $sql );
        return array_map( 'intval', (array) $ids );
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
        // Fallback: enforce length limit softly based on settings
        $settings = method_exists( 'BAI_Slug_Settings', 'get_settings' ) ? BAI_Slug_Settings::get_settings() : [];
        $max_chars = max( 10, (int) ( $settings['slug_max_chars'] ?? 60 ) );
        if ( strlen( $slug ) > $max_chars ) {
            $cut = substr( $slug, 0, $max_chars );
            // try cut at last delimiter to avoid half-words
            $pos = strrpos( $cut, $rep );
            if ( $pos !== false && $pos > 0 ) { $cut = substr( $cut, 0, $pos ); }
            $slug = rtrim( $cut, $rep );
        }
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
        $max_chars = max( 10, (int) ( $settings['slug_max_chars'] ?? 60 ) );
        $custom_system = isset( $settings['system_prompt'] ) ? trim( (string) $settings['system_prompt'] ) : '';
        if ( $custom_system === '' ) {
            $system = 'You are a URL slug generator. Return only a single English URL slug.'
                . ' Constraints: 1-4 words; lowercase a-z and 0-9; words separated by hyphens;'
                . ' no quotes, punctuation, emojis, or explanations; preserve the page intent;'
                . ' do not exceed ' . $max_chars . ' characters.'
                . ( $glossary_tip ? ( ' ' . $glossary_tip ) : '' );
        } else {
            $system = $custom_system;
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
        if ( $slug === '' ) return [ 'ok' => false, 'error' => 'empty' ];

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
        foreach ( $ids as $id ) {
            $slug = (string) get_post_meta( $id, '_proposed_slug', true );
            if ( $slug === '' ) { $result[$id] = [ 'ok' => false, 'message' => '无提案' ]; continue; }
            // collision check at apply time
            if ( self::slug_conflict( $slug, $id ) ) {
                if ( $collision === 'append_date' ) {
                    $post = get_post( $id );
                    $date = date_i18n( 'y-m-d', strtotime( $post->post_date ) );
                    $slug2 = $slug . '-' . $date;
                    if ( self::slug_conflict( $slug2, $id ) ) { $slug2 .= '-' . substr( (string) $id, -3 ); }
                    $slug = $slug2;
                } else {
                    $result[$id] = [ 'ok' => false, 'message' => '冲突未处理' ]; continue;
                }
            }
            $res = wp_update_post( [ 'ID' => $id, 'post_name' => $slug ], true );
            if ( is_wp_error( $res ) ) { $result[$id] = [ 'ok' => false, 'message' => $res->get_error_message() ]; continue; }
            update_post_meta( $id, '_generated_slug', $slug );
            update_post_meta( $id, '_slug_source', 'ai' );
            delete_post_meta( $id, '_proposed_slug' );
            delete_post_meta( $id, '_proposed_slug_raw' );
            delete_post_meta( $id, '_proposed_meta' );
            if ( method_exists( 'BAI_Slug_Settings', 'increment_counter' ) ) { BAI_Slug_Settings::increment_counter(); }
            $result[$id] = [ 'ok' => true, 'slug' => $slug ];
        }
        wp_send_json_success( [ 'result' => $result ] );
    }

    public static function ajax_reject() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '权限不足' ], 403 );
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! self::verify_nonce_flexible( $nonce ) ) wp_send_json_error( [ 'message' => '无效令牌' ], 403 );
        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : [];
        if ( empty( $ids ) ) wp_send_json_error( [ 'message' => '无选择' ], 400 );
        foreach ( $ids as $id ) {
            delete_post_meta( $id, '_proposed_slug' );
            delete_post_meta( $id, '_proposed_slug_raw' );
            delete_post_meta( $id, '_proposed_meta' );
        }
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
        $ids = [];
        if ( ! empty( $taxonomies ) ) {
            $terms = get_terms( [ 'taxonomy' => $taxonomies, 'hide_empty' => false, 'fields' => 'ids' ] );
            if ( ! is_wp_error( $terms ) && is_array( $terms ) ) { $ids = array_map( 'intval', $terms ); }
        }
        update_option( self::TERMS_IDS, $ids, false );
        $job = [ 'running' => true, 'batch' => (int) $batch, 'taxonomies' => $taxonomies, 'total' => (int) count( $ids ), 'index' => 0, 'processed' => 0, 'cursor' => 0, 'logs' => [], 'skip_ai' => $skip_ai ? 1 : 0, 'started_at' => time(), 'updated_at' => time(), 'finished_at' => 0 ];
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

            $default_slug = sanitize_title( $term->name );
            if ( $term->slug !== '' && $term->slug !== $default_slug ) { continue; }

            $slug = class_exists( 'BAI_Slug_Helpers' ) ? BAI_Slug_Helpers::request_slug( $term->name, $settings ) : '';
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
}
