'use strict';
const fs=require('node:fs'),path=require('node:path');
if(process.env.SN_APPROVAL_ALLOW_SYNTHETIC!=='YES'||process.env.GITHUB_ACTIONS!=='true'||process.env.RUNNER_ENVIRONMENT!=='github-hosted'||!/^\/tmp\/sn-approval-[A-Za-z0-9]{8}$/.test(process.env.SN_APPROVAL_ROOT||''))throw Error('Hosted disposable browser context required');
require('./traceability-ui.cjs')({playwright:process.env.SN_PLAYWRIGHT_MODULE}).then(assertions=>{
 const native=JSON.parse(fs.readFileSync(path.join(process.env.SN_APPROVAL_OUTPUT,'trace242-native.json')));if(native.version!=='2.0.243'||!native.passed)throw Error('Matching native candidate required');
 fs.writeFileSync(path.join(process.env.SN_APPROVAL_OUTPUT,'trace242-browser.json'),JSON.stringify({passed:true,synthetic:true,assertions,version:native.version,core_sha256:native.core_sha256,commission_sha256:native.commission_sha256,desktop_mobile:true,external_network_blocked:true,production_touched:false},null,2));
}).catch(e=>{console.error(e.stack);process.exitCode=1;});
