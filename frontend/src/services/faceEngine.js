/**
 * Motor de rostro del reloj de asistencia. En esta fase (F2) no hay reconocimiento: el reloj toma la foto a mano
 * y el servidor la guarda como respaldo. La fase F3 lo reemplaza por la librería Human (detección, parpadeo y
 * descriptor del rostro) sin cambiar esta interfaz.
 *
 * Interfaz:
 *   load(): Promise<boolean>            ¿quedó listo?
 *   available(): boolean
 *   analyze(video): Promise<{ faces, blink, descriptor, liveness, ready }>
 *   reset(): void                       olvida el parpadeo de la persona anterior
 */
export const faceEngine = {
  async load() {
    return false;
  },
  available() {
    return false;
  },
  async analyze() {
    return { faces: 0, blink: false, descriptor: null, liveness: null, ready: false };
  },
  reset() {},
};
