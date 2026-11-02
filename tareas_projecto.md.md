# PLAN INTEGRAL DE DESARROLLO TÉCNICO: SISTEMA ECORUTA TEMUCO

**Proyecto:** Sistema de Gestión de Flota, Rutas Dinámicas y Monitoreo IoT para Terranova Trunkay

**Equipo de Desarrollo:** Maximiliano González y Nelson Ponce

**Stack Tecnológico:** Laravel 13 (PHP 8.4) + PostgreSQL + Docker Sail (`logistica-camion-back-end`) | Flutter Web & Mobile con Riverpod y Drift (`ecoruta-frontend`) | ESP32 C++ (IoT)

## 1. Arquitectura de Base de Datos y Estructura de Migraciones (PostgreSQL)

Al inspeccionar el repositorio `logistica-camion-back-end`, se identifican las migraciones base de Laravel/Sanctum (`users`, `personal_access_tokens`, `cache`, `jobs`) y cinco migraciones de dominio creadas recientemente: `chofer`, `admninstrador` (nota: corregir el typo en el nombre del archivo a `administrador`), `camiones`, `puntos` y `dispositivo`.

Para que el sistema opere de principio a fin (incluyendo generación de rutas, sincronización offline, historial GPS, telemetría IoT y cálculo de métricas), la base de datos relacional en PostgreSQL debe estructurarse con las siguientes **10 tablas de dominio y seguridad**:

### 1.1. Tablas de Usuarios, Roles y Seguridad

|**Tabla**|**Archivo de Migración**|**Columnas y Tipos de Datos (PostgreSQL / Laravel)**|**Índices y Reglas de Integridad**|
|---|---|---|---|
|**`users`**|`0001_01_01_000000_create_users_table.php`|`id` (bigIncrements PK)<br><br>  <br><br>`email` (string, unique)<br><br>  <br><br>`password` (string)<br><br>  <br><br>`role` (enum: `'administrador'`, `'chofer'`, `'sysadmin'`)<br><br>  <br><br>`is_active` (boolean, default: `true`)<br><br>  <br><br>`timestamps()`|Índice único en `email`. Índice en `role`. Autenticación gestionada mediante tokens Bearer con Laravel Sanctum (`personal_access_tokens`).|
|**`administradores`**|`2026_10_28_165539_admninstrador.php`|`id` (bigIncrements PK)<br><br>  <br><br>`user_id` (foreignId -> `users.id`, unique, cascadeOnDelete)<br><br>  <br><br>`nombre_completo` (text, cifrado en aplicación)<br><br>  <br><br>`cargo` (string, nullable)<br><br>  <br><br>`telefono` (string, nullable)<br><br>  <br><br>`timestamps()`|Relación `1:1` con `users`. Almacena el perfil administrativo que supervisa la plataforma web y móvil.|
|**`choferes`**|`2026_10_28_165510_chofer.php`|`id` (bigIncrements PK)<br><br>  <br><br>`user_id` (foreignId -> `users.id`, unique, cascadeOnDelete)<br><br>  <br><br>`nombre_cifrado` (text, **Eloquent `encrypted` cast**)<br><br>  <br><br>`rut_hash` (string, unique, indexado para búsqueda)<br><br>  <br><br>`licencia_clase` (string, default `'A4'`)<br><br>  <br><br>`telefono_cifrado` (text, nullable, `encrypted`)<br><br>  <br><br>`disponible` (boolean, default `true`)<br><br>  <br><br>`timestamps()`|Relación `1:1` con `users`. El campo `nombre_cifrado` cumple con el cifrado de datos personales en reposo. Todas las agrupaciones SQL de desempeño se hacen por `choferes.id`.|
|**`audit_logs`**|`Nueva migración: create_audit_logs_table.php`|`id` (bigIncrements PK)<br><br>  <br><br>`user_id` (foreignId -> `users.id`, nullable, nullOnDelete)<br><br>  <br><br>`accion` (string: `'login'`, `'logout'`, `'failed_login'`, `'route_generated'`)<br><br>  <br><br>`ip_address` (ipAddress, nullable)<br><br>  <br><br>`user_agent` (text, nullable)<br><br>  <br><br>`detalles` (jsonb, nullable)<br><br>  <br><br>`created_at` (timestamp)|Índice compuesto en `(user_id, created_at)`. Registra la trazabilidad de accesos y acciones críticas de seguridad.|

### 1.2. Tablas de Flota, Puntos de Reciclaje y Sensórica IoT

