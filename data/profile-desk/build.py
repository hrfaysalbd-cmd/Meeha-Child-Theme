import json
W='https://en.wikipedia.org/wiki/Tarique_Rahman_ministry'
T='https://www.tbsnews.net/bangladesh/cabinet-reshuffle-moyeen-partho-saching-prue-millat-take-oath-ministers-1521296'
S13='https://en.wikipedia.org/wiki/13th_Jatiya_Sangsad'
SH='https://en.wikipedia.org/wiki/Shadow_cabinet_of_Bangladesh'
BATCH='ওয়েব-সংগ্রহ-নভেম্বর-২০২৬'
D={'Dhaka':'ঢাকা','Chittagong':'চট্টগ্রাম','Cox\'s Bazar':'কক্সবাজার','Sirajganj':'সিরাজগঞ্জ','Dinajpur':'দিনাজপুর','Feni':'ফেনী','Comilla':'কুমিল্লা','Rajshahi':'রাজশাহী','Magura':'মাগুরা','Sylhet':'সিলেট','Bhola':'ভোলা','Lakshmipur':'লক্ষ্মীপুর','Lalmonirhat':'লালমনিরহাট','Jhenaidah':'ঝিনাইদহ','Chandpur':'চাঁদপুর','Narsingdi':'নরসিংদী','Tangail':'টাঙ্গাইল','Bandarban':'বান্দরবান','Jamalpur':'জামালপুর','Manikganj':'মানিকগঞ্জ','Jessore':'যশোর','Kishoreganj':'কিশোরগঞ্জ','Faridpur':'ফরিদপুর','Panchagarh':'পঞ্চগড়','Barisal':'বরিশাল','Joypurhat':'জয়পুরহাট','Bogra':'বগুড়া','Brahmanbaria':'ব্রাহ্মণবাড়িয়া','Natore':'নাটোর','Bagerhat':'বাগেরহাট','Patuakhali':'পটুয়াখালী','Mymensingh':'ময়মনসিংহ','Pirojpur':'পিরোজপুর','Rajbari':'রাজবাড়ী','Gaibandha':'গাইবান্ধা'}
BN='০১২৩৪৫৬৭৮৯'
bnd=lambda s:''.join(BN[int(c)] if c.isdigit() else c for c in s)
def seat(c):
    if not c: return '',''
    name,_,n=c.rpartition('-')
    if not name: name=c; n=''
    d=D[name]; return d, (f'{d}-{bnd(n)}' if n else d)
BNP='বাংলাদেশ জাতীয়তাবাদী দল (বিএনপি)'
rows=[]
def org(bn,en,cat,imp,links,tags,title='',bio='',status='active',alias=''):
    rows.append(dict(name_bn=bn,name_en=en,category=cat,importance=imp,links=links,tags=tags+[BATCH],title=title,bio=bio,status=status,aliases=alias,country='বাংলাদেশ'))
def person(bn,en,title,cons='',party='',imp=3,tags=[],src=W,date='2026-02-17',cat='politician',bio='',status='active',links=[],alias='',ev=None):
    d,s=seat(cons)
    t=title+(f' · সংসদ সদস্য, {s}' if s else '')
    r=dict(name_bn=bn,name_en=en,category=cat,title=t,org=party,district=d,importance=imp,tags=tags+[BATCH],status=status,links=links,aliases=alias,country='বাংলাদেশ',
           bio=bio or f'{title}। তথ্য উইকিপিডিয়া/সংবাদ সূত্র থেকে নভেম্বর ২০২৬-এ সংগ্রহ; প্রকাশের আগে যাচাই করুন।')
    r['events']=ev if ev is not None else [dict(date=date,kind='position',title=title,source_url=src)]
    rows.append(r)

