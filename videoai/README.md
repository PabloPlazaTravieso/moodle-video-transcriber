# mod_videoai: Video Transcriber / Transcriptor de vídeo para Moodle

Actividad de Moodle 4.5+ en la que el profesor sube un vídeo y el plugin, en segundo plano:

1. **Separa el audio** → `nombre-audio.wav` (PCM 16 bits, 16 kHz, mono: el formato que espera Whisper).
   Opcionalmente, también un **vídeo sin sonido**, desactivado por defecto porque no hace falta para transcribir y casi
   duplica el espacio.
2. **Transcribe el audio** y guarda la transcripción **frase a frase con sus tiempos**: en la tabla `videoai_segments`
   y como subtítulos `.vtt` y `.json` descargables. Hay dos motores:
   - **FFmpeg (por defecto)**: el filtro `whisper` de FFmpeg 8+, con whisper.cpp por dentro. Lo ejecuta el mismo ffmpeg
     que separa el audio, así que **todo ocurre dentro del plugin**, sin servicios aparte.
   - **Servicio externo**: `transcriber/` (faster-whisper). Es más rápido y puede ir en otro servidor con GPU.
3. **La ofrece a la IA** que responde preguntas, mediante el servicio web `mod_videoai_get_transcript` o la API PHP.

## Requisitos

- Moodle 4.5 o superior, PHP 8.1+.
- `ffmpeg` y `ffprobe` en el servidor de Moodle. Para transcribir con el motor FFmpeg: **FFmpeg 8 o posterior compilado
  con `--enable-whisper`** (ver abajo). El `apt install ffmpeg` de Debian 12 o Ubuntu 24.04 solo sirve para separar el audio.
- Modelos para el motor FFmpeg (unos 575 MB):
  - `ggml-large-v3-turbo-q5_0.bin` de <https://huggingface.co/ggerganov/whisper.cpp>;
  - `ggml-silero-v5.1.2.bin` (detector de voz) de <https://huggingface.co/ggml-org/whisper-vad>.
- Cron de Moodle funcionando (separación y transcripción son tareas adhoc).
- Solo si se elige el motor «Servicio externo»: el servicio [`transcriber/`](../transcriber/README.md) accesible desde Moodle.

## Instalación

1. Copiar la carpeta `videoai` a `<moodle>/mod/videoai` y entrar en *Administración del sitio → Notificaciones*.
2. En *Administración del sitio → Plugins → Módulos de actividad → Transcriptor de vídeo*:
   - rutas a ffmpeg/ffprobe;
   - sección **Transcripción**: activar «Transcribir el audio», idioma (`es`, o vacío para detectarlo) y motor.
     - **FFmpeg**: rutas a los dos modelos. Por defecto: trozos de 25 s, corte en pausas de 2 s y todos los núcleos.
     - **Servicio externo**: URL (p. ej. `http://transcriber:8000`) y clave.

### FFmpeg con Whisper

La receta probada está en [`spike/Dockerfile`](../spike/Dockerfile):
1. Compilar whisper.cpp v1.9.4 como librería e instalarla en `/usr/local`.
2. Compilar FFmpeg 8.1.3 con `./configure --enable-gpl --enable-libx264 --enable-whisper`.

Se comprueba con `ffmpeg -hide_banner -filters | grep whisper`. Si falta, la actividad muestra un error claro.

## Funcionamiento

```
Profesor guarda la actividad
  → tarea process_video: ffprobe + ffmpeg → área "audio" (WAV)
  → tarea transcribe_audio: ffmpeg -af whisper (o POST al servicio) → videoai_segments + área "transcript" (.vtt, .json)
```

- Solo se procesa de nuevo si el vídeo cambia. Reprocesar el mismo vídeo genera un WAV idéntico, y la transcripción,
  que es el paso caro, no se repite salvo con **«Volver a transcribir»**.
- Motor FFmpeg: si falla (FFmpeg sin filtro whisper, modelo inexistente, audio ilegible), el error se muestra en la
  actividad y no se reintenta, porque repetir no cambiaría el resultado.
