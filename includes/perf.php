<?php
// Performance + Code Snippets helpers.
// - Code Snippets (head / after header / footer) from Admin → Settings → Code Snippets.
// - "Delay ads and tracking scripts": their <script> tags become type="text/edu-delay" and run after the visitor's
//   first scroll / tap / key press, or after the timeout — so the page shows and responds first.
// - Page-cache lifetimes are kept in cache/.settings.json so the cached pages can read them before loading anything.
require_once __DIR__ . '/settings.php';

function edu_perf_defaults(): array
{
    return ['delay_scripts' => 'off', 'delay_timeout' => 3, 'ttl_home' => 3600, 'ttl_post' => 21600, 'ttl_list' => 3600,
            'cdn_headers' => '0', 'cdn_max_age' => 60, 'cdn_swr' => '1', 'cache_off' => '0'];
}

function perf_opt(string $k)
{
    return es('performance', $k, edu_perf_defaults()[$k]);
}

/** Marks scripts for delayed loading (JSON-LD and scripts marked data-no-delay are left alone). */
function edu_delay_scripts(string $html): string
{
    if (perf_opt('delay_scripts') === 'off') return $html;
    return preg_replace_callback('/<script\b([^>]*)>/i', function ($m) {
        $attrs = $m[1];
        if (stripos($attrs, 'data-no-delay') !== false || preg_match('/type\s*=\s*["\']?(application\/ld\+json|application\/json|text\/template)/i', $attrs)) return $m[0];
        $attrs = preg_replace('/\stype\s*=\s*(["\'])[^"\']*\1/i', '', $attrs);
        return '<script type="text/edu-delay"' . $attrs . '>';
    }, $html);
}

function edu_snippet(string $where): string
{
    $code = (string)es('snippets', $where, '');
    return $code === '' ? '' : edu_delay_scripts($code);
}

/** Loader for delayed scripts — printed once at the end of the page when delaying is on. */
function edu_delay_loader(): string
{
    if (perf_opt('delay_scripts') === 'off') return '';
    $timeout = perf_opt('delay_scripts') === 'interaction' ? 0 : max(1, min(20, (int)perf_opt('delay_timeout'))) * 1000;
    return '<script data-no-delay>(function(){var done=false,ev=["scroll","pointerdown","keydown","touchstart","mousemove"];function run(){if(done)return;done=true;ev.forEach(function(e){removeEventListener(e,run,{passive:true})});var list=[].slice.call(document.querySelectorAll(\'script[type="text/edu-delay"]\'));(function next(){var o=list.shift();if(!o)return;var s=document.createElement("script");[].slice.call(o.attributes).forEach(function(a){if(a.name!=="type")s.setAttribute(a.name,a.value)});if(o.src){s.onload=s.onerror=next;s.async=false;o.parentNode.replaceChild(s,o)}else{s.text=o.text;o.parentNode.replaceChild(s,o);next()}})()}ev.forEach(function(e){addEventListener(e,run,{passive:true,once:true})});' . ($timeout ? 'addEventListener("load",function(){setTimeout(run,' . $timeout . ')});' : '') . '})();</script>';
}

/** Writes the cache lifetimes + CDN header options where cached pages can read them cheaply. */
function edu_perf_write_cache_settings(): void
{
    $dir = ROOT_PATH . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/.settings.json', json_encode([
        'home' => (int)perf_opt('ttl_home'), 'post' => (int)perf_opt('ttl_post'), 'list' => (int)perf_opt('ttl_list'),
        'cdn' => perf_opt('cdn_headers') === '1', 'max_age' => (int)perf_opt('cdn_max_age'), 'swr' => perf_opt('cdn_swr') === '1',
        'off' => perf_opt('cache_off') === '1', // Cache Manager → cache system OFF: pages are neither read from nor written to the cache
    ]));
}
