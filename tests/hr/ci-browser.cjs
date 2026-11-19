'use strict';
const run=require('./browser.cjs');
if(process.env.SN_HR_BROWSER_NATIVE!=='YES')throw Error('Native HTTP mode required');
run({base:'http://127.0.0.1:8877',output:process.env.SN_APPROVAL_OUTPUT+'/browser'}).catch(error=>{
 console.error('BROWSER_FAILURE_KIND '+String(error.name).replace(/[^A-Za-z]/g,''));
 if(/^[A-Za-z0-9 _/()+,.:-]{1,180}$/.test(error.message))console.error('BROWSER_ASSERTION '+error.message);
 const lines=[...String(error.stack).matchAll(/(?:browser|ci-browser)\.cjs:([0-9]+):([0-9]+)/g)].map(m=>[Number(m[1]),Number(m[2])]);
 console.error('BROWSER_CODE_LINES '+JSON.stringify(lines));process.exitCode=1;
});
