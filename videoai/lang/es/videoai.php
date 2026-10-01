<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Spanish strings for mod_videoai.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['audiofile'] = 'Pista de audio';
$string['channels'] = 'Canales de audio';
$string['channels_desc'] = 'Se recomienda mono para la transcripción.';
$string['channelsmono'] = 'Mono';
$string['channelsstereo'] = 'Estéreo';
$string['duration'] = 'Duración';
$string['engine'] = 'Motor de transcripción';
$string['engine_desc'] = 'FFmpeg ejecuta Whisper en este servidor con el mismo ffmpeg que separa el audio (FFmpeg 8 o posterior compilado con --enable-whisper). El servicio externo (carpeta transcriber/) es más rápido y puede ir en su propio servidor con GPU.';
$string['engineffmpeg'] = 'FFmpeg (filtro whisper, en este servidor)';
$string['engineservice'] = 'Servicio externo (faster-whisper)';
$string['errorffmpegfailed'] = 'ffmpeg no ha podido procesar el vídeo.';
$string['errorffmpegnotconfigured'] = 'Las rutas de ffmpeg y ffprobe no están configuradas o no son ejecutables. Revisa los ajustes del plugin Transcriptor de vídeo.';
$string['errorffmpegtimeout'] = 'ffmpeg no ha terminado en {$a} segundos.';
$string['errorlocked'] = 'Este vídeo ya se está procesando. La tarea se reintentará.';
$string['errornoaudio'] = 'El vídeo no tiene pista de audio.';
$string['errornowhisperfilter'] = 'El ffmpeg configurado no tiene el filtro Whisper. Hace falta FFmpeg 8 o posterior compilado con --enable-whisper.';
$string['errorprobe'] = 'ffprobe ha devuelto información ilegible sobre el vídeo.';
$string['errortranscriberrejected'] = 'El servicio de transcripción ha rechazado la petición (HTTP {$a}).';
$string['errortranscriberresponse'] = 'El servicio de transcripción ha devuelto una respuesta inesperada.';
$string['errortranscriberunavailable'] = 'El servicio de transcripción en {$a} no está disponible. La transcripción se reintentará automáticamente.';
$string['errorwhispermodel'] = 'El archivo de modelo de Whisper {$a} no existe o no se puede leer.';
$string['extractvideo'] = 'Crear pista de vídeo sin sonido';
$string['extractvideo_desc'] = 'Además del audio, guarda una copia del vídeo sin sonido. No hace falta para la transcripción y casi duplica el espacio que ocupa cada vídeo.';
$string['language'] = 'Idioma';
$string['language_desc'] = 'Código de idioma de los vídeos (p. ej. es, en). Déjalo vacío para detectarlo en cada vídeo.';
$string['model'] = 'Modelo';
$string['modulename'] = 'Transcriptor de vídeo';
$string['modulename_help'] = 'Sube un vídeo. Su audio se separa automáticamente para poder transcribirlo y responder preguntas sobre el vídeo.';
$string['modulenameplural'] = 'Actividades Transcriptor de vídeo';
$string['pathtoffmpeg'] = 'Ruta a ffmpeg';
$string['pathtoffmpeg_desc'] = 'Ruta completa del ejecutable ffmpeg en el servidor, p. ej. /usr/bin/ffmpeg o C:\\ffmpeg\\bin\\ffmpeg.exe.';
$string['pathtoffprobe'] = 'Ruta a ffprobe';
$string['pathtoffprobe_desc'] = 'Ruta completa del ejecutable ffprobe, normalmente junto a ffmpeg.';
$string['pluginadministration'] = 'Administración del Transcriptor de vídeo';
$string['pluginname'] = 'Transcriptor de vídeo';
$string['privacy:metadata'] = 'La actividad Transcriptor de vídeo no almacena datos personales.';
$string['reprocess'] = 'Volver a procesar';
$string['reprocessqueued'] = 'El vídeo se ha puesto en cola para procesarse.';
$string['retranscribe'] = 'Volver a transcribir';
$string['retranscribequeued'] = 'El audio se ha puesto en cola para transcribirse.';
$string['samplerate'] = 'Frecuencia de muestreo';
$string['samplerate_desc'] = 'Los modelos de transcripción como Whisper esperan 16000 Hz.';
$string['separation'] = 'Separación de audio y vídeo';
$string['statusdone'] = 'Procesado';
$string['statuserror'] = 'Error';
$string['statusnovideo'] = 'No hay vídeo subido';
$string['statusprocessing'] = 'Procesando';
$string['statusqueued'] = 'En cola';
$string['taskprocessvideo'] = 'Separar audio y vídeo';
$string['tasktranscribeaudio'] = 'Transcribir audio';
$string['timeout'] = 'Tiempo máximo de procesado';
$string['timeout_desc'] = 'Se detiene ffmpeg si un paso tarda más que esto.';
$string['timeprocessed'] = 'Procesado el';
$string['timetranscribed'] = 'Transcrito el';
$string['transcribe'] = 'Transcribir el audio';
$string['transcribe_desc'] = 'Después de separar el audio, enviarlo al servicio de transcripción.';
$string['transcriberkey'] = 'Clave del servicio de transcripción';
$string['transcriberkey_desc'] = 'Se envía como token Bearer. Debe coincidir con API_KEY del servicio; déjala vacía si el servicio no tiene.';
$string['transcribertimeout'] = 'Tiempo máximo de transcripción';
$string['transcribertimeout_desc'] = 'Cuánto esperar a una transcripción. En CPU el servicio necesita aproximadamente la mitad de la duración del vídeo.';
$string['transcriberurl'] = 'URL del servicio de transcripción';
$string['transcriberurl_desc'] = 'URL base del servicio de transcripción (carpeta transcriber/ del repositorio de este plugin), p. ej. http://transcriber:8000.';
$string['transcript'] = 'Transcripción';
$string['transcriptdownload'] = 'Descargar: {$a}';
$string['transcriptempty'] = 'No se ha encontrado voz en el audio.';
$string['transcription'] = 'Transcripción';
$string['transcription_desc'] = 'Voz a texto con Whisper, mediante FFmpeg en este servidor o mediante el servicio externo de transcripción.';
$string['transcriptnone'] = 'Sin transcribir';
$string['videoai:addinstance'] = 'Añadir una actividad Transcriptor de vídeo';
$string['videoai:process'] = 'Ver y relanzar la separación de audio y vídeo';
$string['videoai:view'] = 'Ver la actividad Transcriptor de vídeo';
$string['videoai:viewtranscript'] = 'Ver la transcripción de una actividad Transcriptor de vídeo';
$string['videofile'] = 'Vídeo';
$string['videofile_help'] = 'El archivo de vídeo de la actividad. Al guardar, el audio se extrae en segundo plano; en vídeos largos puede tardar unos minutos.';
$string['videoonlyfile'] = 'Pista de vídeo (sin sonido)';
$string['whispermodel'] = 'Modelo de Whisper (FFmpeg)';
$string['whispermodel_desc'] = 'Ruta a un modelo de whisper.cpp. Recomendado: ggml-large-v3-turbo-q5_0.bin (550 MB) de huggingface.co/ggerganov/whisper.cpp.';
$string['whisperqueue'] = 'Duración de cada trozo de audio (FFmpeg)';
$string['whisperqueue_desc'] = 'Segundos de audio que recibe Whisper cada vez. Trozos más largos le dan más contexto; los 3 s por defecto de FFmpeg pierden precisión.';
$string['whisperthreads'] = 'Hilos de CPU (FFmpeg)';
$string['whisperthreads_desc'] = 'Hilos para la transcripción; 0 usa todos los núcleos. Bájalo si las transcripciones ralentizan el servidor de Moodle.';
$string['whispervadmodel'] = 'Modelo de detección de voz (FFmpeg)';
$string['whispervadmodel_desc'] = 'Ruta a ggml-silero-v5.1.2.bin (de huggingface.co/ggml-org/whisper-vad). Muy recomendable: sin él Whisper se inventa texto en silencios y música.';
$string['whispervadsilence'] = 'Pausa mínima para cortar (FFmpeg)';
$string['whispervadsilence_desc'] = 'Segundos de silencio a partir de los cuales el audio se corta en un trozo nuevo. Cada trozo cuesta una pasada completa de Whisper, así que pausas muy cortas (0,5 s por defecto en FFmpeg) hacen la transcripción mucho más lenta.';
