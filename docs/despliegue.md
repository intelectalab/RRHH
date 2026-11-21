# Despliegue en un VPS (ambiente de prueba y producción)

QA-17 · Cómo subir GestiaHR a un servidor Ubuntu con Docker. Todo corre en un solo VPS. **Caddy** recibe los puertos 80 y 443, obtiene los certificados HTTPS solo y reparte por dominio:

| Dirección | Qué es | Servicio interno |
|---|---|---|
| `https://staging.gestiahr.com` | Sitio comercial | `site:3001` |
| `https://admin.staging.gestiahr.com` | Consola de la plataforma | `web:3000` |
| `https://<subdominio>.staging.gestiahr.com` | Aplicación de cada organización (la demo: `demo`) | `web:3000` |
| `https://api.staging.gestiahr.com` | API (integraciones y app móvil) | `api:8000` |

Postgres, Redis, la API, las colas y el programador quedan en la red interna de Docker, sin puertos abiertos. Para producción se cambia `BASE_DOMAIN=gestiahr.com` y `SITE_ENV=production`. El resto es igual.

Archivos involucrados:
- `docker-compose.prod.yml`
- `docker/prod/` (imágenes, Caddyfile y php.ini)
- `.env.staging.example`
- `scripts/deploy.sh`, `rollback.sh`, `backup.sh` y `restore.sh`
- `.github/workflows/deploy.yml`

## 1. Servidor

- Ubuntu 24.04 LTS de 64 bits.
- Mínimo 2 vCPU, 4 GB de RAM y 60 GB de disco. Para el ambiente con piloto conviene 4 vCPU y 8 GB.
- La primera construcción de imágenes usa bastante memoria. Con 4 GB, agrega 4 GB de swap (paso 3).
- Una IP pública fija.

## 2. DNS (en el panel donde compraste gestiahr.com)

Crea estos registros tipo **A** apuntando a la IP del VPS:

| Nombre | Tipo | Valor |
|---|---|---|
| `staging` | A | IP del VPS |
| `*.staging` | A | IP del VPS |

Con eso funcionan `staging.gestiahr.com`, `admin.staging…`, `api.staging…`, `demo.staging…` y cualquier organización nueva.

Para producción, más adelante: `@` A IP, `*` A IP y `www` A IP.

Comprueba desde tu PC con `nslookup demo.staging.gestiahr.com`: debe responder la IP del VPS.

## 3. Preparar el servidor (una sola vez)

Entra por SSH como root (desde Windows: `ssh root@IP` en cmd o PowerShell) y ejecuta:

```bash
# Actualizar e instalar Docker
apt update && apt upgrade -y
curl -fsSL https://get.docker.com | sh

# Usuario de despliegue (no trabajar como root)
adduser --disabled-password --gecos "" deploy
usermod -aG docker deploy

# Cortafuegos: solo SSH, HTTP y HTTPS
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw allow 443/udp && ufw --force enable

# Swap de 4 GB (si el VPS tiene 4 GB de RAM o menos)
fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile && echo '/swapfile none swap sw 0 0' >> /etc/fstab

# Carpeta del proyecto
mkdir -p /opt/gestiahr && chown deploy:deploy /opt/gestiahr
```

## 4. Acceso del servidor al repositorio

El repositorio es privado, así que el servidor necesita una **clave de solo lectura** (deploy key):

```bash
su - deploy
ssh-keygen -t ed25519 -C "vps-gestiahr" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Copia esa línea en GitHub › intelectalab/RRHH › Settings › Deploy keys › Add deploy key, **sin** marcar «Allow write access». Luego:

```bash
git clone git@github.com:intelectalab/RRHH.git /opt/gestiahr
cd /opt/gestiahr
```

## 5. Variables

```bash
cp .env.staging.example .env
nano .env
```

Completa:
- **`APP_KEY`:** genérala con `echo "base64:$(openssl rand -base64 32)"`. **Guárdala también fuera del servidor**, en tu gestor de claves. Sin ella no se pueden leer los datos cifrados: no se puede perder ni cambiar.
- **`DB_PASSWORD` y `REDIS_PASSWORD`:** cada una con `openssl rand -hex 24`.
- **`ACME_EMAIL`:** tu correo, para los avisos de los certificados.
- **`MAIL_*`:** los datos de tu SMTP. Puerto 587 con `tls` o 465 con `ssl`. El remitente debe ser una dirección que tu SMTP permita enviar.
- **`DEMO_ADMIN_PASSWORD` y `PLATFORM_ADMIN_PASSWORD`:** claves fuertes. Solo se usan en la primera instalación y después se borran del archivo.

Protege el archivo con `chmod 600 .env`. **El `.env` nunca se sube al repositorio.**

## 6. Primera instalación

```bash
SEED_DEMO=1 scripts/deploy.sh
```

El script hace todo en orden:
1. Trae el código.
2. Construye las imágenes (la primera vez tarda varios minutos).
3. Crea la base desde `kit/database/schema.sql`.
4. Corre las migraciones.
5. Carga los parámetros legales y la ayuda.
6. Siembra la organización demo.
7. Levanta los servicios y comprueba que la API responda.

Crea el administrador de la consola:

```bash
docker compose -f docker-compose.prod.yml run --rm api php artisan platform:create-admin tu@correo.pe "Tu Nombre"
```

Después borra `DEMO_ADMIN_PASSWORD` y `PLATFORM_ADMIN_PASSWORD` del `.env`.

Revisa en el navegador:
- `https://staging.gestiahr.com`: el sitio comercial. Todas sus páginas llevan `noindex`, así que Google no lo indexa.
- `https://admin.staging.gestiahr.com/plataforma`: la consola. Te pedirá configurar el segundo factor.
- `https://demo.staging.gestiahr.com`: la demo. Entra con el admin demo y `DEMO_ADMIN_PASSWORD`.

