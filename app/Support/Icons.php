<?php

namespace App\Support;

/**
 * The inline icon set behind <x-ds.icon name="...">. One drawing grammar: a 24 x 24 grid, 1.75 stroke, square caps and mitre
 * joins (angular, like a paddle number), `currentColor` only. No icon font, no CDN. Each value is the inner markup of the <svg>.
 * The names are listed in DESIGN.md; add to both together.
 */
final class Icons
{
    /** @var array<string, string> */
    private const SET = [
        // Auction state vocabulary (each status has its own silhouette, see DESIGN.md)
        'play' => '<path d="M7 5v14l11-7z"/>',
        'hourglass' => '<path d="M6 3h12M6 21h12M7 3v3l5 6-5 6v3M17 3v3l-5 6 5 6v3"/>',
        'calendar' => '<path d="M4 6h16v14H4zM4 10h16M8 3v5M16 3v5"/>',
        'gavel' => '<path d="M13 4.5l6 6-3.5 3.5-6-6zM11.2 11.2L4.5 17.9M3 21h9"/>',
        'no-sale' => '<path d="M4 4h16v16H4zM8 12h8"/>',
        'pause' => '<path d="M8 5v14M16 5v14"/>',
        'ban' => '<path d="M12 3a9 9 0 100 18 9 9 0 000-18zM5.6 5.6l12.8 12.8"/>',
        'extend' => '<path d="M3 12h11M10 7l5 5-5 5M20 5v14"/>',
        'pencil' => '<path d="M4 20l1-5L16 4l4 4L9 19zM14 6l4 4"/>',

        // Navigation
        'home' => '<path d="M3 11l9-8 9 8M5 10v10h14V10"/>',
        'browse' => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
        'bids' => '<path d="M3 17l6-6 4 4 8-8M15 7h6v6"/>',
        'bookmark' => '<path d="M6 3h12v18l-6-4-6 4z"/>',
        'trophy' => '<path d="M8 4h8v5a4 4 0 01-8 0zM8 6H4v1a4 4 0 004 4M16 6h4v1a4 4 0 01-4 4M12 13v4M8 21h8M10 17h4"/>',
        'tag' => '<path d="M3 3h8l10 10-8 8L3 11zM7.5 7.5h.01"/>',
        'receipt' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2zM9 8h6M9 12h6"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4"/>',
        'ladder' => '<path d="M7 3v18M17 3v18M7 7h10M7 12h10M7 17h10"/>',

        // Account and chrome
        'bell' => '<path d="M6 17v-6a6 6 0 1112 0v6l2 2H4zM10 21h4"/>',
        'bell-off' => '<path d="M6 17v-6a6 6 0 011.5-4M18 11v6l2 2H4M10 21h4M4 4l16 16"/>',
        'sun' => '<path d="M12 8a4 4 0 100 8 4 4 0 000-8zM12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9L7 7M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
        'moon' => '<path d="M20 14.5A8 8 0 019.5 4a8 8 0 1010.5 10.5z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M5 5l14 14M19 5L5 19"/>',
        'user' => '<path d="M12 4a4 4 0 100 8 4 4 0 000-8zM4 21c0-4 3.5-6 8-6s8 2 8 6"/>',
        'users' => '<path d="M9 5a3.5 3.5 0 100 7 3.5 3.5 0 000-7zM2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5M16 5.2a3.5 3.5 0 010 6.6M18 14.8c2.4.7 4 2.3 4 5.2"/>',
        'logout' => '<path d="M10 4H4v16h6M15 8l4 4-4 4M9 12h10"/>',
        'sliders' => '<path d="M4 6h9M17 6h3M13 4v4h4V4zM4 12h3M11 12h9M7 10v4h4v-4zM4 18h11M19 18h1M15 16v4h4v-4z"/>',
        'search' => '<path d="M11 4a7 7 0 100 14 7 7 0 000-14zM16 16l5 5"/>',
        'lock' => '<path d="M6 11h12v10H6zM8 11V7a4 4 0 018 0v4"/>',
        'key' => '<path d="M8 8a4 4 0 100 .01zM11 11l9 9M16 16l-2 2M19 19l-2 2"/>',
        'mail' => '<path d="M3 5h18v14H3zM3 6l9 7 9-7"/>',

        // Actions and signals
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'check' => '<path d="M4 12.5l5 5L20 6.5"/>',
        'check-circle' => '<path d="M12 3a9 9 0 100 18 9 9 0 000-18zM8 12.5l3 3 5-6"/>',
        'x-circle' => '<path d="M12 3a9 9 0 100 18 9 9 0 000-18zM9 9l6 6M15 9l-6 6"/>',
        'alert' => '<path d="M12 3L2 20h20zM12 10v5M12 17.5v.5"/>',
        'info' => '<path d="M12 3a9 9 0 100 18 9 9 0 000-18zM12 11v6M12 7.5v.5"/>',
        'clock' => '<path d="M12 3a9 9 0 100 18 9 9 0 000-18zM12 7v5l3 2"/>',
        'arrow-left' => '<path d="M20 12H4M10 6l-6 6 6 6"/>',
        'arrow-right' => '<path d="M4 12h16M14 6l6 6-6 6"/>',
        'arrow-up' => '<path d="M12 20V4M6 10l6-6 6 6"/>',
        'arrow-down' => '<path d="M12 4v16M6 14l6 6 6-6"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'chevron-up' => '<path d="M6 15l6-6 6 6"/>',
        'chevron-left' => '<path d="M15 6l-6 6 6 6"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'external' => '<path d="M14 4h6v6M20 4L10 14M18 14v6H4V6h6"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 14h10l1-14"/>',
        'image' => '<path d="M3 5h18v14H3zM3 16l5-5 4 4 3-3 6 6M15 9h.01"/>',
        'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'sort' => '<path d="M7 4v16M3 16l4 4 4-4M17 20V4M13 8l4-4 4 4"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 9a3 3 0 100 6 3 3 0 000-6z"/>',
        'eye-off' => '<path d="M2 12s4-7 10-7c2 0 3.8.7 5.3 1.7M22 12s-4 7-10 7c-2 0-3.8-.7-5.3-1.7M4 4l16 16"/>',
        'copy' => '<path d="M8 8h12v12H8zM16 8V4H4v12h4"/>',
        'upload' => '<path d="M12 16V4M6 10l6-6 6 6M4 20h16"/>',
        'download' => '<path d="M12 4v12M6 10l6 6 6-6M4 20h16"/>',
        'loader' => '<path d="M12 3a9 9 0 019 9"/>',
        'dots' => '<circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none"/>',
    ];

    public static function has(string $name): bool
    {
        return isset(self::SET[$name]);
    }

    public static function markup(string $name): string
    {
        return self::SET[$name] ?? self::SET['info'];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::SET);
    }
}
