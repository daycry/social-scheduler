import { duplicatePage } from './document.js';
import { STUDIO_FORMAT_PRESETS } from './format-presets.js';

const card = (key, name, title, detail, action) => ({ key, name, title, detail, action });

const concepts = [
    {
        layout: 'bulletin', category: 'Noticias', accent: '#d3613d', background: '#fff3e7', motion: 'fade',
        cards: {
            square: card('bulletin-hours', 'Nuevo horario', 'Abrimos más tarde', 'De lunes a viernes, hasta las 20 h.', 'Consulta el horario completo'),
            portrait: card('bulletin-pickup', 'Recogida en tienda', 'Tu pedido te espera', 'Recoge tu compra en nuestro mostrador.', 'Ver puntos de recogida'),
            'portrait-three-four': card('bulletin-team', 'Nuevas incorporaciones', 'El equipo crece', 'Damos la bienvenida a quienes se suman.', 'Conoce al equipo'),
            story: card('bulletin-schedule', 'Agenda de la semana', 'Esta semana', 'Taller, directo y novedades en la tienda.', 'Guarda la agenda'),
            landscape: card('bulletin-space', 'Estrenamos espacio', 'Nos mudamos', 'Muy pronto te recibimos en otra dirección.', 'Consulta cómo llegar'),
        },
    },
    {
        layout: 'quote', category: 'Citas', accent: '#708c62', background: '#f2f5e7',
        cards: {
            square: card('quote-small-steps', 'Pequeños pasos', 'Un paso pequeño también cuenta.', 'Una idea para hoy'),
            portrait: card('quote-listening', 'El valor de escuchar', 'Escuchar también es una forma de cuidar.', 'Nota del equipo'),
            'portrait-three-four': card('quote-questions', 'Buenas preguntas', 'Una buena pregunta abre camino.', 'Idea de la semana'),
            story: card('quote-quiet', 'Tiempo para parar', 'Haz sitio para lo que importa.', 'Pausa del día'),
            landscape: card('quote-sharing', 'Ideas que se comparten', 'Las ideas crecen al compartirlas.', 'Reflexión de apertura'),
        },
    },
    {
        layout: 'offer', category: 'Promociones', accent: '#efb836', background: '#fff7d7', motion: 'zoom',
        cards: {
            square: card('offer-weekend', 'Oferta de fin de semana', '−20 %', 'En productos seleccionados.', 'Ver la selección'),
            portrait: card('offer-set', 'Lote especial', 'Lote dúo', 'Dos favoritos en un solo pedido.', 'Descubre el lote'),
            'portrait-three-four': card('offer-delivery', 'Envío de regalo', 'Envío gratis', 'Disponible durante la promoción.', 'Consulta las condiciones'),
            story: card('offer-hours', 'Últimas horas', 'Hasta hoy', 'Todavía estás a tiempo de aprovecharlo.', 'Ver la oferta'),
            landscape: card('offer-members', 'Ventaja para socios', 'Precio especial', 'Para miembros de nuestra comunidad.', 'Consulta la promoción'),
        },
    },
    {
        layout: 'event', category: 'Eventos', accent: '#508f9d', background: '#eaf6f5', motion: 'slide_up',
        cards: {
            square: card('event-open-doors', 'Puertas abiertas', 'Ven a vernos', 'Sábado · 11 h\nEn nuestro espacio', 'Reserva tu plaza'),
            portrait: card('event-workshop', 'Taller creativo', 'Crea con papel', 'Jueves · 18 h\nMaterial incluido', 'Inscríbete al taller'),
            'portrait-three-four': card('event-meetup', 'Encuentro local', 'Conoce a tu comunidad', 'Viernes · 19 h\nPunto de encuentro en la plaza', 'Apúntate al encuentro'),
            story: card('event-live', 'Directo de preguntas', 'Hoy en directo', 'A las 19 h\nTrae tus preguntas', 'Activa el recordatorio'),
            landscape: card('event-conference', 'Jornada de charlas', 'Compartir para aprender', 'Martes · 10 h\nAuditorio principal', 'Consulta el programa'),
        },
    },
    {
        layout: 'launch', category: 'Lanzamientos', accent: '#a895ec', background: '#f1ecff', motion: 'slide_right',
        cards: {
            square: card('launch-season', 'Nueva temporada', 'Empieza la temporada', 'Ideas nuevas para tus días de siempre.', 'Explora la colección'),
            portrait: card('launch-guide', 'Presentación de una guía', 'Ya está aquí la guía', 'Un punto de partida para tu próximo proyecto.', 'Descarga la guía'),
            'portrait-three-four': card('launch-service', 'Nuevo servicio', 'Ahora te acompañamos', 'Agenda una sesión con nuestro equipo.', 'Conoce el servicio'),
            story: card('launch-first-look', 'Primer vistazo', 'Mira lo que llega', 'Un adelanto de nuestra próxima colección.', 'Descubre la novedad'),
            landscape: card('launch-series', 'Estreno de una serie', 'Comienza una nueva serie', 'El primer episodio ya está disponible.', 'Ver el estreno'),
        },
    },
    {
        layout: 'testimonial', category: 'Testimonios', accent: '#bc6d83', background: '#fceef1',
        cards: {
            square: card('testimonial-client', 'Voz de un cliente', '«Me explicaron cada paso con claridad.»', 'Nombre del cliente', 'Añade aquí el servicio'),
            portrait: card('testimonial-project', 'Reseña de un proyecto', '«Entendieron lo que necesitábamos desde el inicio.»', 'Nombre del equipo', 'Añade aquí el proyecto'),
            'portrait-three-four': card('testimonial-store', 'Reseña de tienda', '«Encontré justo lo que buscaba.»', 'Nombre del cliente', 'Añade aquí el producto'),
            story: card('testimonial-experience', 'Una buena experiencia', '«Me sentí en buenas manos.»', 'Nombre del cliente', 'Añade aquí su experiencia'),
            landscape: card('testimonial-case', 'El cliente cuenta', '«Ahora sabemos cómo dar el siguiente paso.»', 'Nombre de la organización', 'Añade aquí el caso'),
        },
    },
    {
        layout: 'list', category: 'Listas', accent: '#3879a2', background: '#edf5fc',
        cards: {
            square: card('list-plan', 'Tres ideas para empezar', 'Empieza por aquí', ['Define tu objetivo', 'Elige un primer paso', 'Ponle fecha'], 'Guárdalo para después'),
            portrait: card('list-bag', 'Lista de imprescindibles', 'Prepara tu mochila', ['Agua para el camino', 'Una capa de abrigo', 'Mapa o ruta sin conexión'], 'Revísala antes de salir'),
            'portrait-three-four': card('list-week', 'Plan semanal', 'Organiza la semana', ['Revisa los pendientes', 'Elige tres prioridades', 'Reserva tiempo de descanso'], 'Adáptala a tu ritmo'),
            story: card('list-content', 'Ideas para publicar', '¿Qué publico hoy?', ['Un truco de tu oficio', 'Una duda frecuente', 'Un vistazo al proceso'], 'Guarda estas ideas'),
            landscape: card('list-brief', 'Lista para una reunión', 'Antes de reunirnos', ['Comparte el objetivo', 'Envía el material', 'Acuerda quién toma notas'], 'Comparte la lista'),
        },
    },
    {
        layout: 'comparison', category: 'Antes y después', accent: '#558a79', background: '#edf7ed',
        cards: {
            square: card('comparison-desk', 'Cambio de escritorio', 'Un lugar para cada cosa', ['Material repartido por toda la mesa', 'Superficie libre y lo esencial a mano'], 'Comparte tu cambio'),
            portrait: card('comparison-routine', 'Cambio de rutina', 'Una mañana con calma', ['Decisiones de última hora', 'Lista breve y tiempo reservado'], 'Cuenta qué te ayudó'),
            'portrait-three-four': card('comparison-brief', 'Cambio de presentación', 'Menos texto, más claridad', ['Todas las ideas en una diapositiva', 'Una idea clara en cada pantalla'], 'Muestra tu proceso'),
            story: card('comparison-studio', 'Cambio en el taller', 'El taller en dos momentos', ['Herramientas difíciles de encontrar', 'Utensilios ordenados por uso'], 'Desliza para ver el cambio'),
            landscape: card('comparison-process', 'Evolución de un proyecto', 'Del borrador al plan', ['Ideas sueltas sin fecha', 'Tareas asignadas y plazos claros'], 'Resume lo que cambió'),
        },
    },
    {
        layout: 'carousel', category: 'Carruseles', accent: '#6e68ba', background: '#f3effc', motion: 'fade',
        cards: {
            square: card('carousel-project', 'Cuenta un proyecto', 'Así nació el proyecto', ['Qué queríamos resolver', 'Qué probamos primero', 'Qué aprendimos'], 'Cuéntanos tu idea'),
            portrait: card('carousel-reuse', 'Guía para reutilizar', 'Dale otro uso', ['Elige lo que ya tienes', 'Piensa en otra función', 'Prueba una mejora pequeña'], 'Comparte tu resultado'),
            'portrait-three-four': card('carousel-about', 'Presenta tu marca', 'Hola, somos tu equipo', ['Qué nos mueve', 'Cómo trabajamos', 'Dónde puedes encontrarnos'], 'Ponte en contacto'),
            story: card('carousel-questions', 'Serie de preguntas', 'Nos preguntáis mucho', ['Cómo reservar', 'Qué necesitas traer', 'Cuándo respondemos'], 'Envíanos otra pregunta'),
            landscape: card('carousel-guide', 'Guía en tres pantallas', 'Prepara tu primer directo', ['Elige un tema concreto', 'Prueba cámara y sonido', 'Anuncia la hora'], 'Comparte la guía'),
        },
    },
    {
        layout: 'tip', category: 'Redes sociales', accent: '#cf8f47', background: '#fbf0e4',
        cards: {
            square: card('tip-break', 'Consejo de descanso', 'Haz una pausa', 'Un descanso breve ayuda a retomar con atención.', 'Pon un recordatorio'),
            portrait: card('tip-notes', 'Consejo para tus notas', 'Anota la idea', 'Guarda una frase y añade una acción concreta.', 'Pruébalo con tu próxima idea'),
            'portrait-three-four': card('tip-edit', 'Consejo de edición', 'Lee en voz alta', 'Si te falta aire, divide la frase en dos.', 'Revisa tu próximo texto'),
            story: card('tip-light', 'Consejo para grabar', 'Busca la luz', 'Colócate de frente a una ventana para grabar.', 'Prueba antes de empezar'),
            landscape: card('tip-feedback', 'Consejo de equipo', 'Pide una opinión', 'Pregunta qué se entiende y qué falta por explicar.', 'Elige una pregunta concreta'),
        },
    },
];

