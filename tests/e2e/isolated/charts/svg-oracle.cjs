const assert=require('node:assert/strict'),fixtures=require('./fixtures.cjs');
// A 1 px axis stroke can place its center 0.5 px from a zero-width rectangle edge.
// Freeze a subpixel allowance before execution; bar amplitude/scale has tighter checks.
const close=(a,b,tolerance=.75)=>assert(Number.isFinite(a)&&Number.isFinite(b)&&Math.abs(a-b)<=tolerance,`Coordinate differs: ${a} versus ${b}`);
function check(observed,layer,table){
 const expected=fixtures.expected(table,layer.chart.options.kind,layer.chart.binding.series_columns),bars=observed.items.filter(item=>item.id.startsWith(`${layer.id}:bar:`)),barMap=new Map(bars.map(item=>[item.id,item])),itemMap=new Map(observed.items.map(item=>[item.id,item]));
 assert.equal(bars.length,expected.length);assert.equal(new Set(observed.items.map(i=>i.id)).size,observed.items.length);
 const zero=observed.items.find(i=>i.id===`${layer.id}:axis:zero`);assert(zero,'Zero axis semantic ID missing');
 const horizontal=layer.chart.options.kind.startsWith('bar'),axis=observed.items.find(i=>i.id===`${layer.id}:axis:${horizontal?'x':'y'}`);assert(axis,'Extent axis missing');
 assert((horizontal?axis.width:axis.height)>=40,'Plot dimension below contract minimum');
 const min=layer.chart.options.domain_min,max=layer.chart.options.domain_max;
 // Equal category bands are fixed by row order and the measured plot extent.
 // Outer gap is split across both sides; grouped series subdivide the remaining
 // band equally and split their own gap symmetrically. Stacks share one slot.
 const categoryAxis=itemMap.get(`${layer.id}:axis:${horizontal?'y':'x'}`);assert(categoryAxis,'Categorical extent axis missing');
 const categoryStart=horizontal?categoryAxis.y:categoryAxis.x,categorySpan=horizontal?categoryAxis.height:categoryAxis.width;
 assert(Number.isFinite(categoryStart)&&categorySpan>=40,'Categorical plot extent invalid');
 const {band_gap:bandGap,series_gap:seriesGap}=layer.chart.options;assert(Number.isFinite(bandGap)&&Number.isFinite(seriesGap),'Gap fractions required');
 const grouped=layer.chart.options.kind.includes('grouped'),series=layer.chart.binding.series_columns,rowCount=table.rows.length,band=rowCount?categorySpan/rowCount:0,inner=band*(1-bandGap),slot=inner/(grouped?series.length:1),thickness=slot*(grouped?1-seriesGap:1);
 const rowIndex=new Map(table.rows.map((row,index)=>[row.id,index])),seriesIndex=new Map(series.map((id,index)=>[id,index])),rows=new Map();
 const categoricalTolerance=value=>Math.min(.012,Math.max(.0003,Math.abs(value)*.005));
 let scale=min!==null&&min!==undefined&&max!==null&&max!==undefined?(horizontal?axis.width:axis.height)/(max-min):undefined;
 if(scale!==undefined)close(horizontal?zero.x:zero.y,horizontal?axis.x-min*scale:axis.y+max*scale);
 const rgb=hex=>`rgb(${parseInt(hex.slice(1,3),16)}, ${parseInt(hex.slice(3,5),16)}, ${parseInt(hex.slice(5,7),16)})`;
 for(const value of expected){const actual=barMap.get(`${layer.id}:${value.id}`);assert(actual,'Missing bar '+value.id);assert.equal(actual.fill,rgb(value.color));
  for(const k of ['x','y','width','height'])assert(Number.isFinite(actual[k]));assert(actual.width>=0&&actual.height>=0);
  assert(actual.x>=-.12&&actual.y>=-.12&&actual.x+actual.width<=observed.width+.12&&actual.y+actual.height<=observed.height+.12,'Bar escapes exported viewBox');
  const baseline=horizontal?zero.x:zero.y,low=horizontal?actual.x:actual.y,length=horizontal?actual.width:actual.height;
  if(value.value!==0){const current=length/Math.abs(value.end-value.start);if(scale===undefined)scale=current;else close(current,scale,.012);}
  const start=horizontal?baseline+value.start*(scale??0):baseline-value.start*(scale??0),end=horizontal?baseline+value.end*(scale??0):baseline-value.end*(scale??0);
  close(low,Math.min(start,end));close(length,Math.abs(end-start));
  const ri=rowIndex.get(value.row),ci=seriesIndex.get(value.column),categoryPosition=horizontal?actual.y:actual.x,categoryThickness=horizontal?actual.height:actual.width;
  const offset=(band-inner)/2+(grouped?ci*slot+(slot-thickness)/2:0),wanted=categoryStart+ri*band+offset;
  close(categoryPosition,wanted);close(categoryThickness,thickness,categoricalTolerance(thickness));
  const entries=rows.get(ri)??[];entries.push({ci,position:categoryPosition,origin:categoryPosition-offset});rows.set(ri,entries);
 }
 // Tight relative spacing removes axis-stroke center offsets, while retaining
 // strict category and subseries order even when each band is below one pixel.
 let previousRow;
 for(const [ri,entries] of [...rows].sort((a,b)=>a[0]-b[0])){
  entries.sort((a,b)=>a.ci-b.ci);let previousSeries;
  for(const entry of entries){if(previousSeries){const gap=grouped?(entry.ci-previousSeries.ci)*slot:0;close(entry.position-previousSeries.position,gap,categoricalTolerance(slot));if(grouped)assert(entry.position>previousSeries.position,'Grouped subseries order changed');}previousSeries=entry;}
  const representative=entries[0];if(previousRow){const gap=(ri-previousRow.ri)*band;close(representative.origin-previousRow.origin,gap,categoricalTolerance(band));assert(representative.origin>previousRow.origin,'Category order collapsed or permuted');}previousRow={ri,origin:representative.origin};
 }
 const categoryItems=observed.items.filter(item=>item.id.startsWith(`${layer.id}:category:`)),categoryColumn=table.columns.findIndex(column=>column.id===layer.chart.binding.category_column),labelLimit=layer.chart.style.category_label_limit??24;
 assert(categoryColumn>=0,'Selected category column missing in fixture');
 if(layer.chart.style.category_labels===false)assert.equal(categoryItems.length,0,'Disabled category labels still drawn');
 else{
  if(rowCount<=Math.max(1,Math.floor(categorySpan/((layer.chart.style.fontSize??16)*1.5))))assert.equal(categoryItems.length,rowCount,'Small fixture categories missing');
  else assert(categoryItems.length>0,'All sampled category labels missing');
  for(const item of categoryItems){const id=item.id.slice(`${layer.id}:category:`.length),ri=rowIndex.get(id);assert(ri!==undefined,'Unknown semantic category ID');const raw=String(table.rows[ri].values[categoryColumn]);if([...raw].length<=labelLimit)assert.equal(item.text,raw,'Category text no longer matches original fixture');else assert(item.text.endsWith('…')&&item.text.startsWith([...raw].slice(0,labelLimit-1).join('')),'Category shortening changed meaning');const ink=item.ink;assert(ink&&['x','y','width','height'].every(key=>Number.isFinite(ink[key]))&&ink.width>=0&&ink.height>=0&&typeof ink.empty==='boolean','Finite category ink required; no BBox fallback');if(ink.empty){assert(ink.width===0&&ink.height===0&&!item.text.trim(),'Empty ink cannot conceal visible category text');continue;}assert(ink.width>0&&ink.height>0,'Visible category ink must have positive dimensions');const position=horizontal?ink.y:ink.x,size=horizontal?ink.height:ink.width,start=categoryStart+ri*band;assert(position>=start-.75&&position+size<=start+band+.75,'Category text outside its semantic row band');}
 }
 const multicolor=layer.chart.options.kind.includes('multicolor'),legend=multicolor?table.rows.slice(0,12).map(r=>({id:r.id,label:String(r.values[0])})):layer.chart.binding.series_columns.map(id=>({id,label:table.columns.find(c=>c.id===id).label}));
 if(layer.chart.style.legend!==false)for(const entry of legend){const item=observed.items.find(i=>i.id===`${layer.id}:legend:${entry.id}`);assert(item,'Missing semantic legend');assert.equal(item.text,entry.label);}
 return {...observed,expected,layer:layer.id,kind:layer.chart.options.kind,background:layer.chart.style.background??'#ffffff',proportional:layer.chart.options.kind.includes('proportional')};
}
module.exports={check,close};
