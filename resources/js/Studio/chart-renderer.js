import { Rect, Line, FabricText, Group, LayoutManager, FixedLayout } from 'fabric';
import { createBarChartScene } from './chart-scene.js';

/** Build trusted Fabric objects from our typed scene, never from serialized Fabric/markup. */
export async function createChartFabric(element, { chartResources, fontRegistry, signal, options = {} } = {}) {
    if (element.chart.style?.font_id != null && !fontRegistry) throw new TypeError('Prepare chart private font before rendering.');
    const scene = await createBarChartScene(element.chart, chartResources, { width: element.width, height: element.height, signal });
    const style = fontRegistry?.style(element) ?? element.chart.style ?? {};
    const family = fontRegistry?.family(element) ?? style.fontFamily ?? 'Arial';
    const children = scene.primitives.map(p => {
        const base = { id: `${element.id}:${p.id}`, left: p.x - scene.width / 2, top: p.y - scene.height / 2, originX: 'left', originY: 'top', width: p.width, height: p.height, fill: p.fill ?? null, strokeWidth: 0, selectable: false, evented: false, objectCaching: false };
        if (p.type === 'rect') return new Rect(base);
        if (p.type === 'line') return new Line([0, 0, p.width, p.height], { ...base, stroke: p.stroke, strokeWidth: 1 });
        const text = new FabricText(p.text, { ...base, fontFamily: family, fontSize: p.fontSize, fontWeight: style.fontWeight ?? p.fontWeight, fontStyle: style.fontStyle ?? p.fontStyle });
        // Native text measurement after font readiness; one scale preserves glyph proportions.
        const vertical = p.rotation === -90;
        const fit = Math.max(0, Math.min(1, (vertical ? p.height : p.width) / Math.max(1, text.width), (vertical ? p.width : p.height) / Math.max(1, text.height)));
        text.set({ scaleX: fit, scaleY: fit });
        if (vertical) text.set({ originX: 'center', originY: 'center', left: base.left + p.width / 2, top: base.top + p.height / 2, angle: -90 });
        return text;
    });
    const positions = children.map(child => ({ left: child.left, top: child.top }));
    const group = new Group(children, { ...options, width: scene.width, height: scene.height, layoutManager: new LayoutManager(new FixedLayout()), subTargetCheck: false, interactive: false });
    children.forEach((child, i) => { child.set(positions[i]); child.setCoords(); });
    group.studioType = 'chart';
    group.studioChart = JSON.parse(JSON.stringify(element.chart));
    group.studioChartScene = scene;
    return group;
}
