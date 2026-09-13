import test from 'node:test';
import assert from 'node:assert/strict';
import {locationPoint,freshLocation} from './location.mjs';
test('location is validated and rounded before uploading',()=>{
 assert.deepEqual(locationPoint({coords:{latitude:30.123456,longitude:-94.987654,accuracy:25}}).center,[30.123,-94.988]);
 assert.throws(()=>locationPoint({coords:{latitude:100,longitude:0}}));
 assert.throws(()=>locationPoint({coords:{latitude:NaN,longitude:0}}));
});
test('current location expires while fixed places remain usable',()=>{
 assert.equal(freshLocation({locationMode:'current',locationUpdatedAt:100000},100030),true);
 assert.equal(freshLocation({locationMode:'current',locationUpdatedAt:100000},130000),false);
 assert.equal(freshLocation({locationMode:'current',locationUpdatedAt:140000},130000),false);
 assert.equal(freshLocation({locationMode:'fixed'},130000),true);
});
