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
 * English strings for mod_videoai.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['audiofile'] = 'Audio track';
$string['channels'] = 'Audio channels';
$string['channels_desc'] = 'Mono is recommended for speech-to-text.';
$string['channelsmono'] = 'Mono';
$string['channelsstereo'] = 'Stereo';
$string['duration'] = 'Duration';
$string['engine'] = 'Transcription engine';
$string['engine_desc'] = 'FFmpeg runs Whisper on this server with the same ffmpeg used to separate the audio (FFmpeg 8 or later built with --enable-whisper). The external service (transcriber/ folder) is faster and can run on its own GPU server.';
$string['engineffmpeg'] = 'FFmpeg (whisper filter, on this server)';
$string['engineservice'] = 'External service (faster-whisper)';
$string['errorffmpegfailed'] = 'ffmpeg could not process the video.';
$string['errorffmpegnotconfigured'] = 'The paths to ffmpeg and ffprobe are not configured or not executable. Check the Video Transcriber plugin settings.';
$string['errorffmpegtimeout'] = 'ffmpeg did not finish within {$a} seconds.';
$string['errorlocked'] = 'This video is already being processed. The task will be retried.';
$string['errornoaudio'] = 'The video has no audio track.';
$string['errornowhisperfilter'] = 'The configured ffmpeg has no Whisper filter. It needs FFmpeg 8 or later built with --enable-whisper.';
$string['errorprobe'] = 'ffprobe returned unreadable information about the video.';
$string['errortranscriberrejected'] = 'The transcription service rejected the request (HTTP {$a}).';
$string['errortranscriberresponse'] = 'The transcription service returned an unexpected answer.';
$string['errortranscriberunavailable'] = 'The transcription service at {$a} is not available. The transcription will be retried automatically.';
$string['errorwhispermodel'] = 'The Whisper model file {$a} does not exist or cannot be read.';
$string['extractvideo'] = 'Create a silent video track';
$string['extractvideo_desc'] = 'Besides the audio, store a copy of the video without sound. Not needed for transcription and it roughly doubles the storage used by each video.';
$string['language'] = 'Language';
$string['language_desc'] = 'Language code of the videos (e.g. es, en). Leave empty to detect it for each video.';
$string['model'] = 'Model';
$string['modulename'] = 'Video Transcriber';
$string['modulename_help'] = 'Upload a class video. Its audio is separated and transcribed automatically, and the timestamped transcript is available to the course and to the Pulse assistant.';
$string['modulenameplural'] = 'Video Transcriber activities';
$string['pathtoffmpeg'] = 'Path to ffmpeg';
$string['pathtoffmpeg_desc'] = 'Full path to the ffmpeg executable on the server, e.g. /usr/bin/ffmpeg or C:\\ffmpeg\\bin\\ffmpeg.exe.';
$string['pathtoffprobe'] = 'Path to ffprobe';
$string['pathtoffprobe_desc'] = 'Full path to the ffprobe executable, usually next to ffmpeg.';
$string['pluginadministration'] = 'Video Transcriber administration';
$string['pluginname'] = 'Video Transcriber';
$string['privacy:metadata'] = 'The Video Transcriber activity does not store any personal data.';
$string['reprocess'] = 'Process again';
$string['reprocessconfirm'] = 'The audio will be separated from the video again and then transcribed. This can take a while for long videos. Continue?';
$string['reprocessqueued'] = 'The video has been queued for processing.';
$string['retranscribe'] = 'Transcribe again';
$string['retranscribeconfirm'] = 'The current transcript will be deleted and the audio transcribed again. This can take as long as the video. Continue?';
$string['retranscribequeued'] = 'The audio has been queued for transcription.';
$string['samplerate'] = 'Audio sample rate';
$string['samplerate_desc'] = 'Speech-to-text models such as Whisper expect 16000 Hz.';
$string['separation'] = 'Audio and video separation';
$string['statusdone'] = 'Processed';
$string['statuserror'] = 'Error';
$string['statusnovideo'] = 'No video uploaded';
$string['statusprocessing'] = 'Processing';
$string['statusqueued'] = 'Queued';
$string['taskprocessvideo'] = 'Separate audio and video';
$string['tasktranscribeaudio'] = 'Transcribe audio';
$string['timeout'] = 'Maximum processing time';
$string['timeout_desc'] = 'ffmpeg is stopped if a single step takes longer than this.';
$string['timeprocessed'] = 'Processed on';
$string['timetranscribed'] = 'Transcribed on';
$string['transcribe'] = 'Transcribe the audio';
$string['transcribe_desc'] = 'After separating the audio, send it to the transcription service.';
$string['transcriberkey'] = 'Transcription service API key';
$string['transcriberkey_desc'] = 'Sent as a Bearer token. Must match API_KEY in the service; leave empty if the service has none.';
$string['transcribertimeout'] = 'Transcription timeout';
$string['transcribertimeout_desc'] = 'How long to wait for one transcription. On CPU the service needs roughly half the length of the video.';
$string['transcriberurl'] = 'Transcription service URL';
$string['transcriberurl_desc'] = 'Base URL of the transcription service (transcriber/ folder of this plugin\'s repository), e.g. http://transcriber:8000.';
$string['transcript'] = 'Transcript';
$string['transcriptdownload'] = 'Download: {$a}';
$string['transcriptempty'] = 'No speech was found in the audio.';
$string['transcription'] = 'Transcription';
$string['transcription_desc'] = 'Speech-to-text with Whisper, either through FFmpeg on this server or through the external transcriber service.';
$string['transcriptnone'] = 'Not transcribed';
$string['videoai:addinstance'] = 'Add a new Video Transcriber activity';
$string['videoai:process'] = 'See and rerun the audio/video separation';
$string['videoai:view'] = 'View Video Transcriber activity';
$string['videoai:viewtranscript'] = 'View the transcript of a Video Transcriber activity';
$string['videofile'] = 'Video';
$string['videofile_help'] = 'The video file for this activity (required). After saving, its audio is extracted in the background; this can take a few minutes for long videos.';
$string['videorequired'] = 'Upload a video: the activity needs it to separate the audio and transcribe it.';
$string['videoonlyfile'] = 'Video track (no sound)';
$string['whispermodel'] = 'Whisper model (FFmpeg)';
$string['whispermodel_desc'] = 'Path to a whisper.cpp model file. Recommended: ggml-large-v3-turbo-q5_0.bin (550 MB) from huggingface.co/ggerganov/whisper.cpp.';
$string['whisperqueue'] = 'Audio chunk length (FFmpeg)';
$string['whisperqueue_desc'] = 'Seconds of audio Whisper receives at a time. Longer chunks give it more context; FFmpeg\'s default of 3 s loses accuracy.';
$string['whisperthreads'] = 'CPU threads (FFmpeg)';
$string['whisperthreads_desc'] = 'Threads for the transcription; 0 uses all cores. Lower it if transcriptions slow down the Moodle server.';
$string['whispervadmodel'] = 'Voice activity model (FFmpeg)';
$string['whispervadmodel_desc'] = 'Path to ggml-silero-v5.1.2.bin (from huggingface.co/ggml-org/whisper-vad). Strongly recommended: without it Whisper invents text over silence and music.';
$string['whispervadsilence'] = 'Minimum pause to cut (FFmpeg)';
$string['whispervadsilence_desc'] = 'Seconds of silence after which the audio is cut into a new piece. Each piece costs a full Whisper pass, so very short pauses (FFmpeg default 0.5 s) make transcription much slower.';
