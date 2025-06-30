<?php
require_once(__DIR__.'/../../api/Entity/Base/Usuario.php');
require_once(__DIR__.'/../../api/ApiController.php');
$apiController = new ApiController();
$_SESSION['__db__'] = $apiController->getConnection();
$db = $_SESSION['__db__'];

echo '<pre>';print_r('Lanzando traducciones de menus a partir de ID 10000');echo '</pre>';
$min_id = 1000;
$config = array(
    'nombre' => array('field' => 'valor_varchar'),
);
$idiomas = array(2);

$last_id_query = "SELECT MAX(id) as id FROM _i18n_fields WHERE tabla = '_menus'";
$last_id_data = $db->querySelectAll($last_id_query);
echo '<pre>';print_r($last_id_query);echo '</pre>';
$last_id = (!empty($last_id_data) && !empty($last_id_data[0] && !empty($last_id_data[0]['id']))) ? intval($last_id_data[0]['id']) : 0;
if(!empty($last_id))
    $last_id++;
else
    $last_id = $min_id;
if($last_id < $min_id) $last_id = $min_id;
echo '<pre>';print_r('Próximo ID a utilzar: '.$last_id);echo '</pre>';

echo '<pre>';print_r('Configuración del proceso');echo '</pre>';
echo '<pre>';print_r($config);echo '</pre>';

foreach($config as $property => $config_data) {
    $sql = "SELECT * FROM _menus WHERE id >= 10000 AND id <= 99000 AND deleted = 0 AND
    id NOT IN (SELECT ifi.registro_id FROM _i18n_fields ifi WHERE ifi.tabla = '_menus' AND ifi.`campo` = '".$property."')
    ORDER BY id";
    $menus_data = $db->querySelectAll($sql);
    $sql_insert = "INSERT INTO _i18n_fields (id,idioma_id,`tabla`,`campo`,`registro_id`,`".$config_data['field']."`) VALUES ";
    $is_first = true;
    $count_inserted = 0;
    foreach($idiomas as $idioma_id) {
        foreach ($menus_data as $fd) {
            if(!empty($fd[$property])) {
                if (!$is_first) {
                    $sql_record = ",";
                } else {
                    $sql_record = "";
                    $is_first = false;
                }
                $val = 'menuItem#' . $fd['nombre'].'#'.$fd[$property];
                $id = $last_id;
                $sql_record .= "(";
                $sql_record .= $id . "," . $idioma_id . ",'_menus','" . $property . "'," . $fd['id'] . ",'" . $val . "'";
                $sql_record .= ")";

                $sql_insert .= $sql_record;
                $count_inserted++;
                $last_id++;
            }
        }
    }
    $config[$property]['total'] = $count_inserted;
    if($count_inserted > 0) {
        echo '<pre>';print_r($sql_insert.";");echo '</pre>';
        //$db->query($sql_insert);
    }
}

?>

