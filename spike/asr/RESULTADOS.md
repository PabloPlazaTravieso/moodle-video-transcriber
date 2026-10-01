# Comparativa de librerías de transcripción (spike `mod_videoai`)

**Fecha:** 30/09/2026 · **Entrada:** los WAV que genera el plugin (16 kHz, mono, PCM), exportados de Moodle con `bench/samples_e2e.php`.
**Máquina:** Intel Core 5 210H, Docker/WSL2 con 8 GB de RAM, **8 hilos para todas las librerías, sin GPU**. Idioma forzado a español en todas.

## Resultado

| # | Librería / modelo | Error sintéticos | Error voz real ¹ | Error con ruido | Error técnico (MX) ² | Palabras inventadas en silencio/música | RTF ³ | 1 h de clase tarda | RAM pico | Imagen Docker |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | **faster-whisper por lotes** (turbo int8, lotes de 8, VAD) | 2,1 % | 7,4 % | 0,4 % | 4,9 % | **0** | **0,43** | **~26 min** | 3,7 GB | 0,8 GB |
| 2 | **WhisperX** (turbo int8 + alineación wav2vec2) | **1,7 %** | **6,3 %** | 0,4 % | **4,4 %** | **0** | 0,58 | ~35 min | 4,4 GB | 4,6 GB |
| 3 | **Parakeet-TDT-0.6B-v3** (onnx-asr, int8, Silero VAD) | 2,6 % | 10,2 % | 0,4 % | 8,2 % | **0** | **0,35** | **~21 min** | 2,3 GB | 0,4 GB |
| 4 | Canary-1B-v2 (onnx-asr, int8, Silero VAD) | 2,9 % | 8,4 % | 1,6 % | 8,2 % | 0 | 0,83 | ~50 min | 3,3 GB | 0,4 GB |
| 5 | faster-whisper secuencial (turbo int8, VAD) | 3,9 % | 7,3 % | 0,4 % | 13,7 % | 0 | 0,80 | ~48 min | 2,1 GB | 0,8 GB |
| 6 | whisper.cpp (turbo q5_0) | 4,3 % | 6,8 % | 0,4 % | 7,1 % | 5 | 2,07 | ~2 h 4 min | **0,9 GB** | 0,9 GB |
| 7 | openai-whisper (turbo fp32) | 4,4 % | 7,6 % | 0,4 % | 7,7 % | 5 | 1,29 | ~1 h 18 min | 4,9 GB | 3,6 GB |
| 8 | faster-whisper secuencial **sin VAD** | 5,6 % | 7,1 % | 0,4 % | 13,7 % | 5 | 0,84 | ~50 min | 2,1 GB | 0,8 GB |
| 9 | Vosk (modelo pequeño es-0.42) | 11,3 % | 14,4 % | 10,5 % | 25,8 % | 1 | **0,12** | ~7 min | **0,3 GB** | 0,2 GB |

**Qué es el error:** porcentaje de palabras mal transcritas (WER) respecto a la transcripción de referencia, con el texto normalizado (minúsculas, sin puntuación, números escritos con letras). Más bajo es mejor.
**Qué es el RTF:** tiempo de proceso dividido entre la duración del audio. Menos de 1 significa más rápido que tiempo real.

¹ Referencia *aproximada*: el artículo de Wikipedia de la revisión leída. Quien lee añade unas 90 palabras que no están en el texto, y eso suma lo mismo a todas las librerías. Sirve para comparar entre ellas, no como valor absoluto.
² Penaliza por igual a todos los Whisper cuando escriben bien el formato: «servidor.js» o «3000» cuentan como error frente a «servidor punto js» o «tres mil». Parakeet y Canary aciertan «PostgreSQL»; los Whisper escriben «POST-3SQL».
³ El «1 h de clase tarda» es una extrapolación con este portátil y 8 hilos. Un servidor con más núcleos o con GPU irá bastante más rápido.

Audio evaluado: 16 minutos (5 clases sintéticas con texto exacto y 2 lecturas reales). Los resultados por archivo y los textos completos están en `results/<motor>/*.json`, y el detalle con `python score.py -v`.

## Conclusiones

