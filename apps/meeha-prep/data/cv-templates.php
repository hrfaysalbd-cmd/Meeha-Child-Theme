<?php
/**
 * The ten pre-built CV templates (3 free one-page, 7 paid multi-page). Each is the same package format the
 * admin importer takes (see docs in the setup guide): key, name, desc, price, pages, order, html, css.
 *
 * Template language (a small Mustache subset, rendered the same way in PHP and in the editor's JavaScript):
 *   {{field}}            escaped text (dot paths allowed: {{L.experience}}, {{personal.father}})
 *   {{#list}}…{{/list}}  repeat for each row of a list, or show once when a value is filled in
 *   {{^field}}…{{/field}} show when empty
 * Every value is HTML-escaped; there is no raw output. The document root must be <div class="cv">…</div>
 * and all CSS should start with `.cv` (the page is rendered on its own, A4 wide: 210mm).
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

/*
 * Building blocks shared by the bundled designs (imported templates are free-form HTML).
 */
$mhp_b = array();

$mhp_b['photo'] = '{{#photo}}<img class="cv-photo" src="{{photo}}" alt="">{{/photo}}';

$mhp_b['contact'] = '<ul class="cv-contact">{{#phone}}<li><span class="cv-k">{{L.phone}}</span> {{phone}}</li>{{/phone}}{{#email}}<li><span class="cv-k">{{L.email}}</span> {{email}}</li>{{/email}}{{#address}}<li><span class="cv-k">{{L.address}}</span> {{address}}</li>{{/address}}{{#website}}<li><span class="cv-k">{{L.website}}</span> {{website}}</li>{{/website}}</ul>';

$mhp_b['id'] = '<h1 class="cv-name">{{name}}{{^name}}{{L.your_name}}{{/name}}</h1>{{#headline}}<p class="cv-headline">{{headline}}</p>{{/headline}}';

$mhp_b['summary'] = '{{#summary}}<section class="cv-sec cv-sec--summary"><h2>{{L.summary}}</h2><p class="cv-text">{{summary}}</p></section>{{/summary}}';

$mhp_b['experience'] = '{{#has_experience}}<section class="cv-sec"><h2>{{L.experience}}</h2>{{#experience}}<div class="cv-item"><div class="cv-item__head"><b class="cv-item__t">{{role}}</b><span class="cv-date">{{from}}{{#to}} – {{to}}{{/to}}</span></div>{{#org}}<div class="cv-item__sub">{{org}}{{#place}}, {{place}}{{/place}}</div>{{/org}}{{#details}}<p class="cv-text">{{details}}</p>{{/details}}</div>{{/experience}}</section>{{/has_experience}}';

$mhp_b['education'] = '{{#has_education}}<section class="cv-sec"><h2>{{L.education}}</h2>{{#education}}<div class="cv-item"><div class="cv-item__head"><b class="cv-item__t">{{degree}}</b><span class="cv-date">{{year}}</span></div><div class="cv-item__sub">{{inst}}{{#result}} · {{L.result}}: {{result}}{{/result}}</div></div>{{/education}}</section>{{/has_education}}';

$mhp_b['edu_table'] = '{{#has_education}}<section class="cv-sec"><h2>{{L.education}}</h2><table class="cv-table"><thead><tr><th>{{L.degree}}</th><th>{{L.institution}}</th><th>{{L.year}}</th><th>{{L.result}}</th></tr></thead><tbody>{{#education}}<tr><td>{{degree}}</td><td>{{inst}}</td><td>{{year}}</td><td>{{result}}</td></tr>{{/education}}</tbody></table></section>{{/has_education}}';

$mhp_b['skills'] = '{{#has_skills}}<section class="cv-sec"><h2>{{L.skills}}</h2><ul class="cv-tags">{{#skills}}<li>{{name}}</li>{{/skills}}</ul></section>{{/has_skills}}';

