# Spike: separación de audio y vídeo en Moodle (`mod_videoai`)

**Fecha:** 30/09/2026 · **Entorno:** Moodle 4.5.12+, PHP 8.3.32, PostgreSQL 16, FFmpeg 5.1.9 (Debian 12), Docker sobre WSL2, Intel Core 5 210H (12 hilos), sin GPU.

## Pregunta

¿Puede un plugin de actividad de Moodle separar el audio de los vídeos que suben los profesores de forma fiable, rápida y sin bloquear la plataforma, dejando un audio listo para transcribir con Whisper?

## Respuesta corta

**Sí.** La separación con FFmpeg es prácticamente instantánea comparada con la transcripción que vendrá después. Una clase de 60 min se separa en unos 6 s y un vídeo de 545 MB, subido por el formulario real, queda procesado en unos 15 s. Solo hace falta instalar FFmpeg en el servidor de Moodle. El spike encontró **3 fallos en el plugin, ya corregidos**, y deja **3 decisiones abiertas** (ver al final).

## Qué se ha probado

| Prueba | Script | Resultado |
|---|---|---|
| 10 formatos y casos límite con la clase `ffmpeg` del plugin | `bench/bench.php` | 8 OK y 2 errores esperados |
| Flujo completo en Moodle: crear, editar, cambiar vídeo, error, reprocesar, duplicados, borrar | `bench/e2e.php` | 22/22 comprobaciones |
| HTTP como profesor y como alumno | `bench/http.sh` | 10/10 comprobaciones |
| Subida de 545 MB por el formulario real y procesado por la cola | `bench/upload.sh` | OK |
| Tests PHPUnit del plugin, incluido el de vídeo real | `tests/local/ffmpeg_test.php` | 4 tests, 26 comprobaciones |

### Rendimiento (solo la separación con FFmpeg, en el contenedor)

| Vídeo | Duración | Tamaño | Extraer audio | Vídeo sin sonido | WAV resultante |
|---|---|---|---|---|---|
| MP4 H.264/AAC 720p | 1 min | 54 MB | 0,3 s | 0,3 s | 1,8 MB |
| MP4 H.264/AAC 720p | 10 min | 545 MB | 1,3 s | 1,5 s | 18,3 MB |
| MP4 H.264/AAC 360p | 60 min | 365 MB | 5,9 s | 1,5 s | 110 MB |
| WebM VP9/Opus | 1 min | 10 MB | 0,3 s | 0,2 s | 1,8 MB |
| MOV, nombre con espacios y tildes | 1 min | 54 MB | 0,3 s | 0,3 s | 1,8 MB |
| MKV con 2 pistas de audio | 1 min | 55 MB | 0,5 s | 0,5 s | 1,8 MB (primera pista) |
| AVI MPEG-4/MP3 | 1 min | 3 MB | 0,3 s | 0,2 s | 1,8 MB |
| MP4 HEVC/AAC | 1 min | 13 MB | 0,3 s | 0,3 s | 1,8 MB |
| MP4 sin audio | 30 s | 27 MB | — | — | error «El vídeo no tiene pista de audio» |
| MP4 truncado o corrupto | — | 0,3 MB | — | — | error con el mensaje de ffmpeg (`moov atom not found`) |

En todos los casos el WAV sale en `pcm_s16le`, 16 kHz y mono, que es el formato que espera Whisper, con la misma duración que el vídeo. Ningún resultado conserva la pista contraria: el WAV no lleva vídeo y la copia sin sonido no lleva audio.

**Flujo real con el vídeo de 545 MB:** subida HTTP en 6–11 s y procesado en 14,6 s, contando el arranque del cron, la copia a la carpeta temporal y el guardado en el almacén de ficheros de Moodle.

## Fallos encontrados y corregidos

1. **Audio del vídeo anterior tras un error.** Si el profesor cambiaba el vídeo por uno sin audio o corrupto, quedaba el WAV del vídeo anterior junto al estado «Error». En la fase de transcripción se habría transcrito el vídeo equivocado. **Corrección:** si falla un vídeo *nuevo*, se borran los archivos resultantes y la duración. Si falla el reprocesado del *mismo* vídeo, se conservan.
2. **`FILE_INTERNAL` sin definir fuera del formulario.** Al crear la actividad desde CLI o servicios web se producía un error fatal, porque esa constante vive en `repository/lib.php` y Moodle solo la carga en páginas con selector de ficheros. **Corrección:** se carga explícitamente, como hace `mod_resource`.
3. **Error 500 cuando un alumno pide el audio.** Se lanzaba una excepción de permisos. **Corrección:** ahora se devuelve «no encontrado» (404 en producción).

Mejora menor: el lanzador de procesos esperaba al menos 0,2 s por comando. Ahora comprueba cada 10 ms al principio, lo que ahorra unos 0,4 s por vídeo.

