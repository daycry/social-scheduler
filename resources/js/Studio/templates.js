import { blankDocument, duplicatePage, newElement, validateDocument } from './document.js';

/** Original layouts. No stock images, proprietary Canva designs or premium assets. */
export const STUDIO_TEMPLATES = Object.freeze([
    { key: 'aurora-launch', name: 'Aurora', category: 'Lanzamientos', width: 1080, height: 1080, accent: '#7c3aed', background: '#18132b', description: 'Luz, tipografía grande y una composición con profundidad.', pages: 1 },
    { key: 'bold-statement', name: 'En voz alta', category: 'Redes sociales', width: 1080, height: 1080, accent: '#e85d3f', background: '#f9f0dd', description: 'Un mensaje rotundo con contraste editorial.', pages: 1 },
    { key: 'minimal-quote', name: 'Una buena idea', category: 'Citas', width: 1080, height: 1080, accent: '#879e70', background: '#f5f3ed', description: 'Una cita con espacio, ritmo y una firma discreta.', pages: 1 },
    { key: 'organic-note', name: 'Natural', category: 'Redes sociales', width: 1080, height: 1080, accent: '#e1a66e', background: '#edf1e6', description: 'Formas suaves y una paleta orgánica.', pages: 1 },
    { key: 'studio-offer', name: 'La oportunidad', category: 'Promociones', width: 1080, height: 1080, accent: '#2563eb', background: '#f1f5fb', description: 'Oferta clara con precio y llamada a la acción.', pages: 1 },
    { key: 'social-announcement', name: 'Lo nuevo', category: 'Noticias', width: 1080, height: 1080, accent: '#d6487a', background: '#fff4f7', description: 'Una novedad presentada con estructura de revista.', pages: 1 },
    { key: 'story-editorial', name: 'Entre líneas', category: 'Historias', width: 1080, height: 1920, accent: '#b68858', background: '#ede9e1', description: 'Historia vertical con jerarquía editorial.', pages: 1 },
    { key: 'story-launch', name: 'Muy pronto', category: 'Historias', width: 1080, height: 1920, accent: '#9e7bff', background: '#151125', description: 'Presentación vertical luminosa para un lanzamiento.', pages: 1 },
    { key: 'story-event', name: 'Nos vemos', category: 'Eventos', width: 1080, height: 1920, accent: '#ff8a48', background: '#142c32', description: 'Cartel vertical con fecha, programa y lugar.', pages: 1 },
    { key: 'portrait-spotlight', name: 'En primer plano', category: 'Retratos', width: 1080, height: 1440, accent: '#806659', background: '#f2ece5', description: 'Composición 3:4 para presentar personas o proyectos.', pages: 1 },
    { key: 'carousel-story', name: 'De principio a fin', category: 'Carruseles', width: 1080, height: 1350, accent: '#4f46e5', background: '#f5f4ff', description: 'Tres páginas para contar una idea con continuidad.', pages: 3 },
    { key: 'deck-cover', name: 'Una gran presentación', category: 'Presentaciones', width: 1920, height: 1080, accent: '#386f67', background: '#eef4f0', description: 'Portada panorámica con una composición geométrica.', pages: 1 },
    { key: 'deck-agenda', name: 'El siguiente paso', category: 'Presentaciones', width: 1920, height: 1080, accent: '#b4573c', background: '#f7f0e9', description: 'Tres diapositivas de portada, agenda y cierre.', pages: 3 },
    { key: 'video-title', name: 'En movimiento', category: 'Vídeo', width: 1920, height: 1080, accent: '#d7ef62', background: '#151b20', description: 'Una tarjeta de apertura para tu próxima pieza.', pages: 1 },
]);

const hex = value => typeof value === 'string' && /^#(?:[a-f\d]{3}|[a-f\d]{4}|[a-f\d]{6}|[a-f\d]{8})$/i.test(value);

