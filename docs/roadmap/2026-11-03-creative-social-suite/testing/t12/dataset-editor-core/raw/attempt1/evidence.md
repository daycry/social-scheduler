# T12 pure dataset editor core — implementation evidence

2026-11-06, branch feature/creative-social-suite. Only2assigned sources created; bothbaselineABSENT in baseline.json. Root owns ledger/docs. No HTTP/DB/env/native/browser/build/install/provider run, no commits/push.

Final verification: `node --max-old-space-size=512 --experimental-test-isolation=none --test tests/frontend/studio-dataset-table.test.mjs`: **26/26PASS**,0fail/cancelled/skipped/todo,409.5156ms, raw final26.stdout.txt + JSON exit0. Nodev22.23.2; bothsources node--check exit0. TwoSHA stable before/afterfinal. 512MiB is V8oldspace cap, not a hard process/RSS/WindowsJob cap. No global coveragepercent or full frontend acceptance claimed.

## TDD records

All runner commands and rawstdout/stderr are saved individually. `run.py NAME PATTERN` records command/exit/duration and runs onlymatchingtest; `manifest.json` lists20genuineREDs and6firstPASScharacterizations. No imported-runner error or firstPASS is relabelledTDD.

| RED | Criterion and actual failure | GREEN |
|---|---|---|
| red01 | decimal lexemes/typedcells: columns[] insteadexpected column | green01 |
| red02 | closed schema/IDs/cells/limits: Missing expected exception | green02 |
| red03 | duplicateJSONkeys: Missing expected exception | green03 |
| red04-corrected | canonical52Mbudget: Missing expected exception | green04 |
| red05 | serializer emitted taggedobjects insteadnumbertokens | green05 |
| red06 | celltype emptystring insteadnumber | green06 |
| red07 | edit retained0.0 insteadliteral | green07 |
| red08 | structuralblanktable0columns instead2 | green08 |
| red09 | copy reusedc1 identity | green09 |
| red10 | literalreplacement unchanged | green10-fixed |
| red11 | conversionpreview0changes instead1 | green11 |
| red12-corrected-history | editreal producedone but historycommitretained0.0 | green12-corrected-history |
| red13 | transpose keptlabelsA/B insteadColumna/Fila1/Fila2 | green13 |
| red14-corrected | missingpage dataset metadata | green14 |
| red15 | assemblerappendundefined insteadnull | green15 |
| red16 | mixedsnapshots accepted, Missing expected exception | green16 |
| red18 | deferredserializerobservedchanged caller insteadoriginal001 | green18 |
| red19 | JSONcellobject masqueraded internalnumber | green19 |
| red23 | tablecap missing, genericparseerror insteadbytecap | green23 |
| red24 | pageUTF8capmissing, schemaerror insteadbytecap | green24 |

Preserved exclusions: red04 initially lacked exportedvalidator; addedno-opscaffold then correctedREDbeforevalidation. red14initial assertion dereferencedmissingdataset; fixedassertion beforedecoder implementation. red12original depended on editstub: actualhistoryimplementation was removed (fragment history-before-corrected-red.js.txt), genuineRED rerun aftereditreal, history rewrittenasboundedstates+index, thenGREEN. Do not countoriginalred12. green10initialfailedimmutabletoken reference: snapshot nowretainsvalidfrozen tokens.

CharacterizationsfirstPASS:17exacttrailingnewline rejection;20Unicode500/10000codepoints andcallerimmutability;21JSONgrammar/prototypeisolation/depth;22invalidbulkresult/emptytable;25surrogatepairat64KiB boundary under8MiBexact;26canonical52Mexact/+1 withsharedstrings androwchunkaccounting (no52MwholeJSONstring). These arenotRED. Duringimplementation some PythonreadsusedWindowsdefaultencoding; repairedsources/testUnicode and switchedallreads toexplicitUTF8 beforecurrentfullverification; rawearlierREDs remainoriginal. SchemaRED02 firstassert alreadyfailedonunknownfield beforefurtherdatasets, do not claimindividualREDforeachschemafixture.

## Criteria coverage

E1/E2: genericstrictJSONparser preservesdecimallexemes, including0.0/-0.0/9007199254740993; duplicatekeys rejected, depth16, tableinput52Mbytes/pageinput8MiB beforeparse with64KiBincrementalUTF8, schema/IDs/UTF16/control/codepoint/finite/canonicalbudgetchecks. InternalnumberWeakSet preventsJSONobjecttokenspoof. Strings/formulasneverexecuted; noimplicitconversion.

E3: immutablecell/header/row/column/copy/literalreplace/fill/transpose andexplicitatomiccolumnconversion; sameuntouchedrowobjects retained. Fillnullonlydefault, emptystringsoptin. Transpose preservesoriginalheadersasfirstcolumnvalues, newIDsallentities, oldrows<=99, emptyrowtablevalid. Historyatmost30pastoperations, branchinvalidatesredo, nofulltextclonespercell; mutablesourcescopiednotfrozen.

E4: rawpageJSON preservescelllexemes, metadataintegerJSsafe,6MiBrows and8MiBwire caps; assemblerpinsdataset/revision/SHA/brand/columns/total, exactprogressiveoffsets, duplicateIDsandcumulative52Mrejectedbeforestatecommit. Nestedcurrentdatasetrevisionmayincreaseforhistoricalsnapshot. Parsing/cursorfailuresleavestateunchanged; no tableuntilcomplete.

## Performance scope

performance-v2.json is an informationalpureNodeprobe oncurrenthistoryrewrite,1000x100table/31edits/30historydepth, confirmscolumns anduntouchedlastrowshared. memoryfieldsareprocess.memoryUsage()atend, notpeak oracceptancehardlimit. Earlierperformance.json remainspriorhistoryversion evidence. NoUIrendering/52MBeditorstressclaim. Refactorlocalstringvalidationdedup loweredexistingoversizedsharedstringbudgettestfrom~3.1sto~68mswithoutalteringvalidation. Literalreplace computesresultcodepointlength beforebuilding potentiallyhuge strings.

ReviewA/B(/C/Dbyselector) andUIQA remainpending; **T12 notclosed**. See api.md forstableexports. Rootmustadd2sourcepaths toT12Archivos andcopyREDrecordsintoledger.
