<?php
/**
 * icons.php — inline SVG icon set (no icon font, no external request).
 * All icons inherit currentColor and scale with the surrounding font size.
 */

declare(strict_types=1);

/** Feather-style 24x24 stroke paths. */
function icon_paths(): array
{
    static $paths = null;
    if ($paths !== null) {
        return $paths;
    }

    $paths = [
        'dashboard'    => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'chart'        => '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
        'users'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user'         => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'user-check'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M17 11l2 2 4-4"/>',
        'org'          => '<path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16"/><path d="M15 9h2a2 2 0 0 1 2 2v10"/><path d="M9 7h2M9 11h2M9 15h2"/>',
        'flag'         => '<path d="M4 21V4"/><path d="M4 4h11l-1 4 3 3H4z"/>',
        'calendar'     => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>',
        'doc'          => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
        'doc-check'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 15l2 2 4-4"/>',
        'megaphone'    => '<path d="M3 11v2a1 1 0 0 0 1 1h3l7 4V6L7 10H4a1 1 0 0 0-1 1z"/><path d="M18 9a4 4 0 0 1 0 6"/>',
        'qr'           => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM19 19h2v2h-2zM14 20h2M20 14h1"/>',
        'check'        => '<path d="M20 6L9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
        'alert'        => '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'info'         => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
        'close'        => '<path d="M18 6L6 18M6 6l12 12"/>',
        'menu'         => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'logout'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'search'       => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'bell'         => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'shield'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
        'list'         => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'clock'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'plus'         => '<path d="M12 5v14M5 12h14"/>',
        'edit'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash'        => '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/><path d="M10 11v6M14 11v6"/>',
        'download'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'upload'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/>',
        'print'        => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 17h12v4H6z"/>',
        'filter'       => '<path d="M22 3H2l8 9.5V19l4 2v-8.5z"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'chevron-right'=> '<path d="M9 18l6-6-6-6"/>',
        'chevron-left' => '<path d="M15 18l-6-6 6-6"/>',
        'camera'       => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'refresh'      => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'star'         => '<path d="M12 2l3 6.5 7 .9-5 4.8 1.3 7-6.3-3.4-6.3 3.4L7 14.2l-5-4.8 7-.9z"/>',
        'folder'       => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'map-pin'      => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'award'        => '<circle cx="12" cy="9" r="6"/><path d="M8.2 14L7 22l5-3 5 3-1.2-8"/>',
        'clipboard'    => '<path d="M9 4h6v3H9z"/><path d="M9 5.5H7a2 2 0 0 0-2 2V19a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7.5a2 2 0 0 0-2-2h-2"/>',
        'external'     => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14L21 3"/>',
        'eye'          => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'mail'         => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'phone'        => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
        'briefcase'    => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 12h18"/>',
        'layers'       => '<path d="M12 2l10 5-10 5L2 7z"/><path d="M2 12l10 5 10-5M2 17l10 5 10-5"/>',
        'hash'         => '<path d="M4 9h16M4 15h16M10 3L8 21M16 3l-2 18"/>',
        'database'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.7-4 3-9 3s-9-1.3-9-3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/>',
        'log'          => '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
        'image'        => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 16l-5-5-9 9"/>',
        'play'         => '<path d="M6 4l14 8-14 8z"/>',
        'scan'         => '<path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/><path d="M3 12h18"/>',
        'lock'         => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'inbox'        => '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5 5h14l2 7v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6z"/>',
        'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M4 12h2M18 12h2M12 4v2M12 18v2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M17.7 6.3l-1.4 1.4M7.7 16.3l-1.4 1.4"/>',
    ];

    return $paths;
}

/**
 * Returns inline SVG markup for an icon.
 * An unknown name renders a neutral dot instead of breaking the layout.
 */
function icon(string $name, int $size = 18, string $class = ''): string
{
    $paths = icon_paths();
    $body  = $paths[$name] ?? '<circle cx="12" cy="12" r="4"/>';
    $class = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
    return '<svg class="ico ' . $class . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
        . ' fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/** Inline Philippine flag strip used by the institutional header bar. */
function flag_ph(int $height = 15): string
{
    $h = max(10, min(60, $height));
    $w = (int) round($h * 2);
    // Official Philippine flag: 1:2 ratio, blue top, red bottom, white hoist triangle,
    // golden sun (8 rays) at triangle centre, three 5-pointed stars at triangle corners.
    return '<svg class="ph-flag" width="' . $w . '" height="' . $h . '" viewBox="0 0 900 450" role="img" aria-label="Philippine flag" xmlns="http://www.w3.org/2000/svg">'
        . '<rect width="900" height="225" fill="#0038A8"/>'
        . '<rect y="225" width="900" height="225" fill="#CE1126"/>'
        . '<polygon points="0,0 0,450 390,225" fill="#FFFFFF"/>'
        . '<g transform="translate(130,225)" fill="#FCD116">'
        . '<polygon points="0,-85 6,-26 0,-18 -6,-26"/>'
        . '<polygon points="60,-60 16,-9 8,-18 22,-27"/>'
        . '<polygon points="85,0 26,6 18,0 26,-6"/>'
        . '<polygon points="60,60 9,16 18,8 27,22"/>'
        . '<polygon points="0,85 -6,26 0,18 6,26"/>'
        . '<polygon points="-60,60 -16,9 -8,18 -22,27"/>'
        . '<polygon points="-85,0 -26,-6 -18,0 -26,6"/>'
        . '<polygon points="-60,-60 -9,-16 -18,-8 -27,-22"/>'
        . '<circle r="32"/>'
        . '</g>'
        . '<g transform="translate(38,52)" fill="#FCD116"><polygon points="0,-20 4.7,-14.5 11.8,-14.5 6.3,-9 8.7,-2.5 0,-7 -8.7,-2.5 -6.3,-9 -11.8,-14.5 -4.7,-14.5"/></g>'
        . '<g transform="translate(38,398)" fill="#FCD116"><polygon points="0,-20 4.7,-14.5 11.8,-14.5 6.3,-9 8.7,-2.5 0,-7 -8.7,-2.5 -6.3,-9 -11.8,-14.5 -4.7,-14.5"/></g>'
        . '<g transform="translate(334,225)" fill="#FCD116"><polygon points="0,-20 4.7,-14.5 11.8,-14.5 6.3,-9 8.7,-2.5 0,-7 -8.7,-2.5 -6.3,-9 -11.8,-14.5 -4.7,-14.5"/></g>'
        . '</svg>';
}