export function createFromTemplate(key, kit = {}) {
    const template = STUDIO_TEMPLATES.find(item => item.key === key);
    if (!template) throw new Error('La plantilla seleccionada no está disponible.');
    const palette = Array.isArray(kit.palette) ? kit.palette.filter(hex) : [];
    const dark = palette[0] ?? '#182128';
    const accent = palette[1] ?? template.accent;
    const paper = palette[2] ?? template.background;
    const white = '#ffffff';
    const font = Array.isArray(kit.fonts) && typeof kit.fonts[0] === 'string' ? kit.fonts[0] : 'Arial';
    const document = blankDocument(template.width, template.height);
    const page = document.pages[0];
    page.name = template.name;
    page.background = paper;
    const w = template.width;
    const h = template.height;
    const elements = page.elements;
    const rect = (x, y, width, height, fill, patch = {}) => elements.push(newElement('rect', { x, y, width, height, fill, ...patch }));
    const ellipse = (x, y, width, height, fill, patch = {}) => elements.push(newElement('ellipse', { x, y, width, height, fill, ...patch }));
    const text = (value, x, y, width, size, fill = dark, patch = {}) => elements.push(newElement('text', { text: value, x, y, width, height: size * 1.4, fontSize: size, fontFamily: font, fill, lineHeight: 1.1, ...patch }));
    const label = (value, x = 80, y = 70, fill = dark) => text(value, x, y, w - 160, 24, fill, { charSpacing: 160, fontWeight: 'bold' });
    const footer = (value = 'TU MARCA · HECHO CON INTENCIÓN', fill = dark) => text(value, 80, h - 90, w - 160, 21, fill, { charSpacing: 80 });
    const gradient = (from, to, angle = 45) => ({ type: 'linear', angle, stops: [{ offset: 0, color: from }, { offset: 1, color: to }] });

    switch (key) {
        case 'aurora-launch':
            page.background = gradient(dark, accent, 130);
            ellipse(470, -220, 900, 900, white, { opacity: 0.12 });
            ellipse(-250, 600, 740, 740, accent, { opacity: 0.8 });
            rect(80, 190, 74, 8, white, { radius: 4 });
            label('UNA NUEVA PERSPECTIVA', 80, 80, white);
            text('Haz espacio\na lo nuevo.', 80, 280, 920, 120, white, { fontWeight: 'bold', lineHeight: 1.05 });
            text('Tu próxima gran idea\nempieza aquí.', 86, 620, 750, 36, white);
            rect(80, 790, 330, 76, white, { radius: 38 });
            text('DESCÚBRELO', 115, 813, 270, 23, dark, { fontWeight: 'bold', charSpacing: 90 });
            footer('TU MARCA · NUEVA COLECCIÓN', white);
            break;
        case 'bold-statement':
            rect(0, 0, w, 24, accent);
            label('IDEAS QUE MERECEN ESPACIO');
            text('PIENSA\nEN GRANDE.', 75, 210, 950, 130, dark, { fontWeight: 'bold', lineHeight: 0.95 });
            rect(80, 605, 900, 18, accent);
            text('Lo extraordinario empieza\ncon una decisión pequeña.', 85, 690, 850, 38);
            footer();
            break;
        case 'minimal-quote':
            text('“', 75, 150, 300, 240, accent, { fontFamily: font, fontWeight: 'bold' });
            text('Las ideas\ncrecen cuando\nlas compartes.', 100, 340, 860, 82, dark, { lineHeight: 1.15 });
            rect(100, 735, 95, 5, accent);
            text('UNA REFLEXIÓN PARA HOY', 100, 780, 850, 22, dark, { charSpacing: 120 });
            footer();
            break;
        case 'organic-note':
            ellipse(-150, -200, 700, 700, accent, { opacity: 0.45 });
            ellipse(650, 720, 550, 550, dark, { opacity: 0.12 });
            rect(70, 75, 940, 930, '#ffffff', { opacity: 0.88, radius: 42 });
            label('VUELVE A LO ESENCIAL', 120, 145);
            text('Menos ruido.\nMás sentido.', 115, 330, 850, 98, dark, { fontWeight: 'bold' });
            text('Una manera más consciente\nde hacer las cosas.', 120, 650, 800, 35);
            text('TU MARCA', 120, 900, 800, 24, accent, { fontWeight: 'bold', charSpacing: 120 });
            break;
        case 'studio-offer':
            label('SOLO POR UN TIEMPO');
            text('Una oportunidad\na tu medida.', 80, 190, 900, 78, dark, { fontWeight: 'bold' });
            rect(70, 450, 940, 370, dark, { radius: 32 });
            text('30%', 115, 485, 800, 180, white, { fontWeight: 'bold' });
            text('EN TU PRÓXIMO PEDIDO', 120, 715, 800, 26, white, { charSpacing: 100 });
            rect(80, 870, 480, 75, accent, { radius: 15 });
            text('CONOCE LOS DETALLES', 110, 894, 420, 22, white, { fontWeight: 'bold' });
            break;
        case 'social-announcement':
            label('EL DIARIO DE TU MARCA');
            rect(80, 145, 920, 3, dark);
            text('Tenemos\nalgo que\ncontarte.', 80, 205, 850, 115, dark, { fontWeight: 'bold', lineHeight: 1.02 });
            ellipse(780, 580, 200, 200, accent);
            text('NUEVO', 813, 664, 145, 23, white, { fontWeight: 'bold' });
            rect(80, 825, 920, 3, dark);
            text('Un nuevo capítulo.\nLa misma esencia.', 80, 875, 800, 35);
            break;
        case 'story-editorial':
            label('EDICIÓN ESPECIAL', 90, 110);
            rect(90, 200, 900, 4, dark);
            ellipse(270, 365, 720, 720, accent, { opacity: 0.55 });
            rect(90, 330, 480, 780, dark, { rotation: -4, radius: 6 });
            text('Un nuevo\npunto de\nvista.', 95, 1180, 900, 128, dark, { fontWeight: 'bold' });
            text('Mira de cerca. Hay mucho\npor descubrir.', 100, 1640, 850, 37);
            footer('TU MARCA · SIGUE EXPLORANDO');
            break;
        case 'story-launch':
            page.background = gradient(dark, accent, 100);
            ellipse(-350, 200, 1400, 1400, white, { opacity: 0.07 });
            ellipse(250, 500, 900, 900, white, { opacity: 0.1 });
            label('EL FUTURO YA ESTÁ AQUÍ', 80, 110, white);
            text('Algo\nincreíble\nestá por\nllegar.', 80, 380, 920, 156, white, { fontWeight: 'bold', lineHeight: 0.98 });
            text('Sé parte del siguiente capítulo.', 86, 1320, 850, 37, white);
            rect(80, 1520, 760, 95, white, { radius: 48 });
            text('GUARDA LA FECHA', 150, 1553, 650, 29, dark, { fontWeight: 'bold', charSpacing: 80 });
            footer('TU MARCA · MUY PRONTO', white);
            break;
        case 'story-event':
            page.background = dark;
            label('ENCUENTROS QUE INSPIRAN', 80, 110, white);
            text('NOS\nVEMOS.', 70, 270, 970, 190, white, { fontWeight: 'bold', lineHeight: 0.94 });
            rect(80, 800, 920, 3, accent);
            text('24 / 06', 80, 900, 900, 125, accent, { fontWeight: 'bold' });
            text('18:30 H · EN TU CIUDAD', 85, 1090, 900, 31, white, { charSpacing: 60 });
            text('Conversaciones, ideas\ny nuevas conexiones.', 85, 1320, 890, 46, white);
            rect(80, 1630, 920, 85, accent, { radius: 16 });
            text('RESERVA TU PLAZA', 130, 1657, 820, 29, dark, { fontWeight: 'bold', textAlign: 'center' });
            break;
        case 'portrait-spotlight':
            label('PERSONAS · IDEAS · PROYECTOS');
            rect(80, 220, 920, 760, accent, { radius: 36 });
            ellipse(280, 370, 520, 520, white, { opacity: 0.24 });
            text('Tu historia\nmerece ser\ncontada.', 125, 385, 820, 100, white, { fontWeight: 'bold' });
            text('En primer plano.', 85, 1080, 900, 70, dark, { fontWeight: 'bold' });
            text('Una mirada más cercana\na lo que nos mueve.', 90, 1220, 840, 34);
            footer();
            break;
        case 'carousel-story':
            label('UNA IDEA, TRES PASOS');
            text('Empieza\npor aquí.', 80, 260, 920, 135, dark, { fontWeight: 'bold' });
            rect(80, 720, 920, 300, accent, { radius: 30 });
            text('Pequeños cambios.\nGrandes posibilidades.', 120, 805, 850, 54, white);
            footer('01 / 03 · DESLIZA PARA CONTINUAR');
            break;
        case 'deck-cover':
            label('IDEAS PARA EL SIGUIENTE CAPÍTULO', 110, 90);
            rect(1270, -180, 700, 1400, accent, { rotation: 18 });
            ellipse(1390, 540, 470, 470, white, { opacity: 0.3 });
            text('Lo que\nviene ahora.', 110, 280, 1220, 165, dark, { fontWeight: 'bold', lineHeight: 1.02 });
            text('Una presentación para mirar hacia delante.', 120, 780, 1180, 38);
            text('TU MARCA · 2026', 120, 950, 1200, 25, dark, { charSpacing: 100 });
            break;
        case 'deck-agenda':
            label('ESTRATEGIA CON INTENCIÓN', 110, 90);
            text('Nuestro\npróximo paso.', 110, 270, 1500, 150, dark, { fontWeight: 'bold' });
            rect(110, 730, 1300, 10, accent);
            text('Un plan claro para avanzar juntos.', 120, 810, 1400, 42);
            footer();
            break;
        case 'video-title':
            page.background = dark;
            rect(100, 100, 12, 880, accent);
            label('UNA HISTORIA DE TU MARCA', 160, 145, white);
            text('Dale al\nsiguiente paso.', 150, 320, 1550, 160, white, { fontWeight: 'bold', lineHeight: 1.03 });
            text('Todo empieza con una idea.', 160, 800, 1500, 42, accent);
            page.duration = 5;
            break;
    }
    if (key === 'carousel-story' || key === 'deck-agenda') {
        const second = duplicatePage(page);
        second.name = key === 'carousel-story' ? 'Desarrolla la idea' : 'Nuestra agenda';
        second.elements = [newElement('text', { text: '01. Descubre\n02. Conecta\n03. Da el siguiente paso', x: 100, y: h * 0.3, width: w - 200, height: h * 0.5, fontFamily: font, fontSize: key === 'carousel-story' ? 73 : 100, fontWeight: 'bold', fill: dark, lineHeight: 1.8 }), newElement('rect', { x: 100, y: h * 0.2, width: 100, height: 10, fill: accent })];
        const third = duplicatePage(page);
        third.name = 'El siguiente paso';
        third.background = dark;
        third.elements = [newElement('text', { text: '¿Empezamos?', x: 80, y: h * 0.34, width: w - 160, height: 220, fontFamily: font, fontSize: key === 'carousel-story' ? 108 : 160, fontWeight: 'bold', fill: white, textAlign: 'center' }), newElement('text', { text: 'TU MARCA · HABLEMOS', x: 80, y: h * 0.72, width: w - 160, height: 80, fontFamily: font, fontSize: 30, fill: accent, charSpacing: 100, textAlign: 'center' })];
        document.pages.push(second, third);
    }
    return validateDocument(document);
}
