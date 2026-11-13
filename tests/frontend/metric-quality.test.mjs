import test from 'node:test';
import assert from 'node:assert/strict';
import { metricQuality } from '../../resources/js/Pages/Dashboard/Partials/quality.js';

test('partial coverage exposes missing account days even when the reported total is zero', () => {
    const quality = metricQuality({ status: 'partial', observed_account_days: 42, expected_account_days: 66, missing_account_days: 24 }, { is_stale: true, latest_data_date: '2026-10-21', latest_expected_date: '2026-11-02' });
    assert.equal(quality.label, 'Datos parciales');
    assert.equal(quality.warning, true);
    assert.match(quality.detail, /42 de 66/);
    assert.match(quality.detail, /24/);
    assert.match(quality.detail, /21\/10\/2026/);
    assert.match(quality.detail, /02\/11\/2026/);
});

test('missing, unsupported and still open periods have distinct meanings', () => {
    assert.equal(metricQuality({ status: 'no_data' }).label, 'Sin datos recogidos');
    assert.equal(metricQuality({ status: 'unsupported' }).label, 'No disponible en esta red');
    assert.equal(metricQuality({ status: 'not_due' }).label, 'Periodo aún abierto');
    assert.equal(metricQuality(null), null);
});

test('la analitica de Pinterest no disponible por entorno o acceso tiene su propia explicacion', () => {
    const sandbox = metricQuality({ status: 'unavailable_sandbox', expected_account_days: 5, observed_account_days: 0, missing_account_days: 5 });
    assert.equal(sandbox.label, 'No disponible en el entorno de pruebas');
    assert.equal(sandbox.warning, true);
    assert.match(sandbox.detail, /Pinterest no ofrece analítica en el entorno de pruebas; conecta la app con acceso de producción/);
    assert.match(metricQuality({ status: 'unavailable_access' }).detail, /acceso estándar/);
});

test('complete zero observations are not described as absent or as a guaranteed refresh', () => {
    const quality = metricQuality({ status: 'complete', observed_account_days: 6, expected_account_days: 6, missing_account_days: 0 }, { latest_data_date: '2026-11-02', latest_attempt_at: '2026-11-03T12:00:00Z', is_stale: false });
    assert.equal(quality.label, 'Cobertura completa');
    assert.equal(quality.warning, false);
    assert.match(quality.detail, /6 de 6/);
    assert.doesNotMatch(quality.detail, /actualizad|garantiz/i);
});