# ---- parties (first, so people can link to them)
org(BNP,'Bangladesh Nationalist Party','party',5,['https://www.bnpbd.org'],['দল','সরকারি দল'],bio='১৩তম জাতীয় সংসদ নির্বাচনে (১২ ফেব্রুয়ারি ২০২৬) সংখ্যাগরিষ্ঠ দল; সরকার গঠন করেছে।',alias='বিএনপি, BNP')
org('বাংলাদেশ জামায়াতে ইসলামী','Bangladesh Jamaat-e-Islami','party',5,[],['দল','বিরোধী দল'],bio='১৩তম সংসদে প্রধান বিরোধী দল (১১ দলীয় জোটের নেতৃত্বে)।',alias='জামায়াত, Jamaat')
org('জাতীয় নাগরিক পার্টি (এনসিপি)','National Citizen Party','party',5,[],['দল','বিরোধী দল','এনসিপি'],bio='জুলাই ২০২৪ আন্দোলনের ছাত্রনেতাদের গড়া দল; ১৩তম সংসদে বিরোধী জোটে।',alias='এনসিপি, NCP')
org('বাংলাদেশ আওয়ামী লীগ','Bangladesh Awami League','party',4,['https://albd.org'],['দল'],bio='২০২৪ সালের আগস্ট পর্যন্ত ক্ষমতাসীন দল। বর্তমান আইনি অবস্থা (কার্যক্রমে নিষেধাজ্ঞা ইত্যাদি) প্রকাশের আগে যাচাই করুন।',status='inactive',alias='আওয়ামী লীগ, AL')
org('জাতীয় পার্টি','Jatiya Party (Ershad)','party',3,[],['দল'],alias='জাপা')
org('বাংলাদেশ জাতীয় পার্টি (বিজেপি)','Bangladesh Jatiya Party','party',3,[],['দল'],bio='১৩তম সংসদে ১ আসন; বিএনপি জোটে। চেয়ারম্যান আন্দালিভ রহমান পার্থ।')
org('গণ অধিকার পরিষদ','Gono Odhikar Parishad','party',3,[],['দল'],bio='১৩তম সংসদে ১ আসন; বিএনপি জোটে।')
org('গণসংহতি আন্দোলন','Ganosamhati Andolan','party',3,[],['দল'],bio='১৩তম সংসদে ১ আসন; বিএনপি জোটে।')
org('বাংলাদেশ খেলাফত মজলিস','Bangladesh Khilafat Majlis','party',3,[],['দল','বিরোধী দল'],bio='১৩তম সংসদে ৩ আসন; ১১ দলীয় জোটে।')
org('ইসলামী আন্দোলন বাংলাদেশ','Islami Andolan Bangladesh','party',3,[],['দল'],bio='১৩তম সংসদে ১ আসন।')

# ---- state
person('মির্জা ফখরুল ইসলাম আলমগীর','Mirza Fakhrul Islam Alamgir','রাষ্ট্রপতি (আগস্ট ২০২৬ থেকে)',party=BNP,imp=5,tags=['রাষ্ট্রপতি'],src=T,date='2026-08-21',cat='official',bio='২১ আগস্ট ২০২৬-এর মধ্যে রাষ্ট্রপতি হিসেবে শপথ নেন; আগে স্থানীয় সরকারমন্ত্রী ও বিএনপি মহাসচিব ছিলেন। সূত্র দেখে যাচাই করুন।')
person('তারেক রহমান','Tarique Rahman','প্রধানমন্ত্রী; প্রতিরক্ষা ও মন্ত্রিপরিষদ বিভাগের দায়িত্বেও','Dhaka-17',BNP,5,['মন্ত্রিসভা','প্রধানমন্ত্রী'])
person('হাফিজ উদ্দিন আহমদ','Hafiz Uddin Ahmad','স্পিকার, জাতীয় সংসদ',party=BNP,imp=4,tags=['সংসদ'],src=S13,date='2026-03-12')
person('কায়সার কামাল','Kayser Kamal','ডেপুটি স্পিকার, জাতীয় সংসদ',party=BNP,imp=3,tags=['সংসদ'],src=S13)
person('নুরুল ইসলাম মনি','Nurul Islam Moni','চিফ হুইপ (সরকারি দল)',imp=3,tags=['সংসদ'],src=SH,bio='শ্যাডো কেবিনেট পাতায় সরকারি দলের চিফ হুইপ হিসেবে নাম আছে; দল ও আসন পাতায় নেই, যাচাই করুন।')

