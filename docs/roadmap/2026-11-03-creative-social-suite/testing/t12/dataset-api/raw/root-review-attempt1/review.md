# T-12: revisión de adaptadores datasets

2026-11-06. Intento1 A+B+D sobre14fuentes y8descriptores,9coreaprobadasintactas. Fuentecongeladaen `storage/framework/testing/t12-dataset-api/root-review-attempt1/`. Scope0,C=false,D=true porSchedulerServer/SchedulerTool. T12abierta8/23;QA26TCPaúnnoejecutada.

Alias:S=StudioDatasetActions.php;I=StudioDatasetInput.php;T=StudioDatasetApiTest.php;M=ManageStudioDataset.php;C=t12-dataset-api-contract.md.

## Lente A: tabla completa

| Criterio | Veredicto | Evidencia |
|---|---|---|
| AlcanceT12/archivosnecesarios | ✓ |14API+8descriptorescorrespondenalsubbloque,ledger314. |
| Preservarcore/aprobaciones | ✓ |9SHAiguales yreportscoreaprobados. |
| Contratoprevio/OpenAPIcompatible | ✓ |8paths/11ops/14schemas;lint0errores3avisosprevios,diff0rompedores. |
| CRUDweb/APIpormarca | ✓ | Routes6/7;T72,83,174,229. |
| Auth/abilities/verified/CSRF | ✓ | Rutas,M23;T97,186,229;401/403/419. |
| Aislamiento/metadatosseguros | ✓ | S157autorizasnapshotanteslectura;T72,83,290. |
| Revisionpositiva/conflicto409 | ✓ | S35,140;T83,174,249,394. |
| Archive/restore/historialarchivado | ✓ | S129,132;T83,394. |
| Unknownkeysrechazadosporacción | ✓ | S16,26;I16;controller21,27;M72;T97,374,379. |
| Objeto/listaytiposJSON | ✓ | I55,71;controller19;M63;T174,249,416;probes13correctos. B1serializadorMCPseañadeenB. |
| List/historialpaginado/literal | ✓ | S120,129,152;I23;T72,83,394. |
| Filasrevision/SHAantesI/O | ✓ | S100,157;T107pinrevision1trasescritura2. |
| Offset/limit6MiBsinperder/truncar | ✓ | S102–118;T120seisfilasyoffset6,T208última999. |
| MultipartCSV/TSV25MBJSON52MB | ✓ | S33–98;T174,208;evidencia25MB1/7Kernel,noTCP. |
| Guardiaantesdecode/sinContentLengthtrust | ✓ | Guard13,20–29;bootstrap30;T139,265;root1/1guardXML. D1MIMEbypassseañadeenD. |
| HTTP53MiB/MCP160MiBfijo | ✓ | Guard13,15,22;C47;T302;noini_setprod. |
| Descargaprivadastreamed/opcionescerradas | ✓ | S167,controller31–40;T159,416. |
| SafeCSV/textfiel/JSONtyped | ✓ | S167–186;T159,416;coreintacto. |
| Snapshot503sinfallback/rutas | ✓ | S181;SchedulerTool86;T107,290. |
| MCPacciones/abilities/IDs/sharedservices | ✓ | M23,45,58–86;T186,240,249,394. |
| Catálogopaginadoreal42 | ✓ | Server106;StudioApiMcpTest87;rootPest15/199XML. |
| Auditdataset_id/errorescontrolados | ✓ | SchedulerTool55,86,103;T364. |
| REDyverificaciónejecutada | ✓ |17REDtranscritosidentificados;21pass/1skip174,root15/199+guard1/1,erroresharnessseparados. |
| Guías/README/catálogo/límitesactualizados | ✓ | Guía479–513,módulo171,README41–42,testplan42/101–113;nodeclareTCPniUIaceptados. |
| Estadodelrecursoencontrato | ✗ A1Minor | C3 aúnencorrecciónpeseaprobaciónledger1372. |
| T12integral | Pendienteexplícito | Ledger313–320,no cierreporAPI. |

Aejecutólint/diffOpenAPIexit0,13probesPHP256M,pico10.485.760B,php-l14sinfallos y31SHAcurrent/22snapshot/6baselinecoincidentes. Noestresesgrandesrepetidos. Sinconstitución/dev.json/cobertura;interopn/a.

## Lente B: tabla completa

| Objetivo | Veredicto | Evidencia |
|---|---|---|
| Integridadsnapshot | ✓ |14snapshot/liveSHAcoinciden. |
| Campos/objetoslistas/parámetros | ✓ | I19–90;controller17–28;M60–86. |
| Imports/mutacionesoptimistas | ✓ | S34–98,138–147;coreaprobadosinrutaalternativa. |
| Filasacotadas/revisionSHA | ✓ | S100–118,157–164. |
| Exportvalidadoantesheaders | ✓ | S167–185;controller30–40. |
| FracciónceronuméricaMCP | ✗ B1Important | M86→SchedulerTool105 Response::json SDKsinPRESERVE_ZERO_FRACTION. |

BprobePHPstdin256Msinenvironment/DB:handlerrealconActions/auditstubinmemorydevuelveisError=falseyvalues[0,1,null,""]parafuente[0.0,1.0,null,""]. ContrasteJSONcanónicopreservafloat. No seatribuyetestTCPoestresaMBgrandes.

## Lente D: tabla completa

| Carga | Veredicto | Evidencia |
|---|---|---|
| List/historial100elementos | ✓ | S124;core113–153,sinN+1nuevo. |
| Procesamiento1000×100ypágina | ✓ | ProbePHP256M≈1MB:100/500/1000filas4,54/18,26/36,57ms,pico14.680.064B;loop100filas/6MiB. |
| Importgrande/perfilmemoria | ✓ evidencia leída |25MB127.926.272B/256M;MCP≈51MBenvelope153.030.414B490.733.568B/512M,noTCP/52Mexactoclaim. |
| LímiteMCPantesmaterialización | ✗ D1Important | Guard17omite!isJson aunqueSDKparsearaw. |

Dprobepequeño256M,sinapp/DB/write:Requestinstrumentado+ValidateMcpHeaders/HttpTransport/Server::handlereales,subclaseinterceptasóloejecuciónfinaldedominio. text/plain,application/JSONyContentTypeausente:guard_getContent_calls0/sdkparsedtrue/status200;controlapplication/jsonguard1lectura. No seejecuta600MB;sourcelecturailimitadaexplicalacargayCWE400. Sinlímiteupstreammenor,unPOSTMCPautenticado>=600MiBpuedeagotarPHP512M envezde413.

## Fusión intento1

| # | Grado | Gap | Tarea | Estado | Evidencia |
|---|---|---|---|---|---|
| A1 | Minor | Contratodescriberecursoaprobadocomoencorrección | T-12 | Pendientecorrecciónprosa | C3contradiceledger1372. |
| B1 | Important | RespuestaMCPpierde0.0/1.0 | T-12 | PendienteRED/fix | M86→SchedulerTool105,probehandlerSDKreal. |
| D1 | Important | MIMEbypasscap160MiBdelMCP | T-12 | PendienteRED/fix | Guard17frenteSDKrawindependientedeContentType. |

0Critical,2Important,1Minor. NoQAniaceptaciónadaptadoreshastaresolver. Preservar✓enrevisión2,sóloabrirevidencianueva;sinrebatidos. Core9sigueaprobado ysin cambios.