|**Tabla**|**Archivo de Migración**|**Columnas y Tipos de Datos (PostgreSQL / Laravel)**|**Índices y Reglas de Integridad**|
|---|---|---|---|
|**`camiones`**|`2026_10_28_165555_camiones.php`|`id` (bigIncrements PK)<br><br>  <br><br>`patente` (string, unique, 10 chars)<br><br>  <br><br>`marca` (string)<br><br>  <br><br>`modelo` (string)<br><br>  <br><br>`anio` (smallInteger)<br><br>  <br><br>`capacidad_kg` (decimal 8,2)<br><br>  <br><br>`estado_operativo` (enum: `'disponible'`, `'en_ruta'`, `'mantenimiento'`, `'inactivo'`)<br><br>  <br><br>`chofer_actual_id` (foreignId -> `choferes.id`, nullable, nullOnDelete)<br><br>  <br><br>`ultima_latitud` (decimal 10,7, nullable)<br><br>  <br><br>`ultima_longitud` (decimal 10,7, nullable)<br><br>  <br><br>`velocidad_actual_kmh` (decimal 5,2, default 0)<br><br>  <br><br>`ultima_actualizacion_gps` (timestamp, nullable)<br><br>  <br><br>`timestamps()`|Índice único en `patente`. Índice en `estado_operativo`. Guardar `ultima_latitud`, `ultima_longitud` y `ultima_actualizacion_gps` directamente en `camiones` permite que el polling del mapa administrativo consulte la flota completa en milisegundos sin hacer `JOIN` pesados sobre millones de registros históricos.|
|**`puntos`**|`2026_10_28_165602_puntos.php`|`id` (bigIncrements PK)<br><br>  <br><br>`nombre` (string, ej. `'Condominio Los Pablos'`)<br><br>  <br><br>`direccion` (string)<br><br>  <br><br>`tipo_punto` (enum: `'condominio'`, `'punto_calle'`, `'empresa'`, `'municipio'`)<br><br>  <br><br>`latitud` (decimal 10,7)<br><br>  <br><br>`longitud` (decimal 10,7)<br><br>  <br><br>`estado_llenado` (enum: `'vacio'`, `'bajo'`, `'medio'`, `'alto'`, `'lleno'`)<br><br>  <br><br>`porcentaje_llenado` (smallInteger, default 0, rango 0–100)<br><br>  <br><br>`peso_actual_kg` (decimal 6,2, nullable)<br><br>  <br><br>`ultima_actualizacion_estado` (timestamp)<br><br>  <br><br>`activo` (boolean, default `true`)<br><br>  <br><br>`timestamps()`|Índice compuesto en `(activo, estado_llenado)` para acelerar el filtrado del algoritmo de rutas (que incluye únicamente `'alto'` y `'lleno'`).|
|**`dispositivos`**|`2026_10_28_165616_dispositivo.php`|`id` (bigIncrements PK)<br><br>  <br><br>`punto_id` (foreignId -> `puntos.id`, unique, nullOnDelete)<br><br>  <br><br>`codigo_hardware` (string, unique, ej. MAC address del ESP32)<br><br>  <br><br>`api_token_hash` (string, unique)<br><br>  <br><br>`tipo_sensor` (enum: `'ultrasonico_hcsr04'`, `'peso_hx711'`, `'hibrido'`)<br><br>  <br><br>`altura_contenedor_cm` (smallInteger, default 150)<br><br>  <br><br>`capacidad_max_kg` (decimal 6,2, nullable)<br><br>  <br><br>`bateria_voltaje` (decimal 4,2, nullable)<br><br>  <br><br>`ultimo_heartbeat` (timestamp, nullable)<br><br>  <br><br>`estado` (enum: `'activo'`, `'inactivo'`, `'falla'`)<br><br>  <br><br>`timestamps()`|Relación `1:1` con `puntos`. Permite autenticar cada microcontrolador ESP32 mediante su propio token de hardware al enviar lecturas por Wi-Fi.|
|**`lecturas_iot`**|`Nueva migración: create_lecturas_iot_table.php`|`id` (bigIncrements PK)<br><br>  <br><br>`dispositivo_id` (foreignId -> `dispositivos.id`, cascadeOnDelete)<br><br>  <br><br>`punto_id` (foreignId -> `puntos.id`, cascadeOnDelete)<br><br>  <br><br>`distancia_cm` (decimal 6,2, nullable)<br><br>  <br><br>`peso_kg` (decimal 6,2, nullable)<br><br>  <br><br>`porcentaje_calculado` (smallInteger)<br><br>  <br><br>`estado_resultante` (enum: `'vacio'`, `'bajo'`, `'medio'`, `'alto'`, `'lleno'`)<br><br>  <br><br>`registrado_en` (timestamp)|Índice compuesto en `(punto_id, registrado_en)`. Guarda la serie temporal de lecturas enviadas por el ESP32 para auditoría y calibración del piloto.|