# ---- advisers
for bn,en,t,c,p,i in [
 ('মির্জা আব্বাস','Mirza Abbas','প্রধানমন্ত্রীর রাজনৈতিক উপদেষ্টা (মন্ত্রী পদমর্যাদা)','Dhaka-8',BNP,4),
 ('নজরুল ইসলাম খান','Nazrul Islam Khan','প্রধানমন্ত্রীর রাজনৈতিক উপদেষ্টা; কৃষি মন্ত্রণালয় বিষয়ক উপদেষ্টা','',BNP,4),
 ('রুহুল কবির রিজভী','Ruhul Kabir Rizvi','প্রধানমন্ত্রীর রাজনৈতিক উপদেষ্টা; শিল্প মন্ত্রণালয় বিষয়ক উপদেষ্টা','',BNP,4),
 ('মো. ইসমাইল জবিউল্লাহ','Md. Ismail Zabihullah','উপদেষ্টা, জনপ্রশাসন মন্ত্রণালয়','','',3),
 ('রাশেদ আল মাহমুদ তিতুমীর','Rashed Al Mahmud Titumir','উপদেষ্টা, অর্থ ও পরিকল্পনা মন্ত্রণালয়','','',3),
 ('জহির উদ্দিন স্বপন','Zahir Uddin Swapon','প্রধানমন্ত্রীর রাজনৈতিক উপদেষ্টা (পদ যাচাই করুন)','Barisal-1',BNP,3),
 ('এ কে এম শামসুল ইসলাম','AKM Shamsul Islam','উপদেষ্টা, প্রতিরক্ষা মন্ত্রণালয় ও সশস্ত্র বাহিনী বিভাগ','','',3),
 ('জাহেদ উর রহমান','Zahed Ur Rahman','নীতি ও কৌশল বিষয়ক উপদেষ্টা; তথ্য ও সংস্কৃতি','','',3),
 ('মাহদী আমিন','Mahdi Amin','উপদেষ্টা: শিক্ষা, প্রাথমিক শিক্ষা, শ্রম ও প্রবাসী কল্যাণ','',BNP,3),
 ('রেহান আসিফ আসাদ','Rehan Asif Asad','উপদেষ্টা: ডাক, টেলিযোগাযোগ ও আইসিটি; বিজ্ঞান ও প্রযুক্তি','','',3)]:
    person(bn,en,t,c,p,i,['মন্ত্রিসভা','উপদেষ্টা'])

# ---- ministers
M=[('আমীর খসরু মাহমুদ চৌধুরী','Amir Khasru Mahmud Chowdhury','অর্থ ও পরিকল্পনামন্ত্রী','Chittagong-11'),
 ('সালাহউদ্দিন আহমদ','Salahuddin Ahmed','স্বরাষ্ট্রমন্ত্রী','Cox\'s Bazar-1'),
 ('ইকবাল হাসান মাহমুদ','Iqbal Hassan Mahmood','বিদ্যুৎ, জ্বালানি ও খনিজ সম্পদমন্ত্রী','Sirajganj-2'),
 ('এ জেড এম জাহিদ হোসেন','A. Z. M. Zahid Hossain','মহিলা ও শিশু বিষয়ক এবং সমাজকল্যাণমন্ত্রী','Dinajpur-6'),
 ('আবদুল আউয়াল মিন্টু','Abdul Awal Mintoo','পরিবেশ, বন ও জলবায়ু পরিবর্তনমন্ত্রী','Feni-3'),
 ('কাজী শাহ মোফাজ্জল হোসাইন কায়কোবাদ','Kazi Shah Mofazzal Hossain Kaikobad','ধর্মমন্ত্রী','Comilla-3'),
 ('মিজানুর রহমান মিনু','Mizanur Rahman Minu','ভূমিমন্ত্রী','Rajshahi-2'),
 ('নিতাই রায় চৌধুরী','Nitai Roy Chowdhury','সংস্কৃতিমন্ত্রী','Magura-2'),
 ('খন্দকার আবদুল মুক্তাদির','Khandaker Abdul Muktadir','শিল্প, বস্ত্র ও পাট এবং বাণিজ্যমন্ত্রী','Sylhet-1'),
 ('আরিফুল হক চৌধুরী','Ariful Haque Choudhury','শ্রম ও কর্মসংস্থান এবং প্রবাসী কল্যাণমন্ত্রী','Sylhet-4'),
 ('শহীদ উদ্দীন চৌধুরী এ্যানী','Shahid Uddin Chowdhury Anee','পানিসম্পদমন্ত্রী','Lakshmipur-3'),
 ('আসাদুল হাবিব দুলু','Asadul Habib Dulu','দুর্যোগ ব্যবস্থাপনা ও ত্রাণমন্ত্রী','Lalmonirhat-3'),
 ('মো. আসাদুজ্জামান','Md. Asaduzzaman','আইন, বিচার ও সংসদ বিষয়কমন্ত্রী','Jhenaidah-1'),
 ('জাকারিয়া তাহের সুমন','Zakaria Taher Sumon','গৃহায়ণ ও গণপূর্তমন্ত্রী','Comilla-8'),
 ('এ এন এম এহছানুল হক মিলন','A. N. M. Ehsanul Hoque Milan','শিক্ষা এবং প্রাথমিক ও গণশিক্ষামন্ত্রী','Chandpur-1'),
 ('সরদার মো. সাখাওয়াত হোসেন','Sardar Md. Sakhawat Husain','স্বাস্থ্য ও পরিবার কল্যাণমন্ত্রী','Narsingdi-4'),
 ('ফকির মাহবুব আনাম স্বপন','Fakir Mahbub Anam Swapan','ডাক, টেলিযোগাযোগ ও আইসিটি এবং বিজ্ঞান ও প্রযুক্তিমন্ত্রী','Tangail-1'),
 ('শেখ রবিউল আলম','Shaikh Rabiul Alam','সড়ক পরিবহন ও সেতু, রেলপথ এবং নৌপরিবহনমন্ত্রী','Dhaka-10'),
 ('আহমেদ আযম খান','Ahmed Azam Khan','মুক্তিযুদ্ধ বিষয়কমন্ত্রী (মার্চ ২০২৬ থেকে)','Tangail-8'),
 ('আফরোজা খানম রিতা','Afroza Khanam Rita','খাদ্যমন্ত্রী','Manikganj-3')]
