/*
 * Institute PDF reports — the same plain business-document look as the traffic report (black / grey, ruled borders,
 * document number, two info boxes, bordered tables with a grey heading row, notes, sign-off, page numbers).
 * Any element with data-report="student|course|batch|class" data-id="N" (or data-report="students" data-src="URL")
 * downloads its report. Data comes from /admin/ci-report.php (or the given data-src).
 */
(function () {
    var INK = [20, 20, 20], TEXT = [45, 45, 45], MUTE = [110, 110, 110], RULE = [150, 150, 150], LIGHT = [205, 205, 205], HEAD = [236, 236, 236], ZEBRA = [248, 248, 248];
    function clean(t) {
        return String(t == null ? '' : t).replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/[–—]/g, '-').replace(/…/g, '...')
            .replace(/₹/g, 'Rs.').replace(/[^\x20-\x7E -ÿ]/g, '').replace(/\s+/g, ' ').trim();
    }
    function rs(n) { return 'Rs. ' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function nice(ymd) { if (!ymd) return '-'; var M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], p = String(ymd).split('-'); return ('0' + (+p[2])).slice(-2) + ' ' + M[+p[1] - 1] + ' ' + p[0]; }
    function cap(s) { s = clean(s); return s ? s.charAt(0).toUpperCase() + s.slice(1) : '-'; }

    /** students list (students.php?export=json) → the generic document */
    function fromStudents(d) {
        var t = { fee: 0, paid: 0, due: 0, active: 0, left: 0, Institute: 0, Online: 0, Both: 0 };
        var body = d.rows.map(function (r, i) {
            t.fee += r.fee; t.paid += r.paid; t.due += r.due; if (r.status === 'active') t.active++; if (r.status === 'left') t.left++; if (r.type && t[r.type] !== undefined) t[r.type]++;
            return [i + 1, r.reg, r.name, r.type || '-', r.mobile, r.class || '-', r.courses || '-', nice(r.admitted), cap(r.status), rs(r.fee), rs(r.paid), rs(r.due)];
        });
        var today = new Date(), no = 'SL-' + today.getFullYear() + ('0' + (today.getMonth() + 1)).slice(-2) + ('0' + today.getDate()).slice(-2) + '-' + d.rows.length;
        return {
            title: 'STUDENTS REPORT', subtitle: 'List of students with fee', no: no, file: 'students-report_' + today.toISOString().slice(0, 10), landscape: true,
            institute: d.institute, generated: d.generated, viewer: d.viewer,
            boxes: [{ head: 'REPORT FOR', rows: (d.filters.length ? d.filters : ['All students']).slice(0, 6).map(function (f) { var i = f.indexOf(': '); return i > 0 ? [f.slice(0, i), f.slice(i + 2)] : ['Filter', f]; }) },
                    { head: 'FIGURES', rows: [['Students', String(d.rows.length) + ' (Institute ' + t.Institute + ' · Online ' + t.Online + ' · Both ' + t.Both + ')'], ['Active', String(t.active)], ['Left', String(t.left)], ['Fee collected', rs(t.paid)], ['Fee due', rs(t.due)]] }],
            sections: [{ title: 'Students', right_text: d.rows.length + ' student(s)', head: ['No.', 'Reg. No.', 'Student', 'Type', 'Mobile', 'Class', 'Courses / batches', 'Admission', 'Status', 'Fee', 'Paid', 'Due'], body: body,
                foot: body.length ? [['', '', 'Total', '', '', '', '', '', '', rs(t.fee), rs(t.paid), rs(t.due)]] : null, right: [9, 10, 11], small: true, empty: 'No students match these filters.' }],
            notes: [d.rows.length + ' student(s) in this list: ' + t.active + ' active, ' + t.left + ' left · Institute ' + t.Institute + ', Online ' + t.Online + ', Both ' + t.Both + '.', 'Fee: ' + rs(t.fee) + ' in total, ' + rs(t.paid) + ' collected, ' + rs(t.due) + ' still due.']
        };
    }

    // K = how tight the layout is (1 = normal). ciReportPdf tries a tighter K when that saves a page (a report that
    // only spills a few rows / the sign-off onto page 2 then fits on one page); save = false only builds the document.
    function draw(d, K, save) {
        K = K || 1;
        var doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4', orientation: d.landscape ? 'landscape' : 'portrait', compress: true });
        var W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight(), M = 40, CW = W - 2 * M, y;
        var inst = d.institute || {};
        function font(style, size, color) { doc.setFont('helvetica', style); doc.setFontSize(size); doc.setTextColor.apply(doc, color || INK); }
        function rule(x1, y1, x2, y2, w, c) { doc.setDrawColor.apply(doc, c || RULE); doc.setLineWidth(w || 0.6); doc.line(x1, y1, x2, y2); }
        function need(h) { if (y + h > H - 54) { doc.addPage(); y = 60; } }
        function sectionTitle(t, right, rows) {
            need(rows ? (40 + 22 + Math.min(rows, 4) * 19) * K : 40 * K);
            font('bold', 10, INK); doc.text(clean(t).toUpperCase(), M, y);
            if (right) { font('normal', 8.5, MUTE); doc.text(clean(right), W - M, y, { align: 'right' }); }
            rule(M, y + 5, W - M, y + 5, 0.8, INK);
            y += 13;
        }

        // ---------- header: the site's brand (tinted panel with a notch and a red stripe, emblem, name, address) ----------
        var brand = inst.brand || {}, bTitle = clean(brand.title || inst.name) || 'Institute', bLine = clean(brand.line || inst.address);
        y = 34;
        var ph = 62, px = M, pw = Math.min(CW * 0.6, 330), logoS = ph - 12;
        doc.setFillColor(249, 223, 198);
        // panel: straight left, notch at 97% / 45% on the right (as on the website)
        doc.lines([[pw, 0], [-pw * 0.03, ph * 0.45], [pw * 0.03, ph * 0.55], [-pw, 0]], px, y, [1, 1], 'F', true);
        doc.setFillColor(240, 40, 1);
        doc.triangle(px + pw - 4, y + ph * 0.45, px + pw - 18, y + ph, px + pw - 8, y + ph, 'F');
        var tx = px + 12;
        if (d._logo) { doc.addImage(d._logo, 'PNG', px + 10, y + 6, logoS * 0.866, logoS); tx = px + 10 + logoS * 0.866 + 10; }
        font('bold', 17, [158, 27, 50]); doc.text(doc.splitTextToSize(bTitle, pw - (tx - px) - 30)[0], tx, y + ph / 2 - 3);
        if (bLine) { font('normal', 8.5, [26, 26, 26]); doc.text(doc.splitTextToSize(bLine, pw - (tx - px) - 30)[0], tx, y + ph / 2 + 11); }
        var contact = [clean(inst.phone), clean(inst.email)].filter(Boolean).join('  |  ');
        if (contact) { font('normal', 8, MUTE); doc.text(contact, px, y + ph + 13); }
        font('bold', 18, INK); doc.text(clean(d.title), W - M, y + 18, { align: 'right' });
        var meta = [['Report No.', clean(d.no)], ['Report Date', clean(d.generated)]];
        font('bold', 8.5, INK);
        var valW = Math.max.apply(null, meta.map(function (m) { return doc.getTextWidth(m[1]); }));
        meta.forEach(function (m, i) {
            var my = y + 34 + i * 12.5;
            font('normal', 8.5, MUTE); doc.text(m[0] + ':', W - M - valW - 10, my, { align: 'right' });
            font('bold', 8.5, INK); doc.text(m[1], W - M - valW, my);
        });
        y += ph + (contact ? 20 : 10 * K); // the phone / email line keeps its room above the rule
        rule(M, y, W - M, y, 1.4, INK);
        y += 18 * K;

        // ---------- two info boxes ----------
        var boxes = d.boxes || [];
        if (boxes.length) {
            var half = (CW - 16) / 2, top = y, n = Math.max.apply(null, boxes.map(function (b) { return b.rows.length; }));
            var RH = 14.5 * Math.max(K, 0.82), bh = 28 + n * RH + 6;
            boxes.slice(0, 2).forEach(function (b, bi) {
                var x = M + bi * (half + 16);
                doc.setDrawColor.apply(doc, RULE); doc.setLineWidth(0.6); doc.rect(x, top, half, bh, 'S');
                doc.setFillColor.apply(doc, HEAD); doc.rect(x + 0.3, top + 0.3, half - 0.6, 19.4, 'F'); rule(x, top + 20, x + half, top + 20, 0.6);
                font('bold', 8.5, INK); doc.text(clean(b.head), x + 9, top + 13.5);
                b.rows.forEach(function (r, i) {
                    var ry = top + 35 + i * RH;
                    font('normal', 8.6, MUTE); doc.text(clean(r[0]), x + 9, ry);
                    font('bold', 8.6, INK); doc.text(doc.splitTextToSize(clean(r[1]) || '-', half - 110)[0] || '-', x + 100, ry);
                });
            });
            y = top + bh + 24 * K;
        }

        // ---------- tables ----------
        (d.sections || []).forEach(function (s) {
            var body = (s.body || []).map(function (r) { return r.map(function (c) { return clean(c); }); });
            sectionTitle(s.title, s.right_text, body.length);
            if (!body.length) { font('italic', 9, MUTE); doc.text(clean(s.empty || 'Nothing to show.'), M, y + 8); y += 30 * K; return; }
            var right = s.right || [], cs = {};
            right.forEach(function (i) { cs[i] = { halign: 'right', minCellWidth: s.small ? 50 : 70 }; }); // money stays on one line
            Object.keys(s.w || {}).forEach(function (i) { cs[i] = Object.assign(cs[i] || {}, { cellWidth: s.w[i] }); });
            doc.autoTable({
                startY: y, margin: { left: M, right: M, top: 60, bottom: 54 }, head: [s.head.map(clean)], body: body, foot: s.foot ? s.foot.map(function (r) { return r.map(clean); }) : undefined,
                theme: 'grid', showHead: 'everyPage', showFoot: 'lastPage', rowPageBreak: 'avoid',
                styles: { font: 'helvetica', fontSize: Math.max(6.8, (s.small ? 7.8 : 8.6) * (K < 1 ? 0.94 : 1)), cellPadding: { top: 4 * K, right: 5, bottom: 4 * K, left: 5 }, textColor: TEXT, lineColor: LIGHT, lineWidth: 0.5, overflow: 'linebreak', valign: 'middle' },
                headStyles: { fillColor: HEAD, textColor: INK, fontStyle: 'bold', lineColor: RULE, lineWidth: 0.6 },
                footStyles: { fillColor: HEAD, textColor: INK, fontStyle: 'bold', lineColor: RULE, lineWidth: 0.6 },
                alternateRowStyles: { fillColor: ZEBRA }, tableLineColor: RULE, tableLineWidth: 0.6, columnStyles: cs,
                didParseCell: function (c) {
                    if ((c.section === 'head' || c.section === 'foot') && right.indexOf(c.column.index) >= 0) c.cell.styles.halign = 'right';
                    if (c.section === 'body' && /^(Left|Dropped)( \(|$)/.test(String(c.cell.raw))) { c.cell.styles.fontStyle = 'bold'; }
                }
            });
            y = doc.lastAutoTable.finalY + 22 * K;
        });

        // ---------- notes + sign-off ----------
        // normal: notes across the page, the sign-off under them; tighter (K < 1): notes on the left and the sign-off
        // beside them on the right, smaller lines — so a report that only spilled its notes / sign-off fits one page
        var notes = (d.notes || []).map(clean).filter(Boolean), side = K < 1 && !d.nosign, SW = 190;
        var nfs = K < 1 ? 8.2 : 8.8, nlh = K < 1 ? 10.5 : 12;
        if (notes.length) {
            var nw = side ? CW - SW - 16 : CW;
            font('normal', nfs, TEXT);
            var wrapped = notes.map(function (t, i) { return doc.splitTextToSize((i + 1) + '.  ' + t, nw - 20); });
            var nh = (K < 1 ? 10 : 14) + wrapped.reduce(function (a, w) { return a + w.length * nlh + 3; }, 0);
            need(13 + nh + (d.nosign || side ? 6 : 50));
            sectionTitle('Notes');
            doc.setDrawColor.apply(doc, RULE); doc.setLineWidth(0.6); doc.rect(M, y, nw, nh, 'S');
            var ty = y + (K < 1 ? 12 : 15);
            wrapped.forEach(function (w) { font('normal', nfs, TEXT); doc.text(w, M + 10, ty); ty += w.length * nlh + 3; });
            if (side) { // the sign-off beside the notes, level with their bottom
                rule(W - M - 170, y + nh - 12, W - M, y + nh - 12, 0.6, INK);
                font('normal', 8.5, MUTE); doc.text('Authorised Signatory', W - M - 85, y + nh, { align: 'center' });
            }
            y += nh + 30 * K;
        } else { need(d.nosign ? 0 : 50); y += 10 * K; }
        if (!d.nosign && !(side && notes.length)) { // the sign-off line (can be left out on the Reports page)
            rule(W - M - 170, y + 14, W - M, y + 14, 0.6, INK);
            font('normal', 8.5, MUTE); doc.text('Authorised Signatory', W - M - 85, y + 27, { align: 'center' });
        }

        // ---------- running header + footer ----------
        var pages = doc.internal.getNumberOfPages();
        for (var pg = 1; pg <= pages; pg++) {
            doc.setPage(pg);
            if (pg > 1) {
                font('bold', 9, INK); doc.text(bTitle, M, 36);
                font('normal', 8.5, MUTE); doc.text(cap(clean(d.title).toLowerCase()) + ' ' + clean(d.no), W - M, 36, { align: 'right' });
                rule(M, 44, W - M, 44, 0.8, INK);
            }
            rule(M, H - 42, W - M, H - 42, 0.6, RULE);
            font('normal', 7.6, MUTE);
            doc.text('This is a system-generated report from ' + bTitle + '.', M, H - 28);
            doc.text('Page ' + pg + ' of ' + pages, W - M, H - 28, { align: 'right' });
        }
        var slug = clean(d.file || 'report').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'report';
        if (save !== false) doc.save(slug + '.pdf');
        return doc;
    }
    /** Builds the report with the fewest pages: normal spacing first; when it runs onto more pages, a tighter
        spacing is tried and kept only if it saves a page (most reports that just spill over then fit on one page). */
    function best(d) {
        var pages = function (doc) { return doc.internal.getNumberOfPages(); };
        var doc = draw(d, 1, false), n = pages(doc);
        if (n > 1) [0.86, 0.74, 0.64].some(function (k) { var t = draw(d, k, false), tn = pages(t); if (tn < n) { doc = t; n = tn; } return n === 1; });
        var slug = clean(d.file || 'report').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'report';
        doc.save(slug + '.pdf');
        return n;
    }
    window.ciReportDraw = draw;
    var logoCache = {};
    /** The brand emblem as a PNG data URL (jsPDF cannot draw SVG). Resolves to null when it cannot be loaded. */
    function logo(url) {
        if (!url) return Promise.resolve(null);
        if (logoCache[url]) return logoCache[url];
        return (logoCache[url] = new Promise(function (res) {
            var im = new Image();
            im.onload = function () { try { var w = 300, h = Math.round(300 * (im.naturalHeight || 232) / (im.naturalWidth || 200)), c = document.createElement('canvas'); c.width = w; c.height = h; c.getContext('2d').drawImage(im, 0, 0, w, h); res(c.toDataURL('image/png')); } catch (e) { res(null); } };
            im.onerror = function () { res(null); };
            im.src = url;
        }));
    }
    /** Draws and downloads the PDF once the emblem is ready. */
    window.ciReportPdf = function (d) {
        return logo(d.institute && d.institute.brand && d.institute.brand.emblem).then(function (img) { d._logo = img; return best(d); });
    };
    window.ciReportFromStudents = fromStudents;

    /** The same document as HTML, to read the report on the page before downloading it. */
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    window.ciReportHtml = function (d) {
        var inst = d.institute || {}, h = '<div class="rp-doc">';
        var br = inst.brand || {};
        h += '<div class="rp-top"><div><div class="rp-brand">' + (br.emblem ? '<img src="' + esc(br.emblem) + '" alt="">' : '') + '<div><b>' + esc(br.title || inst.name || 'Institute') + '</b>' + (br.line || inst.address ? '<span>' + esc(br.line || inst.address) + '</span>' : '') + '</div></div><p>' + esc([inst.phone, inst.email].filter(Boolean).join('  |  ')) + '</p></div>';
        h += '<div class="rp-meta"><h2>' + esc(d.title) + '</h2><table><tr><td>Report No.:</td><td>' + esc(d.no) + '</td></tr><tr><td>Report Date:</td><td>' + esc(d.generated) + '</td></tr></table></div></div>';
        if ((d.boxes || []).length) {
            h += '<div class="rp-boxes">' + d.boxes.slice(0, 2).map(function (b) { return '<div class="rp-box"><h3>' + esc(b.head) + '</h3><table>' + b.rows.map(function (r) { return '<tr><td>' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>'; }).join('') + '</table></div>'; }).join('') + '</div>';
        }
        (d.sections || []).forEach(function (sct) {
            var right = sct.right || [];
            h += '<div class="rp-sec"><div class="rp-st"><b>' + esc(sct.title) + '</b>' + (sct.right_text ? '<span>' + esc(sct.right_text) + '</span>' : '') + '</div>';
            if (!(sct.body || []).length) { h += '<p class="rp-empty">' + esc(sct.empty || 'Nothing to show.') + '</p></div>'; return; }
            var cell = function (tag, v, i) { return '<' + tag + (right.indexOf(i) >= 0 ? ' class="r"' : '') + '>' + esc(v) + '</' + tag + '>'; };
            h += '<div class="rp-tw"><table class="rp-t"><thead><tr>' + sct.head.map(function (v, i) { return cell('th', v, i); }).join('') + '</tr></thead><tbody>';
            h += sct.body.map(function (r) { return '<tr' + (r.some(function (c) { return /^(Left|Dropped)/.test(String(c)); }) ? ' class="rp-left"' : '') + '>' + r.map(function (v, i) { return cell('td', v, i); }).join('') + '</tr>'; }).join('');
            h += '</tbody>' + (sct.foot ? '<tfoot>' + sct.foot.map(function (r) { return '<tr>' + r.map(function (v, i) { return cell('td', v, i); }).join('') + '</tr>'; }).join('') + '</tfoot>' : '') + '</table></div></div>';
        });
        if ((d.notes || []).length) h += '<div class="rp-sec"><div class="rp-st"><b>Notes</b></div><ol class="rp-notes">' + d.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ol></div>';
        if (!d.nosign) h += '<div class="rp-sign"><span>Authorised Signatory</span></div>';
        return h + '</div>';
    };

    /** A copy of a report with only the chosen parts: sel = {boxes: {i: false}, sec: {i: false}, cols: {i: {j: false}},
        notes: false, sign: false} (missing = shown). The header is always kept. Used by the Reports page for the
        preview and the download, so both show exactly the same. */
    window.ciReportPick = function (d, sel) {
        sel = sel || {}; var on = function (o, k) { return !o || o[k] !== false; };
        var out = Object.assign({}, d);
        out.boxes = (d.boxes || []).filter(function (b, i) { return on(sel.boxes, i); });
        out.sections = [];
        (d.sections || []).forEach(function (s, i) {
            if (!on(sel.sec, i)) return;
            var keep = s.head.map(function (h, j) { return on((sel.cols || {})[i], j); });
            if (keep.indexOf(true) < 0) return; // no column left = no table
            var map = []; keep.forEach(function (k, j) { if (k) map.push(j); });
            var cut = function (r) { return map.map(function (j) { return r[j]; }); };
            var w = {}; Object.keys(s.w || {}).forEach(function (j) { var n = map.indexOf(+j); if (n >= 0) w[n] = s.w[j]; });
            out.sections.push(Object.assign({}, s, { head: cut(s.head), body: (s.body || []).map(cut), foot: s.foot ? s.foot.map(cut) : s.foot,
                right: (s.right || []).map(function (j) { return map.indexOf(j); }).filter(function (n) { return n >= 0; }), w: w }));
        });
        out.notes = sel.notes === false ? [] : (d.notes || []);
        out.nosign = sel.sign === false;
        return out;
    };

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-report]');
        if (!b) return;
        e.preventDefault();
        if (!window.jspdf || !window.jspdf.jsPDF) { alert('The PDF tool is still loading, please try again in a moment.'); return; }
        var kind = b.dataset.report, url = b.dataset.src || ('/admin/ci-report.php?type=' + encodeURIComponent(kind) + '&id=' + encodeURIComponent(b.dataset.id || ''));
        b.disabled = true; b.classList.add('is-busy');
        fetch(url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) { if (d.error) throw new Error(d.error); return window.ciReportPdf(d.kind === 'students' ? fromStudents(d) : d); })
            .catch(function (err) { alert('Could not make the report: ' + err.message); })
            .then(function () { b.disabled = false; b.classList.remove('is-busy'); });
    });
})();
