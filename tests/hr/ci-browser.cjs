'use strict';
const run=require('./browser.cjs');
if(process.env.SN_HR_BROWSER_NATIVE!=='YES')throw Error('Native HTTP mode required');
run({base:'http://127.0.0.1:8877',output:process.env.SN_APPROVAL_OUTPUT+'/browser'}).catch(error=>{console.error(error.message);process.exitCode=1;});
