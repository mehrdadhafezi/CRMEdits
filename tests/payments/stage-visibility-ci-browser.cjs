'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
if(!process.env.SN_PLAYWRIGHT_MODULE||!process.env.SN_APPROVAL_OUTPUT||!process.env.SN_APPROVAL_ROOT)throw Error('Explicit synthetic stage browser target required');
const root=path.resolve(__dirname,'../..');
require('./stage-visibility-browser.cjs')({playwright:process.env.SN_PLAYWRIGHT_MODULE,jquery:path.join(process.env.SN_APPROVAL_ROOT,'wordpress/wp-includes/js/jquery/jquery.min.js')}).then(result=>{
 result.version='2.0.246';result.production_touched=false;result.actual_runtime_js=true;
 for(const [field,name]of [['stage_js_sha256','payment-transactions.js'],['seller_js_sha256','public-seller.js'],['manager_js_sha256','public-manager.js']])result[field]=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'assets/js',name))).digest('hex');
 fs.writeFileSync(path.join(process.env.SN_APPROVAL_OUTPUT,'stage246-browser.json'),JSON.stringify(result,null,2));
}).catch(e=>{console.error(e);process.exitCode=1;});
