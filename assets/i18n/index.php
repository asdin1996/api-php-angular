<?php 
    $files_to_search = array(
        'componentes' => __DIR__.'/componentes/'.$_GET['file'].'.json',
        'cliente' => __DIR__.'/cliente/'.$_GET['file'].'.json',
    );
    $result = array();
    $all_strings = array();
    foreach($files_to_search as $type => $f)
    {
        if(file_exists($f))
        {
            $json_string = file_get_contents($f);
            $json_object = json_decode($json_string,JSON_UNESCAPED_UNICODE);
            if(!empty($json_object))
                $all_strings = array_merge($all_strings,$json_object);
        }
            
    }
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: *");
    header("Access-Control-Allow-Methods: GET");
    header("content-type: application/json");
    echo json_encode($all_strings,JSON_UNESCAPED_UNICODE);
?>