$mhp_b['languages'] = '{{#has_languages}}<section class="cv-sec"><h2>{{L.languages}}</h2><ul class="cv-list">{{#languages}}<li><b>{{name}}</b>{{#level}} — {{level}}{{/level}}</li>{{/languages}}</ul></section>{{/has_languages}}';

$mhp_b['training'] = '{{#has_training}}<section class="cv-sec"><h2>{{L.training}}</h2>{{#training}}<div class="cv-item"><div class="cv-item__head"><b class="cv-item__t">{{name}}</b><span class="cv-date">{{year}}</span></div>{{#org}}<div class="cv-item__sub">{{org}}</div>{{/org}}</div>{{/training}}</section>{{/has_training}}';

$mhp_b['achievements'] = '{{#has_achievements}}<section class="cv-sec"><h2>{{L.achievements}}</h2><ul class="cv-list">{{#achievements}}<li>{{text}}</li>{{/achievements}}</ul></section>{{/has_achievements}}';

$mhp_b['personal'] = '{{#has_personal}}<section class="cv-sec"><h2>{{L.personal}}</h2><table class="cv-kv">{{#personal.father}}<tr><th>{{L.father}}</th><td>{{personal.father}}</td></tr>{{/personal.father}}{{#personal.mother}}<tr><th>{{L.mother}}</th><td>{{personal.mother}}</td></tr>{{/personal.mother}}{{#personal.dob}}<tr><th>{{L.dob}}</th><td>{{personal.dob}}</td></tr>{{/personal.dob}}{{#personal.nationality}}<tr><th>{{L.nationality}}</th><td>{{personal.nationality}}</td></tr>{{/personal.nationality}}{{#personal.religion}}<tr><th>{{L.religion}}</th><td>{{personal.religion}}</td></tr>{{/personal.religion}}{{#personal.marital}}<tr><th>{{L.marital}}</th><td>{{personal.marital}}</td></tr>{{/personal.marital}}{{#personal.permanent}}<tr><th>{{L.permanent}}</th><td>{{personal.permanent}}</td></tr>{{/personal.permanent}}</table></section>{{/has_personal}}';

$mhp_b['references'] = '{{#has_references}}<section class="cv-sec"><h2>{{L.references}}</h2><div class="cv-refs">{{#references}}<div class="cv-ref"><b>{{name}}</b>{{#role}}<div>{{role}}</div>{{/role}}{{#contact}}<div class="cv-muted">{{contact}}</div>{{/contact}}</div>{{/references}}</div></section>{{/has_references}}';

$mhp_b['declaration'] = '{{#declaration}}<section class="cv-sec cv-sec--decl"><h2>{{L.declaration}}</h2><p class="cv-text">{{declaration}}</p><div class="cv-sign"><span>{{name}}</span><span>{{L.signature}}</span></div></section>{{/declaration}}';

/*
 * Shared base styles: A4 page, Bangla-friendly type, print rules. Each design adds its own on top.
 */
