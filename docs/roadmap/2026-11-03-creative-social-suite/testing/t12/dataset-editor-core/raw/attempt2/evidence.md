# T12 dataset editor core — correction attempt2

2026-11-06. Onlytwoassigned sources changed. Baseline exactattempt1source5be12258…aff66f/tested52613d…4af1 captured underbefore/ andbefore-manifest.json. All185attempt1artifacts validated unchanged beforeandafter; originalmanifest/evidence/full.diff remainimmutable. Noledger/docs/UI/otherWIP edited; no native/socket/build/server/provider work, no commits/push.

## Verified review gaps and minimal fixes

B1Important (sparse/inheritedarraydata): externalgraphnowcaptured throughownpropertydescriptors. Listsrequireownlength+ownenumerablevaluepropertyforeachindex, noholes/accessors/extraproperties; neithermap/forEachnor inheritedindicesaretrusted beforecapture. This appliescolumns,rows,values. Aftercapture, ordinarydensearraysarevalidated normally. Fourregressionstargetdirectvalidation, serializationandhistory, inheritedindexfallbacks, andgetterlistentries.

B2Important (frozenexternalgetteralias): schemaJSONdata excludesaccessors, rejectsdescriptorbeforeevaluatinggetter. Capturesrecorddatafields once andvalidatescapturedprimitivevalues; thereisno validategetter-thenreadgetteragainTOCTOU. Snapshotsmaterializeownid/label/value data; onlyWeakSet-brandedcorecolumnarrays/rows/tablesmaybereused. Object.isFrozenexternaltrustremoved. Callerinputisnotmutatedorfrozen; coreinternalstructuralsharingretained. No new publicexports/signaturechange.

## TDD raw evidence

| Run | One criterion | Actual RED | GREEN |
|---|---|---|---|
| red01 | deletedvaluesindex1 | Missing expected exception TypeError atT254 | green01PASS1/1 |
| red02 | inheritedindicesmustnotfillJSONlists | Missing expected exception TypeError atT262 | green02PASS1/1 |
| red03 | frozenexternalgettercolumn | Missing expected exception TypeError atT273 | green03PASS1/1 |
| red04 | record/listaccessors | Missing expected exception TypeError atT282 | green04PASS1/1 |

Allfourtestsaddedandindividuallyexecutedbeforeruntimefix. Original26testsbyte-identicalprefixpreserved. No setup/importerroredrunsandno artificialfirstgreenRED inattempt2.

## Current verification

`node --max-old-space-size=512 --experimental-test-isolation=none --test tests/frontend/studio-dataset-table.test.mjs`: **30/30PASS**,0fail/cancelled/skipped/todo,583.342ms. neighbors30.stdout.txt +neighbors30.json realexit0. Bothsources `node --check` exit0. 512MiBhereisV8oldspacecap, nothardRSSorWindowsJoblimit. SourceSHAconfirmed unchanged whilepackaging.

Performance rerun justifiedbycapture/cloningcostchange: identicalpureNode1000x100/31edits probe oncurrentcode,30undo,columns+untouchedlastrowshared. Mean17.2283ms/max25.5275ms,total614.4892ms. process.memoryUsage()atend RSS68,476,928B/heapUsed17,748,456B; **notpeak** orhardbudgetacceptance. Priorattempt1probe17.0380msmean/max26.7673ms isinformational baseline, no statisticallyrigorousbenchmarkclaim.

`delta.diff` againstreviewedattempt1; `full.diff` cumulativeagainstABSENT; source/ exactfinal2files. Re-reviewfreshA2/B2withA1/C1criteria preserved, conditionalC/Dselector andlaterUIQA remainroot-owned. T12integralstillopen8/23; notcompletion/handoffglobal.
