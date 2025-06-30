<?php
require_once(__DIR__.'/../../api/ApiController.php');
$apiController = new ApiController();
$_SESSION['__db__'] = $apiController->getConnection();
$db = $_SESSION['__db__'];

$export_idiomas_query = "SELECT * FROM _idiomas WHERE deleted = 0 ORDER BY id ASC";
$export_idiomas_values = $db->querySelectAll($export_idiomas_query);

$export_i18n_query = "SELECT * FROM _i18n WHERE deleted = 0 AND origen NOT LIKE 'API_%' ORDER BY origen,idioma_id,id";
$export_i18n_values = $db->querySelectAll($export_i18n_query);
$traducciones = array();
$idiomas = array();

foreach($export_i18n_values as $eiv)
{
    $key = $eiv['tipo'].'#'.$eiv['origen'];
    if(empty($traducciones[$key]))
        $traducciones[$key] = array(
            'tipo' => $eiv['tipo'],
            'origen' => $eiv['origen'],
            'values' => array(),
        );
    $traducciones[$key]['values'][$eiv['idioma_id']] = array(
        'id' => $eiv['id'],
        'trad' => $eiv['traduccion']
    );
    if(!in_array($eiv['idioma_id'],$idiomas))
        $idiomas[] = $eiv['idioma_id'];
}

$idiomas_array = array();
foreach($idiomas as $l_id)
{
    $idioma_txt = '';
    foreach($export_idiomas_values as $eiv)
    {
        if($eiv['id'] == $l_id) {
            $idioma_txt = $eiv['iso'];
            break;
        }
    }
    $idiomas_array[$l_id] = $idioma_txt;
}

$print = "";
if(!empty($traducciones)) {
    $print .= "'Cadena';";
    $contador_idiomas = 0;
    foreach ($idiomas_array as $l_id => $l_value) {
        $contador_idiomas++;
        $print .= "'Id" . $contador_idiomas . "';'" . $l_value . "';";
    }
    $print .= "\n";
    foreach ($traducciones as $tr) {
        $cadena = $tr['origen'];
        $print .= "'".$cadena."';";
        foreach ($idiomas_array as $l_id => $l_value) {
            $id = '';
            $traduccion = '';
            if(!empty($tr['values'][$l_id]))
            {
                $id = $tr['values'][$l_id]['id'];
                $traduccion = $tr['values'][$l_id]['trad'];

            }
            $print .= "'".$id."';'".$traduccion."';";
        }
        $print .= "\n";
    }
    echo '<pre>';print_r($print);echo '</pre>';
}
echo '<pre>';print_r("--------------------------------------");echo '</pre>';

$add_if_not_translated = false;
$traducciones = array();
$export_values_query = "SELECT * FROM _menus WHERE deleted = 0";
$menus = $db->querySelectAll($export_values_query);
$export_values_query = "SELECT * FROM __schema_tables WHERE deleted = 0";
$schema_tables = $db->querySelectAll($export_values_query);
$export_values_query = "SELECT * FROM __schema_fields WHERE deleted = 0";
$schema_fields = $db->querySelectAll($export_values_query);

