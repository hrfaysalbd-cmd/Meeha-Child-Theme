<?php
/**
 * r47 CV designs — five more templates (two free, three paid) in the same package format and
 * Mustache subset as data/cv-templates.php. Installed by MHP_CV::seed( true ) on upgrade; upsert
 * is by key, so editors' changes to existing designs stay untouched.
 *
 * @package meeha-prep
 */

defined( 'ABSPATH' ) || exit;

$mhp2_base = '.cv{box-sizing:border-box;width:210mm;min-height:297mm;margin:0 auto;background:#fff;color:#1d232b;font:10.5pt/1.55 "Meeha Sans","Noto Sans Bengali","Hind Siliguri",system-ui,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
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

$mhp2 = array();

$mhp2['photo']   = '{{#photo}}<img class="cv-photo" src="{{photo}}" alt="">{{/photo}}';
$mhp2['contact'] = '<ul class="cv-contact">{{#phone}}<li><span class="cv-k">{{L.phone}}</span> {{phone}}</li>{{/phone}}{{#email}}<li><span class="cv-k">{{L.email}}</span> {{email}}</li>{{/email}}{{#address}}<li><span class="cv-k">{{L.address}}</span> {{address}}</li>{{/address}}{{#website}}<li><span class="cv-k">{{L.website}}</span> {{website}}</li>{{/website}}</ul>';
$mhp2['id']      = '<h1 class="cv-name">{{name}}{{^name}}{{L.your_name}}{{/name}}</h1>{{#headline}}<p class="cv-headline">{{headline}}</p>{{/headline}}';
$mhp2['summary'] = '{{#summary}}<section class="cv-sec cv-sec--summary"><h2>{{L.summary}}</h2><p class="cv-text">{{summary}}</p></section>{{/summary}}';

$mhp2['experience'] = '{{#has_experience}}<section class="cv-sec"><h2>{{L.experience}}</h2>{{#experience}}<div class="cv-item"><div class="cv-item__head"><b class="cv-item__t">{{role}}</b><span class="cv-date">{{from}}{{#to}} – {{to}}{{/to}}</span></div>{{#org}}<div class="cv-item__sub">{{org}}{{#place}}, {{place}}{{/place}}</div>{{/org}}{{#details}}<p class="cv-text">{{details}}</p>{{/details}}</div>{{/experience}}</section>{{/has_experience}}';
$mhp2['education']  = '{{#has_education}}<section class="cv-sec"><h2>{{L.education}}</h2>{{#education}}<div class="cv-item"><div class="cv-item__head"><b class="cv-item__t">{{degree}}</b><span class="cv-date">{{year}}</span></div><div class="cv-item__sub">{{inst}}{{#result}} · {{L.result}}: {{result}}{{/result}}</div></div>{{/education}}</section>{{/has_education}}';
$mhp2['edu_table']  = '{{#has_education}}<section class="cv-sec"><h2>{{L.education}}</h2><table class="cv-table"><thead><tr><th>{{L.degree}}</th><th>{{L.institution}}</th><th>{{L.year}}</th><th>{{L.result}}</th></tr></thead><tbody>{{#education}}<tr><td>{{degree}}</td><td>{{inst}}</td><td>{{year}}</td><td>{{result}}</td></tr>{{/education}}</tbody></table></section>{{/has_education}}';
$mhp2['skills']     = '{{#has_skills}}<section class="cv-sec"><h2>{{L.skills}}</h2><ul class="cv-tags">{{#skills}}<li>{{name}}</li>{{/skills}}</ul></section>{{/has_skills}}';
$mhp2['languages']  = '{{#has_languages}}<section class="cv-sec"><h2>{{L.languages}}</h2><ul class="cv-list">{{#languages}}<li><b>{{name}}</b>{{#level}} — {{level}}{{/level}}</li>{{/languages}}</ul></section>{{/has_languages}}';
$mhp2['training']   = '{{#has_training}}<section class="cv-sec"><h2>{{L.training}}</h2>{{#training}}<div class="cv-item"><div class="cv-item__head"><b class="cv-item__t">{{name}}</b><span class="cv-date">{{year}}</span></div>{{#org}}<div class="cv-item__sub">{{org}}</div>{{/org}}</div>{{/training}}</section>{{/has_training}}';
$mhp2['achievements'] = '{{#has_achievements}}<section class="cv-sec"><h2>{{L.achievements}}</h2><ul class="cv-list">{{#achievements}}<li>{{text}}</li>{{/achievements}}</ul></section>{{/has_achievements}}';
$mhp2['personal']   = '{{#has_personal}}<section class="cv-sec"><h2>{{L.personal}}</h2><table class="cv-kv">{{#personal.father}}<tr><th>{{L.father}}</th><td>{{personal.father}}</td></tr>{{/personal.father}}{{#personal.mother}}<tr><th>{{L.mother}}</th><td>{{personal.mother}}</td></tr>{{/personal.mother}}{{#personal.dob}}<tr><th>{{L.dob}}</th><td>{{personal.dob}}</td></tr>{{/personal.dob}}{{#personal.nationality}}<tr><th>{{L.nationality}}</th><td>{{personal.nationality}}</td></tr>{{/personal.nationality}}{{#personal.religion}}<tr><th>{{L.religion}}</th><td>{{personal.religion}}</td></tr>{{/personal.religion}}{{#personal.marital}}<tr><th>{{L.marital}}</th><td>{{personal.marital}}</td></tr>{{/personal.marital}}{{#personal.permanent}}<tr><th>{{L.permanent}}</th><td>{{personal.permanent}}</td></tr>{{/personal.permanent}}</table></section>{{/has_personal}}';
$mhp2['references'] = '{{#has_references}}<section class="cv-sec"><h2>{{L.references}}</h2><div class="cv-refs">{{#references}}<div class="cv-ref"><b>{{name}}</b>{{#role}}<div>{{role}}</div>{{/role}}{{#contact}}<div class="cv-muted">{{contact}}</div>{{/contact}}</div>{{/references}}</div></section>{{/has_references}}';
$mhp2['declaration'] = '{{#declaration}}<section class="cv-sec cv-sec--decl"><h2>{{L.declaration}}</h2><p class="cv-text">{{declaration}}</p><div class="cv-sign"><span>{{name}}</span><span>{{L.signature}}</span></div></section>{{/declaration}}';

