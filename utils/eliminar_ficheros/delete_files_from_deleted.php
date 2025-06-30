<?php
echo '<pre>';print_r('Este proceso es sensible.');echo '</pre>';
echo '<pre>';print_r('Antes de lanzarlo, saca una copia de /media y de base de datos.');echo '</pre>';
echo '<pre>';print_r('El proceso recorrerá todos los campos de tipo FILE para buscar los que estén deleted y borrarlos del servidor.');echo '</pre>';
die('Prevenir ejecución. Comentar para ejecutar y volver a comentar esta línea');
require_once(__DIR__ . '/../api/ApiController.php');
require_once(__DIR__ . '/../api/EntityLib.php');

// Quitamos límites a peticiones
ini_set('memory_limit', -1);
if(!set_time_limit(-1))
    die('No se puede cambiar');
// Registramos hora inicio
$time_in = gettimeofday();

$execute = !empty($_GET['execute']) ? true : false;
$debug = !empty($_GET['debug']) ? intval($_GET['debug']) : 0;

$apiController = new ApiController();
$apiController->beginTransaction();
$dbConn = $apiController->getConnection();
$imgFiles = array('png', 'jpg', 'jpeg', 'bmp');

$ignore_tables = array('_buzon','_buzon_todos','importaciones');

try {
    $sql = "SELECT f.id,f.field,f.table_id,t.`table`,f.`file_options` as file_options FROM __schema_fields f ".
        "LEFT JOIN __schema_tables t ON (t.id = f.table_id) ".
        "WHERE f.deleted = 0 AND t.deleted = 0 AND f.type = 'file' AND (f.no_real = 0 OR f.no_real IS NULL) AND (f.calculado = 0 OR f.calculado IS NULL) AND (t.real_entity IS NULL OR t.real_entity = '')";
    debug($sql,2);
    $items = $dbConn->querySelectAll($sql);
    $imagenes = array();
    if (!empty($items)) {
        foreach ($items as $i) {
            if(!empty($i['table']) && !in_array($i['table'],$ignore_tables)) {
                $file_options = !empty($i['file_options']) ? json_decode($i['file_options'], true, JSON_UNESCAPED_UNICODE) : array();
                if(empty($imagenes[$i['table']])) $imagenes[$i['table']] = array();
                $imagenes[$i['table']][] = array('field' => $i['field'], 'file_options' => $file_options);
            }
        }
    }

    $procesar_elementos = array();
    foreach($imagenes as $table => $table_file_fields)
    {
        foreach($table_file_fields as $tff)
        {
            $field = $tff['field'];
            debug('------------------------------------',2);
            debug('Procesando tabla: '.$table.' - Campo: '.$field,2);
            $sql = "SELECT `id`,`".$field."` FROM `".$table."` WHERE `".$field."` IS NOT NULL AND deleted = 1";
            debug($sql,2);
            $result_data = $dbConn->querySelectAll($sql);
            if(!empty($result_data))
            {
                $count = count($result_data);
                debug('- Se han encontrado '.$count.' elementos eliminados con adjunto ',2);
                debug('------------------------------------',2);
                $aux_count = 0;
                foreach($result_data as $rd)
                {
                    $info_file = !empty($rd['file']) ? json_decode($rd['file'],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
                    if(!empty($info_file) && !empty($info_file['path'])) {
                        $full_path = __DIR__ . '/../' . $info_file['path'];
                        if(file_exists($full_path)) {
                            $procesar_elementos[] = array(
                                'source' => $table,
                                'id' => $rd['id'],
                                'path' => $full_path
                            );
                        }
                    }
                }
            }
            else{
                debug('- No hay elementos para este campo',2);
            }
        }
    }
    $total_eliminados = 0;
    foreach($procesar_elementos as $pe)
    {
        if(!empty($pe['path']))
        {
            echo '<pre>';print_r('Detectado fichero: '.$pe['path'].' que se podría eliminar');echo '</pre>';
            if($execute) {
                unlink($pe['path']);
            }
            $total_eliminados++;
        }
    }
    echo '<pre>';print_r('Fin del proceso. Se eliminan: '.$total_eliminados);echo '</pre>';die;

} catch (Exception $e) {
    $apiController->rollbackTransaction();
    echo '<pre>';print_r('ERROR! ' . $e->getMessage());echo '</pre>';
}

// Calculamos tiempo de ejecución
$time_out = gettimeofday();
$diff_seconds = $time_out['sec'] - $time_in['sec'];
$diff_miliseconds = $time_out['usec'] - $time_in['usec'];
if ($diff_miliseconds < 0) {
    $diff_seconds = $diff_seconds - 1;
    $diff_miliseconds = $diff_miliseconds * (-1);
}
$diff = floatval($diff_seconds . '.' . $diff_miliseconds);


echo '<pre>';
print_r('Proceso finalizado en ' . $diff . 's');
echo '</pre>';

function debug($msg,$min_debug = 0)
{
    global $debug;
    if($min_debug <= $debug)
    {
        echo '<pre>';print_r($msg);echo '</pre>';
    }
}

?>

