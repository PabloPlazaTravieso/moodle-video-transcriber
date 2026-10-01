# moodle-video-transcriber

Plugin de actividad para **Moodle 4.5+** que convierte los vídeos de clase en texto que una IA puede usar para responder
preguntas:

```
Profesor sube un vídeo → FFmpeg separa el audio → Whisper lo transcribe → frases con [minuto:segundo] → IA de preguntas
```

En Moodle aparece como **«Transcriptor de vídeo»**; su nombre técnico es `mod_videoai`.

**Estado:** alfa (v0.3.0). Separación y transcripción funcionan y están probadas en un Moodle 4.5 de pruebas.
La parte de preguntas y respuestas (la IA) está por integrar.

## Qué hay en el repositorio

| Carpeta | Contenido |
|---|---|
| [`videoai/`](videoai/) | **El plugin de Moodle** (`mod_videoai`). Se copia a `moodle/mod/videoai`. Ver su [README](videoai/README.md). |
| [`local_videotranscriber/`](local_videotranscriber/) | **Transcripción automática de los vídeos que ya están en los cursos** (Archivo, Página, Carpeta…). Se copia a `moodle/local/videotranscriber`. Ver su [README](local_videotranscriber/README.md). |
| [`transcriber/`](transcriber/) | Servicio opcional de transcripción (Python, faster-whisper) para cuando hay mucho volumen o una GPU aparte. |
| [`spike/`](spike/) | Investigación y pruebas: entorno Docker con Moodle, vídeos de prueba, comparativa de 7 librerías de transcripción y todos los resultados. Empieza por [`spike/SPIKE.md`](spike/SPIKE.md). |

## Cómo funciona

1. El profesor crea una actividad «Transcriptor de vídeo» y sube el vídeo.
2. Una tarea en segundo plano ejecuta **FFmpeg** y extrae el audio (WAV de 16 kHz y mono). Una hora de vídeo se separa en segundos.
3. Otra tarea transcribe el audio con **Whisper** (modelo `large-v3-turbo`). Hay dos motores, a elegir en los ajustes:
   - **FFmpeg** (por defecto): el filtro `whisper` de FFmpeg 8 con el mismo ffmpeg. Todo queda dentro del plugin.
   - **Servicio externo**: `transcriber/` con faster-whisper. Es el doble de rápido y puede ir en otro servidor.
4. La transcripción se guarda frase a frase con sus tiempos, y también como subtítulos `.vtt` y `.json`.
5. La IA la lee con el servicio web **`mod_videoai_get_transcript`**:

```sh
curl https://tu-moodle/webservice/rest/server.php \
  -d wstoken=TOKEN -d wsfunction=mod_videoai_get_transcript -d moodlewsrestformat=json -d cmid=123
# → {"ready": true, "segments": [{"start": 1.14, "end": 6.42, "text": "Hoy vamos a hablar de..."}],
#    "text": "[00:00:01] Hoy vamos a hablar de...\n[00:00:06] ...", ...}
```

### Vídeos que ya están en los cursos: automático con Pulse

Con [`local_videotranscriber`](local_videotranscriber/README.md), los vídeos que el profesorado ha puesto en un curso se
transcriben solos **en cuanto Pulse (el chatbot) se activa en ese curso**:
- Pulse llama a `local_videotranscriber_set_course_enabled`;
- o, si ya estaba activo, basta con que pida las transcripciones;
- los vídeos que se añadan después también se detectan solos.

