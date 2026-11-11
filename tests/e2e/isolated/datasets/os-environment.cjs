// OS path expansion only; no app settings or credentials.
function applyTo(target, source=process.env) { for (const [key,value] of Object.entries(source)) if (key.toLowerCase()==='systemdrive' && value) target[key]=value; return target; }
module.exports={applyTo};
