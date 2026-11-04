# Human 3.3.6 (copia local)

Librería de rostro del reloj de asistencia (docs/PLAN_ASISTENCIA_Y_SUELDOS.md, F3). Licencia MIT (ver `LICENSE`).
Origen: paquete npm `@vladmandic/human@3.3.6` (`dist/human.esm.js` y los modelos de `models/`).

Se copia como archivos porque producción no instala dependencias npm nuevas y los modelos se sirven tal cual.
Solo se incluyen los modelos que usa el reloj: `blazeface` (detección), `facemesh` (puntos del rostro y parpadeo),
`faceres` (descriptor para comparar), `antispoof` y `liveness` (cara real). No se usa `iris`.

Para actualizar: `npm pack @vladmandic/human@<versión>`, copiar `dist/human.esm.js` y esos modelos, y probar el
reloj en la portátil de caja (la comparación del servidor usa la misma escala que `faceres`).