### 1.3. Tablas de Planificación de Rutas, Recolección Offline y Telemetría GPS

|**Tabla**|**Archivo de Migración**|**Columnas y Tipos de Datos (PostgreSQL / Laravel)**|**Índices y Reglas de Integridad**|
|---|---|---|---|
|**`rutas`**|`Nueva migración: create_rutas_table.php`|`id` (bigIncrements PK)<br><br>  <br><br>`camion_id` (foreignId -> `camiones.id`, restrictOnDelete)<br><br>  <br><br>`chofer_id` (foreignId -> `choferes.id`, restrictOnDelete)<br><br>  <br><br>`creado_por_admin_id` (foreignId -> `administradores.id`, nullable)<br><br>  <br><br>`fecha_programada` (date)<br><br>  <br><br>`estado` (enum: `'planificada'`, `'en_curso'`, `'completada'`, `'cancelada'`)<br><br>  <br><br>`total_puntos_evaluados` (smallInteger)<br><br>  <br><br>`puntos_incluidos_count` (smallInteger)<br><br>  <br><br>`puntos_excluidos_count` (smallInteger, puntos en estado `'bajo'` o `'medio'`)<br><br>  <br><br>`distancia_estimada_km` (decimal 6,2)<br><br>  <br><br>`duracion_estimada_min` (smallInteger)<br><br>  <br><br>`encoded_polyline` (text, polilínea de Google Maps Platform)<br><br>  <br><br>`hora_inicio_real` (timestamp, nullable)<br><br>  <br><br>`hora_termino_real` (timestamp, nullable)<br><br>  <br><br>`timestamps()`|Índices en `(fecha_programada, estado)`, `chofer_id` y `camion_id`. Los campos `puntos_excluidos_count` y `total_puntos_evaluados` permiten calcular de forma directa el indicador de ahorro de viajes en vano.|
|**`ruta_puntos`**|`Nueva migración: create_ruta_puntos_table.php`|`id` (bigIncrements PK)<br><br>  <br><br>`ruta_id` (foreignId -> `rutas.id`, cascadeOnDelete)<br><br>  <br><br>`punto_id` (foreignId -> `puntos.id`, restrictOnDelete)<br><br>  <br><br>`orden_parada` (smallInteger)<br><br>  <br><br>`estado_llenado_inicial` (enum: `'alto'`, `'lleno'`)<br><br>  <br><br>`estado_parada` (enum: `'pendiente'`, `'recolectado'`, `'omitido'`)<br><br>  <br><br>`hora_estimada_llegada` (timestamp, calculada por Google Maps)<br><br>  <br><br>`hora_real_recoleccion` (timestamp, nullable, enviada por el chofer)<br><br>  <br><br>`sync_uuid` (uuid, unique, nullable — clave de idempotencia offline)<br><br>  <br><br>`sincronizado_offline` (boolean, default `false`)<br><br>  <br><br>`observaciones` (text, nullable)<br><br>  <br><br>`timestamps()`|Índice único en `sync_uuid` para evitar duplicados cuando la app móvil de Flutter reenvía datos tras recuperar señal de internet. Índice único en `(ruta_id, orden_parada)`.|
|**`ubicaciones_gps`**|`Nueva migración: create_ubicaciones_gps_table.php`|`id` (bigIncrements PK)<br><br>  <br><br>`camion_id` (foreignId -> `camiones.id`, cascadeOnDelete)<br><br>  <br><br>`ruta_id` (foreignId -> `rutas.id`, nullable, cascadeOnDelete)<br><br>  <br><br>`chofer_id` (foreignId -> `choferes.id`, nullable, nullOnDelete)<br><br>  <br><br>`latitud` (decimal 10,7)<br><br>  <br><br>`longitud` (decimal 10,7)<br><br>  <br><br>`velocidad_kmh` (decimal 5,2, nullable)<br><br>  <br><br>`rumbo_grados` (smallInteger, nullable)<br><br>  <br><br>`capturado_en` (timestamp, hora exacta del GPS móvil)<br><br>  <br><br>`recibido_en` (timestamp, hora del servidor)|Índice compuesto en `(camion_id, capturado_en DESC)` y `(ruta_id, capturado_en ASC)`. Almacena la traza histórica del recorrido para reconstrucción de rutas y análisis de velocidades.|

