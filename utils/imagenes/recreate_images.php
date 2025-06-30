<?php
echo '<pre>';print_r('Este proceso es sensible.');echo '</pre>';
echo '<pre>';print_r('Antes de lanzarlo, saca una copia de /media y de base de datos.');echo '</pre>';
echo '<pre>';print_r('Antes de lanzarlo, configura también todos los campos de tipo FILE para que tengan una configuración correcta');echo '</pre>';
echo '<pre>';print_r('El proceso recorrerá todos los campos de tipo imagen para regenerar su miniatura. En base a la configuración que se ponga para cada campo, regenerará y eliminará los originales.');echo '</pre>';
die('Error forzado. Comentar esta línea para poder ejecutar');
require_once(__DIR__ . '/../api/ApiController.php');
require_once(__DIR__ . '/../api/EntityLib.php');

// Quitamos límites a peticiones
ini_set('memory_limit', -1);
if(!set_time_limit(-1))
    die('No se puede cambiar');
// Registramos hora inicio
$time_in = gettimeofday();

$apiController = new ApiController();
$apiController->beginTransaction();
$dbConn = $apiController->getConnection();
$imgFiles = array('png', 'jpg', 'jpeg', 'bmp');
$execute = (!empty($_GET) && !empty($_GET['execute'])) ? true : false;
$debug = (!empty($_GET) && !empty($_GET['debug'])) ? true : false;

