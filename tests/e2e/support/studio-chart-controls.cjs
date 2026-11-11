// Select names include descendant option text in implicit HTML labels.
// Match the label prefix, restricted to the combobox role and the dialog scope.
function chartSelect(scope,label){
 if(typeof label!=='string'||!label)throw new TypeError('Chart select label required');
 const escaped=label.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
 return scope.getByRole('combobox',{name:new RegExp('^'+escaped)});
}
module.exports={chartSelect};
