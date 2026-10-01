# Vídeos de prueba para separación y transcripción

Conjunto de 12 vídeos en español (unos 51 minutos de voz) para probar `mod_videoai` y comparar librerías de transcripción.
Los genera y descarga `make_samples.sh` dentro del volumen Docker `samplesdata`, sin guardar nada pesado en la carpeta del repositorio:

```sh
cd spike
docker compose --profile tools build tools
docker compose --profile tools run --rm tools sh samples/make_samples.sh   # vídeos en /samples, referencias en /samples/reference
docker compose exec -u www-data webserver php /bench/samples_e2e.php      # los sube a Moodle, los procesa y exporta /samples/audio/*.wav
```

En el sitio del spike están en el curso **Muestras de transcripción** (<http://localhost:8000/course/view.php?id=7>).

## Sintéticos (voz generada con Piper TTS)

Tienen **transcripción exacta** (el texto que se leyó), así que sirven para calcular el porcentaje de error (WER) con precisión.
Textos en `texts/`, escritos para este spike. Voces de [rhasspy/piper-voices](https://huggingface.co/rhasspy/piper-voices).

| Archivo | Duración | Qué prueba | Voz |
|---|---|---|---|
| `tts_01_clase_fotosintesis.mp4` | 1:26 | Clase normal, vocabulario de ciencias, una fórmula leída | es_ES `davefx` |
| `tts_02_tutoria_dos_voces.mp4` | 0:57 | Diálogo profesora / alumno, fechas y horas | es_ES `sharvard`, voces F y M |
| `tts_03_silencio_y_musica.mp4` | 1:17 | 45 s de silencio y 20 s de música en medio: detecta si el modelo **se inventa texto** | es_ES `davefx` |
| `tts_04_clase_programacion_mx.mp4` | 1:13 | Términos técnicos en inglés (API REST, JSON, npm), números y códigos HTTP | es_MX `claude` |
| `tts_05_clase_fotosintesis_ruido.mp4` | 1:26 | La clase 01 con ruido rosa de fondo (unos 16 dB por debajo de la voz) | es_ES `davefx` |

Limitación: la voz sintética es más limpia y regular que una persona real. Estos vídeos miden bien los casos límite, pero no sustituyen a clases reales.

## Voz real (Wikimedia Commons)

| Archivo | Duración | Contenido | Autor | Licencia | Referencia |
|---|---|---|---|---|---|
| `real_01_las_estrellas.webm` | 9:44 | Documental divulgativo (Worldnet TV, años 90) | USGov-BBB | Dominio público | — |
| `real_02_cuidemos_el_planeta.webm` | 14:57 | Documental divulgativo (Worldnet TV, años 90) | USGov-BBB | Dominio público | — |
| `real_03_mi_casa_es_una_burbuja.webm` | 1:11 | Animación infantil educativa (Venezuela) | 123TV Canal Infantil | Dominio público | — |
| `real_04_wikipedia_caracas.mp4` | 1:02 | Wikipedia hablada: introducción de «Caracas», acento venezolano | Wilfredor | CC0 | Aproximada: 1.er párrafo de la [revisión 128931323](https://es.wikipedia.org/w/index.php?oldid=128931323) |
| `real_05_poema_el_cuervo.mp4` | 4:54 | «El cuervo» de Poe (trad. Carlos Arturo Torres), leído por Libardomm, acento colombiano | Libardomm | CC BY-SA 3.0 | — |
| `real_06_acento_canario.mp4` | 1:20 | Párrafo del artículo «Canarias», acento canario | Cutrupe | CC BY-SA 3.0 | — |
| `real_07_wikipedia_dolores_cacuango.mp4` | 8:45 | Wikipedia hablada: «Dolores Cacuango» | Colaboradores de Wikipedia | CC BY-SA 4.0 | Aproximada: [revisión 130409470](https://es.wikipedia.org/w/index.php?oldid=130409470) |

Páginas de origen (`https://commons.wikimedia.org/wiki/File:` seguido del nombre):
`"Las_estrellas"_(Worldnet_TV)..webm`, `"Cuidemos_el_planeta_Tierra"_(Worldnet_TV)..webm`, `1.2.3TV_-_Mi_casa_es_una_burbuja.webm`,
`Caracas,_Spanish_Wikipedia_Article_Intro.wav`, `Edgar_Allan_Poe_-_El_Cuervo_Spanish.ogg`, `CanariasVoz1.ogg`, `Dolores_cacuango.ogg`.

Los archivos de solo audio (04–07) se han convertido en vídeo con un fondo de color, para que pasen por el plugin igual que un vídeo real.
Las referencias «aproximadas» son el texto del artículo en la revisión leída (`wiki_to_text.php` extrae solo párrafos y títulos).
Quien lee puede saltarse o añadir palabras, así que el WER de estos vídeos se debe comparar *entre librerías*, no leer como valor absoluto.

**Uso:** solo para pruebas internas. Los archivos CC BY-SA exigen citar al autor y compartir con la misma licencia si se redistribuyen.