## Riesgos y hallazgos

- **Almacenamiento.** La copia sin sonido casi duplica el espacio de cada vídeo (545 MB de origen + 535 MB sin sonido + 18 MB de WAV). El WAV ocupa unos 110 MB por hora.
- **Disco temporal.** Durante el procesado se copia el vídeo a `$CFG->tempdir`, así que hace falta un espacio libre de unas 2 veces el vídeo más grande.
- **Límites de subida.** La imagen por defecto de PHP permite 200 MB. Hubo que subir `upload_max_filesize` y `post_max_size` (aquí a 2 GB) y revisar también el límite de tamaño del curso y del sitio. En producción, además, hay que ajustar los límites de nginx o Apache y los tiempos de espera del proxy.
- **FFmpeg en el servidor.** Es la única dependencia nueva (`apt install ffmpeg`, unos 100 MB de paquetes). En hostings gestionados sin acceso a paquetes, la alternativa es un microservicio aparte.
- **Reproducción en el navegador.** HEVC y MKV se separan bien, pero no todos los navegadores los reproducen. Esto afecta al reproductor, no a la transcripción.
- **Cron.** Sin cron no se procesa nada. La cola de tareas de Moodle funciona bien: las tareas duplicadas no repiten el trabajo y las tareas de actividades ya borradas se descartan sin error.
- **Rutas con caracteres especiales.** El plugin pasa los argumentos como lista a `proc_open`, sin shell, y funciona con espacios y tildes. `escapeshellarg()` elimina las tildes cuando la configuración de idioma es `C`, así que no hay que usarlo en código nuevo.

## Segunda ronda: guardar solo el audio (decidido)

La copia sin sonido se ha **desactivado por defecto** (ajuste `extractvideo = 0`), porque para transcribir solo hace falta el audio. Se ha repetido la prueba completa en modo «solo audio» (`bench/e2e-audio-only.txt`): **24/24 comprobaciones**. Se incluye un caso nuevo: si la opción estaba activada y se desactiva, al reprocesar se borra la copia sin sonido que quedaba.

| Vídeo de 545 MB (10 min) | Audio + copia sin sonido | Solo audio |
|---|---|---|
| Tiempo de procesado en Moodle | 14,6 s | **3,0 s** |
| Espacio total (origen + resultados) | 1.098 MB | **563 MB** (+3 %) |

## Decisiones abiertas

1. **¿FFmpeg en el servidor de Moodle o en un microservicio?** Si podemos instalar paquetes en el servidor, la versión actual basta. Si no, o si la transcripción con Whisper va a ir en otra máquina, conviene que un mismo servicio haga las dos cosas.
2. **¿Guardar el WAV o un formato comprimido?** Whisper acepta también FLAC y Opus. FLAC ocupa aproximadamente la mitad sin perder calidad. Conviene decidirlo al diseñar la fase de transcripción.

## Cómo reproducirlo

```sh
cd spike
docker compose up -d --build
docker compose exec webserver sh /bench/sync_code.sh        # copia del código de Moodle al volumen (~8 min la primera vez)
docker compose exec -u www-data webserver php admin/cli/install_database.php --agree-license \
  --fullname="Spike VideoAI" --shortname=spike --adminuser=admin --adminpass='Admin1234!' --adminemail=admin@example.com
docker compose exec webserver sh /bench/make_videos.sh      # vídeos sintéticos en /tmp/videos
docker compose exec webserver php /bench/bench.php          # rendimiento
docker compose exec -u www-data webserver php /bench/e2e.php
```

Sitio: <http://localhost:8000> (admin / `Admin1234!`). Para borrarlo todo: `docker compose down -v`.

El entorno usa el código de `C:\dev\moodle-dev\moodle` en **solo lectura** y no lo modifica. El `config.php` y el plugin se montan solo dentro del contenedor. Ejecutar Moodle directamente desde una carpeta de Windows montada es unas 10 veces más lento (más de una hora de instalación frente a 3 minutos), por eso se copia a un volumen.

## Tercera ronda: vídeos con voz real y sintética

Conjunto de 12 vídeos en español (unos 51 minutos) en `samples/` (ver `samples/SOURCES.md`):
- 5 generados con Piper TTS, con transcripción exacta: clase normal, diálogo a dos voces, silencio y música, términos técnicos con acento mexicano, y ruido de fondo.
- 7 de Wikimedia Commons (dominio público, CC0 o CC BY-SA): documentales, animación infantil, Wikipedia hablada y poesía, con acentos venezolano, colombiano y canario.

