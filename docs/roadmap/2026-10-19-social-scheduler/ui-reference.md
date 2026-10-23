# Referencia de UI — Metricool (observación, 2026-10-19)

> Recorrido de solo lectura por app.metricool.com (marca Aipocalipsis, plan gratuito), sin publicar ni guardar nada.
> Sirve de inspiración para F3 (calendario), F7 (analítica) y F8 (informes); no es un requisito de copia.

## Estructura general
- **Barra superior** con módulos: Analytics · Reporting · Inbox · Planning · SmartLinks · Ads. A la derecha, **selector de marca** (avatar + nombre) — encaja con nuestro modelo de marcas (T-08).
- **Barra lateral** contextual al módulo: en Analytics, una entrada por red (Facebook, Instagram, TikTok, YouTube, LinkedIn) con su avatar si está conectada o un «+» para conectarla, y «More connections».
- **Selector de periodo** global arriba a la derecha («Main period» + «Comparison period»).

## Analytics
- **Summary** (resumen de marca): tarjetas por métrica (Followers, Impressions…) con total grande + desglose por red en chips de color, y gráfico de línea temporal debajo.
- **Por red** (p. ej. Facebook): pestañas Page overview · Posts · Competitors. Bloques: Growth (Followers / Views / Page visits / Total content), Acquired vs Lost, Posts viewed, Interactions (Reactions, Comments, Shared, Clicks, Posts) y KPIs derivados (por día / por post), Types, Views.
- Avisos de **cambios de métricas de Meta** (Page Likes → Followers, Page impressions → Views) — coincide con lo medido en el spike: `page_impressions`/`page_fans` ya no existen.

## Planning
- Pestañas: **Calendar · List** · Posts library · Autolists · Deleted posts · Flows.
- **Calendario semanal** por franjas horarias con **mapa de calor de mejores horas** (filtro «Best times» por red); cada post es una tarjeta con iconos de red, hora y extracto del texto. Zona horaria visible (Europe/Madrid).
- **Lista**: tabla Fecha · Contenido (miniatura + texto) · Redes · Estado (Published…) · acciones; búsqueda, rango de fechas, filtros y acciones masivas.
- Botón principal **«Create post»**.

## Reporting
- Tarjetas: Reports (PDF/PPT con logo y colores), Campaign dashboards, Metricool Studio, Looker Studio.

## Ideas para nuestro alcance
- Calendario semana/mes + vista lista con estado por red (borrador, programado, publicando, publicado, error).
- Tarjetas KPI con total + desglose por red y línea temporal, periodo y comparación.
- Mapa de calor de mejores horas: fuera de alcance v1 salvo que la spec lo pida.
