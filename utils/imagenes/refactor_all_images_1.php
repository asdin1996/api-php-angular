<?php
echo '<pre>';print_r('Este proceso es sensible.');echo '</pre>';
echo '<pre>';print_r('Antes de lanzarlo, saca una copia de /media y de base de datos.');echo '</pre>';
echo '<pre>';print_r('El proceso recorrerá todos los campos de tipo FILE para moverlos de sitio.');echo '</pre>';
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

$ignore_tables = array('_buzon_todos');

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
            $sql = "SELECT `id`,`".$field."` FROM `".$table."` WHERE `".$field."` IS NOT NULL";
            debug($sql,2);
            $result_data = $dbConn->querySelectAll($sql);
            if(!empty($result_data))
            {
                $count = count($result_data);
                debug('- Se han encontrado '.$count.' elementos a comprobar',2);
                debug('------------------------------------',2);
                $aux_count = 0;
                foreach($result_data as $rd)
                {
                    $aux_count++;
                    $procesar = false;
                    $path = null;
                    $path_id = null;
                    $path_filename = null;
                    $full_path_filename = null;
                    $new_path_filename = null;
                    $new_path_folder = null;
                    $destiny_value = null;
                    $motivo_no_aplica = '';
                    $r_id = $rd['id'];
                    debug('-- Procesando elemento '.$aux_count.' de '.$count,2);
                    $txt_info = !empty($rd[$field]) ? $rd[$field] : null;
                    if(!empty($txt_info))
                    {
                        $json_info = json_decode($txt_info,JSON_UNESCAPED_UNICODE);
                        if(!empty($json_info) && !empty($json_info['path']))
                        {
                            $path = $json_info['path'];
                            debug($path,2);
                            $base_url = 'media/'.$table;
                            if(strpos($path,$base_url) === 0)
                            {
                                $aux_path = explode('/',$path);
                                if(count($aux_path) == 4)
                                {
                                    $path_id = ''.$aux_path[2];
                                    if(strlen($path_id) >= 2)
                                    {
                                        $aux_path_id = str_split($path_id);
                                        $new_path_id = '';
                                        foreach($aux_path_id as $pid)
                                        {
                                            if(!empty($new_path_id)) $new_path_id .= '/';
                                            $new_path_id .= $pid;
                                        }
                                        $path_filename = $aux_path[3];
                                        $full_path_filename = __DIR__.'/../'.$base_url.'/'.$path_id.'/'.$path_filename;
                                        $new_path_filename = __DIR__.'/../'.$base_url.'/'.$new_path_id.'/'.$path_filename;
                                        $new_path_folder = $base_url.'/'.$new_path_id;
                                        $destiny_value = $base_url.'/'.$new_path_id.'/'.$path_filename;
                                        if(file_exists($full_path_filename)) {
                                            $procesar = true;
                                        }
                                        else {
                                            $motivo_no_aplica = '[E5] No existe el fichero';
                                        }
                                    }
                                    else {
                                        $motivo_no_aplica = '[E4] El ID no es de más de un dígito';
                                    }
                                }
                                else
                                    $motivo_no_aplica = '[E3] El path no se puede dividir en 4 trozos (media/tabla/id/fichero)';
                            }
                            else {
                                $motivo_no_aplica = '[E2] El path no empieza por media/tabla';
                            }
                        }
                        else
                        {
                            $motivo_no_aplica = '[E1] El JSON no es válido: '.$txt_info;
                        }
                    }
                    else{
                        $motivo_no_aplica = '[E0] No hay valor de imagen';
                    }
                    if($procesar)
                    {
                        $procesar_elementos[] = array(
                            'table' => $table,
                            'id' => $r_id,
                            'field' => $field,
                            'act_value' => $path,
                            'new_value' => $destiny_value,
                            'act_path' => $full_path_filename,
                            'new_path' => $new_path_filename,
                            'act_json' => $txt_info,
                            'new_json' => str_replace("'","\'",str_replace($path,$destiny_value,$txt_info)),
                            'new_folder' => $new_path_folder,
                        );
                        //EntityLib::prepareFolder($new_path_folder);
                        //die('Prevenir con 1');
                    }
                    else
                    {
                        debug('- No aplica: '.$motivo_no_aplica,2);;
                    }

                }
            }
            else{
                debug('- No hay elementos para este campo',2);
            }
        }
    }

    if(!empty($procesar_elementos))
    {
        foreach($procesar_elementos as $pe)
        {
            $new_value = '';
            debug($pe,1);
            if($execute) {
                EntityLib::prepareFolder($pe['new_folder']);
                if(rename($pe['act_path'],$pe['new_path'])) {
                    $sql = "UPDATE `" . $pe['table'] . "` SET `" . $pe['field'] . "` = '" . $pe['new_json'] . "' WHERE id = " . $pe['id'];
                    debug($sql);
                    $dbConn->query($sql);
                }
            }
        }
        $apiController->commitTransaction();
    }
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

