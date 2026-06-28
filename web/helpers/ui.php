<?php
/**
 * NexAlert - UI helpers for admin templates (tooltips, labels).
 */

declare(strict_types=1);

/** HTML attributes for a hover tooltip on any element. */
function tip_attr(string $text, string $position = 'top'): string
{
    return ' data-tip="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-tip-pos="' . htmlspecialchars($position, ENT_QUOTES, 'UTF-8') . '"';
}

/** Small ? icon with tooltip — use inside labels. */
function tip_icon(string $text, string $position = 'top'): string
{
    return '<span class="tip-icon" tabindex="0" role="button" aria-label="Help"'
        . tip_attr($text, $position)
        . '>?</span>';
}

/** Label text with optional tooltip icon. */
function tip_label(string $label, ?string $tip = null, string $position = 'top'): string
{
    $html = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    if ($tip !== null && $tip !== '') {
        $html = '<span class="inline-flex items-center gap-1">' . $html . tip_icon($tip, $position) . '</span>';
    }

    return $html;
}

/** Nav link with optional tooltip on the anchor. */
function nav_tip_attr(?string $tip): string
{
    return ($tip !== null && $tip !== '') ? tip_attr($tip, 'right') : '';
}
