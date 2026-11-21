'use strict';
const fs=require('node:fs'),path=require('node:path');
if(process.env.SN_APPROVAL_ALLOW_SYNTHETIC!=='YES'||process.env.GITHUB_ACTIONS!=='true'||process.env.RUNNER_ENVIRONMENT!=='github-hosted'||!/^\/tmp\/sn-approval-[A-Za-z0-9]{8}$/.test(process.env.SN_APPROVAL_ROOT||''))throw Error('Hosted disposable context required');
require('./staged-gateway-ui.cjs')({playwright:process.env.SN_PLAYWRIGHT_MODULE}).then(result=>fs.writeFileSync(path.join(process.env.SN_APPROVAL_OUTPUT,'staged243-browser.json'),JSON.stringify({...result,synthetic:true},null,2))).catch(e=>{console.error(e.stack);process.exitCode=1;});
