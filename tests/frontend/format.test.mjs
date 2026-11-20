import assert from 'node:assert/strict';
import test from 'node:test';
import { createDateFormatter, createZonedDateFormatter } from '../../resources/js/lib/format.js';

const options = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' };

test('uses the brand zone, including a change to the next calendar date', () => {
    const instant = '2026-11-17T23:30:00Z';
    assert.equal(createDateFormatter('Pacific/Auckland', options)(instant), '18/11/2026, 12:30');
    assert.equal(createDateFormatter('America/Los_Angeles', options)(instant), '17/11/2026, 15:30');
});

test('follows daylight saving transitions in the brand zone', () => {
    const format = createDateFormatter('Europe/Madrid', { hour: '2-digit', minute: '2-digit' });
    assert.equal(format('2026-03-29T00:45:00Z'), '01:45');
    assert.equal(format('2026-03-29T01:15:00Z'), '03:15');
});

test('keeps the local time zone for an absent brand and uses UTC for an invalid zone', t => {
    const previous = process.env.TZ;
    process.env.TZ = 'America/Los_Angeles';
    t.after(() => {
        if (previous === undefined) delete process.env.TZ;
        else process.env.TZ = previous;
    });
    const instant = '2026-11-18T00:30:00Z';
    assert.equal(createDateFormatter(undefined, options)(instant), '17/11/2026, 16:30');
    assert.equal(createDateFormatter('Unknown/Zone', options)(instant), '18/11/2026, 00:30');
});

test('keeps the caller placeholder for absent and invalid dates', () => {
    const format = createDateFormatter('Europe/Madrid', options, 'nunca');
    assert.equal(format(null), 'nunca');
    assert.equal(format(''), 'nunca');
    assert.equal(format('invalid date'), 'nunca');
});

test('the shared row formatter caches constructors independently per zone', t => {
    const DateTimeFormat = Intl.DateTimeFormat;
    const constructors = t.mock.method(Intl, 'DateTimeFormat', function (...args) {
        return new DateTimeFormat(...args);
    });
    const format = createZonedDateFormatter(options, 'fecha ausente');
    const at = '2026-11-17T20:30:00Z';
    assert.equal(format(at, 'Pacific/Auckland'), '18/11/2026, 09:30');
    assert.equal(format(at, 'Europe/Madrid'), '17/11/2026, 21:30');
    assert.equal(format(at, 'Pacific/Auckland'), '18/11/2026, 09:30');
    assert.equal(format(null, 'Europe/Madrid'), 'fecha ausente');
    assert.equal(constructors.mock.callCount(), 2);

    assert.equal(format(at), new DateTimeFormat('es-ES', options).format(new Date(at)));
    assert.equal(format(at, 'Invalid/Zone'), '17/11/2026, 20:30');
    assert.equal(format(at, 'Invalid/Zone'), '17/11/2026, 20:30');
    assert.equal(constructors.mock.callCount(), 5);
});
