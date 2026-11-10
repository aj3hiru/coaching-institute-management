<?php // the FAQ design of the homepage is used on every page with FAQs ($faqLayout = 'classic' keeps the old list)
if (($faqLayout ?? 'home') === 'home'): ?>
<!-- FAQ SECTION (homepage design) -->
<section class="hf" aria-labelledby="faq-heading">
  <style>
  .hf{max-width:var(--nb-container,1200px);margin:46px auto 20px;padding:0 16px;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.7fr);gap:36px;align-items:start;box-sizing:border-box}
  .hf-intro{position:sticky;top:90px;padding:28px 26px;border-radius:18px;background:linear-gradient(160deg,color-mix(in srgb,var(--gc-accent,#0c6878) 9%,#fff),#fff);border:1px solid color-mix(in srgb,var(--gc-accent,#0c6878) 16%,#fff)}
  .hf-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:99px;background:var(--gc-accent,#0c6878);color:#fff;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
  .hf-badge svg{width:14px;height:14px;fill:currentColor}
  .hf .hf-h2{margin:14px 0 10px;font-size:28px;line-height:1.25;font-weight:800;color:var(--text-primary,#111827)}
  .hf-sub{margin:0 0 20px;color:#4b5563;font-size:15px;line-height:1.6}
  .hf-help{display:flex;align-items:center;gap:12px;padding-top:18px;border-top:1px dashed color-mix(in srgb,var(--gc-accent,#0c6878) 25%,#fff)}
  .hf-help span{font-size:14px;color:#374151;line-height:1.4}.hf-help b{display:block;color:#111827}
  .hf-btn{margin-left:auto;flex-shrink:0;display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:10px;background:var(--gc-accent,#0c6878);color:#fff !important;font-size:14px;font-weight:700;transition:filter .15s,transform .15s}
  .hf-btn:hover{filter:brightness(1.08);transform:translateY(-1px)}
  .hf-list{display:flex;flex-direction:column;gap:12px}
  .hf-item{border:1px solid #e5e7eb;border-radius:14px;background:#fff;transition:border-color .2s,box-shadow .2s}
  .hf-item[open]{border-color:color-mix(in srgb,var(--gc-accent,#0c6878) 45%,#fff);box-shadow:0 10px 26px rgba(16,24,40,.07)}
  .hf-item summary{list-style:none;display:flex;align-items:center;gap:14px;padding:16px 18px;cursor:pointer;font-size:16px;font-weight:700;line-height:1.45;color:#111827}
  .hf-item summary::-webkit-details-marker{display:none}
  .hf-item summary:focus-visible{outline:2px solid var(--gc-accent,#0c6878);outline-offset:2px;border-radius:14px}
  .hf-num{flex-shrink:0;width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:color-mix(in srgb,var(--gc-accent,#0c6878) 10%,#fff);color:var(--gc-accent,#0c6878);font-size:13px;font-weight:800}
  .hf-item[open] .hf-num{background:var(--gc-accent,#0c6878);color:#fff}
  .hf-q{flex:1;min-width:0}
  .hf-pm{position:relative;flex-shrink:0;width:28px;height:28px;border-radius:50%;background:#f3f4f6;transition:background .2s,transform .25s}
  .hf-pm::before,.hf-pm::after{content:"";position:absolute;left:50%;top:50%;width:12px;height:2px;margin:-1px 0 0 -6px;border-radius:2px;background:#374151;transition:transform .25s,background .2s}
  .hf-pm::after{transform:rotate(90deg)}
  .hf-item[open] .hf-pm{background:var(--gc-accent,#0c6878);transform:rotate(180deg)}
  .hf-item[open] .hf-pm::before,.hf-item[open] .hf-pm::after{background:#fff}.hf-item[open] .hf-pm::after{transform:rotate(0)}
  .hf-a{padding:0 18px 18px 64px;color:#4b5563;font-size:15px;line-height:1.7}
  .hf-a p{margin:0 0 .6em}.hf-a p:last-child{margin:0}.hf-a a{color:var(--gc-accent,#0c6878)}
  @media (max-width:900px){.hf{grid-template-columns:1fr;gap:20px;margin-top:34px}.hf-intro{position:static;padding:22px 20px}.hf .hf-h2{font-size:23px}}
  @media (max-width:520px){.hf-item summary{padding:14px;gap:11px;font-size:15px}.hf-num{width:28px;height:28px;font-size:12px}.hf-a{padding:0 14px 16px 53px;font-size:14.5px}.hf-help{flex-wrap:wrap}.hf-btn{margin-left:0}}
  [data-theme="dark"] .hf-intro{background:#151b23;border-color:#263040}[data-theme="dark"] .hf-item{background:#151b23;border-color:#263040}[data-theme="dark"] .hf-item summary,[data-theme="dark"] .hf .hf-h2,[data-theme="dark"] .hf-help b{color:#e5e7eb}[data-theme="dark"] .hf-a,[data-theme="dark"] .hf-sub,[data-theme="dark"] .hf-help span{color:#9ca3af}[data-theme="dark"] .hf-pm{background:#1f2733}
  </style>
  <div class="hf-intro">
    <span class="hf-badge"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 17h-2v-2h2zm2.1-7.7-.9.9A3.4 3.4 0 0 0 13 15h-2v-.5a4 4 0 0 1 1.2-2.8l1.2-1.3A2 2 0 1 0 10 9H8a4 4 0 1 1 7.1 2.3z"/></svg>FAQs</span>
    <h2 class="hf-h2" id="faq-heading">Frequently Asked Questions (FAQs)</h2>
    <p class="hf-sub">Quick answers about applying for government jobs, admit cards, exam dates and results.</p>
    <div class="hf-help"><span><b>Still have a question?</b>We are happy to help.</span><a class="hf-btn" href="/contact-us">Contact us</a></div>
  </div>
  <div class="hf-list">
    <?php foreach (array_values($faqs) as $i => $faq): ?>
    <details class="hf-item"<?= $i === 0 ? ' open' : '' ?>>
      <summary><span class="hf-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span><span class="hf-q"><?= htmlspecialchars($faq['question']) ?></span><span class="hf-pm" aria-hidden="true"></span></summary>
      <div class="hf-a"><?= $faq['answer_html'] ?></div>
    </details>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>
<!-- FAQ SECTION -->
<section class="faq" aria-labelledby="faq-heading">

    <div class="faq-header">
        <h2 id="faq-heading">
            Frequently Asked Questions (FAQs)
        </h2>
    </div>

    <?php foreach ($faqs as $faq): ?>

<details class="faq-item">
    
    <summary>
        <?= htmlspecialchars($faq['question']) ?>
    </summary>

    <article class="faq-content">
        <?= $faq['answer_html'] ?>
    </article>

</details>

<?php endforeach; ?>
</section>
<?php endif; ?>

<!-- FAQ SCHEMA MARKUP SECTION -->
<script type="application/ld+json">
<?= json_encode([

    '@context' => 'https://schema.org',

    '@type' => 'FAQPage',

    'mainEntity' => array_map(

        static fn ($faq) => [

            '@type' => 'Question',

            'name' => strip_tags($faq['question']),

            'acceptedAnswer' => [

                '@type' => 'Answer',

                'text' => trim(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        strip_tags($faq['answer_html'])
                    )
                ),

            ],

        ],

        $faqs

    ),

], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG) ?>
</script>