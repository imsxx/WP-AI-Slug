<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Helpers {
    public static function build_endpoint( $settings ) {
        $base = rtrim( (string) ( $settings['api_base'] ?? '' ), '/' );
        $path = '/' . ltrim( (string) ( $settings['api_path'] ?? '/v1/chat/completions' ), '/' );
        return $base . $path;
    }

    private static function extract_error_message( $code, $raw_body ) {
        $msg  = 'HTTP ' . (int) $code;
        $data = null;
        if ( is_string( $raw_body ) && $raw_body !== '' ) {
            $data = json_decode( $raw_body, true );
        }
        if ( is_array( $data ) ) {
            if ( isset( $data['error'] ) ) {
                $e  = $data['error'];
                $em = is_array( $e ) ? ( $e['message'] ?? '' ) : (string) $e;
                $et = is_array( $e ) ? ( $e['type'] ?? '' ) : '';
                $ec = is_array( $e ) ? ( $e['code'] ?? '' ) : '';
                $parts = array_filter( [ $em, $et, $ec ], function ( $x ) { return (string) $x !== ''; } );
                if ( $parts ) { return $msg . ' - ' . implode( ' | ', $parts ); }
            }
            if ( isset( $data['message'] ) && is_string( $data['message'] ) && $data['message'] !== '' ) {
                return $msg . ' - ' . $data['message'];
            }
        }
        if ( is_string( $raw_body ) && $raw_body !== '' ) {
            $snippet = substr( preg_replace( '/\s+/', ' ', $raw_body ), 0, 400 );
            return $msg . ' - ' . $snippet;
        }
        return $msg;
    }

    public static function glossary_hint( $title, $settings ) {
        if ( empty( $settings['use_glossary'] ) || empty( $settings['glossary_text'] ) ) {
            return '';
        }
        $map = self::safe_parse_glossary_lines( $settings['glossary_text'] );
        if ( empty( $map ) ) { return ''; }
        $title = (string) $title;
        $hits  = [];
        foreach ( $map as $src => $dst ) {
            if ( $src === '' || $dst === '' ) { continue; }
        foreach ( (array) $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) { continue; }
            list( $src, $dst ) = self::split_glossary_pair( $line );
            if ( $src === '' || $dst === '' ) { continue; }
            foreach ( self::expand_glossary_sources( $src ) as $variant ) {
                $map[ $variant ] = $dst;
            }
        }
        return $map;
    }

    private static function split_glossary_pair( $line ) {
        $src = '';
        $dst = '';
        if ( strpos( $line, '=' ) !== false ) {
            list( $src, $dst ) = array_map( 'trim', explode( '=', $line, 2 ) );
        } elseif ( strpos( $line, '|' ) !== false ) {
            list( $src, $dst ) = array_map( 'trim', explode( '|', $line, 2 ) );
        }
        return [ (string) $src, (string) $dst ];
    }

    private static function expand_glossary_sources( $src ) {
        $src = (string) $src;
        if ( $src === '' ) { return []; }
        $placeholder = "\u{E000}";
        $escaped     = str_replace( '\\-', $placeholder, $src );
        $parts       = preg_split( '/\s*-\s*/u', $escaped );
        if ( ! is_array( $parts ) ) { $parts = [ $escaped ]; }
        $variants = [];
        foreach ( $parts as $part ) {
            $part = trim( str_replace( $placeholder, '-', $part ) );
            if ( $part === '' ) { continue; }
            $variants[ $part ] = true;
        }
        if ( empty( $variants ) ) {
            $single = trim( str_replace( $placeholder, '-', $escaped ) );
            if ( $single !== '' ) { $variants[ $single ] = true; }
        }
        return array_keys( $variants );
    }
        $text  = (string) $text;
        if ( $text === '' ) { return $map; }
        $lines = preg_split( "/\r?\n/", $text );
        foreach ( (array) $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) { continue; }
            if ( strpos( $line, '=' ) !== false ) {
                list( $src, $dst ) = array_map( 'trim', explode( '=', $line, 2 ) );
            } elseif ( strpos( $line, '|' ) !== false ) {
                list( $src, $dst ) = array_map( 'trim', explode( '|', $line, 2 ) );
            } else { continue; }
            if ( $src !== '' && $dst !== '' ) { $map[ $src ] = $dst; }
        }
        return $map;
    }

    private static function stringify_context_value( $value ) {
        if ( is_array( $value ) ) {
            $flat = array_filter( array_map( 'trim', array_map( 'sanitize_text_field', wp_unslash( $value ) ) ) );
            return implode( ', ', $flat );
        }
        if ( is_object( $value ) ) {
            if ( $value instanceof WP_Post ) { return (string) $value->post_title; }
            if ( $value instanceof WP_Term ) { return (string) $value->name; }
        }
        return is_scalar( $value ) ? (string) $value : '';
    }

    private static function tokens_from_context( $settings, $context ) {
        $tokens = [
            '[SITE_TOPIC]'   => (string) ( $settings['site_topic'] ?? '' ),
            '[TITLE]'        => (string) ( $context['title'] ?? '' ),
            '[INDUSTRY]'     => (string) ( $context['industry'] ?? '' ),
            '[BODY_EXCERPT]' => (string) ( $context['body_excerpt'] ?? '' ),
            '[POST_TYPE]'    => (string) ( $context['post_type'] ?? '' ),
            '[MAX_LENGTH]'   => '',
        ];
        if ( is_array( $context ) ) {
            foreach ( $context as $key => $value ) {
                $tokens[ '[' . strtoupper( $key ) . ']' ] = self::stringify_context_value( $value );
            }
        }
        return $tokens;
    }

    public static function build_system_prompt( $settings, $context, $subject = 'title' ) {
        $is_term = ( $subject === 'term' ) || ( isset( $context['taxonomy'] ) && $context['taxonomy'] );
        $template = '';
        if ( $is_term && isset( $settings['taxonomy_system_prompt'] ) && trim( (string) $settings['taxonomy_system_prompt'] ) !== '' ) {
            $template = trim( (string) $settings['taxonomy_system_prompt'] );
        } else {
            $template = isset( $settings['system_prompt'] ) ? trim( (string) $settings['system_prompt'] ) : '';
        }
        $tokens   = self::tokens_from_context( $settings, $context );
        if ( $template !== '' ) {
            return strtr( $template, $tokens );
        }
        return strtr( self::default_system_prompt( 0, $is_term ? 'term' : $subject ), $tokens );
    }

    private static function taxonomy_terms_string( $post, $settings ) {
        if ( ! $post instanceof WP_Post ) { return ''; }
        // Option A: Prefer first Category term; fallback to first term of any taxonomy
        $first = '';
        // Try category taxonomy first (if exists for this post type)
        if ( in_array( 'category', get_object_taxonomies( $post->post_type ), true ) ) {
            $cat_terms = wp_get_post_terms( $post->ID, 'category', [ 'fields' => 'names' ] );
            if ( ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
                $first = trim( (string) $cat_terms[0] );
            }
        }
        if ( $first !== '' ) { return $first; }
        // Fallback: first term of any taxonomy assigned
        $taxes = get_object_taxonomies( $post->post_type );
        foreach ( $taxes as $tax ) {
            $terms = wp_get_post_terms( $post->ID, $tax, [ 'fields' => 'names' ] );
            if ( is_wp_error( $terms ) || empty( $terms ) ) { continue; }
            $first = trim( (string) $terms[0] );
            if ( $first !== '' ) { return $first; }
        }
        return '';
    }

    private static function post_body_excerpt( $post ) {
        if ( ! $post instanceof WP_Post ) { return ''; }
        $excerpt = $post->post_excerpt;
        if ( $excerpt === '' ) {
            $excerpt = wp_strip_all_tags( (string) $post->post_content );
        }
        $excerpt = trim( wp_trim_words( $excerpt, 60, '' ) );
        return $excerpt;
    }

    public static function context_from_post( $post, $settings ) {
        if ( ! $post instanceof WP_Post ) { return []; }
        return [
            'title'        => (string) $post->post_title,
            'post_type'    => (string) $post->post_type,
            'industry'     => self::taxonomy_terms_string( $post, $settings ),
            'body_excerpt' => self::post_body_excerpt( $post ),
        ];
    }

    private static function related_titles_for_term( $term, $limit = 6 ) {
        if ( ! $term instanceof WP_Term ) { return []; }
        $tax = (string) $term->taxonomy;
        $ids = get_objects_in_term( $term->term_id, $tax );
        $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : [];
        if ( empty( $ids ) ) { return []; }
        $q = new WP_Query( [
            'post__in'       => $ids,
            'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'posts_per_page' => max( 1, (int) $limit ),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );
        if ( ! $q->have_posts() ) { return []; }
        $titles = [];
        foreach ( $q->posts as $pid ) {
            $t = get_post_field( 'post_title', (int) $pid );
            if ( is_string( $t ) && $t !== '' ) { $titles[] = $t; }
        }
        return $titles;
    }

    public static function context_from_term( $term ) {
        if ( ! $term instanceof WP_Term ) { return []; }
        return [
            'title'          => (string) $term->name,
            'industry'       => (string) $term->taxonomy,
            'taxonomy'       => (string) $term->taxonomy,
            'related_titles' => self::related_titles_for_term( $term, 6 ),
        ];
    }

    public static function default_system_prompt( $unused, $subject = 'title' ) {
        if ( $subject === 'term' ) {
            return 'You are a URL slug generator for taxonomy terms. Return only a single English URL slug. '
                . 'Constraints: 1-4 words; lowercase a-z and 0-9; words separated by hyphens; '
                . 'no quotes, punctuation, emojis, or explanations; focus on the term semantics; '
                . 'give higher weight to [RELATED_TITLES] than [SITE_TOPIC].';
        }
        $intent  = ( $subject === 'page' ) ? 'page' : 'title';
        return 'You are a URL slug generator. Return only a single English URL slug. '
            . 'Constraints: 1-4 words; lowercase a-z and 0-9; words separated by hyphens; '
            . 'no quotes, punctuation, emojis, or explanations; preserve the ' . $intent . ' intent.';
    }

    public static function request_slug( $title, $settings, $context = [] ) {
        $endpoint = self::build_endpoint( $settings );
        $api_key  = (string) ( $settings['api_key'] ?? '' );
        $model    = (string) ( $settings['model'] ?? 'gpt-4o-mini' );

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $api_key !== '' ) { $headers['Authorization'] = 'Bearer ' . $api_key; }

        $context   = is_array( $context ) ? $context : [];
        if ( ! isset( $context['title'] ) ) { $context['title'] = $title; }

        $tokens          = self::tokens_from_context( $settings, $context );
        $prompt_template = sprintf( 'Translate the following title into a concise English slug (1-4 words), lowercase, spaces to hyphens. Output slug only: "%s"', $title );
        $prompt          = strtr( $prompt_template, $tokens );

        $messages    = [];
        $glossary_tip = self::glossary_hint( $title, $settings );
        $system_rule = self::build_system_prompt( $settings, $context, isset( $context['taxonomy'] ) && $context['taxonomy'] ? 'term' : 'title' );
        $messages[]  = [ 'role' => 'system', 'content' => $system_rule . ( $glossary_tip ? ( ' ' . $glossary_tip ) : '' ) ];
        $messages[]  = [ 'role' => 'user', 'content' => $prompt ];

        $resp = wp_remote_post( $endpoint, [
            'timeout' => 20,
            'headers' => $headers,
            'body'    => wp_json_encode( [ 'model' => $model, 'messages' => $messages ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( '请求 AI 失败: ' . $resp->get_error_message(), $title ); }
            return null;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        if ( $code !== 200 ) {
            $emsg = self::extract_error_message( $code, $body );
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( 'AI 请求失败: ' . $emsg, $title ); }
            return null;
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( ! $content ) {
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( 'AI 响应无有效内容', $title ); }
            return null;
        }
        $slug = sanitize_title( $content );
        if ( $slug === '' ) {
            if ( class_exists( 'BAI_Slug_Log' ) ) { BAI_Slug_Log::add( 'AI 返回的 Slug 无法解析: ' . $content, $title ); }
            return null;
        }
        return $slug;
    }

    public static function test_connectivity( $settings ) {
        $endpoint = self::build_endpoint( $settings );
        $api_key  = (string) ( $settings['api_key'] ?? '' );
        $model    = (string) ( $settings['model'] ?? 'gpt-4o-mini' );

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $api_key !== '' ) { $headers['Authorization'] = 'Bearer ' . $api_key; }

        $resp = wp_remote_post( $endpoint, [
            'timeout' => 12,
            'headers' => $headers,
            'body'    => wp_json_encode( [
                'model' => $model,
                // 用真实的最小聊天来模拟生产调用
                'messages' => [ [ 'role' => 'user', 'content' => 'Hi' ] ],
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'message' => $resp->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $raw = wp_remote_retrieve_body( $resp );
        $data = json_decode( $raw, true );
        if ( $code === 200 ) {
            // 仅在存在有效 choices 时判定为 OK
            $content = is_array( $data ) ? ( $data['choices'][0]['message']['content'] ?? '' ) : '';
            if ( is_string( $content ) && $content !== '' ) {
                return [ 'ok' => true, 'message' => 'HTTP 200' ];
            }
            // OpenAI 兼容代理有时返回 200+error 字段
            if ( isset( $data['error'] ) ) {
                return [ 'ok' => false, 'message' => self::extract_error_message( $code, $raw ) ];
            }
            return [ 'ok' => false, 'message' => 'Empty content' ];
        }
        return [ 'ok' => false, 'message' => self::extract_error_message( $code, $raw ) ];
    }
}

?>


