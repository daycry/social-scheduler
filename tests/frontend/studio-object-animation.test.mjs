import test from 'node:test';
import assert from 'node:assert/strict';
import { animationState, fitAnimation, normalizeAnimation } from '../../resources/js/Studio/object-animation.js';
import { applyElementAnimation } from '../../resources/js/Studio/animation-scene.js';
import { blankDocument, newElement, validateDocument } from '../../resources/js/Studio/document.js';
import { restrictElementWindow, splitTimelineElement } from '../../resources/js/Studio/timeline.js';

const near = (actual, expected, message) =>
    assert.ok(Math.abs(actual - expected) < 1e-9, `${message ?? ''} ${actual} != ${expected}`);
const window = { start: 1, duration: 4 };

function scene(animation, timing = window) {
    const doc = blankDocument(320, 180);
    doc.schema_version = 2;
    doc.timeline = { fps: 30, audio_tracks: [] };
    doc.pages[0].duration = 6;
    doc.pages[0].elements = [
        newElement('text', {
            id: 'title',
            text: 'Hola',
            ...(timing ? { timing } : {}),
            ...(animation ? { animation } : {}),
        }),
    ];
    return doc;
}

test('without animation the state is the identity, so old designs render as before', () => {
    for (const time of [0, 1, 2.5, 5])
        assert.deepEqual(animationState(undefined, time, window), { opacity: 1, offsetX: 0, offsetY: 0, scale: 1 });
});

test('fade in: hidden before the entry, half way at the middle, fully visible at the end', () => {
    const animation = { in: { type: 'fade', duration: 1, delay: 0.5, easing: 'linear' } };
    near(animationState(animation, 1.2, window).opacity, 0, 'antes de la entrada');
    near(animationState(animation, 1.5, window).opacity, 0, 'inicio de la entrada');
    near(animationState(animation, 2, window).opacity, 0.5, 'mitad');
    near(animationState(animation, 2.5, window).opacity, 1, 'fin');
    near(animationState(animation, 4, window).opacity, 1, 'después');
});

test('fade out ends at the end of the window minus the delay and stays hidden afterwards', () => {
    const animation = { out: { type: 'fade', duration: 1, delay: 0.5, easing: 'linear' } };
    near(animationState(animation, 3.5, window).opacity, 1, 'antes de la salida');
    near(animationState(animation, 4, window).opacity, 0.5, 'mitad de la salida');
    near(animationState(animation, 4.5, window).opacity, 0, 'fin de la salida');
    near(animationState(animation, 4.9, window).opacity, 0, 'retardo final');
});

test('slides move from and towards their side by a quarter of the page, zoom scales from half', () => {
    const slide = (type) => animationState({ in: { type, duration: 1, easing: 'linear' } }, 1, window);
    assert.deepEqual([slide('slide_left').offsetX, slide('slide_left').offsetY], [-0.25, 0]);
    assert.deepEqual([slide('slide_right').offsetX, slide('slide_right').offsetY], [0.25, 0]);
    assert.deepEqual([slide('slide_up').offsetX, slide('slide_up').offsetY], [0, -0.25]);
    assert.deepEqual([slide('slide_down').offsetX, slide('slide_down').offsetY], [0, 0.25]);
    near(animationState({ in: { type: 'zoom', duration: 1, easing: 'linear' } }, 1, window).scale, 0.5, 'zoom inicial');
    near(
        animationState({ in: { type: 'zoom', duration: 1, easing: 'linear' } }, 1.5, window).scale,
        0.75,
        'zoom a mitad',
    );
    const out = animationState({ out: { type: 'slide_left', duration: 1, easing: 'linear' } }, 5, window);
    near(out.offsetX, -0.25, 'la salida se va hacia su lado');
    near(out.opacity, 0, 'la salida termina transparente');
});

test('easing curves keep the endpoints and differ in the middle', () => {
    const at = (easing) => animationState({ in: { type: 'fade', duration: 1, easing } }, 1.25, window).opacity;
    near(at('linear'), 0.25);
    assert.ok(at('ease_in') < 0.25 && at('ease_out') > 0.25 && at('ease_in_out') < 0.25);
    for (const easing of ['linear', 'ease_in', 'ease_out', 'ease_in_out']) {
        near(animationState({ in: { type: 'fade', duration: 1, easing } }, 1, window).opacity, 0, easing);
        near(animationState({ in: { type: 'fade', duration: 1, easing } }, 2, window).opacity, 1, easing);
    }
});

