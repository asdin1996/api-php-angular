<?php

require_once(__DIR__ . '/EntityLib.php');
require_once(__DIR__ . '/ApiException.php');
require_once(__DIR__ . '/ClassLoader.php');
require_once(__DIR__ . '/PermisosController.php');

class Entity
{

    const _DEFAULT_GETBYID_OPTIONS = array(
        'related' => 1,
        'add_actions' => 1,
    );
    const _MAX_TEXT_SIZE = 256;
    const _DEFAULT_TEXT_SIZE = 150;

    protected $table = '';  // Tabla de la entidad
    protected $source_table = ''; // Propiedad para tablas que no sean la propia fuente - Escrituras
    protected $real_entity = ''; // Propiedad para tablas que no sean la propia fuente - Lecturas

    protected $api_call = '';  // Api Call
    protected $name_formula = 'id';
    protected $table_id = '';  // Table id
    protected $internal_table = false;
    protected $level = 1;

    protected $add_single_events = false;
    protected $is_wizard = false;

    protected $permisos_dimensiones = null; // Dimensiones de la tabla
    protected $database = null; // Database object asociado
    protected $definition = array(); // Definición de la entidad de base de datos
    protected $user_id = null;
    protected $lang_id = null;
    protected $ignore_def = false; // Solo para las tablas internas!

    protected $ignore_dimension = false; // Si hay que ignorar las dimensiones a todos los niveles.
    protected $ignore_permission_fields = false; // Si hay que forzar a que no se ignoren campos de permisos
    protected $loading_table_definition = null; // Para marcar si estamos cargando definición de otra tabla
    protected $loading_table_definition_break = 0; // Para marcar si estamos cargando definición de otra tabla
    protected $ignore_related_def = false; // Para desactivar la búsqueda de definiciones de relacionados
    protected $is_main_rec = false; // Para activar funcionalidades adicionales de cara a api, permisos y demás.

    protected $crud_allow_add = true; // Permite añadir
    protected $crud_allow_edit = true; // Permite editar
    protected $crud_allow_view = true; // Permite ver
    protected $crud_allow_delete = true; // Permite eliminar
    protected $crud_allow_list = true; // Permite lista
    protected $crud_allow_save = true; // Permite save
    protected $crud_allow_pdf = true; // Permite pdf
    protected $crud_allow_xls = true; // Permite xls

    protected $ignore_fields_permisos = false;
    
    protected $removed_data_save = null;

    protected $schema_data_table = array();
    protected $schema_data_fields = array();
    protected $schema_data_lang = array();

    const _ENTITY_SAVED = 'API_ENTITY_SAVED';

    public function getAllow($action)
    {

        $allowed = true;
        switch ($action) {
            case 'add' :
                $allowed = $this->crud_allow_add;
                break;
            case 'edit' :
                $allowed = $this->crud_allow_edit;
                break;
            case 'view' :
                $allowed = $this->crud_allow_view;
                break;
            case 'delete' :
                $allowed = $this->crud_allow_delete;
                break;
            case 'list' :
                $allowed = $this->crud_allow_list;
                break;
            case 'save' :
                $allowed = $this->crud_allow_save;
                break;
            case 'export_pdf' :
                $allowed = $this->crud_allow_pdf;
                break;
            case 'export_xls' :
                $allowed = $this->crud_allow_xls;
                break;
            default :
                // Resto de acciones
                $allowed = false;
                if (!empty($_SESSION['__permisos__'])) {
                    $permisosController = $_SESSION['__permisos__'];
                    $check = $permisosController->checkPermissions($this->api_call, $action);
                    $allowed = $check['allowed'];
                }
                break;

        }
        return $allowed;
    }

    public function __construct($tablename, $is_main_rec = false)
    {
        $this->database = !empty($_SESSION['__db__']) ? $_SESSION['__db__'] : array();
        if (empty($this->database)) {
            throw new Exception('Intentando crear entidad SIN BDD!!! VER CON DESARROLLO.');
            $servername = EntityLib::getServerName();
            $optionsPathFile = __DIR__ . '/options/options.' . $servername . '.json';
            if (!file_exists($optionsPathFile)) {
                throw new Exception(EntityLib::__('API_JSON_CONFIG_ERROR', $this->__masterLangId));
            }
            $jsonOptions = file_get_contents($optionsPathFile);
            $app_options = json_decode($jsonOptions, JSON_UNESCAPED_UNICODE);
            $db_options = array(
                'db_dbtype' => 'sql',
                'db_host' => !empty($app_options['db_host']) ? $app_options['db_host'] : '',
                'db_database' => !empty($app_options['db_name']) ? $app_options['db_name'] : '',
                'db_user' => !empty($app_options['db_user']) ? $app_options['db_user'] : '',
                'db_password' => !empty($app_options['db_pass']) ? $app_options['db_pass'] : '',
            );
            $this->database = new Database($db_options);
        }

        $this->user_id = (!empty($_SESSION) && !empty($_SESSION['__user_id__'])) ? $_SESSION['__user_id__'] : null;
        $this->lang_id = (!empty($_SESSION) && !empty($_SESSION['__lang_id__'])) ? $_SESSION['__lang_id__'] : null;
        $this->is_main_rec = $is_main_rec;
        $this->__assignTableNameAndAlias($tablename);

        if(strpos($this->table,'_') === 0)
        {
            $this->ignore_dimension = true; // Ignoramos tema de dimensiones en tablas del sistema
        }
    }

    /** @noinspection LanguageDetectionInspection */
    private function __assignTableNameAndAlias($tablename)
    {
        $sql = "SELECT * FROM __schema_tables WHERE (`api_call` = ?) AND deleted = 0 ";
        $sql .= "UNION SELECT * FROM __schema_tables WHERE (`table` = ?) AND deleted = 0";
        $query_params = array($tablename,$tablename);
        $finalName = $tablename;
        $finalDimensions = null;
        $showTables = $this->database->querySelectAll($sql,$query_params);

        $tableId = 0;
        $source_table = null;
        $name_formula = null;
        if (!empty($showTables)) {
            $this->schema_data_table = $showTables[0]; 
            $finalName = $showTables[0]['table'];
            $finalApiName = $showTables[0]['api_call'];
            $tableId = $showTables[0]['id'];
            $source_table = !empty($showTables[0]['source']) ? $showTables[0]['source'] : null;
            $real_entity = !empty($showTables[0]['real_entity']) ? $showTables[0]['real_entity'] : null;
            $name_formula = !empty($showTables[0]['name_field']) ? $showTables[0]['name_field'] : null;
            $finalDimensions = $showTables[0]['permisos'];
            $this->crud_allow_add = !empty($showTables[0]['allow_crud_add']);
            $this->crud_allow_edit = !empty($showTables[0]['allow_crud_edit']);
            $this->crud_allow_view = !empty($showTables[0]['allow_crud_view']);
            $this->crud_allow_delete = !empty($showTables[0]['allow_crud_delete']);
            $this->crud_allow_pdf = !empty($showTables[0]['allow_export_pdf']);
            $this->crud_allow_xls = !empty($showTables[0]['allow_export_xls']);
            $this->crud_allow_save = $this->crud_allow_add || $this->crud_allow_edit || $this->crud_allow_delete;
            if (!empty($_SESSION['__permisos__'])) {
                $permisosController = $_SESSION['__permisos__'];

                $check_save = false;
                $check_save = $permisosController->checkPermissions($finalApiName, 'save');
                $check_save = !empty($check_save) && !empty($check_save['allowed']);
                if ($this->crud_allow_save) {
                    $this->crud_allow_save = $check_save;
                }
                if ($this->crud_allow_add) {
                    $check = $permisosController->checkPermissions($finalApiName, 'add');
                    $this->crud_allow_add = $check['allowed'] && $check_save;
                }
                if ($this->crud_allow_edit) {
                    $check = $permisosController->checkPermissions($finalApiName, 'edit');
                    $this->crud_allow_edit = $check['allowed'] && $check_save;
                }
                if ($this->crud_allow_delete) {
                    $check = $permisosController->checkPermissions($finalApiName, 'delete');
                    $this->crud_allow_delete = $check['allowed'] && $check_save;
                }
                if ($this->crud_allow_view) {
                    $check = $permisosController->checkPermissions($finalApiName, 'view');
                    $this->crud_allow_view = $check['allowed'];
                }
                if ($this->crud_allow_list) {
                    $check = $permisosController->checkPermissions($finalApiName, 'list');
                    $this->crud_allow_list = $check['allowed'];
                }
                if ($this->crud_allow_pdf) {
                    $check = $permisosController->checkPermissions($finalApiName, 'export_pdf');
                    $this->crud_allow_pdf = $check['allowed'];
                }
                if ($this->crud_allow_xls) {
                    $check = $permisosController->checkPermissions($finalApiName, 'export_xls');
                    $this->crud_allow_xls = $check['allowed'];
                }

            }


            $sql_fields = "SELECT * FROM __schema_fields WHERE (`table_id` = ?) AND deleted = 0 ";
            $query_params = array($tableId);

            // Nos quedamos con los campos a ignorar
            $fields_to_ignore = array();
            if(!empty($_SESSION['__ignore_fields_next__'])){
                $fields_to_ignore = $_SESSION['__ignore_fields_next__'];
                unset($_SESSION['__ignore_fields_next__']);
            }
            // Recorremos los campos que haya que ignorar en la consulta y añadimos el filtro en la query + parámetros
            if(!empty($fields_to_ignore))
            {
                $fields_to_ignore_sql = "";
                $sql_fields .= "AND `field` NOT IN (";
                foreach($fields_to_ignore as $fti)
                {
                    if(!empty($fields_to_ignore_sql)) $fields_to_ignore_sql .= ",";
                    $fields_to_ignore_sql .= "?";
                    $query_params[] = $fti;
                }
                $sql_fields .= $fields_to_ignore_sql.") ";
            }

            $sql_fields .= "ORDER BY `table_id` ASC,`field_order` ASC, id ASC";

            $resultFields = $this->database->querySelectAll($sql_fields,$query_params);
            if(!empty($resultFields))
                $this->schema_data_fields = $resultFields;

            /*
            if(!empty($_SESSION['__ignore_fields_next__'])){
                foreach($_SESSION['__ignore_fields_next__'] as $field_to_ignore)
                {
                    foreach($this->schema_data_fields as $sdfkey => $finfo)
                    {
                        if(!empty($finfo['field']) && $field_to_ignore == $finfo['field'])
                            unset($this->schema_data_fields[$sdfkey]);
                    }
                }
                unset($_SESSION['__ignore_fields_next__']);
            }
            */

        } else $finalApiName = $finalName;

        $this->table = $finalName;
        $this->source_table = !empty($source_table) ? $source_table : $this->table;
        $this->real_entity = !empty($real_entity) ? $real_entity : $this->table;
        $this->api_call = $finalApiName;
        $this->table_id = $tableId;
        if(!empty($name_formula)) $this->name_formula = $name_formula;

        $this->internal_table = !empty($this->table) && substr($this->table, 0, 1) == '_';

        if (!$this->internal_table && $this->level == 1) {
            $processedDimensions = array();
            if (!empty($finalDimensions)) {
                $auxDim1 = explode(',', $finalDimensions);
                foreach ($auxDim1 as $ad) {
                    $aux = explode('#', $ad);
                    $dim_field = $aux[0];
                    $dim_value = $aux[1];
                    $processedDimensions[$dim_field] = $dim_value;
                }
            }
            $this->permisos_dimensiones = $processedDimensions;
        }

        // Hay que gestionar traducciones para cambiar propiedades de tablas y campos que sean traducibles
        $user_translate_to_id = !empty($_SESSION['__lang_id__']) ? ($_SESSION['__lang_id__'] != 1 ? $_SESSION['__lang_id__'] : null) : null;
        if(!empty($tableId) && !empty($user_translate_to_id)) {
            $sql_para_traducciones = "SELECT * FROM _i18n_fields WHERE `deleted` = 0 AND idioma_id = ? AND(";
            $sql_para_traducciones .= "(`tabla` = '__schema_tables' AND `registro_id` = ?)";
            $query_params = array($user_translate_to_id,$tableId);
            $fields_aux_id = array();
            if (!empty($this->schema_data_fields)) {
                $field_ids = '';
                foreach ($this->schema_data_fields as $f_key => $f) {
                    if (!empty($f['id'])) {
                        if (!empty($field_ids)) $field_ids .= ',';
                        $field_ids .= '?';
                        $query_params[] = $f['id'];
                        $fields_aux_id[$f['id']] = $f_key;
                    }
                }
                $sql_para_traducciones .= " OR (`tabla` = '__schema_fields' AND `registro_id` IN (" . $field_ids . "))";
            }
            $sql_para_traducciones .= ")";
            $resultTraducciones = $this->database->querySelectAll($sql_para_traducciones,$query_params);
            if(!empty($resultTraducciones))
            {
                foreach($resultTraducciones as $rt)
                {
                    $rt_es_tabla = $rt['tabla'] == '__schema_tables';
                    $rt_es_field = $rt['tabla'] == '__schema_fields';
                    $rt_campo = $rt['campo'];
                    $rt_registro_id = intval($rt['registro_id']);
                    $rt_valor_traduccion = !empty($rt['valor_varchar'])
                        ? $rt['valor_varchar']
                        : (!empty($rt['valor_txt']) ? $rt['valor_txt'] : null);
                    if(!empty($rt_valor_traduccion)) {
                        if ($rt_es_tabla) {
                            $this->schema_data_table[$rt_campo] = $rt_valor_traduccion;
                        } else if ($rt_es_field) {
                            $field_key = array_key_exists($rt_registro_id,$fields_aux_id) !== false ? $fields_aux_id[$rt_registro_id] : null;
                            if(!is_null($field_key))
                            {
                                $this->schema_data_fields[$field_key][$rt_campo] = $rt_valor_traduccion;
                            }
                        }
                    }
                }
            }

        }

    }

    private function setLevel($new_level)
    {
        $this->level = $new_level;

        // Cuando establecemos el setLevel de una entidad, quitamos campos si tienen la propiedad only_main_card_field
        if(!empty($this->schema_data_fields) && $this->level > 1)
        {
            $fields_aux = array();
            foreach($this->schema_data_fields as $sdf)
            {
                if(empty($sdf['only_main_card_field']))
                    $fields_aux[] = $sdf;
            }
            $this->schema_data_fields = $fields_aux;
        }
    }

