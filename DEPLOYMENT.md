# Despliegue gratuito (Render + Railway)

Este flujo separa la base de datos del código y deja todo listo para ejecutarlo como arquitectura cliente-servidor sin dominio propio. Todos los servicios sugeridos tienen plan gratuito (limitado pero suficiente para pruebas).

## 1. Exporta tu base local

`ash
mysqldump -u root -p sena_bolsa_empleo > sql/sena_bolsa_empleo.sql
`

- Cambia usuario/contraseña si tu entorno es distinto.
- El archivo generado en sql/ será la copia que subirás al servicio remoto.

## 2. Crea una base en Railway (gratis)

1. Ingresa a <https://railway.app>, crea cuenta y pulsa **New Project ? Provision MySQL**.
2. Railway levantará una instancia MySQL 8 con usuario/contraseña aleatorios.
3. En la pestaña **Variables** encontrarás MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE. Guarda esos datos: serán tu DB_HOST, DB_PORT, etc.

> Alternativas: PlanetScale (MySQL) o Neon (Postgres) también sirven; solo asegúrate de traducir credenciales a las variables del archivo config/database.php.

## 3. Importa el dump

Descarga el archivo .sql desde Railway (botón **Connect ? Download CA Cert** no es necesario) y ejecuta:

`ash
mysql -h <MYSQLHOST> -P <MYSQLPORT> -u <MYSQLUSER> -p<MYSQLPASSWORD> <MYSQLDATABASE> < sql/sena_bolsa_empleo.sql
`

- No hay espacio entre -p y la contraseña.
- Si usas Windows y prefieres interfaz gráfica, también puedes cargar el dump con MySQL Workbench apuntando al host remoto.

## 4. Configura variables de entorno

El archivo config/database.php ya lee valores desde DB_*. Debes definirlos donde se ejecute PHP:

| Variable | Valor (Railway) |
| --- | --- |
| DB_HOST | MYSQLHOST |
| DB_PORT | MYSQLPORT |
| DB_NAME | MYSQLDATABASE |
| DB_USER | MYSQLUSER |
| DB_PASS | MYSQLPASSWORD |

### Prueba local

`ash
set DB_HOST=<host_remoto>
set DB_PORT=<puerto>
set DB_NAME=<db>
set DB_USER=<usuario>
set DB_PASS=<contraseña>
php -S 127.0.0.1:8000 index.php
`

En Linux/macOS reemplaza set por export. Si todo funciona localmente con la base remota, estás listo para desplegar.

## 5. Despliega el código en Render (plan gratuito)

1. Sube el proyecto a un repositorio Git (GitHub, GitLab o Bitbucket).
2. Render detectará el archivo ender.yaml del proyecto. Desde tu panel ve a **Blueprints ? New Blueprint Instance** y selecciona el repo.
3. Asegúrate de que el servicio creado tenga:
   - **Environment**: PHP.
   - **Build Command**: composer install --no-dev --optimize-autoloader.
   - **Start Command**: php -S 0.0.0.0: index.php (ajusta si tienes un public/ distinto).
4. En la pestaña **Environment** agrega las variables DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS usando los valores de Railway (Render marcará como “from dashboard” porque en ender.yaml están como sync: false).
5. Haz deploy. Render te dará una URL https://<nombre>.onrender.com lista para compartir.

## 6. Pasos opcionales

- **Supervisión**: activa alertas gratuitas en Render para reinicios o errores.
- **Backups**: crea un job en Railway (o un cron externo) que ejecute mysqldump y suba el archivo a un bucket gratuito (Backblaze B2 o incluso GitHub Releases privados).
- **Dominio propio**: más adelante puedes apuntar un dominio a la URL de Render desde Cloudflare gratuito.

Con esto el proyecto queda completamente desacoplado: el front/controladores PHP viven en Render y la base MySQL se ejecuta en Railway. Solo necesitas mantener las variables de entorno sincronizadas cuando regeneres credenciales o hagas rotación de contraseñas.
