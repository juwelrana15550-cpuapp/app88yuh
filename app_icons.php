<?php
/**
 * Premium app-icon set for categories.
 *
 * নতুন আইকন যোগ করতে চাইলে নিচের তালিকায় শুধু একটি লাইন যোগ করুন:
 *   'key' => ['Label', 'Group', 'tile-color-1', 'tile-color-2 or null', '<svg glyph, 48x48 box>'],
 * key সর্বোচ্চ ১২ অক্ষর (a-z, 0-9) হতে হবে। Database-এ "app:key" হিসেবে সেভ হয়।
 */
function app_icons(): array {
    static $s = null;
    if ($s !== null) return $s;

    $note = '<path d="M27.2 11h4.1c.3 2.9 2 4.9 4.9 5.2v4.1c-1.9 0-3.6-.6-4.9-1.5v8.2a7.3 7.3 0 1 1-6.3-7.2v4.2a3.2 3.2 0 1 0 2.2 3z"';

    $s = [
        // ---------- Email & Office ----------
        'gmail' => ['Gmail', 'Email & Office', '#ffffff', null,
            '<path d="M10 17.5c0-2.7 3.1-4.3 5.2-2.7L24 21.4l8.8-6.6c2.1-1.6 5.2 0 5.2 2.7V20L24 30.5 10 20z" fill="#EA4335"/>'
            . '<path d="M10 20l5 3.8V34h-3a2 2 0 0 1-2-2z" fill="#4285F4"/>'
            . '<path d="M38 20l-5 3.8V34h3a2 2 0 0 0 2-2z" fill="#34A853"/>'],
        'outlook' => ['Outlook', 'Email & Office', '#2A8BF2', '#0F5FC6',
            '<rect x="22" y="15" width="17" height="18" rx="3" fill="#fff" opacity=".96"/>'
            . '<path d="M23.5 18.5l7 5 7-5" stroke="#1A6FD1" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<rect x="9" y="13" width="18" height="22" rx="3.5" fill="#0B4DA8"/>'
            . '<circle cx="18" cy="24" r="4.5" stroke="#fff" stroke-width="2.8" fill="none"/>'],
        'microsoft' => ['Microsoft', 'Email & Office', '#ffffff', null,
            '<rect x="11" y="11" width="12.5" height="12.5" rx="1" fill="#F25022"/><rect x="24.5" y="11" width="12.5" height="12.5" rx="1" fill="#7FBA00"/>'
            . '<rect x="11" y="24.5" width="12.5" height="12.5" rx="1" fill="#00A4EF"/><rect x="24.5" y="24.5" width="12.5" height="12.5" rx="1" fill="#FFB900"/>'],
        'google' => ['Google', 'Email & Office', '#ffffff', null,
            '<g fill="none" stroke-width="5">'
            . '<path d="M31.28 17.89A9.5 9.5 0 0 0 15.77 19.25" stroke="#EA4335"/>'
            . '<path d="M15.77 19.25A9.5 9.5 0 0 0 15.77 28.75" stroke="#FBBC04"/>'
            . '<path d="M15.77 28.75A9.5 9.5 0 0 0 31.28 30.11" stroke="#34A853"/>'
            . '<path d="M31.28 30.11A9.5 9.5 0 0 0 33.5 24H24.5" stroke="#4285F4"/></g>'],
        'yahoo' => ['Yahoo', 'Email & Office', '#7B2FF2', '#5A0FD0',
            '<text x="24" y="31" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-style="italic" font-size="22" fill="#fff">Y!</text>'],
        'zoom' => ['Zoom', 'Email & Office', '#3B9BFF', '#2D6FF0',
            '<rect x="10" y="17" width="19" height="14" rx="4" fill="#fff"/><path d="M32 22.5l6-4v11l-6-4z" fill="#fff"/>'],
        'dropbox' => ['Dropbox', 'Email & Office', '#1A6BFF', '#0050E0',
            '<g fill="#fff"><path d="M17 12l7 4.5-7 4.5-7-4.5z"/><path d="M31 12l7 4.5-7 4.5-7-4.5z"/><path d="M17 21l7 4.5-7 4.5-7-4.5z"/><path d="M31 21l7 4.5-7 4.5-7-4.5z"/><path d="M24 28.2l7 4.5-7 4.5-7-4.5z"/></g>'],

        // ---------- Social ----------
        'facebook' => ['Facebook', 'Social', '#2B8BFF', '#1259D8',
            '<path d="M26.5 38V26h4l.7-4.7h-4.7v-3c0-1.4.5-2.3 2.4-2.3h2.4V11.9c-.4-.1-1.9-.2-3.5-.2-3.5 0-5.8 2.1-5.8 6v3.6h-4V26h4v12z" fill="#fff"/>'],
        'instagram' => ['Instagram', 'Social', '#9B3FD1', '#FF9A3C',
            '<rect x="13" y="13" width="22" height="22" rx="6.5" fill="none" stroke="#fff" stroke-width="2.8"/>'
            . '<circle cx="24" cy="24" r="5.2" fill="none" stroke="#fff" stroke-width="2.8"/><circle cx="30.6" cy="17.4" r="1.7" fill="#fff"/>'],
        'whatsapp' => ['WhatsApp', 'Social', '#3EDC81', '#14A04F',
            '<path d="M24 12.5a11 11 0 0 0-9.5 16.5L13 35l6.2-1.6A11 11 0 1 0 24 12.5z" fill="none" stroke="#fff" stroke-width="2.6" stroke-linejoin="round"/>'
            . '<path d="M19.6 18.4c-.9 1-.7 2.8 1.5 5.3 2.3 2.5 4.2 3.3 5.4 2.6l1-1a.8.8 0 0 0 0-1l-1.9-1.3a.8.8 0 0 0-1 .1l-.6.6c-.8-.3-2.200-1.600-2.900-2.900l.5-.7a.8.8 0 0 0 0-1l-1.200-2c-.3-.4-.8-.4-1.100 0z" fill="#fff"/>'],
        'telegram' => ['Telegram', 'Social', '#37B6F2', '#1D93D2',
            '<path d="M35.5 14 11.8 23.2c-1 .4-.9 1.300 0 1.600l5.900 1.900 2.300 7c.2.6.9.8 1.400.4l3.300-2.700 5.900 4.300c.7.5 1.500.1 1.700-.7l3.900-18.900c.2-1-.7-1.700-1.700-1.300z" fill="#fff"/>'
            . '<path d="M18 26.3 33 17.5 21 28.4" fill="none" stroke="#2AA3DE" stroke-width="1.6" stroke-linejoin="round"/>'],
        'tiktok' => ['TikTok', 'Social', '#14141A', '#000000',
            $note . ' fill="#25F4EE" transform="translate(-1.2 -.8)"/>'
            . $note . ' fill="#FE2C55" transform="translate(1.2 .8)"/>'
            . $note . ' fill="#fff"/>'],
        'x' => ['X (Twitter)', 'Social', '#1A1A1F', '#000000',
            '<path d="M14.5 13.5h6.2l12.8 21h-6.2z" fill="#fff"/><path d="M33 13.5 15 34.5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>'],
        'youtube' => ['YouTube', 'Social', '#FF3B3B', '#D80000',
            '<rect x="10" y="15" width="28" height="18" rx="5.5" fill="#fff"/><path d="M21 19.6v8.8l8-4.4z" fill="#E00000"/>'],
        'snapchat' => ['Snapchat', 'Social', '#FFFC00', null,
            '<path d="M24 11c-4.6 0-7 3.3-7 7.2 0 1 .1 2 .2 3-.6.1-1.5.2-2.2-.3-.5-.3-1.400.3-.9 1 .4.7 1.800 1.200 2.900 1.500-.3 1.400-1.800 3.200-4 3.800-.8.2-.9 1 0 1.300 1.100.4 2.500.5 2.800 1 .3.7.1 1.600 1 1.800 1 .2 2.200-.3 3.600.4 1.400.8 2.200 2.100 3.400 2.100h.2c1.200 0 2-1.300 3.400-2.100 1.400-.7 2.600-.2 3.600-.4.9-.2.7-1.100 1-1.800.3-.5 1.700-.6 2.800-1 .9-.3.8-1.100 0-1.300-2.200-.6-3.700-2.400-4-3.800 1.100-.3 2.500-.8 2.900-1.500.5-.7-.4-1.300-.9-1-.7.5-1.600.4-2.200.3.1-1 .2-2 .2-3 0-3.900-2.400-7.200-7-7.200z" fill="#fff" stroke="#16161A" stroke-width="1.8" stroke-linejoin="round"/>'],
        'discord' => ['Discord', 'Social', '#6B77FF', '#4752E0',
            '<path d="M34.4 15.3A25 25 0 0 0 28.600 13.500l-.7 1.400a23 23 0 0 0-7.800 0l-.7-1.400a25 25 0 0 0-5.800 1.800C10.300 20.800 9.400 26.200 9.800 31.500a25 25 0 0 0 7.100 3.600l1.500-2.400c-.8-.3-1.600-.7-2.300-1.200l.6-.4a17.800 17.800 0 0 0 14.600 0l.6.4c-.7.5-1.500.9-2.300 1.200l1.500 2.400a25 25 0 0 0 7.100-3.600c.5-6.100-.9-11.400-3.900-16.200z" fill="#fff"/>'
            . '<circle cx="19.3" cy="25" r="2.6" fill="#4F5BE8"/><circle cx="28.7" cy="25" r="2.6" fill="#4F5BE8"/>'],
        'linkedin' => ['LinkedIn', 'Social', '#1685DB', '#0A58A8',
            '<rect x="13" y="21" width="5" height="14" fill="#fff"/><circle cx="15.5" cy="16.2" r="2.9" fill="#fff"/>'
            . '<path d="M21.5 21h4.800v2c.9-1.500 2.600-2.400 4.600-2.400 4 0 5.100 2.600 5.100 6.100V35h-5v-7.200c0-1.600-.4-2.700-1.900-2.700-1.700 0-2.600 1.200-2.600 3V35h-5z" fill="#fff"/>'],
        'twitch' => ['Twitch', 'Social', '#A66BFF', '#7B36E8',
            '<path d="M13.500 12h21.500v14.500l-5.800 5.800h-5.400l-3.600 3.600v-3.600h-6.700z" fill="#fff"/><rect x="21" y="17.500" width="2.800" height="7" rx="1" fill="#7B36E8"/><rect x="28" y="17.500" width="2.800" height="7" rx="1" fill="#7B36E8"/>'],

        // ---------- Entertainment & Payment ----------
        'netflix' => ['Netflix', 'Entertainment & Pay', '#141414', '#000000',
            '<path d="M16 11h5.500v26H16z" fill="#B20710"/><path d="M26.500 11H32v26h-5.500z" fill="#B20710"/><path d="M16 11h5.500L32 37h-5.500z" fill="#E50914"/>'],
        'spotify' => ['Spotify', 'Entertainment & Pay', '#2BE070', '#14A84A',
            '<g fill="none" stroke="#0B1A10" stroke-linecap="round"><path d="M13.500 19.500c7.500-2.200 15.500-1.500 22 2.200" stroke-width="3.600"/><path d="M15 26c6-1.600 12-1 17.300 2" stroke-width="3.200"/><path d="M16.500 32c4.500-1.100 9-.7 13 1.500" stroke-width="2.800"/></g>'],
        'steam' => ['Steam', 'Entertainment & Pay', '#1B2838', '#2F5A7A',
            '<circle cx="29" cy="19.500" r="6.500" fill="none" stroke="#fff" stroke-width="2.600"/><circle cx="29" cy="19.500" r="2.600" fill="#fff"/><path d="M20 28.500l8-6.500" stroke="#fff" stroke-width="3" stroke-linecap="round"/><circle cx="19" cy="31" r="4.200" fill="#fff"/>'],
        'paypal' => ['PayPal', 'Entertainment & Pay', '#1A4DBF', '#0A2C7A',
            '<path d="M18 21h7c3.600 0 5.800 2.300 5.200 5.800-.6 3.700-3.200 5.700-6.800 5.700h-2.600l-.9 5.500H15z" fill="#8EC1FF"/>'
            . '<path d="M20 12h8.500c4.200 0 6.400 2.400 5.800 6-.7 4.200-3.600 6.300-7.700 6.300h-3.200l-1.200 7.200h-4.600z" fill="#fff"/>'],
        'apple' => ['Apple', 'Entertainment & Pay', '#1A1A1F', '#000000',
            '<path d="M30.600 25.200c0-3.100 2.500-4.600 2.700-4.700-1.500-2.200-3.800-2.500-4.600-2.500-1.900-.2-3.800 1.200-4.800 1.200-1 0-2.500-1.100-4.100-1.100-2.100 0-4.100 1.200-5.200 3.100-2.200 3.800-.6 9.500 1.600 12.600 1 1.500 2.300 3.200 3.900 3.200 1.600-.1 2.200-1 4.100-1s2.500 1 4.200 1c1.700 0 2.800-1.600 3.800-3.100 1.200-1.800 1.700-3.400 1.700-3.500-.1 0-3.300-1.300-3.300-5.200zM27.500 15.900c.9-1.100 1.500-2.500 1.300-4-1.300.1-2.800.9-3.700 1.900-.8.900-1.500 2.400-1.300 3.800 1.400.1 2.800-.7 3.700-1.700z" fill="#fff"/>'],
        'amazon' => ['Amazon', 'Entertainment & Pay', '#2B3A4F', '#131A22',
            '<text x="24" y="27" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="700" font-size="20" fill="#fff">a</text>'
            . '<path d="M14 32.500c6.500 4.500 15 4.200 21-.5" fill="none" stroke="#FF9900" stroke-width="2.800" stroke-linecap="round"/><path d="M31.500 29.500l3.800 2.600-4.300 1.400" fill="none" stroke="#FF9900" stroke-width="2.600" stroke-linecap="round" stroke-linejoin="round"/>'],

        // ---------- General ----------
        'shop' => ['Shop (default)', 'General', '#9A8CF3', '#7C3AED',
            '<g fill="none" stroke="#fff" stroke-width="2.800" stroke-linecap="round" stroke-linejoin="round"><path d="M14 19.500 17.500 14h13l3.500 5.500V32a3 3 0 0 1-3 3H17a3 3 0 0 1-3-3z"/><path d="M14 19.500h20"/><path d="M19.500 24.500a4.500 4.500 0 0 0 9 0"/></g>'],
        'shield' => ['Shield / VPN', 'General', '#1FC2B8', '#0F766E',
            '<path d="M24 11.500l10 3.500v8c0 6-4.200 10.500-10 12.500-5.800-2-10-6.500-10-12.500v-8z" fill="#fff"/><path d="M19.500 24l3.200 3.200 5.800-6.200" fill="none" stroke="#0F8F86" stroke-width="2.800" stroke-linecap="round" stroke-linejoin="round"/>'],
        'gift' => ['Gift card', 'General', '#FF6B8B', '#E11D48',
            '<rect x="13" y="22" width="22" height="13" rx="2" fill="#fff"/><rect x="11" y="17" width="26" height="6" rx="2" fill="#fff"/><rect x="22.500" y="17" width="3" height="18" fill="#F43F5E"/>'
            . '<path d="M24 17c-3-1-6-4-4-6s5 1 4 6zm0 0c3-1 6-4 4-6s-5 1-4 6z" fill="none" stroke="#fff" stroke-width="2.400" stroke-linejoin="round"/>'],
        'crown' => ['Premium', 'General', '#FFC04A', '#E27D06',
            '<path d="M12 33.500V19l6.500 5.500L24 14l5.500 10.500L36 19v14.500z" fill="#fff"/><rect x="12" y="35.500" width="24" height="2.800" rx="1.400" fill="#fff"/>'],
    ];
    return $s;
}

