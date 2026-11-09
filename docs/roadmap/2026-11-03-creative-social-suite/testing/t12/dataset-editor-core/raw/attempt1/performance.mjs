import { performance } from 'node:perf_hooks';
import assert from 'node:assert/strict';
import { validateDatasetTable, createDatasetHistory, editDatasetCell, datasetTableChunks } from '../../../../resources/js/Studio/dataset-table.js';
const columns = Array.from({ length: 100 }, (_, i) => ({ id: `c${i}`, label: `Column ${i}` }));
const table = { columns, rows: Array.from({ length: 1000 }, (_, i) => ({ id: `r${i}`, values: Array(100).fill('0042') })) };
const started = performance.now(); validateDatasetTable(table); const history = createDatasetHistory(table); const initial = history.table;
const samples = [];
for (let i = 0; i < 31; i++) { const at = performance.now(); history.commit(editDatasetCell(history.table, `r${i}`, 'c0', `changed_${i}`)); samples.push(performance.now() - at); }
assert.equal(history.undoDepth, 30); assert.equal(history.table.rows[999], initial.rows[999]);
assert.equal(history.table.columns, initial.columns);
const bytes = [...datasetTableChunks(history.table)].reduce((sum, chunk) => sum + new TextEncoder().encode(chunk).length, 0);
console.log(JSON.stringify({ rows: 1000, columns: 100, edits: 31, history_depth: history.undoDepth, untouched_last_row_shared: true, columns_shared: true, bytes, max_edit_ms: Math.max(...samples), mean_edit_ms: samples.reduce((a,b)=>a+b,0)/samples.length, duration_ms: performance.now()-started, memory: process.memoryUsage() }, null, 2));