## 2. Especificación Completa de Endpoints de la API RESTful (`/api/v1`)

Todos los endpoints se declaran en `routes/api.php` bajo el prefijo `/api/v1` y devuelven respuestas JSON estandarizadas mediante `JsonResource` de Laravel.

### 2.1. Autenticación y Control de Acceso (Sanctum + RBAC)

- `POST /api/v1/auth/login` — Recibe `email`, `password` y `device_name`. Verifica credenciales, registra el evento en `audit_logs`, y retorna el token Bearer de Sanctum junto con el rol (`administrador` o `chofer`) y el perfil asociado.
    
- `POST /api/v1/auth/logout` — Revoca el token actual en `personal_access_tokens` y registra el cierre de sesión en `audit_logs`.
    
- `GET /api/v1/auth/me` — Retorna los datos del usuario autenticado, su rol y, si es chofer, su camión asignado y su ruta activa del día.
    

### 2.2. CRUD de Flota (`camiones`) y Personal (`choferes`, `administradores`)

_(Protegidos por middleware `auth:sanctum` y `role:administrador`)_

- `GET /api/v1/camiones` — Lista todos los camiones con su estado operativo, datos de diagnóstico y chofer actual.
    
- `POST /api/v1/camiones` — Crea un camión nuevo (`patente`, `marca`, `modelo`, `anio`, `capacidad_kg`).
    
- `GET /api/v1/camiones/{camion}` — Obtiene el detalle de un camión, incluyendo su última posición GPS y métricas resumidas.
    
- `PUT /api/v1/camiones/{camion}` — Actualiza datos del camión, estado operativo o asigna un `chofer_actual_id`.
    
- `DELETE /api/v1/camiones/{camion}` — Desactiva o elimina lógicamente un camión (si no tiene ruta en curso).
    
- `GET /api/v1/choferes` — Lista los choferes (el modelo desencripta `nombre_cifrado` automáticamente en PHP al serializar el JSON).
    
- `POST /api/v1/choferes` — Crea simultáneamente el registro en `users` (rol `chofer`) y su perfil en `choferes` con datos personales cifrados.
    
- `GET /api/v1/choferes/{chofer}` — Detalle del chofer e historial de rutas completadas.
    
- `PUT /api/v1/choferes/{chofer}` — Actualiza datos del chofer, licencia o disponibilidad.
    
- `DELETE /api/v1/choferes/{chofer}` — Desactiva la cuenta del chofer (`is_active = false`).
    

### 2.3. CRUD de Puntos de Reciclaje (`puntos`) y Dispositivos IoT (`dispositivos`)

- `GET /api/v1/puntos` — Lista todos los puntos de reciclaje en Temuco con sus coordenadas, tipo y `estado_llenado` actual (`vacio`, `bajo`, `medio`, `alto`, `lleno`). Permite filtrar por `?estado_llenado=alto,lleno`.
    
- `POST /api/v1/puntos` — Registra un nuevo punto de reciclaje con `nombre`, `direccion`, `tipo_punto`, `latitud` y `longitud`.
    
- `GET /api/v1/puntos/{punto}` — Obtiene el detalle del punto, el dispositivo IoT vinculado y sus últimas 20 lecturas.
    
- `PUT /api/v1/puntos/{punto}` — Modifica datos geográficos o permite actualizar manualmente el `estado_llenado`.
    
- `DELETE /api/v1/puntos/{punto}` — Desactiva un punto de reciclaje (`activo = false`).
    
- `CRUD /api/v1/dispositivos` — `GET`, `POST`, `PUT`, `DELETE` para vincular un microcontrolador ESP32 (`codigo_hardware`, `altura_contenedor_cm`, `tipo_sensor`) a un `punto_id` y generar su token de telemetría.
    
- `POST /api/v1/iot/telemetria` — _(Autenticado por header `X-IoT-Token` del ESP32)_. Recibe `distancia_cm`, `peso_kg` y `bateria_voltaje`, calcula el porcentaje de llenado, guarda el registro en `lecturas_iot` y actualiza automáticamente `puntos.estado_llenado`.
    

