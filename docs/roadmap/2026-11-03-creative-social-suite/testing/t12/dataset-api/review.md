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


## Intento 2: A2/B2/D2 aprobadas

Delta de cuatro fuentes y dos documentos respecto al snapshot exacto del intento1. Los31 SHA actuales y congelados coinciden; nueve fuentes core y25 rutas anteriores permanecen intactas. Las tablas completas anteriores se transmitieron a las tres lentes, que conservaron los✓ y sólo reabrieron los casos corregidos o la nueva consecuencia de identidad objeto/lista por MIME.

[Delta de correcciones](raw/implementation/attempt2-delta.patch), [RED/GREEN y comandos](raw/implementation/attempt2-evidence.md), [JUnit estándar](raw/implementation/attempt2-standard.xml). El estándar acredita26casos:25PASS y1SKIP opt-in,208aserciones. La subida25MB y la llamadaMCP≈51MB se conservan como ejecuciones focales anteriores, no se convierten en un agregado ficticio ni en52MBexactos/TCP.

### Tabla A2 completa

| Criterio previo | Resultado2 | Evidencia |
|---|---|---|
| Alcance y archivos necesarios | ✓ preservado | Cuatro fuentes y dos documentos declarados. |
| Núcleo y aprobaciones | ✓ |31SHA;core9intacto. |
| Contrato/OpenAPI compatible | ✓ preservado | SHAe6e9b161…;lint0errores,3avisos previos,diff0rompedores. |
| CRUD web/API | ✓ preservado | Rutas6/7;T75,86,177,232. |
| Auth/abilities/verified/CSRF | ✓ preservado | M24;T100,189,232;401/403/419. |
| Aislamiento/metadatos seguros | ✓ preservado | S157;T75,86,293. |
| Revisión/conflicto409 | ✓ preservado | S35,140;T86,177,252,397. |
| Archivo/restauración/historial | ✓ preservado | S129,132;T86,397. |
| Claves desconocidas | ✓ preservado | S16,26;I16;controller21/27;M78–89. |
| Objeto/lista y tipos | ✓ ampliado | M71–75 relectura independiente deMIME;T489–507,seiscasos inválidos rechazados. |
| Paginación/búsqueda literal | ✓ preservado | S120,129,152;I23. |
| Filas revisión/SHA antes deI/O | ✓ preservado | S100,157;T110. |
| Offset/limit6MiB sin pérdida | ✓ preservado | S102–118;T123,211. |
| MultipartCSV/TSV25MB/JSON52MB | ✓ preservado | S33–98;T177,211;evidenciaKernel,noTCP. |
| Guardia previa/ContentLength no confiable | ✓ corregido | G19–28,todosPOSTMCP;T444–486,ocho cruces;17assert. |
| Presupuestos HTTP53MiB/MCP160MiB | ✓ | G11/13/24–28;C45–51;sinini_setprod. |
| GET/SSE y otros callers no leen cuerpo | ✓ caracterización | G19–22;T510–526,4assert;probeA2doscallers. |
| Descarga privada y opciones | ✓ preservado | S167;controller31–40;T162,419. |
| CSV seguro/texto fiel/JSON tipado | ✓ ampliado | M34–36 flagscanónicos;T433–441valores ytexto0.0/1.0/-0.0. |
| Snapshot503 sin rutas/fallback | ✓ preservado | S181;SchedulerTool86;T110,293. |
| MCP acciones/abilities/IDs/shared | ✓ preservado | M24/45/66–92;T189,243,252,397. |
| Catálogo42 paginado | ✓ preservado | Server106;testcatálogo87;Pest15/199. |
| Auditoría/errores | ✓ preservado | SchedulerTool55/86/103,defaultSDK108–110;T367. |
| RED y verificación | ✓ | XML26/208,25PASS+1SKIP;cuatro casos añadidos,preservahechosanteriores. |
| Guías/catálogo/perfiles | ✓ |192MiBPOST/proxy,64MiBupload,512MiBPHP sólo requisitos,no despliegue ejecutado. |
| Estado del recurso | ✓ A1 corregido | C3 aprobación interna yTCP pendiente diferenciados. |
| T12integral/E2E | Pendiente explícito | No cierre deT12 ni QA26 anticipada. |
| Constitución/cobertura/interop | n/a preservado | Sin configuración mínima ni piezasgeneradas del plugin. |
| ADR | ✓ sin coincidencias | Consulta específica,exit0. |