$export_i18n_query = "SELECT * FROM _i18n_fields WHERE deleted = 0 AND tabla IN ('_menus','__schema_tables','__schema_fields') ORDER BY tabla,campo,idioma_id";
$export_i18n_values = $db->querySelectAll($export_i18n_query);
$traducciones = array();
$idiomas = array(1);
foreach($export_i18n_values as $eiv)
{
    $key = $eiv['tabla'].'#'.$eiv['campo'].'#'.$eiv['registro_id'];
    if(empty($traducciones[$key]))
        $traducciones[$key] = array(
            'source' => $eiv['tabla'],
            'field' => $eiv['campo'],
            'id' => $eiv['registro_id'],
            'values' => array(1 => array()),
        );
    $traduccion = !empty($eiv['valor_varchar']) ? $eiv['valor_varchar'] : $eiv['valor_txt'];
    $traducciones[$key]['values'][$eiv['idioma_id']] = array(
        'id' => $eiv['id'],
        'tipo' => !empty($eiv['valor_varchar']) ? '_varchar' : '_txt',
        'trad' => $traduccion,
    );
    if(!in_array($eiv['idioma_id'],$idiomas))
        $idiomas[] = $eiv['idioma_id'];
}
$menus_fields = array('nombre');
foreach($menus as $m)
{
    foreach($menus_fields as $mf) {
        if(!empty($m[$mf])) {
            $key = '_menus#' . $mf . '#' . $m['id'];
            if($add_if_not_translated) {
                if (empty($traducciones[$key])) {
                    $traducciones[$key] = array(
                        'source' => '_menus',
                        'field' => $mf,
                        'id' => $m['id'],
                        'values' => array(1 => array()),
                    );
                }
                $traducciones[$key]['values'][1] = array(
                    'id' => $m['id'],
                    'trad' => $m[$mf],
                );
            }
            else if (!empty($traducciones[$key])) {
                $traducciones[$key]['values'][1] = array(
                    'id' => $m['id'],
                    'trad' => $m[$mf],
                );
            }
        }
    }
}
$sf_fields = array('label','help');
foreach($schema_fields as $sf)
{
    foreach($sf_fields as $sfname) {
        if(!empty($sf[$sfname])) {
            $key = '__schema_fields#' . $sfname . '#' . $sf['id'];
            if($add_if_not_translated) {
                if (empty($traducciones[$key])) {
                    $traducciones[$key] = array(
                        'source' => '__schema_fields',
                        'field' => $sfname,
                        'id' => $sf['id'],
                        'values' => array(1 => array()),
                    );
                }
                $traducciones[$key]['values'][1] = array(
                    'id' => $sf['id'],
                    'trad' => $sf[$sfname],
                );
            }
            else if (!empty($traducciones[$key])) {
                $traducciones[$key]['values'][1] = array(
                    'id' => $sf['id'],
                    'trad' => $sf[$sfname],
                );
            }
        }
    }
}
$st_fields = array('entity_name_one','entity_name_multiple');
foreach($schema_tables as $st)
{
    foreach($st_fields as $stname) {
        if(!empty($st[$stname])) {
            $key = '__schema_tables#' . $stname . '#' . $st['id'];
            if($add_if_not_translated) {
                if (empty($traducciones[$key])) {
                    $traducciones[$key] = array(
                        'source' => '__schema_fields',
                        'field' => $stname,
                        'id' => $st['id'],
                        'values' => array(1 => array()),
                    );
                }
                $traducciones[$key]['values'][1] = array(
                    'id' => $st['id'],
                    'trad' => $st[$stname],
                );
            }
            else if (!empty($traducciones[$key])) {
                $traducciones[$key]['values'][1] = array(
                    'id' => $st['id'],
                    'trad' => $st[$stname],
                );
            }
        }
    }
}


$idiomas_array = array();
foreach($idiomas as $l_id)
{
    $idioma_txt = '';
    foreach($export_idiomas_values as $eiv)
    {
        if($eiv['id'] == $l_id) {
            $idioma_txt = $eiv['iso'];
            break;
        }
    }
    $idiomas_array[$l_id] = $idioma_txt;
}

$print = "";
if(!empty($traducciones)) {
    $print .= "'Cadena';";
    $contador_idiomas = 0;
    foreach ($idiomas_array as $l_id => $l_value) {
        $contador_idiomas++;
        $print .= "'Id" . $contador_idiomas . "';'" . $l_value . "';";
    }
    $print .= "\n";
    foreach ($traducciones as $tr_key => $tr) {
        $cadena = $tr_key;
        $print .= "'".$cadena."';";
        foreach ($idiomas_array as $l_id => $l_value) {
            $id = '';
            $traduccion = '';
            if(!empty($tr['values'][$l_id]))
            {
                $id = $tr['values'][$l_id]['id'];
                $traduccion = $tr['values'][$l_id]['trad'];

            }
            $print .= "'".$id."';'".$traduccion."';";
        }
        $print .= "\n";
    }
    echo '<pre>';print_r($print);echo '</pre>';die;
}
//echo '<pre>';print_r("--------------------------------------");echo '</pre>';


?>

