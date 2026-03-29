<?php
/**
 * Database migration runner.
 *
 * Discovers numbered migration files in the migrations/ directory and runs
 * any that have not been applied yet. Stores the current version in wp_options.
 *
 * @package Tossa\Agent\Database
 */

declare(strict_types=1);

namespace Tossa\Agent\Database;

final class Migrator {

    private const OPTION_KEY = 'tossa_agent_db_version';

    /**
     * Run all pending migrations in order.
     */
    public function run(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $applied   = (int) get_option(self::OPTION_KEY, 0);
        $dir       = __DIR__ . '/migrations';
        $files     = glob($dir . '/*.php');

        if (! $files) {
            return;
        }

        sort($files);
        $charset_collate = $wpdb->get_charset_collate();

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            // File format: 001-description.php — extract the number prefix.
            $number = (int) explode('-', $basename, 2)[0];

            if ($number <= $applied) {
                continue;
            }

            $sql = $this->load_migration($file, $wpdb->prefix, $charset_collate);

            if ($sql) {
                dbDelta($sql);
            }

            update_option(self::OPTION_KEY, $number, true);
        }

        // Also store plugin version for upgrade detection.
        update_option('tossa_agent_db_version', TOSSA_AGENT_VERSION, true);
    }

    /**
     * Load a migration file and return the SQL string.
     *
     * Each migration file should return a string of SQL via a closure that
     * receives ($prefix, $charset_collate).
     */
    private function load_migration(string $file, string $prefix, string $charset_collate): string {
        $migration = require $file;

        if (is_callable($migration)) {
            return (string) $migration($prefix, $charset_collate);
        }

        return '';
    }

    /**
     * Get the current migration version number.
     */
    public static function current_version(): int {
        return (int) get_option(self::OPTION_KEY, 0);
    }
}