$mhp_base = '.cv{box-sizing:border-box;width:210mm;min-height:297mm;margin:0 auto;background:#fff;color:#1d232b;font:10.5pt/1.55 "Meeha Sans","Noto Sans Bengali","Hind Siliguri",system-ui,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
	. '.cv *{box-sizing:border-box}.cv h1,.cv h2,.cv h3,.cv p,.cv ul,.cv table{margin:0}.cv ul{padding:0;list-style:none}'
	. '.cv-text{white-space:pre-line}.cv-muted{color:#6b7480}.cv-photo{display:block;object-fit:cover}'
	. '.cv-sec{margin-top:5mm}.cv-sec>h2{break-after:avoid}.cv-item{break-inside:avoid;margin-top:2.4mm}.cv-item__head{display:flex;justify-content:space-between;gap:4mm;align-items:baseline}'
	. '.cv-date{flex:none;font-size:9pt;color:#6b7480;white-space:nowrap}.cv-item__sub{font-size:9.5pt;color:#4a535e}'
	. '.cv-tags{display:flex;flex-wrap:wrap;gap:1.6mm}.cv-tags li{padding:.6mm 2.6mm;border-radius:99px;background:#eef2f6;font-size:9pt}'
	. '.cv-list li{position:relative;padding-left:4mm;margin-top:1mm}.cv-list li::before{content:"";position:absolute;left:0;top:.62em;width:1.6mm;height:1.6mm;border-radius:50%;background:currentColor;opacity:.45}'
	. '.cv-kv{border-collapse:collapse;font-size:9.5pt}.cv-kv th{width:42mm;padding:.8mm 3mm .8mm 0;text-align:left;font-weight:600;color:#4a535e;vertical-align:top}.cv-kv td{padding:.8mm 0}'
	. '.cv-table{width:100%;border-collapse:collapse;font-size:9.5pt}.cv-table th,.cv-table td{padding:1.4mm 2mm;border:1px solid #d5dbe2;text-align:left}.cv-table th{background:#f2f5f8;font-weight:600}'
	. '.cv-refs{display:grid;grid-template-columns:1fr 1fr;gap:4mm;font-size:9.5pt}.cv-sign{display:flex;justify-content:space-between;margin-top:12mm;font-size:9.5pt}.cv-sign span:last-child{border-top:1px solid #8a939e;padding-top:1mm;min-width:45mm;text-align:center}'
	. '.cv-contact{font-size:9.5pt}.cv-k{font-weight:600}';

$mhp_main = $mhp_b['summary'] . $mhp_b['experience'] . $mhp_b['education'] . $mhp_b['training'] . $mhp_b['achievements'];
$mhp_side = $mhp_b['skills'] . $mhp_b['languages'];
$mhp_tail = $mhp_b['personal'] . $mhp_b['references'] . $mhp_b['declaration'];

