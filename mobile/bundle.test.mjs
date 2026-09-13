import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync,readFileSync} from 'node:fs';
test('production source remains server-side and native app has dedicated identity',()=>{
 const config=JSON.parse(readFileSync('capacitor.config.json'));
 assert.equal(config.appId,'live.crimewatch.app');assert.equal(config.plugins.CapacitorHttp.enabled,true);assert.equal(config.server?.url,undefined);
 const builder=readFileSync('mobile/build.mjs','utf8');assert.match(builder,/html\|css\|js\|svg/);
 const privacy=readFileSync('ios/App/App/PrivacyInfo.xcprivacy','utf8');assert.match(privacy,/CA92\.1/);
 const project=readFileSync('ios/App/App.xcodeproj/project.pbxproj','utf8');assert.match(project,/PrivacyInfo\.xcprivacy in Resources/);
 assert.ok(existsSync('ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png'));
});
