<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BAI_Slug_Log {
    private static $opt = '_bai_slug_error_log';
    private static $max = 200;

    public static function add( $message, $title = '' ) {
        $log = get_option( self::$opt, [] );
        array_unshift( $log, [ 'time' => current_time( 'mysql' ), 'message' => (string) $message, 'title' => (string) $title ] );
        if ( count( $log ) > self::$max ) { $log = array_slice( $log, 0, self::$max ); }
        update_option( self::$opt, $log );
    }

    public static function get() { return (array) get_option( self::$opt, [] ); }
    public static function clear() { delete_option( self::$opt ); }

    public static function display() {
        if ( isset( $_POST['bai_clear_log'] ) ) {
            check_admin_referer( 'bai_clear_log' );
            self::clear();
            echo '<div class="notice notice-success"><p>日志已清空。</p></div>';
        }
        $log = self::get();
        echo '<form method="post" style="margin:10px 0;">';
        wp_nonce_field( 'bai_clear_log' );
        submit_button( '清空日志', 'secondary', 'bai_clear_log', false );
        echo '</form>';

        echo '<table class="widefat striped" style="max-width:1000px;">';
        echo '<thead><tr><th>时间</th><th>标题</th><th>信息</th></tr></thead><tbody>';
        if ( empty( $log ) ) {
            echo '<tr><td colspan="3">暂无日志</td></tr>';
        } else {
            foreach ( $log as $e ) {
                echo '<tr>';
                echo '<td>' . esc_html( $e['time'] ) . '</td>';
                echo '<td>' . esc_html( $e['title'] ) . '</td>';
                echo '<td>' . esc_html( $e['message'] ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }
}

?>
