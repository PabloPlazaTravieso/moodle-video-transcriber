# Servicio de transcripción de `mod_videoai`

Servicio HTTP pequeño que transcribe el audio que separa el plugin de Moodle. Usa **faster-whisper** con el modelo
`large-v3-turbo` en int8, **procesamiento por lotes** y **filtro de silencios (VAD)**, que fue la opción ganadora de la
comparativa del spike (`spike/asr/RESULTADOS.md`): unos 2 % de palabras erróneas en español, ningún texto inventado en
silencios, y aproximadamente la mitad de la duración del audio en tiempo de proceso con 8 hilos de CPU.

## API

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/health` | Modelo cargado y si está ocupado. |
| `POST` | `/transcribe` | Multipart: `file` (audio, cualquier formato que lea FFmpeg) y opcionalmente `language` (`es`, `en`…; vacío = detectar). |

Cabecera `Authorization: Bearer <API_KEY>` si se ha definido `API_KEY`.

Respuesta:

```json
{
  "language": "es",
  "duration": 86.47,
  "text": "Buenos días a todos. Hoy vamos a hablar de la fotosíntesis…",
  "segments": [
    {"start": 0.0, "end": 0.94, "text": "Buenos días a todos."},
    {"start": 1.14, "end": 6.42, "text": "Hoy vamos a hablar de la fotosíntesis, uno de los procesos más importantes…"}
  ],
  "model": "faster-whisper 1.2.1 large-v3-turbo int8",
  "processing_seconds": 48.4
}
```

Cada segmento es **una frase** (como máximo 20 s), con su tiempo real en el vídeo.

Errores: `401` clave incorrecta, `413` archivo demasiado grande, `422` audio ilegible (el plugin no reintenta).
Si el servicio está caído, tarda demasiado o responde `5xx`, el plugin guarda el error y **reintenta automáticamente**.

## Arranque

```sh
docker build -t videoai-transcriber .
docker run -d --name transcriber -p 8000:8000 -e API_KEY=cambia-esto -v transcriber-models:/models videoai-transcriber
```

La primera vez descarga el modelo (unos 1,6 GB) en el volumen `/models`.

En Moodle: *Administración del sitio → Plugins → Módulos de actividad → Transcriptor de vídeo → Transcripción*:
activar «Transcribir el audio», URL (p. ej. `http://transcriber:8000`) y la misma clave.

## Configuración (variables de entorno)

| Variable | Por defecto | Uso |
|---|---|---|
| `API_KEY` | vacío (sin autenticación) | Clave que debe enviar Moodle. **Defínela siempre fuera de desarrollo.** |
| `WHISPER_MODEL` | `large-v3-turbo` | `large-v3` es algo más preciso y más lento; `small` para máquinas muy modestas. |
| `WHISPER_DEVICE` / `WHISPER_COMPUTE_TYPE` | `cpu` / `int8` | En GPU NVIDIA: `cuda` / `float16` (necesita una imagen base con CUDA). |
| `WHISPER_THREADS` | `0` (todos) | Hilos de CPU. |
| `WHISPER_BATCH_SIZE` | `8` | Fragmentos que se decodifican a la vez. |
| `MAX_SEGMENT_SECONDS` | `20` | Duración máxima de un segmento aunque no termine la frase. |
| `MAX_GAP_SECONDS` | `1.0` | Pausa a partir de la cual se empieza un fragmento nuevo (ver abajo). |
| `MAX_UPLOAD_MB` | `1024` | Tamaño máximo del audio. |

## Decisiones que conviene conocer

- **Un solo trabajo a la vez.** Dos transcripciones simultáneas solo compiten por los mismos núcleos y duplican la
  memoria (unos 3,7 GB por proceso). Las peticiones siguientes esperan. Para más volumen, más contenedores detrás
  de un balanceador.
- **Tramos de voz propios.** El filtro de silencios propio del modo por lotes concatena la voz de antes y de después de una
  pausa larga en un único fragmento, y en el spike eso colocaba la primera palabra tras un silencio de 65 s en el
  segundo 5 en lugar del 71. El servicio calcula los tramos con el mismo Silero VAD, pero no los une a través de pausas
  de más de `MAX_GAP_SECONDS`.
- **Frases a partir de tiempos por palabra.** El modo por lotes devuelve fragmentos de unos 30 s. Con los tiempos de
  cada palabra se recortan en frases, para que la IA pueda citar el momento exacto. Coste: un 4 % más de tiempo.
- **`av<16`.** faster-whisper 1.2.1 no funciona con PyAV 16 o superior (argumento `metadata_errors`).
