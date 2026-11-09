// Session-only presentation overlays. Coordinates and widths never use document pixels.
const finite = value => typeof value === 'number' && Number.isFinite(value);
const validPoint = point => point && finite(point.x) && finite(point.y)
    && point.x >= 0 && point.x <= 1 && point.y >= 0 && point.y <= 1;
const copyPoint = point => ({ x: point.x, y: point.y });

export function presentationPoint(rect, width, height, clientX, clientY) {
    if (!rect || ![rect.left, rect.top, rect.width, rect.height, width, height, clientX, clientY].every(finite)
        || rect.width <= 0 || rect.height <= 0 || width <= 0 || height <= 0) return null;
    const scale = Math.min(rect.width / width, rect.height / height);
    const contentWidth = width * scale; const contentHeight = height * scale;
    const left = rect.left + (rect.width - contentWidth) / 2;
    const top = rect.top + (rect.height - contentHeight) / 2;
    const x = (clientX - left) / contentWidth; const y = (clientY - top) / contentHeight;
    return validPoint({ x, y }) ? { x, y } : null;
}

export function createPresentationTools({ now = () => performance.now(), onChange = () => {} } = {}) {
    let pageId = null; let cursor = 'normal'; let tool = 'pointer'; let pointer = null;
    let penColor = '#ff0000'; let penWidth = 0.005; let trailSeconds = 3;
    let strokes = []; let active = null; let owner = null; let disposed = false; let lastTime = 0;
    const clock = () => {
        const value = now();
        if (finite(value)) lastTime = Math.max(lastTime, value);
        return lastTime;
    };
    const snapshot = () => Object.freeze({
        pageId, cursor, tool, pointer: pointer ? Object.freeze(copyPoint(pointer)) : null,
        penColor, penWidth, trailSeconds,
        strokes: Object.freeze(strokes.map(stroke => Object.freeze({
            points: Object.freeze(stroke.points.map(point => Object.freeze(copyPoint(point)))),
            color: stroke.color, width: stroke.width, alpha: stroke.alpha,
        }))),
        needsFrame: !disposed && strokes.some(stroke => stroke !== active && stroke.alpha > 0),
    });
    const publish = () => { const value = snapshot(); if (!disposed) onChange(value); return value; };
    const finish = () => {
        if (active) active.endedAt = clock();
        active = null; owner = null;
    };
    const clearState = () => { strokes = []; active = null; owner = null; pointer = null; };
    const prune = () => {
        const time = clock();
        for (const stroke of strokes) {
            stroke.alpha = stroke === active ? 1 : Math.max(0, 1 - (time - stroke.endedAt) / (trailSeconds * 1000));
        }
        strokes = strokes.filter(stroke => stroke === active || stroke.alpha > 0);
    };
    const trim = () => {
        let total = strokes.reduce((count, stroke) => count + stroke.points.length, 0);
        while (strokes.length > 64 || total > 4096) {
            const index = strokes.findIndex(stroke => stroke !== active);
            if (index < 0) break;
            total -= strokes[index].points.length; strokes.splice(index, 1);
        }
    };
    const setting = (valid, apply) => {
        if (disposed) return snapshot();
        if (!valid) throw new TypeError('Invalid presentation tools setting');
        return apply() === false ? snapshot() : publish();
    };
    return {
        snapshot,
        setPage(id) {
            return setting(id === null || (typeof id === 'string' && id.length > 0) || (finite(id) && Number.isSafeInteger(id)), () => {
                if (pageId === id) return false;
                pageId = id; clearState();
            });
        },
        setCursor(value) {
            return setting(['normal', 'laser', 'hidden'].includes(value), () => {
                if (cursor === value) return false; cursor = value;
            });
        },
        setTool(value) {
            return setting(['pointer', 'pen'].includes(value), () => {
                if (tool === value) return false; finish(); tool = value;
            });
        },
        setPenColor(value) {
            return setting(typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value), () => {
                if (penColor === value) return false; penColor = value;
            });
        },
        setPenWidth(value) {
            return setting(finite(value) && value >= 0.001 && value <= 0.04, () => {
                if (penWidth === value) return false; penWidth = value;
            });
        },
        setTrailSeconds(value) {
            return setting(Number.isInteger(value) && value >= 1 && value <= 10, () => {
                if (trailSeconds === value) return false; trailSeconds = value; prune();
            });
        },
        pointerDown(point, pointerId) {
            if (disposed || owner !== null) return snapshot();
            if (!validPoint(point)) {
                if (!pointer) return snapshot(); pointer = null; return publish();
            }
            if (!Number.isSafeInteger(pointerId)) throw new TypeError('Invalid pointer ID');
            if (tool !== 'pen' && pointer?.x === point.x && pointer?.y === point.y) return snapshot();
            pointer = copyPoint(point);
            if (tool === 'pen') {
                prune(); owner = pointerId;
                active = { points: [copyPoint(point)], color: penColor, width: penWidth, alpha: 1, endedAt: null };
                strokes.push(active); trim();
            }
            return publish();
        },
        pointerMove(point, pointerId) {
            if (disposed || (owner !== null && owner !== pointerId)) return snapshot();
            const next = validPoint(point) ? point : null;
            if ((!next && !pointer) || (next && pointer?.x === next.x && pointer?.y === next.y)) return snapshot();
            pointer = next ? copyPoint(next) : null;
            if (pointer && active) {
                const previous = active.points.at(-1);
                if (previous.x !== pointer.x || previous.y !== pointer.y) {
                    // Decimate interior samples at capacity; always keep the original and live endpoint.
                    if (active.points.length >= 1024) {
                        active.points = active.points.filter((_, index, points) => index === 0 || index === points.length - 1 || index % 2 === 0);
                    }
                    active.points.push(copyPoint(pointer)); trim();
                }
            }
            return publish();
        },
        pointerUp(pointerId) {
            if (disposed || owner === null || owner !== pointerId) return snapshot();
            finish(); return publish();
        },
        pointerLeave() {
            if (disposed || (!pointer && owner === null)) return snapshot();
            pointer = null; finish(); return publish();
        },
        clear() { if (disposed || (!pointer && !strokes.length)) return snapshot(); clearState(); return publish(); },
        tick() {
            if (disposed) return snapshot();
            const previous = strokes.map(stroke => stroke.alpha); prune();
            if (previous.length !== strokes.length || strokes.some((stroke, index) => stroke.alpha !== previous[index])) return publish();
            return snapshot();
        },
        dispose() { if (!disposed) { clearState(); disposed = true; } },
    };
}

