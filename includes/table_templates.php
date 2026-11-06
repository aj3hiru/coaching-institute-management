<?php
// Ready-made table templates for the post editor (Insert → Templates). Inline styles, so a post looks the same on
// every page and in feeds. After inserting, every cell, colour and button can be edited or deleted in the editor.
function edu_table_templates(): array
{
    $th = fn($bg, $c = '#fff') => 'style="background:' . $bg . ';color:' . $c . ';padding:10px 12px;text-align:left;border:1px solid ' . $bg . '"';
    $td = 'style="padding:10px 12px;border:1px solid #d9e2ec"';
    $tbl = 'style="width:100%;border-collapse:collapse;margin:0 0 18px"';
    $cap = fn($bg, $t) => '<tr><th colspan="__C__" style="background:' . $bg . ';color:#fff;padding:12px;text-align:center;font-size:18px;border:1px solid ' . $bg . '">' . $t . '</th></tr>';
    $btn = fn($bg, $t, $u = '#') => '<a href="' . $u . '" target="_blank" rel="noopener" style="display:inline-block;background:' . $bg . ';color:#fff;padding:9px 18px;border-radius:6px;text-decoration:none;font-weight:600">' . $t . '</a>';
    $wrapBtn = fn($html) => '<div style="display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin:0 0 18px">' . $html . '</div>';
    $t2 = fn($bg, $title, array $rows, $h1 = 'Event', $h2 = 'Date') => '<table ' . $tbl . '><thead>' . str_replace('__C__', '2', $cap($bg, $title)) . '<tr><th ' . $th($bg . 'cc') . '>' . $h1 . '</th><th ' . $th($bg . 'cc') . '>' . $h2 . '</th></tr></thead><tbody>'
        . implode('', array_map(fn($r) => '<tr><td ' . $td . '>' . $r[0] . '</td><td ' . $td . '>' . $r[1] . '</td></tr>', $rows)) . '</tbody></table>';
    // Job card table: each value cell is linked (data-jc) to the post editor's Job card fields — edit either side
    $jcRow = fn($label, $key) => '<tr><td ' . str_replace('padding', 'background:#f1f5f9;font-weight:600;width:40%;padding', $td) . '>' . $label . '</td><td data-jc="' . $key . '" ' . $td . '>—</td></tr>';
    $jobCard = '<table data-jobcard="1" ' . $tbl . '><tbody>' . str_replace('__C__', '2', $cap('#0c6878', 'Job Details'))
        . $jcRow('Post Name', 'job_post_name') . $jcRow('Department', 'job_org') . $jcRow('Location', 'job_location') . $jcRow('Salary', 'job_salary')
        . $jcRow('Total Vacancies', 'job_vacancies') . $jcRow('Qualification', 'job_qualification') . $jcRow('Last Date', 'last_date') . '</tbody></table>';
    return [
        ['id' => 'jobcard', 'name' => 'Job Details (linked to Job card)', 'html' => $jobCard],
        ['id' => 'b1', 'name' => 'Important Dates + Apply button', 'html' => $t2('#0c6878', 'Important Dates', [['Notification Released', '01 January 2026'], ['Online Application Starts', '05 January 2026'], ['Last Date to Apply', '31 January 2026'], ['Last Date for Fee Payment', '02 February 2026'], ['Admit Card', 'Before Exam'], ['Exam Date', 'March 2026']]) . $wrapBtn($btn('#0c6878', 'Apply Online'))],
        ['id' => 'b2', 'name' => 'Application Fee', 'html' => $t2('#ea580c', 'Application Fee', [['General / OBC / EWS', '₹ 500/-'], ['SC / ST', '₹ 250/-'], ['Female (All Categories)', '₹ 250/-'], ['Payment Mode', 'Debit Card, Credit Card, Net Banking, UPI']], 'Category', 'Fee')],
        ['id' => 'b3', 'name' => 'Vacancy Details (3 columns)', 'html' => '<table ' . $tbl . '><thead>' . str_replace('__C__', '3', $cap('#1d4ed8', 'Vacancy Details')) . '<tr><th ' . $th('#3b82f6') . '>Post Name</th><th ' . $th('#3b82f6') . '>Total Posts</th><th ' . $th('#3b82f6') . '>Eligibility</th></tr></thead><tbody>'
            . '<tr><td ' . $td . '>Post Name 1</td><td ' . $td . '>100</td><td ' . $td . '>10th Pass</td></tr><tr><td ' . $td . '>Post Name 2</td><td ' . $td . '>50</td><td ' . $td . '>12th Pass</td></tr><tr><td ' . $td . '><b>Total</b></td><td ' . $td . '><b>150</b></td><td ' . $td . '></td></tr></tbody></table>'],
        ['id' => 'b4', 'name' => 'Age Limit', 'html' => $t2('#15803d', 'Age Limit (as on 01/01/2026)', [['Minimum Age', '18 Years'], ['Maximum Age', '27 Years'], ['Age Relaxation', 'As per rules (SC/ST: 5 years, OBC: 3 years)']], 'Details', 'Age')],
        ['id' => 'b5', 'name' => 'Important Links (buttons)', 'html' => '<table ' . $tbl . '><thead>' . str_replace('__C__', '2', $cap('#7c3aed', 'Important Links')) . '</thead><tbody>'
            . '<tr><td ' . $td . '><b>Apply Online</b></td><td ' . $td . ' align="center">' . $btn('#16a34a', 'Click Here') . '</td></tr>'
            . '<tr><td ' . $td . '><b>Download Notification</b></td><td ' . $td . ' align="center">' . $btn('#dc2626', 'Click Here') . '</td></tr>'
            . '<tr><td ' . $td . '><b>Official Website</b></td><td ' . $td . ' align="center">' . $btn('#2563eb', 'Click Here') . '</td></tr></tbody></table>'],
        ['id' => 'b6', 'name' => 'Job Overview (key – value)', 'html' => '<table ' . $tbl . '><tbody>' . str_replace('__C__', '2', $cap('#0f172a', 'Recruitment Overview'))
            . implode('', array_map(fn($r) => '<tr><td ' . str_replace('padding', 'background:#f1f5f9;font-weight:600;width:40%;padding', $td) . '>' . $r[0] . '</td><td ' . $td . '>' . $r[1] . '</td></tr>', [['Organization', 'Organization name'], ['Post Name', 'Post name'], ['Total Vacancies', '000'], ['Job Location', 'All India'], ['Apply Mode', 'Online'], ['Official Website', 'www.example.gov.in']])) . '</tbody></table>'],
        ['id' => 'b7', 'name' => 'Exam Pattern (4 columns)', 'html' => '<table ' . $tbl . '><thead>' . str_replace('__C__', '4', $cap('#9333ea', 'Exam Pattern')) . '<tr><th ' . $th('#a855f7') . '>Subject</th><th ' . $th('#a855f7') . '>Questions</th><th ' . $th('#a855f7') . '>Marks</th><th ' . $th('#a855f7') . '>Duration</th></tr></thead><tbody>'
            . '<tr><td ' . $td . '>General Knowledge</td><td ' . $td . '>25</td><td ' . $td . '>50</td><td rowspan="4" ' . $td . '>60 Minutes</td></tr><tr><td ' . $td . '>Reasoning</td><td ' . $td . '>25</td><td ' . $td . '>50</td></tr><tr><td ' . $td . '>Mathematics</td><td ' . $td . '>25</td><td ' . $td . '>50</td></tr><tr><td ' . $td . '>English</td><td ' . $td . '>25</td><td ' . $td . '>50</td></tr><tr><td ' . $td . '><b>Total</b></td><td ' . $td . '><b>100</b></td><td ' . $td . '><b>200</b></td></tr></tbody></table>'],
        ['id' => 'b8', 'name' => 'Join WhatsApp / Telegram box', 'html' => '<table ' . $tbl . '><tbody><tr><td style="padding:16px;border:2px dashed #25d366;background:#f0fdf4;text-align:center"><p style="margin:0 0 10px;font-weight:700;font-size:17px">📢 Get the latest job updates first!</p>'
            . $btn('#25d366', 'Join WhatsApp Channel', 'https://whatsapp.com/channel/') . ' &nbsp; ' . $btn('#229ed9', 'Join Telegram', 'https://t.me/') . '</td></tr></tbody></table>'],
        ['id' => 'b9', 'name' => 'Stay Updated (CTA)', 'html' => '<table ' . $tbl . '><tbody><tr><td style="padding:18px;border:1px solid #fde68a;background:#fffbeb;text-align:center;border-radius:8px"><p style="margin:0 0 6px;font-weight:700;font-size:18px">🔔 Stay Updated</p><p style="margin:0 0 12px;color:#57534e">Follow us for daily Sarkari Naukri, Results and Admit Card updates.</p>'
            . $btn('#f59e0b', 'Follow on Google News', 'https://news.google.com/') . ' &nbsp; ' . $btn('#0c6878', 'Join Us') . '</td></tr></tbody></table>'],
        ['id' => 'b10', 'name' => 'Simple 3×3 table with header', 'html' => '<table ' . $tbl . '><thead><tr><th ' . $th('#334155') . '>Heading 1</th><th ' . $th('#334155') . '>Heading 2</th><th ' . $th('#334155') . '>Heading 3</th></tr></thead><tbody>'
            . str_repeat('<tr><td ' . $td . '>&nbsp;</td><td ' . $td . '>&nbsp;</td><td ' . $td . '>&nbsp;</td></tr>', 3) . '</tbody></table>'],
    ];
}