### 2.4. Rastreo GPS en Tiempo Real (Polling de Baja Latencia $\le$ 30s)

- `POST /api/v1/gps/ping` — _(Rol: `chofer`)_. Recibe `camion_id`, `ruta_id` (opcional), `latitud`, `longitud`, `velocidad_kmh`, `rumbo_grados` y `capturado_en`. Actualiza las columnas `ultima_latitud`, `ultima_longitud`, `velocidad_actual_kmh` y `ultima_actualizacion_gps` en `camiones` e inserta el punto histórico en `ubicaciones_gps`.
    
- `GET /api/v1/gps/flota` — _(Rol: `administrador`)_. Endpoint ultraligero consultado cada 10–15 segundos por el mapa del administrador. Retorna la posición actual, velocidad, chofer asignado, estado y progreso de la ruta activa de todos los camiones en operación.
    
- `GET /api/v1/rutas/{ruta}/traza-gps` — Retorna el arreglo cronológico de coordenadas en `ubicaciones_gps` para dibujar el recorrido real efectuado por el camión frente al recorrido planificado.
    

### 2.5. Planificación Dinámica de Rutas y Ejecución en Terreno (Con Soporte Offline)

- `POST /api/v1/rutas/generar` — _(Rol: `administrador`)_. Recibe `camion_id`, `chofer_id`, `fecha_programada` y coordenadas de origen/destino (Planta Terranova en Padre Las Casas/Temuco). Ejecuta el motor de optimización:
    
    1. Consulta todos los puntos activos en `puntos`.
        
    2. Separa los puntos listos (`estado_llenado IN ('alto', 'lleno')`) de los puntos excluidos (`vacio`, `bajo`, `medio`).
        
    3. Consume la API **Google Maps Platform (Routes API / Directions API con `optimizeWaypoints: true`)** para ordenar las paradas de forma óptima y calcular tiempos estimados de llegada (`hora_estimada_llegada`) y la polilínea (`encoded_polyline`).
        
    4. Guarda la cabecera en `rutas` y el detalle ordenado en `ruta_puntos`.
        
- `GET /api/v1/rutas` — Lista las rutas filtrables por fecha, chofer, camión o estado.
    
- `GET /api/v1/chofer/ruta-activa` — _(Rol: `chofer`)_. Devuelve la ruta del día asignada al chofer con la lista ordenada de paradas (`ruta_puntos`), coordenadas de cada punto y la polilínea para renderizar en Google Maps en la app móvil.
    
- `POST /api/v1/rutas/{ruta}/iniciar` — _(Rol: `chofer`)_. Marca `rutas.estado = 'en_curso'`, registra `hora_inicio_real = now()` y cambia el camión a `'en_ruta'`.
    
- `POST /api/v1/rutas/{ruta}/recolectar-punto` — _(Rol: `chofer`, **Idempotente para Sync Offline**)_. Recibe `punto_id`, `sync_uuid` (UUID v4 generado en el celular), `hora_real_recoleccion` (timestamp local del teléfono al momento de vaciar el contenedor) y `observaciones`. Si `sync_uuid` ya existe en `ruta_puntos`, devuelve `200 OK` sin duplicar; si no existe, marca la parada como `'recolectado'`, registra la hora real y actualiza `puntos.estado_llenado = 'vacio'` y `porcentaje_llenado = 0`.
    
- `POST /api/v1/rutas/{ruta}/sincronizar-lote` — _(Rol: `chofer`)_. Permite enviar en una sola petición un arreglo de recolecciones acumuladas en la base de datos local `Drift` mientras el chofer estuvo sin cobertura celular.
    
- `POST /api/v1/rutas/{ruta}/finalizar` — _(Rol: `chofer`)_. Marca `rutas.estado = 'completada'`, registra `hora_termino_real = now()` y cambia el camión a `'disponible'`.
    

### 2.6. Indicadores de Gestión (KPIs) y Exportación de Reportes

