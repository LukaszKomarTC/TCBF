<?php
/**
 * Diff Renderer.
 *
 * Generates human-readable diffs for SEO metadata changes, used in the
 * Action Review admin page.
 *
 * @package Tossa\Agent\SEO
 */

declare(strict_types=1);

namespace Tossa\Agent\SEO;

final class DiffRenderer {

    /**
     * Render a side-by-side diff of before/after SEO metadata.
     *
     * @param array $before Before snapshot (field => value).
     * @param array $after  After snapshot or proposed values (field => value).
     * @return array<string, array{before: mixed, after: mixed, changed: bool}>
     */
    public function diff(array $before, array $after): array {
        $all_keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $result   = [];

        foreach ($all_keys as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;

            $result[$key] = [
                'before'  => $b,
                'after'   => $a,
                'changed' => $b !== $a,
            ];
        }

        return $result;
    }

    /**
     * Render an HTML diff table for admin display.
     *
     * @param array $before Before snapshot.
     * @param array $after  After/proposed values.
     * @param string[] $fields Only show these fields (empty = all).
     * @return string HTML table.
     */
    public function render_html(array $before, array $after, array $fields = []): string {
        $diff = $this->diff($before, $after);

        if (! empty($fields)) {
            $diff = array_intersect_key($diff, array_flip($fields));
        }

        $rows = '';
        foreach ($diff as $field => $values) {
            $class = $values['changed'] ? 'tossa-diff-changed' : 'tossa-diff-same';
            $before_display = esc_html($values['before'] ?? '(empty)');
            $after_display  = esc_html($values['after'] ?? '(empty)');

            $rows .= sprintf(
                '<tr class="%s"><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                esc_attr($class),
                esc_html($field),
                $before_display,
                $values['changed'] ? "<strong>{$after_display}</strong>" : $after_display
            );
        }

        return sprintf(
            '<table class="widefat tossa-diff-table"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>',
            esc_html__('Field', 'tossa-ai-agent'),
            esc_html__('Current', 'tossa-ai-agent'),
            esc_html__('Proposed', 'tossa-ai-agent'),
            $rows
        );
    }

    /**
     * Generate a plain-text diff summary (for logs/email).
     */
    public function render_text(array $before, array $after): string {
        $diff  = $this->diff($before, $after);
        $lines = [];

        foreach ($diff as $field => $values) {
            if (! $values['changed']) {
                continue;
            }

            $lines[] = sprintf(
                '%s: "%s" => "%s"',
                $field,
                $values['before'] ?? '(empty)',
                $values['after'] ?? '(empty)'
            );
        }

        return implode("\n", $lines);
    }
}
