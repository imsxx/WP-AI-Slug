<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Helpers {
    public static function build_endpoint( $settings ) {
        $base = rtrim( (string) ( $settings['api_base'] ?? '' ), '/' );
        $path = '/' . ltrim( (string) ( $settings['api_path'] ?? '/v1/chat/completions' ), '/' );
        return $base . $path;
    }

    private static function extract_error_message( $code, $raw_body ) {
        $msg = 'HTTP ' . (int) $code;
        $data = null;
        if ( is_string( $raw_body ) && $raw_body !== '' ) {
            $data = json_decode( $raw_body, true );
        }
        if ( is_array( $data ) ) {
            if ( isset( $data['error'] ) ) {
                $e = $data['error'];
                $em = is_array( $e ) ? ( $e['message'] ?? '' ) : (string) $e;
                $et = is_array( $e ) ? ( $e['type'] ?? '' ) : '';
                $ec = is_array( $e ) ? ( $e['code'] ?? '' ) : '';
                $parts = array_filter( [ $em, $et, $ec ], function( $x ){ return (string) $x !== ''; } );
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

    private static function parse_glossary_lines( $text ) {
        $map = [];
        $lines = preg_split( "/\r?\n/", (string) $text );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            if ( strpos( $line, '=' ) !== false ) {
                list( $src, $dst ) = array_map( 'trim', explode( '=', $line, 2 ) );
            } elseif ( strpos( $line, '|' ) !== false ) {
                list( $src, $dst ) = array_map( 'trim', explode( '|', $line, 2 ) );
            } else {
                continue;
            }
            if ( $src !== '' && $dst !== '' ) { $map[ $src ] = $dst; }
        }
        return $map;
    }

    public static function request_slug( $title, $settings ) {
        $endpoint = self::build_endpoint( $settings );
        $api_key  = (string) ( $settings['api_key'] ?? '' );
        $model    = (string) ( $settings['model'] ?? 'gpt-4o-mini' );

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $api_key !== '' ) { $headers['Authorization'] = 'Bearer ' . $api_key; }

        $max_chars = max( 10, (int) ( $settings['slug_max_chars'] ?? 60 ) );
        $prompt = sprintf( 'Translate the following title into a concise English slug (1-4 words), lowercase, spaces to hyphens, do not exceed %d characters. Output slug only: "%s"', $max_chars, $title );

        $messages = [];
        $glossary_tip = '';
        if ( ! empty( $settings['use_glossary'] ) && ! empty( $settings['glossary_text'] ) ) {
            $map = self::parse_glossary_lines( $settings['glossary_text'] );
            $hit = [];
            foreach ( $map as $src => $dst ) {
                if ( $src === '' || $dst === '' ) continue;
                if ( mb_stripos( $title, $src ) !== false ) { $hit[ $src ] = $dst; }
            }
            if ( $hit ) {
                $pairs = [];
                foreach ( $hit as $k => $v ) { $pairs[] = $k . ' => ' . $v; }
                $glossary_tip = 'If the title contains these terms, use exact translations: ' . implode( '; ', $pairs ) . '.';
            }
        }
        $custom_system = isset( $settings['system_prompt'] ) ? trim( (string) $settings['system_prompt'] ) : '';
        if ( $custom_system === '' ) {
            $system_rule = 'You are a URL slug generator. Return only a single English URL slug. '
                . 'Constraints: 1-4 words; lowercase a-z and 0-9; words separated by hyphens; '
                . 'no quotes, punctuation, emojis, or explanations; preserve the title intent; '
                . 'do not exceed ' . $max_chars . ' characters.';
        } else {
            $system_rule = $custom_system;
        }
        $messages[] = [ 'role' => 'system', 'content' => $system_rule . ( $glossary_tip ? ( ' ' . $glossary_tip ) : '' ) ];
        $messages[] = [ 'role' => 'user', 'content' => $prompt ];

        $resp = wp_remote_post( $endpoint, [
            'timeout' => 20,
            'headers' => $headers,
            'body'    => wp_json_encode( [ 'model' => $model, 'messages' => $messages ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            BAI_Slug_Log::add( '请求 AI 失败: ' . $resp->get_error_message(), $title );
            return null;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        if ( $code !== 200 ) {
            $emsg = self::extract_error_message( $code, $body );
            BAI_Slug_Log::add( 'AI 请求失败: ' . $emsg, $title );
            return null;
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( ! $content ) {
            BAI_Slug_Log::add( 'AI 响应无有效内容', $title );
            return null;
        }
        $slug = sanitize_title( $content );
        if ( strlen( $slug ) > $max_chars ) {
            $cut = substr( $slug, 0, $max_chars );
            $pos = strrpos( $cut, '-' );
            if ( $pos !== false && $pos > 0 ) { $cut = substr( $cut, 0, $pos ); }
            $slug = rtrim( $cut, '-' );
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
                'messages' => [ [ 'role' => 'user', 'content' => 'Hello' ] ],
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'message' => $resp->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code === 200 ) { return [ 'ok' => true, 'message' => 'HTTP 200' ]; }
        $raw = wp_remote_retrieve_body( $resp );
        return [ 'ok' => false, 'message' => self::extract_error_message( $code, $raw ) ];
    }
}

?>
