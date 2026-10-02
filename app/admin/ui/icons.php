<?php
// Иконки интерфейса админки: линейный SVG-спрайт, подключается через <use href="#i-имя">.
// Отдельно от иконок сайта (app/lib/icons.php): там заливка, здесь обводка.
declare(strict_types=1);

const ADM_ICONS = [
    'building' => 'M4 21V5l8-2 8 2v16M9 9h1M14 9h1M9 13h1M14 13h1M10 21v-4h4v4',
    'layers' => 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5',
    'doc' => 'M7 3h8l4 4v14H7zM14 3v5h5M10 13h6M10 17h6',
    'mail' => 'M3 5h18v14H3zM3 6l9 7 9-7',
    'phone' => 'M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z',
    'star' => 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z',
    'zoom' => 'M11 18a7 7 0 100-14 7 7 0 000 14zM20 20l-4-4M11 8v6M8 11h6',
    'clock' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2',
    'menu' => 'M4 7h16M4 12h16M4 17h16',
    'close' => 'M6 6l12 12M18 6 6 18',
    'check' => 'M5 12.5l4.5 4.5L19 7.5',
    'arrow' => 'M5 12h14M13 6l6 6-6 6',
    'chevron' => 'M6 9l6 6 6-6',
    'users' => 'M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM2.5 20a6.5 6.5 0 0113 0M16 4.5a3.5 3.5 0 010 6.5M18 14a6 6 0 013.5 6',
    'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5L16 9.5',
    'truck' => 'M2 6h11v10H2zM13 9h4l3 3v4h-7M6.5 19.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM17 19.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z',
    'gear' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.6 1.6 0 00.32 1.77l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.6 1.6 0 00-1.77-.32 1.6 1.6 0 00-1 1.47V21a2 2 0 11-4 0v-.1A1.6 1.6 0 008.9 19.4a1.6 1.6 0 00-1.77.32l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.6 1.6 0 00.32-1.77 1.6 1.6 0 00-1.47-1H3a2 2 0 110-4h.1A1.6 1.6 0 004.6 8.9a1.6 1.6 0 00-.32-1.77l-.06-.06a2 2 0 112.83-2.83l.06.06a1.6 1.6 0 001.77.32H9a1.6 1.6 0 001-1.47V3a2 2 0 114 0v.1a1.6 1.6 0 001 1.47 1.6 1.6 0 001.77-.32l.06-.06a2 2 0 112.83 2.83l-.06.06a1.6 1.6 0 00-.32 1.77V9a1.6 1.6 0 001.47 1H21a2 2 0 110 4h-.1a1.6 1.6 0 00-1.47 1z',
];

function sprite_svg(): string
{
    $symbols = '';
    foreach (ADM_ICONS as $k => $d) $symbols .= '<symbol id="i-' . $k . '" viewBox="0 0 24 24"><path d="' . $d . '"/></symbol>';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"'
        . ' fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $symbols . '</svg>';
}

function adm_icon(string $name, string $cls = ''): string
{
    return '<svg class="ic ' . $cls . '" aria-hidden="true" focusable="false"><use href="#i-' . $name . '"/></svg>';
}

// Знак завода: та же «Д» с янтарным квадратом, что и в шапке сайта.
const LOGO_MARK = '<svg class="logo__mark" viewBox="0 0 40 40" aria-hidden="true" focusable="false">'
    . '<rect width="40" height="40" rx="7" fill="#E8761A"/>'
    . '<path fill="#14171A" d="M9 11h8.6c5.3 0 8.9 3.5 8.9 9s-3.6 9-8.9 9H9zm4.6 4v10h3.7c2.9 0 4.6-1.9 4.6-5s-1.7-5-4.6-5zM28.5 11H32v18h-3.5z"/></svg>';