- Motor servicio: si el servicio no responde o devuelve `5xx`, la tarea **se reintenta automáticamente**. Si rechaza la
  petición (clave incorrecta, audio ilegible), no se reintenta.
- Con los dos motores se descartan los fragmentos que son solo una frase típica inventada por Whisper
  («Gracias por ver el video», «Subtítulos realizados por la comunidad de Amara.org»…).
- Si el vídeo se sustituye por uno sin audio, o falla, se borran el audio y la transcripción anteriores para que la IA
  no responda sobre el vídeo equivocado.

Quién ve qué (permisos por defecto):

| Permiso | Quién | Para qué |
|---|---|---|
| `mod/videoai:view` | todos los participantes | ver el vídeo |
| `mod/videoai:process` | profesor con edición, gestor | estado, audio separado, reprocesar, volver a transcribir |
| `mod/videoai:viewtranscript` | profesores, gestor | ver y descargar la transcripción, y leerla por servicio web |

## Integración con la IA de preguntas

### Servicio web (REST)

1. Activar servicios web y el protocolo REST.
2. Crear un servicio externo con la función `mod_videoai_get_transcript`.
3. Crear un usuario técnico con un rol que tenga `mod/videoai:viewtranscript` en los cursos, y darle un token.

```sh
curl https://moodle.example.com/webservice/rest/server.php \
  -d wstoken=TOKEN -d wsfunction=mod_videoai_get_transcript -d moodlewsrestformat=json -d cmid=123
```

Respuesta: `ready`, `status`, `language`, `duration`, `model`, `timetranscribed`, `segments` (`start`, `end`, `text`)
y `text`, que es la transcripción completa con una línea por frase y el formato `[HH:MM:SS] texto`, lista para meter
en el prompt y que la IA pueda citar el minuto.

### Desde PHP (otro plugin de Moodle)

```php
use mod_videoai\local\transcript;
$segments = transcript::get_segments($videoaiid);        // [['start' => 1.14, 'end' => 6.42, 'text' => '...'], ...]
$prompt   = transcript::as_timestamped_text($videoaiid); // "[00:00:01] Hoy vamos a hablar de..."
```

## Depuración

```
php admin/cli/adhoc_task.php --execute          # ejecutar las tareas pendientes ya
php mod/videoai/cli/process.php --id=<id de mdl_videoai> --force
vendor/bin/phpunit --filter mod_videoai         # el test con vídeo real necesita MOD_VIDEOAI_TEST_FFMPEG/FFPROBE
```

## Privacidad

El audio de las clases se envía al servicio de transcripción configurado. Instálalo en vuestra propia infraestructura:
el servicio no guarda nada, trabaja con un archivo temporal que se borra al terminar. El plugin no almacena datos
personales de los usuarios (proveedor de privacidad nulo), pero la transcripción contiene lo que se dice en el vídeo.

## Limitaciones conocidas

- Motor FFmpeg: el filtro corta el audio en trozos fijos de 25 s sin solaparlos. Si el corte cae en mitad de una frase,
  Whisper puede completarla mal (una vez cada 25 s de habla continua como mucho). Tras un silencio largo con música,
  el inicio de la frase siguiente puede aparecer hasta un trozo antes (15 s en el spike). El filtro no permite ajustar
  el decodificador de Whisper. Si esto importa, usa el motor «Servicio externo».
- Motor FFmpeg: una hora de clase tarda aproximadamente una hora en 8 hilos de CPU y usa la CPU del servidor de Moodle.
  Baja «Hilos de CPU» si afecta a los usuarios.

- Sin copia de seguridad/restauración todavía (`FEATURE_BACKUP_MOODLE2` desactivado).
- El tamaño máximo del vídeo lo limitan `upload_max_filesize` / `post_max_size` de PHP y el máximo del curso.
- El WAV ocupa unos 115 MB por hora de vídeo. En CPU la transcripción tarda aproximadamente la mitad de la duración del vídeo.