El mismo vídeo en varios cursos se transcribe una sola vez, y nunca se tocan las entregas ni los archivos de los alumnos.
Pulse lee el resultado con `local_videotranscriber_get_course_transcripts`, que indica también en qué actividad está cada
vídeo. La guía de integración para el equipo de Pulse está en su [README](local_videotranscriber/README.md#para-el-equipo-de-pulse-integración).

## Instalación rápida

1. Copia [`videoai/`](videoai/) a `moodle/mod/videoai` e instálalo desde *Administración del sitio → Notificaciones*.
2. En el servidor necesitas **FFmpeg 8 o posterior compilado con `--enable-whisper`**. El de Debian o Ubuntu solo sirve para
   separar el audio. La receta probada está en [`spike/Dockerfile`](spike/Dockerfile).
3. Descarga los modelos (unos 575 MB):
   - [`ggml-large-v3-turbo-q5_0.bin`](https://huggingface.co/ggerganov/whisper.cpp);
   - [`ggml-silero-v5.1.2.bin`](https://huggingface.co/ggml-org/whisper-vad), el detector de voz.
4. En *Plugins → Módulos de actividad → Transcriptor de vídeo*: rutas de ffmpeg, ffprobe y los modelos, activar
   «Transcribir el audio» e idioma `es`.
5. El cron de Moodle tiene que estar funcionando.

## Resultados de las pruebas

Comparativa con las mismas 7 muestras en español (5 sintéticas con texto exacto y 2 lecturas reales), en CPU de 8 hilos sin GPU.
Detalle completo en [`spike/asr/RESULTADOS.md`](spike/asr/RESULTADOS.md).

| Motor | Error sintéticos | Error voz real | Texto inventado en silencios | Tiempo por hora de clase | RAM |
|---|---|---|---|---|---|
| **FFmpeg 8 + whisper** (plugin) | 3,3 % | 7,1 % | 0 | ~1 h | 0,75 GB |
| faster-whisper por lotes (servicio) | 2,1 % | 7,4 % | 0 | ~26 min | 3,7 GB |
| WhisperX | 1,7 % | 6,3 % | 0 | ~35 min | 4,4 GB |
| Parakeet v3 (NVIDIA) | 2,6 % | 10,2 % | 0 | ~21 min | 2,3 GB |
| openai-whisper | 4,4 % | 7,6 % | 5 palabras | ~1 h 18 min | 4,9 GB |
| Vosk | 11,3 % | 14,4 % | 1 palabra | ~7 min | 0,3 GB |

El «error» es el porcentaje de palabras mal transcritas. En voz real está inflado porque la referencia es aproximada.

Pruebas del plugin:
- tests automáticos (PHPUnit): 24/24;
- separación de principio a fin: 24/24;
- transcripción de principio a fin: 22/22 con el servicio y 18/19 con FFmpeg (ver limitaciones);
- transcripción automática de los cursos: PHPUnit 8/8, disparador de Pulse por REST 11/11, modo por categorías 13/13 y web/REST 6/6.

## Limitaciones conocidas

- **Motor FFmpeg:** corta el audio en trozos fijos de 25 s. Tras un silencio largo con música, el tiempo de la frase
  siguiente puede adelantarse (en las pruebas, del segundo 70 al 55). Si un corte cae en mitad de una frase, puede
  completarla mal. El motor servicio no tiene estos problemas.
- Falta la copia de seguridad y restauración de la actividad.
- Falta la interfaz de preguntas y respuestas para los alumnos (la parte de la IA).
- Los vídeos enlazados desde fuera de Moodle (YouTube, Vimeo…) no se transcriben automáticamente.
- Solo se ha probado con voz sintética, Wikipedia y documentales; faltan clases reales.

## Probarlo en local (Docker)

```sh
cd spike
echo "MOODLE_SRC=/ruta/a/un/checkout/de/moodle-4.5" > .env
docker compose up -d --build              # Moodle + PostgreSQL + FFmpeg 8 con Whisper
docker compose exec webserver sh /bench/sync_code.sh
docker compose exec -u www-data webserver php admin/cli/install_database.php --agree-license \
  --fullname="Video Transcriber" --shortname=vt --adminuser=admin --adminpass='Admin1234!' --adminemail=admin@example.com
```

Sitio en <http://localhost:8000>. Las credenciales de [`spike/`](spike/) (`Admin1234!`, `spike-secret`, `moodle/moodle`) son
**solo para este entorno local**. Los pasos completos, las muestras y la comparativa están en [`spike/SPIKE.md`](spike/SPIKE.md).

## Licencia

[GNU GPL v3 o posterior](LICENSE), como exige Moodle para sus plugins. Los vídeos de prueba se descargan de Wikimedia Commons
con sus propias licencias (ver [`spike/samples/SOURCES.md`](spike/samples/SOURCES.md)) y no se incluyen en el repositorio.
