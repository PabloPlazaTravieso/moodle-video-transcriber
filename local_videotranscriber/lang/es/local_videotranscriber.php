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
 * Spanish strings for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['autoenable'] = 'Activar un curso cuando Pulse pide sus transcripciones';
$string['autoenable_desc'] = 'Para los cursos donde Pulse ya está activo: la primera vez que Pulse pide las transcripciones de un curso, el curso queda marcado como activo en Pulse y sus vídeos empiezan a transcribirse. La cuenta que usa Pulse necesita el permiso local/videotranscriber:manage.';
$string['categories'] = 'Categorías';
$string['categories_desc'] = 'Se procesan los cursos de estas categorías y de sus subcategorías cuando el alcance es «Categorías seleccionadas».';
$string['coursematerial'] = 'Página del curso (secciones y resumen)';
$string['coursereport'] = 'Transcripciones de vídeos';
$string['disablepulse'] = 'Desactivar';
$string['duration'] = 'Duración';
$string['enabled'] = 'Transcribir automáticamente los vídeos de los cursos';
$string['enabled_desc'] = 'Transcribe los vídeos que el profesorado ha añadido a los cursos del alcance. Con el alcance «Cursos donde Pulse está activo», la transcripción empieza en cuanto Pulse se activa en un curso, y los vídeos nuevos se detectan al cambiar las actividades; una ejecución nocturna recoge lo que se haya escapado.';
$string['enablepulse'] = 'Activar ahora';
$string['errorlocked'] = 'Este vídeo ya se está procesando. La tarea se reintentará.';
$string['maxduration'] = 'Duración máxima del vídeo (minutos)';
$string['maxduration_desc'] = 'Los vídeos más largos se omiten. 0 para no poner límite.';
$string['maxpertask'] = 'Vídeos encolados por ejecución';
$string['maxpertask_desc'] = 'Cuántos vídeos nuevos envía a transcribir cada ejecución nocturna; el resto espera a las noches siguientes. Con el motor FFmpeg, transcribir tarda aproximadamente lo que dura el vídeo con 8 núcleos.';
$string['nospeech'] = 'No se ha encontrado voz en este vídeo.';
$string['notinscope'] = 'La transcripción automática no está activa en este curso. Se siguen mostrando los vídeos ya transcritos.';
$string['novideos'] = 'Todavía no hay vídeos transcritos en este curso.';
$string['pluginname'] = 'Transcriptor de vídeo: vídeos de los cursos';
$string['pluginoff'] = 'La transcripción automática está desactivada en la administración del sitio, así que no se está transcribiendo ningún vídeo.';
$string['privacy:metadata'] = 'El plugin solo transcribe material del curso añadido por el profesorado y no almacena datos personales.';
$string['pulseactive'] = 'Pulse está activo en este curso desde el {$a->date} ({$a->source}): sus vídeos se transcriben automáticamente.';
$string['pulsedisabledmsg'] = 'Pulse desactivado en este curso: los vídeos nuevos no se transcribirán. Las transcripciones existentes se conservan.';
$string['pulseenabledmsg'] = 'Pulse activado en este curso: {$a} vídeo(s) enviados a transcribir.';
$string['pulseinactive'] = 'Pulse no está activo en este curso, así que sus vídeos no se transcriben.';
$string['scope'] = 'Cursos a procesar';
$string['scope_desc'] = 'En qué cursos se buscan vídeos. «Cursos donde Pulse está activo» lo decide Pulse: activa un curso con el servicio web local_videotranscriber_set_course_enabled (o, si se permite abajo, al pedir sus transcripciones).';
$string['scopeall'] = 'Todos los cursos';
$string['scopecategories'] = 'Categorías seleccionadas';
$string['scopepulse'] = 'Cursos donde Pulse está activo';
$string['settingsintro'] = 'Los vídeos se separan y transcriben con el motor configurado en los <a href="{$a}">ajustes de la actividad Transcriptor de vídeo</a> (FFmpeg o servicio externo, modelos, idioma). Solo se busca en el material del curso (Archivo, Carpeta, Página, Libro, Lección, Etiqueta, descripciones de actividades, secciones del curso, H5P, banco de contenidos), nunca en archivos subidos por el alumnado.';
$string['showtranscript'] = 'Ver transcripción';
$string['skippednoaudio'] = 'El vídeo no tiene pista de audio.';
$string['skippedtoolong'] = 'Supera el máximo de {$a} minutos.';
$string['sourcemanual'] = 'activado desde esta página';
$string['sourcerequest'] = 'activado al pedir Pulse las transcripciones';
$string['sourcewebservice'] = 'activado por Pulse';
$string['statusdone'] = 'Transcrito';
$string['statuserror'] = 'Error';
$string['statusnew'] = 'En espera';
$string['statusprocessing'] = 'Transcribiendo';
$string['statusqueued'] = 'En cola';
$string['statusskipped'] = 'Omitido';
$string['taskdiscover'] = 'Buscar y encolar vídeos de los cursos para transcribir';
$string['taskdiscovercourse'] = 'Buscar y encolar los vídeos de un curso';
$string['tasktranscribe'] = 'Transcribir un vídeo de un curso';
$string['timetranscribed'] = 'Transcrito el';
$string['transcriptof'] = 'Transcripción: {$a}';
$string['unregistered'] = 'Hay {$a} vídeo(s) en este curso que aún no se han detectado; se detectarán en unos minutos o, como tarde, en la próxima ejecución nocturna.';
$string['videotranscriber:manage'] = 'Activar o desactivar la transcripción automática de un curso (para la cuenta de Pulse)';
$string['videotranscriber:view'] = 'Ver las transcripciones de los vídeos del curso';
$string['where'] = 'Dónde';
