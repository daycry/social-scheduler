# T-12: codec de descarga de tablas

2026-11-06. A1/B1 aprobadas tras rebate documentado,0gaps de corrección pendientes. Dosarchivosnuevos baselineABSENT, [diffcompleto](raw/full.diff), [contratoprevio](../../../t12-dataset-api-contract.md). T12sigueabierta,sinendpoints deeste codec ni aceptación de editor/Office.

## Evidencia y decodificación

PesttrasPint: **8/8,1034aserciones**,PHP256M. SeisREDindividuales funcionales yseisGREEN; doscaracterizacionesprimerPASS declaradas. Las pruebas no mutación(07) ypresupuesto52M(08) no se venden comoTDD. [ResultadofinalUTF8](raw/normalized/final-suite.log), [original](raw/final-suite.log), [16logsdecodificadosconSHAoriginal](raw/normalized/decoding.json). PowerShell escribió cabeceraUTF8 yappendUTF16LE;lascopiasnormalizadas se obtienen decodificandoambaspartes estrictamente yvalidandoJSON, sinborrarNUL arbitrariamente. Originalesintactos.

## LenteA — tabla completa delintento1

S=`app/Services/Studio/StudioTableExport.php`;T=`tests/Feature/Studio/StudioTableExportTest.php`;C=`t12-dataset-api-contract.md`.

| Criterio | Veredicto inicial | Evidencia |
|---|---|---|
| Firma/validación inmediata antesiterador | ✓ | S10–24;T27–41,excepciones siniterar. |
| FormatosCSV/TSV/JSON ycompatibilidadopciones | ✓ | S12–17;T27–34;RED02/03→GREEN. |
| PerfilDatasetfijo/pureza | ✓ | S19/24;sinFS/red/DB. |
| Tabla/tipos/UTF8/nofinitos | ✓ | S19;T37–41;RED04→GREEN8assert. |
| Spreadsheetdefault prefijaTODOSstringsetiquetas/celdas | ✓ | S27/43–59;T19–24/65–69;vacíos/apóstrofe/fórmulas/Unicode. |
| Números/null conservanlexical | ✓ | S57;T20–24/66–69 yprobes. |
| CSV/TSVescapeCRLF | ✓ | S58–62;T19/65;formatos±header. |
| TextbytefielincluidoUFEFF | ✓ | S23–24;T44–52;RED05→GREEN4assert. |
| JSONporregistros/flags/tipos/IDs/Unicode/0.0 | ✓ | S7/30–40;T55–62;RED06→GREEN4assert,sinJSONcompleto. |
| Nomutaciónentrada | ✓ | T65–73,caracterización07 yprobe. |
| Presupuesto52Moriginalantesprefijos/+1 | ✓ | S19antesS57;T76–119,mediciónindependiente,caracterización08. |
|6REDfocalesindividualesfuncionales | ✓ | Decodificaciónestrictaoriginales01–06 yGREEN. |
| REDnomutaciónpedido literalporC40anterior | ✗ A1Important inicial | Sólo07primerPASS,constabaexplícitamenteenledger. No defecto funcional. |
| Alcance/codecpreviointacto | ✓ | Diffexacto2fuentes,4SHAincluyecodec/testprevios. |
| Verificaciónejecutada/T12abierta | ✓ |8/8,1034trasPint;sinendpointsaceptados. |
| Constitución/cobertura/OpenAPI/interop | n/a | Ausentesmínimos/constitución;ningúnOpenAPI/commands/agents/hooks en2rutas. |

Aejecutó**82probesPASS**,4SHAfuente y16SHAlogs,JSONdecodificadoyfulldiffcorrectos. Norepitió52MB.

## LenteB — tabla completa

| Criterio | Veredicto | Evidencia |
|---|---|---|
| Opciones/tiposinválidosfallanalLLAMAR | ✓ | S12–19,inputsUnicode/control/nofinitos. |
| CSV/TSVescapeyfidelidadtext | ✓ | S23/57–62;str_getcsv ycomparaciónbyteindependientes. |
| JSONtyped/IDs/números | ✓ | S30–40;1024tablas conJSONcanónico ydecodeestricto. |
| Nomutación/cero filas/headeropcional | ✓ | S43–49;serialización y12combinacionesvacías. |
| Presupuestooriginalantesprefijo | ✓ | S19/57;lecturapruebaexacto/+1 yresultado8/1034. |

BprobePHPstdin256M: **12339comprobacionesPASS/1024tablas,pico2097152B asignadorPHP**. Fuenteyfrozen hashesiguales;no52Mrepetido.

## Rebate A1 y armonización documental

| # | Gradoinicial | Gap inicial | Veredictofinal | Evidencia |
|---|---|---|---|---|
| A1 | Important | LiteralC40pedíaREDparanomutaciónque sóloconstabacomocaracterización | Descartado(rebatido) | SkillTDDpermitedeclarar criterio yacubiertosincontarlocomoTDD; S10arrayporvalor yS19/30–62sólolectura,sincódigoespecíficode mutaciónque borrar/reimplementar. |

No se identifica un manejo nuevo de no mutación escrito antes de su test. Introducir una escritura o firma por referencia sólo para fabricarRED falsearía la propiedad inherente ya comprobada. Esto difiere del gap de cleanup del recurso, que sí tenía código nuevo específico y se borró/reimplementó trasRED. No se infiere excepción del usuario.

RootarmonizaC40: seiscriteriosfuncionalesRED y07/08caracterizaciones, sin cambiar comportamiento, pruebas o historia. Areadenda compruebaC40nuevo,C36/C38funcionalesintactos y4SHAfuentes, sinruntimeadicional. Tablas✓preservadas. Rebatircon evidencia no consume otrointento.

**0Critical/Important/Minor pendientes**. C/Dfalse porselector;scope0sinoutside/avisos/info/exclusionesusuario. Modeloheredadofallbackpluginparcial. Rootaceptasólocodec; APIUI/MCP/upload/descarga pública ygráficos permanecenpendientes.
