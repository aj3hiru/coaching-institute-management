<?php
// Inline SVG icons for social networks (author box, author profile). Same drawings as the CMS theme icons.
function edu_social_svg(string $net, int $size = 16): string
{
    $paths = [
        'facebook' => '<path fill="currentColor" d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/>',
        'x' => '<path fill="currentColor" d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/>',
        'instagram' => '<g fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/></g><circle cx="17.5" cy="6.5" r="1.2" fill="currentColor"/>',
        'threads' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M18.6 8.6C17.5 5.8 15.1 4 12 4 7.6 4 4.5 7.5 4.5 12s3.1 8 7.5 8c3.3 0 5.9-1.7 6.6-4.4.6-2.3-.6-4.4-3-5.1-2.2-.7-4.8 0-5.3 1.8-.4 1.5.8 2.8 2.6 2.7 2-.1 3.1-1.6 3.1-3.8 0-2.3-1.2-3.7-3.2-3.7-1.4 0-2.4.6-3 1.5"/>',
        'linkedin' => '<path fill="currentColor" d="M5.2 8.2H2V21h3.2V8.2ZM3.6 3A1.9 1.9 0 1 0 3.6 6.8 1.9 1.9 0 0 0 3.6 3ZM21.8 13.7c0-3.9-2.1-5.8-5-5.8-2.3 0-3.3 1.3-3.9 2.2V8.2H9.7V21h3.2v-6.3c0-1.7.3-3.4 2.5-3.4 2.1 0 2.1 2 2.1 3.5V21h3.3v-7.3Z"/>',
        'mail' => '<g fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></g>',
        'pin' => '<g fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/></g>',
        'globe' => '<g fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3Z"/></g>',
        'cap' => '<path fill="currentColor" d="M12 3 1 9l11 6 9-4.9V17h2V9L12 3Zm-6.8 9.3V16c0 1.9 3 3.5 6.8 3.5s6.8-1.6 6.8-3.5v-3.7L12 16l-6.8-3.7Z"/>',
        'briefcase' => '<g fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/></g>',
        'star' => '<path fill="currentColor" d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.3l-5.9 3.3 1.3-6.6-4.9-4.6 6.6-.8L12 2.5Z"/>',
        'verified' => '<path fill="#1d9bf0" d="M22.5 12.5c0-1.58-.875-2.95-2.148-3.6.154-.435.238-.905.238-1.4 0-2.21-1.71-3.998-3.818-3.998-.47 0-.92.084-1.336.25C14.818 2.415 13.51 1.5 12 1.5s-2.816.917-3.437 2.25c-.415-.165-.866-.25-1.336-.25-2.11 0-3.818 1.79-3.818 4 0 .494.083.964.237 1.4-1.272.65-2.147 2.018-2.147 3.6 0 1.495.782 2.798 1.942 3.486-.02.17-.032.34-.032.514 0 2.21 1.708 4 3.818 4 .47 0 .92-.086 1.335-.25.62 1.334 1.926 2.25 3.437 2.25 1.512 0 2.818-.916 3.437-2.25.415.163.865.248 1.336.248 2.11 0 3.818-1.79 3.818-4 0-.174-.012-.344-.033-.513 1.158-.687 1.943-1.99 1.943-3.484zm-6.616-3.334l-4.334 6.5c-.145.217-.382.334-.625.334-.143 0-.288-.04-.416-.126l-.115-.094-2.415-2.415c-.293-.293-.293-.768 0-1.06s.768-.294 1.06 0l1.77 1.767 3.825-5.74c.23-.345.696-.436 1.04-.207.346.23.44.696.21 1.04z"/>',
        'back' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m6-7-7 7 7 7"/>',
    ];
    $p = $paths[$net] ?? '';
    return $p === '' ? '' : '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

/** Social links of an author row (columns instagram, threads, linkedin, facebook, twitter) → [network => url], X first. */
function edu_author_socials(array $a, string $prefix = ''): array
{
    $out = [];
    foreach (['x' => 'twitter', 'instagram' => 'instagram', 'linkedin' => 'linkedin', 'facebook' => 'facebook', 'threads' => 'threads'] as $net => $col) {
        $u = trim((string)($a[$prefix . $col] ?? ''));
        if ($u !== '' && preg_match('#^https?://#i', $u)) $out[$net] = $u;
    }
    return $out;
}
