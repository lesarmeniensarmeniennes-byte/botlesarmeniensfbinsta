<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Simple queue status helpers.
 */
class ANA_Queue_Manager {

    public static function get(): array {
        return (array) get_option( 'ana_queue', [] );
    }

    public static function count(): int {
        return count( self::get() );
    }

    public static function is_running(): bool {
        return (string) get_option( 'ana_queue_status', 'idle' ) === 'running';
    }

    public static function reset(): void {
        update_option( 'ana_queue', [] );
        update_option( 'ana_queue_status', 'idle' );
        update_option( 'ana_queue_log', [] );
    }
}