try {
    //$apiController->commitTransaction();
    $sql = "SELECT f.id,f.field,f.table_id,t.`table`,f.`file_options` as file_options FROM __schema_fields f ".
        "LEFT JOIN __schema_tables t ON (t.id = f.table_id) ".
        "WHERE f.deleted = 0 AND t.deleted = 0 AND f.type = 'file'";
    $items = $dbConn->querySelectAll($sql);
    $imagenes = array();
    if (!empty($items)) {
        foreach ($items as $i) {
            $file_options = !empty($i['file_options']) ? json_decode($i['file_options'],true,JSON_UNESCAPED_UNICODE) : array();
            $imagenes[] = array('table' => $i['table'], 'field' => $i['field'],'file_options' => $file_options);
        }
    }

    $peso_total_sources = 0;
    $peso_total_optimizados = 0;
    $peso_total_optimizados2 = 0;

    $contador = 0;
    $resumen_procesar = array();
    $sqls = array();
    $files_to_unlink = array();
    if (!empty($imagenes)) {
        foreach ($imagenes as $imgtable) {
            $file_options = !empty($imgtable['file_options']) ? $imgtable['file_options'] : array();
            $is_image = (!empty($file_options) && !empty($file_options['type'])) && $file_options['type'] === 'image';
            $optimize_options = !empty($file_options['optimize']) ? $file_options['optimize'] : array();
            if($debug)
            {
                echo '<pre>';print_r('Procesando '.$imgtable['table'].' - '.$imgtable['field']);echo '</pre>';
                echo '<pre>';print_r($optimize_options);echo '</pre>';
            }
            if($is_image) {
                $sql = "SELECT id,`" . $imgtable['field'] . "` FROM `" . $imgtable['table'] . "` WHERE deleted = 0 AND `" . $imgtable['field'] . "` IS NOT NULL";
                try {
                    $imagenesTabla = $dbConn->querySelectAll($sql);
                    if (!empty($imagenesTabla)) {
                        foreach ($imagenesTabla as $it) {
                            $contador++;
                            if(!empty($it[$imgtable['field']])) {
                                $filedata = json_decode($it[$imgtable['field']], JSON_UNESCAPED_UNICODE);
                                //echo '<pre>';print_r('####'.$contador.': '.$filedata['path']);echo '</pre>';
                                $fileext = !empty($filedata['ext']) ? $filedata['ext'] : '';
                                if (in_array(strtolower($fileext), $imgFiles)) {
                                    $original_filepath = $filedata['path'];
                                    $filepath = !empty($filedata['path']) ? __DIR__ . '/../' . $filedata['path'] : '';
                                    if (file_exists($filepath)) {
                                        $previous_optimized_file = $filedata['path'];
                                        $previous_optimized_webp = $filedata['path'];
                                        $previous_optimized_file = str_replace('.'.$filedata['ext'],'_o.'.$filedata['ext'],$previous_optimized_file);
                                        $previous_optimized_webp = str_replace('.'.$filedata['ext'],'_o.webp',$previous_optimized_webp);
                                        $previous_optimized_path = __DIR__.'/../'.$previous_optimized_file;
                                        $previous_webp_path = __DIR__.'/../'.$previous_optimized_webp;
                                        if($previous_optimized_file != $filedata['path'] && file_exists($previous_optimized_path))
                                        {
                                            //echo '<pre>';print_r('Borrar OPT PREVIO: '.$previous_optimized_path);echo '</pre>';
                                            unlink($previous_optimized_path);
                                        }
                                        if($previous_optimized_webp != $filedata['path'] && file_exists($previous_webp_path))
                                        {
                                            //echo '<pre>';print_r('Borrar WEBP PREVIO: '.$previous_webp_path);echo '</pre>';
                                            unlink($previous_webp_path);
                                        }
                                        $optimize_file = !empty($optimize_options);
                                        if($optimize_file) {
                                            $optimized_file = EntityLib::createOptimizedFileWithOptions($filedata,$optimize_options);
                                            //echo '<pre>';print_r('#'.$contador.' - Procesando IMG: '.$filepath);echo '</pre>';
                                            $optimized_public_path = $optimized_file['relative'];
                                            $optimized_full_path = $optimized_file['absolute'];
                                            $filedata['path'] = $optimized_public_path;
                                            $filedata['size'] = filesize($optimized_full_path);
                                            if(!empty($optimize_options['webp']))
                                            {
                                                $filedata['name'] = str_replace('.'.$filedata['ext'],'.webp',$filedata['name']);
                                                $filedata['ext'] = 'webp';
                                            }

                                            if(array_key_exists('keep',$optimize_options) && empty($optimize_options['keep']))
                                            {
                                                //echo '<pre>';print_r('Buscando imagen original para borrarla del disco duro');echo '</pre>';
                                                $original_filepath = __DIR__.'/../'.$original_filepath;
                                                //echo '<pre>';print_r($original_filepath);echo '</pre>';
                                                if($original_filepath !== $filedata['path'] && file_exists($original_filepath))
                                                {
                                                    //echo '<pre>';print_r('#'.$contador.' - Borrar ORIGINAL: '.$original_filepath);echo '</pre>';
                                                    $files_to_unlink[] = $original_filepath;
                                                }
                                            }

                                            $json_value = json_encode($filedata,JSON_UNESCAPED_UNICODE);
                                            $update_sql = EntityLib::generateSql($imgtable['table'],'UPDATE',array($imgtable['field'] => $json_value),array('id' => $it['id']));
                                            echo '<pre>';print_r($update_sql);echo '</pre>';
                                            $dbConn->query($update_sql);
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    //echo '<pre>';print_r('Se salta la combinación ' . $imgtable['table'] . ' - ' . $imgtable['field']);echo '</pre>';
                    //echo '<pre>';print_r('Motivo: ' . $e->getMessage());echo '</pre>';
                }
            }
            else {
                if($debug)
                {
                    echo '<pre>';print_r('Se salta la combinación ' . $imgtable['table'] . ' - ' . $imgtable['field'].' por no ser campo imagen');echo '</pre>';
                }
            }
        }
    }
    //throw new Exception('Se fuerza la salida para NO guardar los cambios en BDD.');
    $apiController->commitTransaction();
    if(!empty($files_to_unlink))
    {
        echo '<pre>';print_r('Borrando '.count($files_to_unlink).' imágenes');echo '</pre>';
        foreach($files_to_unlink as $ftu)
            unlink($ftu);
    }
} catch (Exception $e) {
    $apiController->rollbackTransaction();
    echo '<pre>';
    print_r('ERROR! ' . $e->getMessage());
    echo '</pre>';
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

?>