- `GET /api/v1/kpis/dashboard` — _(Rol: `administrador`)_. Recibe parámetros `?fecha_inicio=YYYY-MM-DD&fecha_fin=YYYY-MM-DD&agrupacion=semana|mes|trimestre|anio` y retorna el cálculo consolidado de los 6 indicadores de gestión:
    
    1. **Tiempo promedio de ruta por chofer y camión:** Promedio de `(hora_termino_real - hora_inicio_real)` agrupado por `chofer_id` y `camion_id`.
        
    2. **Tiempo promedio entre puntos de recolección:** Promedio de diferencias `(hora_real_recoleccion[N] - hora_real_recoleccion[N-1])` dentro de cada ruta.
        
    3. **Cantidad total de puntos vaciados por periodo:** Conteo de registros en `ruta_puntos` con `estado_parada = 'recolectado'` agrupados por semana, mes, trimestre y año.
        
    4. **Ranking de choferes y camiones:** Clasificación de mayor a menor cantidad de puntos atendidos en el rango seleccionado (agrupando por ID en SQL y desencriptando `nombre_cifrado` en la capa de presentación de Laravel).
        
    5. **Desviación entre tiempo estimado y tiempo real:** Promedio en minutos de `(hora_real_recoleccion - hora_estimada_llegada)` por parada y ruta.
        
    6. **Porcentaje de puntos excluidos por estado "no listo":** `SUM(puntos_excluidos_count) / SUM(total_puntos_evaluados) * 100` para cuantificar el ahorro de combustible frente a las rutas fijas antiguas.
        
- `GET /api/v1/kpis/exportar` — _(Rol: `administrador`)_. Genera y descarga un documento formal en formato **PDF** o **Excel/CSV** con las tablas de métricas y el resumen ejecutivo para la gerencia de Terranova Trunkay.
    

## 3. Funciones y Servicios Core a Desarrollar en el Sistema

Para que los endpoints anteriores y las aplicaciones cliente operen de forma integrada, se deben programar los siguientes módulos funcionales:

### 3.1. Servicios Backend (`logistica-camion-back-end`)

1. **`GoogleMapsRouteOptimizerService` (`app/Services/GoogleMapsRouteOptimizerService.php`):**
    
    - Conecta mediante el cliente HTTP de Laravel con `[https://routes.googleapis.com/directions/v2:computeRoutes](https://routes.googleapis.com/directions/v2:computeRoutes)` (o Directions API).
        
    - Recibe la ubicación de la planta de Terranova Trunkay y la colección de modelos `Punto` cuyo estado sea `'alto'` o `'lleno'`.
        
    - Solicita la optimización del orden de visita (`optimizeWaypointOrder: true`), extrae el arreglo `optimizedIntermediateWaypointIndex`, calcula el `hora_estimada_llegada` acumulando la duración de cada tramo (`legs`) a la hora de inicio de la jornada y retorna la polilínea codificada (`encodedPolyline`).
        
2. **`IoTTelemetryProcessorService` (`app/Services/IoTTelemetryProcessorService.php`):**
    
    - Convierte la distancia medida por el sensor ultrasónico HC-SR04 (`distancia_cm`) en porcentaje de llenado mediante la fórmula:
        
        $$\text{Porcentaje} = \text{clamp}\left(\frac{\text{altura\_contenedor\_cm} - \text{distancia\_cm}}{\text{altura\_contenedor\_cm}} \times 100, \, 0, \, 100\right)$$
        
    - Clasifica automáticamente el porcentaje en los estados de dominio:
        
        - $0\% - 10\% \rightarrow \text{'vacio'}$
            
        - $11\% - 40\% \rightarrow \text{'bajo'}$
            
        - $41\% - 70\% \rightarrow \text{'medio'}$
            
        - $71\% - 89\% \rightarrow \text{'alto'}$
            
        - $90\% - 100\% \rightarrow \text{'lleno'}$
            
3. **`KpiCalculationService` y `KpiReportExportService` (`app/Services/Kpi/`):**
    
    - Ejecuta consultas agregadas optimizadas en PostgreSQL (usando funciones de ventana `LAG()` para calcular el tiempo entre el punto $N$ y el punto $N-1$ en una sola consulta SQL) y genera el archivo descargable (usando `barryvdh/laravel-dompdf` o exportación nativa CSV/XLSX).
        

### 3.2. Módulos Frontend Multiplataforma (`ecoruta-frontend` en Flutter)

1. **Capa de Persistencia Local y Cola Offline (`Drift` + `SharedPreferences`):**
    
    - Base de datos SQLite local en `Drift` con dos tablas: `CachedRoutes` (guarda la ruta del día y sus paradas al iniciar la jornada) y `PendingCollectionsOutbox` (almacena cada recolección marcada por el chofer con su `sync_uuid`, `punto_id` y `collected_at`).
        
    - `SyncOutboxService` en Riverpod que escucha el estado de red y vacía automáticamente la tabla `PendingCollectionsOutbox` hacia `/api/v1/rutas/{ruta}/sincronizar-lote` apenas el teléfono recupera cobertura en terreno.
        