Los 12 se han subido como actividades y procesado con el plugin en modo «solo audio» (`bench/samples-results.txt`): **12/12 correctos**, 51,5 min de audio separados en 15,1 s en una sola ejecución de la cola. Los WAV exportados (`/samples/audio/*.wav`) y las referencias (`/samples/reference/*.txt`) son la entrada para comparar las librerías de transcripción.

## Cuarta ronda: comparativa de transcripción

9 configuraciones de 7 librerías sobre los WAV del plugin (detalle en `asr/RESULTADOS.md`).
**Recomendación: faster-whisper por lotes (`large-v3-turbo` int8 + VAD):** 2,1 % de error en clases sintéticas, 7,4 % en voz real, 0 palabras inventadas, RTF 0,43 (1 h de clase en ~26 min con 8 hilos sin GPU) y licencia MIT.
Alternativas: WhisperX (el más preciso, tiempos por palabra, pero 4,6 GB de imagen) y Parakeet v3 (el más rápido y ligero de los precisos).
Hallazgo clave: sin VAD, Whisper se inventa «Gracias por ver el video.» en los silencios.

## Quinta ronda: transcripción integrada en el plugin (v0.2.0)

- Servicio `transcriber/` (FastAPI + faster-whisper por lotes, turbo int8, VAD) y tarea `transcribe_audio` en el plugin.
  La transcripción se guarda frase a frase con tiempos (`videoai_segments`) y en `.vtt`/`.json`. La IA la lee con el
  servicio web `mod_videoai_get_transcript` (probado por REST con token, `bench/ws_rest.sh`).
- `bench/transcribe_e2e.php`: **22/22 comprobaciones**. Incluye cadena separación → transcripción, idempotencia, reprocesado
  sin repetir la transcripción, servicio caído con reintento automático, clave errónea sin reintento, transcripción
  desactivada, silencio y música, vídeo sin audio, permisos del servicio web y borrado.
- PHPUnit: 20 tests, 69 comprobaciones. Actualización 0.1 → 0.2 probada sobre el sitio existente.
- Dos problemas encontrados y resueltos en el servicio:
  1. El modo por lotes devuelve fragmentos de ~30 s. Se recortan en frases con los tiempos por palabra (+4 % de tiempo).
  2. Su VAD une la voz de antes y de después de una pausa larga y descoloca los tiempos: una frase dicha en el segundo 71
     aparecía en el 5. El servicio calcula sus propios tramos y no cruza pausas de más de 1 s.

## Sexta ronda: transcripción con FFmpeg 8 (filtro `whisper`), motor por defecto (v0.3.0)

FFmpeg 8.1.3 compilado con `--enable-whisper` (whisper.cpp v1.9.4) en la imagen de Moodle (`spike/Dockerfile`). El plugin
usa el mismo ffmpeg para separar y transcribir, sin servicios aparte. Resultados en las 7 muestras (`asr/results-table-ffmpeg.txt`):

| Configuración | Error sintéticos | Error voz real | Palabras inventadas | RTF | RAM |
|---|---|---|---|---|---|
| **FFmpeg, plugin** (trozos de 25 s, corte en pausas de 2 s, filtro de frases inventadas) | 3,3 % | 7,1 % | 0 | 0,96 | 0,75 GB |
| FFmpeg, trozos de 30 s + VAD | 4,9 % | 9,8 % | 6 | 0,91 | 0,83 GB |
| FFmpeg, trozos de 30 s sin VAD | 4,5 % | 10,6 % | 5 | 1,15 | 0,83 GB |
| faster-whisper por lotes (servicio) | 2,1 % | 7,4 % | 0 | 0,43 | 3,7 GB |

Ajustes decisivos, todos medidos:
1. `-filter_threads`: sin él, el filtro usa pocos hilos (342 s frente a 108 s con 8 hilos para 57 s de audio).
2. `vad_min_silence_duration=2`: con 0,5 s corta en muchos trozos y cada uno cuesta una ventana entera de Whisper (108 s → 44 s).
3. `queue=25`: con 30 s, que es una ventana completa de Whisper, perdía el resto de un trozo tras la primera frase
   (el comienzo del artículo de Dolores Cacuango desaparecía).
4. El detector de voz del filtro deja pasar la música y Whisper escribe «Gracias por ver el video». El plugin descarta los
   fragmentos formados solo por esas frases típicas.

Pruebas: `bench/transcribe_e2e.php` con `ENGINE=ffmpeg`: 18/19 comprobaciones. PHPUnit: 24 tests. Rutas con `: , [` probadas.
**Fallo conocido (no corregible desde el plugin):** tras 65 s de silencio y música, «Muy bien, ya ha pasado el tiempo.»
aparece en el segundo 55 en lugar del 70: el tiempo puede adelantarse hasta un trozo tras música. Tampoco se puede
evitar que, si el corte fijo cae en mitad de una frase, Whisper complete esa frase mal.
