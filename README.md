# Didi2 B2B — Plataforma de Distribución Mayorista

Plataforma B2B que conecta comercios con mayoristas. PHP 8.2 + MariaDB.

## Requisitos

- **PHP** 8.2+
- **MariaDB** 11.x
- **Composer** (las dependencias ya vienen en `vendor/`)

## Instalación rápida

### 1. Clonar el repo
```bash
git clone https://github.com/isairodriguez2025-hub/Repo-Final.git
cd Repo-Final
```

### 2. Configurar la base de datos
```bash
# Iniciar MariaDB
sudo systemctl start mariadb

# Crear la base de datos y el usuario
sudo mysql -u root -p << 'SQL'
CREATE DATABASE IF NOT EXISTS didi2;
CREATE USER IF NOT EXISTS 'didi2_app'@'%' IDENTIFIED BY 'tu_password_seguro';
GRANT ALL PRIVILEGES ON didi2.* TO 'didi2_app'@'%';
FLUSH PRIVILEGES;
SQL
```

### 3. Configurar el entorno
```bash
cp .env.example .env
# Editar .env con las credenciales de la BD:
#   DB_HOST=127.0.0.1
#   DB_PORT=3307
#   DB_NAME=didi2
#   DB_USER=didi2_app
#   DB_PASS=tu_password_seguro
```

### 4. Ejecutar las migraciones
```bash
php bin/migrate.php
```

### 5. Iniciar el servidor
```bash
php -S 127.0.0.1:8000 -t public/
```

La app estará en: **http://127.0.0.1:8000**

## Cuentas demo

| Rol | Correo | Contraseña |
|-----|--------|------------|
| Comercio | `comercio@didi2.mx` | `comercio123` |
| Mayorista | `mayorista@didi2.mx` | `demo1234` |
| Admin | `admin@didi2.mx` | `admin123` |
| Repartidor | `repartidor@didi2.mx` | `repartidor123` |

## Estructura del proyecto

```
Repo-Final/
├── public/                  ← Document root
│   ├── index.php
│   ├── manifest.json        ← PWA
│   ├── sw.js                ← Service Worker
│   └── assets/              ← Leaflet, CSS, JS
├── app/
│   ├── Config/              ← Rutas y configuración
│   ├── Core/                ← Router, DB, Auth, Session
│   ├── Modules/             ← Funcionalidades por módulo
│   └── Views/               ← Plantillas HTML
├── database/
│   └── migrations/          ← 18 migraciones SQL
├── tests/                   ← PHPUnit
├── vendor/                  ← Dependencias de Composer
├── .env.example             ← Configuración de entorno (copiar a .env)
├── composer.json
└── README.md
```

## Módulos principales

- **Catálogo** — Productos, categorías, inventario
- **Búsqueda** — Geográfica con Leaflet + OpenStreetMap
- **Carrito/Checkout** — Multi-mayorista, reserva de stock
- **Facturación CFDI 4.0** — Datos fiscales, PAC de práctica
- **Panel de administración** — CRUD usuarios, reclamos, parámetros
- **PWA** — Funciona offline, service worker
- **Post-MVP** — Fletes consolidados, BNPL, licitaciones

## Testing

```bash
php vendor/bin/phpunit tests/Integration/
```

## Despliegue

Ver `DEPLOY.md` para instrucciones de despliegue en producción.

## Tecnologías

- PHP 8.2
- MariaDB 11.x
- nikic/FastRoute
- Leaflet + OpenStreetMap
- PHPUnit
