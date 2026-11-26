'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
if(!process.env.SN_PLAYWRIGHT_MODULE||!process.env.SN_APPROVAL_OUTPUT||!process.env.SN_APPROVAL_ROOT)throw Error('Explicit synthetic browser target required');
const root=path.resolve(__dirname,'../..');
require('./gateway-catalog-browser.cjs')({playwright:process.env.SN_PLAYWRIGHT_MODULE,jquery:path.join(process.env.SN_APPROVAL_ROOT,'wordpress/wp-includes/js/jquery/jquery.min.js'),baseline:path.join(process.env.SN_APPROVAL_ROOT,'gateway245-baseline')}).then(result=>{
 result.version='2.0.246';result.manager_js_sha256=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'assets/js/public-manager.js'))).digest('hex');result.catalog_js_sha256=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'assets/js/sales-catalog-staff.js'))).digest('hex');
 fs.writeFileSync(path.join(process.env.SN_APPROVAL_OUTPUT,'gateway245-browser.json'),JSON.stringify(result,null,2));
}).catch(e=>{console.error(e);process.exitCode=1;});
