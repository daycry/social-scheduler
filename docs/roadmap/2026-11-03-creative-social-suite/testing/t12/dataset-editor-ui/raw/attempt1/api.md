# Dataset UI handoff — 2026-11-06

`createDatasetClient({ fetchImpl=globalThis.fetch, csrf=readCookie, createAssembler, tableChunks })`.
All URLs are constructed private same-origin web endpoints from safe integer brand/dataset IDs. No caller URL, bearer, provider or network outside local UI request.

- `list(brandId,{query='',page=1,signal}={})` → metadata and real20/page pagination, active+archived together.
- `get(brandId,id,{signal}={})`; `history(brandId,id,{page=1,signal}={})`.
- `load(brandId,id,revision,{signal,onProgress}={})` → `{table,revision,sha256}`; serial RAW pages, fixedrevision, strict increasingoffset, shared assembler validates IDs/SHA/schema/cumulative budget. Never JSON.parse celdas.
- `save(brandId,id|null,{name,revision,table},{signal}={})` → import multipartJSON Blob from codec chunks. UI does not abort writes and serializes them; retains real late effects while fencing old context.
- `upload(brandId,{file,name,format,header=true},{signal}={})` → original file multipart, no localfile text read, CSV/TSV25M or JSON52M. Format/header explicit.
- `control(brandId,id,'archive'|'restore')` → metadata.
- `exportUrl(brandId,id,{revision,format='csv',mode='spreadsheet'})` → fixedsnapshot private web download; JSON omitsmode. CSV/TSV safe default, text explicit.

UI has dialognative Teleport (own ESCcancelprevent), inline alertdialog for dirty discard, localbeforeunload warning, focus type on nullcell/value on nonnull. Dataset state uses shallowRef; core numbertokens remain frozen/branded. Undo30 structural shared states, no structuredClone/JSON.stringify table. Window20rows×8columns, totalstatekept, row/columnpaging, semantictable. Typedinputstage explicit Apply; headerdraft own/nullprototype dictionary, changecommit anderror preservinginput. Pendingcell/header block save/undo/switchcells/bulk-loss. Dirty includes uncommitted fields.

Entries: Index `/studio?brand_id=ID` and Editor `/studio/designs/ID/edit`, button 'Tablas de datos'; dialog `aria-label='Tablas de datos de marca'`; entrypages modified only imports/ref/button/component. Full labels delivered to QA27agent; fileimport/tools summaries open first. 'Descargar tabla' downloads persisted revision, 'Descargar borrador JSON' exactlocaltypedBlob. Conflict message userfriendly (no literal409 required), reload requires discardconfirmation. Historical/archived table read-only; copy to newdraft allowed. No XLSX/charts advertised.

No build/Chromium/server/DB/ONNX run by this implementer. Root owns build, independent review/QA and ledger; no acceptance of whole T12.