export function presentationCursorStyle(snapshot) {
    if (snapshot?.cursor === 'hidden' || snapshot?.cursor === 'laser') return 'none';
    return snapshot?.tool === 'pen' ? 'crosshair' : 'default';
}

export function paintPresentationTools(ctx, snapshot, { width, height, timer } = {}) {
    if (!ctx || !snapshot || !finite(width) || !finite(height) || width <= 0 || height <= 0) return;
    const minor = Math.min(width, height);
    ctx.save();
    try {
        ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        for (const stroke of snapshot.strokes) {
            if (stroke.alpha <= 0 || !stroke.points.length) continue;
            ctx.globalAlpha = stroke.alpha; ctx.strokeStyle = stroke.color; ctx.fillStyle = stroke.color;
            ctx.lineWidth = stroke.width * minor;
            const first = stroke.points[0]; ctx.beginPath();
            if (stroke.points.length === 1) {
                ctx.arc(first.x * width, first.y * height, ctx.lineWidth / 2, 0, Math.PI * 2); ctx.fill();
            } else {
                ctx.moveTo(first.x * width, first.y * height);
                for (let i = 1; i < stroke.points.length; i++) ctx.lineTo(stroke.points[i].x * width, stroke.points[i].y * height);
                ctx.stroke();
            }
        }
        ctx.globalAlpha = 1;
        if (snapshot.cursor === 'laser' && snapshot.pointer) {
            ctx.fillStyle = '#ff2222'; ctx.shadowColor = '#ff0000'; ctx.shadowBlur = minor * 0.018;
            ctx.beginPath(); ctx.arc(snapshot.pointer.x * width, snapshot.pointer.y * height, minor * 0.008, 0, Math.PI * 2); ctx.fill();
            ctx.shadowBlur = 0;
        }
        if (timer?.visible && !['idle', 'disposed'].includes(timer.status) && typeof timer.label === 'string') {
            const size = Math.max(1, minor * 0.055); const margin = minor * 0.03;
            ctx.font = `600 ${size}px sans-serif`; ctx.textAlign = 'right'; ctx.textBaseline = 'top';
            ctx.fillStyle = 'rgba(0,0,0,0.75)';
            const boxWidth = Math.min(width - margin * 2, size * (timer.label.length * 0.7 + 0.7));
            ctx.fillRect(width - margin - boxWidth, margin, boxWidth, size * 1.5);
            ctx.fillStyle = '#ffffff'; ctx.fillText(timer.label, width - margin - size * 0.25, margin + size * 0.2);
        }
    } finally { ctx.restore(); }
}
