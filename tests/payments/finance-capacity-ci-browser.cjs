'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
if(!process.env.SN_PLAYWRIGHT_MODULE||!process.env.SN_APPROVAL_OUTPUT)throw Error('Explicit synthetic browser runtime required');
require('./finance-capacity-ui.cjs')({playwright:process.env.SN_PLAYWRIGHT_MODULE}).then(result=>{
 result.receipt_js_sha256=crypto.createHash('sha256').update(fs.readFileSync(path.resolve(__dirname,'../../assets/js/receipt-review.js'))).digest('hex');
 fs.writeFileSync(path.join(process.env.SN_APPROVAL_OUTPUT,'finance244-browser.json'),JSON.stringify(result,null,2));
}).catch(error=>{console.error(error);process.exitCode=1;});
