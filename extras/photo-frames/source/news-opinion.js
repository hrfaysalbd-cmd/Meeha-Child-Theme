// Draws the 3 news + 3 opinion frames (theme presets surjo, nokshi, sonali, sompadokiyo, bishleshon, kolom) into out/, 4 sizes each.
const {chromium}=require('/opt/node-tools/node_modules/playwright');
const F='file:///home/claude/Meeha-Child-Theme/theme/meeha/assets/fonts/';
const SIZES=[[1080,1080],[1080,1350],[1200,630],[1080,1920]];
// tab at top centre: returns svg
function tab(W,s0,txt,{fill,color,dot,w,stroke}){const s=Math.max(s0,.8);const h=58*s,tw=(w||320)*s,x=(W-tw)/2,r=16*s;
 return `<path d="M${x} 0 H${x+tw} V${h-r} Q${x+tw} ${h} ${x+tw-r} ${h} H${x+r} Q${x} ${h} ${x} ${h-r} Z" fill="${fill}" ${stroke?`stroke="${stroke}" stroke-width="${3*s}"`:''}/>`+
 (dot?`<circle cx="${x+34*s}" cy="${h/2}" r="${9*s}" fill="${dot}"/><circle cx="${x+34*s}" cy="${h/2}" r="${15*s}" fill="none" stroke="${dot}" stroke-opacity=".45" stroke-width="${3*s}"/>`:'')+
 `<text x="${W/2+(dot?16*s:0)}" y="${h/2+1*s}" font-family="MS" font-weight="600" font-size="${27*s}" fill="${color}" text-anchor="middle" dominant-baseline="middle">${txt}</text>`;}
function flower(cx,cy,r,c1,c2,c3){let p='';for(let i=0;i<8;i++){const a=i*Math.PI/4;p+=`<ellipse cx="${cx+Math.cos(a)*r*.55}" cy="${cy+Math.sin(a)*r*.55}" rx="${r*.42}" ry="${r*.2}" transform="rotate(${i*45} ${cx+Math.cos(a)*r*.55} ${cy+Math.sin(a)*r*.55})" fill="${i%2?c1:c2}"/>`;}
 return p+`<circle cx="${cx}" cy="${cy}" r="${r*.26}" fill="${c3}"/><circle cx="${cx}" cy="${cy}" r="${r*.11}" fill="#fff"/>`;}
