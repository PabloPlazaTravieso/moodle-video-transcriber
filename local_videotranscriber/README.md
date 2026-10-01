# local_videotranscriber: transcripción automática de los vídeos de los cursos para Pulse

Plugin local de Moodle 4.5+ que **transcribe solo los vídeos que el profesorado ya ha subido a un curso en cuanto Pulse
(el chatbot que responde las preguntas) se activa en ese curso**. Usa el motor de [`mod_videoai`](../videoai/README.md):
FFmpeg separa el audio y Whisper lo transcribe. Pulse lee después las transcripciones por servicio web.

## El disparador: Pulse

| Cuándo | Qué pasa |
|---|---|
| **Pulse se activa en un curso** y llama a `local_videotranscriber_set_course_enabled(courseid, 1)` | Se buscan los vídeos del curso y se envían a transcribir **en ese momento** |
| **Pulse ya estaba activo** y pide `local_videotranscriber_get_course_transcripts(courseid)` de un curso no activado | El curso se activa solo y empieza a transcribirse. La respuesta lo indica con `activated: true` (ajuste «Activar al pedir transcripciones») |
| **El profesorado añade o cambia contenido** en un curso activo | Se buscan los vídeos nuevos de ese curso en unos minutos |
| **Pulse se desactiva** (`set_course_enabled(courseid, 0)`) | No se transcriben vídeos nuevos; las transcripciones ya hechas se conservan |
| **Cada noche** (02:00) | Red de seguridad: recoge lo que se haya escapado y olvida los vídeos borrados |

Un administrador también puede activar o desactivar un curso a mano desde la página «Transcripciones de vídeos» del curso.

## Para el equipo de Pulse: integración

1. **Cuenta de servicio en Moodle** (por ejemplo `pulsebot`) con un rol de sistema que tenga:
   - `local/videotranscriber:view`: leer las transcripciones;
   - `local/videotranscriber:manage`: activar o desactivar cursos;
   - `moodle/course:view` («Ver cursos sin participación»): la cuenta no está matriculada, y sin este permiso Moodle
     responde `requireloginerror`;
   - `webservice/rest:use`.
2. **Servicio externo** con las dos funciones `local_videotranscriber_set_course_enabled` y
   `local_videotranscriber_get_course_transcripts`, y un **token** para la cuenta.
3. **Llamadas:**

```sh
# Al activar Pulse en un curso
curl https://tu-moodle/webservice/rest/server.php -d wstoken=TOKEN -d moodlewsrestformat=json \
  -d wsfunction=local_videotranscriber_set_course_enabled -d courseid=123 -d enabled=1
# → {"courseid":123,"enabled":true,"pluginenabled":true,"videosfound":4,"videosqueued":4}

# Para responder preguntas: transcripciones del curso
curl https://tu-moodle/webservice/rest/server.php -d wstoken=TOKEN -d moodlewsrestformat=json \
  -d wsfunction=local_videotranscriber_get_course_transcripts -d courseid=123
# → {"inscope":true,"activated":false,"unregistered":0,"videos":[{"ready":true,"status":3,
#     "locations":[{"cmid":45,"activityname":"Tema 1","filename":"clase.mp4","url":"..."}],
#     "text":"[00:00:00] Buenos días...\n[00:00:04] ...","segments":[...]}]}
```

Cada vídeo trae `status`:
- `0` en espera;
- `1` en cola;
- `2` transcribiendo;
- `3` listo;
- `4` error;
- `5` omitido (sin audio o demasiado largo).

Hasta que valga 3 no hay `text`. Transcribir tarda aproximadamente lo que dura el vídeo con el motor FFmpeg en 8 núcleos.

## Dónde busca vídeos

Solo en **material del curso añadido por el profesorado**:
- recursos Archivo y Carpeta;
- Página, Libro, Lección y Etiqueta;
- descripciones de cualquier actividad;
- secciones y resumen del curso;
- H5P y banco de contenidos.

**Nunca** en entregas de tareas, foros, archivos privados ni otras zonas donde suben archivos los alumnos.

El mismo vídeo en varios cursos se transcribe **una sola vez** (se identifica por su contenido). No se guarda el audio, solo
la transcripción. Los vídeos enlazados desde fuera (YouTube, Vimeo…) no se encuentran, porque no son archivos de Moodle.

## Instalación y ajustes

1. Necesita `mod_videoai` instalado y configurado: rutas de FFmpeg, modelos de Whisper e idioma.
2. Copia esta carpeta a `moodle/local/videotranscriber` e instálala desde *Administración del sitio → Notificaciones*.
3. En *Administración del sitio → Plugins → Plugins locales → Transcriptor de vídeo: vídeos de los cursos*:

| Ajuste | Por defecto | Uso |
|---|---|---|
| Transcribir automáticamente | No | Interruptor general |
| Cursos a procesar | **Cursos donde Pulse está activo** | O «Categorías seleccionadas» o «Todos los cursos» |
| Activar al pedir transcripciones | Sí | El caso «Pulse ya estaba activo» |
| Categorías | ninguna | Solo para el alcance por categorías; se incluyen las subcategorías |
| Vídeos encolados por ejecución | 10 | Límite por activación de curso y por noche |
| Duración máxima | 180 min | Los vídeos más largos se omiten |

## Pruebas

- **PHPUnit:** 8 tests propios más las comprobaciones del núcleo.
  - `discovery_test`: alcance, exclusiones, un registro por contenido, límite, limpieza, servicio web.
  - `pulse_test`: activación inmediata, permisos, activación al pedir transcripciones, cambios en el curso, desactivación.
- **Disparador de Pulse por REST**, con una cuenta de servicio como la de Pulse: [`spike/bench/pulse_e2e.sh`](../spike/bench/pulse_e2e.sh),
  11/11, sin ejecutar nunca la tarea nocturna.
- **Modo por categorías:** [`spike/bench/autotranscribe_e2e.php`](../spike/bench/autotranscribe_e2e.php) 13/13, más 6/6 web y REST.

## Limitaciones

- Un vídeo en estado «Error» no se reintenta solo, salvo que el servicio externo no estuviera disponible.
- Desactivar Pulse o reducir el alcance no borra transcripciones; solo se borran cuando el vídeo desaparece de Moodle.
- Con el motor FFmpeg se aplican sus limitaciones de tiempos tras silencios largos con música (ver el README de `mod_videoai`).