2. **Motor de Geolocalización y Polling (`Geolocator` + `Riverpod`):**
    
    - **Modo Chofer:** `LocationBroadcasterNotifier` que captura la posición GPS del teléfono cada **12–15 segundos** mientras una ruta está `'en_curso'` y la envía a `POST /api/v1/gps/ping`.
        
    - **Modo Administrador:** `FleetMapPollingNotifier` que consulta `GET /api/v1/gps/flota` cada **10–15 segundos** y actualiza los marcadores de camiones sobre el widget `GoogleMap` sin recargar la pantalla, garantizando una latencia total menor a 30 segundos.
        
3. **Diseño Responsivo Web/Móvil y Tema Institucional:**
    
    - `ResponsiveAdminScaffold` que adapta la interfaz del administrador usando `LayoutBuilder` (menú lateral fijo en navegador de escritorio y barra de navegación inferior/drawer en teléfonos móviles) aplicando la paleta de colores institucionales de Terranova Trunkay.
        

### 3.3. Firmware del Nodo IoT (`iot-esp32`)

- Sketch en C++ (PlatformIO / Arduino IDE) para el microcontrolador **ESP32 DevKit WROOM-32**.
    
- Dispara el pulso ultrasónico en el sensor **HC-SR04** (y lee el módulo **HX711** si tiene celda de carga conectada), toma la mediana de 5 lecturas consecutivas para filtrar ruido acústico, se conecta por Wi-Fi y envía un `HTTP POST` JSON a `/api/v1/iot/telemetria` antes de entrar en modo `Deep Sleep` para ahorrar energía.
    

## 4. División Equitativa del Desarrollo entre los Integrantes del Equipo

Para que **Maximiliano González** y **Nelson Ponce** trabajen en paralelo sin bloquearse mutuamente ni generar conflictos de integración en Git, el desarrollo se divide en dos líneas de responsabilidad complementarias (**Subsistema A: Operaciones en Terreno, Geolocalización, Rutas e IoT** vs. **Subsistema B: Administración, Seguridad, Gestión de Entidades y Analítica de KPIs**):