const D={
 'news-surjo-nil':(W,H,s)=>{const B=26*s;return `<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0A69C6"/><stop offset="1" stop-color="#00A6E0"/></linearGradient></defs>
  <path fill-rule="evenodd" fill="url(#g)" d="M0 0H${W}V${H}H0Z M${B} ${B}V${H-B}H${W-B}V${B}Z"/>
  <rect x="${B+7*s}" y="${B+7*s}" width="${W-2*B-14*s}" height="${H-2*B-14*s}" fill="none" stroke="#fff" stroke-opacity=".85" stroke-width="${2.5*s}"/>
  ${[[0,0,1,1],[W,0,-1,1],[0,H,1,-1],[W,H,-1,-1]].map(([x,y,a,b])=>`<path d="M${x} ${y+b*110*s}V${y}H${x+a*110*s}" fill="none" stroke="#E5252A" stroke-width="${B*2}"/>`).join('')}
  ${tab(W,s,'সর্বশেষ সংবাদ',{fill:'#012E5C',color:'#fff',dot:'#FF3B3F',w:330})}`;},
 'news-nokshi-lalsobuj':(W,H,s)=>{const B=22*s,i=B+12*s;return `<path fill-rule="evenodd" fill="#006A4E" d="M0 0H${W}V${H}H0Z M${B} ${B}V${H-B}H${W-B}V${B}Z"/>
  <rect x="${B}" y="${B}" width="${W-2*B}" height="${H-2*B}" fill="none" stroke="#F42A41" stroke-width="${4*s}"/>
  <rect x="${i}" y="${i}" width="${W-2*i}" height="${H-2*i}" fill="none" stroke="#FFF4D6" stroke-width="${3.5*s}" stroke-dasharray="${14*s} ${9*s}" stroke-linecap="round"/>
  <rect x="${B/2}" y="${B/2}" width="${W-B}" height="${H-B}" fill="none" stroke="#FFD24A" stroke-width="${2.5*s}" stroke-dasharray="${5*s} ${11*s}" stroke-linecap="round"/>
  ${[[B,B],[W-B,B],[B,H-B],[W-B,H-B]].map(([x,y])=>`<circle cx="${x}" cy="${y}" r="${30*s}" fill="#006A4E" stroke="#FFF4D6" stroke-width="${2.5*s}"/>`+flower(x,y,27*s,'#F42A41','#FFD24A','#F42A41')).join('')}
  ${tab(W,s,'আজকের খবর',{fill:'#F42A41',color:'#fff',w:260})}`;},
 'news-bishesh-sonali':(W,H,s)=>{const B=30*s,g='#D9A93B';let o='';
  for(const [x,y,a,b] of [[B,B,1,1],[W-B,B,-1,1],[B,H-B,1,-1],[W-B,H-B,-1,-1]]) o+=`<path d="M${x} ${y+b*70*s}V${y}H${x+a*70*s} M${x+a*12*s} ${y+b*46*s}V${y+b*12*s}H${x+a*46*s}" fill="none" stroke="${g}" stroke-width="${5*s}"/><rect x="${x+a*20*s-6*s}" y="${y+b*20*s-6*s}" width="${12*s}" height="${12*s}" fill="${g}" transform="rotate(45 ${x+a*20*s} ${y+b*20*s})"/>`;
  return `<path fill-rule="evenodd" fill="#0B1F3A" d="M0 0H${W}V${H}H0Z M${B} ${B}V${H-B}H${W-B}V${B}Z"/>
  <rect x="${B/2}" y="${B/2}" width="${W-B}" height="${H-B}" fill="none" stroke="${g}" stroke-width="${2*s}"/>
  <rect x="${B}" y="${B}" width="${W-2*B}" height="${H-2*B}" fill="none" stroke="${g}" stroke-width="${3*s}"/>${o}
  <defs><linearGradient id="gg" x1="0" x2="1"><stop offset="0" stop-color="#B8862A"/><stop offset=".5" stop-color="#F3D27A"/><stop offset="1" stop-color="#B8862A"/></linearGradient></defs>
  ${tab(W,s,'বিশেষ প্রতিবেদন',{fill:'url(#gg)',color:'#0B1F3A',w:340,stroke:'#0B1F3A'})}`;},
 'opinion-sompadokiyo':(W,H,s)=>{const B=24*s;return `<path fill-rule="evenodd" fill="#F5EBDD" d="M0 0H${W}V${H}H0Z M${B} ${B}V${H-B}H${W-B}V${B}Z"/>
  <rect x="${B}" y="${B}" width="${W-2*B}" height="${H-2*B}" fill="none" stroke="#0B2746" stroke-width="${4*s}"/>
  <rect x="${B/2-1.5*s}" y="${B/2-1.5*s}" width="${W-B+3*s}" height="${H-B+3*s}" fill="none" stroke="#C9A24A" stroke-width="${1.6*s}"/>
  ${[[B,B],[W-B,B],[B,H-B],[W-B,H-B]].map(([x,y])=>`<rect x="${x-9*s}" y="${y-9*s}" width="${18*s}" height="${18*s}" fill="#C9A24A" stroke="#0B2746" stroke-width="${3*s}" transform="rotate(45 ${x} ${y})"/>`).join('')}
  <path d="M${W/2-90*s} ${H-B/2}H${W/2+90*s}" stroke="#0B2746" stroke-width="${4*s}"/><circle cx="${W/2}" cy="${H-B/2}" r="${7*s}" fill="#C9A24A"/>
  ${tab(W,s,'সম্পাদকীয়',{fill:'#0B2746',color:'#F5EBDD',w:260})}`;},
 'opinion-bishleshon':(W,H,s)=>{const B=12*s,L=34*s;let dots='';for(let r=0;r<4;r++)for(let c=0;c<4;c++){dots+=`<circle cx="${W-B-22*s-c*20*s}" cy="${B+170*s+r*20*s}" r="${3.4*s}" fill="#13A5A0" fill-opacity="${.9-.18*(r+c)/2}"/><circle cx="${L+22*s+c*20*s}" cy="${H-B-22*s-r*20*s}" r="${3.4*s}" fill="#0A69C6" fill-opacity="${.9-.18*(r+c)/2}"/>`;}
  return `<defs><linearGradient id="t" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0A69C6"/><stop offset="1" stop-color="#13A5A0"/></linearGradient></defs>
  <path fill-rule="evenodd" fill="url(#t)" d="M0 0H${W}V${H}H0Z M${L} ${B}V${H-B}H${W-B}V${B}Z"/>
  <rect x="${L+10*s}" y="${B+10*s}" width="${W-L-B-20*s}" height="${H-2*B-20*s}" fill="none" stroke="#fff" stroke-opacity=".7" stroke-width="${2*s}"/>${dots}
  ${tab(W,s,'বিশ্লেষণ',{fill:'#fff',color:'#0A69C6',w:220,stroke:'#13A5A0'})}`;},
 'opinion-khola-kolom':(W,H,s)=>{const B=22*s;const nib=(x,y,k)=>`<g transform="translate(${x} ${y}) scale(${k}) rotate(45)"><path d="M0 -40 C14 -22 16 0 10 18 L0 40 L-10 18 C-16 0 -14 -22 0 -40Z" fill="#B35A00"/><circle r="5" cy="2" fill="#FFF3E0"/><path d="M0 7V38" stroke="#FFF3E0" stroke-width="2.5"/></g>`;
  return `<defs><linearGradient id="a" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F2A33A"/><stop offset="1" stop-color="#E4572E"/></linearGradient></defs>
  <path fill-rule="evenodd" fill="url(#a)" d="M0 0H${W}V${H}H0Z M${B} ${B}V${H-B}H${W-B}V${B}Z"/>
  <path d="M${B} ${B+140*s}V${B}H${B+140*s} M${W-B} ${H-B-140*s}V${H-B}H${W-B-140*s}" fill="none" stroke="#fff" stroke-width="${6*s}" stroke-linecap="round"/>
  ${tab(W,s,'খোলা কলম',{fill:'#FFF3E0',color:'#B35A00',w:270,stroke:'#E4572E'})}
  ${nib(W/2-112*s,29*s,.62*s)}`;},
};
(async()=>{const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const pg=await b.newPage();
for(const [name,fn] of Object.entries(D)) for(const [W,H] of SIZES){const s=Math.min(W,H)/1080*(H===1920?1:1);
 await pg.setViewportSize({width:W,height:H});
 await pg.setContent(`<style>@font-face{font-family:MS;font-weight:600;src:url(${F}meeha-sans-bn-600.woff2)}@font-face{font-family:MSerif;font-weight:700;src:url(${F}meeha-serif-bn-700.woff2)}html,body{margin:0;background:transparent}</style><svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}">${fn(W,H,s)}</svg>`);
 await pg.evaluate(()=>document.fonts.ready);await pg.waitForTimeout(80);
 await pg.screenshot({path:`out/${name}-${W}x${H}.png`,omitBackground:true});}
await b.close();})();