/** Kept for compatibility: icons are now self-contained inline SVGs, so no sprite is needed. */
function app_icon_sprite(): string { return ''; }

/** One self-contained inline SVG icon (own gradients, unique ids) - works in every browser/WebView. */
function app_icon_svg(string $k, string $size = '1.1em'): string {
    static $n = 0;
    $set = app_icons();
    if (!isset($set[$k])) $k = 'shop';
    $d = $set[$k];
    $u = 'ai' . (++$n);
    $fill = $d[3] ? 'url(#' . $u . 'g)' : $d[2];
    $defs = '<defs>'
        . ($d[3] ? '<linearGradient id="' . $u . 'g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $d[2] . '"/><stop offset="1" stop-color="' . $d[3] . '"/></linearGradient>' : '')
        . '<linearGradient id="' . $u . 's" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".30"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/></linearGradient></defs>';
    return '<svg class="appi" width="' . $size . '" height="' . $size . '" viewBox="0 0 48 48" aria-hidden="true" style="display:inline-block;vertical-align:-.22em">'
        . $defs . '<rect width="48" height="48" rx="11" fill="' . $fill . '"/>' . $d[4]
        . '<rect width="48" height="48" rx="11" fill="url(#' . $u . 's)"/>'
        . '<rect x=".5" y=".5" width="47" height="47" rx="10.5" fill="none" stroke="#000" stroke-opacity=".10"/></svg>';
}

/** Category icon as HTML: premium app icon ("app:gmail") or a plain emoji. */
function cat_icon($v, string $size = '1.1em'): string {
    $v = trim((string)$v);
    if ($v === '') $v = 'app:shop';
    if (strncmp($v, 'app:', 4) === 0) return app_icon_svg(substr($v, 4), $size);
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/** Plain-text version (for <option> lists, which cannot hold SVG). */
function cat_icon_text($v): string {
    $v = trim((string)$v);
    return ($v === '' || strncmp($v, 'app:', 4) === 0) ? '' : $v;
}
function cat_icon_label($v): string {
    $v = trim((string)$v);
    if (strncmp($v, 'app:', 4) === 0) return app_icons()[substr($v, 4)][0] ?? 'Shop (default)';
    return $v === '' ? 'Shop (default)' : 'Emoji ' . $v;
}
