<?php

require_once(__DIR__ . '/../api/ApiController.php');
require_once(__DIR__ . '/../api/EntityLib.php');

// Quitamos límites a peticiones
ini_set('memory_limit', -1);
// Registramos hora inicio
$time_in = gettimeofday();

$apiController = new ApiController();
$apiController->beginTransaction();
$dbConn = $apiController->getConnection();
$imgFiles = array('png', 'jpg', 'jpeg', 'bmp');

try {
    //$apiController->commitTransaction();
    $sql = "SELECT f.id,f.field,f.table_id,t.`table` FROM __schema_fields f LEFT JOIN __schema_tables t ON (t.id = f.table_id) WHERE f.deleted = 0 AND t.deleted = 0 AND f.type = 'file' AND calculado = 0";
    $items = $dbConn->querySelectAll($sql);
    $imagenes = array();
    if (!empty($items)) {
        foreach ($items as $i) {
            $imagenes[] = array('table' => $i['table'], 'field' => $i['field']);
        }
        //$apiController->commitTransaction();
    }
    $resumen_procesar = array();
    if (!empty($imagenes)) {
        foreach ($imagenes as $imgtable) {
            //echo '<pre>';print_r($imgtable);echo '</pre>';
            $sql = "SELECT id,`" . $imgtable['field'] . "` FROM `" . $imgtable['table'] . "` WHERE deleted = 0 AND `" . $imgtable['field'] . "` IS NOT NULL";
            $imagenesTabla = $dbConn->querySelectAll($sql);
            if (!empty($imagenesTabla)) {
                foreach ($imagenesTabla as $it) {
                    $filedata = json_decode($it[$imgtable['field']], JSON_UNESCAPED_UNICODE);
                    $fileext = !empty($filedata['ext']) ? $filedata['ext'] : '';
                    if (in_array(strtolower($fileext), $imgFiles)) {
                        $filepath = !empty($filedata['path']) ? __DIR__ . '/../' . $filedata['path'] : '';
                        if (file_exists($filepath)) {
                            $finalfilepath = EntityLib::getOptimizedPathForFile($filedata);
                            if (!empty($finalfilepath) && !file_exists($finalfilepath)) {
                                $item = array(
                                    'id' => $it['id'],
                                    'table' => $imgtable['table'],
                                    'field' => $imgtable['field'],
                                    'img_source' => $filedata['path'],
                                    'img_size' => $filedata['size'],
                                    'img_path' => $filepath,
                                    'opt_source' => $finalfilepath
                                );
                                $resumen_procesar[] = $item;
                            }
                        }
                    }
                }
            }
        }
    }

    foreach ($resumen_procesar as $rp) {
        $image = new Imagick($rp['']);

        // Load the image
        $image = new Imagick($rp['img_path']);
        // Determine the current DPI of the image
        $resolution = $image->getImageResolution();
        // Check if the image has a DPI greater than 72
        if ($resolution['x'] > 72 || $resolution['y'] > 72) {
            // Set the DPI to 72
            $image->setImageResolution(72, 72);
            // Set the units of the image to pixels per inch
            $image->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
        }
        // Get the current width and height of the image
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        // Determine if the image needs to be scaled
        $needs_scaling = false;
        if ($width > 2560 || $height > 2560) {
            $needs_scaling = true;
        }
        // Scale the image if necessary
        if ($needs_scaling) {
            // Determine which dimension is larger, the width or height
            $is_landscape = $width > $height;
            // Calculate the new dimensions based on the largest dimension being 2560 pixels
            if ($is_landscape) {
                $new_width = 2560;
                $new_height = intval($height * ($new_width / $width));
            } else {
                $new_height = 2560;
                $new_width = intval($width * ($new_height / $height));
            }
            // Scale the image to the new dimensions
            $image->scaleImage($new_width, $new_height);
        }
        // Set the image quality to 72
        $image->setImageCompression(Imagick::COMPRESSION_JPEG);
        // Write the optimized JPEG image to a file
        $image->setImageCompressionQuality(72);
        $image->stripImage();

        $result = $image->writeImage($rp['opt_source']);

        /*
        // Create a new Imagick object to hold the WebP version of the image
        $webp_image = new Imagick();
        $webp_image->readImageBlob($image);
        // Optimize the WebP image
        $webp_image->setImageCompressionQuality(60);
        $webp_image->setFormat('webp');
        $webp_image->stripImage();
        // Write the optimized WebP image to a file
        $webp_image->writeImage('path/to/optimized_image.webp');
        */

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