La primera visita a cada subdominio tarda unos segundos más, porque es cuando Caddy obtiene su certificado.

## 7. Actualizar a una versión nueva

Por SSH, como `deploy`, en `/opt/gestiahr`:

```bash
scripts/deploy.sh
```

Antes de migrar, el script hace una copia de seguridad.

Desde GitHub también se puede: Actions › «CD Deploy & Rollback» › Run workflow › staging › deploy. Solo despliega commits con CI en verde. Requiere estos secretos en Settings › Environments › `staging`:
- `VPS_HOST`: la IP.
- `VPS_USER`: `deploy`.
- `VPS_PATH`: `/opt/gestiahr`.
- `VPS_SSH_KEY`: una clave privada cuya pública esté en `/home/deploy/.ssh/authorized_keys` del servidor.

## 8. Volver a la versión anterior

```bash
scripts/rollback.sh            # a la versión anterior
scripts/rollback.sh a1b2c3d    # a un commit concreto
```

Las migraciones son compatibles hacia atrás, así que la versión anterior funciona sobre la base actual. Si además hay que volver los datos, usa la restauración (paso 9).

## 9. Copias de seguridad y restauración

Programa la copia diaria con `crontab -e` (como `deploy`):

```
30 3 * * * cd /opt/gestiahr && scripts/backup.sh diaria >> backups/cron.log 2>&1
```

Cada copia guarda:
- La base completa: `backups/db-<fecha>.dump`.
- Los archivos subidos: `backups/files-<fecha>.tar.gz`.
- Su suma de verificación.

Se conservan 14 días.

**Copia la carpeta `backups/` fuera del servidor** (otro proveedor, o tu PC con `scp`). Una copia en el mismo disco no sirve si se pierde el servidor.

Probar que una copia se puede restaurar, sin tocar la base en uso (QA-17 lo exige antes de salir):

```bash
ls backups/                      # ver las copias
scripts/restore.sh --check 20261121T033000Z-diaria
```

Restaurar de verdad (pide escribir RESTAURAR y antes hace otra copia):

```bash
scripts/restore.sh 20261121T033000Z-diaria
```

## 10. Procedimiento ante incidentes

1. **Ver qué falla:**
   - `docker compose -f docker-compose.prod.yml ps`: todo debe estar `running` o `healthy`.
   - `docker compose -f docker-compose.prod.yml logs --tail=200 api`. También `web`, `horizon` o `caddy`.
2. **Si el problema llegó con una actualización:** `scripts/rollback.sh`.
3. **Si se dañaron datos:** `scripts/restore.sh --check <copia>` y luego `scripts/restore.sh <copia>`.
4. **Si se cayó el servidor completo:**
   1. Arma uno nuevo con los pasos 1 a 5, usando **la misma `APP_KEY`**.
   2. Levanta la base con `docker compose -f docker-compose.prod.yml up -d postgres redis`.
   3. Copia `backups/` al servidor nuevo y restaura con `scripts/restore.sh <copia>`.
   4. Cambia la IP en el DNS.
5. **Registra el incidente** (la consola cuenta los incidentes pero aún no tiene pantalla para registrarlos): qué pasó, desde cuándo, a quién afectó, qué se hizo y cómo se resolvió, en un documento con fecha que conserve el equipo.
6. **Si hubo exposición de datos personales:** sigue el plan de respuesta de `kit/docs/12-seguridad-y-privacidad.md` §11 (registro de incidentes de privacidad y notificación dentro del plazo, que es un parámetro).

## 11. Antes de pasar a producción real

El ambiente de prueba sirve para mostrar el producto y para que la organización piloto pruebe. Para cerrar **QA-17** y operar en producción con planillas reales faltan estas cosas:

- **Parámetros legales:** el especialista laboral valida los marcados «por validar» y los casos dorados de planilla (`kit/docs/19`).
- **Planilla en paralelo:** la organización piloto migra con los importadores, calcula al menos un periodo en paralelo con su sistema anterior y cada diferencia queda explicada (Nómina › Paralelo).
- **Proveedores reales** de firma electrónica con certificado, notificaciones push, facturación electrónica/OSE, tipo de cambio, IA (A-09) y copias externas. Hoy usan los adaptadores falsos. En `APP_ENV=production` la API exige proveedores reales.
- **Estructuras oficiales:** PLAME, T-Registro y AFPnet confirmadas, y los formatos bancarios de los bancos del piloto.
- **Base de datos:** hoy la API se conecta como dueña de la base y en cada petición baja a `gestia_app`, el rol sin privilegios con RLS forzado. En producción conviene un usuario de conexión aparte, sin superusuario, miembro de `gestia_app` y `gestia_platform`, y dejar al dueño solo para migraciones.
- **Prueba de restauración:** una restauración probada (paso 9) y este procedimiento de incidentes revisado por el equipo.