    public function getApiCall()
    {
        return $this->api_call;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getLevel()
    {
        return $this->level;
    }

    public function getCrudAllowEdit()
    {
        return $this->crud_allow_edit;
    }


    public function getTableId()
    {
        return $this->table_id;
    }

    public function getNameFormula()
    {
        return $this->name_formula;
    }

    public function setIgnoreDef($ignore)
    {
        $this->ignore_def = $ignore;
    }

    public function setIgnoreRelatedDef($ignore_related)
    {
        $this->ignore_related_def = $ignore_related;
    }

    public function setIgnoreDimension($ignore_dimension_defaults)
    {
        $this->ignore_dimension = $ignore_dimension_defaults;
    }

    public function setIgnorePermission($ignore_permission_fields)
    {
        $this->ignore_permission_fields = $ignore_permission_fields;
    }

    public function loadDefinition($force_lang_id = null)
    {
        $trad_params = array();
        if(!empty($force_lang_id))
            $trad_params['__force_lang__'] = $force_lang_id;
        //EntityLib::debugSequence('['.$this->level.'] Inicializando "'.$this->table."'",true);
        $_memory_objects = array();
        $result = array('fields' => array(), 'list_fields' => array(), 'filter_fields' => array(), 'export_fields' => array(), 'related_info' => null, 'name_field' => null, 'entity_name_one' => '', 'entity_name_multiple' => '');
        if ($this->ignore_def)
            return $result;

        /*$e = new Entity('schema_tables');
        $e->setLevel($this->level + 1);
        $e->loading_table_definition = $this->table;
        $e->setIgnoreDef(true);
        $params = array(
            'filters' => array('api_call' => array('=' => $this->table)),
        );
        $tableData = $e->getList($params);
        if (empty($tableData) || empty($tableData['data'])) {
            $params = array(
                'filters' => array('table' => array('=' => $this->table)),
            );
            $tableData = $e->getList($params);
        }
        */
        // Cargamos dimensiones de la tabla
        $dimensiones_tabla = $this->permisos_dimensiones;
        $remove_fields_permisos = array();

        // Establecemos todos los permisos a nulo, solo procederemos a comprobar si es el registro principal.
        $camposOcultar = $camposNoEditables = $accionesNoDisponibles = $permisosController = array();
        if ($this->is_main_rec) {
            $rol_ids = !empty($_SESSION['__rol_ids__']) ? $_SESSION['__rol_ids__'] : array();
            $permisosController = !empty($_SESSION['__permisos__']) ? $_SESSION['__permisos__'] : new PermisosController($rol_ids);
            $camposOcultar = $permisosController->getNoVisibleFields();
            $camposNoEditables = $permisosController->getNoEditableFields();
            $accionesNoDisponibles = $permisosController->getDisabledActions();
        }

        if (!empty($this->schema_data_table)) {
            $relatedDefinitions = array();
            $origin = $this->schema_data_table;
            $result = array();
            /*$all_fields_name = array('id', '__name__');
            $modal_base_field = array('id', '__name__');
            $modal_base_filter = array('id', '__name__');
            $orm_fields_name = array('id', '__name__');*/
            $all_fields_name = array();
            $modal_base_field = array();
            $modal_base_filter = array();
            $orm_fields_name = array();
            $base_fields = EntityLib::getBaseFields();
            $full_fields_info = EntityLib::getBaseFieldsData();

            /*if(in_array($this->table,array('__schema_fields'))) {
                $e2 = $this;
                $e2->setIgnoreDef(true);
            }
            else
            {
                $e2 = new Entity('schema_fields');
            }
            */
            /*
            $e2 = new Entity('schema_fields',false);
            $e2->loading_table_definition = $this->table;
            $e2->setIgnoreDef(true);
            $params = array(
                'filters' => array('table_id' => array('=' => $origin['id']), 'deleted' => array('=' => 0)),
                'order' => array('table_id' => 'ASC', 'field_order' => 'ASC', 'id' => 'ASC'),
            );
            $fieldData = $e2->getList($params);
            */
            $fieldData = array('data' => $this->schema_data_fields);
            $fieldsToAppend = array();
            if (!empty($fieldData) && !empty($fieldData['data'])) {
                if (empty($origin['include_base_fields'])) {
                    if (!empty($fieldKeys['datetime_add'])) {
                        unset($base_fields['datetime_add']);
                        unset($full_fields_info['datetime_add']);
                    }
                    if (!empty($fieldKeys['datetime_upd'])) {
                        unset($base_fields['datetime_upd']);
                        unset($full_fields_info['datetime_upd']);
                    }
                    if (!empty($fieldKeys['datetime_del'])) {
                        unset($base_fields['datetime_del']);
                        unset($full_fields_info['datetime_del']);
                    }
                    if (!empty($fieldKeys['user_add_id'])) {
                        unset($base_fields['user_add_id']);
                        unset($full_fields_info['user_add_id']);
                    }
                    if (!empty($fieldKeys['user_upd_id'])) {
                        unset($base_fields['user_upd_id']);
                        unset($full_fields_info['user_upd_id']);
                    }
                    if (!empty($fieldKeys['user_del_id'])) {
                        unset($base_fields['user_del_id']);
                        unset($full_fields_info['user_del_id']);
                    }
                    //if(!empty($fieldKeys['deleted'])) { unset($base_fields['deleted']); unset($full_fields_info['deleted']);}
                }

                foreach ($full_fields_info as $ffi_key => $ffi_values) {
                    $ffi_values['field'] = $ffi_key;
                    $fieldData['data'][] = $ffi_values;
                }

                $fieldKeys = array();
                foreach ($fieldData['data'] as $f) {
                    $fieldKeys[$f['field']] = $f;
                }

                //$all_fields_name = array_merge($all_fields_name, $base_fields);

                foreach ($fieldData['data'] as $field) {
                    $allowed_for_user = true;
                    if (!empty($field['permiso_requerido']) && !$this->ignore_permission_fields) {
                        $aux = explode('#', $field['permiso_requerido']);
                        if (!empty($aux[0]) && !empty($aux[1])) {
                            $field_permission_controller = $aux[0];
                            $field_permission_action = $aux[1];
                            if (!empty($_SESSION['__permisos__'])) {
                                $permisosController = $_SESSION['__permisos__'];
                                $check = $permisosController->checkPermissions($field_permission_controller, $field_permission_action);
                                $allowed_for_user = $check['allowed'];
                            }
                        }
                    }

                    if ($allowed_for_user) {
                        $all_fields_name[] = $field['field'];
                        $orm_fields_name[] = $field['field'];
                        //if (empty($full_fields_info[$field['field']])) {
                        $auxField = $field;
                        $auxField['field_order'] = intval($field['field_order']);
                        $auxField['id'] = !empty($field['id']) ? $field['id'] : 0;
                        $auxField['type'] = $field['type'];
                        $auxField['field'] = $field['field'];
                        $auxField['label'] = $field['label'];
                        $auxField['tipo_icono'] = !empty($field['tipo_icono']) ? $field['tipo_icono'] : '';
                        $auxField['label_icon'] = !empty($field['label_icon']) ? $field['label_icon'] : '';
                        $auxField['label_text'] = !empty($field['label_text']) ? $field['label_text'] : '';
                        $auxField['filter_options'] = !empty($field['filter_options']) ? $field['filter_options'] : 'default';
                        $auxField['editable'] = !empty($field['editable']) ? 1 : 0;
                        $auxField['no_editable_with_value'] = !empty($field['no_editable_with_value']);
                        $auxField['help'] = !empty($field['help']) ? $field['help'] : '';
                        $auxField['traducible'] = !empty($field['traducible']) ? 1 : 0;
                        if (!empty($camposNoEditables)) {
                            if (in_array($field['field'], $camposNoEditables))
                                $auxField['editable'] = 0;
                        }

                        $auxField['hidden'] = !empty($field['hidden']) ? 1 : 0;
                        if (!empty($auxField['hidden'])) {
                            if (!in_array($auxField['field'], $camposOcultar))
                                $camposOcultar[] = $auxField['field'];
                        }
                        $auxField['required'] = !empty($field['required']) ? 1 : 0;
                        $auxField['allow_null'] = !empty($field['allow_null']) ? 1 : 0;
                        $auxField['no_real'] = !empty($field['no_real']) ? 1 : 0;
                        $auxField['max_size'] = !empty($field['max_size']) ? intval($field['max_size']) : self::_DEFAULT_TEXT_SIZE;
                        $auxField['max_size_truncate_lists'] = !empty($field['max_size_truncate_lists']) ? intval($field['max_size_truncate_lists']) : 0;
                        $auxField['default_value'] = !is_null($field['default_value']) ? $field['default_value'] : null;
                        switch ($field['type']) {
                            case 'int' :
                                $auxField['default_value'] = !is_null($auxField['default_value']) ? intval($auxField['default_value']) : null;
                                break;
                            case 'boolean' :
                                $auxField['default_value'] = !empty($auxField['default_value']) ? intval($auxField['default_value']) : 0;
                                break;
                            case 'date' :
                                $defaultValue = null;
                                if (!empty($auxField['default_value'])) {
                                    switch ($auxField['default_value']) {
                                        case 'today' :
                                            $defaultValue = date('Y-m-d');
                                            break;
                                    }
                                }
                                $auxField['default_value'] = $defaultValue;
                                break;
                            case 'datetime' :
                                $defaultValue = null;
                                if (!empty($auxField['default_value'])) {
                                    switch ($auxField['default_value']) {
                                        case 'now' :
                                            $defaultValue = date('Y-m-d H:i:s');
                                            break;
                                    }
                                }
                                $auxField['default_value'] = $defaultValue;
                                $auxField['time_with_seconds'] = !empty($field['time_with_seconds']);
                                break;
                            case 'time' :
                                $auxField['time_with_seconds'] = !empty($field['time_with_seconds']);
                                break;
                            case 'decimal' :
                                $auxField['default_value'] = !is_null($auxField['default_value']) ? floatval($auxField['default_value']) : null;
                                $auxField['decimal_precission'] = !empty($field['decimal_precission']) ? intval($field['decimal_precission']) : 2;
                                $auxFI = array('type' => 'decimal', 'decimal_precission' => $auxField['decimal_precission']);
                                if(!is_null($auxField['default_value']))
                                    $auxField['default_value'] = $this->exportField2API($auxFI, $auxField['default_value']);
                                break;
                            case 'enum' :
                            case 'enum-multi' :
                                if (!empty($field['enum_options'])) {
                                    if(strpos($field['enum_options'],'var#') === 0)
                                    {
                                        $var_aux = substr($field['enum_options'],4);
                                        $var_value = EntityLib::getConfig($var_aux);
                                        if(empty($var_value))
                                            throw new Exception('No se ha configurado correctamente la variable "'.$var_aux.'"');
                                        $field['enum_options'] = $var_value;
                                    }
                                    $aux = json_decode($field['enum_options'], JSON_UNESCAPED_UNICODE);
                                    // Quitamos opción de blanco!
                                    //$auxField['enum_options'] = $aux;
                                    $auxField['enum_options'] = array();
                                    foreach($aux as $enum_key => $enum_value)
                                    {
                                        $aux_enum_value = $enum_value;
                                        if(strpos($aux_enum_value,'#') !== false)
                                        {
                                            $aux_enum_value_arr = explode('#',$aux_enum_value);
                                            if(!empty($aux_enum_value_arr) && !empty($aux_enum_value_arr[0]))
                                            {
                                                $aux_enum_value_arr[0] = EntityLib::__($aux_enum_value_arr[0],$trad_params);
                                                $aux_enum_value = implode('#',$aux_enum_value_arr);
                                            }
                                        }
                                        else
                                        {
                                            $aux_enum_value = EntityLib::__($aux_enum_value,$trad_params);
                                        }
                                        $auxField['enum_options'][$enum_key] = $aux_enum_value;
                                    }

                                    $auxField['enum_order'] = !empty($field['enum_order']) ? $field['enum_order'] : 'name';
                                    if(!empty($auxField['enum_order']))
                                    {
                                        if(!in_array($auxField['enum_order'],array('name','order')))
                                        {
                                            $auxField['enum_order'] = 'name';
                                        }
                                    }

                                    /*$allEnum = array('');
                                    $allEnum = array_merge($allEnum,$aux);
                                    $auxField['enum_options'] = $allEnum;*/
                                }
                                break;
                            case 'related' :
                            case 'related-combo' :
                                if(empty($this->ignore_related_def)) {
                                    if (!empty($field['related_parent_options']))
                                        $auxField['related_parent_options'] = json_decode($field['related_parent_options'], JSON_UNESCAPED_UNICODE);
                                    else
                                        $auxField['related_parent_options'] = null;
                                    if (!empty($field['related_options'])) {

                                        // Si el related options empieza por var#, nos vamos a buscar esa variable
                                        if(strpos($field['related_options'],'var#') === 0)
                                        {
                                            $var_aux = substr($field['related_options'],4);
                                            $var_value = EntityLib::getConfig($var_aux);
                                            if(empty($var_value))
                                                throw new Exception('No se ha configurado correctamente la variable "'.$var_aux.'"');
                                            $field['related_options'] = $var_value;
                                        }

                                        $is_related_pivot = substr($field['related_options'], 0, 1) === '$';
                                        if (!$is_related_pivot) {
                                            $auxField['related_multiple'] = !empty($field['related_multiple']) ? 1 : 0;
                                            $auxField['related_view'] = !empty($field['related_view']) ? $field['related_view'] : null;
                                            $relatedFieldValues = array('');
                                            //if (!empty($field['related_options'])) {
                                            $related_table = $field['related_options'];
                                            $related_filters = array();
                                            $aux_relatedtable_information = explode('[', $related_table);
                                            if (!empty($aux_relatedtable_information)) {
                                                $related_table = $aux_relatedtable_information[0];
                                                if (!empty($aux_relatedtable_information[1])) {
                                                    $related_filters_str = substr($aux_relatedtable_information[1], 0, strlen($aux_relatedtable_information[1]) - 1);
                                                    $all_conditions = explode(',', $related_filters_str);
                                                    foreach ($all_conditions as $single_condition) {
                                                        $aux_related_filters_info = explode('=', $single_condition);
                                                        if (empty($related_filters))
                                                            $related_filters = array('filters' => array());

                                                        if (!empty($aux_related_filters_info[1])) {
                                                            $filter_value = $aux_related_filters_info[1];
                                                            $condition = '=';
                                                            if (strpos($filter_value, '||') !== false) {
                                                                $condition = 'IN';
                                                                $filter_value = explode('||', $filter_value);
                                                            }
                                                            $related_filters['filters'][$aux_related_filters_info[0]] = array($condition => $filter_value);
                                                        }
                                                    }
                                                }
                                            }
                                            $auxField['related_table'] = $related_table;
                                            $auxField['related_filters'] = !empty($related_filters['filters']) ? $related_filters['filters'] : array();
                                            if ($this->is_main_rec && $this->level <= 3) {

                                                //echo '<pre>';print_r('Cargando datos de '.$related_table);echo '</pre>';
                                                // Daba problemas si pasaba el array vacío. Si está vacío pasaré nulo
                                                if(empty($_memory_objects[$related_table])) {
                                                    //EntityLib::debugSequence('Se cargará entidad asociada "' . $related_table . '"', true);
                                                    $relatedObj = ClassLoader::getModelObject($related_table, true);
                                                }
                                                else
                                                {
                                                    //EntityLib::debugSequence('Se cargará entidad asociada "' . $related_table . '" de memoria', true);
                                                    $relatedObj = $_memory_objects[$related_table];
                                                }
                                                $relatedObj->setLevel($this->level + 1);
                                                $relatedNameField = $relatedObj->getNameFormula();
                                                $auxField['related_option_info'] = $related_table . '#id#' . $relatedNameField;
                                                $auxField['related_option_extra'] = $relatedNameField;
                                                $auxField['related_option_api'] = $relatedObj->getApiCall();

                                                if(!in_array($related_table,$_memory_objects))
                                                    $_memory_objects[$related_table] = $relatedObj;
                                            }
                                            if (empty($related_filters))
                                                $related_filters = null;
                                            $auxField['related_filters'] = $related_filters;
                                            //}
                                            //$auxField['related_options'] = array();
                                            $fieldsToAppend = array($this->getExtraFieldForField($auxField));
                                        } // Si el campo related pivota dependiendo del valor de un campo o no
                                        else {
                                            $auxField['related_multiple'] = 0;
                                            $auxField['related_view'] = null;
                                            $related_table = substr($field['related_options'], 1);
                                            $related_table = substr($related_table, 0, strlen($related_table) - 1);
                                            $aux_relatedtable_information = explode('[', $related_table);
                                            // El primer index del array es la variable con $
                                            $aux_relatedtable_information_pivot = explode(',', $aux_relatedtable_information[1]);
                                            $auxField['related_pivot_switch'] = '$' . $aux_relatedtable_information[0];
                                            $auxField['related_pivot_case'] = array();
                                            foreach ($aux_relatedtable_information_pivot as $aux_related_pivot_table) {
                                                $aux_relatedtable_information_pivot_values = explode('=', $aux_related_pivot_table);
                                                $aux_relatedtable_real = $aux_relatedtable_information_pivot_values[0];
                                                $aux_relatedtable_value = $aux_relatedtable_information_pivot_values[1];
                                                $load_full = $this->level <= 2;
                                                $subtableObj = ClassLoader::getModelObject($aux_relatedtable_real, $load_full);
                                                $subtableObj->setLevel($this->level + 1);
                                                $auxField['related_pivot_case'][$aux_relatedtable_value] = array(
                                                    'table' => $aux_relatedtable_real,
                                                    'def' => $subtableObj->loadDefinition()
                                                );
                                            }
                                            $fieldsToAppend = array($this->getExtraFieldForField($auxField));
                                        }

                                    }
                                }
                                break;
                            case 'calendar' :
                                $related_table = '_calendarios';
                                if (empty($relatedDefinitions[$related_table]) && empty($this->ignore_related_def)) {
                                    $subtableObj = ClassLoader::getModelObject($related_table, true);
                                    $subtableObj->setLevel($this->level + 1);
                                    $relatedDefinitions[$related_table] = $subtableObj->loadDefinition();

                                    // Pte - Probablemente haya que quitar valores
                                    /*
                                    $relatedExcludeFields = !empty($relatedDefinitions[$related_table]['exclude_fields']) ? $relatedDefinitions[$related_table]['exclude_fields'] : array();
                                    if (!in_array('adjunto_id', $relatedExcludeFields)) {
                                        $relatedDefinitions[$related_table]['exclude_fields'][] = 'adjuntos_id';
                                    }
                                    */
                                }
                                break;
                            case 'gallery' :
                                $related_table = 'adjuntos_galerias';
                                if (empty($relatedDefinitions[$related_table]) && empty($this->ignore_related_def)) {
                                    $subtableObj = ClassLoader::getModelObject($related_table, true);
                                    $subtableObj->setLevel($this->level + 1);
                                    $relatedDefinitions[$related_table] = $subtableObj->loadDefinition();

                                    // Forzamos a quitarle el adjunto_id
                                    $relatedExcludeFields = !empty($relatedDefinitions[$related_table]['exclude_fields']) ? $relatedDefinitions[$related_table]['exclude_fields'] : array();
                                    if (!in_array('adjunto_id', $relatedExcludeFields)) {
                                        $relatedDefinitions[$related_table]['exclude_fields'][] = 'adjunto_id';
                                    }
                                }
                                break;
                            case 'attachment' :
                                $related_table = 'adjuntos_ficheros';
                                if (empty($relatedDefinitions[$related_table]) && empty($this->ignore_related_def)) {
                                    $subtableObj = ClassLoader::getModelObject($related_table, true);
                                    $subtableObj->setLevel($this->level + 1);
                                    $relatedDefinitions[$related_table] = $subtableObj->loadDefinition();
                                    //echo '<pre>';print_r($relatedDefinitions[$related_table]);echo '</pre>';

                                    // Forzamos a quitarle el adjunto_id
                                    $relatedExcludeFields = !empty($relatedDefinitions[$related_table]['exclude_fields']) ? $relatedDefinitions[$related_table]['exclude_fields'] : array();
                                    if (!in_array('adjunto_id', $relatedExcludeFields)) {
                                        $relatedDefinitions[$related_table]['exclude_fields'][] = 'adjunto_id';
                                    }
                                }
                                break;
                            case 'attachment_custom' :
                                $related_table = $this->table.'_adjuntos_ficheros';
                                if (empty($relatedDefinitions[$related_table]) && empty($this->ignore_related_def)) {
                                    $subtableObj = ClassLoader::getModelObject($related_table, true);
                                    $subtableObj->setLevel($this->level + 1);
                                    $relatedDefinitions[$related_table] = $subtableObj->loadDefinition();
                                    // Forzamos a quitarle el adjunto_id
                                    $relatedExcludeFields = !empty($relatedDefinitions[$related_table]['exclude_fields']) ? $relatedDefinitions[$related_table]['exclude_fields'] : array();
                                    if (!in_array('adjuntos_custom_id', $relatedExcludeFields)) {
                                        $relatedDefinitions[$related_table]['exclude_fields'][] = 'adjuntos_custom_id';
                                    }
                                }
                                break;
                            case 'subtable' :
                            case 'subtable-edit' :
                            case 'comments' :
                                $subtableInformationStr = !empty($field['subtable_information']) ? $field['subtable_information'] : '';
                                $subtableInformation = array();
                                //if ($this->is_main_rec) {
                                $aux_subtable_information = explode('[', $subtableInformationStr);

                                // Para evitar bucles infinitos
                                $subtableIgnoreFields = !empty($field['subtable_ignore_fields']) ? explode(",",$field['subtable_ignore_fields']) : array();
                                if(!empty($subtableIgnoreFields))
                                    $_SESSION['__ignore_fields_next__'] = $subtableIgnoreFields;

                                if (!empty($aux_subtable_information) && empty($this->ignore_related_def)) {
                                    //echo '<pre>';print_r($aux_subtable_information);echo '</pre>';;
                                    $aux_subtable_string = $aux_subtable_information[0];
                                    $aux_subtable_filters = array();
                                    if (!empty($aux_subtable_information[1])) {
                                        $aux_subtable_filters = substr($aux_subtable_information[1], 0, strlen($aux_subtable_information[1]) - 1);
                                    }
                                    $aux_subtable_info = explode('#', $aux_subtable_string);
                                    $aux_subtable_name = $aux_subtable_info[0];
                                    $aux_subtable_api = $aux_subtable_name;
                                    $aux_subtable_field = !empty($aux_subtable_info[1]) ? $aux_subtable_info[1] : '';
                                    $aux_subtable_source_field = !empty($aux_subtable_info[2]) ? $aux_subtable_info[2] : '';

                                    $aux_subtable_order = 'id ASC';
                                    if (!empty($aux_subtable_info[3]))
                                        $aux_subtable_order = $aux_subtable_info[3];

                                    $aux_subtable_processed_info = array();
                                    if (!empty($aux_subtable_filters)) {
                                        // Generación de __relateds__ de cara a servir en estructura.
                                        // Solo se tiene en cuenta un campo con filtro.
                                        // La condición es = unicamente
                                        $aux_subtable_filters_info = explode('=', $aux_subtable_filters);
                                        $filter_value = $aux_subtable_filters_info[1];
                                        $condition = '=';
                                        if (strpos($filter_value, '||') !== false) {
                                            $condition = 'IN';
                                            $filter_value = explode('||', $filter_value);
                                        }
                                        $aux_subtable_processed_info = array(
                                            //'filters' => array(
                                            $aux_subtable_filters_info[0] => array($condition => $filter_value)
                                            //),
                                        );
                                    }

                                    $api_query = "SELECT `api_call` FROM __schema_tables WHERE `deleted` = 0 AND `table` = ?";
                                    $query_params = array($api_query);
                                    $api_values = $this->database->querySelectAll($api_query,$query_params);
                                    if (!empty($api_values) && !empty($api_values[0]) && !empty($api_values[0]['api_call'])) {
                                        $aux_subtable_api = $api_values[0]['api_call'];
                                    }

                                    // Cargamos el objeto related para pillar su estructura y pasarla
                                    $subtableObj = ClassLoader::getModelObject($aux_subtable_name, true);
                                    $subtableObj->setLevel($this->level + 1);
                                    $subtableDef = $subtableObj->loadDefinition();

                                    $subtableInformation = array(
                                        'table' => $aux_subtable_name,
                                        'api_call' => $aux_subtable_api,
                                        'source_field' => $field['field'],
                                        'destiny_table_relation_field' => $aux_subtable_field,
                                        'source_table_relation_field' => $aux_subtable_source_field,
                                        'related_filters' => $aux_subtable_processed_info,
                                        'order' => $aux_subtable_order,
                                        //'def' => $subtableDef
                                    );

                                    if (empty($relatedDefinitions[$aux_subtable_name])) {
                                        $relatedDefinitions[$aux_subtable_name] = $subtableDef;

                                        // Forzamos a quitarle el campo de la clave
                                        $relatedExcludeFields = !empty($relatedDefinitions[$aux_subtable_name]['exclude_fields']) ? $relatedDefinitions[$aux_subtable_name]['exclude_fields'] : array();
                                        if (!in_array($aux_subtable_field, $relatedExcludeFields)) {
                                            $relatedDefinitions[$aux_subtable_name]['exclude_fields'][] = $aux_subtable_field;
                                        }
                                    }
                                    /*
                                    echo '<pre>';print_r($aux_subtable_string);echo '</pre>';
                                    echo '<pre>';print_r($aux_subtable_filters);echo '</pre>';*/
                                }
                                //}
                                if (!empty($subtableInformation))
                                    $auxField['subtable_information'] = $subtableInformation;
                                $auxField['subtable_ignore_delete_cascade'] = !empty($field['subtable_ignore_delete_cascade']) ? 1 : 0;
                                break;
                            case 'map' :
                                $auxField['map_options'] = !empty($field['map_options']) ? json_decode($field['map_options'], JSON_UNESCAPED_UNICODE) : '';
                                break;
                            case 'password' :
                                $passwordFields = $this->getExtraFieldForPassword($auxField);
                                $fieldsToAppend = array_merge($fieldsToAppend, $passwordFields);
                                break;
                            default :
                                break;
                        }

                        $auxFieldsToAppend = array();
                        foreach ($fieldsToAppend as $fta) {
                            $auxFieldsToAppend[$fta['field']] = $fta;
                        }
                        $fieldsToAppend = $auxFieldsToAppend;

                        // Ojo, si hay dimensiones que afecten al campo hay que ver si forzamos valor
                        // Importante: solo hacemos este paso para el nivel 1!
                        if (!empty($dimensiones_tabla) && $this->level == 1) {
                            foreach ($dimensiones_tabla as $dim_field => $dim_dimension) {
                                if ($dim_field === $field['field'] && $dim_field != 'id') {
                                    // Buscamos en las dimensiones de sesión del usuario. para el valor por defecto.
                                    // Ojo, si la dimension es un array, es que hay varias, NO añadimos en ese caso!
                                    if (!empty($_SESSION) && !empty($_SESSION['__dimension__']) && !empty($_SESSION['__dimension__'][$dim_dimension]) && !is_array($_SESSION['__dimension__'][$dim_dimension])) {
                                        // Los campos con dimensión no serán editables, pero solo si se ha definido una dimensión, si no no se fuerza.
                                        // De esta manera, la parte de Todos, como tiene valor 0 se pilla como empty y no se fuerza, permitiendo al usuario elegir (ya se filtra en tabla destino también si se configura correcto)
                                        $auxField['editable'] = 0;
                                        $auxField['default_value'] = $_SESSION['__dimension__'][$dim_dimension];
                                        if (in_array($field['type'], array('related', 'related-combo')) && !empty($fieldsToAppend)) {
                                            $related_value_field = $field['field'] . '_value';
                                            /* Código antiguo
                                            if (!empty($auxField['related_options']) && !empty($auxField['related_options'][$auxField['default_value']])) {
                                                $fieldsToAppend[$related_value_field]['default_value'] = $auxField['related_options'][$auxField['default_value']];
                                            }*/
                                            // Código nuevo, el related_options va a venir siempre vacío, lo que hacemos es si hay valor de dimensión, coger este registro para traernos el name y ponerlo
                                            // en el valor por defecto
                                            $dimObj = ClassLoader::getModelObject($dim_dimension, true);
                                            $dimRec = $dimObj->getById($auxField['default_value'],array('ignore_related' => false,'add_actions' => false));
                                            if (!empty($dimRec) && !empty($dimRec['__name__']))
                                                $fieldsToAppend[$related_value_field]['default_value'] = $dimRec['__name__'];
                                        }
                                    } else {
                                        // En cualquier otro caso, lo que hacemos es marcar esta propiedad para forzar a que el campo sea no editable si hay valor
                                        $auxField['no_editable_with_value'] = 1;
                                    }
                                }
                            }
                        }

                        $full_fields_info[$field['field']] = $auxField;
                        if (!empty($fieldsToAppend)) {
                            foreach ($fieldsToAppend as $fta) {
                                $full_fields_info[$fta['field']] = $fta;
                            }
                        }
                        //}
                    }
                    else if(!empty($field['field'])){
                        $remove_fields_permisos[] = $field['field'];
                    }
                }
            }

            //$permisos = !empty($origin['permisos']) ? explode(',',$origin['permisos']) : array();
            $default_order = !empty($origin['default_order']) ? $origin['default_order'] : "id DESC";
            $list_fields = !empty($origin['list_fields']) ? explode(",", $origin['list_fields']) : $orm_fields_name;
            $final_list_fields = array();
            foreach ($list_fields as $sub_key => $sub_value) {
                $sub_info = !empty($full_fields_info[$sub_value]) ? $full_fields_info[$sub_value] : array();
                /*
                if (!empty($sub_info) && !empty($sub_info['type']) && $sub_info['type'] === 'related') {
                    $sub_valuefield_name = $sub_value . '_value';
                    $final_list_fields[$sub_key] = $sub_valuefield_name;
                } else
                    $final_list_fields[$sub_key] = $sub_value;
                */
                $final_list_fields[$sub_key] = $sub_value;
            }
            $list_fields = array_values($final_list_fields);

            $filter_fields = !empty($origin['filter_fields']) ? explode(",", $origin['filter_fields']) : $list_fields;
            $final_filter_fields = array();
            foreach ($filter_fields as $sub_key => $sub_value) {
                $sub_info = !empty($full_fields_info[$sub_value]) ? $full_fields_info[$sub_value] : array();
                if (!empty($sub_info) && !empty($sub_info['source_field'])) {
                    $super_valuefield_name = $sub_info['source_field'];
                    $final_filter_fields[$sub_key] = $super_valuefield_name;
                }
                if (!empty($sub_info) && !empty($sub_info['type']) && $sub_info['type'] !== 'file') {
                    $final_filter_fields[$sub_key] = $sub_value;
                }
            }
            $filter_fields = array_values($final_filter_fields);

            $modal_list_fields = !empty($origin['modal_list_fields']) ? explode(",", $origin['modal_list_fields']) : array();
            //$modal_list_fields = !empty($origin['modal_list_fields']) ? explode(",",$origin['modal_list_fields']) : $modal_base_field;
            if (!empty($modal_list_fields)) {
                foreach ($modal_list_fields as $sub_key => $sub_value) {
                    /*
                    $sub_info = !empty($full_fields_info[$sub_value]) ? $full_fields_info[$sub_value] : array();
                    if (!empty($sub_info) && !empty($sub_info['type']) && in_array($sub_info['type'], array('related', 'related-combo'))) {
                        $sub_valuefield_name = $sub_value . '_value';
                        $modal_list_fields[$sub_key] = $sub_valuefield_name;
                    }
                    */
                    $modal_list_fields[$sub_key] = $sub_value;
                }
            } else
                $modal_list_fields = $list_fields;

            /* Comento lo de no sacar el __name__ en listados
            if (!empty($modal_list_fields) && in_array('__name__', $modal_list_fields)) {
                $key = array_search('__name__', $modal_list_fields);
                unset($modal_list_fields[$key]);
            }
            */
            $modal_list_fields = array_values($modal_list_fields);

            $modal_filter_fields = !empty($origin['modal_filter_fields']) ? explode(",", $origin['modal_filter_fields']) : $filter_fields;
            //$modal_filter_fields = !empty($origin['modal_filter_fields']) ? explode(",",$origin['modal_filter_fields']) : $modal_base_filter;
            foreach ($modal_filter_fields as $sub_key => $sub_value) {
                $sub_info = !empty($full_fields_info[$sub_value]) ? $full_fields_info[$sub_value] : array();
                if (!empty($sub_info) && !empty($sub_info['source_field'])) {
                    $super_valuefield_name = $sub_info['source_field'];
                    $modal_filter_fields[$sub_key] = $super_valuefield_name;
                }
            }
            /* Comento lo de no sacar el __name__ en listados
            if (!empty($modal_filter_fields) && in_array('__name__', $modal_filter_fields)) {
                $key = array_search('__name__', $modal_filter_fields);
                unset($modal_filter_fields[$key]);
            }
            */
            $modal_filter_fields = array_values($modal_filter_fields);

            // Campos para movil
            $mobile_list_fields = !empty($origin['mobile_fields']) ? explode(",", $origin['mobile_fields']) : array();
            if (!empty($mobile_list_fields)) {
                foreach ($mobile_list_fields as $sub_key => $sub_value) {
                    /*
                    $sub_info = !empty($full_fields_info[$sub_value]) ? $full_fields_info[$sub_value] : array();
                    if (!empty($sub_info) && !empty($sub_info['type']) && in_array($sub_info['type'], array('related', 'related-combo'))) {
                        $sub_valuefield_name = $sub_value . '_value';
                        $mobile_list_fields[$sub_key] = $sub_valuefield_name;
                    }
                    */
                    $mobile_list_fields[$sub_key] = $sub_value;
                }
            } else
                $mobile_list_fields = $list_fields;

            /* Comento lo de no sacar el __name__ en listados
            if (!empty($mobile_list_fields) && in_array('__name__', $mobile_list_fields)) {
                $key = array_search('__name__', $mobile_list_fields);
                unset($mobile_list_fields[$key]);
            }
            */
            $mobile_list_fields = array_values($mobile_list_fields);

            $modal_mobile_list_fields = !empty($origin['modal_mobile_fields']) ? explode(",", $origin['modal_mobile_fields']) : array();
            if (!empty($modal_mobile_list_fields)) {
                foreach ($modal_mobile_list_fields as $sub_key => $sub_value) {
                    /*
                    $sub_info = !empty($full_fields_info[$sub_value]) ? $full_fields_info[$sub_value] : array();
                    if (!empty($sub_info) && !empty($sub_info['type']) && in_array($sub_info['type'], array('related', 'related-combo'))) {
                        $sub_valuefield_name = $sub_value . '_value';
                        $modal_mobile_list_fields[$sub_key] = $sub_valuefield_name;
                    }
                    */
                    $modal_mobile_list_fields[$sub_key] = $sub_value;
                }
            } else
                $modal_mobile_list_fields = $mobile_list_fields;

            /* Comento lo de no sacar el __name__ en listados
            if (!empty($modal_mobile_list_fields) && in_array('__name__', $modal_mobile_list_fields)) {
                $key = array_search('__name__', $modal_mobile_list_fields);
                unset($modal_mobile_list_fields[$key]);
            }
            */
            $modal_mobile_list_fields = array_values($modal_mobile_list_fields);

            $export_fields = !empty($origin['export_fields']) ? explode(",", $origin['export_fields']) : $all_fields_name;
            $related_config = !empty($origin['related_config']) ? explode(",", $origin['related_config']) : array();
            $name_field = !empty($origin['name_field']) ? $origin['name_field'] : null;
            $entity_name_one = !empty($origin['entity_name_one']) ? EntityLib::__($origin['entity_name_one'],$trad_params) : '';
            $entity_name_multiple = !empty($origin['entity_name_multiple']) ? EntityLib::__($origin['entity_name_multiple'],$trad_params) : '';
            $real_entity = !empty($origin['real_entity']) ? $origin['real_entity'] : null;
            $related_info = array();
            if (!empty($related_config)) {
                foreach ($related_config as $key => $single_related) {
                    $aux = explode('#', $single_related);
                    $related_info[] = array(
                        'subtable' => $aux[0],
                        'subfield' => $aux[1],
                        'field' => $aux[2],
                    );
                }
            }

            $camposOcultarFinal = array();
            foreach ($camposOcultar as $sub_value) {
                $sub_info = !empty($full_fields_info[$sub_value]) ? $full_fields_info[$sub_value] : array();
                if (!empty($sub_info) && !empty($sub_info['type']) && in_array($sub_info['type'], array('related', 'related-combo'))) {
                    $sub_valuefield_name = $sub_value . '_value';
                    $camposOcultarFinal[] = $sub_value;
                    $camposOcultarFinal[] = $sub_valuefield_name;
                } else if (!empty($sub_info) && !empty($sub_info['type']))
                    $camposOcultarFinal[] = $sub_value;
            }

            $result['source'] = !empty($origin['real_entity']) ? $origin['real_entity'] : null;
            $result['table'] = $this->table;
            $result['fields'] = $full_fields_info;
            $result['exclude_fields'] = $camposOcultarFinal;
            $result['list_fields'] = $list_fields;
            $result['page_size_options'] = (!empty($origin['page_size_options']) && !empty($origin['page_size'])) ? $origin['page_size_options'] : '10,20,50,100';
            $result['page_size'] = !empty($origin['page_size']) ? intval($origin['page_size']) : 20;
            $result['filter_fields'] = $filter_fields;
            $result['modal_list_fields'] = $modal_list_fields;
            $result['modal_filter_fields'] = $modal_filter_fields;
            $result['mobile_list_fields'] = $mobile_list_fields;
            $result['modal_mobile_list_fields'] = $modal_mobile_list_fields;
            $result['default_order'] = $default_order;
            $result['export_fields'] = $export_fields;
            $result['related_info'] = $related_info;
            $result['name_field'] = $name_field;
            $result['entity_name_one'] = $entity_name_one;
            $result['entity_name_multiple'] = $entity_name_multiple;
            $result['entity_help'] = !empty($origin['entity_help']) ? EntityLib::getTextValueForRol($origin['entity_help']) : null;
            $result['entity_subtitle'] = !empty($origin['entity_subtitle']) ? EntityLib::getTextValueForRol($origin['entity_subtitle']) : null;
            $result['real_entity'] = !empty($origin['real_entity']) ? $origin['real_entity'] : null;
            $result['dimensions'] = $dimensiones_tabla;
            $result['autorefresh'] = !empty($origin['autorefresh']) ? intval($origin['autorefresh']) : 0;
            $result['force_open_filters'] = !empty($origin['force_open_filters']) ? 1 : 0;
            $result['pagination_top'] = !empty($origin['pagination_top']) ? 1 : 0;
            $result['pagination_bottom'] = !empty($origin['pagination_bottom']) ? 1 : 0;
            $result['custom_actions'] = !empty($origin['custom_actions']) ? json_decode($origin['custom_actions'], JSON_UNESCAPED_UNICODE) : array();
            $result['user_h_fields'] = $remove_fields_permisos;

            $user_translate_to_id = !empty($_SESSION['__lang_id__']) ? ($_SESSION['__lang_id__'] != 1 ? $_SESSION['__lang_id__'] : null) : null;
            if(!empty($user_translate_to_id))
            {
                $properties_traducible = array('label','help');
                foreach($result['fields'] as $campo_traducible => $aux_data_traducible) {
                    if (!empty($result['fields'][$campo_traducible])) {
                        foreach($properties_traducible as $pt) {
                            if (!empty($aux_data_traducible['__lang__']) &&
                                !empty($aux_data_traducible['__lang__'][$pt]) &&
                                !empty($aux_data_traducible['__lang__'][$pt][$user_translate_to_id]) &&
                                !empty($aux_data_traducible['__lang__'][$pt][$user_translate_to_id]['value'])) {
                                $result['fields'][$campo_traducible][$pt] = $result['fields'][$campo_traducible]['__lang__'][$pt][$user_translate_to_id]['value'];
                            }
                        }
                    }
                }
                
            }

            // Traducimos todos los términos del custom actions
            $custom_actions = array();
            if(!empty($result['custom_actions'])) {
                foreach ($result['custom_actions'] as $rca) {
                    $aux_ca = $rca;
                    if (array_key_exists('label', $aux_ca)) $aux_ca['label'] = EntityLib::__($aux_ca['label'],$trad_params);
                    if (array_key_exists('tooltip', $aux_ca)) $aux_ca['tooltip'] = EntityLib::__($aux_ca['tooltip'],$trad_params);
                    if (array_key_exists('confirm', $aux_ca)) $aux_ca['confirm'] = EntityLib::__($aux_ca['confirm'],$trad_params);
                    if (array_key_exists('disabled_text', $aux_ca)) $aux_ca['disabled_text'] = EntityLib::__($aux_ca['disabled_text'],$trad_params);
                    $custom_actions[] = $aux_ca;
                }
                $result['custom_actions'] = $custom_actions;
            }

            $result['class_for_lines'] = !empty($origin['class_for_lines']) ? $origin['class_for_lines'] : null;
            $result['condition_for_lines'] = !empty($origin['condition_for_lines']) ? $origin['condition_for_lines'] : null;
            if (!empty($relatedDefinitions)) {
                $result['related_def'] = $relatedDefinitions;
            }
            /*
            $result['global_actions'] = array(
                array('id' => 'add','tipo_icono' => 'google','icon' => 'plus','label' => 'Añadir','link' => '/'.$this->table.'/add'),
                array('id' => 'add','tipo_icono' => 'google','icon' => 'plus','label' => 'Eliminar','action' => 'delete'),
                array('id' => 'add','tipo_icono' => 'google','icon' => 'plus','label' => 'Validar','action' => 'validate','modal-msg' => '¿Está seguro de que desea validar?'),
            );
            */

            $result['__list_actions__'] = array();
            $result['__list_actions__'] = EntityLib::getListActions($this, array('single' => $result['entity_name_one'], 'plural' => $result['entity_name_multiple'], 'custom_actions' => $result['custom_actions'], 'disable_actions' => $accionesNoDisponibles));


            // Gestión de traducciones de etiquetas de campos
            $i18n = array();
            /*
            $searchForTranslate = '';
            foreach($full_fields_info as $field_id => $fieldInfo)
            {
                if(in_array($fieldInfo['type'],array('text','varchar'))) {
                    if (!empty($searchForTranslate)) $searchForTranslate .= ',';
                    $searchForTranslate .= $fieldInfo['id'];
                }
            }
            $translateSql = "SELECT * FROM _i18n_fields WHERE tabla = '__schema_fields' AND registro_id IN (".$searchForTranslate.")";
            $qRT = $this->database->querySelectAll($translateSql);
            if(!empty($qRT))
            {
                foreach($qRT as $trRecord)
                {
                    $field_translate = !empty($trRecord['campo']) ? $trRecord['campo'] : '';
                    $translation = !empty($trRecord['valor_txt']) ? $trRecord['valor_txt'] : (!empty($trRecord['valor_varchar']) ? $trRecord['valor_varchar'] : '');
                    $translation_lang_id = !empty($trRecord['idioma_id']) ? $trRecord['idioma_id'] : 0;
                    if(!empty($field_translate) && !empty($translation) && !empty($translation_lang_id))
                    {
                        if(empty($i18n[$field_translate]))
                            $i18n[$field_translate] = array();
                        $i18n[$field_translate]['lang'.$translation_lang_id] = $translation;
                    }
                }
            }
            */
        }

        if (!empty($i18n))
            $result['__i18n__'] = $i18n;

        // Cargamos el objeto en caché
        if (empty($_SESSION['__defs__'])) $_SESSION['__defs__'] = array();
        if (empty($_SESSION['__defs__'][$this->table])) $_SESSION['__defs__'][$this->table] = array('data' => $result, 'timestamp' => EntityLib::getTimestamp());
        return $result;
    }

    public function getDefinition($subaction_option = null)
    {
        if (empty($this->definition))
            $this->definition = $this->loadDefinition();
        return $this->definition;
    }

    /* Función que obtiene el nombre de una entidad */
    // PENDIENTE!
    private function getName($id)
    {
        $options = array(
            'filters' => array(
                'id' => array('=' => $id),
                'deleted' => array('=' => 0),
            ),
        );
        $entity = $this->getList($options);
        if (!empty($entity)) {
            echo '<pre>';
            print_r($entity);
            echo '</pre>';
        }


    }

    /* Función que obtiene los registros de un listado */
    public function getList($params = array())
    {
        $def = $this->getDefinition();
        
        $query_list_params = array();

        $fieldInfo = $def['fields'];
        $defaultOrder = !empty($def) && !empty($def['default_order']) ? $def['default_order'] : 'id DESC';
        $relatedFields = array();
        $relatedFieldsMultiple = array();
        $subtableFields = array();
        $galleryFields = array();
        $calendarFields = array();
        $translateFields = array();
        $group_by = !empty($params['group_by']) ? $params['group_by'] : array();

        if (!empty($params['select_all'])) {
            $params['page'] = 1;
            $params['pagesize'] = 999999;
            $params['fields'] = array('id');
            $params['ignore_related'] = true;
            $params['add_actions'] = false;
            if (!empty($def['name_field'])) {
                $nameFieldsForName = EntityLib::obtainAllConcatFields($def['name_field']);
                if (!empty($nameFieldsForName)) {
                    foreach ($nameFieldsForName as $nffn) {
                        if (!in_array($nffn, $params['fields']))
                            if (strpos($nffn, '"') === false)
                                $params['fields'][] = $nffn;
                    }
                }
            }
        }

        foreach ($fieldInfo as $fieldName => $fI) {
            if (in_array($fI['type'], array('related', 'related-combo')) && !empty($fI['related_option_info'])) {
                $aux = explode('#', $fI['related_option_info']);
                $is_multiple = !empty($fI['related_multiple']);
                if($is_multiple)
                {
                    $relatedFieldsMultiple[$fieldName] = array(
                        'table' => $aux[0],
                        'field' => $aux[1],
                        'destiny' => $aux[2],
                        'options' => !empty($fI['related_multiple_options']) ? json_decode($fI['related_multiple_options'],JSON_UNESCAPED_UNICODE) : array(),
                        'filters' => (!empty($fI['related_filters']) && !empty($fI['related_filters']['filters'])) ? $fI['related_filters']['filters'] : array(),
                        'calculado' => !empty($fI['calculado']),
                    );
                }
                else {
                    $relatedFields[$fieldName] = array(
                        'table' => $aux[0],
                        'field' => $aux[1],
                        'destiny' => $aux[2]
                    );
                }
            } else if (in_array($fI['type'], array('attachment'))) {
                $galleryFields[$fieldName] = array(
                    'table' => '_adjuntos_ficheros',
                    'field' => $fieldName,
                    'destiny' => 'adjuntos_id',
                    'attachment_type' => $fI['type'],
                );
            } else if (in_array($fI['type'], array('attachment_custom'))) {
                $galleryFields[$fieldName] = array(
                    'table' => $this->table.'_adjuntos_ficheros',
                    'field' => $fieldName,
                    'destiny' => 'adjuntos_custom_id',
                    'attachment_type' => $fI['type'],
                );
            }else if (in_array($fI['type'], array('gallery'))) {
                $galleryFields[$fieldName] = array(
                    'table' => '_adjuntos_galerias',
                    'field' => $fieldName,
                    'destiny' => 'adjuntos_id',
                    'attachment_type' => $fI['type'],
                );
            } else if (in_array($fI['type'], array('subtable','subtable-edit', 'comments')) && $this->is_main_rec) {
                if (!empty($fI['type'])) {
                    $subtableFields[] = $fieldName;
                }
            } else if (in_array($fI['type'], array('calendar')) && $this->is_main_rec) {
                if (!empty($fI['type'])) {
                    $calendarFields[] = $fieldName;
                }
            }

            if(!empty($_SESSION) && !empty($_SESSION['__languages__']) && !empty($fI['traducible']))
                $translateFields[] = $fieldName;
        }

        // Buscamos los campos de dimensión para establecer filtros automáticos
        $userSessionId = $this->user_id;
        $dimensionFields = $this->permisos_dimensiones;
        $extraDimFilters = array();
        if (!empty($dimensionFields) && !empty($userSessionId)) {
            foreach ($dimensionFields as $dF => $dim) {
                $filter_by_values = array();
                $load_all_from_user = !empty($_SESSION['__dimension__']) && isset($_SESSION['__dimension__'][$dim]) && isset($_SESSION['__dimension__'][$dim]);
                $load_all = !empty($_SESSION['__dimension__']) && !isset($_SESSION['__dimension__'][$dim]);
                $load_single = !empty($_SESSION['__dimension__']) && !empty($_SESSION['__dimension__'][$dim]);
                if ($dim === 'LOGGEDUSER') {
                    if (!empty($userSessionId))
                        $filter_by_values[] = $userSessionId;
                } else {
                    $filter_by_values = array();
                    if (!$load_all && $load_single) {
                        if(is_array($_SESSION['__dimension__'][$dim]))
                            $filter_by_values = $_SESSION['__dimension__'][$dim];
                        else
                            $filter_by_values[] = $_SESSION['__dimension__'][$dim];
                    } else if (!$load_all && $load_all_from_user) {
                        $queryDimForUser = "SELECT valor FROM _usuarios_dimensiones WHERE usuario_id = ? AND dimension = ? AND deleted = 0";
                        $query_params = array($userSessionId,$dim);
                        $dimValues = $this->database->querySelectAll($queryDimForUser,$query_params);
                        if (!empty($dimValues)) {
                            foreach ($dimValues as $dv) {
                                $filter_by_values[] = $dv['valor'];
                            }
                        }
                    }
                }
                if (!empty($filter_by_values))
                    $extraDimFilters[$dF] = array('IN' => $filter_by_values);
            }
        }
        
        /* Procesamiento para JOINS! */
        $joinSelect = '';
        $joinFieldForQuery = '';
        $joinInfo = array();
        $joinId = 1;
        //$nuevo_sistema_joins = $this->getTable() == 'reservas';
        $nuevo_sistema_joins = true;

        $finalParamsFilters = array();
        if ($nuevo_sistema_joins) {
            $process_entity_names = array();
            if (!empty($relatedFields)) {
                foreach ($relatedFields as $rfName => $rfData) {
                    $rfDataTable = $rfData['table'];
                    $no_real_field = !empty($fieldInfo) && !empty($fieldInfo[$rfName]) && !empty($fieldInfo[$rfName]['no_real']);
                    $multiple = !empty($fieldInfo) && !empty($fieldInfo[$rfName]) && !empty($fieldInfo[$rfName]['related_multiple']);
                    if (!$no_real_field && !$multiple) {
                        // PENDIENTE! - Para hacer filtros en las relaciones! De momento solo se dará en campos no reales, no se procesa el filtro!
                        if (strpos($rfDataTable, "[") !== false) {
                            $aux = explode("[", $rfDataTable);
                            $rfDataTable = $aux[0];
                        }
                        $process_entity_names[$rfName] = array(
                            'field' => $rfName,
                            'table' => $rfDataTable
                        );
                    }
                }
            }

            if (!empty($process_entity_names)) {
                $entity_names = $this->processEntityNames($process_entity_names);
                $joins = $this->addJoinsForNames($process_entity_names,$entity_names);
                $joinProcessed = array(
                    'names' => array(),
                    'joins' => array()
                );
                foreach($joins as $join_field => $join_data)
                {
                    if(!empty($join_data) && !empty($join_data['joins'])) {
                        foreach ($join_data['joins'] as $join_alias => $j) {
                            $join_table = $j['join_table'];
                            $join_condition = $j['join_condition'];
                            $joinProcessed['joins'][] = "LEFT JOIN `" . $join_table . "` `" . $join_alias . "` " . $join_condition;

                            if(!empty($params['add_extra_fields']) && !empty($params['add_extra_fields'][$join_field])) {
                                $add_join_field = $params['add_extra_fields'][$join_field];
                                foreach($add_join_field as $source_field => $destiny_field)
                                {
                                    $joinProcessed['names'][] = "`".$join_alias."`.".$source_field." AS `".$destiny_field."`";
                                    // Evitamos añadirlo más de una vez
                                    unset($params['add_extra_fields'][$join_field]);
                                }
                            }
                        }
                        $join_name_with_ifnull = "CASE WHEN `xxx`.`" . $join_field . "` IS NULL THEN NULL ELSE ";
                        $join_name = "CONCAT(";
                        $join_concat_str = '';
                        foreach ($join_data['join_formula'] as $join_concat_field) {
                            if (!empty($join_concat_str)) $join_concat_str .= ',';
                            $join_concat_str .= $join_concat_field;
                        }
                        $join_name .= $join_concat_str . ")";
                        $join_name_with_ifnull .= $join_name . " END";
                        $join_name_with_ifnull .= " AS `" . $join_field . '_value' . "`";
                        $joinProcessed['names'][] = $join_name_with_ifnull;
                    }
                }

            }
        }
        else {
            if (!empty($relatedFields)) {
                foreach ($relatedFields as $rfName => $rfData) {
                    $rfDataTable = $rfData['table'];
                    $no_real_field = !empty($fieldInfo) && !empty($fieldInfo[$rfName]) && !empty($fieldInfo[$rfName]['no_real']);
                    $multiple = !empty($fieldInfo) && !empty($fieldInfo[$rfName]) && !empty($fieldInfo[$rfName]['related_multiple']);
                    if (!$no_real_field && !$multiple) {
                        // PENDIENTE! - Para hacer filtros en las relaciones! De momento solo se dará en campos no reales, no se procesa el filtro!
                        if (strpos($rfDataTable, "[") !== false) {
                            $aux = explode("[", $rfDataTable);
                            $rfDataTable = $aux[0];
                        }

                        $joinAlias = 'jt' . $joinId;
                        $joinField_DestinyName = $rfName . '_value';
                        $joinField1 = $rfName;
                        //$joinField2 = $joinAlias.'.`'.$rfData['destiny'].'`';
                        $joinField2 = EntityLib::getConcatFieldsFromString($rfData['destiny'], $joinAlias);
                        //$joinField2 = EntityLib::getConcatFieldsFromStringv2($rfData['destiny'], $joinAlias, $rfDataTable);
                        $joinFieldSource = $joinAlias . '.`' . $rfData['field'] . '`';
                        $joinFieldForQuery = $joinField2 . " AS `" . $joinField_DestinyName . "`";
                        $joinSelect = "LEFT JOIN `" . $rfDataTable . "` AS " . $joinAlias . " ON (xxx.`" . $joinField1 . "` = " . $joinFieldSource . ")";
                        $joinInfo[$joinId] = array('join' => $joinSelect, 'fieldToAdd' => $joinFieldForQuery);
                        $joinId++;

                        if (!empty($params['filters'])) {
                            foreach ($params['filters'] as $filterField => $filterValue) {
                                $es_filtro_subtabla = strpos($filterField, $rfName . '.') !== false;
                                if ($es_filtro_subtabla) {
                                    $aux = explode('.', $filterField);
                                    $filterRealField = $aux[1];
                                    $finalParamsFilters[$joinAlias . '.' . $aux[1]] = $filterValue;
                                } else
                                    $finalParamsFilters[$filterField] = $filterValue;
                            }
                        }

                    }
                }
            }
        }

        if (!empty($finalParamsFilters)) {
            $params['filters'] = $finalParamsFilters;
        }


        $result = array();
        $data = array();
        $sql_fields = '*';
        $sql_with_options = '';
        $filters = !empty($params['filters']) ? $params['filters'] : array();
        $order = !empty($params['order']) ? $params['order'] : array();
        if (empty($order) && !empty($defaultOrder)) {
            $auxDefaultOrder = explode(',', $defaultOrder);
            if (!empty($auxDefaultOrder)) {
                foreach ($auxDefaultOrder as $dfCrit) {
                    $auxDefaultOrderCrit = explode(' ', $dfCrit);
                    $order[$auxDefaultOrderCrit[0]] = $auxDefaultOrderCrit[1];
                }
            }
        }

        // Forzamos filtro deleted = 0 SI NO se especifica ningún filtro en campo, que estaba comentado más abajo
        if ((!empty($filters) && !array_key_exists('deleted', $filters)) || empty($filters)) {
            $filters['deleted'] = array('=' => 0);
        }

        // Limpiamos _value en los filtros
        $processed_order = array();
        if (!empty($order)) {
            foreach ($order as $order_field => $order_dir) {
                // Ojo, solo para los _id_value por si acaso!
                if (strpos($order_field, '_id_value') !== false) {
                    $real_field = str_replace('_id_value', '_id', $order_field);
                    $processed_order[$real_field] = $order_dir;
                } else
                    $processed_order[$order_field] = $order_dir;
            }
            $order = $processed_order;
        }


        $page = !empty($params['page']) ? intval($params['page']) : 1;
        // Pillamos el pagesize, añadimos opción de por defecto para que coja el valor de config
        $pagesize = !empty($params['pagesize']) ? $params['pagesize'] : 0;
        if(!empty($pagesize) && $pagesize == 'default')
        {
            $pagesize = intval($def['page_size']);
        }
        else
        {
            $pagesize = intval($pagesize);
        }
        $include_count = !empty($params['include_count']) ? ($params['include_count'] === 1 || $params['include_count'] === true) : 0;
        //$include_possible_values = !empty($params['include_possible_values']) ? ($params['include_possible_values'] === 1 || $params['include_possible_values'] === true) : 0;
        //$include_all_possible_values = !empty($params['include_all_possible_values']) ? ($params['include_all_possible_values'] === 1 || $params['include_all_possible_values'] === true) : 0;
        $limit_text = !empty($params['limit_text']) ? ($params['limit_text'] === 1 || $params['limit_text'] === true) : 0;
        $count = 0;

        $filtersToAdd = $filters;

        if (!empty($extraDimFilters) && empty($params['ignore_dimension_filter']))
        {
            if(empty($this->ignore_dimension)) {
                //$filtersToAdd = array_merge($filtersToAdd, $extraDimFilters);
                // !IMPORTANTE! Si hay filtro por dimensión y se ha filtrado por el mismo campo, se hace una combinación de filtros en un AND
                // Ejemplo:
                // Filtramos por un ID = 3
                // Por dimensión tengo acceso solo a 4, 5 y 6
                // El filtro que se hace es un AND de las 2 condiciones, para obtener el ID deseado pero solo entre las opciones
                // En este caso seria (3 AND IN(4,5,6)), al no tener acceso no se encontrará ningún registro nunca
                foreach ($extraDimFilters as $dimFilterField => $dimFilterValue) {
                    if (!array_key_exists($dimFilterField, $filtersToAdd))
                        $filtersToAdd[$dimFilterField] = $dimFilterValue;
                    else {
                        $existing_filter = $filtersToAdd[$dimFilterField];
                        unset($filtersToAdd[$dimFilterField]);
                        $combined_filter = array(
                            '__union__' => 'AND',
                            '__conditions__' => array(
                                array($dimFilterField => $existing_filter),
                                array($dimFilterField => $dimFilterValue),
                            ),
                        );
                        $filtersToAdd[] = $combined_filter;
                    }
                }
            }
        }

        //$processedFilters = array();
        $allFilters = array();
        if (!empty($filtersToAdd)) {
            foreach ($filtersToAdd as $filterKey => $filterValue) {
                $add_filter = array();

                if (is_integer($filterKey)) {
                    $add_filter[$filterKey] = $filterValue;
                } else {
                    $is_enum_multiple = !empty($fieldInfo[$filterKey]) && !empty($fieldInfo[$filterKey]['type']) && $fieldInfo[$filterKey]['type'] === 'enum-multi';
                    $is_related_multiple = !empty($fieldInfo[$filterKey]) && !empty($fieldInfo[$filterKey]['type']) && !empty($fieldInfo[$filterKey]['related_multiple']);
                    $is_name_filter = $filterKey === '__name__';
                    if (!$is_enum_multiple && !$is_related_multiple && !$is_name_filter) {
                        $add_filter[$filterKey] = $filterValue;
                        //$processedFilters[$filterKey] = $filterValue;
                    } else if ($is_enum_multiple) {
                        if (!empty($filterValue['IN']) || !empty($filterValue['in'])) {
                            $values = !empty($filterValue['IN']) ? $filterValue['IN'] : array();
                            if (empty($values)) {
                                $values = !empty($filterValue['in']) ? $filterValue['in'] : array();
                            }
                            $new_filter_or_enum_multiple = array(
                                '__union__' => 'OR',
                                '__conditions__' => array(),
                            );
                            foreach ($values as $val) {
                                $new_filter_or_enum_multiple['__conditions__'][] = array($filterKey => array('LIKE' => '%' . $val . '%'));
                            }
                            $add_filter[] = $new_filter_or_enum_multiple;
                            //$processedFilters[] = $new_filter_or_enum_multiple;
                        } else {
                            $add_filter[$filterKey] = $filterValue;
                            //$processedFilters[$filterKey] = $filterValue;
                        }
                    } else if ($is_related_multiple) {
                        if (!empty($fieldInfo[$filterKey]['related_multiple_options'])) {
                            $related_multiple_options = json_decode($fieldInfo[$filterKey]['related_multiple_options'], true);
                            if (!empty($related_multiple_options)) {
                                $related_multiple_table = $related_multiple_options['table'];
                                $related_multiple_key = $related_multiple_options['key'];
                                $related_multiple_related = $related_multiple_options['related'];
                                $related_multiple_fields = $related_multiple_options['fields'];
                                $related_multiple_selected = array();
                                $related_multiple_obj = ClassLoader::getModelObject($related_multiple_table, false);
                                $related_multiple_opt = array(
                                    'filters' => array(
                                        'deleted' => array('=' => 0),
                                        $related_multiple_fields[$filterKey] => $filterValue,
                                    )
                                );
                                $related_multiple_values = $related_multiple_obj->getList($related_multiple_opt);
                                if (!empty($related_multiple_values) && !empty($related_multiple_values['data'])) {
                                    foreach ($related_multiple_values['data'] as $rmv) {
                                        $related_multiple_selected[] = $rmv[$related_multiple_key];
                                    }
                                }

                                $add_filter[$related_multiple_related] = array('IN' => $related_multiple_selected);
                                //$processedFilters[$related_multiple_related] = array('IN' => $related_multiple_selected);
                            }
                        }
                    } else if ($is_name_filter) {
                        $name_fields = !empty($def['name_field']) ? explode(',', $def['name_field']) : array('id');
                        $values = !empty($filterValue['like']) ? $filterValue['like'] : '';
                        if (empty($values) && !empty($filterValue['LIKE']))
                            $values = !empty($filterValue['LIKE']) ? $filterValue['LIKE'] : '';
                        $filtrable_name_fields = array();
                        foreach ($name_fields as $nf) {
                            if (substr($nf, 0, 1) != '"') {
                                $filtrable_name_fields[] = $nf;
                            }
                        }
                        if (!empty($filtrable_name_fields)) {
                            $new_filter_name = array(
                                '__union__' => 'OR',
                                '__conditions__' => array(),
                            );
                            foreach ($filtrable_name_fields as $fnf) {
                                $new_filter_name['__conditions__'][] = array($fnf => array('LIKE' => $values));
                            }
                            $add_filter[] = $new_filter_name;
                        }

                    }
                }

                if (!empty($add_filter)) {
                    $allFilters[] = $add_filter;
                }

            }


            $finalProcessedFilters = array();
            $combineFilters = array();
            if (!empty($allFilters)) {
                // Detectamos filtros que pueda haber repetidos
                foreach ($allFilters as $filter_key => $filter_step) {
                    foreach ($filter_step as $filter_step_key => $filter_step_values) {
                        // Si es un entero es un filtro complejo, OR, AND, etc.. este entra tal cual.
                        if (is_integer($filter_step_key)) {
                            $finalProcessedFilters[] = $filter_step_values;
                        } // Si no, es un campo, y hay que ver si hay que combinarlo con otro filtro antes de añadir
                        else {
                            if (empty($combineFilters[$filter_step_key])) {
                                $combineFilters[$filter_step_key] = array();
                            }
                            $combineFilters[$filter_step_key][] = $filter_step_values;
                        }
                    }
                }

                // Recorremos los filtros combinados, en caso de tener más de 1 hay que hacer un AND para tener en cuenta todas las condiciones.
                if (!empty($combineFilters)) {
                    foreach ($combineFilters as $combine_key => $combine_all_filters) {
                        if (!empty($combine_all_filters)) {
                            if (count($combine_all_filters) == 1) {
                                $finalProcessedFilters[$combine_key] = $combine_all_filters[0];
                            } else {
                                $new_filter_multiple = array(
                                    '__union__' => 'AND',
                                    '__conditions__' => array(),
                                );
                                foreach ($combine_all_filters as $combine_single_filter) {
                                    $new_filter_multiple['__conditions__'][] = array($combine_key => $combine_single_filter);
                                }
                                $finalProcessedFilters[] = $new_filter_multiple;
                            }
                        }
                    }
                }
            }
            $wheres_aux = EntityLib::whereGeneratorv2($finalProcessedFilters, $fieldInfo, 'xxx', 'AND');
            $wheres = $wheres_aux[0];
            $query_list_params = $wheres_aux[1];
            $sql_with_options .= " WHERE " . $wheres;
        }

        /*
        $is_first = true;
        $filters_added = false;
        for ($filter_round = 0; $filter_round < 2; $filter_round++) {
            if ($filter_round == 0) $filtersToAdd = $filters;
            else $filtersToAdd = $extraDimFilters;
            if (!empty($filtersToAdd)) {
                if (!$filters_added) {
                    $sql_with_options .= " WHERE";
                    $filters_added = true;
                }
                foreach ($filtersToAdd as $fta_field => $fta_filter) {

                    if (!$is_first) {
                        $sql_with_options .= " AND";
                    }

                    $fields = array();
                    $es_or = false;
                    $or_added_parentesis = false;
                    if ($fta_field === 'OR') {
                        $fields = $fta_filter;
                        $es_or = true;
                    } else
                        $fields[$fta_field] = $fta_filter;

                    foreach ($fields as $field => $filter) {
                        $es_filtro_subtabla = strpos($field, '.') !== false;

                        if ($es_or && $or_added_parentesis)
                            $sql_with_options .= " OR";

                        if (count($filter) > 1) {
                            $sql_with_options .= " (";
                        }
                        $first_subfilter = true;
                        foreach ($filter as $condition => $value) {

                            if (!$first_subfilter)
                                $sql_with_options .= " AND ";
                            else
                                $first_subfilter = false;

                            $is_md5 = $condition === 'MD5';
                            $value = $filter[$condition];

                            $condition = strtoupper($condition);

                            // En los campos que sean related filtraremos siempre por IN en vez de =
                            if(!empty($fieldInfo[$field]) && !empty($fieldInfo[$field]['type']))
                            {
                                $is_related = in_array($fieldInfo[$field]['type'],array('related'));
                                if($is_related) {
                                    $condition = 'IN';
                                    // En caso de que no se esté filtrando por un array directamente, forzamos
                                    if(!is_array($value))
                                        $value = array($value);
                                }
                            }

                            if (is_bool($value))
                                $value = ($value === true || $value === 1) ? 1 : 0;

                            if ($condition === 'IN') {
                                if (!empty($value)) {
                                    //$value = "(".implode(',',$value).")";
                                    $aux_str = "(";
                                    $aux_str_vals = "";
                                    foreach ($value as $auxval) {
                                        if (!empty($aux_str_vals))
                                            $aux_str_vals .= ",";
                                        if (is_numeric($auxval))
                                            $aux_str_vals .= $auxval;
                                        else
                                            $aux_str_vals .= "'" . $auxval . "'";
                                    }
                                    $aux_str .= $aux_str_vals . ")";
                                    $value = $aux_str;
                                } else {
                                    $condition = "IS";
                                    $value = "NULL AND xxx.`" . $field . "` IS NOT NULL";
                                }
                            } else if ($is_md5) {
                                $condition = '=';
                                $value = "MD5('" . $value . "')";
                            } else if (is_string($value)) {
                                if (is_null($value))
                                    $value = "NULL";
                                else
                                    $value = "'" . $value . "'";
                            } else {
                                if (is_null($value))
                                    $value = "NULL";
                            }

                            if ($es_or && !$or_added_parentesis) {
                                $sql_with_options .= " (";
                                $or_added_parentesis = true;
                            }

                            if ($es_filtro_subtabla)
                                $sql_with_options .= " " . $field . " " . $condition . " " . $value;
                            else
                                $sql_with_options .= " xxx.`" . $field . "` " . $condition . " " . $value;


                            if ($is_first)
                                $is_first = false;
                        }
                        if (count($filter) > 1) {
                            $sql_with_options .= " )";
                        }
                    }

                    if ($es_or) {
                        $sql_with_options .= " )";
                    }
                }
            }
        }
        */


        if (!empty($order)) {
            $is_first = true;
            $sql_with_options .= " ORDER BY ";
            foreach ($order as $field => $direction) {
                if (!$is_first)
                    $sql_with_options .= ", ";

                // Si estamos ordenando por el campo __expr__ en direction no va a venir la direccion, va a venir la expresión por la que queramos ordenar
                if($field == '__expr__')
                    $sql_with_options .= $direction;
                else
                    $sql_with_options .= " xxx.`" . $field . "` " . $direction;

                if ($is_first)
                    $is_first = false;
            }
        }

        $sql_with_options_no_limits = $sql_with_options;
        if (!empty($pagesize) && !empty($page)) {
            $record_inicial = $pagesize * ($page - 1);
            $sql_with_options .= " LIMIT " . $record_inicial . ", " . $pagesize;
        }

        // Por defecto, buscamos todos los campos
        $fields_to_search = "xxx.*";
        $add_joins_if_available = true;
        // Si se establecen campos, mostraremos solo los campos que deseamos obtener
        if (!empty($params['fields'])) {
            $fields_to_search = "";
            $add_joins_if_available = false;
            foreach ($params['fields'] as $fts) {
                if (!empty($fields_to_search))
                    $fields_to_search .= ",";
                $fields_to_search .= "xxx." . $fts;
            }
        }

        $sql = "SELECT " . $fields_to_search . " FROM `" . $this->table . "` xxx";
        if($nuevo_sistema_joins)
        {
            if(!empty($translateFields))
                {
                    // Si hay que traducir, metemos las consultas a los campos que se traducen. Añadimos al sistema de joins para values
                    if(empty($joinProcessed['names'])) $joinProcessed['names'] = array();
                    if(empty($joinProcessed['joins'])) $joinProcessed['joins'] = array();

                    foreach($_SESSION['__languages__'] as $lang_id => $lang_code)
                    {
                        $contador_traducciones = 0;
                        foreach($translateFields as $tf)
                        {
                            $contador_traducciones++;
                            $new_join_id = "`lang_".$lang_code."_".$contador_traducciones."`";
                            $lang_field_name = "`__lang_".$lang_id."_".$tf."`";
                            $lang_field_name_id = "`__lang_".$lang_id."_".$tf."_id`";
                            $new_join_name = "IFNULL(".$new_join_id.".`valor_varchar`,".$new_join_id.".`valor_txt`) AS ".$lang_field_name;
                            $new_join_name_id = $new_join_id.".id as ".$lang_field_name_id;
                            $new_join = "LEFT JOIN `_i18n_fields` ".$new_join_id." ON (";
                            $new_join .= $new_join_id.".`deleted` = 0 AND ";
                            $new_join .= $new_join_id.".`idioma_id` = ".$lang_id." AND ";
                            $new_join .= $new_join_id.".`tabla` = '".$this->table."' AND ";
                            $new_join .= $new_join_id.".`campo` = '".$tf."' AND ";
                            $new_join .= $new_join_id.".`registro_id` = `xxx`.`id`)";

                            $joinProcessed['names'][] = $new_join_name;
                            $joinProcessed['names'][] = $new_join_name_id;
                            $joinProcessed['joins'][] = $new_join;

                        }
                    }

                }

            if(!empty($joinProcessed))
            {
                $joinFields = '';
                $joinQuery = '';
                foreach($joinProcessed['names'] as $jpn)
                {
                    $joinFields .= "," . $jpn;
                }
                foreach($joinProcessed['joins'] as $j)
                {
                    $joinQuery .= " " . $j . " ";
                }
                if (!$add_joins_if_available) $joinFields = '';
                $sql = "SELECT " . $fields_to_search . $joinFields . " FROM `" . $this->table . "` xxx " . $joinQuery;
            }
        }
        else if (!empty($joinInfo)) {
            $joinFields = '';
            $joinQuery = '';
            foreach ($joinInfo as $joinField => $jI) {
                $joinFields .= "," . $jI['fieldToAdd'];
                $joinQuery .= " " . $jI['join'] . " ";
            }

            if (!$add_joins_if_available) $joinFields = '';
            $sql = "SELECT " . $fields_to_search . $joinFields . " FROM `" . $this->table . "` xxx " . $joinQuery;
        }

        if (!empty($sql_with_options))
            $sql .= " " . $sql_with_options;


        // Añadimos la agrupación, en caso de hacerse.
        // Importante, no está agrupando a nivel de la consulta inicial, obtiene un ID de grupo para cada registro encapsulando el select generado hasta ahora
        if(!empty($group_by))
        {
            $group_table = '`group_by`';
            $group_fields_add = '';
            $group_key_field = '';
            $group_name_field = '';
            $group_order_add = '';

            $group_key = !empty($group_by['id']) ? $group_by['id'] : array();
            $group_name = !empty($group_by['name']) ? $group_by['name'] : array();
            $group_order = !empty($group_by['order']) ? $group_by['order'] : array();

            // Si no se ha definido nombre, se dará como nombre el id del grupo
            if(empty($group_name) && !empty($group_key))
                $group_name = $group_key;

            if(!empty($group_key))
            {
                foreach($group_key as $gk)
                {
                    if(!empty($group_key_field))
                    {
                        $group_key_field .= ",'~#~',";
                    }
                    else {
                        $group_key_field = "CONCAT(";
                    }
                    $group_key_field .= $group_table.".".$gk;
                }
                $group_key_field .= ") AS `__group_id__`";
            }
            if(!empty($group_name))
            {
                foreach($group_name as $gn)
                {
                    if(!empty($group_name_field))
                    {
                        $group_name_field .= ",' - ',";
                    }
                    else {
                        $group_name_field = "CONCAT(";
                    }
                    $group_name_field .= $group_table.".".$gn;
                }
                $group_name_field .= ") AS `__group_name__`";
            }
            if(!empty($group_order))
            {
                foreach($group_order as $go => $go_sentido)
                {
                    if(!empty($group_order_add))
                    {
                        $group_order_add .= ",";
                    }
                    $group_order_add .= $group_table.".".$go." ".$go_sentido;
                };
                if (!empty($order)) {
                    foreach ($order as $field => $direction) {
                        if(!empty($group_order_add))
                        {
                            $group_order_add .= ",";
                        }
                        $group_order_add .= $group_table.".".$field." ".$direction;
                    }
                }
            }

            if(!empty($group_key_field))
                $group_fields_add .= $group_key_field;
            if(!empty($group_name_field))
            {
                if(!empty($group_fields_add)) $group_fields_add .= ", ";
                $group_fields_add .= $group_name_field;
            }

            if(!empty($group_fields_add)) $group_fields_add .= ", ";
            $group_fields_add .= $group_table.".*";
            $sql = "SELECT ".$group_fields_add." FROM (".$sql.") ".$group_table;
            if(!empty($group_order_add))
                $sql .= " ORDER BY ".$group_order_add;
        }

        // Obtenemos resultados y los formateamos correctamente para servirlos
        try {
            /*if($_SESSION['__user_id__'] === 1)
            {
                echo '<pre>';print_r($sql);echo '</pre>';
                echo '<pre>';print_r($query_list_params);echo '</pre>';
            }*/
            //EntityLib::debugSequence('['.$this->level.'] '.'Se invoca al getList de la entidad "'.$this->table.'"',true);
            $data = $this->database->getItems($sql,$query_list_params);
        }catch(Exception $e)
        {
            if(!empty($e->getMessage()))
            {
                $get_items_error = $e->getMessage();
                $errores_a_detectar = array(
                    "You have an error in your SQL syntax;",
                    "Unknown column '"
                );
                foreach($errores_a_detectar as $ed) {
                    if (strpos($get_items_error, $ed) === 0) {
                        $_SESSION['__last_query__'] = $sql;
                        throw new Exception('Se produjo un error procesando la información introducida. Compruebe los parámetros de entrada y vuelva a realizar la petición.');
                    }
                }
            }
            throw new Exception($e->getMessage());
        }

        if(!empty($def['user_h_fields']) && !$this->ignore_fields_permisos)
        {
            foreach($data as $key => $d)
            {
                foreach($def['user_h_fields'] as $user_h_field)
                    unset($data[$key][$user_h_field]);
            }
        }
        /*
        foreach ($fieldData['data'] as $field) {
            $allowed_for_user = true;
            if (!empty($field['permiso_requerido'])) {
                $aux = explode('#', $field['permiso_requerido']);
                if (!empty($aux[0]) && !empty($aux[1])) {
                    $field_permission_controller = $aux[0];
                    $field_permission_action = $aux[1];
                    if (!empty($_SESSION['__permisos__'])) {
                        $permisosController = $_SESSION['__permisos__'];
                        $check = $permisosController->checkPermissions($field_permission_controller, $field_permission_action);
                        $allowed_for_user = $check['allowed'];
                    }
                }
            }
        }
        */

        // Obtenemos los valores para los related multiples - Se obtiene de todos los datos de una para optimizar consultas
        $this->processItemRelatedMultiple($data, $relatedFieldsMultiple);

        $ignore_def = true;
        $ignore_lang_keys = array();
        if (!empty($data)) {
            // Procesamiendo individual de cada registro
            foreach ($data as $key => $rec) {
                $name_fields = array();
                foreach ($rec as $field => $value) {
                    $singleFieldInfo = (!empty($fieldInfo) && !empty($fieldInfo[$field])) ? $fieldInfo[$field] : array();
                    if (!empty($singleFieldInfo)) {
                        $processed_value = $this->exportField2API($singleFieldInfo, $value);
                        if ($singleFieldInfo['type'] === 'text' && $limit_text) {
                            $max_field_text_size = !empty($singleFieldInfo['max_size_truncate_lists']) ? $singleFieldInfo['max_size_truncate_lists'] : $this::_MAX_TEXT_SIZE;
                            if (strlen($processed_value) > $max_field_text_size) {
                                $processed_value = substr($processed_value, 0, $max_field_text_size);
                                $processed_value .= ' [...]';
                            }
                        }
                        $data[$key][$field] = $processed_value;
                    }
                    else {
                        // Gestionamos las traducciones posibles
                        if(strpos($field,'__lang_') !== false)
                        {
                            if(!in_array($field,$ignore_lang_keys)) {
                                $lang_translation_field_id = $field . '_id';
                                $lang_translation_id = !empty($rec[$lang_translation_field_id]) ? $rec[$lang_translation_field_id] : null;
                                $ignore_lang_keys[] = $lang_translation_field_id;
                                $aux_lang = str_replace('__lang_', '', $field);
                                $aux_lang = explode('_', $aux_lang);
                                $lang_code = $aux_lang[0];
                                // Verificar si el array tiene más de dos elementos
                                if (count($aux_lang) > 2) {
                                    $lang_field = implode('_', array_slice($aux_lang, 1));
                                } else {
                                    $lang_field = $aux_lang[1];
                                }

                                if (empty($data[$key]['__lang__'])) $data[$key]['__lang__'] = array();
                                if (empty($data[$key]['__lang__'][$lang_field])) $data[$key]['__lang__'][$lang_field] = array();
                                $data[$key]['__lang__'][$lang_field][$lang_code] = array(
                                    'id' => intval($lang_translation_id),
                                    'idioma_id' => intval($lang_code),
                                    'value' => $value
                                );
                                unset($data[$key][$field]);
                            }
                            else
                            {
                                unset($data[$key][$field]);
                            }

                        }
                    }
                }
                
                if (empty($params['ignore_related'])) {
                    if (!empty($galleryFields)) {
                        $this->processItemAdjuntos($data[$key], $galleryFields);
                    }

                    if (!empty($subtableFields)) {
                        foreach ($subtableFields as $sF) {

                            $subtableFieldData = $fieldInfo[$sF];
                            $subtableFieldDataOptions = $subtableFieldData['subtable_information'];
                            $subtableFieldOrderOptions = !empty($subtableFieldData['subtable_order']) ? $subtableFieldData['subtable_order'] : array();
                            $subtableFieldDataObj = ClassLoader::getModelObject($subtableFieldDataOptions['table'], true);
                            $subtableFieldDataObj->setLevel($this->level + 1);
                            $subtableFieldOrderInfo = !empty($subtableFieldDataOptions['order']) ? $subtableFieldDataOptions['order'] : 'id DESC';
                            $subtableFieldOrderInfo = explode(" ", $subtableFieldOrderInfo);
                            $subtableFieldOrderInfo = array($subtableFieldOrderInfo[0] => $subtableFieldOrderInfo[1]);
                            $subtableOptions = array(
                                'filters' => array(
                                    'deleted' => array('=' => 0),
                                ),
                                'order' => $subtableFieldOrderInfo,
                                'add_actions' => empty($params['add_actions']) ? 0 : 1,
                            );
                            if (!empty($subtableFieldDataOptions['destiny_table_relation_field'])) {
                                $subtableOptions['filters'][$subtableFieldDataOptions['destiny_table_relation_field']] = array('=' => $data[$key][$subtableFieldDataOptions['source_table_relation_field']]);
                            }
                            if (!empty($subtableFieldDataOptions['related_filters'])) {
                                foreach ($subtableFieldDataOptions['related_filters'] as $extra_field_related => $extra_filter_related) {
                                    $subtableOptions['filters'][$extra_field_related] = $extra_filter_related;
                                }
                            }

                            if (empty($subtableFieldData['calculado'])) {
                                $subtableData = $subtableFieldDataObj->getList($subtableOptions);
                                $subtableFieldRecords = array();
                                if (!empty($subtableData) && !empty($subtableData['data']))
                                    $subtableFieldRecords = $subtableData['data'];

                                if (empty($data[$key]['__related__']))
                                    $data[$key]['__related__'] = array();

                                if (empty($data[$key]['__related__'][$sF]))
                                    $data[$key]['__related__'][$sF] = $subtableFieldRecords;
                            }
                        }
                    }

                    if (!empty($calendarFields)) {
                        foreach ($calendarFields as $cF) {
                            $calendarFieldDataObj = ClassLoader::getModelObject('_calendarios', true);
                            $calendarFieldDataObj->setLevel($this->level + 1);
                            $calendarOptions = array(
                                'filters' => array(
                                    'deleted' => array('=' => 0),
                                    'origen' => array('=' => $this->table),
                                    'origen_id' => array('=' => $rec['id']),
                                    'fecha_inicio' => array('>=' => (intval(date('Y')) - 1) . '-01-01'),
                                ),
                                'order' => array('tipo' => 'DESC'),
                            );
                            $calendarData = $calendarFieldDataObj->getList($calendarOptions);

                            $calendarFieldDataObsObj = ClassLoader::getModelObject('_calendarios_observaciones', true);
                            $calendarFieldDataObsObj->setLevel($this->level + 1);
                            $calendarObsOptions = array(
                                'filters' => array(
                                    'deleted' => array('=' => 0),
                                    'origen' => array('=' => $this->table),
                                    'origen_id' => array('=' => $rec['id']),
                                ),
                            );
                            $calendarObsData = $calendarFieldDataObsObj->getList($calendarObsOptions);

                            $calendarFieldRecords = array();
                            if (!empty($calendarData) && !empty($calendarData['data']))
                                $calendarFieldRecords = $calendarData['data'];

                            $calendarObsRecords = array();
                            if (!empty($calendarObsData) && !empty($calendarObsData['data']))
                                $calendarObsRecords = $calendarObsData['data'];

                            $data[$key][$cF] = array(
                                '_fechas' => $calendarFieldRecords,
                                '_observaciones' => $calendarObsRecords,
                            );
                        }
                    }
                } else
                    $relatedFields = array();

                // Tras procesar salida de campos basica, llamamos al processItem individual de cada uno de los registros
                if (!empty($params['is_card'])) $this->add_single_events = true;
                if (!empty($params['is_wizard'])) $this->is_wizard = true;
                $ignore_process = !empty($params['ignore_process']);
                if (!$ignore_process)
                    $this->processItem($data[$key]);
                $custom_process = !empty($params['custom_process']) ? $params['custom_process'] : '';
                if(!empty($custom_process) && !$ignore_process) {
                    $this->$custom_process($data[$key]);
                }
                if (!empty($params['is_wizard'])) $this->is_wizard = false;
                if (!empty($params['is_card'])) $this->add_single_events = false;


                if (!empty($params['add_actions'])) {
                    $data[$key]['__actions__'] = $this->getListActions($data[$key]);
                }

            }

            // Obtenemos valores __name__ y _value para relateds - Se obtiene de todos los datos para optimizar consultas
            $this->getNames($data, $relatedFields);

            // Hacemos procesamiento en bulk, por motivos de eficiencia.
            $ignore_bulk_process = !empty($params['ignore_bulk_process']);
            if (!$ignore_bulk_process)
                $this->processBulkItem($data);
            $custom_bulk_process = !empty($params['custom_bulk_process']) ? $params['custom_bulk_process'] : '';
            if(!empty($custom_bulk_process) && !$ignore_bulk_process) {
                $this->$custom_bulk_process($data);
            }

            // En el select all solo queremos id y _name_
            if (!empty($params['select_all'])) {
                foreach ($data as $key => $d) {
                    foreach ($d as $df => $dv) {
                        if (!in_array($df, array('id', '__name__')))
                            unset($data[$key][$df]);
                    }
                }
            }
        }


        if (!empty($include_count)) {
            $sql_count = "SELECT COUNT(xxx.id) FROM `" . $this->table . "` xxx";
            if (!empty($sql_with_options_no_limits))
                $sql_count .= " " . $sql_with_options_no_limits;

            $countRes = $this->database->getItems($sql_count,$query_list_params);
            if (!empty($countRes))
                if (!empty($countRes[0])) {
                    $count = !empty($countRes[0]['COUNT(xxx.id)']) ? $countRes[0]['COUNT(xxx.id)'] : 0;
                }
            $result['count'] = intval($count);
        }

        // Si hay que devolver los diferentes valores posibles - Para ENUM / SELECT por defecto
        /*
        if(!empty($include_possible_values))
        {
            $possible_values = array();
            $def = $this->getDefinition();
            if(!empty($def['fields'])) {
                foreach ($def['fields'] as $field => $fieldInfo)
                {
                    $field_type = !empty($fieldInfo['type']) ? $fieldInfo['type'] : '';
                    $field_values = array();
                    $add_to_values = false;
                    switch($field_type)
                    {
                        case 'enum' :
                            $add_to_values = true;
                            $empty_values = array(array('id' => null,'name' => '-- Sin valor --'));
                            if(!empty($fieldInfo['enum_options'])) {
                                $field_values_2 = $fieldInfo['enum_options'];

                                $enumQuery = "SELECT DISTINCT(".$field.") as enum_id FROM ".$this->table;
                                if(!$include_all_possible_values)
                                    if(!empty($sql_with_options))
                                        $enumQuery .= " ".$sql_with_options_no_limits;

                                $enum_values = $this->database->querySelectAll($enumQuery);
                                if(!empty($enum_values))
                                {
                                    foreach($enum_values as $enum_key => $enum_data)
                                    {
                                        $enum_id = !empty($enum_data['enum_id']) ? $enum_data['enum_id'] : null;
                                        if(!empty($enum_id))
                                        {
                                            if(!empty($field_values_2[$enum_id]))
                                            {
                                                $field_values[] = array('id' => $enum_id,'name' => $field_values_2[$enum_id]);
                                            }
                                        }
                                        else
                                        {
                                            $field_values = array_merge($empty_values,$field_values);
                                        }
                                    }
                                }
                            }
                            break;
                        case 'related' :
                            $add_to_values = true;
                            $empty_values = array(array('id' => null,'name' => '-- Sin valor --'));
                            if(!empty($fieldInfo['related_options'])) {
                                $related_table = $fieldInfo['related_options'];
                                $relatedObj = ClassLoader::getModelObject($related_table,$this->database);
                                $relatedDef = $relatedObj->getDefinition();
                                $relatedNameField = !empty($relatedDef['name_field']) ? $relatedDef['name_field'] : 'id';
                                $relatedQuery = "SELECT id,".$relatedNameField." as name FROM ".$related_table." WHERE id IN (";
                                $relatedSubquery = "SELECT DISTINCT(".$field.") FROM ".$this->table;
                                if(!$include_all_possible_values)
                                    if(!empty($sql_with_options))
                                        $relatedSubquery .= " ".$sql_with_options_no_limits;
                                $relatedQuery .= $relatedSubquery;
                                $relatedQuery .= ")";
                                $relatedValues = $this->database->querySelectAll($relatedQuery);
                                foreach($relatedValues as $fvkey => $fvvalue)
                                {
                                    $relatedValues[$fvkey]['id'] = intval($fvvalue['id']);
                                }

                                $relatedNullQuery = $relatedSubquery." WHERE ".$field." IS NULL";
                                $relatedNullData = $this->database->querySelectAll($relatedNullQuery);
                                $related_with_null = !empty($relatedNullData);
                                if(!$related_with_null)
                                    $field_values = $relatedValues;
                                else
                                    $field_values = array_merge($empty_values,$relatedValues);

                            }
                            $add_to_values = true;
                            break;
                    }
                    if($add_to_values) {
                        $possible_values[$field] = $field_values;
                    }
                }
            }
            $result['values'] = $possible_values;
        }
        */

        /*
         * TRADUCCIONES - PENDIENTE IMPLEMENTAR, COMENTO BASE POR AHORA
         */
        /*
        $i18n = array();
        $searchForTranslate = '';
        // Recorremos los registros que vamos a mostrar para obtener sus IDs y optimizar en 1 consulta
        if(!empty($data))
        {
            foreach($data as $data_key => $data_values)
            {
                $data_id = !empty($data_values['id']) ? $data_values['id'] : 0;
                if(!empty($data_id))
                {
                    if (!empty($searchForTranslate)) $searchForTranslate .= ',';
                    $searchForTranslate .= $data_id;
                }
            }
            $translateSql = "SELECT * FROM _i18n_fields WHERE tabla = '".$this->table."' AND registro_id IN (".$searchForTranslate.")";
            $qRT = $this->database->querySelectAll($translateSql);
            if(!empty($qRT))
            {
                $aux_i18n = array();
                foreach($qRT as $qRTKey => $qRTvalue)
                {
                    $qRT_reg_id = $qRTvalue['registro_id'];
                    $qRT_trans_id = $qRTvalue['id'];
                    $qRT_lang_id = $qRTvalue['idioma_id'];
                    $qRT_field = $qRTvalue['campo'];
                    $qRT_trans = !empty($qRTvalue['valor_txt']) ? $qRTvalue['valor_txt'] : (!empty($qRTvalue['valor_varchar']) ? $qRTvalue['valor_varchar'] : '');
                    if(empty($aux_i18n[$qRT_reg_id])) $aux_i18n[$qRT_reg_id] = array();
                    if(empty($aux_i18n[$qRT_reg_id][$qRT_lang_id])) $aux_i18n[$qRT_reg_id][$qRT_lang_id] = array();
                    if(empty($aux_i18n[$qRT_reg_id][$qRT_lang_id][$qRT_field])) $aux_i18n[$qRT_reg_id][$qRT_lang_id][$qRT_field] = array();
                    $aux_i18n[$qRT_reg_id][$qRT_lang_id][$qRT_field] = array('id' => $qRT_trans_id,'translation' => $qRT_trans);
                }

                foreach($data as $dataKey => $dataValue)
                {
                    $dataId = $dataValue['id'];
                    $record_i18n = (!empty($aux_i18n) && !empty($aux_i18n[$dataId])) ? $aux_i18n[$dataId] : array();
                    if(!empty($record_i18n))
                        $data[$dataKey]['__i18n__'] = $record_i18n;
                }
            }
        }
        */
        $result['data'] = $data;
        $result['query'] = $sql;
        return $result;
    }

    /* Función que procesa una serie de registros para obtener la información de los related multiples */
    public function processItemRelatedMultiple(&$data, $related_multiple_fields)
    {
        if(!empty($related_multiple_fields)) {
            $related_multiple_fields_to_calculate = array();
            $related_all_data = array();
            foreach($related_multiple_fields as $rmf_key => $rmf_data)
            {
                if(empty($rmf_data['calculado']) && !empty($rmf_data['options']))
                {
                    //echo '<pre>';print_r($rmf_key);echo '</pre>';
                    $related_multiple_fields_to_calculate[$rmf_key] = $rmf_data;
                    $rmf_final_table = !empty($rmf_data['table']) ? $rmf_data['table'] : null;
                    $rmf_final_filters = !empty($rmf_data['filters']) ? $rmf_data['filters'] : array();
                    $rmf_intermediary_table = (!empty($rmf_data['options']) && $rmf_data['options']['table']) ? $rmf_data['options']['table'] : null;
                    $rmf_intermediary_field_related = (!empty($rmf_data['options']) && $rmf_data['options']['key']) ? $rmf_data['options']['key'] : null;
                    $rmf_relation_fields = (!empty($rmf_data['options']) && $rmf_data['options']['fields']) ? $rmf_data['options']['fields'] : null;
                    $rmf_related_field = (!empty($rmf_data['options']) && $rmf_data['options']['related']) ? $rmf_data['options']['related'] : null;
                    $rmf_final_field = null;
                    $rmf_is_ok = !empty($rmf_final_table) && !empty($rmf_intermediary_table) && !empty($rmf_intermediary_field_related) && !empty($rmf_relation_fields) && !empty($rmf_related_field);
                    if($rmf_is_ok) {
                        $intermediary_fields_str = "`int`." . $rmf_intermediary_field_related." AS `id`";

                        foreach ($rmf_relation_fields as $rrf_key => $rrf) {
                            if (!empty($intermediary_fields_str)) {
                                $intermediary_fields_str .= ",";
                            }
                            $intermediary_fields_str .= "`int`.`" . $rrf . "`";
                            if(is_null($rmf_final_field))
                            {
                                $rmf_final_field = $rrf;
                                $intermediary_fields_filter = "`int`.`" . $rmf_final_field."`";
                            }
                        }

                        $related_field_str = '';
                        foreach ($data as $data_key => $rec) {
                            if (!empty($related_field_str)) $related_field_str .= ',';
                            $related_field_str .= $rec[$rmf_related_field];
                        }
                        if(!empty($related_field_str)) {
                            $query = "SELECT " . $intermediary_fields_str . " FROM `" . $rmf_intermediary_table . "` `int` ";
                            $query .= "LEFT JOIN ".$rmf_final_table." `fin` ON (`fin`.`id` = ".$intermediary_fields_filter.") ";
                            $query .= "WHERE `int`." . $rmf_intermediary_field_related . " IN (" . $related_field_str . ") AND `int`.deleted = 0";
                            $query .= " AND `fin`.deleted = 0";
                            if(!empty($rmf_final_filters))
                            {
                                foreach($rmf_final_filters as $rff_field => $rff_filter)
                                {
                                    foreach($rff_filter as $rff_cond => $rff_value)
                                    {
                                        // Ojo, ignoramos si esta el campo 'ignore_dimension_filter'
                                        if(in_array($rff_filter,array('ignore_dimension_filter'))) {
                                            if (is_array($rff_value)) {
                                                $query .= " AND `fin`.`" . $rff_field . "` " . $rff_cond . " (";
                                                $subquery = '';
                                                foreach ($rff_value as $rff_in_value) {
                                                    $rff_in_value = "'" . $rff_in_value . "'";
                                                    if (!empty($subquery)) $subquery .= ",";
                                                    $subquery .= $rff_in_value;
                                                }
                                                $query .= $subquery . ")";
                                            } else {
                                                $is_var = substr($rff_value, 0, 1) == '$';
                                                if (!$is_var) {
                                                    $rff_value = "'" . $rff_value . "'";
                                                    $query .= " AND `fin`.`" . $rff_field . "` " . $rff_cond . " " . $rff_value;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            //echo '<pre>';print_r($query);echo '</pre>';
                            $related_multiple_fields[$rmf_key]['query'] = $query;
                            $related_data = $this->database->querySelectAll($query);
                            $related_processed_data = array();
                            $all_processed_data = array();
                            if(!empty($related_data))
                            {
                                foreach($related_data as $rd)
                                {
                                    if(!empty($rd[$rmf_final_field])) {
                                        if(empty($related_processed_data[$rd['id']]))
                                            $related_processed_data[$rd['id']] = array();
                                        $related_processed_data[$rd['id']][] = $rd[$rmf_final_field];
                                        if(!in_array($rd[$rmf_final_field],$all_processed_data))
                                            $all_processed_data[] = $rd[$rmf_final_field];
                                    }
                                }
                            }
                            if(!empty($all_processed_data))
                            {
                                $relatedObj = ClassLoader::getModelObject($rmf_final_table);
                                $options = array(
                                    'filters' => array('id' => array('IN' => $all_processed_data)),
                                    'select_all' => true,
                                    'ignore_dimension_filter' => true,
                                );
                                $related_final_list = $relatedObj->getList($options);
                                $related_final_list_processed = array();
                                if(!empty($related_final_list) && !empty($related_final_list['data']))
                                {
                                    foreach($related_final_list['data'] as $rfld)
                                    {
                                        $related_final_list_processed[$rfld['id']] = $rfld['__name__'];
                                    }
                                }
                                $related_all_data[$rmf_key] = array(
                                    'result_ids' => $related_processed_data,
                                    'related_ids' => $related_final_list_processed
                                );
                            }

                        }
                        $related_multiple_fields_to_calculate[$rmf_key] = $related_multiple_fields[$rmf_key];
                    }
                }
            }

            if(!empty($related_all_data))
            {
                // Recorremos los registros que vamos a procesar para generar la query eficiente
                foreach($data as $data_key => $rec)
                {
                    foreach($related_all_data as $rad_key => $rad_data) {
                        $name_values = null;
                        $id_values = (!empty($rad_data['result_ids']) && !empty($rad_data['result_ids'][$rec['id']])) ? $rad_data['result_ids'][$rec['id']] : array();
                        if(!empty($id_values))
                        {
                            foreach($id_values as $single_id)
                            {
                                if(!empty($rad_data['related_ids'][$single_id]))
                                {
                                    if(!empty($name_values))
                                        $name_values .= '~#~';
                                    else
                                        $name_values = '';
                                    $name_values .= $rad_data['related_ids'][$single_id];
                                }
                            }
                        }
                        $data[$data_key][$rad_key] = $id_values;
                        $data[$data_key][$rad_key.'_value'] = $name_values;
                    }
                }
            }
        }
    }

    /* Función que procesa un registro a la hora de obtenerse la información.*/
    public function processItem(&$data)
    {

    }

    /* Función que procesa todos los registros del set a la hora de obtenerse la información */
    // Similar a processItem pero con el set completo para optimizar consultas.
    public function processBulkItem(&$data)
    {

    }

    /* Función que procesa un registro a la hora de pasar por un ADD (preparar el registro).*/
    public function processBeforeAdd($extra_params = array())
    {
        $data = array();
        return $data;
    }

    public function getListActions($data)
    {
        $def = $this->getDefinition();
        $actions = array();

        // Estos 2 flags valen expresamente para no sacar los botones de ver / edit desde el listado.
        // Es necesario ya que puede que haya acceso al "view" por permisos pero no queramos añadir el icono en el propio listado
        $ignorar_edit = false;
        $ignorar_view = false;
        $permisosController = !empty($_SESSION['__permisos__']) ? $_SESSION['__permisos__'] : null;
        if (!is_null($permisosController)) {
            $permisos_listado = $permisosController->checkPermissions($this->api_call, 'list');
            if (!empty($permisos_listado)) {
                if (!empty($permisos_listado['disable_actions'])) {
                    $ignorar_view = in_array('view', $permisos_listado['disable_actions']);
                    $ignorar_edit = in_array('edit', $permisos_listado['disable_actions']);
                }
            }
        }

        // Botones custom primero
        if (!empty($def['custom_actions'])) {
            $custom_actions = !empty($def['custom_actions']) ? $def['custom_actions'] : array();
            if (!empty($custom_actions)) {
                foreach ($custom_actions as $ca) {
                    $add_to_record = empty($ca['if']);
                    $ca['head'] = !empty($ca['head']) ? 1 : 0;
                    $ca['hide_in_list'] = array_key_exists('show_in_list', $ca) ? $ca['show_in_list'] == 0 : false;
                    $ca['show_in_sublist'] = array_key_exists('show_in_sublist', $ca) ? !empty($ca['show_in_sublist']) : false;
                    // Si no se ha definido que se muestre en listados PERO si que se oculte en sublistados, forzamos a que no se muestre en sublistados!
                    if(!array_key_exists('show_in_sublist', $ca) && !empty($ca['hide_in_list']))
                        $ca['show_in_sublist'] = false;
                    $ca['allow_in_add'] = !empty($ca['allow_in_add']) ? 1 : 0;
                    if (!empty($ca['if'])) {
                        $aux_filters = explode(',', $ca['if']);
                        if(!empty($aux_filters)) {
                            $incumple_algun_filtro = false;
                            foreach ($aux_filters as $aux_filter) {
                                $aux = explode('=', $aux_filter);
                                if (!empty($aux)) {
                                    $cumple_filtro = false;
                                    $field_condition = $aux[0];
                                    $field_value = '' . $aux[1];
                                    $field_source = '' . !empty($data[$field_condition]) ? $data[$field_condition] : '';
                                    if ($field_value === "NOT NULL") {
                                        $cumple_filtro = !empty($field_source);
                                    } else if ($field_value === "NULL") {
                                        $cumple_filtro = empty($field_source);
                                    } else {
                                        if (strpos($field_value, '||') !== false) {
                                            $field_value_or_conditions = explode('||', $field_value);
                                            $cumple_filtro = in_array($field_source, $field_value_or_conditions);
                                        } else
                                            $cumple_filtro = ($field_value == $field_source) || (empty($field_value) && empty($field_source));
                                    }
                                    if(!$cumple_filtro) $incumple_algun_filtro = true;
                                }
                            }
                            if(!$incumple_algun_filtro) $add_to_record = true;
                        }
                    }
                    if ($add_to_record && !empty($ca['solo_propios'])) {
                        $add_to_record = $data['user_add_id'] == $_SESSION['__user_id__'];
                    }

                    // Separamos hide_in_list / show_in_sublist / disable_in_sublist
                    if ($add_to_record && !empty($ca['hide_in_list']) && $this->level == 1) {
                        $add_to_record = false;
                    }
                    if ($add_to_record && empty($ca['show_in_sublist']) && $this->level > 1) {
                        $add_to_record = false;
                    }
                    if ($add_to_record && !empty($ca['disable_in_sublist']) && $this->level > 1) {
                        $add_to_record = false;
                    }
                    // Comprobamos si se ha definido que tiene que haber una dimensión en concreto
                    if($add_to_record && !empty($ca['dimension']))
                    {
                        $add_to_record = (!empty($_SESSION) && !empty($_SESSION['__dimension__']) && !empty($_SESSION['__dimension__'][$ca['dimension']]));
                    }
                    if ($add_to_record && empty($ca['head']) && empty($ca['allow_in_add'])) {
                        if (!empty($ca) && (empty($ca['permiso']) || (!empty($ca['permiso']) && $this->getAllow($ca['permiso']))) && $add_to_record) {
                            if (!empty($ca['label']))
                                $ca['label'] = EntityLib::__($ca['label']);
                            if (!empty($ca['tooltip']))
                                $ca['tooltip'] = EntityLib::__($ca['tooltip']);
                            $actions[] = $ca;
                        }
                    }
                }
            }
        }

        // Ver - Editar - Borrar, últimos botones siempre
        if ($this->crud_allow_view && !$ignorar_view)
            $actions[] = array('id' => 'view', 'tipo_icono' => 'google', 'icon' => 'visibility', 'label' => EntityLib::__('CRUD_VIEW_LABEL'), 'link' => '/' . $this->api_call . '/view/' . $data['id'], 'crud' => 1);
        if ($this->crud_allow_edit && !$ignorar_edit)
            $actions[] = array('id' => 'edit', 'tipo_icono' => 'google', 'icon' => 'edit', 'label' => EntityLib::__('CRUD_EDIT_LABEL'), 'link' => '/' . $this->api_call . '/edit/' . $data['id'], 'crud' => 1,'color' => 'add');
        if ($this->crud_allow_delete)
            $actions[] = array('id' => 'delete', 'tipo_icono' => 'google', 'icon' => 'delete', 'label' => EntityLib::__('CRUD_DELETE_LABEL'), 'action' => 'delete', 'crud' => 1,'color' => 'delete');


        $this->processActionByRecordValues($actions,$data);

        return $actions;
    }

    /* Función para procesar las acciones según valores de registro (se programa en cada entidad) */
    public function processActionByRecordValues(&$actions,$rec)
    {
    }

    /* Función para bloquear expresamente acciones de add / edit según el estado del (se programa en cada entidad) */
    public function allowActionByRecord($action,&$rec)
    {
        // Por defecto true
        return true;
    }
    public function allowActionByRecordInDef($action,&$rec,$definition)
    {
        // Por defecto true
        return true;
    }

    /*
    Función que procesa un registro para obtener información adicional ampliada genérica
    Datos que se cargarán aquí: galerías, ficheros adjuntos, campos calculados si se acaban incluyendo
    */
    public function processItemAdjuntos(&$data, $galleryFields)
    {
        //echo '<pre>';print_r($galleryFields);echo '</pre>';
        if (!empty($galleryFields)) {
            foreach ($galleryFields as $gField => $gData) {
                $galeria_id = (!empty($data) && !empty($data[$gField])) ? $data[$gField] : 0;
                $adjuntos = array();
                if (!empty($galeria_id)) {
                    $adjuntosObj = ClassLoader::getModelObject($gData['table'], true);
                    $options = array(
                        'filters' => array(
                            'deleted' => array('=' => 0),
                            $gData['destiny'] => array('=' => $galeria_id),
                        ),
                        'order' => array(
                            'order' => 'DESC',
                        ),
                        'add_actions' => 1,
                    );
                    $adjuntosData = $adjuntosObj->getList($options);
                    if (!empty($adjuntosData) && !empty($adjuntosData['data']))
                        $adjuntos = $adjuntosData['data'];

                    if (empty($data['__related__'])) {
                        $data['__related__'] = array();
                    }

                    $g_type = !empty($gData['attachment_type']) ? $gData['attachment_type'] : '';
                    if(in_array($g_type,array('attachment_custom'))) {
                        $adjuntos_custom_table = $this->table.'_adjuntos_ficheros';
                        $data['__related__'][$adjuntos_custom_table] = $adjuntos;
                    }
                    else
                        $data['__related__'][$gField] = $adjuntos;
                }

            }
        }

    }

    /* Función que obtiene un único registro */
    public function getById($id, $getOptions = self::_DEFAULT_GETBYID_OPTIONS)
    {
        foreach (self::_DEFAULT_GETBYID_OPTIONS as $o_key => $o_value) {
            if (!isset($getOptions[$o_key]))
                $getOptions[$o_key] = $o_value;
        }

        $filters = array(
            'deleted' => array('=' => 0),
            'id' => array('=' => $id)
        );
        $options = $getOptions;
        $options['filters'] = $filters;
        if (!empty($getOptions['is_card'])) {
            $options['related'] = 1;
            $options['include_all_possible_values'] = 1;
        }

        $list_data = $this->getList($options);
        $record = array();
        if (!empty($list_data) && !empty($list_data['data'])) {
            $record = $list_data['data'][0];
        }

        return $record;
    }

    /* Función que exporta datos de un listado en CSV*/
    public function export2Pdf($params = array())
    {
        /*
        $_csv_field_separator = ';';
        $_csv_record_separator = "\n";
        $listOptions = array();
        if (!empty($params['filters'])) $listOptions['filters'] = $params['filters'];
        if (!empty($params['order'])) $listOptions['order'] = $params['order'];
        $listOptions['page'] = 1;
        $listOptions['pagesize'] = 99999999;
        $listOptions['convert'] = true;
        $listOptions['limit_text'] = false;
        $listOptions['include_count'] = false;
        $listOptions['filters']['deleted'] = array('=' => 0);
        $result = $this->getList($listOptions);
        $data = array();
        if (!empty($result) && !empty($result['data'])) $data = $result['data'];
        */

        //Generas el PDF con los datos del $data
        $pdfdata = array();
        return $pdfdata;

    }

    /* Función que exporta datos de un listado en CSV*/
    public function export2Csv($params = array())
    {
        $_csv_field_separator = ';';
        $_csv_record_separator = "\n";
        $listOptions = array();
        if (!empty($params['filters'])) $listOptions['filters'] = $params['filters'];
        if (!empty($params['order'])) $listOptions['order'] = $params['order'];
        $listOptions['page'] = 1;
        $listOptions['pagesize'] = 99999999;
        $listOptions['convert'] = true;
        $listOptions['limit_text'] = false;
        $listOptions['include_count'] = false;
        $listOptions['filters']['deleted'] = array('=' => 0);
        $result = $this->getList($listOptions);
        $data = array();
        if (!empty($result) && !empty($result['data'])) $data = $result['data'];

        $def = $this->getDefinition();
        //echo '<pre>';print_r($def);echo '</pre>';
        //echo '<pre>';print_r($data);echo '</pre>';

        $csvString = '';
        if (!empty($def['fields'])) {
            $export_fields = !empty($def['export_fields']) ? $def['export_fields'] : array();
            $headerString = '';
            foreach ($def['fields'] as $field_id => $fieldInfo) {
                $label = $fieldInfo['label'];
                if (!empty($headerString))
                    $headerString .= $_csv_field_separator;
                if (empty($label)) $label = $field_id;
                $headerString .= $label;
            }
            $headerString .= $_csv_record_separator;
            $bodyString = '';
            if (!empty($data) && !empty($export_fields)) {
                foreach ($data as $dk => $dv) {
                    $recordString = '';
                    foreach ($export_fields as $ef) {
                        $fieldInfo = array();
                        if (!empty($def['fields']) && !empty($def['fields'][$ef]))
                            $fieldInfo = $def['fields'][$ef];
                        $recordValue = isset($dv[$ef]) ? $dv[$ef] : '';
                        $recordValue = $this->exportField2HumanValue($fieldInfo, $recordValue);
                        if (!empty($recordString))
                            $recordString .= $_csv_field_separator;
                        $recordString .= $recordValue;
                    }
                    /*foreach($dv as $dv_field => $dv_value)
                    {
                        $fieldInfo = array();
                        if(!empty($def['fields']) && !empty($def['fields'][$dv_field]))
                            $fieldInfo = $def['fields'][$dv_field];
                        $dv_value = $this->exportField2HumanValue($fieldInfo,$dv_value);
                        if(!empty($recordString))
                            $recordString .= $_csv_field_separator;
                        $recordString .= $dv_value;
                    }
                    */

                    $bodyString .= $recordString . $_csv_record_separator;
                }
            }
            $csvString = $headerString . $bodyString;
        }
        return $csvString;
    }

    /* Require PHPSpreadSheet */
    public function requirePhpSpreadSheet()
    {
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Spreadsheet.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/IOFactory.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Shared/File.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Exception.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/IReader.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/BaseReader.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Exception.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/Namespaces.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/IReadFilter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/DefaultReadFilter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/ReferenceHelper.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Security/XmlScanner.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Settings.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/Calculation.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/Category.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/Engine/CyclicReferenceStack.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/Engine/Logger.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/Engine/BranchPruner.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Theme.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/IComparable.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Worksheet.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Pane.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/AutoFilter/Column.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Shared/StringHelper.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Collection/CellsFactory.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Collection/Cells.php');
        require_once(__DIR__ . '/lib/PsrSimpleCache/CacheInterface.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Collection/Memory/SimpleCache3.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/PageSetup.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/PageMargins.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/HeaderFooter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/SheetView.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Protection.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Dimension.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/RowDimension.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/ColumnDimension.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/AutoFilter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/AutoFilter/Column/Rule.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/AutoFit.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/NumberFormat/Formatter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Shared/Font.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Shared/Drawing.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Document/Properties.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Shared/IntOrFloat.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Document/Security.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Supervisor.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Style.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Font.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Color.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Fill.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Borders.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Border.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Alignment.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/NumberFormat.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Protection.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/BaseParserClass.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/Styles.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/Theme.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/Properties.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/RgbTint.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Shared/Date.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/ConditionalStyles.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Conditional.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/ConditionalFormatting/ConditionalFormattingRuleExtension.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Validations.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/DefinedName.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/NamedRange.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/AddressRange.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/Coordinate.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/SheetViews.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/SheetViewOptions.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/ColumnAndRowAttributes.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/Functions.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/Cell.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/Hyperlink.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/DataType.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/IgnoredErrors.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/IValueBinder.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/DefaultValueBinder.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/TableReader.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Table.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Table/TableStyle.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Table/Column.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/AutoFilter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Iterator.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/PageSetup.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/Hyperlinks.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/CellAddress.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/SharedFormula.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/CellReferenceHelper.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/WorkbookView.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/RowIterator.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Row.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/CellIterator.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/RowCellIterator.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/BaseDrawing.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Drawing/Shadow.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Drawing.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Comment.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/RichText/RichText.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/RichText/ITextElement.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/RichText/TextElement.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/RichText/Run.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Helper/Size.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Cell/DataValidation.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Reader/Xlsx/DataValidations.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Comment.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/RichText/ITextElement.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/RichText/RichText.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/RichText/TextElement.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/RichText/Run.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/BaseDrawing.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Drawing/Shadow.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Worksheet/Drawing.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Helper/Size.php');

        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/IWriter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/BaseWriter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Exception.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/WriterPart.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Chart.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/FunctionPrefix.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/Token/Stack.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/MathTrig/Sum.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Calculation/Exception.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Chart/DataSeries.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Comments.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/ContentTypes.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/DocProps.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Drawing.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Rels.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/RelsRibbon.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/RelsVBA.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/StringTable.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Style.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Theme.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Table.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Workbook.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/Worksheet.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/HashTable.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Shared/XMLWriter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/DefinedNames.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/Xlsx/AutoFilter.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/ZipStream0.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/ZipStream2.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Writer/ZipStream3.php');
        require_once(__DIR__ . '/lib/PhpSpreadsheet/Style/Alignment.php');
        require_once(__DIR__ . '/lib/ZipStream/OperationMode.php');
        require_once(__DIR__ . '/lib/ZipStream/CompressionMethod.php');
        require_once(__DIR__ . '/lib/ZipStream/File.php');
        require_once(__DIR__ . '/lib/ZipStream/GeneralPurposeBitFlag.php');
        require_once(__DIR__ . '/lib/ZipStream/Version.php');
        require_once(__DIR__ . '/lib/ZipStream/Zs/ExtendedInformationExtraField.php');
        require_once(__DIR__ . '/lib/ZipStream/PackField.php');
        require_once(__DIR__ . '/lib/ZipStream/LocalFileHeader.php');
        require_once(__DIR__ . '/lib/ZipStream/Time.php');
        require_once(__DIR__ . '/lib/ZipStream/CentralDirectoryFileHeader.php');
        require_once(__DIR__ . '/lib/ZipStream/EndOfCentralDirectory.php');
        require_once(__DIR__ . '/lib/ZipStream/ZipStream.php');
    }

    /* Require BoxSpout */
    public function requireBoxSpout(){
        require_once(__DIR__ . '/lib/Box/Spout/Common/Type.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Helper/FileSystemHelperInterface.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Helper/FileSystemHelper.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Helper/Escaper/EscaperInterface.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Helper/Escaper/XLSX.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Helper/GlobalFunctionsHelper.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Helper/CellTypeHelper.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Manager/OptionsManagerInterface.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Manager/OptionsManagerAbstract.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Creator/HelperFactory.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Entity/Row.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Entity/Cell.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Entity/Style/Style.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Entity/Style/Color.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Exception/SpoutException.php');
        require_once(__DIR__ . '/lib/Box/Spout/Common/Exception/IOException.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/SheetInterface.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/ReaderInterface.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/ReaderAbstract.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/IteratorInterface.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/Common/XMLProcessor.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/Common/Creator/ReaderFactory.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/Common/Creator/InternalEntityFactoryInterface.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/Common/Creator/ReaderEntityFactory.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/Common/Entity/Options.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/OptionsManager.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/StyleManager.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Creator/HelperFactory.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Creator/ManagerFactory.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Creator/InternalEntityFactory.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/SharedStringsCaching/CachingStrategyFactory.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/SharedStringsCaching/CachingStrategyInterface.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/SharedStringsCaching/InMemoryStrategy.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/WorkbookRelationshipsManager.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/SharedStringsManager.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/SheetManager.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Manager/SharedStringsCaching/FileBasedStrategy.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Helper/CellValueFormatter.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Helper/CellHelper.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Sheet.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/RowIterator.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/SheetIterator.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/CSV/Reader.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/XLSX/Reader.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/Wrapper/XMLInternalErrorsHelper.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/Wrapper/XMLReader.php');
        require_once(__DIR__ . '/lib/Box/Spout/Reader/Common/Manager/RowManager.php');
    }
    /* Funcion para leer fichero */
    public function readBoxFile($fichero,$read_options = array())
    {
        $this->requireBoxSpout();
        $required_header = (!empty($read_options) && !empty($read_options['required_header'])) ? $read_options['required_header'] : array();
        $include_header = (!empty($read_options) && (!empty($read_options['include_header']) || !empty($read_options['required_header']))) ? true : false;
        try {
            $reader = \Box\Spout\Reader\Common\Creator\ReaderEntityFactory::createXLSXReader();
            $reader->open($fichero);

            $datos = array();
            $header = array();
            $i = 0;
            foreach ($reader->getSheetIterator() as $hoja) {
                if ($hoja->isActive()) {
                    foreach ($hoja->getRowIterator() as $celda) {
                        $celdas = [];
                        foreach ($celda->getCells() as $cell) {
                            $celdas[] = $cell->getValue();
                        }
                        if ($i == 0 && $include_header)
                            $header = array_filter($celdas);
                        else
                            $datos[] = $celdas;

                        if($include_header && $i == 0 && !empty($required_header))
                        {
                            if($header !== $required_header)
                            {
                                throw new Exception('La cabecera del fichero no es correcta. Las columnas deben ser: '.json_encode($required_header,JSON_UNESCAPED_UNICODE));
                            }
                        }

                        $i++;
                    }
                }

            }
            $reader->close();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        return array(
            'header' => $header,
            'data' => $datos
        );
    }

    /* Función que exporta datos de un listado en EXCEL*/
    public function export2Xls($params = array())
    {
        $base_url = EntityLib::getConfig('app_url');
        $this->requirePhpSpreadSheet();
        $def = $this->getDefinition();
        $export_fields = !empty($def['export_fields']) ? $def['export_fields'] : $def['list_fields'];
        $listOptions['filters'] = array();
        if (!empty($params['filters'])) $listOptions['filters'] = $params['filters'];
        if (!empty($params['order'])) $listOptions['order'] = $params['order'];
        $listOptions['page'] = 1;
        $listOptions['pagesize'] = 99999999;
        $listOptions['convert'] = true;
        $listOptions['limit_text'] = false;
        $listOptions['include_count'] = false;
        $listOptions['filters']['deleted'] = array('=' => 0);
        $data = $this->getList($listOptions);
        $array_imprimir = array(
            'header' => array(),
            'data' => array(),
        );

        // Recorremos campos a exportar para añadir el HEADER
        foreach ($export_fields as $field_export) {
            $field_info = !empty($def['fields'][$field_export]) ? $def['fields'][$field_export] : null;
            $array_imprimir['header'][] = $field_info['label'];
        }
        if (!empty($data['data'])) {
            foreach ($data['data'] as $key => $rec) {
                $values_to_export = array();
                foreach ($export_fields as $field_export) {
                    $value = !empty($rec[$field_export]) ? $rec[$field_export] : '';
                    $field_info = !empty($def['fields'][$field_export]) ? $def['fields'][$field_export] : null;
                    $type_field = $field_info['type'];
                    $export_value = $value;
                    switch ($type_field) {
                        case 'related' :
                        case 'related-combo' :
                            // Verificar si $export_value es un array
                            $related_table = !empty($field_info['related_options']) ? $field_info['related_options'] : throw new Exception('No ha definido bien la estructura de la tabla para exportar');
                            if (is_array($export_value)) {
                                // Fix por si hay filtros, no tenido en cuenta
                                if(strpos($related_table,'[') !== false)
                                {
                                    $aux_table_name = explode('[',$related_table);
                                    $related_table = $aux_table_name[0];
                                }
                                $object_related = ClassLoader::getModelObject($related_table, true);
                                $export_value_final = '';
                                foreach ($export_value as $value) {
                                    $data = $object_related->getById($value);
                                    $export_value_final .= $data['__name__'] . ',';
                                }
                                $export_value_final = rtrim($export_value_final, ',');
                                $export_value=$export_value_final;
                            } else {
                                // Si no es un array, asignar $export_value directamente
                                $export_value = isset($rec[$field_export . '_value']) ? $rec[$field_export . '_value'] : $rec[$field_export];
                            }
                            // Si el campo es de múltiples relacionados, reemplazar el separador especial
                            if (!empty($field_info['related_multiple'])) {
                                $export_value = str_replace('~#~', ', ', $export_value);
                            }
                            break;
                        case 'enum' :
                            $export_value = !empty($field_info['enum_options'][$value]) ? $field_info['enum_options'][$value] : '';
                            if (strpos($export_value, '#') !== false) {
                                $aux = explode('#', $export_value);
                                $export_value = $aux[0];
                            }
                            break;
                        case 'file' :
                            $export_value = '';
                            $json_data = !empty($rec[$field_export]) ? json_decode($rec[$field_export], true) : array();
                            if (!empty($json_data['path'])) {
                                $export_value = '@@@link@@@' . $json_data['path'];
                            }
                            break;
                        case 'date' :
                            if (!empty($value)) {
                                $export_value = substr($value, 8, 2) . '/' . substr($value, 5, 2) . '/' . substr($value, 0, 4);
                            }
                            break;
                        case 'datetime' :
                            if (!empty($value)) {
                                $export_value = substr($value, 8, 2) . '/' . substr($value, 5, 2) . '/' . substr($value, 0, 4) . ' ' . substr($value, 11, 2) . ':' . substr($value, 14, 2) . ':' . substr($value, 17);
                            }
                            break;
                        case 'text' :
                        case 'varchar' :
                            $export_value = '@@@text@@@' . (!empty($value) ? $value : '');
                            break;
                        case 'html' :
                            $export_value = '@@@text@@@' . (!empty($value) ? strip_tags($value) : '');
                            break;
                        case 'decimal':
                        case 'int' :
                            $export_value = strval($rec[$field_export]);
                            break;
                        default :
                            break;
                    }
                    $values_to_export[] = $export_value;
                }
                $array_imprimir['data'][] = $values_to_export;
            }
        }

        $resultado = array();
        // Recorremos registros para añadir
        try {

            if (empty($array_imprimir['data']))
                throw new Exception('No se puede generar el fichero de exportación porque no hay registros que cumplan las condiciones introducidas en los filtros.');

            // Creamos hoja
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

            // Le ponemos el ajuste de texto a todas las celdas por defecto
            $spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(true);

            // Rellenamos la cabecera y establecemos negrita
            $column = 1;
            $row = 'A';
            foreach ($array_imprimir['header'] as $key => $data_header) {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setCellValue($row . $column, $data_header);
                $row++;
            }
            $final_row = $row;
            $final_row--;
            $spreadsheet->getActiveSheet()->getStyle("A1:" . $final_row . "1")->getFont()->setBold(true);

            // Rellenamos los datos
            $column = 2;
            foreach ($array_imprimir['data'] as $key => $data_body) {
                $row = 'A';
                foreach ($data_body as $key2 => $value) {
                    $is_link = $is_text = false;
                    if(is_string($value)){
                        $is_link = strpos($value, '@@@link@@@') !== false;
                        $is_text = strpos($value, '@@@text@@@') !== false;
                    }
                    if ($is_text) {
                        $value = substr($value, 10);
                    }
                    if ($is_text) {
                        $sheet->getStyle($row . $column)->getNumberFormat()->setFormatCode("@");
                        $sheet->setCellValueExplicit($row . $column, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                    else if ($is_link) {
                        $value = str_replace('@@@link@@@',$base_url.'/',$value);
                        $sheet->setCellValue($row . $column, 'Descargar');
                        $sheet->getCell($row . $column)->getHyperlink()->setUrl($value);
                    }
                    else
                        $sheet->setCellValue($row . $column, $value);

                    $row++;
                }
                $column++;
            }

            // Establecemos en todas las celdas vertical center
            $final_row = $row;
            $final_row--;
            $final_column = $column;
            $final_column--;
            $range = 'A1:' . $final_row . $final_column;
            $style = $sheet->getStyle($range);
            $style->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            // Ajustamos en todas las columnas el autotamaño
            for ($i = 'A'; $i != $spreadsheet->getActiveSheet()->getHighestColumn(); $i++) {
                $spreadsheet->getActiveSheet()->getColumnDimension($i)->setAutoSize(true);
            }

            $spreadsheet
                ->getProperties()
                ->setCreator("Agencia Ekiba");
            $fileName = "Exportación " . $this->getApiCall() . " " . date('YmdHis') . ".xlsx";
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . urlencode($fileName) . '"');
            ob_start();
            $writer->save('php://output');
            $contenido = ob_get_clean();
            $base64 = base64_encode($contenido);
            $pdf_base64 = $base64;
            if (!empty($pdf_base64)) {
                $resultado['base64'] = $pdf_base64;
                $resultado['nombre'] = $fileName;
            } else {
                throw new Exception('Error generando el fichero XLSX');
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        return $resultado;
    }


    /*
     * Función para extraer la información de ficheros del array de datos, en caso de haberla, y preparar su información ordenada para gestionar la subida tras el guardado
     * Los ficheros se procesan ANTES del guardado. Si el guardado va bien, se suben los ficheros y se vuelve a ajustar el registro con la ruta final.
     * Supone hacer un UPDATE adicional pero se evitan problemas derivados de colocar los ficheros antes, es más probable que falle el SQL que escribir el fichero.
     * Además, para saber la ruta final y organizarlo por carpetas, es necesario hacer el guardado antes para saber el ID asociado.
     */
    private function processFileInputs(&$data)
    {
        $def = $this->getDefinition();
        $file_inputs = array();
        if (!empty($def['fields'])) {
            foreach ($def['fields'] as $fieldName => $fieldInfo) {
                if ($fieldInfo['type'] === 'file') {
                    $file_content = $fieldName . '_new';
                    $file_name = $fieldName . '_new_name';
                    $file_ext = $fieldName . '_new_ext';
                    $file_size = $fieldName . '_new_size';
                    $file_delete = $fieldName . '_deleted';

                    if (!empty($data[$file_content])) {
                        $file_inputs[$fieldName] = array(
                            'content' => $data[$file_content],
                            'name' => $data[$file_name],
                            'ext' => $data[$file_ext],
                            'size' => floatval($data[$file_size]),
                        );
                        // No machacamos valor del file
                        unset($data[$fieldName]);
                    } else if (!empty($data[$file_delete])) {
                        // Forzamos file null
                        $data[$fieldName] = null;
                    } else {
                        // No machacamos valor del file
                        unset($data[$fieldName]);
                    }
                    if (array_key_exists($file_name, $data)) unset($data[$file_name]);
                    if (array_key_exists($file_ext, $data)) unset($data[$file_ext]);
                    if (array_key_exists($file_size, $data)) unset($data[$file_size]);
                    if (array_key_exists($file_content, $data)) unset($data[$file_content]);
                    if (array_key_exists($file_delete, $data)) unset($data[$file_delete]);
                }
            }
        }
        return $file_inputs;
    }

    private function processRelatedInputs(&$data)
    {
        $def = $this->getDefinition();
        if (!empty($def['fields'])) {
            foreach ($def['fields'] as $fieldName => $fieldInfo) {
                if (in_array($fieldInfo['type'], array('related', 'related-combo'))) {
                    $value_input = $fieldName . '_value';
                    unset($data[$value_input]);
                }
            }
        }
    }

    private function processSubtableInputs(&$data)
    {
        $def = $this->getDefinition();
        $gallery_inputs = array();
        $attachment_inputs = array();
        $subtable_inputs = array();
        $calendar_inputs = array();
        if (!empty($def['fields'])) {
            foreach ($def['fields'] as $fieldName => $fieldInfo) {
                //if (!empty($data[$fieldName])) {
                //if ($fieldInfo['type'] === 'gallery' || $fieldInfo['type'] === 'attachment') {
                if (in_array($fieldInfo['type'], array('gallery', 'attachment','attachment_custom'))) {
                    if(!empty($data[$fieldName]))
                        $gallery_inputs[$fieldName] = array('field' => $fieldInfo, 'values' => $data[$fieldName]);
                    unset($data[$fieldName]);
                } else if (in_array($fieldInfo['type'], array('subtable','subtable-edit', 'comments'))) {
                    if(!empty($data[$fieldName]))
                        $subtable_inputs[$fieldName] = array('field' => $fieldInfo, 'values' => !empty($data[$fieldName]) ? $data[$fieldName] : array());
                    unset($data[$fieldName]);
                } else if (in_array($fieldInfo['type'], array('calendar'))) {
                    if(!empty($data[$fieldName]))
                        $calendar_inputs[$fieldName] = array('field' => $fieldInfo, 'values' => $data[$fieldName]);
                    unset($data[$fieldName]);
                }
                //}
            }
        }
        return array(
            'gallery' => $gallery_inputs,
            'subtable' => $subtable_inputs,
            'subtable-edit' => $subtable_inputs,
            'calendar' => $calendar_inputs,
        );
    }

    public function validate($data)
    {
        $errors = array();
        return $errors;
    }

    public function save(&$data)
    {

        $def = $this->getDefinition();

        // Quitamos campos protegidos por si acaso!
        $protected_fields = array(
            '__actions__',
            '__name__',
            '__from__',
            '__app__',
            '__edited__',
            '__deleted__',
        );
        foreach($protected_fields as $pf)
        {
            if (array_key_exists($pf,$data))
                unset($data[$pf]);
        }

        // Nos guardamos y quitamos campo de traducciones para meter después (por si no existe el registro)
        $traducciones = array();
        if(array_key_exists('__lang__',$data))
        {
            $traducciones = $data['__lang__'];
            unset($data['__lang__']);
        }

        $ignore_system_fields = false;
        if (!empty($data['__ignore_system_fields__']) && !empty($data['__ignore_system_fields__'])) {
            unset($data['__ignore_system_fields__']);
            $ignore_system_fields = true;
        }

        $skip_before_save = false;
        if (!empty($data['__skip_before_save__']) && !is_null($data['__skip_before_save__'])) {
            $skip_before_save = true;
            unset($data['__skip_before_save__']);
        }
        $skip_after_save = false;
        if (!empty($data['__skip_after_save__']) && !is_null($data['__skip_after_save__'])) {
            $skip_after_save = true;
            unset($data['__skip_after_save__']);
        }

        // Gestionamos si hay que devolver el objeto completo
        $return_full_object = false;
        if(!empty($data['__return_full_object__']) && !is_null($data['__return_full_object__']))
        {
            $return_full_object = true;
            unset($data['__return_full_object__']);
        }

        $watermark = null;
        if (isset($data['__watermark__'])) {
            if (!empty($data['__watermark__']) && !is_null($data['__watermark__'])) {
                $watermark = $data['__watermark__'];
            }
            unset($data['__watermark__']);
        }

        // Limpiamos campos calculados y preparamos los related-multiple
        $related_multiple_fields = array();
        if (!empty($def['fields'])) {
            foreach ($def['fields'] as $fieldName => $fieldInfo) {
                if (!empty($fieldInfo['calculado'])) {
                    unset($data[$fieldName]);
                }
                else {
                    if ($fieldInfo['type'] == 'related' && !empty($fieldInfo['related_multiple']) && !empty($fieldInfo['related_multiple_options'])) {
                        $related_multiple_fields[$fieldName] = $fieldInfo;
                    }
                }
            }
        }

        // Obtenemos la versión previa del mismo registro, por si hay que detectar cambios o tener los valores previos
        $previousData = array();
        if (!empty($data['id'])) {
            $previousData = $this->getById($data['id']);
            $previousWatermark = null;
            if (!empty($previousData['datetime_upd']))
                $previousWatermark = $previousData['datetime_upd'];
            // Comprobamos también fecha de borrado. Nos quedaremos con la mayor de upd / del
            if (!empty($previousData['datetime_del'])) {
                $previousWatermark2 = $previousData['datetime_del'];
                if ($previousWatermark2 > $previousWatermark)
                    $previousWatermark = $previousWatermark2;
            }

            // En previousWatermark tenemos la última fecha de modificación del registor.
            // Si esta es superior al watermark pasado por la API quiere decir que el registro tiene una fecha de modificación mayor a esta fecha, que es cuando se cargó la ficha.
            if (!empty($watermark) && !empty($previousWatermark) && ($previousWatermark > $watermark)) {
                throw new Exception('El registro que quiere actualizar ha sido modificado por otro usuario / proceso. Recargue la página e inténtelo de nuevo');
            }
        }

        if(!empty($related_multiple_fields))
            $this->beforeSaveRelatedMultiple($data,$related_multiple_fields);

        $validationErrors1 = array();
        if (!$skip_before_save) {
            $validationErrors1 = $this->beforeSave($data);
        }
            $validationErrors2 = $this->validate($data);
        $validationErrors = !empty($validationErrors1) ? $validationErrors1 : array();
        if (!empty($validationErrors2)) {
            $validationErrors = array_merge($validationErrors1, $validationErrors2);
        }

        if (!empty($validationErrors)) {
            $message = json_encode($validationErrors);
            $apiError = new ApiException($message);
            $apiError->setErrors($validationErrors);
            throw $apiError;
        }

        $sourceData = $data;
        $this->processRelatedInputs($data);
        $newFiles = $this->processFileInputs($data);

        // Obtenemos los input de tipo subtabla (galería, adjuntos, subtabla, calendar)
        // Nos separarmos los 4 tipos porque tienen cosas en común pero funcionarán diferente.
        $subtableInputs = $this->processSubtableInputs($data);
        $galleryInputs = array();
        $calendarInputs = array();
        if (!empty($subtableInputs)) {
            foreach ($subtableInputs as $subInputType => $subInputInfo) {
                foreach ($subInputInfo as $subInputField => $subInputDetail) {
                    if ($subInputType === 'gallery') {
                        $galleryId = !empty($previousData[$subInputField]) ? $previousData[$subInputField] : 0;
                        $subtableInputs[$subInputType][$subInputField]['__id__'] = $galleryId;
                        $galleryInputs[$subInputField] = $subInputDetail;
                        $galleryInputs[$subInputField]['__id__'] = $galleryId;
                        unset($subtableInputs[$subInputType][$subInputField]);
                    } else if ($subInputType === 'attachment') {
                        $attachmentId = !empty($previousData[$subInputField]) ? $previousData[$subInputField] : 0;
                        $subtableInputs[$subInputType][$subInputField]['__id__'] = $attachmentId;
                        $galleryInputs[$subInputField] = $subInputDetail;
                        $galleryInputs[$subInputField]['__id__'] = $attachmentId;
                        unset($subtableInputs[$subInputType][$subInputField]);
                    } else if ($subInputType === 'attachment_custom') {
                        $attachmentCustomId = !empty($previousData[$subInputField]) ? $previousData[$subInputField] : 0;
                        $subtableInputs[$subInputType][$subInputField]['__id__'] = $attachmentCustomId;
                        $galleryInputs[$subInputField] = $subInputDetail;
                        $galleryInputs[$subInputField]['__id__'] = $attachmentCustomId;
                        unset($subtableInputs[$subInputType][$subInputField]);
                    } else if ($subInputType === 'calendar') {
                        unset($subtableInputs[$subInputType][$subInputField]);
                        $calendarInputs[$subInputField] = $subInputDetail;
                    }
                }
            }
        }
        if (!empty($subtableInputs['subtable']))
            $subtableInputs = $subtableInputs['subtable'];

        foreach ($subtableInputs as $subtableField => $subtableDetail) {
            if (!empty($subtableDetail['values'])) {
                $related_subtable = $subtableDetail['field']['subtable_information']['table'];
                if (empty($data['__related__']))
                    $data['__related__'] = array();
                if (empty($data['__related__'][$related_subtable]))
                    $data['__related__'][$related_subtable] = array();
                $data['__related__'][$related_subtable] = array_merge($data['__related__'][$related_subtable], $subtableDetail['values']);
            }
        }

        $relateds = array();
        if (!empty($data['__related__'])) {
            $relateds = $data['__related__'];
            unset($data['__related__']);
        }

        $is_delete = false;
        $rec_id = !empty($data['id']) ? $data['id'] : 0;
        $type = '';
        $timestamp = date('Y-m-d H:i:s');
        if (empty($rec_id)) {
            $type = 'INSERT';
            if (!$ignore_system_fields) {
                if (!empty($this->user_id) && empty($data['user_add_id']))
                    $data['user_add_id'] = $this->user_id;
                if (empty($data['datetime_add']))
                    $data['datetime_add'] = $timestamp;
            }
            $sql_aux = EntityLib::generateSqlv2(!empty($this->source_table) ? $this->source_table : $this->table, $type, $data, array(), $this->getDefinition());
            $sql = $sql_aux[0];
            $sql_params = $sql_aux[1];
        } else {
            $type = 'UPDATE';
            if (!$ignore_system_fields) {
                if (!empty($this->user_id))
                    $data['user_upd_id'] = $this->user_id;
                $data['datetime_upd'] = $timestamp;
            }

            if (!empty($data['deleted'])) {
                if (!$ignore_system_fields) {
                    if (!empty($this->user_id))
                        $data['user_del_id'] = $this->user_id;
                    $data['datetime_del'] = $timestamp;
                }
                $is_delete = true;
            }
            unset($data['id']);
            $sql_aux = EntityLib::generateSqlv2(!empty($this->source_table) ? $this->source_table : $this->table, $type, $data, array('id' => $rec_id), $this->getDefinition());
            $sql = $sql_aux[0];
            $sql_params = $sql_aux[1];
        }

        if (!empty($rec_id))
            $data['id'] = $rec_id;

        $qR = $this->database->query($sql,$sql_params);

        $affectedId = 0;
        if (!empty($rec_id))
            $affectedId = $rec_id;
        else if (!empty($qR)) {
            $aux = $this->database->getLastModifiedId();
            $affectedId = !empty($aux) ? $aux : 0;
        }

        if (empty($affectedId)) {
            throw new Exception(EntityLib::__('API_QUERY_ERROR', array($sql)));
        }

        $updateData = array();
        if (!empty($newFiles)) {
            $strAffectedId = ''.$affectedId;
            $aux_path_id = str_split($strAffectedId);
            $new_path_id = '';
            foreach($aux_path_id as $pid)
            {
                if(!empty($new_path_id)) $new_path_id .= '/';
                $new_path_id .= $pid;
            }

            $subdomain_path = EntityLib::getConfig('app_subdomain');
            if(empty($subdomain_path)) {
                $baseUploadPath = 'media/' . (!empty($this->real_entity) ? $this->real_entity : $this->table) . '/' . $new_path_id . '/';
            }
            else
            {
                $baseUploadPath = 'media/'.$subdomain_path.'/' . (!empty($this->real_entity) ? $this->real_entity : $this->table) . '/' . $new_path_id . '/';
            }
            $uploaded = false;
            foreach ($newFiles as $fieldName => $newFile) {
                /*
                if (empty($newFile['name']))
                    $newFile['name'] = date('YmdHis') . '-' . EntityLib::getGuidv4();
                else
                    $newFile['name'] = date('YmdHis') . '-' . $newFile['name'];
                */
                // Ojo, generamos SIEMPRE un nuevo nombre de imagen porque si se subían varios con el mismo nombre el fichero era el mismo!
                $newFile['name'] = date('YmdHis') . '-' . EntityLib::getGuidv4();
                // Por dar un nombre corto, hasheo el nombre por si es muy largo.
                $filename = sha1($newFile['name']);
                if (!empty($newFile['ext']))
                    $filename .= '.' . $newFile['ext'];
                $fullPath = $baseUploadPath . $filename;
                $fullPath = mb_strtolower($fullPath);
                EntityLib::prepareFolder($baseUploadPath);


                $file_field_info = (!empty($def) && !empty($def['fields']) && !empty($def['fields'][$fieldName])) ? $def['fields'][$fieldName] : array();
                $file_field_options = !empty($file_field_info['file_options']) ? json_decode($file_field_info['file_options'], JSON_UNESCAPED_UNICODE) : array();
                $optimize_options = !empty($file_field_options['optimize']) ? $file_field_options['optimize'] : array();
                $optimize_file = !empty($optimize_options);
                EntityLib::uploadFile($fullPath, $newFile['content']);
                $json_field_content = array(
                    'path' => $fullPath,
                    'name' => mb_strtolower($newFile['name']),
                    'ext' => mb_strtolower($newFile['ext']),
                    'size' => $newFile['size'],
                    'datetime' => date('Y-m-d H:i:s'),
                );
                if ($optimize_file) {
                    $optimized_file = EntityLib::createOptimizedFileWithOptions($json_field_content, $optimize_options);
                    $optimized_public_path = $optimized_file['relative'];
                    $optimized_full_path = $optimized_file['absolute'];
                    $json_field_content['path'] = $optimized_public_path;
                    $json_field_content['size'] = filesize($optimized_full_path);
                    if (!empty($optimize_options['webp'])) {
                        $json_field_content['name'] = str_replace('.' . $json_field_content['ext'], '.webp', $json_field_content['name']);
                        $json_field_content['ext'] = 'webp';
                    }
                }
                if ($optimize_file) {
                    if (!empty($optimize_options['webp']) && array_key_exists('keep', $optimize_options) && empty($optimize_options['keep'])) {
                        $real_source_path = __DIR__ . '/../' . $fullPath;
                        if (file_exists($real_source_path) && $real_source_path !== $optimized_full_path) unlink($real_source_path);
                    }
                }
                $updateData[$fieldName] = json_encode($json_field_content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $uploaded = true;
            }
        }


        // Comprobamos galerías para guardar, si procede
        // Galerías y adjuntos funcionan igual
        // En la tabla padre habrá un campo ID que hace referencia al ID de la tabla de _adjuntos
        // En la tabla _adjuntos_ficheros se subirán los diferentes ficheros, asociados a la galería
        // Si es el primer adjunto / imagen de la galería, se crea un _adjunto nuevo.
        $relatedSubtables = array();
        if (!empty($affectedId)) {
            if (!empty($galleryInputs)) {
                //$galleryObj = ClassLoader::getModelObject('_adjuntos');
                //$galleryItemObj = ClassLoader::getModelObject('adjuntos_ficheros');
                foreach ($galleryInputs as $galleryField => $galleryOptions) {
                    $galleryFieldInfo = $galleryOptions['field'];
                    $galleryFieldValues = $galleryOptions['values'];
                    $galleryFieldSourceId = $galleryOptions['__id__'];
                    // Creamos la entidad galería si no existe
                    if (!empty($galleryFieldValues)) {
                        if (empty($galleryFieldSourceId)) {

                            if ($galleryFieldInfo['type'] === 'gallery') {
                                $galleryObj = ClassLoader::getModelObject('_adjuntos');
                                $gallerySourceData = array(
                                    'nombre' => 'Galería ' . $this->table . ' ' . $affectedId,
                                    'type' => 'galeria'
                                );
                            } else if ($galleryFieldInfo['type'] === 'attachment') {
                                $galleryObj = ClassLoader::getModelObject('_adjuntos');
                                $gallerySourceData = array(
                                    'nombre' => 'Adjuntos ' . $this->table . ' ' . $affectedId,
                                    'type' => 'adjunto'
                                );
                            } else if ($galleryFieldInfo['type'] === 'attachment_custom') {
                                $galleryObj = ClassLoader::getModelObject($this->table.'_adjuntos');
                                $gallerySourceData = array(
                                    'nombre' => 'Adjuntos personalizados ' . $this->table . ' ' . $affectedId,
                                    'type' => 'adjunto'
                                );
                            }
                            $galleryFieldSourceId = $galleryObj->save($gallerySourceData);
                            $updateData[$galleryField] = $galleryFieldSourceId;
                        }
                        foreach ($galleryFieldValues as $gfKey => $gfData) {

                            if ($galleryFieldInfo['type'] === 'gallery') {
                                $galleryFieldValues[$gfKey]['adjuntos_id'] = $galleryFieldSourceId;
                                $galleryItemObj = ClassLoader::getModelObject('adjuntos_galerias');
                            } else if ($galleryFieldInfo['type'] === 'attachment') {
                                $galleryFieldValues[$gfKey]['adjuntos_id'] = $galleryFieldSourceId;
                                $galleryItemObj = ClassLoader::getModelObject('adjuntos_ficheros');
                            } else if ($galleryFieldInfo['type'] === 'attachment_custom') {
                                $galleryFieldValues[$gfKey]['adjuntos_custom_id'] = $galleryFieldSourceId;
                                $galleryItemObj = ClassLoader::getModelObject($this->table.'_adjuntos_ficheros');
                            }
                            $galleryItemId = $galleryItemObj->save($galleryFieldValues[$gfKey]);
                        }
                    }
                }
            }

            if (!empty($calendarInputs)) {
                foreach ($calendarInputs as $calendarField => $calendarOptions) {
                    $valores = !empty($calendarOptions['values']) && !empty($calendarOptions['values']['_fechas']) ? $calendarOptions['values']['_fechas'] : array();
                    {
                        foreach ($valores as $valor_calendar) {
                            $data_save_calendar = $valor_calendar;
                            if (empty($valor_calendar['id'])) {
                                $data_save_calendar['origen'] = $this->table;
                                $data_save_calendar['origen_id'] = $affectedId;
                            }
                            $calendarObj = ClassLoader::getModelObject('_calendarios', false);
                            $calendarObj->save($data_save_calendar);
                        }
                    }
                }
            }

            /*
            if(!empty($subtableInputs))
            {
                foreach($subtableInputs as $subtableField => $subtableOptions)
                {
                    $subtableFieldInfo = $subtableOptions['field'];
                    $subtableFieldValues = $subtableOptions['values'];
                    // Creamos la entidad galería si no existe
                        foreach($galleryFieldValues as $gfKey => $gfData)
                        {
                            $galleryFieldValues[$gfKey]['adjuntos_id'] = $galleryFieldSourceId;
                            $galleryItemId = $galleryItemObj->save($galleryFieldValues[$gfKey]);
                        }
                }
            }
            */
        }


        if (!empty($relateds)) {
            $data['__related__'] = $relateds;
        }

        // Si hemos hecho actualizaciones relacionadas con ficheros (hay que guardar primero), generamos el SQL UPDATE oportuno
        // En los campos tipo FILE no se guarda el base 64 ni nada, primero tiene que existir el registro, tener un ID, y posteriormente se sube.
        // Es requisito lo del ID para poder subir ficheros en registros nuevos no creados todavía.
        if (!empty($updateData)) {
            $sql_aux = EntityLib::generateSqlv2($this->table, 'UPDATE', $updateData, array('id' => $affectedId), $this->getDefinition());
            $sql = $sql_aux[0];
            $sql_params = $sql_aux[1];
            $this->database->query($sql,$sql_params);
        }

        // Ejecutamos afterSave si no lo hemos ordenado saltar
        if (!$skip_after_save) {
            $this->afterSave($affectedId, $data);
        }

        if (!empty($affectedId) && !empty($relateds)) {
            //$relateds = !empty($data['__related__']) ? $data['__related__'] : array();
            $related_affected_ids = array();
            $sourceDef = $this->getDefinition();
            $prepareFields = array();
            if (!empty($sourceDef) && !empty($sourceDef['related_info'])) {
                $def_related_info = $sourceDef['related_info'];
                foreach ($def_related_info as $dri) {
                    if (empty($prepareFields[$dri['subtable']]))
                        $prepareFields[$dri['subtable']] = array();
                    // Forzamos error si la relación no se hace vs campo ya que sería algo extraño, por si algún día surje.
                    // #REF1 - En caso de que se produzca este error es porque no se está relacionando el detalle con el origen a través del campo id del origen.
                    //      No se estima necesario. En caso de necesitar implementar habría que ajustar esta parte para que se asocie el campo que se relacione.
                    if ($dri['field'] === 'id')
                        $prepareFields[$dri['subtable']][$dri['subfield']] = $affectedId;
                    else {
                        if (empty($dri['field']))
                            throw new Exception(EntityLib::__('API_ERROR_RELATED_CONFIG'));
                        $prepareFields[$dri['subtable']][$dri['subfield']] = $data[$dri['field']];
                    }
                }
            }
            //echo '<pre>';print_r($relateds);echo '</pre>';
            foreach ($relateds as $related_table => $relateds_from_table) {

                // Ojo, en los related de nivel 2 hay que revisar también si es una subtabla!
                $subtable_real_name = $related_table;
                if (!empty($sourceDef) && !empty($sourceDef['fields']) && !empty($sourceDef['fields'][$related_table])) {
                    $subtable_real_name = $sourceDef['fields'][$related_table]['subtable_information']['table'];
                }

                //echo '<pre>';print_r('Pllando objeto para tabla related '.$related_table);echo '</pre>';
                $relatedObject = ClassLoader::getModelObject($subtable_real_name, false);

                foreach ($relateds_from_table as $related_data) {
                    $related_data_save = $related_data;
                    if (empty($related_data_save['id'])) {
                        if (!empty($prepareFields[$related_table])) {
                            foreach ($prepareFields[$related_table] as $subtable_field => $subtable_value) {
                                $related_data_save[$subtable_field] = $subtable_value;
                            }
                        }
                    }

                    $related_affected_id = $relatedObject->save($related_data_save);
                    if (empty($related_affected_id))
                        throw new Exception('Error guardando!');
                }
            }

            //throw new Exception('Evitando guardado - Testeando!');
        }

        // Traducciones
        if (!empty($affectedId) && !empty($traducciones))
        {
            $traduccionesObj = ClassLoader::getModelObject('_i18n_fields');
            $tr_table = $this->table;
            foreach($traducciones as $tr_field => $tr_data)
            {
                foreach ($tr_data as $tr_lang_id => $tr_values) {
                    $tr_id = !empty($tr_values['id']) ? $tr_values['id'] : null;
                    $tr_delete = !empty($tr_values['deleted']);
                    $tr_value = !empty($tr_values['value']) ? $tr_values['value'] : null;
                    $tr_field_info = (!empty($def) && !empty($def['fields']) && !empty($def['fields'][$tr_field])) ? $def['fields'][$tr_field] : array();
                    $tr_is_text = !empty($tr_field_info['type']) && in_array($tr_field_info['type'], array('text', 'code', 'html'));
                    $trad_update = array();
                    if (!empty($tr_id))
                        $trad_update['id'] = $tr_id;
                    if($tr_delete)
                        $trad_update['deleted'] = true;
                    else {
                        $trad_update['idioma_id'] = $tr_lang_id;
                        $trad_update['tabla'] = $tr_table;
                        $trad_update['campo'] = $tr_field;
                        $trad_update['registro_id'] = $affectedId;
                        if ($tr_is_text)
                            $trad_update['valor_txt'] = $tr_value;
                        else
                            $trad_update['valor_varchar'] = $tr_value;
                    }
                    $traduccionesObj->save($trad_update);
                }
            }
        }

        // Por último, si estamos borrando
        if ($is_delete && !empty($affectedId)) {
            $tableFields = $def['fields'];
            foreach ($tableFields as $field => $fieldInfo) {
                if (in_array($fieldInfo['type'], array('subtable', 'comments','subtable-edit')) && empty($fieldInfo['subtable_ignore_delete_cascade'])) {
                    $subtableInfo = !empty($fieldInfo['subtable_information']) ? $fieldInfo['subtable_information'] : '';
                    $subtableDestinyField = !empty($subtableInfo) && !empty($subtableInfo['destiny_table_relation_field']) ? $subtableInfo['destiny_table_relation_field'] : '';
                    if (!empty($subtableInfo) && !empty($subtableInfo['source_table_relation_field']) && $subtableInfo['source_table_relation_field'] === 'id') {

                        $values_delete_cascade = array(
                            'datetime_del' => $timestamp,
                            'user_del_id' => $this->user_id,
                            'deleted' => 1,
                        );
                        $where_cascade = array(
                            $subtableDestinyField => $affectedId,
                            'deleted' => 0,
                        );
                        $deleteCascadeQueryAux = EntityLib::generateSqlv2($subtableInfo['table'], 'UPDATE', $values_delete_cascade, $where_cascade);
                        $deleteCascadeQuery = $deleteCascadeQueryAux[0];
                        $deleteCascadeQueryParams = $deleteCascadeQueryAux[1];
                        $deleteCascadeResult = $this->database->query($deleteCascadeQuery,$deleteCascadeQueryParams);
                    }
                }
            }
        }

        if (!$skip_after_save) {
            $this->afterSaveAllRelated($affectedId, $data);
        }

        // Devolvemos objeto completo si se especifica
        if($return_full_object)
        {
            $full_object = $this->getById($affectedId,array('include_related' => true,'add_actions' ));
            return $full_object;
        }

        return $affectedId;
    }

    // Función para guardar múltiples registros en una sola petición
    public function saveMultiple(&$multiple_data, $insert_with_errors = false, $add_data = false)
    {
        $affected_ok_ids = array();
        $affected_ok_data = array();
        $affected_error_ids = array();
        if (empty($multiple_data))
            throw new Exception('No ha especificado ningún dato para guardar.');
        $first_key = array_key_first($multiple_data);
        if ($first_key !== 0)
            throw new Exception('Para un guardado múltiple debe especificar una colección de registros');

        foreach ($multiple_data as $key => $single_data) {
            try {
                $affected_id = $this->save($single_data);
                $new_record = array('key' => $key, 'id' => $affected_id);
                if (!empty($add_data)) {
                    $new_data = $this->getById($affected_id);
                    unset($new_data['__related__']);
                    unset($new_data['__actions__']);
                    $new_record['data'] = $new_data;
                }
                $affected_ok_ids[] = $new_record;
            } catch (ApiException $e) {
                $affected_error_ids[] = array('key' => $key, 'error' => $e->getErrors(), 'api_error' => true);
            } catch (Exception $e) {
                $affected_error_ids[] = array('key' => $key, 'error' => $e->getMessage());
            }
        }
        if (!empty($affected_error_ids) && !$insert_with_errors) {
            $message = 'Ocurrieron errores a la hora de guardar sus registros.';
            $apiError = new ApiException($message);
            $allErrors = array();
            foreach ($affected_error_ids as $key => $affected_error) {
                $allErrors[] = array('key' => $affected_error['key'], 'error' => $affected_error['error']);
            }
            $apiError->setErrors($allErrors);
            throw $apiError;
        }
        return array(
            'success' => $affected_ok_ids,
            'errors' => $affected_error_ids
        );
    }

    // Lógicas y comprobaciones previas antes de hacer guardado
    public function beforeSave(&$data)
    {
        $errors = array();

        // Detectamos si hay campos related-multiple
        $def = $this->getDefinition();
        $related_multiple_fields = array();
        if(!empty($def) && !empty($def['fields']))
        {
            foreach($def['fields'] as $field_info)
            {
                $field_type = !empty($field_info['type']) ? $field_info['type'] : '';
                $field_multiple = false;
                if($field_type == 'related')
                {
                    $field_type = !empty($field_info['related-multiple']) ? true : false;
                }
            }
        }
        return $errors;
    }

    public function beforeSaveRelatedMultiple(&$data,$related_multiple_fields)
    {
        // Importante! Ya que se puede dar el caso de que distintos campos vayan contra la misma tabla, hay que hacer una gestión de relaciones a eliminar a nivel de tabla
        // En este array generaremos este control a nivel de tabla por cada campo.
        $related_multiple_delete_ids = array();
        $related_multiple_existing_ids = array();
        foreach($related_multiple_fields as $rmf_field => $rmf_data)
        {
            if(array_key_exists($rmf_field,$data))
            {
                $rmf_processed_data = json_decode($rmf_data['related_multiple_options'],JSON_UNESCAPED_UNICODE);
                $related_values = !empty($data[$rmf_field]) ? $data[$rmf_field] : array();
                $rmf_final_table = !empty($rmf_processed_data['table']) ? $rmf_processed_data['table'] : null;
                $rmf_related_field = !empty($rmf_processed_data['related']) ? $rmf_processed_data['related'] : null;
                $rmf_key_field = !empty($rmf_processed_data['key']) ? $rmf_processed_data['key'] : null;
                $rmf_relation_fields = !empty($rmf_processed_data['fields']) ? $rmf_processed_data['fields'] : null;
                $rmf_final_field = (!empty($rmf_relation_fields) && !empty($rmf_relation_fields[$rmf_field])) ? $rmf_relation_fields[$rmf_field] : null;

                $rmf_is_ok = !empty($rmf_final_table) && !empty($rmf_related_field) && !empty($rmf_final_field) && !empty($rmf_key_field);
                if($rmf_is_ok) {
                    // Si ya existe el registro, nos quedaremos con los valores previos que podría haber en la relación.
                    // Hacemos esto por si hay que eliminar valores que ahora no vengan definidios.
                    if (!empty($data[$rmf_related_field])) {
                        $related_search_query = "SELECT `sub`.`id`,`sub`.`".$rmf_final_field."` FROM " . $rmf_final_table ." `sub` WHERE `sub`.`".$rmf_key_field."` = ".$data[$rmf_related_field]." AND `sub`.`deleted` = 0";
                        $related_data = $this->database->querySelectAll($related_search_query);
                        foreach ($related_data as $sd) {
                            $mark_as_deleted = false;
                            $exists = false;
                            $mark_as_deleted = !in_array($sd[$rmf_final_field], $related_values);
                            if ($mark_as_deleted) {
                                if(empty($related_multiple_delete_ids[$rmf_final_table]))
                                    $related_multiple_delete_ids[$rmf_final_table] = array(
                                        'field' => $rmf_final_field,
                                        'values' => array()
                                    );
                                if(!array_key_exists($sd['id'],$related_multiple_delete_ids[$rmf_final_table]['values']))
                                    $related_multiple_delete_ids[$rmf_final_table]['values'][$sd['id']] = $sd[$rmf_final_field];
                            } else {
                                // Si ya existe, no hacemos nada (quitamos de su respectivo array para no procesar)
                                $key = array_search($sd[$rmf_final_field], $related_values);
                                if ($key !== false) {
                                    unset($related_values[$key]);
                                }
                                if(empty($related_multiple_existing_ids[$rmf_final_table]))
                                    $related_multiple_existing_ids[$rmf_final_table] = array();
                                if(!in_array($sd[$rmf_final_field],$related_multiple_existing_ids[$rmf_final_table]))
                                    $related_multiple_existing_ids[$rmf_final_table][] = $sd[$rmf_final_field];

                            }
                        }
                    }
                    if(empty($data['__related__'])) $data['__related__'] = array();
                    if(empty($data['__related__'][$rmf_final_table])) $data['__related__'][$rmf_final_table] = array();

                    foreach ($related_values as $related_id) {
                        $new = array(
                            $rmf_final_field => $related_id,
                        );
                        $data['__related__'][$rmf_final_table][] = $new;
                    }
                }
            }
        }
        if(!empty($related_multiple_delete_ids))
        {
            foreach($related_multiple_delete_ids as $related_table => $related_table_data)
            {
                $related_table_data_field = (!empty($related_table_data) && !empty($related_table_data['field'])) ? $related_table_data['field'] : '';
                $related_table_data_values = (!empty($related_table_data) && !empty($related_table_data['values'])) ? $related_table_data['values'] : array();
                $saving_values_for_related = (!empty($data) && !empty($data['__related__']) && !empty($data['__related__'][$related_table])) ? $data['__related__'][$related_table] : array();
                $related_ids_saved = !empty($related_multiple_existing_ids[$related_table]) ? $related_multiple_existing_ids[$related_table] : array();
                foreach($saving_values_for_related as $svfr)
                {
                    $aux_related_id = !empty($svfr[$related_table_data_field]) ? $svfr[$related_table_data_field] : null;
                    if(!in_array($aux_related_id,$related_ids_saved))
                        $related_ids_saved[] = $aux_related_id;
                }
                if(!empty($related_table_data_values))
                {
                    foreach($related_table_data_values as $dv_id => $dv_value)
                    {
                        if(!in_array($dv_value,$related_ids_saved))
                        {
                            if(empty($data['__related__']))
                                $data['__related__'] = array();
                            if(empty($data['__related__'][$related_table]))
                                $data['__related__'][$related_table] = array();
                            $new = array(
                                'id' => $dv_id,
                                'deleted' => 1,
                            );
                            $data['__related__'][$related_table][] = $new;
                        }
                        
                    }
                }
            }
        }

        /*if($this->table == 'espacios_escenicos')
        {
            echo '<pre>';print_r($data);echo '</pre>';die;
        }*/
    }

    // Lógicas y comprobaciones posteriores a guardado
    /*
    * AK 07/02/2023
    * azzeddine.khmaich
     * En el after save, gestionamos las series de los códigos
     * Importante que esté todo bien configurado a nivel de bbdd
    */
    public function afterSave($saved_id, $data)
    {
        if (empty($data['id'])) {
            $schema_tables = new Entity('__schema_tables');
            $serieObj = new Entity('_series');
            $serieFechaObj = new Entity('_series_fechas');
            $data_table = $schema_tables->getById($this->table_id);
            $serie_field = null;

            if (!empty($data_table) && !empty($data_table['series'])) {
                $serie = $data_table['series'];
                // Cambio para multiples series con filtros
                // Se definirán primero filtros, luego campo, luego código de serie
                // En la última serie (o en la única si no hay filtros), no se pondrá filtro, sería como un else.
                $series_array = explode(',', $data_table['series']);
                $serie_activa = '';
                if(count($series_array) >= 2)
                {
                    $serie_asignada = false;
                    foreach($series_array as $sv)
                    {
                        $aux = explode('#', $sv);
                        // Si la longitud es mayor de 2, es que hay filtro
                        if(count($aux) > 2)
                        {
                            $serie_campo = $aux[1];
                            $serie_codigo = $aux[2];
                            $filtro_str = $aux[0];
                            $filtro_str = str_replace(array('[',']'),array('',''),$filtro_str);
                            $filtros = explode('&',$filtro_str);
                            $cumple = true;
                            foreach($filtros as $f)
                            {
                                $aux = explode('=',$f);
                                if(count($aux) == 2)
                                {
                                    $campo = $aux[0];
                                    $valor = $aux[1];
                                    if(!(!empty($data[$campo]) && $data[$campo] == $valor))
                                    {
                                        $cumple = false;
                                    }
                                }
                                if($cumple)
                                {
                                    $serie_activa = $serie_campo.'#'.$serie_codigo;
                                    $serie_asignada = true;
                                }
                            }
                        }
                        // Si no, es que no hay filtro, nos quedamos con esa
                        else
                        {
                            $serie_activa = $sv;
                            $serie_asignada = true;
                        }

                        // Si hemos asignado serie, salimos
                        if($serie_asignada)
                            break;

                    }
                }
                else
                    $serie_activa = $serie;

                $aux = explode('#', $serie_activa);
                $serie_code_value = $aux[1];
                $serie_field = $aux[0];
                $options = array(
                    'filters' => array(
                        'codigo' => array('=' => $serie_code_value),
                        'deleted' => array('=' => 0),
                    ),
                );
                $serie_data = $serieObj->getList($options);
            }
            $obj_field = null;
            $date_field = null;
            $serie_value = null;
            if (!empty($serie_data) && !empty($serie_data['data'])) {
                $serie_value = $serie_data['data'][0];
                $obj_field = !empty($serie_value['destino']) ? $serie_value['destino'] : null;
                if(empty($data[$obj_field])) {
                    $update_serie = true;
                    $update_serie_id = !empty($serie_value['id']) ? $serie_value['id'] : null;
                    $update_serie_fecha_id = null;
                    $serie_incremento = !empty($serie_value['incremento']) ? $serie_value['incremento'] : 1;
                    $serie_ultimo = !empty($serie_value['ultimo_valor']) ? $serie_value['ultimo_valor'] : 0;

                    $patron_data = $serie_value['patron'];
                    if (strpos($patron_data, '{DT}') !== false) {
                        $date_field = !empty($serie_value['date_field']) ? $serie_value['date_field'] : 'datetime_add';
                        $allowed_data_formulas = array('y', 'Y');
                        $date_value = null;
                        if (!empty($date_field)) {
                            $data_field = null;
                            if (!empty($data[$date_field])) {
                                $date_value = $data[$date_field];
                                $def = $this->getDefinition();
                                $data_field = (!empty($def) && !empty($def['fields']) && !empty($def['fields'][$date_field])) ? $def['fields'][$date_field] : null;
                                if (empty($data_field))
                                    throw new Exception('No se encontró el campo "' . $data_field . '" definido para la numeración del registro.');
                                switch ($data_field['type']) {
                                    case 'date' :
                                        break;
                                    case 'datetime' :
                                        $date_value = date('Y-m-d', strtotime($date_value));
                                        break;
                                    default :
                                        throw new Exception('El campo "' . $date_field . '" definido para la numeración del registro no es de tipo fecha o fecha/hora.');
                                        break;
                                }
                            } else
                                $date_value = date('Y-m-d');
                        }
                        if (!empty($date_value)) {
                            $serieDateObj = ClassLoader::getModelObject('_series_fechas', false);
                            $serie_date_options = array(
                                'filters' => array(
                                    'serie_id' => array('=' => $serie_value['id']),
                                    'from' => array('<=' => $date_value),
                                ),
                                'order' => array('from' => 'DESC')
                            );
                            $serie_date_data = $serieDateObj->getList($serie_date_options);
                            $serie_date_data = (!empty($serie_date_data) && !empty($serie_date_data['data'])) ? $serie_date_data['data'][0] : array();
                            if (empty($serie_date_data))
                                throw new Exception('No se encontró la configuración de la serie "' . $serie_value['codigo'] . '" para la fecha "' . $date_value . '"');
                            if (empty($serie_date_data['date_value']))
                                throw new Exception('No se ha configurado el valor de fecha para indicar en la serie');
                            $patron_data = str_replace('{DT}', $serie_date_data['date_value'], $patron_data);

                            $update_serie = false;
                            $update_serie_fecha_id = $serie_date_data['id'];
                            $serie_incremento = !empty($serie_date_data['incremento']) ? $serie_date_data['incremento'] : 1;
                            $serie_ultimo = !empty($serie_date_data['ultimo_valor']) ? $serie_date_data['ultimo_valor'] : 0;
                        } else {
                            throw new Exception('No se pudo obtener correctamente el valor para la fecha de la numeración');
                        }
                    }

                    $patron_value = explode('{', $patron_data);
                    $patron_value_expode = trim(substr($patron_value[1], 0, -1));
                    $lenght_patron = strlen($patron_value_expode);
                    $last_patron_value = !empty($serie_ultimo) ? intval($serie_ultimo) : 0;
                    $incremento = !empty($serie_incremento) ? intval($serie_incremento) : 1;
                    $codigo = $patron_value[0] . str_pad($last_patron_value + $incremento, $lenght_patron, "0", STR_PAD_LEFT);
                    $this_update = array(
                        'id' => $saved_id,
                        $serie_field => $serie_value['id'],
                        $obj_field => $codigo
                    );

                    if (!empty($this_update)) {
                        $sql_aux = EntityLib::generateSqlv2($this->table, 'UPDATE', $this_update, array('id' => $saved_id), $this->getDefinition());
                        $sql = $sql_aux[0];
                        $sql_params = $sql_aux[1];
                        $this->database->query($sql, $sql_params);
                    }
                    if ($update_serie) {
                        if (empty($update_serie_id)) throw new Exception('No se ha encontrado la serie a actualizar');
                        $serie_update = array(
                            'id' => $update_serie_id,
                            'ultimo_valor' => $last_patron_value + $incremento
                        );
                        $serieObj->save($serie_update);
                    } else {
                        if (empty($update_serie_fecha_id)) throw new Exception('No se ha encontrado la serie/fecha a actualizar');
                        $serie_fecha_update = array(
                            'id' => $update_serie_fecha_id,
                            'ultimo_valor' => $last_patron_value + $incremento
                        );
                        $serieDateObj->save($serie_fecha_update);
                    }
                }


            }
        }
    }

    public function afterSaveAllRelated($saved_id, $data)
    {

    }

    public function getExtraFieldForField($fieldInfo)
    {
        return array(
            'field_order' => $fieldInfo['field_order'],
            'id' => $fieldInfo['field'],
            'type' => 'varchar',
            'field' => $fieldInfo['field'] . '_value',
            'label' => $fieldInfo['label'],
            'tipo_icono' => $fieldInfo['tipo_icono'],
            'label_icon' => $fieldInfo['label_icon'],
            'label_text' => $fieldInfo['label_text'],
            'editable' => 0,
            'hidden' => 0,
            'required' => 0,
            'input_container_class' => !empty($fieldInfo['input_container_class']) ? $fieldInfo['input_container_class'] : '',
            'allow_null' => $fieldInfo['allow_null'],
            'default_value' => '',
            'source_field' => $fieldInfo['field'],
            'no_real' => 1,
        );
    }

    public function getExtraFieldForPassword($fieldInfo)
    {
        return array(
            array(
                'field_order' => $fieldInfo['field_order'],
                'id' => $fieldInfo['field'],
                'type' => 'varchar',
                'field' => $fieldInfo['field'] . '_new_1',
                'label' => 'Nueva contraseña',
                'tipo_icono' => $fieldInfo['tipo_icono'],
                'label_icon' => $fieldInfo['label_icon'],
                'label_text' => $fieldInfo['label_text'],
                'editable' => 1,
                'hidden' => 0,
                'required' => 0,
                'input_container_class' => !empty($fieldInfo['input_container_class']) ? $fieldInfo['input_container_class'] : '',
                'allow_null' => $fieldInfo['allow_null'],
                'default_value' => '',
                'source_field' => $fieldInfo['field'],
                'no_real' => 1,
            ),
            array(
                'field_order' => $fieldInfo['field_order'],
                'id' => $fieldInfo['field'],
                'type' => 'varchar',
                'field' => $fieldInfo['field'] . '_new_2',
                'label' => 'Nueva contraseña (repetir)',
                'tipo_icono' => $fieldInfo['tipo_icono'],
                'label_icon' => $fieldInfo['label_icon'],
                'label_text' => $fieldInfo['label_text'],
                'editable' => 1,
                'hidden' => 0,
                'required' => 0,
                'input_container_class' => !empty($fieldInfo['input_container_class']) ? $fieldInfo['input_container_class'] : '',
                'allow_null' => $fieldInfo['allow_null'],
                'default_value' => '',
                'source_field' => $fieldInfo['field'],
                'no_real' => 1,
            ),
        );
    }

    public function exportField2API($fI, $value)
    {
        $result = $value;
        if (empty($fI['type']))
            $fI['type'] = 'varchar';
        switch ($fI['type']) {
            case 'int' :
            case 'gallery' :
            case 'attachment' :
            case 'attachment_custom' :
            case 'subtable' :
            case 'subtable-edit' :
            case 'comments' :
                if (is_null($result))
                    $result = null;
                else
                    $result = intval($result);
                break;
            case 'related' :
            case 'related-combo' :
                if(empty($fI['related_multiple'])) {
                    if (is_null($result))
                        $result = null;
                    else
                        $result = intval($result);
                }
                else
                {
                    $result = array();
                    if(!empty($value)) {
                        if(is_array($value)) {
                            foreach ($value as $v) {
                                $result[] = intval($v);
                            }
                        }
                        else{
                            $result[] = intval($value);
                        }
                    }
                }
                break;
            case 'boolean' :
                if (is_null($result))
                    $result = null;
                else {
                    $result = intval($result);
                    $result = !empty($result) ? intval(1) : intval(0);
                }
                break;
            case 'decimal' :
                if (is_null($result))
                    $result = null;
                else {
                    $result = floatval($result);
                    $dec_prec = 2;
                    if (!empty($fI['decimal_precission']))
                        $dec_prec = $fI['decimal_precission'];
                    //$result = round(number_format($result, $dec_prec, '.', ''),$dec_prec);
                    //$result = number_format($result, $dec_prec, '.', '');
                    ini_set('bcmath.scale', $dec_prec);
                    $result = round($result, $dec_prec);
                    $result = bcadd($result, '0', $dec_prec);
                    ini_set('bcmath.scale', 0);
                    $result = floatval($result);
                }
                break;
            case 'enum-multi' :
                if (!empty($result)) {
                    $result = explode(',', $result);
                } else
                    $result = null;
                break;
            case 'file' :
                if (is_null($result))
                    $result = null;
                /*
                else if (!empty($result)) {
                    $jsonItem = json_decode($result, JSON_UNESCAPED_UNICODE);
                    $realPath = EntityLib::getOptimizedPathForFile($jsonItem, true);
                    if (!empty($realPath)) {
                        $jsonItem['path'] = $realPath;
                    }
                    $result = json_encode($jsonItem, JSON_UNESCAPED_UNICODE);
                }*/
                break;
            default :
                if (is_null($result))
                    $result = null;
                break;
        }
        return $result;
    }

    public function exportField2HumanValue($fI, $value)
    {
        $result = $value;
        if (empty($fI['type']))
            $fI['type'] = 'varchar';
        switch ($fI['type']) {
            case 'date' :
                if (!empty($result)) {
                    //$result = substr($result, 8, 2) . '/' . substr($result, 5, 2) . '/' . substr($result, 0, 4);
                    $result = EntityLib::sqlDate2Date($result);
                }
                break;
            case 'datetime' :
                if (!empty($result)) {
                    //$result = substr($result, 8, 2) . '/' . substr($result, 5, 2) . '/' . substr($result, 0, 4) . ' ' . substr($result, 11, 2) . ':' . substr($result, 14, 2) . ':' . substr($result, 17);
                    $result = EntityLib::sqlDatetime2Datetime($result);
                }
                break;
        }
        if (empty($result)) $result = " ";
        return $result;
    }

    /* Función para obtener los nombres del registro */
    /*
     * Se le pasan 2 parámetros
     * data -> array con los datos para los que queremos calcular valores
     *  IMPORTANTE -> Parámetro por referencia, se modifica
     * fields -> array con los campos que son de tipo related en el name - Si se pasa vacío, ninguno
     *
     * Aquí, básicamente, añadimos al registro todos los _value que tocan
     * Calculamos y añadimos al registro el __name__ para la entidad.
     * Si el registro no tiene nameField, tirará de una cadena custom "[id]"
     *
     *
     */
    private function getNames(&$data, $relatedFields = array())
    {

        // Cargamos definición de la tabla y valores para calcular nombre y valores related.
        // No volveremos a hacer consulta, como ya calculamos esto en el def inicial de la entidad, cargaremos de aquí los valores de los related
        // El __name__ lo construimos individualmente
        $thisDef = $this->getDefinition();
        $nameField = !empty($thisDef['name_field']) ? $thisDef['name_field'] : '"[",id,"]"';
        $nameFieldsForName = EntityLib::obtainAllConcatFields($nameField);
        $relatedNameFieldsForName = EntityLib::obtainConcatFields($nameField);

        $user_translate_to_id = !empty($_SESSION['__lang_id__']) ? ($_SESSION['__lang_id__'] != 1 ? $_SESSION['__lang_id__'] : null) : null;
        foreach ($data as $key => $rec) {
            $full_name = '';
            foreach ($nameFieldsForName as $name_part) {
                $is_literal = strpos($name_part, '"') !== false;
                if ($is_literal) {
                    $aux_literal = str_replace('"', '', $name_part);
                    $full_name .= $aux_literal;
                } else {
                    $field_value = '';
                    if (in_array($name_part, $relatedNameFieldsForName)) {
                        $name_part_related = $name_part . '_value';
                        if(array_key_exists($name_part_related,$rec)) {
                            $field_value = !empty($rec[$name_part_related]) ? $rec[$name_part_related] : $field_value;
                            if (empty($field_value)) {
                                $field_value = !empty($rec[$name_part]) ? '[' . $rec[$name_part] . ']' : $field_value;
                            }
                        }
                        else
                        {
                            $field_value = !empty($rec[$name_part]) ? $rec[$name_part] : $field_value;
                            $field_traducible = (!empty($thisDef['fields']) && !empty($thisDef['fields'][$name_part])) ? !empty($thisDef['fields'][$name_part]['traducible']) : false;
                            if($field_traducible && !empty($user_translate_to_id))
                            {
                                if(!empty($rec['__lang__']) && !empty($rec['__lang__'][$name_part]) && !empty($rec['__lang__'][$name_part][$user_translate_to_id]) && !empty($rec['__lang__'][$name_part][$user_translate_to_id]['value']))
                                    $field_value = $rec['__lang__'][$name_part][$user_translate_to_id]['value'];
                            }
                        }
                    } else {
                        $field_value = !empty($rec[$name_part]) ? $rec[$name_part] : $field_value;
                    }
                    $full_name .= $field_value;
                }
            }
            $data[$key]['__name__'] = $full_name;
        }

        // Comento funcionalidad vieja por si hay que volver atrás. En la nueva no se usa el parámetro de related fields
        /*
        // Cargamos, en caso de haber campos relacionados, los valores de cada uno de ellos cargados al inicio
        if (!empty($relatedFields)) {
            foreach ($relatedFields as $relatedField => $rfData) {
                $relatedFieldInfo = !empty($thisDef) && !empty($thisDef['fields']) && !empty($thisDef['fields'][$relatedField]) ? $thisDef['fields'][$relatedField] : array();
                $relatedFieldOptions = !empty($relatedFieldInfo) && !empty($relatedFieldInfo['related_options']) ? $relatedFieldInfo['related_options'] : '';
                $relatedFields[$relatedField]['values'] = $relatedFieldOptions;
            }
        }
        //echo '<pre>';print_r($relatedFields);echo '</pre>';
        // Nos guardamos aquí un array con los campos related, que los usaremos para generar el nombre luego
        $relatedFieldsKeys = array_keys($relatedFields);

        // Generamos la cadena base para meter. Lógica para formar cadena correctamente.
        // Básicamente procesamos el name_field para dejar una cadena de base tipo "CIF: {cif}" para luego reemplazar campos
        // Aquí generaremos una única vez la cadena teniendo en cuenta ya lo que son textos fijos y lo que son campos
        $baseNameString = '';
        if (!empty($nameFieldsForName)) {
            foreach ($nameFieldsForName as $nffn) {
                $is_field = strpos($nffn, '"') === false;
                if ($is_field) {
                    $baseNameString .= '{' . $nffn . '}';
                } else
                    $baseNameString .= substr($nffn, 1, strlen($nffn) - 2);
            }
        }

        // Finalmente procesamos cada uno de los registros para añadirle el campo del nombre de la entidad, así como añadir los _value asociado a los related
        foreach ($data as $key => $rec) {
            $nameForRec = $baseNameString;

            // Recorremos todos los campos del registro
            foreach ($rec as $field => $value) {
                // Vemos si tenemos que tener en cuenta este campo para el nombre
                $this_field_for_name = !empty($relatedNameFieldsForName) && in_array($field, $relatedNameFieldsForName);

                // Si el campo es related, hay que añadir su valor
                if (in_array($field, $relatedFieldsKeys)) {

                    // Cargamos los valores a mostrar para el related y añadimos el campo _value
                    $related_value = '';
                    $related_values = !empty($relatedFields[$field]) && !empty($relatedFields[$field]['values']) ? $relatedFields[$field]['values'] : array();
                    $related_multiple = !empty($relatedFields[$field]) && !empty($relatedFields[$field]['multiple']);

                    // Los related multiple no aplican en el names
                    if (!$related_multiple) {
                        $related_value = !empty($value) ? "[" . $value . "]" : '';
                        $related_value = !empty($related_values) && !empty($related_values[$value]) ? $related_values[$value] : $related_value;
                        // Si el campo es para el nombre, le metemos el valor humano en vez de la id a la cadena para el name
                        if ($this_field_for_name) {
                            $nameForRec = str_replace('{' . $field . '}', $related_value, $nameForRec);
                        }
                        // Esto en teoría se cogerá ya bien desde el LEFT JOIN de la consulta, no machacamos
                        if (empty($data[$key][$field . '_value']))
                            $data[$key][$field . '_value'] = $related_value;
                    }
                } else {
                    // Si el campo no es related y tenemos que tenerlo en cuenta para el nombre, metemos el valor que tenga el campo a la cadena para el name
                    if ($this_field_for_name) {
                        $nameForRec = str_replace('{' . $field . '}', $value, $nameForRec);
                    }
                }
            }
            $data[$key]['__name__'] = $nameForRec;
        }
        */
    }

    public function getAllNames($filters = array(), $field = '__name__')
    {
        $namesArray = array();
        if (empty($filters))
            $filters = array('deleted' => array('=' => 0));
        $data = $this->getList(array('filters' => $filters));
        if (!empty($data) && !empty($data['data'])) $data = $data['data'];
        if (!empty($data)) {
            foreach ($data as $d) {
                $namesArray[$d['id']] = $d[$field];
            }
        }
        return $namesArray;
    }

    public function processEntityNames($process_entity_names, $depth = 1)
    {
        $tables = array();
        if ($this->level <= 2 && $depth <= 3) {
            $tables_string = '';
            foreach ($process_entity_names as $pen) {
                $pen_field = $pen['field'];
                $pen_table = $pen['table'];
                if (!array_key_exists($pen_table, $tables)) {
                    $tables[$pen_table] = null;
                    if (!empty($tables_string)) $tables_string .= ',';
                    $tables_string .= "'" . $pen_table . "'";
                }
            }
            //EntityLib::debugSequence('Obteniendo names de tablas: '.$tables_string,true);
            $sql_table_name = "SELECT `table`,name_field FROM __schema_tables WHERE `table` IN (" . $tables_string . ")";
            $result_table_name = $this->database->querySelectAll($sql_table_name);
            //echo '<pre>';print_r($result_table_name);echo '</pre>';
            if (!empty($result_table_name)) {
                foreach ($result_table_name as $rtn) {
                    if (empty($tables[$rtn['table']])) {
                        $name_field = $rtn['name_field'];
                        if (empty($name_field)) $name_field = 'id';
                        $tables[$rtn['table']] = array(
                            'formula' => $name_field,
                            'formula_parts' => array(),
                            'formula_fields' => array(),
                            'formula_fields_extra' => array(),
                            'formula_traducible_fields' => array(),
                        );

                        $aux = explode(",", $name_field);
                        foreach ($aux as $a) {
                            if (strpos($a, '"') === false) {
                                $tables[$rtn['table']]['formula_fields'][] = $a;
                            }
                            $tables[$rtn['table']]['formula_parts'][] = $a;

                            // Importante! El campo ID no se define en cada tabla, lo marcamos como int si se da el caso.
                            if($a == 'id')
                            {
                                $tables[$rtn['table']]['formula_fields_extra']['id'] = array(
                                    'type' => 'int',
                                );
                            }
                        }

                    }
                }
            }

            //EntityLib::debugSequence('Obteniendo información de campos para los nombres: '.$tables_string,true);
            $sql_definicion_campos = "SELECT ";
            $sql_definicion_campos .= "t.`table`,f.field,f.type,f.related_options,f.enum_options,f.traducible ";
            $sql_definicion_campos .= "FROM __schema_fields f ";
            $sql_definicion_campos .= "LEFT JOIN __schema_tables t ON (t.id = f.table_id) ";
            $sql_definicion_campos .= "WHERE (XXXXX) AND f.deleted = 0 AND t.deleted = 0";
            $sql_xxxxx = '';
            foreach ($tables as $table_name => $table_data) {
                if(empty($table_data))
                {
                    throw new Exception(EntityLib::__('Error de configuración obteniendo datos'));
                }
                $formula_fields = $table_data['formula_fields'];
                $fields_str = '';
                foreach ($formula_fields as $ff) {
                    if (!empty($fields_str)) $fields_str .= ',';
                    $fields_str .= "'" . $ff . "'";
                }
                $condition = "f.field IN (" . $fields_str . ") AND t.`table` IN ('" . $table_name . "')";
                if (!empty($sql_xxxxx))
                    $sql_xxxxx .= ' OR ';
                $sql_xxxxx .= '(' . $condition . ')';
            }
            $sql_definicion_campos = str_replace('XXXXX', $sql_xxxxx, $sql_definicion_campos);
            $result_definicion_campos = $this->database->querySelectAll($sql_definicion_campos);
            foreach ($result_definicion_campos as $rdc) {
                $rdc_table = $rdc['table'];
                $rdc_field = $rdc['field'];
                $rdc_type = $rdc['type'];
                $rdc_traducible = !empty($rdc['traducible']);
                $rdc_extra = array(
                    'type' => $rdc_type,
                );
                switch($rdc_type)
                {
                    case 'related':
                    case 'related-combo':
                    //case 'related_info':
                        $rdc_related_options = $rdc['related_options'];
                        $aux = explode('[',$rdc_related_options);
                        $rdc_related_options = $aux[0];
                        $rdc_extra['related_options'] = $rdc_related_options;
                        $sub = array($rdc_field => array(
                            'field' => $rdc_field,
                            'table' => $rdc_related_options
                        ));
                        $rdc_extra['related_formula'] = $this->processEntityNames($sub,$depth+1);
                        break;
                    case 'varchar' :
                    case 'text' :
                        if($rdc_traducible)
                        {
                            $rdc_extra['traducible'] = 1;
                            $tables[$rdc_table]['formula_traducible_fields'][] = $rdc_field;
                        }
                        break;
                }
                $tables[$rdc_table]['formula_fields_extra'][$rdc_field] = $rdc_extra;
            }
            return $tables;
        }

    }
    
    public function addJoinsForNames($process_entity_names,$join_info)
    {
        $all_joins_to_add = array();
        $join_count = 0;

        $user_translate_to_id = !empty($_SESSION['__lang_id__']) ? ($_SESSION['__lang_id__'] != 1 ? $_SESSION['__lang_id__'] : null) : null;

        //echo '<pre>';print_r($join_info);echo '</pre>';
        /*echo '<pre>';print_r($process_entity_names);echo '</pre>';
        echo '<pre>';print_r($join_info);echo '</pre>';die;*/
        foreach($process_entity_names as $field => $field_data)
        {
            $join_count++;
            $table = $field_data['table'];
            /*if(empty($join_info[$table]))
            {
                echo '<pre>';print_r($this->table);echo '</pre>';
                echo '<pre>';print_r($join_info);echo '</pre>';
                echo '<pre>';print_r('Field: '.$field);echo '</pre>';
                echo '<pre>';print_r($field_data);echo '</pre>';
                echo '<pre>';print_r($table);echo '</pre>';die;
            }*/
            if(!empty($join_info) && !empty($join_info[$table])) {
                $table_options = $join_info[$table];
                $formula_parts = $table_options['formula_parts'];
                $join_id = 'jt' . $join_count;
                $process_entity_names[$field]['joins'] = array();
                $process_entity_names[$field]['joins'][$join_id] = array(
                    'join_table' => $table,
                    'join_condition' => "ON `" . $join_id . "`.id = `xxx`." . $field,
                );
                $process_entity_names[$field]['join_formula'] = array();
                //$process_entity_names[$field]['join_data'] = $table_options;

                $extra_count = 0;
                $trad_count = 0;
                foreach ($formula_parts as $fp) {
                    $subextra_count = 0;
                    $field_options = !empty($table_options['formula_fields_extra'][$fp]) ? $table_options['formula_fields_extra'][$fp] : null;

                    $is_literal = is_null($field_options);
                    if ($is_literal) {
                        $aux_literal = str_replace('"', "'", $fp);
                        $process_entity_names[$field]['join_formula'][] = $aux_literal;
                    } else {
                        $field_type = $field_options['type'];
                        if (in_array($field_type,array('related','related-combo'))) {
                            $extra_count++;
                            $subjoin_id = $join_id . 'sub' . $extra_count;
                            $process_entity_names[$field]['joins'][$subjoin_id] = array(
                                'join_table' => $field_options['related_options'],
                                'join_condition' => "ON `" . $subjoin_id . "`.id = `" . $join_id . "`." . $fp,
                                //'join_formula' => $field_options['related_formula'][$field_options['related_options']]['formula_parts'],
                            );
                            $subformula_parts = $field_options['related_formula'][$field_options['related_options']]['formula_parts'];
                            $count_subformula = count($subformula_parts);
                            $aux_count = 0;
                            $subtrad_count = 0;
                            //echo '<pre>';print_r($field_options['related_formula'][$field_options['related_options']]);echo '</pre>';
                            //echo '<pre>';print_r($subformula_parts);echo '</pre>';
                            foreach ($subformula_parts as $sfp) {
                                $aux_count++;
                                $is_first = $aux_count == 1;
                                $is_last = $aux_count == $count_subformula;
                                $join_pre = !$is_first ? "" : "CASE WHEN `" . $subjoin_id . "`.id IS NULL THEN '' ELSE CONCAT(";
                                $join_suf = !$is_last ? "" : ") END";
                                $is_literal = substr($sfp, 0, 1) == '"';
                                if ($is_literal) {
                                    $aux_literal = str_replace('"', "'", $sfp);
                                    $process_entity_names[$field]['join_formula'][] = $join_pre . $aux_literal . $join_suf;
                                } else {
                                    $subfield_options = !empty($field_options['related_formula'][$field_options['related_options']]['formula_fields_extra'][$sfp]) ? $field_options['related_formula'][$field_options['related_options']]['formula_fields_extra'][$sfp] : '';
                                    $subfield_type = $subfield_options['type'];
                                    if ($subfield_type == 'related') {
                                        $subextra_count++;
                                        $subsubjoin_id = $subjoin_id . 'sub' . $subextra_count;
                                        $process_entity_names[$field]['joins'][$subsubjoin_id] = array(
                                            'join_table' => $subfield_options['related_options'],
                                            'join_condition' => "ON `" . $subsubjoin_id . "`.id = `" . $subjoin_id . "`." . $sfp,
                                        );
                                        $subsubformula_parts = $subfield_options['related_formula'][$subfield_options['related_options']]['formula_parts'];
                                        $subaux_count = 0;
                                        $count_subsubformula = count($subsubformula_parts);
                                        foreach ($subsubformula_parts as $ssfp) {
                                            $subaux_count++;
                                            $subis_first = $subaux_count == 1;
                                            $subis_last = $subaux_count == $count_subsubformula;
                                            $subjoin_pre = !$subis_first ? "" : "CASE WHEN `" . $subsubjoin_id . "`.id IS NULL THEN '' ELSE CONCAT(";
                                            $subjoin_suf = !$subis_last ? "" : ") END";
                                            $subjoin_suf = "";
                                            $subis_literal = substr($ssfp, 0, 1) == '"';
                                            if ($subis_literal) {
                                                $subaux_literal = str_replace('"', "'", $ssfp);
                                                $process_entity_names[$field]['join_formula'][] = $subjoin_pre . $subaux_literal . $subjoin_suf;
                                            } else {
                                                $process_entity_names[$field]['join_formula'][] = $subjoin_pre . "IFNULL(`" . $subsubjoin_id . "`." . $ssfp . ",'')" . $subjoin_suf;
                                            }
                                        }
                                    } else {
                                        $subfield_traducible = !empty($subfield_options['traducible']) && !empty($user_translate_to_id);
                                        if ($subfield_traducible) {
                                            $subtrad_count++;
                                            $subtraducible_join_id = $subjoin_id . '_trad_' . $subtrad_count;
                                            $process_entity_names[$field]['joins'][$subtraducible_join_id] = array(
                                                'join_table' => '_i18n_fields',
                                                'join_condition' => "ON (`" . $subtraducible_join_id . "`.`deleted` = 0 AND `" . $subtraducible_join_id . "`.`idioma_id` = " . $user_translate_to_id . " AND `" . $subtraducible_join_id . "`.`tabla` = '" . $field_options['related_options'] . "' AND `" . $subtraducible_join_id . "`.`campo` = '" . $sfp . "' AND `" . $subtraducible_join_id . "`.`registro_id` = `" . $subjoin_id . "`.`id`)",
                                            );
                                            $default_value = "IFNULL(`" . $subjoin_id . "`." . $sfp . ",'')";
                                            $aux_join_info = " CASE WHEN `" . $subtraducible_join_id . "`.id IS NULL THEN " . $default_value . " ELSE ";
                                            $aux_join_info .= " CASE WHEN IFNULL(`" . $subtraducible_join_id . "`.`valor_varchar`,`" . $subtraducible_join_id . "`.`valor_txt`) IS NULL THEN " . $default_value;
                                            $aux_join_info .= " ELSE IFNULL(`" . $subtraducible_join_id . "`.`valor_varchar`,`" . $subtraducible_join_id . "`.`valor_txt`) END";
                                            $aux_join_info .= " END";
                                            $aux_join_info = $join_pre . $aux_join_info . $join_suf;
                                            $process_entity_names[$field]['join_formula'][] = $aux_join_info;
                                        } else
                                            $process_entity_names[$field]['join_formula'][] = $join_pre . "IFNULL(`" . $subjoin_id . "`." . $sfp . ",'')" . $join_suf;
                                    }
                                }
                            }
                        } else {
                            $field_traducible = !empty($field_options['traducible']) && !empty($user_translate_to_id);
                            if ($field_traducible) {
                                $trad_count++;
                                $traducible_join_id = $join_id . '_trad_' . $trad_count;
                                $process_entity_names[$field]['joins'][$traducible_join_id] = array(
                                    'join_table' => '_i18n_fields',
                                    'join_condition' => "ON (`" . $traducible_join_id . "`.`deleted` = 0 AND `" . $traducible_join_id . "`.`idioma_id` = " . $user_translate_to_id . " AND `" . $traducible_join_id . "`.`tabla` = '" . $table . "' AND `" . $traducible_join_id . "`.`campo` = '" . $fp . "' AND `" . $traducible_join_id . "`.`registro_id` = `" . $join_id . "`.`id`)",
                                );
                                $default_value = "IFNULL(`" . $join_id . "`." . $fp . ",'')";
                                $aux_join_info = " CASE WHEN `" . $traducible_join_id . "`.id IS NULL THEN " . $default_value . " ELSE ";
                                $aux_join_info .= " CASE WHEN IFNULL(`" . $traducible_join_id . "`.`valor_varchar`,`" . $traducible_join_id . "`.`valor_txt`) IS NULL THEN " . $default_value;
                                $aux_join_info .= " ELSE IFNULL(`" . $traducible_join_id . "`.`valor_varchar`,`" . $traducible_join_id . "`.`valor_txt`) END";
                                $aux_join_info .= " END";
                                $process_entity_names[$field]['join_formula'][] = $aux_join_info;
                            } else
                                $process_entity_names[$field]['join_formula'][] = "IFNULL(`" . $join_id . "`." . $fp . ",'')";
                        }
                    }
                    //echo '<pre>';print_r($field_options);echo '</pre>';
                }
            }
            //echo '<pre>';print_r($formula_parts);echo '</pre>';
            //echo '<pre>';print_r($table_options);echo '</pre>';
        }
        //echo '<pre>';print_r($process_entity_names);echo '</pre>';die;
        return $process_entity_names;
    }

    // Funciones para intervenir datos en beforeSave / afterSave
    // La idea es que va a haber campos que no son reales y hay que gestionarlos de una forma distinta
    // USO:
    // - En el beforeSave de la entidad principal, se llama al setRemovedData y se hace unset de lo que se quiera quitar
    // - En el afterSave de la entidad principal, se llama al getRemovedData y se hace lo que toque en cada caso
    public function setRemovedData($removed_data)
    {
        $this->removed_data_save = $removed_data;
    }
    public function getRemovedData()
    {
        $result = $this->removed_data_save;
        // Importante! Una vez se consumen estos datos vamos a nulo para evitar
        $this->removed_data_save = null;
        return $result;
    }

    public function setIgnorePermisosCampos($ignorar = false)
    {
        $this->ignore_fields_permisos = $ignorar;
    }

}


?>
