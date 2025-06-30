<?php
require_once(__DIR__.'/../../api/Entity/Base/Usuario.php');
require_once(__DIR__.'/../../api/ApiController.php');
$apiController = new ApiController();
$_SESSION['__db__'] = $apiController->getConnection();
$db = $_SESSION['__db__'];

echo '<pre>';print_r('Lanzando traducciones de schema tables a partir de tabla 10000');echo '</pre>';
$min_id = 10000;
$config = array(
    'entity_name_one' => array('field' => 'valor_varchar'),
    'entity_name_multiple' => array('field' => 'valor_varchar'),
    'entity_help' => array('field' => 'valor_txt'),
    'entity_subtitle' => array('field' => 'valor_txt'),
);
$idiomas = array(2);

$last_id_query = "SELECT MAX(id) as id FROM _i18n_fields WHERE tabla = '__schema_tables'";
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
    $sql_insert = "INSERT INTO _i18n_fields (id,idioma_id,`tabla`,`campo`,`registro_id`,`".$config_data['field']."`) VALUES ";
    $is_first = true;
    $count_inserted = 0;
    foreach($idiomas as $idioma_id) {

        $sql = "SELECT st.id,st.`table`,st.entity_name_one,st.entity_name_multiple,st.entity_help,st.entity_subtitle
        FROM __schema_tables st
        WHERE
        st.id >= 10000 AND st.deleted = 0 AND
        st.id NOT IN (SELECT ifi.registro_id FROM _i18n_fields ifi WHERE ifi.tabla = '__schema_tables' AND ifi.`campo` = '".$property."')";
        $config[$property]['query'] = $sql;
        $fields_data = $db->querySelectAll($sql);
        foreach ($fields_data as $fd) {
            if(!empty($fd[$property])) {
                if (!$is_first) {
                    $sql_record = ",";
                } else {
                    $sql_record = "";
                    $is_first = false;
                }
                $val = $fd['table'] . '#' . $property.'#'.$fd[$property];
                $id = $last_id;
                $sql_record .= "(";
                $sql_record .= $id . "," . $idioma_id . ",'__schema_tables','" . $property . "'," . $fd['id'] . ",'" . $val . "'";
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