A2 ejecutó SHA31,php-l4 yprobePHP256M:floatcanónico,8MIME/longitud y2callerssinlecturas,pico10.485.760B. Sin ensayosgrandes adicionales.

### Tabla B2 completa

| Criterio previo | Resultado2 | Evidencia |
|---|---|---|
| Snapshot | ✓ |31SHA;6cambios declarados. |
| Campos/objetos/listas/parámetros | ✓ ampliado |12casos MIME/forma, incluidos6inválidos nuevos;body/user/route/headers preservados. |
| Import/mutaciones | ✓ preservado | S34;dominio intacto. |
| Filas/revisión/SHA | ✓ preservado | S100;recorrido intacto. |
| Export antes deheaders | ✓ preservado | S167. |
| Fracción cero MCP | ✓ B1 corregido | M34/SchedulerTool108;handlerrealpreserva0.0/1.0/-0.0trasenvolverJSON;defaultlegacyintacto. |

B2probePHPstdin256M,12casos correctos,pico12.582.912B. Sin fuentes/DB/env/TCP ni cargas grandes nuevas.

### Tabla D2 completa

| Carga previa | Resultado2 | Evidencia |
|---|---|---|
| List/historial100elementos | ✓ preservado | S120–133,sinN+1nuevo. |
|1000×100 ypágina | ✓ preservado | MediciónD1lineal4,54/18,26/36,57ms,pico14.680.064B;S100–118intacto. |
| Import grande/perfil | ✓ evidencia preservada |25MB/256M y≈51MBenvelope153M/512Mhistóricos;no52Mexacto/TCPnuevo. |
| Cap antesde materializar | ✓ D1 corregido | G19–33;POSTeselúnicométodoSDKqueconsumeRPC(Registrar44–57);máximodelectura167.772.161B. |

D2probePHP256M conRequest/ValidateMcpHeaders/HttpTransport/Serverreales:8/8MIME/ContentLength,HTTP200 ysecuenciaresource,string,string(lecturaacotadapreviaSDK);4/4GET/HEAD/DELETE/POSTajenosinlecturas. Pico10.485.760B. LaslecturasposterioresobtienencadenaSymfonyalmacenada,sinotrarawcopycompletaporlectura. No se envían600MB.

### Resolución

| # | Grado anterior | Estado | Evidencia |
|---|---|---|---|
| A1 | Minor | Corregido | C3 coherente con aprobación del recurso. |
| B1 | Important | Corregido | Hook por dataset preserva floats;default otras41 herramientas intacto. |
| D1 | Important | Corregido | Cap en todosPOSTMCP,shape original sin dependenciaMIME;8+6casos correctos. |

**0Critical/Important/Minor pendientes.** Aprobado el código de adaptadores; la aceptación por servidorTCP sigue pendiente. T12 y el objetivo de23tareas permanecen abiertos.

## Adenda de prosa posterior a la aprobación

A2 comprueba cuatro anotaciones de estado/nombre:guía,móduloMCP,contrato ytest-plan. Se declara revisión2aprobada/TCPpendiente yse corrige sólo elnombre real delspecprivado `.spec.cjs`. Sin cambiarcriterios,PHP niOpenAPI.23SHA PHPintactos(14API+9core) ycuatrodescriptoresrestantesintactos. ReferenciaQAprivada comprobada,noejecutada. Conforme actualizar elmanifestQA31conloscuatroSHA de la adenda.

Para reproducir elPest compartido sin `.env` real, restaurar elhelperpublicado `raw/implementation/pest-bootstrap.php` a `storage/framework/testing/t12-dataset-api/implementation/pest-bootstrap.php` antes de usar elcomando delinforme. Nopublicar configcachegenerada,SQLite,tokens,cookies ni auth/private.json.
