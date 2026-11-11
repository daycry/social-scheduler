# T12 dataset library/editor candidate — 2026-11-06

Six assigned sources only; four files were ABSENT before work, Index/Editor exact baselines saved and CRLF preserved. `source-manifest.json` hashes and `full.diff`/final-source copies are complete. Root alone updates ledger/docs.

## TDD observed before implementation

Functional stubs existed to avoid import/syntax errors. Each listed command was a single `node --max-old-space-size=512 --experimental-test-isolation=none --test --test-name-pattern=<case> <file>` run before its minimal behavior implementation. Actual TAP originals `NN-*-red.log` and corresponding `green.log` are preserved. PowerShell redirected originals are UTF16LE; `normalized/` strictdecodes without replacement with original/derived SHA. The original tools combinedprobe14-ui-tools-red only reached its first assertion; it is not counted as evidence for the remaining tools: four separate RED commands14structure/15conversion/16literal/17transpose followed before those implementations.

| Prefix | Focal observed RED | GREEN |
|---|---|---|
|01|client list returned empty metadata instead of brand rows|1/1|
|02|raw page observer stayed[]; loader returnednull|1/1|
|03|save returnednull instead of multipart exact typedchunk result|1/1|
|04|upload returnednull, original file was not sent|1/1|
|05|metadata/history/archive/restore endpoint call list[]|1/1|
|06|newlocal table remainednull|1/1|
|07|initial list requests0, expected1|1/1|
|08|dirtyclose did not create discardprompt|1/1|
|09|selectedtable loads0, expected1; oldread fence absent|1/1|
|10|typed -0.0 edit remained emptytext|1/1|
|11|revisionconflict save notcalled, no preserved conflictdraft|1/1|
|12|write calls0; serialization/contextfence absent|1/1|
|13|history callsabsent; historic/current readonly notwired|1/1|
|14structure|addrow left1row expected2|1/1|
|15|conversionpreview stayednull|1/1|
|16|literal replace leftbad not30|1/1|
|17|transposepreview stayedfalse|1/1|
|18|importuploadcalls0|1/1|
|19|archived table remainededitable|1/1|
|20|copy stillselectedoriginaldataset instead of newdraft|1/1|
|21|unappliedcelltext notdirty|1/1|
|22|stagedheader notdirty|1/1|
|23|beforeunload handler undefined|1/1|
|24|visiblewindowlength0 expected20|1/1|
|25|switchcell replaced pendinginput withB|1/1|
|26|undo replaced committedB withA despite pendinginput|1/1|
|27|emptycell typefocus0 expected1|1/1|
|28|headerdraft.constructor inherited Object instead ofundefined for validIDs|1/1|

Assertions after earlier failures in a focal are not represented as separate RED observations. Individual entries demonstrate the given UI bridge criterion, not every algorithm branch; typedcore has its own tests and review by anotheragent.

## Characterizations and final verification

FirstPASS (not RED/TDD): Vue script+template compilation for dialog/Index/Editor, actualclient+core raw two-page typed0.0/-0.0/9007199254740993 integration/saveBlob, request422details and abortbeforeappend. No mutation was introduced to fabricate RED for those already covered behaviors. Markup/accessibility/styles/entry integration remain pending real independent UI QA.

`verify.py`: **37/37 PASS**,0failed/cancelled/skipped/todo, exit0; **31 ownclient/dialog tests +6 captions/exportpanel neighbors**. `verification.json` actual command/counts and8before/after SHA (6assigned+2observedcoredependencies),0drift, UTF8rawfinal-verified.log. PHP/backend/large native limits were not rerun or claimed. Core dependencies may have a separate pending review; this is not their approval. No coverage percentage invented.

## Criteria mapping and limitations

E5: entries bothpages, private paginatedbrandlibrary/search, create/importCSVTSVJSON, history/archive/restore/safedownload. E6: typedsemanticwindowtable, structural/bulkops, explicitatomicconversionpreview/rowerrors, dirtyguards inclunappliedinput, readonlyhistory/archive, optimistic409+preserveddraft/localrescue, nativefocus/discard/abort+latewritefence. E7: originalmultipartfile, sharedtypedchunk Blob save, rawrevision/SHAassembledserialpages; no52MBtable embeddedindocument. E1–E4 algorithms owned bycoreagent, not reimplemented here.

No native build/browser/DB/server/provider/.env/ONNX/VPS, deployment, socialpublish, commit/push. Root must build after approved core, perform independent adversarial review and QA27threeviewports; **not UI acceptance or T12 closure**.
