<?php
/**
 * Configuración local de base de datos para el portal PHP.
 *
 * Copia este archivo y ajusta los valores según tu entorno.
 * También puedes sobrescribirlos con variables de entorno:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */
return [
  'host' => getenv('DB_HOST') ?: '127.0.0.1',
  'port' => (int)(getenv('DB_PORT') ?: 3306),
  'name' => getenv('DB_NAME') ?: 'mydb',
  'user' => getenv('DB_USER') ?: 'root',
  'pass' => getenv('DB_PASS') ?: '1234',
];