export const SOCIAL_TEMPLATES = concepts.flatMap(({ cards, ...style }) =>
    Object.entries(cards).map(([format, content]) => {
        const { w, h } = STUDIO_FORMAT_PRESETS.find(preset => preset.key === format);
        return { ...style, ...content, width: w, height: h, pages: style.layout === 'carousel' ? 3 : 1 };
    }),
);

// Conserva el color de marca si tiene contraste suficiente para texto normal.
function luminance(color) {
    const hex = color.length <= 5 ? [...color.slice(1)].map(digit => digit.repeat(2)).join('') : color.slice(1);
    const alpha = hex.length === 8 ? parseInt(hex.slice(6), 16) / 255 : 1;
    return [0.2126, 0.7152, 0.0722].reduce((sum, weight, index) => {
        const channel = (parseInt(hex.slice(index * 2, index * 2 + 2), 16) / 255) * alpha + (1 - alpha);
        return sum + weight * (channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
    }, 0);
}

function readableInk(background, preferred) {
    const bg = luminance(background) + 0.05;
    const ink = luminance(preferred) + 0.05;
    if (Math.max(bg, ink) / Math.min(bg, ink) >= 4.5) return preferred;
    return bg / 0.05 >= 1.05 / bg ? '#000000' : '#ffffff';
}

function layoutTools(page, { width, height }, draw) {
    const { rect, ellipse, text } = draw(page);
    const wide = width > height;
    const box = (x, y, w, h, fill, patch) => rect(x * width, y * height, w * width, h * height, fill, patch);
    const circle = (x, y, diameter, fill, opacity = 1) => ellipse(x * width, y * height, diameter, diameter, fill, { opacity });
    // Reserva espacio para las líneas previstas, incluido el interlineado al envolver el texto.
    const copy = (value, x, y, w, size, fill, name, { lines = 1, ...style } = {}) =>
        text(value, x * width, y * height, w * width, size, fill, { name, height: Math.ceil(size * 1.25 * lines), ...style });
    return { wide, box, circle, copy };
}

export function buildSocialTemplate(document, template, { dark, accent, paper }, draw) {
    const page = document.pages[0];
    const { layout, name, category, title, detail, action, motion } = template;
    const ink = readableInk(paper, dark);
    const inverse = readableInk(dark, '#ffffff');
    const accentInk = readableInk(accent, dark);
    const { wide, box, circle, copy } = layoutTools(page, document, draw);
    const bold = { fontWeight: 'bold' };
    const animation = motion ? { animation: {
        in: { type: motion, duration: 0.65, easing: 'ease_out' },
        out: { type: 'fade', duration: 0.45, easing: 'ease_in' },
    } } : {};
    const label = (x = 0.09, y = 0.11, fill = ink) => copy(category.toLocaleUpperCase('es'), x, y, 0.78, 29, fill, 'Categoría', { ...bold, charSpacing: 90 });
    const headline = (x, y, w, size, fill = ink, style) => copy(title, x, y, w, size, fill, 'Titular', { lines: 2, ...bold, ...animation, ...style });
    const detailAt = (x, y, w, fill = ink, lines = 2) => copy(detail, x, y, w, 40, fill, 'Detalle', { lines });
    const actionAt = (x = 0.09, y = 0.86, w = 0.82, fill = ink) => copy(action, x, y, w, 30, fill, 'Llamada a la acción', bold);
    const list = (message, x, y, w, tone = ink) => copy(message, x, y, w, 37, tone, 'Punto de la lista', bold);

    document.schema_version = 2;
    document.timeline = { fps: 30, audio_tracks: [] };
    page.name = name;
    page.background = paper;
    if (layout === 'carousel') {
        const steps = duplicatePage(page);
        steps.name = 'Los pasos';
        const next = duplicatePage(page);
        next.name = 'Siguiente paso';
        document.pages.push(steps, next);
    }

    switch (layout) {
        case 'bulletin':
            box(0, 0, 0.035, 1, accent);
            box(0.09, 0.19, 0.12, 0.009, accent);
            circle(wide ? 0.83 : 0.73, 0.07, 320, accent, 0.22);
            label();
            headline(0.09, 0.28, wide ? 0.7 : 0.82, 112);
            detailAt(0.09, 0.64, 0.82);
            actionAt();
            break;
        case 'quote':
            box(0.095, 0.16, 0.81, 0.68, accent, { opacity: 0.18, radius: 48 });
            copy('“', 0.18, 0.2, 0.64, 152, ink, 'Comillas', { textAlign: 'center' });
            headline(0.18, 0.39, 0.64, wide ? 92 : 76, ink, { lines: 3, textAlign: 'center', fontStyle: 'italic' });
            copy(detail, 0.18, 0.73, 0.64, 29, ink, 'Firma', { textAlign: 'center' });
            break;
        case 'offer':
            box(0.06, 0.08, 0.88, 0.84, accent, { radius: 44 });
            box(0.095, 0.15, 0.81, 0.7, paper, { radius: 32 });
            label(0.14, 0.21);
            headline(0.14, wide ? 0.27 : 0.33, 0.72, wide ? 144 : 112);
            detailAt(0.14, 0.62, 0.72);
            actionAt(0.14, 0.75, 0.72);
            break;
        case 'event':
            box(wide ? 0.63 : 0, wide ? 0 : 0.57, wide ? 0.37 : 1, wide ? 1 : 0.43, dark);
            box(0.09, 0.19, 0.08, 0.012, accent);
            label();
            headline(0.09, wide ? 0.34 : 0.24, wide ? 0.48 : 0.82, wide ? 86 : 84);
            detailAt(wide ? 0.68 : 0.09, wide ? 0.36 : 0.63, wide ? 0.27 : 0.82, inverse);
            actionAt(wide ? 0.68 : 0.09, wide ? 0.67 : 0.83, wide ? 0.27 : 0.82, inverse);
            break;
        case 'launch':
            page.background = dark;
            circle(wide ? 0.76 : 0.56, 0.19, 680, accent, 0.18);
            circle(wide ? 0.81 : 0.67, 0.26, 410, accent, 0.22);
            label(0.09, 0.13, inverse);
            headline(0.09, 0.32, wide ? 0.63 : 0.8, 102, inverse);
            detailAt(0.09, 0.65, wide ? 0.65 : 0.8, inverse);
            box(0.09, 0.79, wide ? 0.33 : 0.74, 0.09, accent, { radius: 28 });
            actionAt(0.12, 0.814, wide ? 0.28 : 0.66, accentInk);
            break;
        case 'testimonial':
            box(0.06, 0.09, 0.88, 0.83, dark, { radius: 52 });
            box(0.09, 0.12, 0.82, 0.76, paper, { radius: 38 });
            box(0.14, 0.25, 0.09, 0.01, accent);
            label(0.14, 0.19);
            headline(0.14, 0.32, 0.72, wide ? 93 : 74, ink, { lines: 3 });
            circle(0.14, 0.72, 62, accent);
            copy(detail, wide ? 0.19 : 0.23, 0.71, 0.6, 31, ink, 'Nombre', bold);
            actionAt(wide ? 0.19 : 0.23, 0.77, 0.6);
            break;
        case 'list':
            label();
            headline(0.09, 0.19, 0.82, 84);
            detail.forEach((message, index) => {
                const y = 0.39 + index * 0.14;
                box(0.09, y, 0.82, 0.105, accent, { opacity: 0.18, radius: 24 });
                copy(String(index + 1).padStart(2, '0'), 0.125, y + 0.026, 0.1, 41, ink, 'Número', bold);
                list(message, 0.25, y + 0.03, 0.61);
            });
            actionAt();
            break;
        case 'comparison':
            label();
            headline(0.09, 0.18, 0.82, wide ? 90 : 72);
            detail.forEach((message, index) => {
                const x = wide ? 0.09 + index * 0.42 : 0.09;
                const y = wide ? 0.43 : 0.37 + index * 0.235;
                const w = wide ? 0.4 : 0.82;
                const fill = index === 0 ? accentInk : inverse;
                box(x, y, w, wide ? 0.33 : 0.19, index === 0 ? accent : dark, { radius: 28 });
                copy(index === 0 ? 'ANTES' : 'DESPUÉS', x + 0.035, y + 0.03, w - 0.07, 30, fill, 'Momento', bold);
                copy(message, x + 0.035, y + 0.08, w - 0.07, 43, fill, 'Descripción', { lines: 2 });
            });
            actionAt();
            break;
        case 'carousel': {
            label();
            box(0.09, 0.23, 0.11, 0.012, accent);
            headline(0.09, 0.32, 0.78, wide ? 120 : 96);
            copy('Sigue para descubrir los pasos', 0.09, 0.75, 0.66, 37, ink, 'Continuación');
            const [, steps, next] = document.pages;
            const middle = layoutTools(steps, document, draw);
            middle.copy('Los pasos', 0.09, 0.12, 0.82, 90, ink, 'Titular', bold);
            detail.forEach((message, index) => {
                const y = 0.34 + index * 0.18;
                middle.box(0.09, y, 0.82, 0.12, index % 2 ? dark : accent, { radius: 24 });
                middle.copy(message, 0.13, y + 0.033, 0.74, 39, index % 2 ? inverse : accentInk, 'Paso', bold);
            });
            next.background = dark;
            const end = layoutTools(next, document, draw);
            end.box(0.09, 0.34, 0.14, 0.012, accent);
            end.copy(action, 0.09, 0.46, 0.82, 96, inverse, 'Llamada a la acción', { lines: 2, ...bold, ...animation });
            break;
        }
        case 'tip':
            label();
            headline(0.09, 0.25, wide ? 0.48 : 0.82, 106);
            box(wide ? 0.59 : 0.09, wide ? 0.23 : 0.54, wide ? 0.32 : 0.82, wide ? 0.52 : 0.22, accent, { radius: 36 });
            detailAt(wide ? 0.63 : 0.14, wide ? 0.37 : 0.59, wide ? 0.24 : 0.73, accentInk, wide ? 3 : 2);
            actionAt();
            break;
    }
}
