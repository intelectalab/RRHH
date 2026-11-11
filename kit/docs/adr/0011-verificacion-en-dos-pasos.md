# 0011 · Verificación en dos pasos obligatoria para roles con datos sensibles

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: D-08 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit

## Contexto

Quien ve sueldos, datos de salud, denuncias o la configuración de seguridad es el blanco de mayor valor. Exigir un segundo factor a todos los colaboradores, incluido el personal operativo sin correo, frena la adopción del portal.

## Opciones consideradas

1. **Opcional para todos.**
2. **Obligatoria para los roles con datos sensibles.**
3. **Obligatoria para todos.**

## Decisión

Opción 2, y cada organización puede activar la opción 3 como política propia. Es obligatoria para los roles que tienen algún permiso marcado como sensible en `permisos_y_roles.json`, por ejemplo sueldos, planilla, salud, denuncias, seguridad y administración. La consola de la plataforma también la exige siempre. Para el personal sin correo, el segundo factor es un código enviado a su teléfono verificado.

## Consecuencias

- La marca de permiso sensible decide la obligación, así que un rol personalizado con un permiso sensible también queda obligado.
- Al recibir un permiso sensible, el usuario debe configurar el segundo factor en su siguiente ingreso antes de usar ese permiso.
- La política «obligatoria para todos» es un parámetro por organización.

## Comprobación

Hay pruebas de SEG: un usuario con un rol sensible y sin segundo factor no puede abrir una ruta sensible, uno sin rol sensible sí puede, y con la política de la organización activada nadie entra sin segundo factor.