for bn,en,t,c in M: person(bn,en,t,c,BNP if bn!='নিতাই রায় চৌধুরী' else BNP,4,['মন্ত্রিসভা','মন্ত্রী'])
person('খলিলুর রহমান','Khalilur Rahman','পররাষ্ট্রমন্ত্রী (টেকনোক্র্যাট)',imp=4,tags=['মন্ত্রিসভা','মন্ত্রী'],cat='official')
person('আমিনুর রশিদ ইয়াসিন','Amin ur Rashid Yasin','মৎস্য ও প্রাণিসম্পদ এবং কৃষিমন্ত্রী (টেকনোক্র্যাট)',party=BNP,imp=4,tags=['মন্ত্রিসভা','মন্ত্রী'])
for bn,en,t,c,p in [('আবদুল মঈন খান','Abdul Moyeen Khan','স্থানীয় সরকার, পল্লী উন্নয়ন ও সমবায়মন্ত্রী (আগস্ট ২০২৬ থেকে)','Narsingdi-2',BNP),
 ('সাচিং প্রু জেরী','Sa Ching Prue Jerry','পার্বত্য চট্টগ্রাম বিষয়কমন্ত্রী (আগস্ট ২০২৬ থেকে)','Bandarban',BNP),
 ('এম রশিদুজ্জামান মিল্লাত','M. Rashiduzzaman Millat','বেসামরিক বিমান পরিবহন ও পর্যটনমন্ত্রী (আগস্ট ২০২৬ থেকে)','Jamalpur-1',BNP),
 ('আন্দালিভ রহমান পার্থ','Andaleeve Rahman Partho','তথ্য ও সম্প্রচারমন্ত্রী (আগস্ট ২০২৬ থেকে)','Bhola-1','বাংলাদেশ জাতীয় পার্টি (বিজেপি)')]:
    person(bn,en,t,c,p,4,['মন্ত্রিসভা','মন্ত্রী'],src=T,date='2026-08-21')

