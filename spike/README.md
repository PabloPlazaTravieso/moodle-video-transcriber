# Entorno local de pruebas (Docker)

Moodle 4.5 con los dos plugins (`mod_videoai` y `local_videotranscriber`), FFmpeg 8 con Whisper y un **curso de
demostración**, en tu propio equipo y con un solo comando.

## Requisitos

- **Docker Desktop** en marcha, con al menos 8 GB de memoria y unos 15 GB libres.
- **Git Bash** en Windows (viene con Git for Windows); en Linux o macOS vale cualquier terminal.

## Arrancar

```sh
git clone https://github.com/PabloPlazaTravieso/moodle-video-transcriber.git
cd moodle-video-transcriber/spike
./setup.sh
```

La primera vez tarda **entre 20 y 40 minutos**:
- compila FFmpeg 8 con Whisper;
- clona Moodle 4.5;
- descarga los modelos (575 MB) y el paquete de idioma español;
- genera los vídeos de demostración.

Las siguientes veces tarda segundos. Si algo falla a mitad (por ejemplo, la red), vuelve a ejecutar `./setup.sh`: continúa
donde se quedó.

Al terminar muestra la dirección: **<http://localhost:8000>**, usuario `admin`, contraseña `Admin1234!`.

## Qué probar en el curso «Demo: Transcriptor de vídeo con Pulse»

1. **«Clase grabada: fotosíntesis»** es una actividad Transcriptor de vídeo: a los pocos minutos muestra el audio separado y
   la transcripción con tiempos.
2. **Más → Transcripciones de vídeos** muestra los vídeos del curso (en un Archivo, una Página y una Carpeta), con
   «Pulse no está activo».
3. **«Activar ahora»** hace lo mismo que hará Pulse al activarse en el curso. En 3 o 4 minutos los tres vídeos aparecen como
   «Transcrito» y «Ver transcripción» muestra el texto con sus tiempos.
4. Añade un vídeo nuevo al curso (un recurso Archivo con un `.mp4`): aparece y se transcribe solo.

Un cron dentro del contenedor procesa la cola cada minuto, como en un Moodle real.

## Probar como Pulse (servicio web REST)

```sh
MSYS_NO_PATHCONV=1 bash bench/pulse_e2e.sh   # crea una cuenta «pulsebot» con token y prueba el disparador completo
```

O a mano, con un token del servicio:
- `local_videotranscriber_set_course_enabled`, al activarse Pulse en un curso;
- `local_videotranscriber_get_course_transcripts`, para leer las transcripciones.

La guía está en
[`local_videotranscriber/README.md`](../local_videotranscriber/README.md#para-el-equipo-de-pulse-integración).

## Ajustes opcionales (`spike/.env`)

| Variable | Por defecto | Uso |
|---|---|---|
| `MOODLE_PORT` | `8000` | Puerto de Moodle |
| `ADMIN_PASS` | `Admin1234!` | Contraseña del administrador, solo al instalar |
| `MOODLE_SRC` | vacío | Ruta a una copia de Moodle 4.5 que ya tengas, para no clonarla |
| `MODELS_VOLUME` | `videoai-whisper-models` | Volumen de Docker con los modelos |

## Parar, volver a arrancar y borrar

```sh
docker compose stop        # parar (se conserva todo)
./setup.sh                 # volver a arrancar (y el cron)
docker compose down -v     # borrar todo: Moodle, base de datos, modelos y vídeos
```

Opcional: el servicio de transcripción externo (motor «Servicio externo»), con `docker compose --profile service up -d transcriber`.

Las credenciales de este entorno (`Admin1234!`, `spike-secret`, `moodle/moodle`) son **solo para uso local**.

## Qué más hay en esta carpeta

- [`SPIKE.md`](SPIKE.md): la investigación, todas las rondas de pruebas y sus resultados.
- `asr/`: la comparativa de 7 librerías de transcripción.
- `bench/`: los scripts de prueba (formatos, separación, transcripción, disparador de Pulse, web) y sus resultados.
- `samples/`: los textos y el script que generan los vídeos de prueba.
