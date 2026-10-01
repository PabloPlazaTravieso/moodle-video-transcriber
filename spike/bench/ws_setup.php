<?php
// Enable REST web services and create a token that can call mod_videoai_get_transcript (spike only).
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
\core\session\manager::set_user(get_admin());
set_config('enablewebservices', 1);
set_config('webserviceprotocols', 'rest');
$service = $DB->get_record('external_services', ['shortname' => 'videoai_ai']);
if (!$service) {
    $service = (object) ['name' => 'IA de preguntas sobre vídeos', 'shortname' => 'videoai_ai', 'enabled' => 1,
        'restrictedusers' => 0, 'downloadfiles' => 0, 'uploadfiles' => 0, 'timecreated' => time(), 'timemodified' => time()];
    $service->id = $DB->insert_record('external_services', $service);
    $DB->insert_record('external_services_functions', ['externalserviceid' => $service->id,
        'functionname' => 'mod_videoai_get_transcript']);
}
echo \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, get_admin()->id, context_system::instance()), "\n";