# ---- state ministers
SM=[('অনিন্দ্য ইসলাম অমিত','Anindya Islam Amit','বিদ্যুৎ, জ্বালানি ও খনিজ সম্পদ প্রতিমন্ত্রী','Jessore-3',BNP),
 ('মো. শরীফুল আলম','Md. Shariful Alam','বস্ত্র ও পাট প্রতিমন্ত্রী','Kishoreganj-6',BNP),
 ('শামা ওবায়েদ','Shama Obaed','পররাষ্ট্র প্রতিমন্ত্রী','Faridpur-2',BNP),
 ('সুলতান সালাউদ্দিন টুকু','Sultan Salauddin Tuku','মৎস্য ও প্রাণিসম্পদ প্রতিমন্ত্রী','Tangail-5',BNP),
 ('ফরহাদ হোসেন আজাদ','Farhad Hossain Azad','পানিসম্পদ প্রতিমন্ত্রী','Panchagarh-2',BNP),
 ('মীর মোহাম্মদ হেলাল উদ্দিন','Mir Mohammed Helal Uddin','পার্বত্য চট্টগ্রাম বিষয়ক প্রতিমন্ত্রী; ভূমি (অতিরিক্ত)','Chittagong-5',BNP),
 ('হাবিবুর রশিদ হাবিব','Habibur Rashid Habib','রেলপথ এবং সড়ক পরিবহন প্রতিমন্ত্রী','Dhaka-9',BNP),
 ('মো. রাজিব আহসান','Md. Razib Ahsan','নৌপরিবহন ও সেতু প্রতিমন্ত্রী','Barisal-4',BNP),
 ('মো. আবদুল বারী','Md Abdul Bari','জনপ্রশাসন ও খাদ্য প্রতিমন্ত্রী','Joypurhat-2',BNP),
 ('মীর শাহে আলম','Mir Shahe Alam','স্থানীয় সরকার প্রতিমন্ত্রী','Bogra-2',BNP),
 ('জোনায়েদ সাকি','Zonayed Saki','পরিকল্পনা প্রতিমন্ত্রী','Brahmanbaria-6','গণসংহতি আন্দোলন'),
 ('ইশরাক হোসেন','Ishraque Hossain','মুক্তিযুদ্ধ বিষয়ক প্রতিমন্ত্রী','Dhaka-6',BNP),
 ('ফারজানা শারমিন','Farzana Sharmin','সমাজকল্যাণ প্রতিমন্ত্রী','Natore-1',BNP),
 ('শেখ ফরিদুল ইসলাম','Shaikh Faridul Islam','পরিবেশ, বন ও জলবায়ু প্রতিমন্ত্রী','Bagerhat-3',BNP),
 ('নুরুল হক নুর','Nurul Haque Nur','প্রবাসী কল্যাণ প্রতিমন্ত্রী','Patuakhali-3','গণ অধিকার পরিষদ'),
 ('ইয়াসের খান চৌধুরী','Yasser Khan Choudhury','তথ্য ও সম্প্রচার প্রতিমন্ত্রী','Mymensingh-9',BNP),
 ('এম ইকবাল হোসেন','M. Iqbal Hossain','দুর্যোগ ব্যবস্থাপনা ও ত্রাণ প্রতিমন্ত্রী','Mymensingh-3',BNP),
 ('এম এ মুহিত','M. A. Muhit','স্বাস্থ্য ও পরিবার কল্যাণ প্রতিমন্ত্রী','Sirajganj-6',BNP),
 ('আহমদ সোহেল মঞ্জুর','Ahammad Sohel Monzoor','গৃহায়ণ ও গণপূর্ত প্রতিমন্ত্রী','Pirojpur-2',BNP),
 ('ববি হাজ্জাজ','Bobby Hajjaj','প্রাথমিক ও গণশিক্ষা প্রতিমন্ত্রী','Dhaka-13',BNP),
 ('আলী নেওয়াজ মাহমুদ খৈয়াম','Ali Newaz Mahmud Khaiyam','সংস্কৃতি প্রতিমন্ত্রী','Rajbari-1',BNP)]
for bn,en,t,c,p in SM:
    # party stated on the page only for some; BNP otherwise assumed from the government side -> mark
    person(bn,en,t,c,p,3,['মন্ত্রিসভা','প্রতিমন্ত্রী'])
person('আমিনুল হক','Aminul Haque','যুব ও ক্রীড়া প্রতিমন্ত্রী (টেকনোক্র্যাট)',imp=3,tags=['মন্ত্রিসভা','প্রতিমন্ত্রী'],alias='সাবেক জাতীয় ফুটবলার')
person('হুমায়ুন কবির','Humaiun Kobir','পররাষ্ট্র প্রতিমন্ত্রী (আগস্ট ২০২৬ থেকে)',party=BNP,imp=3,tags=['মন্ত্রিসভা','প্রতিমন্ত্রী'],src=T,date='2026-08-21')
person('শামীম কায়সার লিংকন','Shamim Kaisar Lincoln','ধর্ম প্রতিমন্ত্রী (আগস্ট ২০২৬ থেকে)','Gaibandha-4',BNP,3,['মন্ত্রিসভা','প্রতিমন্ত্রী'],src=T,date='2026-08-21')
person('আশিক চৌধুরী','Ashik Chowdhury','নির্বাহী চেয়ারম্যান, বিডা; প্রধানমন্ত্রীর বিশেষ সহকারী (প্রতিমন্ত্রী পদমর্যাদা)',imp=3,tags=['বিশেষ সহকারী'],cat='official')