test('the document validator accepts valid animations and rejects the invalid ones', () => {
    const valid = {
        in: { type: 'slide_left', duration: 0.5, delay: 0.25, easing: 'ease_out' },
        out: { type: 'zoom', duration: 1 },
    };
    assert.deepEqual(validateDocument(scene(valid)).pages[0].elements[0].animation, valid);
    assert.equal(Object.hasOwn(validateDocument(scene(null)).pages[0].elements[0], 'animation'), false);
    const bad = [
        { in: { type: 'spin', duration: 1 } },
        { in: { type: 'fade', duration: 0.01 } },
        { in: { type: 'fade', duration: 11 } },
        { in: { type: 'fade', duration: 1, delay: -1 } },
        { in: { type: 'fade', duration: 1, easing: 'bounce' } },
        { in: { type: 'fade', duration: 1, extra: 1 } },
        { middle: { type: 'fade', duration: 1 } },
        {},
        { in: { type: 'fade', duration: 3, delay: 1.5 } },
        { in: { type: 'fade', duration: 2 }, out: { type: 'fade', duration: 2.5 } },
    ];
    for (const animation of bad)
        assert.throws(
            () => validateDocument(scene(animation)),
            /animaci|propiedad|duraci|retardo|tipo|curva|entrada|caben/i,
            JSON.stringify(animation),
        );
});

test('v1 documents still reject animations', () => {
    const doc = scene({ in: { type: 'fade', duration: 1 } }, null);
    doc.schema_version = 1;
    delete doc.timeline;
    assert.throws(() => validateDocument(doc), /animation|propiedad/);
});

test('fitAnimation drops the phases that no longer fit and normalizeAnimation checks the object life', () => {
    const animation = { in: { type: 'fade', duration: 1 }, out: { type: 'fade', duration: 2 } };
    assert.deepEqual(fitAnimation(animation, 3), animation);
    assert.deepEqual(fitAnimation(animation, 2), { in: animation.in });
    assert.equal(fitAnimation(animation, 0.5), null);
    assert.throws(() => normalizeAnimation(animation, 2.9, 'animation'), /caben/);
});

test('splitting a clip keeps the entry on the first half and the exit on the second', () => {
    const doc = scene({ in: { type: 'fade', duration: 0.5 }, out: { type: 'zoom', duration: 0.5 } });
    const result = splitTimelineElement(doc, doc.pages[0].id, 'title', 3, { createId: () => 'second_half' });
    const [left, right] = result.pages[0].elements;
    assert.deepEqual(left.animation, { in: { type: 'fade', duration: 0.5 } });
    assert.deepEqual(right.animation, { out: { type: 'zoom', duration: 0.5 } });
});

test('restricting a window removes only the phases that do not fit', () => {
    const element = scene({ in: { type: 'fade', duration: 1 }, out: { type: 'fade', duration: 1 } }).pages[0]
        .elements[0];
    const result = restrictElementWindow(element, { start: 1, duration: 1.5 }, { pageDuration: 6 });
    assert.deepEqual(result.animation, { in: { type: 'fade', duration: 1 } });
    assert.equal(
        Object.hasOwn(restrictElementWindow(element, { start: 1, duration: 0.5 }, { pageDuration: 6 }), 'animation'),
        false,
    );
});

function fakeObject() {
    const object = {
        left: 10,
        top: 20,
        scaleX: 2,
        scaleY: 2,
        opacity: 0.8,
        dirty: false,
        getRelativeCenterPoint() {
            return { x: this.left + 50, y: this.top + 25 };
        },
        set(values) {
            Object.assign(this, values);
        },
        setPositionByOrigin(point) {
            this.left = point.x - 50;
            this.top = point.y - 25;
        },
        setCoords() {},
    };
    return object;
}

test('applying the state starts from the stored base and does not accumulate when repeated', () => {
    const element = { timing: window, animation: { in: { type: 'slide_right', duration: 1, easing: 'linear' } } };
    const size = { width: 320, height: 180, pageDuration: 6 };
    const object = fakeObject();
    applyElementAnimation(object, element, 1, size);
    near(object.opacity, 0);
    near(object.left, 10 + 0.25 * 320);
    applyElementAnimation(object, element, 1.5, size);
    applyElementAnimation(object, element, 1.5, size);
    near(object.opacity, 0.4);
    near(object.left, 10 + 0.125 * 320);
    applyElementAnimation(object, element, 3, size);
    near(object.opacity, 0.8);
    near(object.left, 10);
    near(object.top, 20);
});

test('an element without animation leaves its object untouched', () => {
    const object = fakeObject();
    const before = JSON.stringify(object);
    applyElementAnimation(object, { timing: window }, 1.5, { width: 320, height: 180, pageDuration: 6 });
    assert.equal(JSON.stringify(object), before);
    assert.equal(object.studioAnimationBase, undefined);
});

test('a cropped phase whose curve ends before the clip starts is rejected (it would do nothing)', () => {
    for (const delay of [-1, -5]) {
        assert.throws(
            () => normalizeAnimation({ in: { type: 'fade', duration: 1, delay, clipped: true } }, 3, 'animation'),
            /retardo/,
        );
    }
    assert.doesNotThrow(() =>
        normalizeAnimation({ in: { type: 'fade', duration: 1, delay: -0.5, clipped: true } }, 3, 'animation'),
    );
});
