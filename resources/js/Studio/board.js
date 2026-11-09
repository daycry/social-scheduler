/**
 * Pure geometry for the whiteboard connectors. Everything here works on plain numbers,
 * so the same routes can be tested without a browser and rebuilt after any edit.
 */
const round = value => Math.round(value * 100) / 100;

export const CONNECTOR_ROUTES = Object.freeze(['straight', 'elbow']);
export const CONNECTOR_ARROWS = Object.freeze(['none', 'end', 'both']);

/** Side midpoints facing each other: horizontal when the shapes are further apart sideways than vertically. */
export function anchorPoints(from, to) {
    const centre = rect => ({ x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 });
    const a = centre(from);
    const b = centre(to);
    const horizontal = Math.abs(b.x - a.x) >= Math.abs(b.y - a.y);
    if (horizontal) {
        return { horizontal, start: { x: b.x >= a.x ? from.left + from.width : from.left, y: a.y }, end: { x: b.x >= a.x ? to.left : to.left + to.width, y: b.y } };
    }
    return { horizontal, start: { x: a.x, y: b.y >= a.y ? from.top + from.height : from.top }, end: { x: b.x, y: b.y >= a.y ? to.top : to.top + to.height } };
}

/** Orientation of the first segment of an existing route, so editing the route keeps its direction. */
export function orientationOf(points) {
    const [a, b] = points;
    return Math.abs(b[0] - a[0]) >= Math.abs(b[1] - a[1]);
}

export function routePoints(start, end, route = 'straight', horizontal = true) {
    if (route !== 'elbow') return [[start.x, start.y], [end.x, end.y]];
    if (horizontal) {
        const middle = (start.x + end.x) / 2;
        return [[start.x, start.y], [middle, start.y], [middle, end.y], [end.x, end.y]];
    }
    const middle = (start.y + end.y) / 2;
    return [[start.x, start.y], [start.x, middle], [end.x, middle], [end.x, end.y]];
}

function arrowHead(tip, from, size) {
    const angle = Math.atan2(tip[1] - from[1], tip[0] - from[0]);
    const wing = side => [round(tip[0] - size * Math.cos(angle + side)), round(tip[1] - size * Math.sin(angle + side))];
    const left = wing(Math.PI / 6);
    const right = wing(-Math.PI / 6);
    return [['M', left[0], left[1]], ['L', round(tip[0]), round(tip[1])], ['L', right[0], right[1]]];
}

/** Fabric path commands: the route plus open arrow heads, so the stroke colour paints all of it. */
export function connectorCommands(points, arrow = 'end', strokeWidth = 4) {
    const size = Math.max(14, strokeWidth * 4);
    const commands = points.map(([x, y], index) => [index === 0 ? 'M' : 'L', round(x), round(y)]);
    if (arrow === 'end' || arrow === 'both') commands.push(...arrowHead(points.at(-1), points.at(-2), size));
    if (arrow === 'both') commands.push(...arrowHead(points[0], points[1], size));
    return commands;
}

export function commandsFrame(commands) {
    const xs = commands.map(command => command[1]);
    const ys = commands.map(command => command[2]);
    const minX = Math.min(...xs);
    const minY = Math.min(...ys);
    return { minX, minY, width: Math.max(...xs) - minX, height: Math.max(...ys) - minY };
}

/**
 * Canonical form: the stored points are relative to the top-left of the drawn route (arrow heads
 * included), so the element x/y is simply where the drawing starts on the page.
 */
export function normalizeConnector(element) {
    const absolute = element.points.map(([x, y]) => [x + element.x, y + element.y]);
    const horizontal = orientationOf(absolute);
    const route = routePoints({ x: absolute[0][0], y: absolute[0][1] }, { x: absolute.at(-1)[0], y: absolute.at(-1)[1] }, element.route ?? 'straight', horizontal);
    const commands = connectorCommands(route, element.arrow ?? 'end', element.strokeWidth ?? 4);
    const frame = commandsFrame(commands);
    return {
        x: round(frame.minX), y: round(frame.minY), width: round(frame.width), height: round(frame.height),
        points: route.map(([x, y]) => [round(x - frame.minX), round(y - frame.minY)]),
        commands: commands.map(([type, x, y]) => [type, round(x - frame.minX), round(y - frame.minY)]),
    };
}

const IDENTITY = Object.freeze({ scaleX: 1, scaleY: 1, rotation: 0, skewX: 0, skewY: 0, flipX: false, flipY: false });

/**
 * Recompute a connector against the rectangles (page coordinates) of the shapes it is glued to.
 * A missing rectangle means the shape is gone, so that end is detached. The points are in page
 * coordinates, hence the redrawn connector carries the identity transform, not the old one.
 * Returns null when nothing changes.
 */
export function rerouteConnector(current, fromRect, toRect) {
    // A free connector has nothing to follow: keep whatever transform the user gave it.
    if (!fromRect && !toRect && current.from == null && current.to == null) return null;
    const next = { ...current, from: fromRect ? current.from : null, to: toRect ? current.to : null };
    const absolute = current.points.map(([x, y]) => ({ x: x + current.x, y: y + current.y }));
    let start = absolute[0];
    let end = absolute.at(-1);
    let horizontal = Math.abs(absolute[1].x - absolute[0].x) >= Math.abs(absolute[1].y - absolute[0].y);
    if (fromRect && toRect) ({ start, end, horizontal } = anchorPoints(fromRect, toRect));
    else if (fromRect || toRect) {
        const free = fromRect ? end : start;
        const dot = { left: free.x, top: free.y, width: 0, height: 0 };
        const placed = anchorPoints(fromRect ?? dot, fromRect ? dot : toRect);
        if (fromRect) ({ start, horizontal } = placed); else ({ end, horizontal } = placed);
    }
    const points = routePoints(start, end, current.route, horizontal);
    const same = next.from === current.from && next.to === current.to && points.length === absolute.length
        && points.every(([x, y], i) => Math.abs(x - absolute[i].x) < 0.5 && Math.abs(y - absolute[i].y) < 0.5)
        && Object.entries(IDENTITY).every(([key, value]) => (current[key] ?? value) === value);
    if (same) return null;
    const frame = normalizeConnector({ ...next, x: 0, y: 0, points });
    return { ...next, ...IDENTITY, ...frame, points: frame.points };
}