# ---- opposition
JM='বাংলাদেশ জামায়াতে ইসলামী'; NCP='জাতীয় নাগরিক পার্টি (এনসিপি)'
person('শফিকুর রহমান','Shafiqur Rahman','জাতীয় সংসদে বিরোধীদলীয় নেতা; জামায়াতে ইসলামীর আমির','Dhaka-15',JM,5,['বিরোধী দল','জামায়াত'],src='https://en.wikipedia.org/wiki/Leader_of_the_Opposition_in_Jatiya_Sangsad')
person('সৈয়দ আবদুল্লাহ মুহাম্মদ তাহের','Syed Abdullah Muhammad Taher','বিরোধীদলীয় উপনেতা',party=JM,imp=4,tags=['বিরোধী দল','জামায়াত'],src=S13)
person('নাহিদ ইসলাম','Nahid Islam','এনসিপির প্রধান; বিরোধী দলের চিফ হুইপ','Dhaka-11',NCP,5,['বিরোধী দল','এনসিপি','জুলাই আন্দোলন'],src=SH)
person('আসিফ মাহমুদ সজীব ভূঁইয়া','Asif Mahmud Shojib Bhuyain','এনসিপির মুখপাত্র (ফেব্রুয়ারি ২০২৬-এর তথ্য)',party=NCP,imp=4,tags=['বিরোধী দল','এনসিপি','জুলাই আন্দোলন'],src=SH,alias='আসিফ মাহমুদ',bio='অন্তর্বর্তী সরকারের সাবেক উপদেষ্টা; ফেব্রুয়ারি ২০২৬-এ এনসিপির মুখপাত্র হিসেবে শ্যাডো কেবিনেট ঘোষণা করেন। বর্তমান পদ যাচাই করুন।')
person('মামুনুল হক','Mamunul Haque','বাংলাদেশ খেলাফত মজলিসের নেতা',party='বাংলাদেশ খেলাফত মজলিস',imp=4,tags=['বিরোধী দল'],src=S13,bio='১৩তম সংসদ নির্বাচনে ঢাকা-১৩ আসনে পরাজিত। পদ যাচাই করুন।')
person('শিশির মনির','Shishir Monir','জামায়াত নেতা, আইনজীবী',party=JM,imp=3,tags=['বিরোধী দল','জামায়াত'],src=SH,bio='সুনামগঞ্জ-২ আসনে জামায়াতের প্রার্থী ছিলেন (পরাজিত); বিরোধী জোটের শ্যাডো কেবিনেট ঘোষণায় ছিলেন।')
for bn,en in [('হাসনাত আবদুল্লাহ','Hasnat Abdullah'),('সারজিস আলম','Sarjis Alam'),('আখতার হোসেন','Akhter Hossen')]:
    person(bn,en,'এনসিপি নেতা (বর্তমান পদ ও আসন যাচাই করুন)',party=NCP,imp=4,tags=['এনসিপি','জুলাই আন্দোলন'],ev=[],bio='জুলাই ২০২৪ আন্দোলনের পরিচিত মুখ ও এনসিপির নেতা। বর্তমান পদ/আসনের তথ্য এই সংগ্রহে যাচাই করা হয়নি।')
person('শেখ হাসিনা','Sheikh Hasina','সাবেক প্রধানমন্ত্রী; আওয়ামী লীগ সভাপতি',party='বাংলাদেশ আওয়ামী লীগ',imp=5,tags=['সাবেক সরকার'],status='abroad',ev=[],bio='আগস্ট ২০২৪-এ ক্ষমতা ছেড়ে দেশ ছাড়েন। মামলা ও বর্তমান অবস্থার তথ্য আলাদা সূত্র দেখে ঘটনাপঞ্জিতে যোগ করুন।')
person('মুহাম্মদ ইউনূস','Muhammad Yunus','সাবেক প্রধান উপদেষ্টা, অন্তর্বর্তী সরকার (২০২৪–২০২৬); নোবেলজয়ী',imp=5,tags=['সাবেক সরকার'],cat='official',ev=[],bio='২০২৪ সালের আগস্ট থেকে ২০২৬-এর ফেব্রুয়ারিতে নির্বাচিত সরকার আসা পর্যন্ত অন্তর্বর্তী সরকারের প্রধান উপদেষ্টা।')

