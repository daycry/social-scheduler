import { Rect, Textbox, Group, Path, LayoutManager, FixedLayout } from 'fabric';
import { normalizeConnector } from './board.js';

const clone = value => JSON.parse(JSON.stringify(value));

/**
 * A table is a fixed group of cell rectangles and wrapping text boxes. Columns share the element width;
 * each row grows to fit its longest cell, so the text reflows instead of being clipped.
 */
export function createTableFabric(element, options) {
    const { table } = element;
    const columns = table.rows[0].length;
    const columnWidth = element.width / columns;
    const padding = Math.round(table.fontSize * 0.4);
    const texts = table.rows.map((row, r) => row.map((cell, c) => {
        const header = table.header && r === 0;
        return new Textbox(cell, {
            originX: 'left', originY: 'top', left: c * columnWidth + padding, top: 0, width: Math.max(1, columnWidth - padding * 2),
            fontFamily: 'Arial', fontSize: table.fontSize, fontWeight: header ? 'bold' : 'normal', textAlign: table.align,
            fill: header ? table.headerColor : table.textColor, lineHeight: 1.2, splitByGrapheme: true,
        });
    }));
    const heights = texts.map(row => Math.ceil(Math.max(...row.map(text => text.height)) + padding * 2));
    const total = heights.reduce((sum, value) => sum + value, 0);
    const children = [];
    let top = 0;
    table.rows.forEach((row, r) => {
        const header = table.header && r === 0;
        row.forEach((_, c) => {
            children.push(new Rect({ originX: 'left', originY: 'top', left: c * columnWidth - element.width / 2, top: top - total / 2, width: columnWidth, height: heights[r],
                fill: header ? table.headerFill : table.cellFill, stroke: table.borderColor, strokeWidth: 1, strokeUniform: true, objectCaching: false }));
            texts[r][c].set({ left: c * columnWidth + padding - element.width / 2, top: top + padding - total / 2 });
            children.push(texts[r][c]);
        });
        top += heights[r];
    });
    const group = new Group(children, { ...options, width: element.width, height: total, layoutManager: new LayoutManager(new FixedLayout()), subTargetCheck: false, interactive: false });
    children.forEach(child => child.setCoords());
    group.studioTable = clone(table);
    return group;
}

/** Rebuilds the route from its end points, so a changed route, arrow or stroke keeps one drawing. */
export function createConnectorFabric(element, options) {
    const frame = normalizeConnector(element);
    const object = new Path(frame.commands, { ...options, left: frame.x, top: frame.y, strokeLineCap: 'round', strokeLineJoin: 'round', fill: '' });
    object.studioConnector = { from: element.from ?? null, to: element.to ?? null, route: element.route ?? 'straight', arrow: element.arrow ?? 'end', points: frame.points };
    return object;
}
