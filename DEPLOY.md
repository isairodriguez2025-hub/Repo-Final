# Despliegue de Didi2 B2B

## Requisitos

- PHP 8.2+
- MariaDB 11.x
- Composer
- Node.js (solo para build estático si aplica)

## Despliegue rápido

```bash
# 1. Clonar el repo
git clone https://github.com/isairodriguez2025-hub/didi2-b2b.git
cd didi2-b2b

# 2. Instalar dependencias
composer install --no-dev --optimize-autoload

# 3. Configurar entorno
cp .env.example .env
# Editar .env con credenciales de BD y demás

# 4. Crear base de datos en XAMPP
# Abrir phpMyAdmin → http://localhost/phpmyadmin
# Crear base de datos "didi2"
# Importar migrations en orden o usar:
php bin/migrate.php

# 5. Iniciar servidor de desarrollo
php -S 127.0.0.1:8000 -t public/

# 6. Abrir en navegador
# http://127.0.0.1:8000
```

## Despliegue en producción

### Con PHP built-in (desarrollo)
```bash
php -S 127.0.0.1:8000 -t public/
```

### Con Apache + XAMPP
1. Copiar el contenido de `public/` a `C:\xampp\htdocs\didi2\`
2. Configurar VirtualHost en `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:
```apache
<VirtualHost *:80>
    ServerName didi2.local
    DocumentRoot "C:/xampp/htdocs/didi2"
    <Directory "C:/xampp/htdocs/didi2">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
3. Agregar `127.0.0.1 didi2.local` al archivo `hosts` de Windows
4. Reiniciar Apache en XAMPP

### Con cloudflared tunnel (demo pública)
```bash
# Instalar cloudflared
# Ejecutar tunnel:
cloudflared tunnel --url http://127.0.0.1:8000
```

## Respaldo y restauración

### Crear respaldo
```bash
./backup-restore.sh backup
```

### Listar respaldos
```bash
./backup-restore.sh listar
```

### Restaurar
```bash
./backup-restore.sh backup restore /salida/backups/didi2_20261116_000000.sql
```

## Pruebas de carga

### Con k6
```bash
# Instalar k6: https://k6.io/docs/get-started/installation/
k6 run k6/load-test.js
# O con URL personalizada:
BASE_URL=https://tu-dominio.com k6 run k6/load-test.js
```

### Análisis de queries
```bash
./analyze-queries.sh
```

## Variables de entorno

| Variable | Descripción | Ejemplo |
|---|---|---|
| `DB_HOST` | Host de MariaDB | `127.0.0.1` |
| `DB_PORT` | Puerto de MariaDB | `3307` |
| `DB_NAME` | Nombre de la base de datos | `didi2` |
| `DB_USER` | Usuario de la BD | `didi2_app` |
| `DB_PASS` | Contraseña de la BD | `***` |
| `AUTH_SECRET` | Secreto para sesiones | `generar-uno-seguro` |

## Estructura de despliegue

```
didi2-b2b/
├── public/              ← Document root del servidor web
│   ├── index.php        ← Front controller
│   ├── manifest.json    ← PWA manifest
│   ├── sw.js            ← Service worker
│   ├── icon-192.png     ← PWA icon
│   ├── icon-512.png     ← PWA icon
│   ├── robots.txt
│   └── security.txt
├── app/                 ← Código PHP
│   ├── Config/          ← Rutas y configuración
│   ├── Core/            ← Router, Request, Response, View, etc.
│   ├── Modules/         ← Módulos por funcionalidad
│   └── Views/           ← Plantillas HTML
├── database/
│   ├── migrations/      ← Migraciones numeradas
│   └── seeds/           ← Datos semilla
├── tests/               ← PHPUnit tests
├── k6/                  ← Pruebas de carga
├── deploy.sh            ← Script de despliegue
├── backup-restore.sh    ← Script de respaldo/restauración
├── analyze-queries.sh   ← Script de análisis EXPLAIN
└── composer.json
```
