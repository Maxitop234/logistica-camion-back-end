# ADR-0004: Rastreo GPS desde el teléfono del chofer

- **Estado:** Aceptada
- **Fecha:** 2026-10-24 (ratificada el 2026-11-18)
- **Relacionado con:** BE-07, BE-08, FE-03, FE-07

## Contexto
Los dos camiones de Somos Terranova son **arrendados**. La arrendadora no autoriza instalar dispositivos ni leer el puerto OBD2, así que no hay datos de combustible ni de estado del motor. Aun así, la demo debe mostrar posiciones reales y el panel necesita una **sección de administración de GPS**.

## Decisión
- La posición del camión será la del **teléfono del chofer**, capturada por la app Flutter (`geolocator`) cada 10–15 s **solo mientras la ruta está `en_curso`**.
- Cada teléfono se registra como **dispositivo móvil rastreador** (`dispositivos_moviles`), identificado por un UUID de instalación (`X-Device-Id`), con plataforma, versión de la app, permiso de ubicación, precisión, batería y último ping.
- La sección **Administración de GPS** del panel muestra ese estado y alerta cuando un teléfono deja de transmitir.

## Alternativas consideradas
- **GPS dedicado o lector OBD2 en el camión:** requiere autorización de la arrendadora y costo de hardware. Es la mejora natural si la empresa compra camiones.
- **Plataforma comercial de telemetría:** resuelve el rastreo, pero no la generación de rutas según el llenado.

## Consecuencias
- La calidad del dato depende del teléfono: permisos, ahorro de batería, precisión. Por eso hace falta la administración de GPS.
- No hay métricas de combustible: el ahorro se mide en kilómetros y visitas evitadas (KPI-06).
- Privacidad: el rastreo se limita a la jornada, y la API rechaza los pings fuera de una ruta en curso.
