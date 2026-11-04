export const STUDIO_FORMAT_PRESETS = Object.freeze([
    { key: 'square', label: 'Publicación cuadrada', w: 1080, h: 1080 },
    { key: 'portrait', label: 'Instagram vertical', w: 1080, h: 1350 },
    { key: 'portrait-three-four', label: 'Instagram 3:4', w: 1080, h: 1440 },
    { key: 'story', label: 'Historia / Reel', w: 1080, h: 1920 },
    { key: 'landscape', label: 'Presentación / vídeo', w: 1920, h: 1080 },
    { key: 'a4', label: 'Documento A4', w: 2480, h: 3508 },
]);

export const STUDIO_RESIZE_MODES = Object.freeze([
    { value: 'contain', label: 'Ajustar sin recortar' },
    { value: 'cover', label: 'Llenar y recortar' },
    { value: 'canvas', label: 'Solo cambiar lienzo' },
]);