1. **Todas las opciones basadas en Whisper turbo transcriben el español casi perfecto** en voz clara: 0,4–0,8 % de error en las clases, también con ruido de fondo. Lo que diferencia a las librerías es la velocidad, la memoria y si se inventan texto, no la calidad del modelo.
2. **El filtro de silencios (VAD) es imprescindible con Whisper.** Sin él, whisper.cpp, openai-whisper y faster-whisper escriben **«Gracias por ver el video.»** en un silencio de 45 s con música. Para una IA que responde preguntas, eso es información falsa. Con VAD, 0 palabras inventadas. Parakeet y Canary también dieron 0, con Silero VAD.
3. **Procesar por lotes duplica la velocidad** sin perder precisión. faster-whisper pasa de RTF 0,80 a 0,43, y es la razón de que WhisperX sea rápido.
4. **whisper.cpp es el más ligero en memoria, pero el más lento** en esta CPU con beam 5 (RTF 2). No compensa, salvo en servidores con muy poca RAM.
5. **Vosk no da la calidad necesaria:** 11–26 % de error y sin puntuación.
6. **Incidencia práctica:** faster-whisper 1.2.1 falla al abrir audio con PyAV ≥ 16. En el spike se evitó pasándole directamente las muestras del WAV. En producción hay que fijar `av<16` o hacer lo mismo.

## Recomendación

**faster-whisper con `BatchedInferencePipeline`, modelo `large-v3-turbo` en int8 y VAD.**

- Es el segundo más preciso, a muy poca distancia de WhisperX, no se inventa texto y es el más rápido de los Whisper (una hora de clase en unos 26 minutos en este portátil).
- Da marcas de tiempo por frase para que la IA pueda citar el minuto.
- Tiene licencia MIT, no necesita PyTorch y su imagen pesa 0,8 GB, frente a los 4,6 GB de WhisperX.
- Integración propuesta: un pequeño servicio en Python (o un script de terminal) al que la tarea en segundo plano del plugin envía el WAV y del que recibe un JSON `[{start, end, text}]`, que se guarda en Moodle para la IA.

Alternativas:
- **WhisperX**, si hacen falta tiempos **palabra a palabra** o, más adelante, **distinguir quién habla**. A cambio, más RAM y una imagen mucho más pesada.
- **Parakeet v3**, si el servidor es modesto o hay mucho volumen. Es el más rápido y ligero entre los precisos y no se inventa nada, pero comete algo más de errores con voz real. Su licencia es CC-BY-4.0 (hay que citar a NVIDIA).

**Descartadas:** openai-whisper (lento, 4,9 GB de RAM, se inventa texto), whisper.cpp (lento en esta CPU), Vosk (poca calidad) y cualquier configuración sin VAD.

## Límites de esta prueba

- Muestra pequeña (16 min). Diferencias de menos de 1–2 puntos no son concluyentes.
- 5 de las 7 muestras usan voz sintética, más limpia que una clase real. **El siguiente paso es repetirlo con 2 o 3 clases reales vuestras con transcripción revisada a mano**, y probar las 3 finalistas con vuestra IA: las mismas preguntas sobre cada transcripción.
- Los tiempos son de un portátil con 8 GB para Docker. Hay que medir en el servidor real, y con GPU todo sería de 5 a 20 veces más rápido.

## Cómo repetirlo

```sh
cd spike
docker compose -f asr/docker-compose.yml build
sh asr/run_all.sh ref                       # todas; o, por ejemplo: sh asr/run_all.sh ref faster-whisper-batched parakeet
sh asr/run_all.sh all faster-whisper-batched  # sobre los 12 audios, incluidos los que no tienen referencia
```

Modelos descargados en el volumen `videoai-asr_asrmodels` (unos 6,5 GB). Para borrarlo todo: `docker compose -f asr/docker-compose.yml down -v`.

## Añadido: FFmpeg 8 con el filtro `whisper` (motor por defecto del plugin)

| Librería / modelo | Error sintéticos | Error voz real | Error con ruido | Error técnico (MX) | Palabras inventadas | RTF | RAM pico |
|---|---|---|---|---|---|---|---|
| **FFmpeg 8.1.3 whisper** (turbo q5_0, trozos de 25 s, VAD con pausa de 2 s, filtro del plugin) | 3,3 % | 7,1 % | 2,8 % | 6,6 % | 0 | 0,96 | **0,75 GB** |

Es whisper.cpp con decodificación simple (greedy) y parámetros fijos. Queda en precisión junto a los mejores, tarda más o
menos lo que dura el audio y es el que menos memoria usa de los precisos. Todos los detalles y ajustes están en
`../SPIKE.md` (sexta ronda). Se reproduce con `bench/ffmpeg_whisper_bench.php` dentro del contenedor de Moodle.