# ---- online activists / journalists abroad
A=[('পিনাকী ভট্টাচার্য','Pinaki Bhattacharya','লেখক, ব্লগার ও ইউটিউবার (ফ্রান্সপ্রবাসী)','https://netra.news/2023/bangladeshs-youtube-dissidents/'),
 ('ইলিয়াস হোসেন','Elias Hossain','সাংবাদিক ও ইউটিউবার (যুক্তরাষ্ট্রপ্রবাসী)','https://netra.news/2023/bangladeshs-youtube-dissidents/'),
 ('কনক সারওয়ার','Kanak Sarwar','সাংবাদিক ও ইউটিউবার (যুক্তরাষ্ট্রপ্রবাসী)','https://thediplomat.com/2023/07/bangladeshi-diaspora-journalists-push-back-against-democratic-backsliding-at-home'),
 ('জুলকারনাইন সায়ের','Zulkarnain Saer Khan','অনুসন্ধানী সাংবাদিক (প্রবাসী)','https://netra.news/2023/bangladeshs-youtube-dissidents/'),
 ('তাসনিম খলিল','Tasneem Khalil','সাংবাদিক; নেত্র নিউজের প্রধান সম্পাদক (সুইডেনপ্রবাসী)','https://netra.news/2023/bangladeshs-youtube-dissidents/')]
for bn,en,t,src in A:
    person(bn,en,t,imp=3,tags=['অনলাইন অ্যাক্টিভিস্ট','প্রবাসী'],cat='activist',status='abroad',src=src,date='2023-01-01',
           ev=[dict(date='2023-01-01',kind='other',title='সূত্রে পরিচিতি: প্রবাসী ইউটিউব/অনলাইন কণ্ঠ',source_url=src)],
           bio=f'{t}। প্রবাসে থেকে অনলাইনে বাংলাদেশের রাজনীতি নিয়ে সক্রিয়। সূত্রের তারিখ পুরোনো হতে পারে; বর্তমান অবস্থান যাচাই করুন।')
rows[-4]['events'].append(dict(date='2026-01-01',kind='case',title='নিউইয়র্কে চাঁদাবাজির চেষ্টার অভিযোগে গ্রেপ্তারের খবর (তারিখ সূত্রে দেখে ঠিক করুন)',source_url='https://thefinancialexpress.com.bd/home/youtuber-elias-hossain-arrested-in-new-york-over-extortion-attempt')) if rows[-4]['name_en']=='Elias Hossain' else None

# ---- celebrities (profession only)
for bn,en,t in [('শাকিব খান','Shakib Khan','চলচ্চিত্র অভিনেতা'),('জয়া আহসান','Jaya Ahsan','অভিনেত্রী'),('চঞ্চল চৌধুরী','Chanchal Chowdhury','অভিনেতা'),
 ('মোশাররফ করিম','Mosharraf Karim','অভিনেতা'),('মেহজাবীন চৌধুরী','Mehazabien Chowdhury','অভিনেত্রী'),('রুনা লায়লা','Runa Laila','সংগীতশিল্পী'),
 ('সাবিনা ইয়াসমিন','Sabina Yasmin','সংগীতশিল্পী'),('হাবিব ওয়াহিদ','Habib Wahid','সংগীতশিল্পী ও সুরকার'),
 ('সাকিব আল হাসান','Shakib Al Hasan','ক্রিকেটার; আওয়ামী লীগের সাবেক সংসদ সদস্য'),('মাশরাফি বিন মর্তুজা','Mashrafe Bin Mortaza','সাবেক ক্রিকেট অধিনায়ক; আওয়ামী লীগের সাবেক সংসদ সদস্য'),('তামিম ইকবাল','Tamim Iqbal','ক্রিকেটার')]:
    person(bn,en,t,imp=2,tags=['সেলিব্রেটি'],cat='celebrity',ev=[],bio=f'{t}। পরিচিতি সাধারণ জ্ঞান থেকে; বর্তমান অবস্থা যাচাই করুন।')