| **Área Técnica**                                        | **Maximiliano González (Rutas, GPS, Modo Chofer & IoT)**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | **Nelson Ponce (Seguridad, CRUDs Administrativos, KPIs & Portal Admin)**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **1. Migraciones y Modelos (PostgreSQL / Laravel)**     | • Completar y migrar tablas: `puntos`, `dispositivos`, `lecturas_iot`, `rutas`, `ruta_puntos` y `ubicaciones_gps`.<br><br>  <br><br>• Configurar modelos Eloquent `Punto`, `Dispositivo`, `LecturaIot`, `Ruta`, `RutaPunto` y `UbicacionGps` con sus relaciones y scopes de filtrado.                                                                                                                                                                                                                                                                                                                             | • Completar y migrar tablas: `users`, `administradores` (corregir nombre de archivo `admninstrador.php`), `choferes`, `camiones` y `audit_logs`.<br><br>  <br><br>• Configurar modelos `User`, `Administrador`, `Chofer` (con cast `encrypted` para datos personales), `Camion` y `AuditLog`.                                                                                                                                                                                                                                                                                                                 |
| **2. Endpoints REST API y Servicios Backend**           | • CRUD completo de `/api/v1/puntos` y `/api/v1/dispositivos`.<br><br>  <br><br>• Endpoint IoT `POST /api/v1/iot/telemetria` y servicio `IoTTelemetryProcessorService`.<br><br>  <br><br>• Servicio `GoogleMapsRouteOptimizerService` e integración con Google Maps Platform API.<br><br>  <br><br>• Endpoints de ciclo de vida de ruta (`generar`, `ruta-activa`, `iniciar`, `recolectar-punto`, `sincronizar-lote`, `finalizar`).<br><br>  <br><br>• Endpoints GPS (`POST /api/v1/gps/ping`, `GET /api/v1/gps/flota`, `GET /api/v1/rutas/{ruta}/traza-gps`).                                                     | • Autenticación Sanctum (`login`, `logout`, `me`), middlewares de roles (`administrador`, `chofer`) y registro automático en `audit_logs`.<br><br>  <br><br>• CRUD completo de `/api/v1/camiones` y `/api/v1/choferes` (con creación transaccional de usuario y cifrado de nombre).<br><br>  <br><br>• Servicio analítico `KpiCalculationService` (consultas SQL para los 6 indicadores de gestión).<br><br>  <br><br>• Endpoints `/api/v1/kpis/dashboard` y `/api/v1/kpis/exportar` (generación de documentos PDF/CSV).                                                                                      |
| **3. Desarrollo Frontend (`ecoruta-frontend` Flutter)** | • Configuración de base de datos local `Drift` (`CachedRoutes` y `PendingCollectionsOutbox`) y servicio de sincronización offline (`SyncOutboxService`).<br><br>  <br><br>• **Vistas del Chofer (App Móvil):** Mapa de ruta activa con polilínea de Google Maps, tarjeta de siguiente parada, botón de registro de punto recolectado (`vacio`) con actualización optimista en Riverpod y servicio de transmisión GPS cada 12–15 segundos.<br><br>  <br><br>• **Componente de Mapa en Tiempo Real (Admin):** Widget de mapa interactivo con polling cada 10–15s para visualizar camiones y estado de contenedores. | • Arquitectura base de navegación, `ThemeData` con colores institucionales de Terranova Trunkay y `ResponsiveAdminScaffold` (adaptable a Web Desktop y Móvil).<br><br>  <br><br>• **Pantallas de Autenticación:** Login unificado con redirección automática según rol (`administrador` vs. `chofer`) y persistencia en `SharedPreferences`.<br><br>  <br><br>• **Vistas del Administrador (Web y Móvil):** Mantenedores CRUD de camiones, choferes y puntos de reciclaje, panel de despacho/aprobación de ruta diaria, y **Dashboard de KPIs** con gráficos interactivos y botón de exportación de reportes. |
| **4. Hardware IoT y Pruebas Automatizadas**             | • Programación del firmware C++ para el **ESP32 + HC-SR04 / HX711** (`/iot-esp32`) y pruebas físicas del piloto.<br><br>  <br><br>• Pruebas Pest PHP para generación de rutas, idempotencia offline y telemetría; pruebas de widgets/unidades en Flutter para la cola `Drift`.                                                                                                                                                                                                                                                                                                                                    | • Configuración de Seeders (`DatabaseSeeder.php`) con datos realistas de puntos de reciclaje en Temuco, camiones y choferes de prueba.<br><br>  <br><br>• Pruebas Pest PHP para autenticación, control de roles, CRUDs y exactitud matemática de los 6 KPIs; pruebas en Flutter para vistas de administración.                                                                                                                                                                                                                                                                                                |

## 5. Orden de Ejecución Técnica (Secuencia de Construcción sin Bloqueos)

Para construir el sistema de forma ordenada utilizando **OpenCode** y **Google Antigravity**, ejecuten el desarrollo en estas 4 etapas técnicas:

1. **Etapa Base (Esquema de Datos, Modelos y Seeders):**
    
    - Ambos integrantes completan sus respectivas migraciones en `database/migrations/`, ejecutan `./vendor/bin/sail artisan migrate` y construyen los modelos Eloquent con sus `Factories` y `DatabaseSeeder` (incluyendo 15–20 puntos reales de reciclaje en Temuco para pruebas).
        
2. **Etapa de Núcleo API (Autenticación, CRUDs y Servicios):**
    
    - Nelson implementa la autenticación Sanctum, el middleware de roles y los CRUDs de camiones y choferes.
        
    - Maximiliano implementa el CRUD de puntos/dispositivos, los endpoints de polling GPS y el servicio `GoogleMapsRouteOptimizerService` conectado a Google Maps Platform.
        
3. **Etapa de Clientes Flutter (Flujo Operativo y Sincronización Offline):**
    
    - Nelson construye el login, el layout responsivo del administrador y las pantallas de gestión de flota.
        
    - Maximiliano construye la vista móvil del chofer, la integración de `google_maps_flutter`, el emisor GPS y la cola offline con `Drift`.
        
4. **Etapa de Cierre (Analítica de KPIs, Exportación y Piloto IoT):**
    
    - Nelson desarrolla el motor de cálculo SQL de los 6 indicadores de gestión, la exportación a PDF/Excel y los gráficos del dashboard administrativo.
        
    - Maximiliano desarrolla el endpoint de telemetría IoT, carga el firmware en el microcontrolador **ESP32** con el sensor **HC-SR04** y valida la actualización automática del estado de llenado del contenedor en el mapa