$mhp2_main = $mhp2['summary'] . $mhp2['experience'] . $mhp2['education'] . $mhp2['training'] . $mhp2['achievements'];
$mhp2_side = $mhp2['skills'] . $mhp2['languages'];
$mhp2_tail = $mhp2['personal'] . $mhp2['references'] . $mhp2['declaration'];

return array(

	/* ---------------- Free ---------------- */

	array(
		'key'   => 'shobuj-rekha',
		'name'  => 'সবুজ রেখা',
		'desc'  => 'বাঁয়ে সবুজ রেখা, পরিষ্কার এক কলাম — এনজিও ও উন্নয়ন খাতের আবেদনে মানানসই।',
		'price' => 0,
		'pages' => 1,
		'order' => 11,
		'html'  => '<div class="cv cv-sr"><header class="cv-head">' . $mhp2['photo'] . '<div class="cv-headtext">' . $mhp2['id'] . $mhp2['contact'] . '</div></header>' . $mhp2_main . $mhp2_side . $mhp2_tail . '</div>',
		'css'   => $mhp2_base . '.cv-sr{padding:15mm 17mm 15mm 21mm;border-left:5mm solid #15803d}'
			. '.cv-sr .cv-head{display:flex;gap:6mm;align-items:center;padding-bottom:4mm;border-bottom:1px solid #d9e5dc}.cv-sr .cv-photo{width:27mm;height:33mm;border-radius:2mm}'
			. '.cv-sr .cv-name{font-size:21pt;font-weight:700;color:#14532d}.cv-sr .cv-headline{font-size:11pt;color:#15803d;margin-top:1mm}.cv-sr .cv-contact{display:flex;flex-wrap:wrap;gap:1mm 5mm;margin-top:2.5mm}'
			. '.cv-sr h2{font-size:11pt;font-weight:700;color:#14532d;margin-bottom:1.5mm;display:flex;align-items:center;gap:2.5mm}.cv-sr h2::after{content:"";flex:1;height:1px;background:#d9e5dc}'
			. '.cv-sr .cv-tags li{background:#e8f5ec;color:#14532d}',
	),

	array(
		'key'   => 'fresher',
		'name'  => 'ফ্রেশার',
		'desc'  => 'সদ্য পাস করাদের জন্য: শিক্ষার ছক আগে, তারপর প্রশিক্ষণ-দক্ষতা; অভিজ্ঞতা না থাকলেও পাতা ভরা দেখায়।',
		'price' => 0,
		'pages' => 1,
		'order' => 12,
		'html'  => '<div class="cv cv-fr"><header class="cv-head"><div class="cv-headtext"><p class="cv-label">{{L.cv_title}}</p>' . $mhp2['id'] . $mhp2['contact'] . '</div>' . $mhp2['photo'] . '</header>' . $mhp2['summary'] . $mhp2['edu_table'] . $mhp2['training'] . $mhp2_side . $mhp2['achievements'] . $mhp2['experience'] . $mhp2_tail . '</div>',
		'css'   => $mhp2_base . '.cv-fr{padding:15mm 17mm}.cv-fr .cv-head{display:flex;justify-content:space-between;gap:6mm;align-items:flex-start;padding-bottom:4mm;border-bottom:2px solid #7c3aed}'
			. '.cv-fr .cv-label{font-size:9pt;font-weight:700;letter-spacing:.2em;color:#7c3aed}.cv-fr .cv-name{font-size:20pt;font-weight:700}.cv-fr .cv-headline{font-size:10.5pt;color:#4a535e;margin-top:1mm}'
			. '.cv-fr .cv-photo{width:28mm;height:34mm;border-radius:2mm;border:1px solid #ddd6fe}'
			. '.cv-fr h2{font-size:10.5pt;font-weight:700;color:#5b21b6;text-transform:uppercase;letter-spacing:.05em;padding-bottom:1mm;border-bottom:1px solid #e9e4f8;margin-bottom:1.5mm}'
			. '.cv-fr .cv-table th{background:#f5f3fb}.cv-fr .cv-tags li{background:#f1edfb;color:#5b21b6}',
	),

	/* ---------------- Paid ---------------- */

	array(
		'key'   => 'maroon-nirbahi',
		'name'  => 'মেরুন নির্বাহী',
		'desc'  => 'গাঢ় মেরুন ব্যান্ডে নাম ও ছবি, সোনালি রেখা — সিনিয়র পদের আবেদনপত্রে ওজনদার।',
		'price' => 99,
		'pages' => 2,
		'order' => 13,
		'html'  => '<div class="cv cv-mn"><header class="cv-band">' . $mhp2['photo'] . '<div class="cv-headtext">' . $mhp2['id'] . $mhp2['contact'] . '</div></header><div class="cv-body">' . $mhp2_main . $mhp2_side . $mhp2_tail . '</div></div>',
		'css'   => $mhp2_base . '.cv-mn .cv-band{display:flex;gap:7mm;align-items:center;background:#5c1a24;color:#fdf6ee;padding:11mm 16mm;border-bottom:1.5mm solid #c9a227}'
			. '.cv-mn .cv-photo{width:32mm;height:32mm;border-radius:50%;border:2px solid #c9a227}'
			. '.cv-mn .cv-name{font-size:23pt;font-weight:700;letter-spacing:.01em}.cv-mn .cv-headline{font-size:11pt;color:#eed9a9;margin-top:1mm}'
			. '.cv-mn .cv-contact{display:flex;flex-wrap:wrap;gap:1mm 6mm;margin-top:2.5mm;color:#f3e4cf}.cv-mn .cv-band .cv-k{color:#c9a227}'
			. '.cv-mn .cv-body{padding:8mm 16mm 14mm}.cv-mn h2{font-size:11.5pt;font-weight:700;color:#5c1a24;padding-bottom:1mm;border-bottom:2px solid #c9a227;margin-bottom:1.5mm}'
			. '.cv-mn .cv-tags li{background:#f7eef0;color:#5c1a24}',
	),

	array(
		'key'   => 'dhusor-split',
		'name'  => 'ধূসর স্প্লিট',
		'desc'  => 'ডানে হালকা ধূসর সাইডবারে দক্ষতা-ভাষা-ব্যক্তিগত তথ্য, বাঁয়ে অভিজ্ঞতা — আধুনিক কর্পোরেট লুক।',
		'price' => 99,
		'pages' => 2,
		'order' => 14,
		'html'  => '<div class="cv cv-ds"><main class="cv-main"><header class="cv-head">' . $mhp2['id'] . '</header>' . $mhp2_main . $mhp2['references'] . $mhp2['declaration'] . '</main><aside class="cv-side">' . $mhp2['photo'] . $mhp2['contact'] . $mhp2_side . $mhp2['personal'] . '</aside></div>',
		'css'   => $mhp2_base . '.cv-ds{display:grid;grid-template-columns:1fr 62mm;background:linear-gradient(270deg,#eef1f4 62mm,#fff 62mm)}'
			. '.cv-ds .cv-main{padding:14mm 10mm 14mm 15mm}.cv-ds .cv-side{padding:14mm 8mm;color:#2a313a}'
			. '.cv-ds .cv-photo{width:38mm;height:38mm;border-radius:2mm;margin:0 auto 5mm}'
			. '.cv-ds .cv-name{font-size:23pt;font-weight:700;color:#111827;line-height:1.15}.cv-ds .cv-headline{font-size:11pt;color:#4b5563;margin-top:1mm}'
			. '.cv-ds .cv-main h2{font-size:11pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#111827;padding-bottom:1mm;border-bottom:2px solid #9ca3af;margin-bottom:1.5mm}'
			. '.cv-ds .cv-side h2{font-size:10pt;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#374151;margin-bottom:2mm}'
			. '.cv-ds .cv-contact li{margin-top:1.4mm;word-break:break-word}.cv-ds .cv-k{display:block;font-size:8pt;color:#6b7280}'
			. '.cv-ds .cv-tags li{background:#fff;border:1px solid #d1d5db}'
			. '.cv-ds .cv-kv th{display:block;width:auto;padding:0;color:#6b7280}.cv-ds .cv-kv td{display:block;padding:0 0 1.5mm}.cv-ds .cv-kv tr{display:block}',
	),

	array(
		'key'   => 'shikkhok',
		'name'  => 'শিক্ষক',
		'desc'  => 'এনটিআরসিএ ও শিক্ষাপ্রতিষ্ঠানের আবেদন ধাঁচে: শিক্ষার ছক, প্রশিক্ষণ ও রেফারেন্সে জোর।',
		'price' => 79,
		'pages' => 2,
		'order' => 15,
		'html'  => '<div class="cv cv-sk"><header class="cv-head"><div class="cv-headtext"><p class="cv-label">{{L.cv_title}}</p>' . $mhp2['id'] . $mhp2['contact'] . '</div>' . $mhp2['photo'] . '</header>' . $mhp2['summary'] . $mhp2['edu_table'] . $mhp2['training'] . $mhp2['experience'] . $mhp2_side . $mhp2['achievements'] . $mhp2_tail . '</div>',
		'css'   => $mhp2_base . '.cv-sk{padding:14mm 16mm}.cv-sk .cv-head{display:flex;justify-content:space-between;gap:6mm;align-items:flex-start;padding-bottom:4mm;border-bottom:3px double #0e7490}'
			. '.cv-sk .cv-label{font-size:9pt;font-weight:700;letter-spacing:.2em;color:#0e7490}.cv-sk .cv-name{font-size:20pt;font-weight:700;color:#164e63}'
			. '.cv-sk .cv-photo{width:30mm;height:36mm;border:1px solid #67a9bc;padding:.8mm}'
			. '.cv-sk h2{display:inline-block;font-size:10.5pt;font-weight:700;padding:.8mm 3mm;background:#0e7490;color:#fff;border-radius:1mm;margin-bottom:2mm}'
			. '.cv-sk .cv-table th{background:#e8f4f7}.cv-sk .cv-tags li{background:#e8f4f7;color:#164e63}',
	),
);
