// Analytic inputs owned by QA. No product module imports.
const kinds=['column','bar','column-grouped','bar-grouped','column-stacked','bar-stacked','column-proportional','bar-proportional','column-multicolor','bar-multicolor'];
const palette=['#d02030','#168047','#245dcc','#a040b0','#ed8a16','#008080','#444444','#908000','#b04060','#486080','#706040','#307060'];
function fixture({negative=false,dense=false}={}) {
 const count=dense?1000:4,series=dense?12:2;
 return {columns:[{id:'category',label:'Categoría'},...Array.from({length:series},(_,i)=>({id:`s${i}`,label:`Serie ${i+1}`}))],rows:Array.from({length:count},(_,r)=>({id:`r${r}`,values:[dense?`Año ${2020+r} é`:r===3?2026:`Año ${2020+(r===1?0:r)} é`,...Array.from({length:series},(_,c)=>dense?1+(r+c)%8:r===2?null:r===3?0:(negative&&r===1?-1:1)*(c+1)*(r+1)*10)]}))};
}
function expected(table,kind,series) {
 const stacked=kind.includes('stacked')||kind.includes('proportional'),proportional=kind.includes('proportional'),horizontal=kind.startsWith('bar'),multicolor=kind.includes('multicolor');
 const bars=[];
 table.rows.forEach((row,r)=>{let positive=0,negative=0;const indices=series.map(id=>table.columns.findIndex(c=>c.id===id));const total=indices.reduce((n,i)=>n+(row.values[i]??0),0);for(let s=0;s<indices.length;s++){const value=row.values[indices[s]];if(value===null)continue;if(typeof value!=='number'||!Number.isSafeInteger(value))throw Error('QA fixture requires safe exact numeric values');if(proportional&&value<0)throw Error('Negative proportion');const amount=proportional?(total?100*value/total:0):value;const start=stacked?(value<0?negative:positive):0;const end=start+amount;if(stacked){if(value<0)negative=end;else positive=end;}bars.push({id:`bar:${row.id}:${series[s]}`,row:row.id,column:series[s],value,start,end,color:palette[(multicolor?r:s)%palette.length],horizontal});}});
 return bars;
}
module.exports={kinds,palette,fixture,expected};
