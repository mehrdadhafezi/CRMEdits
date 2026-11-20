'use strict';
const fs=require('node:fs'),path=require('node:path'),run=require('./product-settings-browser.cjs');
if(process.env.SN_HR_BROWSER_NATIVE!=='YES'||process.env.SN_APPROVAL_ALLOW_SYNTHETIC!=='YES'||process.env.GITHUB_ACTIONS!=='true'||process.env.RUNNER_ENVIRONMENT!=='github-hosted')throw Error('Generated native HTTP runner required');
const root=fs.realpathSync(process.env.SN_APPROVAL_ROOT);
if(!/^\/tmp\/sn-approval-[A-Za-z0-9]{8}$/.test(root))throw Error('Generated native root required');
const fixture=JSON.parse(fs.readFileSync(path.join(root,'product241-fixture.json')));
if(fixture.synthetic!==true||fixture.version!=='2.0.241')throw Error('Synthetic product fixture required');
run({base:'http://127.0.0.1:8877',fixture,output:process.env.SN_APPROVAL_OUTPUT+'/product-browser'}).catch(error=>{
 console.error('BROWSER_FAILURE_KIND '+String(error.name).replace(/[^A-Za-z]/g,''));
 const headline=String(error.message).split(/\r?\n/,1)[0].replace(/https?:\/\/\S+|[a-f0-9]{24,}/gi,'[redacted]');
 if(/^[A-Za-z0-9 _/()+,.:\[\]-]{1,180}$/.test(headline))console.error('BROWSER_ASSERTION '+headline);
 const lines=[...String(error.stack).matchAll(/(?:product-settings-browser|ci-browser)\.cjs:([0-9]+):([0-9]+)/g)].map(m=>[Number(m[1]),Number(m[2])]);
 console.error('BROWSER_CODE_LINES '+JSON.stringify(lines));process.exitCode=1;
});
