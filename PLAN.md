# Didi2 B2B · Plan de construcción por bloques

**Fuente:** `Idea_V2.pdf` (documento maestro, 2.0) + `Plan_Project_B2B_V4.md`
**Stack:** PHP 8.2+ · MariaDB/MySQL · Apache (XAMPP en local) · VS Code
**Regla de trabajo:** un bloque a la vez, en orden de dependencia. No se avanza si los criterios de aceptación del bloque anterior no pasan.

---

## Estado del entorno

| Pieza | En tu máquina (XAMPP) | En mi entorno de trabajo |
|---|---|---|
| PHP | 8.2+ con Apache | 8.5.12 (portable, conda) |
| Driver MySQL | `pdo_mysql` incluido | compilado a mano (`pdo_mysql.so`) |
| Base de datos | MariaDB de XAMPP | MariaDB 11.4.5 portable |
| Servidor web | Apache con VirtualHost | `php -S` para pruebas |

El código es el mismo; solo cambia cómo se arranca.

---

## Los 19 bloques

| # | Bloque | Entrega | Depende de |
|---|---|---|---|
| 00 | Entorno y repositorio | VirtualHost, `php.ini` endurecido, usuarios MySQL de mínimo privilegio, `/health` | — |
| 01 | Núcleo y fundaciones | Front controller, router, Request/Response, ErrorHandler, Logger JSON, PDO, migraciones, cabeceras de seguridad | 00 |
| 02 | Autenticación y acceso | Registro, login, logout, recuperación, verificación de correo, OTP, CSRF, rate limit, roles | 01 |
| 03 | Perfiles, direcciones y KYC | Perfiles por rol, direcciones con mapa, subida segura de documentos, cola de revisión | 02 |
| 04 | Catálogo e inventario | Categorías, productos con los campos de RN-19, imágenes, movimientos, CSV | 03 |
| 05 | Precios y escalas | Escalas sin solapamiento, precio unitario, IVA desglosado, redondeo | 04 |
| 06 | Búsqueda geográfica | Caja envolvente + Haversine, FULLTEXT, geocerca, mapa Leaflet | 04, 05 |
| 07 | Carrito, checkout y pedidos | Carrito multi-mayorista, reserva atómica de stock, máquina de estados | 05, 06 |
| 08 | Pagos y liquidación | Pasarela en sandbox, webhooks firmados, dispersión, reembolsos | 07 |
| 09 | Notificaciones y worker | Cola de jobs, worker CLI, correo, push web | 07 |
| 10 | Logística y asignación | Perfil de repartidor, ofertas por rondas, tarifas, PIN/QR | 08, 09 |
| 11 | Tracking en vivo | Posiciones GPS, ETA cacheada, mapa de seguimiento | 10 |
| 12 | Favoritos y programados | Favoritos, plantillas recurrentes, reabastecimiento | 07, 09 |
| 13 | Finanzas, reseñas y soporte | Paneles por rol, exportaciones, reseñas, reclamos | 08, 10 |
| 14 | **completado** | facturación CFDI 4.0 con datos fiscales, PAC de práctica y XML timbrado |
| 15 | Panel de administración | CRUD, KYC, disputas, parámetros versionados | 03, 08, 10 |
| 16 | Endurecimiento y PWA | Revisión OWASP, CSP final, aviso de privacidad, PWA | Todos |
| 17 | Carga, despliegue y respaldo | k6, EXPLAIN, despliegue, restauración probada | 16 |
| 18 | Post-MVP | Consolidación de fletes, BNPL, licitaciones | 17 |

**MVP vendible:** BLK-00 a BLK-11.

---

## Reglas que no se negocian

1. **Dinero en `DECIMAL(12,2)`**, nunca `FLOAT`.
2. **Contraseñas con `PASSWORD_ARGON2ID`**, nunca md5/sha1.
3. **PDO con sentencias preparadas reales** (`ATTR_EMULATE_PREPARES = false`).
4. **Nada de SQL concatenado** con entrada del usuario.
5. **Los errores internos no se muestran**: respuesta genérica + `correlation_id` en el log.
6. **Todo cambio de esquema va en una migración numerada**; nunca se edita una ya aplicada.
7. **Toda salida al navegador se escapa** (`htmlspecialchars` con `ENT_QUOTES`, UTF-8).
8. **Cada bloque entrega**: código, migración, pruebas y una nota de lo implementado.

---

## Cómo vamos a trabajar

Por cada bloque:

1. Escribo el código y la migración.
2. Lo pruebo aquí (sintaxis, unitarias y, cuando aplique, contra MariaDB real).
3. Te entrego: qué se hizo, cómo probarlo en tu XAMPP y qué quedó pendiente.
4. Solo paso al siguiente cuando lo anterior pasa.

---

## Bitácora

| Bloque | Estado | Nota |
|---|---|---|
| 00 | **completado** | `/health` responde `ok`, cabeceras de seguridad, usuario de app sin DDL, migraciones funcionando |
| 01 | **completado** | middleware, vistas con escape, 20 pruebas en verde, base reproducible |
| 02 | **completado** | registro, login, verificación, recuperación, rate limit y auditoría |