# ---- organisations
for bn,en,cat,imp,links,tags in [
 ('বাংলাদেশ পুলিশ','Bangladesh Police','law_bd',5,['https://www.police.gov.bd'],['আইনশৃঙ্খলা বাহিনী']),
 ('ঢাকা মেট্রোপলিটন পুলিশ (ডিএমপি)','Dhaka Metropolitan Police','law_bd',4,['https://dmp.gov.bd'],['আইনশৃঙ্খলা বাহিনী']),
 ('র‍্যাপিড অ্যাকশন ব্যাটালিয়ন (র‍্যাব)','Rapid Action Battalion','law_bd',4,['https://www.rab.gov.bd'],['আইনশৃঙ্খলা বাহিনী']),
 ('বর্ডার গার্ড বাংলাদেশ (বিজিবি)','Border Guard Bangladesh','law_bd',4,['https://www.bgb.gov.bd'],['আইনশৃঙ্খলা বাহিনী']),
 ('আন্তঃবাহিনী জনসংযোগ পরিদপ্তর (আইএসপিআর)','Inter Services Public Relations','law_bd',4,['https://www.ispr.gov.bd'],['সশস্ত্র বাহিনী']),
 ('বাংলাদেশ কোস্ট গার্ড','Bangladesh Coast Guard','law_bd',3,[],['আইনশৃঙ্খলা বাহিনী']),
 ('দুর্নীতি দমন কমিশন (দুদক)','Anti-Corruption Commission','govt',4,['https://acc.org.bd'],['সরকারি সংস্থা']),
 ('বাংলাদেশ নির্বাচন কমিশন','Bangladesh Election Commission','govt',4,['https://www.ecs.gov.bd'],['সরকারি সংস্থা']),
 ('বাংলাদেশ ব্যাংক','Bangladesh Bank','govt',4,['https://www.bb.org.bd'],['সরকারি সংস্থা']),
 ('বিটিআরসি','Bangladesh Telecommunication Regulatory Commission','govt',3,['https://www.btrc.gov.bd'],['সরকারি সংস্থা']),
 ('বাংলাদেশ জাতীয় সংসদ','Parliament of Bangladesh','govt',4,['https://www.parliament.gov.bd'],['সংসদ']),
 ('প্রথম আলো','Prothom Alo','media',4,['https://www.prothomalo.com'],['গণমাধ্যম']),
 ('দ্য ডেইলি স্টার','The Daily Star','media',4,['https://www.thedailystar.net'],['গণমাধ্যম']),
 ('বিডিনিউজ২৪','bdnews24.com','media',3,['https://bdnews24.com'],['গণমাধ্যম']),
 ('দ্য বিজনেস স্ট্যান্ডার্ড','The Business Standard','media',3,['https://www.tbsnews.net'],['গণমাধ্যম']),
 ('ঢাকা ট্রিবিউন','Dhaka Tribune','media',3,['https://www.dhakatribune.com'],['গণমাধ্যম']),
 ('যুগান্তর','Jugantor','media',3,['https://www.jugantor.com'],['গণমাধ্যম']),
 ('কালের কণ্ঠ','Kaler Kantho','media',3,['https://www.kalerkantho.com'],['গণমাধ্যম']),
 ('সমকাল','Samakal','media',3,['https://samakal.com'],['গণমাধ্যম']),
 ('নয়া দিগন্ত','Naya Diganta','media',3,['https://www.dailynayadiganta.com'],['গণমাধ্যম']),
 ('আমার দেশ','Amar Desh','media',3,[],['গণমাধ্যম']),
 ('সময় টিভি','Somoy TV','media',4,['https://www.somoynews.tv'],['গণমাধ্যম','টিভি']),
 ('যমুনা টেলিভিশন','Jamuna Television','media',4,['https://jamuna.tv'],['গণমাধ্যম','টিভি']),
 ('চ্যানেল ২৪','Channel 24','media',3,[],['গণমাধ্যম','টিভি']),
 ('বিবিসি বাংলা','BBC Bangla','media',4,['https://www.bbc.com/bengali'],['গণমাধ্যম']),
 ('নেত্র নিউজ','Netra News','media',3,['https://netra.news'],['গণমাধ্যম','অনুসন্ধানী']),
 ('বাসস','Bangladesh Sangbad Sangstha','media',3,['https://www.bssnews.net'],['গণমাধ্যম','সরকারি']),
 ('আইন ও সালিশ কেন্দ্র','Ain o Salish Kendra','ngo',3,['https://www.askbd.org'],['মানবাধিকার']),
 ('অধিকার','Odhikar','ngo',3,['https://odhikar.org'],['মানবাধিকার']),
 ('ট্রান্সপারেন্সি ইন্টারন্যাশনাল বাংলাদেশ (টিআইবি)','Transparency International Bangladesh','ngo',3,['https://www.ti-bangladesh.org'],['সুশাসন']),
 ('ব্র্যাক','BRAC','ngo',3,['https://www.brac.net'],['উন্নয়ন'])]:
    org(bn,en,cat,imp,links,tags)

out={'format':'meeha-profile-desk','version':1,'note':'ওয়েব থেকে নভেম্বর ২০২৬-এ সংগৃহীত। সব লিংক "যাচাই হয়নি" হিসেবে ঢুকবে। প্রকাশের আগে প্রতিটা তথ্য সূত্র দেখে যাচাই করুন।','rows':rows}
json.dump(out,open('/tmp/claude-0/wp/seed/bd-profiles-2026-11.json','w'),ensure_ascii=False,indent=1)
print(len(rows), sum(1 for r in rows if r['category'] in('party','media','law_bd','govt','ngo')))