return array(

	/* ---------------- Free (one page) ---------------- */

	array(
		'key'   => 'saral',
		'name'  => 'সরল',
		'desc'  => 'এক কলামের পরিষ্কার সিভি; যেকোনো চাকরির আবেদনে চলে।',
		'price' => 0,
		'pages' => 1,
		'order' => 1,
		'html'  => '<div class="cv cv-saral"><header class="cv-head">' . $mhp_b['photo'] . '<div class="cv-headtext">' . $mhp_b['id'] . $mhp_b['contact'] . '</div></header>' . $mhp_main . $mhp_side . $mhp_tail . '</div>',
		'css'   => $mhp_base . '.cv-saral{padding:16mm 17mm}.cv-saral .cv-head{display:flex;gap:6mm;align-items:center;padding-bottom:5mm;border-bottom:2px solid #1d232b}.cv-saral .cv-photo{width:28mm;height:34mm;border-radius:2mm}'
			. '.cv-saral .cv-name{font-size:22pt;line-height:1.2;font-weight:700}.cv-saral .cv-headline{font-size:11pt;color:#4a535e;margin-top:1mm}.cv-saral .cv-contact{display:flex;flex-wrap:wrap;gap:1mm 5mm;margin-top:2.5mm}'
			. '.cv-saral h2{font-size:11.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding-bottom:1mm;border-bottom:1px solid #d5dbe2;margin-bottom:1.5mm}.cv-saral .cv-item__t{font-size:10.5pt}',
	),

	array(
		'key'   => 'porishkar',
		'name'  => 'পরিচ্ছন্ন',
		'desc'  => 'বড় নাম, হালকা নীল রেখা আর প্রচুর সাদা জায়গা।',
		'price' => 0,
		'pages' => 1,
		'order' => 2,
		'html'  => '<div class="cv cv-pc"><header class="cv-head">' . $mhp_b['id'] . $mhp_b['contact'] . '</header>' . $mhp_main . $mhp_side . $mhp_tail . '</div>',
		'css'   => $mhp_base . '.cv-pc{padding:18mm 20mm}.cv-pc .cv-head{text-align:center;margin-bottom:3mm}.cv-pc .cv-name{font-size:24pt;font-weight:700;color:#025ab2;letter-spacing:.01em}'
			. '.cv-pc .cv-headline{font-size:11pt;margin-top:1mm}.cv-pc .cv-contact{display:flex;justify-content:center;flex-wrap:wrap;gap:1mm 6mm;margin-top:2.5mm;color:#4a535e}.cv-pc .cv-k{display:none}'
			. '.cv-pc .cv-sec{display:grid;grid-template-columns:36mm 1fr;column-gap:5mm;row-gap:0;padding-top:4mm;border-top:1px solid #dbe6f3}.cv-pc h2{font-size:10pt;font-weight:700;color:#025ab2;text-transform:uppercase;letter-spacing:.06em}'
			. '.cv-pc .cv-sec > h2 + *{margin-top:0}.cv-pc .cv-item:first-of-type{margin-top:0}.cv-pc .cv-sec>:not(h2){grid-column:2}.cv-pc .cv-sec>h2{grid-column:1}.cv-pc .cv-sec>h2{grid-row:1/span 30}',
	),

	array(
		'key'   => 'prothagoto',
		'name'  => 'প্রথাগত (সরকারি চাকরি)',
		'desc'  => 'বাংলাদেশে প্রচলিত ধাঁচ: ছবি ডানে, শিক্ষার তালিকা ছকে, ব্যক্তিগত তথ্য ও ঘোষণা।',
		'price' => 0,
		'pages' => 1,
		'order' => 3,
		'html'  => '<div class="cv cv-pt"><header class="cv-head"><div class="cv-headtext"><p class="cv-label">{{L.cv_title}}</p>' . $mhp_b['id'] . $mhp_b['contact'] . '</div>' . $mhp_b['photo'] . '</header>' . $mhp_b['summary'] . $mhp_b['edu_table'] . $mhp_b['experience'] . $mhp_b['training'] . $mhp_side . $mhp_b['achievements'] . $mhp_tail . '</div>',
		'css'   => $mhp_base . '.cv-pt{padding:14mm 16mm}.cv-pt .cv-head{display:flex;justify-content:space-between;gap:6mm;align-items:flex-start;padding-bottom:4mm;border-bottom:3px double #1d232b}'
			. '.cv-pt .cv-label{font-size:9pt;font-weight:700;letter-spacing:.2em;color:#6b7480}.cv-pt .cv-name{font-size:20pt;font-weight:700}.cv-pt .cv-contact li{margin-top:.6mm}'
			. '.cv-pt .cv-photo{width:30mm;height:36mm;border:1px solid #8a939e;padding:.8mm}.cv-pt h2{display:inline-block;font-size:10.5pt;font-weight:700;padding:.8mm 3mm;background:#1d232b;color:#fff;margin-bottom:2mm}',
	),

	/* ---------------- Paid (multi-page) ---------------- */

	array(
		'key'   => 'adhunik-nil',
		'name'  => 'আধুনিক নীল',
		'desc'  => 'বাঁয়ে নীল সাইডবার (ছবি, যোগাযোগ, দক্ষতা), ডানে অভিজ্ঞতা ও শিক্ষা।',
		'price' => 79,
		'pages' => 2,
		'order' => 4,
		'html'  => '<div class="cv cv-an"><aside class="cv-side">' . $mhp_b['photo'] . $mhp_b['contact'] . $mhp_side . $mhp_b['personal'] . '</aside><main class="cv-main">' . $mhp_b['id'] . $mhp_main . $mhp_b['references'] . $mhp_b['declaration'] . '</main></div>',
		'css'   => $mhp_base . '.cv-an{display:grid;grid-template-columns:66mm 1fr;background:linear-gradient(90deg,#0b3d75 66mm,#fff 66mm)}.cv-an .cv-side{padding:14mm 7mm;color:#e8f0fa}'
			. '.cv-an .cv-photo{width:40mm;height:40mm;border-radius:50%;margin:0 auto 6mm;border:3px solid #6aaef5}.cv-an .cv-side h2{font-size:10pt;letter-spacing:.08em;text-transform:uppercase;color:#9cc9f8;margin-bottom:2mm}'
			. '.cv-an .cv-contact li{margin-top:1.6mm;word-break:break-word}.cv-an .cv-k{display:block;font-size:8pt;color:#9cc9f8;font-weight:600}.cv-an .cv-tags li{background:rgba(255,255,255,.14);color:#fff}'
			. '.cv-an .cv-kv th{color:#9cc9f8;width:auto;display:block;padding:0}.cv-an .cv-kv td{display:block;padding:0 0 1.5mm}.cv-an .cv-kv tr{display:block}'
			. '.cv-an .cv-main{padding:14mm 12mm}.cv-an .cv-name{font-size:24pt;font-weight:700;color:#0b3d75;line-height:1.15}.cv-an .cv-headline{font-size:11.5pt;color:#025ab2;margin-top:1mm}'
			. '.cv-an .cv-main h2{font-size:12pt;color:#0b3d75;padding-bottom:1mm;border-bottom:2px solid #6aaef5;margin-bottom:1mm}',
	),

	array(
		'key'   => 'corporate',
		'name'  => 'কর্পোরেট',
		'desc'  => 'গাঢ় ছাইরঙা মাথা, সোনালি রেখা; ব্যাংক ও কর্পোরেট চাকরির জন্য।',
		'price' => 99,
		'pages' => 2,
		'order' => 5,
		'html'  => '<div class="cv cv-cp"><header class="cv-head">' . $mhp_b['photo'] . '<div class="cv-headtext">' . $mhp_b['id'] . $mhp_b['contact'] . '</div></header><div class="cv-body">' . $mhp_main . '<div class="cv-cols">' . $mhp_side . '</div>' . $mhp_tail . '</div></div>',
		'css'   => $mhp_base . '.cv-cp .cv-head{display:flex;gap:7mm;align-items:center;padding:12mm 16mm;background:#24292f;color:#fff;border-bottom:2mm solid #c9a227}'
			. '.cv-cp .cv-photo{width:30mm;height:30mm;border-radius:2mm;border:2px solid #c9a227}.cv-cp .cv-name{font-size:23pt;font-weight:700}.cv-cp .cv-headline{color:#e7d48f;font-size:11pt}'
			. '.cv-cp .cv-contact{display:grid;grid-template-columns:1fr 1fr;gap:.6mm 6mm;margin-top:2.5mm;color:#d7dbe0}.cv-cp .cv-body{padding:6mm 16mm 14mm}'
			. '.cv-cp h2{font-size:11.5pt;font-weight:700;color:#24292f;display:flex;align-items:center;gap:3mm;margin-bottom:1mm}.cv-cp h2::after{content:"";flex:1;height:1px;background:#c9a227}'
			. '.cv-cp .cv-cols{display:grid;grid-template-columns:1fr 1fr;gap:6mm}.cv-cp .cv-item__t{color:#24292f}',
	),

	array(
		'key'   => 'timeline',
		'name'  => 'টাইমলাইন',
		'desc'  => 'অভিজ্ঞতা ও শিক্ষা সময়রেখায় সাজানো; কাজের ধারাবাহিকতা চোখে পড়ে।',
		'price' => 99,
		'pages' => 3,
		'order' => 6,
		'html'  => '<div class="cv cv-tl"><header class="cv-head">' . $mhp_b['photo'] . '<div>' . $mhp_b['id'] . $mhp_b['contact'] . '</div></header>' . $mhp_main . $mhp_side . $mhp_tail . '</div>',
		'css'   => $mhp_base . '.cv-tl{padding:15mm 16mm}.cv-tl .cv-head{display:flex;gap:6mm;align-items:center}.cv-tl .cv-photo{width:30mm;height:30mm;border-radius:50%}'
			. '.cv-tl .cv-name{font-size:23pt;font-weight:700;color:#0f7b5f}.cv-tl .cv-contact{display:flex;flex-wrap:wrap;gap:.6mm 5mm;margin-top:2mm}.cv-tl h2{font-size:12pt;color:#0f7b5f;margin-bottom:2mm}'
			. '.cv-tl .cv-item{position:relative;margin:0;padding:0 0 3mm 7mm;border-left:2px solid #bfe3d8}.cv-tl .cv-item::before{content:"";position:absolute;left:-1.9mm;top:1.2mm;width:3.2mm;height:3.2mm;border-radius:50%;background:#0f7b5f;border:2px solid #fff}'
			. '.cv-tl .cv-item__head{flex-direction:column;align-items:flex-start;gap:0}.cv-tl .cv-date{order:-1;font-weight:600;color:#0f7b5f}.cv-tl .cv-tags li{background:#e3f4ee}',
	),

	array(
		'key'   => 'nirbahi',
		'name'  => 'নির্বাহী',
		'desc'  => 'অভিজ্ঞ পেশাজীবীর জন্য: সারাংশ বড় করে, দক্ষতা ডান কলামে।',
		'price' => 149,
		'pages' => 3,
		'order' => 7,
		'html'  => '<div class="cv cv-nb"><header class="cv-head">' . $mhp_b['id'] . $mhp_b['contact'] . '</header><div class="cv-grid"><main>' . $mhp_main . $mhp_b['references'] . '</main><aside>' . $mhp_b['photo'] . $mhp_side . $mhp_b['personal'] . '</aside></div>' . $mhp_b['declaration'] . '</div>',
		'css'   => $mhp_base . '.cv-nb{padding:16mm 16mm}.cv-nb .cv-head{padding-bottom:5mm;border-bottom:1px solid #1d232b}.cv-nb .cv-name{font-family:"Meeha Serif","Noto Serif Bengali",Georgia,serif;font-size:26pt;font-weight:700;letter-spacing:.01em}'
			. '.cv-nb .cv-headline{font-size:12pt;color:#7a2e3b;margin-top:1mm}.cv-nb .cv-contact{display:flex;flex-wrap:wrap;gap:.6mm 6mm;margin-top:2.5mm}.cv-nb .cv-grid{display:grid;grid-template-columns:1fr 52mm;gap:8mm}'
			. '.cv-nb aside{padding-top:5mm}.cv-nb .cv-photo{width:52mm;height:58mm;margin-bottom:2mm}.cv-nb h2{font-family:"Meeha Serif","Noto Serif Bengali",Georgia,serif;font-size:12.5pt;color:#7a2e3b;margin-bottom:1mm}'
			. '.cv-nb .cv-sec--summary .cv-text{font-size:11pt;line-height:1.65}.cv-nb aside .cv-kv th{display:block;width:auto;padding:0}.cv-nb aside .cv-kv td{display:block;padding:0 0 1.4mm}.cv-nb aside .cv-kv tr{display:block}',
	),

	array(
		'key'   => 'srijonshil',
		'name'  => 'সৃজনশীল',
		'desc'  => 'রঙিন মাথা ও কার্ড-ধাঁচের অংশ; মিডিয়া, ডিজাইন, মার্কেটিংয়ের জন্য।',
		'price' => 79,
		'pages' => 2,
		'order' => 8,
		'html'  => '<div class="cv cv-sj"><header class="cv-head">' . $mhp_b['photo'] . '<div>' . $mhp_b['id'] . '</div></header><div class="cv-strip">' . $mhp_b['contact'] . '</div><div class="cv-body">' . $mhp_main . $mhp_side . $mhp_tail . '</div></div>',
		'css'   => $mhp_base . '.cv-sj .cv-head{display:flex;gap:7mm;align-items:center;padding:12mm 15mm 10mm;background:linear-gradient(120deg,#b23a48,#6b3fa0);color:#fff}'
			. '.cv-sj .cv-photo{width:34mm;height:34mm;border-radius:6mm;transform:rotate(-3deg);border:2px solid #fff}.cv-sj .cv-name{font-size:25pt;font-weight:700}.cv-sj .cv-headline{font-size:12pt;opacity:.9}'
			. '.cv-sj .cv-strip{padding:3mm 15mm;background:#f6eef8}.cv-sj .cv-contact{display:flex;flex-wrap:wrap;gap:.6mm 6mm}.cv-sj .cv-body{padding:2mm 15mm 14mm}'
			. '.cv-sj h2{font-size:12pt;color:#6b3fa0;margin-bottom:1mm}.cv-sj h2::before{content:"";display:inline-block;width:3mm;height:3mm;margin-right:2mm;border-radius:1mm;background:#b23a48}.cv-sj .cv-tags li{background:#f6eef8;color:#6b3fa0}',
	),

	array(
		'key'   => 'academic',
		'name'  => 'একাডেমিক',
		'desc'  => 'শিক্ষক, গবেষক ও উচ্চশিক্ষার আবেদনের জন্য: শিক্ষা আগে, প্রশিক্ষণ-অর্জন বিস্তারিত।',
		'price' => 99,
		'pages' => 3,
		'order' => 9,
		'html'  => '<div class="cv cv-ac"><header class="cv-head">' . $mhp_b['id'] . $mhp_b['contact'] . '</header>' . $mhp_b['summary'] . $mhp_b['edu_table'] . $mhp_b['experience'] . $mhp_b['training'] . $mhp_b['achievements'] . $mhp_side . $mhp_tail . '</div>',
		'css'   => $mhp_base . '.cv-ac{padding:18mm 20mm;font-family:"Meeha Serif","Noto Serif Bengali",Georgia,serif}.cv-ac .cv-head{text-align:center;padding-bottom:4mm;border-bottom:1px solid #1d232b}'
			. '.cv-ac .cv-name{font-size:21pt;font-weight:700}.cv-ac .cv-contact{display:flex;justify-content:center;flex-wrap:wrap;gap:.6mm 6mm;margin-top:2mm;font-family:"Meeha Sans","Noto Sans Bengali",sans-serif}'
			. '.cv-ac h2{font-size:12pt;font-variant:small-caps;letter-spacing:.06em;text-align:center;margin-bottom:2mm}.cv-ac h2::before,.cv-ac h2::after{content:" — "}.cv-ac .cv-table th{background:#f5f1ea}',
	),

	array(
		'key'   => 'projukti',
		'name'  => 'প্রযুক্তি',
		'desc'  => 'আইটি ও সফটওয়্যার পেশাজীবীদের জন্য: দক্ষতা ওপরে, প্রকল্প-অভিজ্ঞতা স্পষ্ট।',
		'price' => 79,
		'pages' => 2,
		'order' => 10,
		'html'  => '<div class="cv cv-pj"><header class="cv-head"><div>' . $mhp_b['id'] . $mhp_b['contact'] . '</div>' . $mhp_b['photo'] . '</header>' . $mhp_b['summary'] . $mhp_b['skills'] . $mhp_b['experience'] . $mhp_b['education'] . $mhp_b['training'] . $mhp_b['achievements'] . $mhp_b['languages'] . $mhp_tail . '</div>',
		'css'   => $mhp_base . '.cv-pj{padding:15mm 16mm}.cv-pj .cv-head{display:flex;justify-content:space-between;gap:6mm;align-items:center;padding:6mm;border-radius:3mm;background:#0d141d;color:#e6ecf3}'
			. '.cv-pj .cv-name{font-size:22pt;font-weight:700}.cv-pj .cv-headline{color:#5ad1a8;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:10pt;margin-top:1mm}.cv-pj .cv-contact{margin-top:2mm;font-size:9pt}'
			. '.cv-pj .cv-photo{width:26mm;height:26mm;border-radius:3mm}.cv-pj h2{font-size:11.5pt;margin-bottom:1mm}.cv-pj h2::before{content:"// ";color:#0f9d74;font-family:ui-monospace,Menlo,Consolas,monospace}'
			. '.cv-pj .cv-tags li{border-radius:1.2mm;background:#0d141d;color:#5ad1a8;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:8.5pt}',
	),
);
