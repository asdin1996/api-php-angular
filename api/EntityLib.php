<?php

class EntityLib {

    const __default_lang_id = 1;

    /* Funciones INTERNAS para generar SQLs */
    // DEPRECATED 2024/09!
    public static function generateSql($table,$type,$values,$where=array(),$def = array())
    {
        $type = strtoupper($type);
        switch($type)
        {
            case 'INSERT' :
                $sql = $type." INTO $table ({fieldlist}) VALUES ({valuelist})";
                $generate = array('fieldlist','valuelist');
                break;
            case 'UPDATE' :
                if(empty($where))
                    throw new Exception(EntityLib::__('API_QUERY_ERROR_UPDATEWHERE'));
                $sql = $type." $table SET {updatelist} WHERE {wherelist}";
                $generate = array('updatelist','wherelist');
                break;
            case 'DELETE' :
                if(empty($where))
                    throw new Exception(EntityLib::__('API_QUERY_ERROR_DELETEWHERE'));
                $sql = $type." FROM $table WHERE {wherelist}";
                $generate = array('wherelist');
                break;
            default :
                throw new Exception(EntityLib::__('API_QUERY_UNKNOWN'));
        }


        $part_values = self::generateQueryPart($generate,$values,$where,$def);
        foreach($generate as $gen)
        {
            $querypart = $part_values[$gen];
            $sql = str_replace('{'.$gen.'}',$querypart,$sql);
        }
        return $sql;
    }

    /* Función auxiliar para generar solo una parte de la query */
    // DEPRECATED 2024/09!
    public static function generateQueryPart($generates,$values,$where,$def){

        $fieldlist = $valuelist = $updatelist = $wherelist = '';

        $is_fieldlist = in_array('fieldlist',$generates);
        $is_valuelist = in_array('valuelist',$generates);
        $is_updatelist = in_array('updatelist',$generates);
        $is_wherelist = in_array('wherelist',$generates);
        $nullable_Fields = array('text','varchar','enum','enum-multi','date','datetime','time','related','decimal','int','html','code','email','related','related-combo');

        // Recorremos registros
        foreach($values as $field => $value)
        {

            // Dependiendo del tipo de query mostramos
            $fieldInfo = array();
            if(!empty($def) && !empty($def['fields']) && !empty($def['fields'][$field]))
            {
                $fieldInfo = $def['fields'][$field];
            }

            $fieldType = !empty($fieldInfo['type']) ? $fieldInfo['type'] : 'varchar';
            $no_real_rield = !empty($fieldInfo['no_real']);
            $fieldAllowNull = !empty($fieldInfo['allow_null']) || (!empty($fieldInfo['type']) && in_array($fieldInfo['type'],$nullable_Fields));
            $isNullValue = false;
            $valueSeparator = '';

            // Si el campo viene como no_real, evitamos que se introduzca en cualquier tipo de consulta para evitar errores de SQL
            if(!$no_real_rield) {
                switch ($fieldType) {
                    case 'varchar' :
                    case 'text' :
                    case 'link' :
                    case 'date' :
                    case 'datetime' :
                    case 'time' :
                    case 'enum' :
                    case 'html' :
                    case 'code' :
                    case 'file' :
                    case 'email' :
                        /*if (!empty($value) && strpos($value, "'") !== false) {
                            $value = str_replace("'", "\'", $value);
                        }*/
                        if (!empty($value) && strpos($value, "“") !== false) {
                            $value = str_replace("“", '"', $value);
                        }
                        $isNullValue = is_null($value);
                        $valueSeparator = "'";
                        break;
                    case 'enum-multi' :
                        $isNullValue = !empty($value);
                        if(!empty($value))
                            $value = implode(',',$value);
                        $valueSeparator = "'";
                        break;
                    case 'int' :
                    case 'related' :
                    case 'related-combo' :
                    case 'select' :
                        $isNullValue = is_null($value);
                        if (!$isNullValue)
                            $value = intval($value);
                        break;
                    case 'gallery' :
                    case 'attachment' :
                    case 'attachment_custom' :
                        $value = intval($value);
                        break;
                    case 'boolean' :
                        $isNullValue = is_null($value);
                        $value = (!empty($value) && ($value === true || $value === 1)) ? 1 : 0;
                        break;
                    case 'decimal' :
                        $isNullValue = is_null($value);
                        if (!$isNullValue)
                            $value = floatval($value);
                        break;
                    /*case 'file' :
                        $isNullValue = is_null($value);
                        $valueSeparator = "'";
                        break;*/
                    default :
                        $isNullValue = is_null($value);
                        $valueSeparator = "'";
                        break;
                }

                if ($is_fieldlist) {
                    if (!empty($fieldlist) || strlen($fieldlist) > 0)
                        $fieldlist .= ',';
                    $fieldlist .= "`" . $field . "`";
                }

                if ($is_updatelist) {
                    if (!empty($updatelist) || strlen($updatelist) > 0)
                        $updatelist .= ', ';

                    //if($fieldAllowNull && (is_null($value) || empty($value)))
                    if ($fieldAllowNull && (is_null($value)))
                        $updatelist .= "`" . $field . "` = NULL";
                    else
                        $updatelist .= "`" . $field . "` = " . $valueSeparator . $value . $valueSeparator;

                }
                if ($is_valuelist) {
                    if (!empty($valuelist) || strlen($valuelist) > 0)
                        $valuelist .= ',';

                    if ($fieldAllowNull && is_null($value))
                        $valuelist .= 'NULL';
                    else
                        $valuelist .= $valueSeparator . $value . $valueSeparator;
                }
            }

        }

        if(!empty($where) && $is_wherelist)
        {
            foreach($where as $field => $value) {

                $valueSeparator = '';
                // Dependiendo del tipo de query mostramos
                $fieldInfo = array();
                if(!empty($def) && !empty($def['fields']) && !empty($def['fields'][$field]))
                {
                    $fieldInfo = $def['fields'][$field];
                }

                switch($fieldType)
                {
                    case 'varchar' :
                    case 'text' :
                    case 'link' :
                    case 'date' :
                    case 'datetime' :
                    case 'time' :
                    case 'enum' :
                    case 'enum-multi' :
                    case 'html' :
                    case 'code' :
                    case 'email' :
                        $isNullValue = is_null($value);
                        $valueSeparator = "'";
                        break;
                    case 'int' :
                        $isNullValue = is_null($value);
                        $value = intval($value);
                        break;
                    case 'boolean' :
                        $isNullValue = is_null($value);
                        $value = (!empty($value) && ($value === true || $value === 1)) ? 1 : 0;
                        break;
                    case 'decimal' :
                        $isNullValue = is_null($value);
                        $value = floatval($value);
                        break;
                    default :
                        $isNullValue = is_null($value);
                        $valueSeparator = "'";
                        break;
                }

                if (!empty($wherelist))
                    $wherelist .= ' AND ';

                if($fieldAllowNull && is_null($value))
                    $wherelist .= "`" . $field . "` = NULL";
                else
                    $wherelist .= "`" . $field . "` = " . $valueSeparator.$value.$valueSeparator;
            }
        }

        $result = array(
            'fieldlist' => $fieldlist,
            'valuelist' => $valuelist,
            'updatelist' => $updatelist,
            'wherelist' => $wherelist,
        );
        return $result;
    }

    /* Función que obtiene campos por defecto de ORM */
    public static function getBaseFields()
    {
        return array(
            'datetime_add',
            'datetime_upd',
            'datetime_del',
            'user_add_id',
            'user_upd_id',
            'user_del_id',
            'deleted',
        );
    }
    /* Función que obtiene campos por defecto de ORM y toda su info */
    public static function getBaseFieldsData()
    {
        $bfields = array(
            'id' => array('field_order' => 0,'type' => 'int','label' => 'Id','label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => null),
            '__name__' => array('field_order' => 0,'type' => 'varchar','label' => EntityLib::__('ENTITY_FIELD_NAME'),'tipo_icono' => null,'label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => null,'allow_null' => 1,'no_real' => 1),
            'datetime_add' => array('field_order' => 0,'type' => 'datetime','label' => EntityLib::__('ENTITY_FIELD_DT_ADD'),'label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => null,'time_with_seconds' => true),
            'datetime_upd' => array('field_order' => 0,'type' => 'datetime','label' => EntityLib::__('ENTITY_FIELD_DT_UPD'),'label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => null,'time_with_seconds' => true),
            'datetime_del' => array('field_order' => 0,'type' => 'datetime','label' => EntityLib::__('ENTITY_FIELD_DT_DEL'),'label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => null,'time_with_seconds' => true),
            'user_add_id' => array('field_order' => 0,'type' => 'related','label' => EntityLib::__('ENTITY_FIELD_US_ADD'),'label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => null,'related_options' => '_usuarios'),
            'user_upd_id' => array('field_order' => 0,'type' => 'related','label' => EntityLib::__('ENTITY_FIELD_US_UPD'),'label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => null,'related_options' => '_usuarios'),
            'user_del_id' => array('field_order' => 0,'type' => 'related','label' => EntityLib::__('ENTITY_FIELD_US_DEL'),'label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => null,'related_options' => '_usuarios'),
            'deleted' => array('field_order' => 0,'type' => 'boolean','label' => EntityLib::__('ENTITY_FIELD_DEL'),'label_icon' => null,'label_text' => null,'editable' => 0,'hidden' => 0,'required' => 0,'input_container_class' => 'baseField','default_value' => 0),
        );
        return $bfields;
    }
    /* Función que obtiene la consulta CONCAT o del campo del nombre de una tabla*/
    public static function getConcatFieldsFromString($relatedNameField,$joinTable = '')
    {
        $relatedNameFieldForQuery = '';
        if(strpos($relatedNameField,',') !== false)
        {
            $aux_related_nf = explode(",",$relatedNameField);
            $concat_str = 'CONCAT(';
            $is_first_rnf = true;
            foreach($aux_related_nf as $arnf)
            {
                if($is_first_rnf)
                    $is_first_rnf = false;
                else
                    $concat_str .= ",";
                if(strpos($arnf,'"') !== false)
                {
                    $arnf = str_replace('"','',$arnf);
                    $concat_str .= "'".$arnf."'";
                }else {
                    if(empty($joinTable))
                        $concat_str .= "`" . $arnf . "`";
                    else
                        $concat_str .= $joinTable.".`" . $arnf . "`";

                }
            }
            $concat_str .= ')';
            $relatedNameFieldForQuery = $concat_str;
        }
        else {
            if(empty($joinTable))
                $relatedNameFieldForQuery = "`" . $relatedNameField . "`";
            else
                $relatedNameFieldForQuery = $joinTable.".`" . $relatedNameField . "`";
        }
        return $relatedNameFieldForQuery;
    }
    public static function obtainConcatFields($relatedNameField)
    {
        $relatedFields = array();
        if(strpos($relatedNameField,',') !== false)
        {
            $aux_related_nf = explode(",",$relatedNameField);
            foreach($aux_related_nf as $arnf)
            {
                if(strpos($arnf,'"') === false)
                {
                    $relatedFields[] = $arnf;
                }
            }
        }
        else {
            $relatedFields[] = $relatedNameField;
        }
        return $relatedFields;
    }
    public static function obtainAllConcatFields($relatedNameField)
    {
        $allFields = array();
        if(strpos($relatedNameField,',') !== false)
        {
            $aux_related_nf = explode(",",$relatedNameField);
            foreach($aux_related_nf as $arnf)
            {
                $allFields[] = $arnf;
            }
        }
        else {
            $allFields[] = $relatedNameField;
        }
        return $allFields;
    }

    /* Función que genera un GUID */
    public static function getGuidv4()
    {
        if (function_exists('com_create_guid') === true)
            return trim(com_create_guid(), '{}');

        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /* Función que devuelve una traducción de una cadena */
    public static function __($cadena,$params=array())
    {
        //$lang_id = $_SESSION['lang_id'];
        $lang_id = self::getSession('__lang_id__');
        if(empty($lang_id)) $lang_id = 1;
        if(!empty($params) && !empty($params['__force_lang__'])) {
            $lang_id = $params['__force_lang__'];
            unset($params['__force_lang__']);
        }

        self::loadTranslations($lang_id);

        $original = $cadena;
        $traduccion = '';

        $translations = array();
        $default_translations = array();
        if(empty($_SESSION['__translate__']))
            $_SESSION['__translate__'] = array();
        $translations = !empty($_SESSION['__translate__']['lang' . $lang_id]) ? $_SESSION['__translate__']['lang' . $lang_id] : array();
        $default_translations = !empty($_SESSION['__translate__']['lang' . self::__default_lang_id]) ? $_SESSION['__translate__']['lang' . self::__default_lang_id] : array();

        if(!empty($translations) && !empty($translations[$cadena]))
        {
            $traduccion = $translations[$cadena];
        }else if($lang_id !== self::__default_lang_id)
        {
            if(!empty($default_translations) && !empty($default_translations[$cadena]))
            {
                $traduccion = $default_translations[$cadena];
            }
        }
        if(empty($traduccion))
            $traduccion = $original;

        $i = 1;
        foreach($params as $replace)
        {
            $traduccion = str_replace('$'.$i,$replace,$traduccion);
            $i++;
        }

        return $traduccion;
    }

    private static function loadTranslations($lang_id){
        $load = false;
        if(empty($_SESSION['__translate__']))
            $_SESSION['__translate__'] = array();
        if(empty($_SESSION['__translate__']['lang'.$lang_id])) {
            $load = true;
            $_SESSION['__translate__']['lang' . $lang_id] = array();
        }
        if($load)
        {
            //echo '<pre>';print_r('Cargar traducciones');echo '</pre>';
            $db = self::getNewDatabase();
            $default_lang_id = 1;
            if(empty($lang_id))
                $lang_id = $default_lang_id;
            $lang_id_str = '?';
            $query_params = array($default_lang_id);
            if($default_lang_id != $lang_id)
            {
                $lang_id_str .= ',?';
                $query_params[] = $lang_id;
            }
            $query = "SELECT * FROM _i18n WHERE idioma_id IN (".$lang_id_str.") AND deleted = 0";
            $qR = $db->querySelectAll($query,$query_params);
            $translations = array();
            if(!empty($qR))
            {
                foreach($qR as $term)
                {
                    if(!empty($term['origen']) && !empty($term['traduccion']))
                    {
                        $tr_lang_id = 'lang'.$term['idioma_id'];
                        $tr_source = $term['origen'];
                        $tr_translation = $term['traduccion'];
                        if(empty($translations[$tr_lang_id]))
                            $translations[$tr_lang_id] = array();
                        if(empty($translations[$tr_lang_id][$tr_source]))
                            $translations[$tr_lang_id][$tr_source] = $tr_translation;
                    }
                }
            }

            foreach($translations as $tr_id => $all_lang_trans)
            {
                if(empty($_SESSION['__translate__']))
                    $_SESSION['__translate__'] = array();
                $_SESSION['__translate__'][$tr_id] = $all_lang_trans;
            }
        }
    }
    public static function getNewDatabase(){

        $servername = EntityLib::getServerName();
        $optionsPathFile = __DIR__.'/options/options.'.$servername.'.json';
        if(!file_exists($optionsPathFile))
            throw new Exception(EntityLib::__('API_JSON_CONFIG_ERROR',self::__default_lang_id));

        $jsonOptions = file_get_contents($optionsPathFile);
        $app_options = json_decode($jsonOptions,JSON_UNESCAPED_UNICODE);
        $db_options = array(
            'db_dbtype' => 'sql',
            'db_host' => !empty($app_options['db_host']) ? $app_options['db_host'] : '',
            'db_database' => !empty($app_options['db_name']) ? $app_options['db_name'] : '',
            'db_user' => !empty($app_options['db_user']) ? $app_options['db_user'] : '',
            'db_password' => !empty($app_options['db_pass']) ? $app_options['db_pass'] : '',
        );
        return new Database($db_options);

    }

    /*
     * Función que crea, si no existe, una ruta completa en el servidor. Se invocará antes de guardar un fichero, de manera interna, para simplificar el proceso final.
     */
    public static function prepareFolder($path)
    {
        $rootPath = __DIR__.'/../';
        $rootPath = str_replace('api/../','',$rootPath);
        $mediaPath = $rootPath;
        $fullPath = $rootPath.$path;
        $mediaFolders = explode('/' ,$path);
        foreach($mediaFolders as $folder)
        {
            $mediaPath .= $folder;
            if(!is_dir($mediaPath)) {
                if (!mkdir($mediaPath, 0755)) {
                    throw new Exception(EntityLib::__('API_ERROR_CREATEPATH',self::__default_lang_id));
                }
            }
            $mediaPath .= '/';
        }
        return true;
    }

    /*
     * Función que sube un fichero
     */
    public static function uploadFile($path,$content)
    {
        $fullPath = __DIR__.'/../'.$path;
        $fullPath = mb_strtolower($fullPath);
        if(!empty($content))
        {
            $aux = explode(",",$content);
            if(!empty($aux) && !empty($aux[1]))
                $content = $aux[1];
        }
        file_put_contents($fullPath,base64_decode($content));
        return true;
    }

    public static function getDimensions()
    {
        $db = self::getNewDatabase();
        $query = "SELECT * FROM _dimensiones WHERE deleted = 0";
        $dimArray = $db->querySelectAll($query);
        return $dimArray;
    }

    public static function getDimensionRolInfo()
    {
        $dimension_principal = $dimension_principal_valor = $dimension_principal_label = $dimension_principal_valor_mostrar = null;
        if(!empty($_SESSION))
        {
            $es_admin_ekiba = $es_admin_cliente = false;
            $rol_codes = !empty($_SESSION['__rol_codes__']) ? $_SESSION['__rol_codes__'] : array();
            if(in_array('EKIBA',$rol_codes)) $es_admin_ekiba = true;
            if(in_array('ADMON',$rol_codes)) $es_admin_cliente = true;
            if(!empty($_SESSION['__dimension__']))
            {
                $dimension_principal = array_key_first($_SESSION['__dimension__']);
                $dimension_principal_valor = !empty($_SESSION['__dimension__'][$dimension_principal]) ? intval($_SESSION['__dimension__'][$dimension_principal]) : 0;
                if(!empty($dimension_principal_valor))
                {
                    $dimObj = ClassLoader::getModelObject('_dimensiones');
                    $dimOpt = array('filters' => array('data_table' => array('=' => $dimension_principal)));
                    $dimData = $dimObj->getList($dimOpt);
                    $relatedDimObj = ClassLoader::getModelObject($dimension_principal,true);
                    $relatedDimData = $relatedDimObj->getById($dimension_principal_valor);
                    if(!empty($dimData) && !empty($dimData['data']))
                    {
                        $dimension_principal_label = (!empty($dimData['data'][0]) && !empty($dimData['data'][0]['nombre'])) ? $dimData['data'][0]['nombre'] : $dimension_principal;
                    }
                    if(!empty($relatedDimData) && !empty($relatedDimData['__name__']))
                    {
                        $dimension_principal_valor_mostrar = $relatedDimData['__name__'];
                    }
                }
            }
        }
        if(empty($dimension_principal_valor))
        {
            if($es_admin_ekiba) {
                $dimension_principal = 'admin';
                $dimension_principal_valor = null;
                $dimension_principal_label = 'Superadministrador';
                $dimension_principal_valor_mostrar = !empty($_SESSION['__user_name__']) ? $_SESSION['__user_name__'] : 'Administrador del sistema';
            }
            else if($es_admin_cliente) {
                $dimension_principal = 'admin_cli';
                $dimension_principal_valor = null;
                $dimension_principal_label = 'Administración';
                $dimension_principal_valor_mostrar = !empty($_SESSION['__user_name__']) ? $_SESSION['__user_name__'] : 'Administrador';
            }
            else {
                $dimension_principal = 'user';
                $dimension_principal_valor = $_SESSION['__user_id__'];
                $dimension_principal_label = 'Usuario';
                $dimension_principal_valor_mostrar = !empty($_SESSION['__user_name__']) ? $_SESSION['__user_name__'] : $_SESSION['__user_id__'];
            }
        }
        $result = array('dimension' => $dimension_principal,'valor' => $dimension_principal_valor,'label' => $dimension_principal_label,'valor_mostrar' => $dimension_principal_valor_mostrar);
        return $result;
    }

    public static function getDimensionRolInfoFromUser($user_id)
    {
        $dimension_principal = $dimension_principal_valor = $dimension_principal_label = $dimension_principal_valor_mostrar = null;

        // Obtenemos roles y dimensión del usuario pasado por parámetro para devolver los datos del mismo
        $rolObject = ClassLoader::getModelObject('roles');
        $userRolObject = ClassLoader::getModelObject('usuarios_roles',true);
        $userDimObject = ClassLoader::getModelObject('usuarios_dimensiones',true);
        $filters = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'usuario_id' => array('=' => $user_id),
            ),
        );
        $user_roles = $userRolObject->getList($filters);
        $rol_ids_for_user = array();
        $rol_codes_for_user = array();
        if (empty($rol_ids_for_user)) {
            /*if (!empty($user_data) && !empty($user_data['rol_id']))
                $rol_ids_for_user[] = $user_data['rol_id'];*/
            if (!empty($user_roles) && !empty($user_roles['data'])) {
                //echo '<pre>';print_r($user_roles['data']);echo '</pre>';die;
                foreach ($user_roles['data'] as $ur) {
                    if (!in_array($ur['rol_id'], $rol_ids_for_user))
                        $rol_ids_for_user[] = $ur['rol_id'];
                }
            }
        }
        $filters = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'id' => array('IN' => $rol_ids_for_user),
            ),
        );
        $rol_list = $rolObject->getList($filters);
        $rol_codes = array();
        if(!empty($rol_list) && !empty($rol_list['data']))
        {
            foreach($rol_list['data'] as $rk => $rol_data)
            {
                if(!empty($rol_data['codigo']) && !in_array($rol_data['codigo'],$rol_codes))
                    $rol_codes[] = $rol_data['codigo'];
            }
        }

        $filters = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'usuario_id' => array('=' => $user_id),
                //'default' => array('=' => 1)
            ),
        );
        $dimension_list = $userDimObject->getList($filters);
        if(!empty($dimension_list) && !empty($dimension_list['data']))
        {
            foreach($dimension_list['data'] as $dl) {
                if(count($dimension_list['data']) == 1 || $dl['default']) {
                    $dimension_principal = !empty($dl['dimension']) ? $dl['dimension'] : null;
                    $dimension_principal_valor = !empty($dl['valor']) ? $dl['valor'] : null;
                }
            }
        }

        if(!empty($rol_codes))
        {
            $es_admin_ekiba = $es_admin_cliente = false;
            if(in_array('EKIBA',$rol_codes)) $es_admin_ekiba = true;
            if(in_array('ADMON',$rol_codes)) $es_admin_cliente = true;
            if(!empty($dimension_principal_valor))
            {
                if(!empty($dimension_principal_valor))
                {
                    $dimObj = ClassLoader::getModelObject('_dimensiones');
                    $dimOpt = array('filters' => array('data_table' => array('=' => $dimension_principal)));
                    $dimData = $dimObj->getList($dimOpt);
                    $relatedDimObj = ClassLoader::getModelObject($dimension_principal,true);
                    $relatedDimData = $relatedDimObj->getById($dimension_principal_valor);
                    if(!empty($dimData) && !empty($dimData['data']))
                    {
                        $dimension_principal_label = (!empty($dimData['data'][0]) && !empty($dimData['data'][0]['nombre'])) ? $dimData['data'][0]['nombre'] : $dimension_principal;
                    }
                    if(!empty($relatedDimData) && !empty($relatedDimData['__name__']))
                    {
                        $dimension_principal_valor_mostrar = $relatedDimData['__name__'];
                    }
                }
            }
        }
        if(empty($dimension_principal_valor))
        {
            if($es_admin_ekiba) {
                $dimension_principal = 'admin';
                $dimension_principal_valor = null;
                $dimension_principal_label = 'Superadministrador';
                $dimension_principal_valor_mostrar = !empty($_SESSION['__user_name__']) ? $_SESSION['__user_name__'] : 'Administrador del sistema';
            }
            else if($es_admin_cliente) {
                $dimension_principal = 'admin_cli';
                $dimension_principal_valor = null;
                $dimension_principal_label = 'Administración';
                $dimension_principal_valor_mostrar = !empty($_SESSION['__user_name__']) ? $_SESSION['__user_name__'] : 'Administrador';
            }
        }
        $result = array('dimension' => $dimension_principal,'valor' => $dimension_principal_valor,'label' => $dimension_principal_label,'valor_mostrar' => $dimension_principal_valor_mostrar);
        return $result;
    }

    public static function initSession()
    {
        $_SESSION = array(
            '__cache__' => false,
            '__db__' => null,
            '__user_id__' => null,
            '__rol_ids__' => array(),
            '__rol_codes__' => array(),
            '__dimension__' => array(),
        );
        unset($_SESSION['__permisos__']);
    }
    public static function setSession($key,$value)
    {
        $_SESSION[$key] = $value;
    }
    public static function setSessionSub($key,$subkey,$value)
    {
        $_SESSION[$key][$subkey] = $value;
    }
    public static function getSession($key)
    {
        if(!empty($_SESSION) && isset($_SESSION[$key]))
            return $_SESSION[$key];
        else
            return null;
    }


    public static function loadDimensionsForLoginUser($user_id)
    {
        $allDimensions = self::getDimensions();
        $processedDim = array();
        $forcedDim = array();
        if(!empty($allDimensions))
        {
            foreach($allDimensions as $dimData)
            {
                //$aux = explode('#',$dimData);
                $dimName = $dimData['data_table'];
                $dimField = $dimData['field_name'];
                $dimLabel = $dimData['nombre'];
                $processedDim[$dimName] = array('values' => array(),'default' => null,'order' => $dimField,'label' => $dimLabel,'field' => $dimField);
            }
        }

        $userRolObject = ClassLoader::getModelObject('usuarios_roles');
        $rolObject = ClassLoader::getModelObject('roles');
        $optionsUR = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'usuario_id' => array('=' => $user_id),
            ),
        );
        $uRolesSet = $userRolObject->getList($optionsUR);
        if(!empty($uRolesSet) && !empty($uRolesSet['data']))
            $uRoles = $uRolesSet['data'];
        else
            $uRoles = array();

        $dimensionesRoles = array();
        foreach($uRoles as $uR)
        {
            $rolData = $rolObject->getById($uR['rol_id']);
            if(!empty($rolData) && !empty($rolData['dimensiones'])) {
                $aux = explode(',',$rolData['dimensiones']);
                foreach($aux as $explodedDim) {
                    if(!in_array($explodedDim,$dimensionesRoles))
                        $dimensionesRoles[] = $explodedDim;
                }
            }
        }

        $result['__dimensions__'] = array();
        $userDimObject = ClassLoader::getModelObject('usuarios_dimensiones',false);
        $optionsUD = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'usuario_id' => array('=' => $user_id),
            ),
            'ignore_process' => true,
            '__login__' => true,
        );
        $uDim = $userDimObject->getList($optionsUD);
        if(!empty($uDim) && !empty($uDim['data'])) {
            $uDimData = $uDim['data'];
            foreach($uDimData as $uD)
            {
                $dim = $uD['dimension'];
                $dimV = $uD['valor'];
                $dimD = !empty($uD['default']);
                if(!empty($dim) && !empty($dimV))
                {
                    if(empty($processedDim[$dim]))
                        $processedDim[$dim] = array('values' => array(),'default' => null);
                    if(empty($processedDim[$dim]['values'][$dimV]))
                        $processedDim[$dim]['values'][$dimV] = null;
                    if(!empty($dimD))
                        $processedDim[$dim]['default'] = $dimV;
                }
            }
            if(!empty($uDim['unset']))
                unset($processedDim[$uDim['unset']]);
            if(!empty($uDim['forced']))
                $forcedDim[] = $uDim['forced'];
        }

        if(!empty($processedDim))
        {
            foreach($processedDim as $dim => $dimData)
            {
                if(!in_array($dim,$dimensionesRoles))
                {
                    if(!in_array($dim,$forcedDim))
                        unset($processedDim[$dim]);
                }
            }

            foreach($processedDim as $pdKey => $pdValue)
            {
                $valueFilters = array();
                foreach($pdValue['values'] as $pdVKey => $pdvValue)
                {
                    if(!empty($pdVKey))
                        $valueFilters[] = $pdVKey;
                }

                // Cambiamos para obtener con TRUE, sin related y sin acciones, para obtener nombres correctamente
                $dimObj = ClassLoader::getModelObject($pdKey,true);
                $dimOpt = array(
                    'filters' => array(
                        'deleted' => array('=' => 0),
                    ),
                    'ignore_dimension_filter' => true,
                    'ignore_related' => true,
                    'add_actions' => false,
                );
                if(!empty($valueFilters))
                    $dimOpt['filters']['id'] = array('IN' => $valueFilters);

                $dimData = $dimObj->getList($dimOpt);

                if(!empty($dimData) && !empty($dimData['data']))
                {
                    foreach($dimData['data'] as $dD)
                    {
                        if(!empty($dD['id']))
                        {
                            if(empty($processedDim[$pdKey]['values'][$dD['id']]))
                            {
                                $dim_field_name = !empty($processedDim[$pdKey]['field']) ? $processedDim[$pdKey]['field'] : '';
                                $processedDim[$pdKey]['values'][$dD['id']] = !empty($dD[$dim_field_name]) ? $dD[$dim_field_name] : '';
                            }
                        }
                    }
                }

                if(empty($processedDim[$pdKey]['values'])) {
                    $dimObj = ClassLoader::getModelObject($pdKey,false);
                    $dimOpt = array(
                        'filters' => array(
                            'deleted' => array('=' => 0),
                        ),
                    );
                    if(!empty($processedDim[$pdKey]['order'])) {
                        $dimOpt['order'] = array(
                            $processedDim[$pdKey]['order'] => 'ASC',
                        );
                    }
                    $dimData = $dimObj->getList($dimOpt);
                    if(!empty($dimData) && !empty($dimData['data']))
                    {
                        foreach($dimData['data'] as $dD)
                        {
                            if(!empty($dD['id']))
                            {
                                if(empty($processedDim[$pdVKey]['values'][$dD['id']]))
                                {
                                    $processedDim[$pdKey]['values'][$dD['id']] = $dD['__name__'];
                                }
                            }
                        }
                    }
                }

                // Si hay valores y más de 1 añadimos la opción de todos
                if (!empty($processedDim[$pdKey]['values']) && count($processedDim[$pdKey]['values']) > 1) {
                    $processedDim[$pdKey]['values'][0] = "Todos";
                    // Ordenamos los datos propcesados para que la opción de Todos sea por defecto la primera.
                    ksort($processedDim[$pdKey]['values']);
                }

                // Si el usuario ya tiene dimensión marcada, seleccionamos ese valor por defecto
                // Esto puede ocurrir si el usuario cambia de idioma, por ejemplo
                if(
                    !empty($_SESSION) &&
                    !empty($_SESSION['__dimension__']) &&
                    !empty($_SESSION['__dimension__'][$pdKey]) &&
                    !empty($processedDim[$pdKey]['values']) &&
                    !empty($processedDim[$pdKey]['values'][$_SESSION['__dimension__'][$pdKey]]))
                {
                    $processedDim[$pdKey]['default'] = $_SESSION['__dimension__'][$pdKey];
                }

                // Si no hay valor por defecto, cogemos el primer key de valores
                if(empty($processedDim[$pdKey]['default'])) {
                    if (!empty($processedDim[$pdKey]['values'])){
                        $aux = array_keys($processedDim[$pdKey]['values']);
                        $processedDim[$pdKey]['default'] = $aux[0];
                    }
                }

                // Este dato ya no hace falta, limpiamos para no devolver
                if(!empty($processedDim[$pdKey]['order'])) {
                    unset($processedDim[$pdKey]['order']);
                }


            }
        }
        return $processedDim;
    }

    public static function addParentDimensions($dimension,$value)
    {
        $db = $_SESSION['__db__'];
        $related_dim_data = null;
        $related_dim_record = null;
        $parentDim = array();
        if(!empty($dimension))
        {
            $dimObj = ClassLoader::getModelObject('_dimensiones',false);
            $filters = array(
                'deleted' => array('=' => 0),
                'data_table' => array('=' => $dimension),
            );
            $dimValue = $dimObj->getList(array('filters' => $filters));
            if(!empty($dimValue) && !empty($dimValue['data']))
            {
                $dimData = $dimValue['data'][0];
                if(!empty($dimData['related_dim']))
                {
                    $aux_dim = explode(',',$dimData['related_dim']);
                    foreach($aux_dim as $dim_related) {
                        $dimParentDataAux = explode('#', $dim_related);
                        $thisDimObj = ClassLoader::getModelObject($dimParentDataAux[1], false);
                        // Si tiene dimensión, nos quedamos con el registro y si tiene el valor para el campo, lo asignamos
                        if(!empty($value)) {
                            if(is_null($related_dim_record))
                            {
                                $related_dim_record = $thisDimObj->getById($value);
                            }
                            if(!empty($related_dim_record[$dimParentDataAux[2]]))
                                $parentDim[$dimParentDataAux[0]] = $related_dim_record[$dimParentDataAux[2]];
                        }
                        // Si tiene dimensión pero no tiene valor (tiene el TODOS), buscamos sus dimensiones para asociarle sus valores en cadena
                        else {
                            $filter_by_values = array();
                            if(is_null($related_dim_data)) {
                                $queryDimForUser = "SELECT valor FROM _usuarios_dimensiones WHERE usuario_id = ? AND dimension = ? AND deleted = 0";
                                $query_params = array($_SESSION['__user_id__'], $dimParentDataAux[1]);
                                $dimValues = $db->querySelectAll($queryDimForUser, $query_params);
                                if (!empty($dimValues)) {
                                    foreach ($dimValues as $dv) {
                                        $filter_by_values[] = $dv['valor'];
                                    }
                                }
                                $related_dim_options = array(
                                    'filters' => array(
                                        'id' => array('IN' => $filter_by_values)
                                    ),
                                    'include_related' => false,
                                    'add_actions' => false,
                                );
                                $related_dim_data_aux = $thisDimObj->getList($related_dim_options);
                                if(!empty($related_dim_data_aux) && !empty($related_dim_data_aux['data']))
                                    $related_dim_data = $related_dim_data_aux['data'];
                                else
                                    $related_dim_data = array();
                            }
                            
                            if(!empty($related_dim_data))
                            {
                                foreach($related_dim_data as $rdd)
                                {
                                    if(array_key_exists($dimParentDataAux[2],$rdd))
                                    {
                                        if(!empty($rdd[$dimParentDataAux[2]]))
                                        {
                                            if(!in_array($rdd[$dimParentDataAux[2]],$filter_by_values))
                                                $filter_by_values[] = $rdd[$dimParentDataAux[2]]; 
                                        }
                                    }
                                }
                                if(count($filter_by_values) == 1)
                                    $parentDim[$dimParentDataAux[0]] = $filter_by_values[0];
                                else if(count($filter_by_values) > 1)
                                    $parentDim[$dimParentDataAux[0]] = $filter_by_values;
                            }
                        }
                    }
                }
            }
        }
        return $parentDim;
    }

    public static function getCardActions($recObject,$options = array())
    {
        $single = !empty($options['single']) ? $options['single'] : '';
        $plural = !empty($options['plural']) ? $options['plural'] : '';
        $is_add = !empty($options['is_add']);
        $is_view = !empty($options['is_view']);
        $ignore_actions = !empty($options['disable_actions']) ? $options['disable_actions'] : array();
        $custom_actions = !empty($options['custom_actions']) ? $options['custom_actions'] : array();
        // Cargamos botones, en el orden fijo siempre
        $save_button = array(
            'id' => 'save',
            'label' => EntityLib::__('CRUD_SAVE_LABEL').(!empty($single) ? ' '.$single : ''),
            'tipo_icono' => 'google',
            'icon' => 'save_as',
            'tooltip' => EntityLib::__('CRUD_SAVE_LABEL'),
            'action' => 'save',
            'color' => 'add',
        );
        // Si no hay permisos para el edit, redirigiremos el save al view
        if(empty($recObject->getCrudAllowEdit()))
            $save_button['redirect'] = 'view';

        $save_list = array(
            'id' => 'saveBack',
            'label' => EntityLib::__('CRUD_SAVE_LIST_LABEL'),
            'tipo_icono' => 'google',
            'icon' => 'table_view',
            'tooltip' => EntityLib::__('CRUD_SAVE_LIST_TOOLTIP'),
            'action' => 'saveBack',
            'color' => 'add'
        );
        $save_add = array(
            'id' => 'saveNew',
            'label' => EntityLib::__('CRUD_SAVE_ADD_LABEL').(!empty($single) ? ' '.$single : ''),
            'tipo_icono' => 'google',
            'icon' => 'save',
            'tooltip' => EntityLib::__('CRUD_SAVE_ADD_TOOLTIP'),
            'action' => 'saveNew',
            'color' => 'add'
        );
        $back_to_list = array(
            'id' => 'undo',
            'label' => EntityLib::__('CRUD_BACK_LIST_LABEL'),
            'tipo_icono' => 'google',
            'icon' => 'undo',
            'tooltip' => EntityLib::__('CRUD_BACK_LIST_LABEL'),
            'action' => 'undo',
            'color' => 'accent'
        );
        $edit = array(
            'id' => 'edit',
            'label' => EntityLib::__('CRUD_EDIT_LABEL'),
            'tipo_icono' => 'google',
            'icon' => 'edit',
            'tooltip' => EntityLib::__('CRUD_EDIT_LABEL'),
            'action' => 'edit',
            'color' => 'add'
        );
        $buttons = array();
        /* - Comento por ahora
        if($recObject->getAllow('list'))
        {
            $buttons[] = $back_to_list;
        }
        */
        if(!$is_view) {
            if ($is_add && $recObject->getAllow('add')) {
                $buttons[] = $save_button;
                if($recObject->getAllow('list')) {
                    $buttons[] = $save_list;
                }
                $buttons[] = $save_add;
            }
            else if ($recObject->getAllow('edit') && $recObject->getAllow('save')) {
                $buttons[] = $save_button;
                if($recObject->getAllow('list')) {
                    $buttons[] = $save_list;
                }
            }
        }
        else if ($recObject->getAllow('edit') && $recObject->getAllow('save'))
            $buttons[] = $edit;

        //if(!$is_add) {
        if (!empty($custom_actions)) {
            foreach ($custom_actions as $ca) {
                $solo_listados = !empty($ca['only_list']);
                $solo_add = !empty($ca['allow_in_add']) ? 1 : 0;
                $show_in_add = true;
                if($solo_add)
                    $show_in_add = $is_add;
                if (!empty($ca) && (empty($ca['permiso']) || (!empty($ca['permiso']) && $recObject->getAllow($ca['permiso']))) && !$solo_listados && $show_in_add) {
                    if (!empty($ca['label']))
                        $ca['label'] = EntityLib::__($ca['label']);
                    if (!empty($ca['tooltip']))
                        $ca['tooltip'] = EntityLib::__($ca['tooltip']);

                    if($is_add && !empty($ca['allow_in_add']) || !$is_add)
                        $buttons[] = $ca;
                }
            }
        }
        //}

        // Quitamos botones que no correspondan
        $finalButtons = array();
        if(!empty($buttons))
        {
            foreach($buttons as $button)
            {
                if(!in_array($button['id'],$ignore_actions))
                    $finalButtons[] = $button;
            }
        }

        return $finalButtons;
    }

    public static function getListActions($recObject,$options=array())
    {
        $single = !empty($options['single']) ? $options['single'] : '';
        $plural = !empty($options['plural']) ? $options['plural'] : '';
        $debug = !empty($options['debug']);
        $custom_actions = !empty($options['custom_actions']) ? $options['custom_actions'] : array();
        $is_add = !empty($options['is_add']);
        // Cargamos botones, en el orden fijo siempre
        $add_button = array(
            'id' => 'add',
            'label' => EntityLib::__('CRUD_ADD_LABEL').(!empty($single) ? ' '.$single : ''),
            'tipo_icono' => 'google',
            'icon' => 'add',
            'tooltip' => EntityLib::__('CRUD_ADD_LABEL'),
            'action' => 'new',
            'color' => 'add',
        );
        // Cargamos botones, en el orden fijo siempre
        $pdf_button = array(
            'id' => 'export_pdf',
            'label' => EntityLib::__('CRUD_EXPORT_PDF_LABEL'),
            'tipo_icono' => 'google',
            'icon' => 'print',
            'tooltip' => EntityLib::__('CRUD_EXPORT_PDF_LABEL'),
            'action' => 'pdf',
            'color' => 'primary',
        );
        $xls_button = array(
            'id' => 'export_xls',
            'label' => EntityLib::__('CRUD_EXPORT_XLS_LABEL'),
            'tipo_icono' => 'google',
            'icon' => 'article',
            'tooltip' => EntityLib::__('CRUD_EXPORT_XLS_LABEL'),
            'action' => 'xls',
            'color' => 'primary',
        );
        $buttons = array();

        if(!empty($custom_actions))
        {
            foreach($custom_actions as $ca)
            {
                if(!empty($ca['head']))
                {
                    $allowed = empty($ca['permiso']) || $recObject->getAllow($ca['permiso']);
                    if($allowed) {
                        if (!empty($ca['label']))
                            $ca['label'] = EntityLib::__($ca['label']);
                        if (!empty($ca['tooltip']))
                            $ca['tooltip'] = EntityLib::__($ca['tooltip']);

                        if($allowed && !empty($ca['dimension']))
                        {
                            $allowed = (!empty($_SESSION) && !empty($_SESSION['__dimension__']) && !empty($_SESSION['__dimension__'][$ca['dimension']]));
                        }

                        // Tenemos en cuenta el disable in sublist para no cargar la acción en subtables!
                        if($allowed)
                        {
                            if(!empty($ca['disable_in_sublist']) && $recObject->getLevel() > 1)
                                $allowed = false;
                        }

                        if($allowed) $buttons[] = $ca;
                    }
                }
            }
        }

        if($recObject->getAllow('export_pdf'))
        {
            $buttons[] = $pdf_button;
        }
        if($recObject->getAllow('export_xls'))
        {
            $buttons[] = $xls_button;
        }
        if($recObject->getAllow('add'))
        {
            $buttons[] = $add_button;
        }

        return $buttons;
    }

    public static function validateEmailFormat($string)
    {
        //return filter_var($string, FILTER_VALIDATE_EMAIL);
        //$pattern = '/^[a-zA-Z0-9._-ñÑ]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$/';
        $pattern = '/^[a-zA-Z0-9._ñÑ-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$/';
        $email_valid = preg_match($pattern, $string);
        return $email_valid;
    }

    public static function getConfig($path = '')
    {
        $configs = array();
        $config = new Entity('_configuracion');
        $filters = array();
        if(!empty($path))
        {
            $filters['path'] = array('=' => $path);
        }
        $configValues = $config->getList(array('filters' => $filters));
        if(!empty($configValues) && !empty($configValues['data']))
            $configs = $configValues['data'];

        if(!empty($path))
        {
            if(!empty($configs))
                $configs = $configs[0]['value'];
        }



        return $configs;
    }

    public static function addToDate($date,$add,$format = 'Y-m-d')
    {
        return date($format, strtotime($add, strtotime($date)));
    }

    public static function getOptimizedPathForFile($filedata,$public = false)
    {
        $imgFiles = array('png', 'jpg', 'jpeg', 'bmp');
        $fileext = !empty($filedata['ext']) ? mb_strtolower($filedata['ext']) : '';
        $finalpath = '';
        $finalfilepath = '';
        if(in_array(strtolower($fileext),$imgFiles)) {
            $filepath = !empty($filedata['path']) ? __DIR__ . '/../' . $filedata['path'] : '';
            $finalpath = $filedata['path'];
            $finalfilepath = '';
            if (file_exists($filepath)) {
                $aux = explode("/", $filepath);
                $optimized_file_name = '';
                if (!empty($aux)) {
                    $aux2 = explode(".", $aux[count($aux) - 1]);
                    if (!empty($aux2) && !empty($aux2[count($aux2) - 2]))
                        $optimized_file_name = $aux2[count($aux2) - 2];
                }
                if (!empty($optimized_file_name)) {
                    $finalfilepath = str_replace($optimized_file_name . '.' . $fileext, $optimized_file_name . '_o.' . $fileext, mb_strtolower($filepath));
                    $finalpath = str_replace($optimized_file_name . '.' . $fileext, $optimized_file_name . '_o.' . $fileext, mb_strtolower($finalpath));
                }
            }
        }
        if($public)
            return $finalpath;
        else
            return $finalfilepath;
    }
    public static function getOptimizedWebPPathForFile($filedata,$public = false)
    {
        $imgFiles = array('png', 'jpg', 'jpeg', 'bmp');
        $fileext = !empty($filedata['ext']) ? mb_strtolower($filedata['ext']) : '';
        $finalpath = '';
        $finalfilepath = '';
        if(in_array(strtolower($fileext),$imgFiles)) {
            $filepath = !empty($filedata['path']) ? __DIR__ . '/../' . $filedata['path'] : '';
            $finalpath = $filedata['path'];
            $finalfilepath = '';
            if (file_exists($filepath)) {
                $aux = explode("/", $filepath);
                $optimized_file_name = '';
                if (!empty($aux)) {
                    $aux2 = explode(".", $aux[count($aux) - 1]);
                    if (!empty($aux2) && !empty($aux2[count($aux2) - 2]))
                        $optimized_file_name = $aux2[count($aux2) - 2];
                }

                if (!empty($optimized_file_name)) {
                    $finalfilepath = str_replace($optimized_file_name . '.' . $fileext, $optimized_file_name . '_o.webp', mb_strtolower($filepath));
                    $finalpath = str_replace($optimized_file_name . '.' . $fileext, $optimized_file_name . '_o.webp', mb_strtolower($finalpath));
                }
            }
        }
        if($public)
            return $finalpath;
        else
            return $finalfilepath;
    }

    public static function createOptimizedFile($filedata)
    {
        $imgFiles = array('png', 'jpg', 'jpeg', 'bmp');
        $fileext = !empty($filedata['ext']) ? $filedata['ext'] : '';
        if (in_array(strtolower($fileext), $imgFiles)) {
            $filepath = !empty($filedata['path']) ? __DIR__ . '/../' . $filedata['path'] : '';
            if (file_exists($filepath)) {
                $finalfilepath = EntityLib::getOptimizedPathForFile($filedata);
                if (!empty($finalfilepath) && !file_exists($finalfilepath)) {
                    // Load the image
                    $image = new Imagick($filepath);
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
                    if ($width > 1400 || $height > 1400) {
                        $needs_scaling = true;
                    }
                    // Scale the image if necessary
                    if ($needs_scaling) {
                        // Determine which dimension is larger, the width or height
                        $is_landscape = $width > $height;
                        // Calculate the new dimensions based on the largest dimension being 2560 pixels
                        if ($is_landscape) {
                            $new_width = 1400;
                            $new_height = intval($height * ($new_width / $width));
                        } else {
                            $new_height = 1400;
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

                    $result = $image->writeImage($finalfilepath);
                }
            }
        }
    }
    public static function createOptimizedFileWithOptions($filedata,$file_options)
    {
        $result = null;
        $publicfinalfilepath = null;
        $create_webp = (!empty($file_options) && !empty($file_options['webp']));
        $max_width = (!empty($file_options) && !empty($file_options['maxw'])) ? $file_options['maxw'] : 1800;
        $max_height = (!empty($file_options) && !empty($file_options['maxh'])) ? $file_options['maxh'] : 1800;
        $quality_compression = (!empty($file_options) && !empty($file_options['quality'])) ? floatval($file_options['quality']) : 0.00;
        if(!empty($quality_compression))
        {
            if($quality_compression <= 0.5 || $quality_compression > 1)
                $quality_compression = 0.00;
        }
        if(empty($quality_compression)) $quality_compression = $create_webp ? 0.9 : 0.72;
        $max_dpi = (!empty($file_options) && !empty($file_options['maxdpi'])) ? $file_options['maxdpi'] : 72;

        $imgFiles = array('png', 'jpg', 'jpeg', 'bmp');
        $fileext = !empty($filedata['ext']) ? $filedata['ext'] : '';
        if (in_array(strtolower($fileext), $imgFiles)) {
            $filepath = !empty($filedata['path']) ? __DIR__ . '/../' . $filedata['path'] : '';
            if (file_exists($filepath)) {
                if($create_webp) {
                    $finalfilepath = EntityLib::getOptimizedWebPPathForFile($filedata);
                    $publicfinalfilepath = EntityLib::getOptimizedWebPPathForFile($filedata,true);
                }
                else {
                    $finalfilepath = EntityLib::getOptimizedPathForFile($filedata);
                    $publicfinalfilepath = EntityLib::getOptimizedPathForFile($filedata,true);
                }
                if (!empty($finalfilepath)) {
                    // Load the image
                    $image = new Imagick($filepath);
                    // Determine the current DPI of the image
                    $resolution = $image->getImageResolution();
                    // Check if the image has a DPI greater than 72
                    if ($resolution['x'] > $max_dpi || $resolution['y'] > $max_dpi) {
                        // Set the DPI to 72
                        $image->setImageResolution($max_dpi, $max_dpi);
                        // Set the units of the image to pixels per inch
                        $image->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
                    }
                    // Get the current width and height of the image
                    $width = $image->getImageWidth();
                    $height = $image->getImageHeight();
                    // Determine if the image needs to be scaled
                    $needs_scaling = false;
                    if ($width > $max_width || $height > $max_height) {
                        $needs_scaling = true;
                    }
                    // Scale the image if necessary
                    if ($needs_scaling) {
                        // Determine which dimension is larger, the width or height
                        $is_landscape = $width > $height;
                        // Calculate the new dimensions based on the largest dimension being 2560 pixels
                        if ($is_landscape) {
                            $new_width = $max_width;
                            $new_height = intval($height * ($new_width / $width));
                        } else {
                            $new_height = $max_height;
                            $new_width = intval($width * ($new_height / $height));
                        }
                        // Scale the image to the new dimensions
                        $image->scaleImage($new_width, $new_height);
                    }
                    if($create_webp) $image->setFormat('webp');
                    // Set the image quality
                    $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                    //$image->setImageCompressionQuality($quality_compression);
                    $image->setImageCompressionQuality(intval($quality_compression*100));
                    // Write the optimized image to a file
                    $image->stripImage();
                    $result = $image->writeImage($finalfilepath);
                }
            }
        }
        else
        {
            $result = true;
            $finalfilepath = !empty($filedata['path']) ? __DIR__ . '/../' . $filedata['path'] : '';
            $publicfinalfilepath = $filedata['path'];
        }
        
        $final_result = null;
        if(!empty($result)) $final_result = array('relative' => $publicfinalfilepath,'absolute' => $finalfilepath);
        if(empty($final_result)) throw new Exception(EntityLib::__('API_ERROR_IMAGE_CREATE',array($filedata['name'])));
        return $final_result;
    }

    public static function getTimestamp()
    {
        $microtime = microtime();
        $comps = explode(' ', $microtime);
        // Note: Using a string here to prevent loss of precision
        // in case of "overflow" (PHP converts it to a double)
        return sprintf('%d%03d', $comps[1], $comps[0] * 1000);
    }

    public static function getTextValueForRol($text)
    {
        $final_text = $text;
        $roles_usuario = (!empty($_SESSION) && !empty($_SESSION['__rol_codes__'])) ? $_SESSION['__rol_codes__'] : array();
        $primer_rol_usuario = 'ALL';
        if(!empty($roles_usuario))
        {
            $primer_rol_usuario = $roles_usuario[0];
        }
        if (!empty($text)) {
            $es_segun_rol = strpos($text,'~#~') !== false;
            if($es_segun_rol)
            {
                $textos_roles = array();
                $auxiliar_textos = explode('~#~',$text);
                foreach($auxiliar_textos as $aux_rol_text)
                {
                    $aux = explode(']',$aux_rol_text);
                    $auxiliar_roles = !empty($aux[0]) ? substr($aux[0],1) : 'ALL';
                    $auxiliar_texto = !empty($aux[1]) ? $aux[1] : $aux_rol_text;
                    $multiples_roles = strpos($auxiliar_roles,',') !== false;
                    $roles_para_texto = array();
                    if($multiples_roles)
                    {
                        $roles_para_texto = explode(',',$auxiliar_roles);
                    }
                    else
                    {
                        $roles_para_texto[] = $auxiliar_roles;
                    }
                    foreach($roles_para_texto as $rpt)
                    {
                        $textos_roles[$rpt] = $auxiliar_texto;
                    }
                }
                $texto_para_rol = !empty($textos_roles[$primer_rol_usuario]) ? $textos_roles[$primer_rol_usuario] : '';
                if(empty($texto_para_rol))
                    $texto_para_rol = !empty($textos_roles['ALL']) ? $textos_roles['ALL'] : '';
                $final_text = $texto_para_rol;
            }
        }
        return $final_text;
    }


    // Genera filtros para un where
    // Se espera un array de datos con filtros AND
    // DEPRECATED 2024-09!
    public static function whereGenerator($filtersToAdd,$fieldInfo,$tableName,$filter_union = 'AND')
    {
        $where_query = '';
        $is_first = true;
        foreach ($filtersToAdd as $fta_field => $fta_filter) {

            if (!$is_first) {
                $where_query .= " ".$filter_union." ";
            }

            if(is_integer($fta_field)) {
                $this_union = $fta_filter['__union__'];
                $fta_filter = $fta_filter['__conditions__'];
                $where_query .= "(";
                $is_first_multiple = true;
                foreach ($fta_filter as $multiple_condition) {
                    if (!$is_first_multiple) {
                        $where_query .= " " . $this_union." ";
                    }
                    else
                        $is_first_multiple = false;
                    $where_query .= "(";
                    $where_query .= self::whereGenerator($multiple_condition, $fieldInfo, $tableName);
                    $where_query .= ")";
                }
                $where_query .= ")";

            }
            else {
                $fields = array();
                $or_added_parentesis = false;
                $fields[$fta_field] = $fta_filter;

                foreach ($fields as $field => $filter) {
                    $es_filtro_subtabla = strpos($field, '.') !== false;

                    $fInfo = !empty($fieldInfo[$fta_field]) ? $fieldInfo[$fta_field] : array();
                    $es_filtro_en_campo_str = (!empty($fInfo) && !empty($fInfo['type']) && in_array($fInfo['type'],array('varchar','text')));

                    if (count($filter) > 1) {
                        $where_query .= " (";
                    }
                    $first_subfilter = true;

                    foreach ($filter as $condition => $value) {

                        if (!$first_subfilter)
                            $where_query .= " AND ";
                        else
                            $first_subfilter = false;

                        $is_md5 = $condition === 'MD5';
                        $value = $filter[$condition];

                        $condition = strtoupper($condition);

                        // En los campos que sean related filtraremos siempre por IN en vez de =
                        if (!empty($fieldInfo[$field]) && !empty($fieldInfo[$field]['type'])) {
                            $is_related = in_array($fieldInfo[$field]['type'], array('related','related-combo','enum'));
                            if ($is_related && !is_null($value)) {
                                if($condition == '=') $condition = 'IN';
                                else if($condition == '!=') $condition = 'NOT IN';
                                // En caso de que no se esté filtrando por un array directamente, forzamos
                                if (!is_array($value))
                                    $value = array($value);
                            }
                        }
                        if (is_bool($value))
                            $value = ($value === true || $value === 1) ? 1 : 0;

                        if (!($condition === 'IN' || $condition === 'NOT IN')) {
                            if($es_filtro_en_campo_str && !empty($value)) {
                                $value = ''.$value;
                            }
                        }


                        if ($condition === 'IN' || $condition === 'NOT IN') {
                            if (!empty($value)) {
                                //$value = "(".implode(',',$value).")";
                                $aux_str = "(";
                                $aux_str_vals = "";
                                foreach ($value as $auxval) {
                                    if (strlen($aux_str_vals) > 0)
                                        $aux_str_vals .= ",";

                                    if($es_filtro_en_campo_str && !empty($auxval)) {
                                        $auxval = ''.$auxval;
                                    }

                                    if (is_numeric($auxval)) {
                                        $aux_str_vals .= strval($auxval);
                                    }
                                    else
                                        $aux_str_vals .= "'" . $auxval . "'";
                                }
                                $aux_str .= $aux_str_vals . ")";
                                $value = $aux_str;
                            } else {
                                $condition = "IS";
                                $value = "NULL AND " . $tableName . ".`" . $field . "` IS NOT NULL";
                            }
                        } else if ($is_md5) {
                            $condition = '=';
                            $value = "MD5('" . $value . "')";
                        } else if (is_string($value)) {
                            if (is_null($value)) {
                                if(in_array($condition,array('='))) $condition = 'IS';
                                if(in_array($condition,array('!='))) $condition = 'IS NOT';
                                $value = "NULL";
                            }
                            else
                                $value = "'" . $value . "'";
                        } else {
                            if (is_null($value)) {
                                if(in_array($condition,array('='))) $condition = 'IS';
                                if(in_array($condition,array('!='))) $condition = 'IS NOT';
                                $value = "NULL";
                            }
                        }

                        if ($es_filtro_subtabla)
                            $where_query .= " " . $field . " " . $condition . " " . $value;
                        else {
                            if(is_array($value))
                            {
                                echo '<pre>';print_r($field);echo '</pre>';
                                echo '<pre>';print_r($condition);echo '</pre>';
                                echo '<pre>';print_r($value);echo '</pre>';
                            }
                            $where_query .= " " . $tableName . ".`" . $field . "` " . $condition . " " . $value;
                        }

                    }
                    if (count($filter) > 1) {
                        $where_query .= " )";
                    }
                }
            }

            if ($is_first)
                $is_first = false;

        }

        /*if(!empty($_SESSION) && !empty($_SESSION['__user_id__']) && $_SESSION['__user_id__'] == 1)
        {
            echo '<pre>';print_r($filtersToAdd);echo '</pre>';
            echo '<pre>';print_r($where_query);echo '</pre>';
        }*/
        
        return $where_query;
    }

    public static function getJsonFileFromTableView($table,$view,$alternative_view = '')
    {
        $result = null;
        $content = null;
        $full_path = __DIR__.'/../form/'.$table.'_'.$view.'.json';
        if(file_exists($full_path))
        {
            $content = file_get_contents($full_path);
        }

        $alternative_view_2 = '';
        $alternative_view_3 = '';
        // Si estoy pidiendo un ADD o un VIEW y no existe el fichero, compruebo EDIT
        if(is_null($content))
        {

            // Forzamos a que si no se ha especificado 3er parametro, en caso de ADD o VIEW se redirija al EDIT
            if(empty($alternative_view) && $alternative_view !== false)
            {
                if(in_array($view,array('add','view','subtable_edit','subtable_view')))
                    $alternative_view = 'edit';
                else if(in_array($view,array('subtable_add'))) {
                    $alternative_view = 'subtable_edit';
                    $alternative_view_2 = 'add';
                    $alternative_view_3 = 'edit';

                }
            }
            if(!empty($alternative_view)) {
                $full_path = __DIR__ . '/../form/' . $table . '_' . $alternative_view . '.json';
                if (file_exists($full_path)) {
                    $content = file_get_contents($full_path);
                }
            }

            if(is_null($content))
            {
                if(!empty($alternative_view_2)) {
                    $full_path = __DIR__ . '/../form/' . $table . '_' . $alternative_view_2 . '.json';
                    if (file_exists($full_path)) {
                        $content = file_get_contents($full_path);
                    }
                }
            }

            if(is_null($content))
            {
                if(!empty($alternative_view_3)) {
                    $full_path = __DIR__ . '/../form/' . $table . '_' . $alternative_view_3 . '.json';
                    if (file_exists($full_path)) {
                        $content = file_get_contents($full_path);
                    }
                }
            }
        }

        if(!empty($content))
            $result = base64_encode($content);
        return $result;
    }

    public static function formatOutputForPdf($string)
    {
        //return iconv("UTF-8","ISO-8859-1//TRANSLIT",$string);
        return iconv("UTF-8",'windows-1252',$string);
    }

    public static function sqlDate2Date($date_str)
    {
        $date = date('d/m/Y',strtotime($date_str));
        return $date;
    }
    public static function sqlDatetime2Datetime($datetime_str,$include_seconds = true)
    {
        if($include_seconds)
            $format = 'd/m/Y H:i:s';
        else
            $format = 'd/m/Y H:i';

        $date = date($format,strtotime($datetime_str));
        return $date;
    }
    public static function sqlDatetime2Time($datetime_str,$include_seconds = true)
    {
        if($include_seconds)
            $format = 'H:i:s';
        else
            $format = 'H:i';
        $date = date($format,strtotime($datetime_str));
        return $date;
    }
    public static function gmtDatetime2sqlDatetime($datetime_str)
    {
        $fecha = new DateTime($datetime_str, new DateTimeZone('GMT'));
        $fecha->setTimezone(new DateTimeZone('Europe/Madrid'));
        $date = $fecha->format('Y-m-d H:i:s');
        return $date;
    }
    public static function datetime2sqlDatetime($datetime_str)
    {
        $fecha = new DateTime($datetime_str, new DateTimeZone('Europe/Madrid'));
        //$fecha->setTimezone(new DateTimeZone('Europe/Madrid'));
        $date = $fecha->format('Y-m-d H:i:s');
        return $date;
    }

    public static function excelDate2Date($dateValue = 0) {
        if(empty($dateValue)) return null;
        $timestamp = (intval($dateValue) - 25569) * 86400;
        return date('Y-m-d',$timestamp);
    }
    public static function datetimeObject2Datetime($dateValue = null) {
        if(empty($dateValue)) return null;
        return $dateValue->format('Y-m-d H:i:s');
    }
    public static function datetimeObject2Date($dateValue = null) {
        if(empty($dateValue)) return null;
        return $dateValue->format('Y-m-d');
    }

    public static function debugSequence($message,$add = false)
    {
        if(!empty($_SESSION) && array_key_exists('__debug_backtrace__',$_SESSION))
        {
            if($add) $message = '--- '.$message;
            $_SESSION['__debug_backtrace__'][] = $message;
        }
    }

    public static function getServerName()
    {
        if(!empty($_SERVER) && !empty($_SERVER['SERVER_NAME']))
            return $_SERVER['SERVER_NAME'];

        // Si no hay SERVER_NAME buscaremos en el fichero de forzar
        $forceOptionsPathFile = __DIR__.'/options/forceoptions.json';
        if(file_exists($forceOptionsPathFile))
        {
            $jsonOptions = file_get_contents($forceOptionsPathFile);
            $force_options = json_decode($jsonOptions,JSON_UNESCAPED_UNICODE);
            if(!empty($force_options['servername']))
                return $force_options['servername'];
        }

        if(!empty($_SERVER) && !empty($_SERVER['HOME']))
        {
            $homeDir = $_SERVER['HOME'];
            $homeDirAux = explode('/',$_SERVER['HOME']);
            $homeDir = $homeDirAux[count($homeDirAux) - 1];
            return $homeDir;
        }
        else
            throw new Exception('Error obteniendo ServerName');
    }

    public static function guidv4() {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = random_bytes(16);
        assert(strlen($data) == 16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        // Output the 36 character UUID.
        return $uuid;
    }

    public static function forceAllInputsFromDefDisabled(&$def)
    {
        $fieldsView = (!empty($def) && !empty($def['fields'])) ? $def['fields'] : array();
        if (!empty($fieldsView)) {
            foreach ($fieldsView as $fN => $fV) {
                $is_wizard_field = strpos($fN,'wizard') !== false;
                if(!$is_wizard_field)
                    $def['fields'][$fN]['editable'] = 0;
            }
        }
        if(!empty($def) && !empty($def['related_def']))
        {
            foreach($def['related_def'] as $rd_key => $rd_values)
            {
                self::forceAllInputsFromDefDisabled($def['related_def'][$rd_key]);
            }
        }
    }

    public static function debugHelper($message)
    {
        echo '<pre>';print_r($message);echo '</pre>';
        echo '<pre>';print_r("\n");echo '</pre>';
        debug_print_backtrace();
        echo '<pre>';print_r("\n");echo '</pre>';
    }

    public static function checkPasswordRequirements($new_password,$type = 0)
    {
        $valid = false;
        try {
            $min_length = 8;
            $min_may = 0;
            $min_min = 0;
            $min_num = 0;
            $min_esp = 0;
            switch ($type) {
                case 1 :
                case 2 :
                case 3 :
                    $min_length = 8;
                    $min_may = 1;
                    $min_min = 1;
                    $min_num = 1;
                    $min_esp = 1;
                    switch ($type) {
                        case 1:
                            break;
                        case 2:
                            break;
                        case 3:
                            break;
                        default:
                            break;
                    }
                    break;
                // Opción por defecto -> Longitud mínima 8
                case 0:
                default:
                    break;
            }

            $pass_counters = self::countCharsForPassword($new_password);
            $contador_err = !empty($pass_counters['err']) ? $pass_counters['err'] : 0;
            $contador_may = !empty($pass_counters['may']) ? $pass_counters['may'] : 0;
            $contador_min = !empty($pass_counters['min']) ? $pass_counters['min'] : 0;
            $contador_num = !empty($pass_counters['num']) ? $pass_counters['num'] : 0;
            $contador_esp = !empty($pass_counters['esp']) ? $pass_counters['esp'] : 0;
            $valid = !(
                strlen($new_password) < $min_length ||
                $contador_err > 0 ||
                $contador_may < $min_may ||
                $contador_min < $min_min ||
                $contador_num < $min_num ||
                $contador_esp < $min_esp
            );
        }catch(Exception $e)
        {
            $valid = false;
        }
        return $valid;
    }
    private static function countCharsForPassword($cadena) {
        $contador_may = 0;
        $contador_min = 0;
        $contador_num = 0;
        $contador_esp = 0;
        $contador_no_validos = 0;
        $caracteres_esp = "~`!@#$%^&*()_-+={[}]|\\:;\"'<,>.?/";
        $longitud = strlen($cadena);
        for ($i = 0; $i < $longitud; $i++) {
            if (ctype_upper($cadena[$i])) {
                $contador_may++;
            }
            else if (ctype_lower($cadena[$i])) {
                $contador_min++;
            }
            elseif (ctype_digit($cadena[$i])) {
                $contador_num++;
            }
            elseif (strpos($caracteres_esp, $cadena[$i]) !== false) {
                $contador_esp++;
            }
            else {
                $contador_no_validos++;
            }
        }
        return array(
            'may' => $contador_may,
            'min' => $contador_min,
            'num' => $contador_num,
            'esp' => $contador_esp,
            'err' => $contador_no_validos
        );
    }

    public static function getDateAsString($fecha,$format)
    {
        $date = new DateTime($fecha);
        $formatter = new IntlDateFormatter(
            'es_ES',
            IntlDateFormatter::FULL,
            IntlDateFormatter::FULL,
            'Europe/Madrid',
            IntlDateFormatter::GREGORIAN,
            $format
        );
        $fecha_formateada = $formatter->format($date);
        return $fecha_formateada;
    }


    // DEPRECATED 2024-09!
    public static function generateSqlv2($table,$type,$values,$where=array(),$def = array())
    {
        /*
        echo '<pre>';print_r($table);echo '</pre>';
        echo '<pre>';print_r($type);echo '</pre>';
        echo '<pre>';print_r($values);echo '</pre>';
        echo '<pre>';print_r($where);echo '</pre>';
        */
        $sql = '';
        $sql_params = array();
        $type = strtoupper($type);
        switch($type)
        {
            case 'INSERT' :
                $sql = $type." INTO $table ({fieldlist}) VALUES ({valuelist})";
                $generate = array('fieldlist','valuelist');
                break;
            case 'UPDATE' :
                if(empty($where))
                    throw new Exception(EntityLib::__('API_QUERY_ERROR_UPDATEWHERE'));
                $sql = $type." $table SET {updatelist} WHERE {wherelist}";
                $generate = array('updatelist','wherelist');
                break;
            case 'DELETE' :
                if(empty($where))
                    throw new Exception(EntityLib::__('API_QUERY_ERROR_DELETEWHERE'));
                $sql = $type." FROM $table WHERE {wherelist}";
                $generate = array('wherelist');
                break;
            default :
                throw new Exception(EntityLib::__('API_QUERY_UNKNOWN'));
        }


        $part_values = self::generateQueryPartv2($generate,$values,$where,$def);
        foreach($generate as $gen)
        {
            $querypart = $part_values[$gen];
            if($gen == 'fieldlist')
                $sql = str_replace('{'.$gen.'}',$querypart,$sql);
            else
            {
                $sql = str_replace('{'.$gen.'}',$querypart[0],$sql);
                foreach($querypart[1] as $prm){
                    $sql_params[] = $prm;
                }
            }
        }

        /*
        echo '<pre>';print_r($sql);echo '</pre>';
        echo '<pre>';print_r($sql_params);echo '</pre>';
        die;
        */

        return array(
            $sql,
            $sql_params
        );
    }

    /* Función auxiliar para generar solo una parte de la query */
    // DEPRECATED 2024-09!
    public static function generateQueryPartv2($generates,$values,$where,$def){

        $fieldlist = $valuelist = $updatelist = $wherelist = '';
        $valueParams = $updateParams = $whereParams = array();

        $is_fieldlist = in_array('fieldlist',$generates);
        $is_valuelist = in_array('valuelist',$generates);
        $is_updatelist = in_array('updatelist',$generates);
        $is_wherelist = in_array('wherelist',$generates);
        $nullable_Fields = array('text','varchar','enum','enum-multi','date','datetime','time','related','decimal','int','html','code','email','related','related-combo');

        // Recorremos registros
        foreach($values as $field => $value)
        {

            // Dependiendo del tipo de query mostramos
            $fieldInfo = array();
            if(!empty($def) && !empty($def['fields']) && !empty($def['fields'][$field]))
            {
                $fieldInfo = $def['fields'][$field];
            }

            $fieldType = !empty($fieldInfo['type']) ? $fieldInfo['type'] : 'varchar';
            $no_real_rield = !empty($fieldInfo['no_real']);
            $fieldAllowNull = !empty($fieldInfo['allow_null']) || (!empty($fieldInfo['type']) && in_array($fieldInfo['type'],$nullable_Fields));
            $isNullValue = false;
            $valueSeparator = '';

            // Si el campo viene como no_real, evitamos que se introduzca en cualquier tipo de consulta para evitar errores de SQL
            if(!$no_real_rield) {
                switch ($fieldType) {
                    case 'varchar' :
                    case 'text' :
                    case 'link' :
                    case 'date' :
                    case 'datetime' :
                    case 'time' :
                    case 'enum' :
                    case 'html' :
                    case 'code' :
                    case 'file' :
                    case 'email' :
                        /*if (!empty($value) && strpos($value, "'") !== false) {
                            $value = str_replace("'", "\'", $value);
                        }*/
                        if (!empty($value) && strpos($value, "“") !== false) {
                            $value = str_replace("“", '"', $value);
                        }
                        $isNullValue = is_null($value);
                        if(!$isNullValue)
                            $value = ''.$value;
                        $valueSeparator = "'";
                        break;
                    case 'enum-multi' :
                        $isNullValue = !empty($value);
                        if(!empty($value))
                            $value = implode(',',$value);
                        $valueSeparator = "'";
                        break;
                    case 'int' :
                    case 'related' :
                    case 'related-combo' :
                    case 'select' :
                        $isNullValue = is_null($value);
                        if (!$isNullValue)
                            $value = intval($value);
                        break;
                    case 'gallery' :
                    case 'attachment' :
                    case 'attachment_custom' :
                        $value = intval($value);
                        break;
                    case 'boolean' :
                        $isNullValue = is_null($value);
                        $value = (!empty($value) && ($value === true || $value === 1)) ? 1 : 0;
                        break;
                    case 'decimal' :
                        $isNullValue = is_null($value);
                        if (!$isNullValue)
                            $value = floatval($value);
                        break;
                    /*case 'file' :
                        $isNullValue = is_null($value);
                        $valueSeparator = "'";
                        break;*/
                    default :
                        $isNullValue = is_null($value);
                        $valueSeparator = "'";
                        break;
                }

                if ($is_fieldlist) {
                    if (!empty($fieldlist) || strlen($fieldlist) > 0)
                        $fieldlist .= ',';
                    $fieldlist .= "`" . $field . "`";
                }

                if ($is_updatelist) {
                    if (!empty($updatelist) || strlen($updatelist) > 0)
                        $updatelist .= ', ';

                    //if($fieldAllowNull && (is_null($value) || empty($value)))
                    if ($fieldAllowNull && (is_null($value)))
                        $updatelist .= "`" . $field . "` = NULL";
                    else {
                        //$updatelist .= "`" . $field . "` = " . $valueSeparator . $value . $valueSeparator;
                        $updatelist .= "`" . $field . "` = ?";
                        $updateParams[] = $value;
                    }

                }
                if ($is_valuelist) {
                    if (!empty($valuelist) || strlen($valuelist) > 0)
                        $valuelist .= ',';

                    if ($fieldAllowNull && is_null($value))
                        $valuelist .= 'NULL';
                    else {
                        //$valuelist .= $valueSeparator . $value . $valueSeparator;
                        $valuelist .= '?';
                        $valueParams[] = $value;
                    }
                }
            }

        }

        if(!empty($where) && $is_wherelist)
        {
            foreach($where as $field => $value) {

                $valueSeparator = '';
                // Dependiendo del tipo de query mostramos
                $fieldInfo = array();
                if(!empty($def) && !empty($def['fields']) && !empty($def['fields'][$field]))
                {
                    $fieldInfo = $def['fields'][$field];
                }

                switch($fieldType)
                {
                    case 'varchar' :
                    case 'text' :
                    case 'link' :
                    case 'date' :
                    case 'datetime' :
                    case 'time' :
                    case 'enum' :
                    case 'enum-multi' :
                    case 'html' :
                    case 'code' :
                    case 'email' :
                        $isNullValue = is_null($value);
                        if(!$isNullValue) $value = ''.$value;
                        $valueSeparator = "'";
                        break;
                    case 'int' :
                        $isNullValue = is_null($value);
                        $value = intval($value);
                        break;
                    case 'boolean' :
                        $isNullValue = is_null($value);
                        $value = (!empty($value) && ($value === true || $value === 1)) ? 1 : 0;
                        break;
                    case 'decimal' :
                        $isNullValue = is_null($value);
                        $value = floatval($value);
                        break;
                    default :
                        $isNullValue = is_null($value);
                        $valueSeparator = "'";
                        break;
                }

                if (!empty($wherelist))
                    $wherelist .= ' AND ';

                if($fieldAllowNull && is_null($value))
                    $wherelist .= "`" . $field . "` = NULL";
                else {
                    //$wherelist .= "`" . $field . "` = " . $valueSeparator . $value . $valueSeparator;
                    $wherelist .= "`" . $field . "` = ?";
                    $whereParams[] = $value;
                }
            }
        }

        $result = array(
            'fieldlist' => $fieldlist,
            'valuelist' => !empty($valuelist) ? array($valuelist,$valueParams) : array(),
            'updatelist' => !empty($updatelist) ? array($updatelist,$updateParams) : array(),
            'wherelist' => !empty($wherelist) ? array($wherelist,$whereParams) : array(),
        );
        //echo '<pre>';print_r($result);echo '</pre>';
        return $result;
    }

    public static function whereGeneratorv2($filtersToAdd,$fieldInfo,$tableName,$filter_union = 'AND')
    {
        /*echo '<pre>';print_r($filtersToAdd);echo '</pre>';
        echo '<pre>';print_r("\n");echo '</pre>';*/
        $where_query = '';
        $where_params = array();
        $is_first = true;
        foreach ($filtersToAdd as $fta_field => $fta_filter) {

            if (!$is_first) {
                $where_query .= " ".$filter_union." ";
            }

            if(is_integer($fta_field)) {
                $this_union = $fta_filter['__union__'];
                $fta_filter = $fta_filter['__conditions__'];
                $where_query .= "(";
                $is_first_multiple = true;
                foreach ($fta_filter as $multiple_condition) {
                    if (!$is_first_multiple) {
                        $where_query .= " " . $this_union." ";
                    }
                    else
                        $is_first_multiple = false;
                    $where_query .= "(";
                    $where_aux = self::whereGeneratorv2($multiple_condition, $fieldInfo, $tableName);
                    $where_query .= $where_aux[0];
                    foreach($where_aux[1] as $wa)
                    {
                        $where_params[] = $wa;;
                    }
                    //$where_query .= self::whereGenerator($multiple_condition, $fieldInfo, $tableName);
                    $where_query .= ")";
                }
                $where_query .= ")";

            }
            else {
                $fields = array();
                $or_added_parentesis = false;
                $fields[$fta_field] = $fta_filter;

                foreach ($fields as $field => $filter) {
                    $es_filtro_subtabla = strpos($field, '.') !== false;

                    $fInfo = !empty($fieldInfo[$fta_field]) ? $fieldInfo[$fta_field] : array();
                    $es_filtro_en_campo_str = (!empty($fInfo) && !empty($fInfo['type']) && in_array($fInfo['type'],array('varchar','text')));

                    if (count($filter) > 1) {
                        $where_query .= " (";
                    }
                    $first_subfilter = true;

                    foreach ($filter as $condition => $value) {

                        if (!$first_subfilter)
                            $where_query .= " AND ";
                        else
                            $first_subfilter = false;

                        $is_md5 = $condition === 'MD5';
                        $value = $filter[$condition];

                        $condition = strtoupper($condition);
                        $post_condition = '';
                        $ignore_espace = false;
                        $added_params = false;

                        // En los campos que sean related filtraremos siempre por IN en vez de =
                        if (!empty($fieldInfo[$field]) && !empty($fieldInfo[$field]['type'])) {
                            $is_related = in_array($fieldInfo[$field]['type'], array('related','related-combo','enum'));
                            if ($is_related && !is_null($value)) {
                                if($condition == '=') $condition = 'IN';
                                else if($condition == '!=') $condition = 'NOT IN';
                                // En caso de que no se esté filtrando por un array directamente, forzamos a que sea un array si no es LIKE, NOT LIKE
                                if (in_array($condition,array('IN','NOT IN')) && !is_array($value))
                                    $value = array($value);
                            }
                        }
                        if (is_bool($value))
                            $value = ($value === true || $value === 1) ? 1 : 0;

                        if (!($condition === 'IN' || $condition === 'NOT IN')) {
                            if($es_filtro_en_campo_str && !empty($value)) {
                                $value = ''.$value;
                            }
                        }


                        if ($condition === 'IN' || $condition === 'NOT IN') {
                            $added_params = true;
                            if (!empty($value)) {
                                //$value = "(".implode(',',$value).")";
                                $aux_str = "(";
                                $aux_str_vals = "";
                                foreach ($value as $auxval) {
                                    if (strlen($aux_str_vals) > 0)
                                        $aux_str_vals .= ",";

                                    if($es_filtro_en_campo_str && !empty($auxval)) {
                                        $auxval = ''.$auxval;
                                    }

                                    if (is_numeric($auxval)) {
                                        //$aux_str_vals .= strval($auxval);
                                        $aux_str_vals .= "?";
                                        $where_params[] = ''.$auxval;
                                    }
                                    else {
                                        $aux_str_vals .= "?";
                                        $where_params[] = $auxval;
                                        //$aux_str_vals .= "'" . $auxval . "'";
                                    }
                                }
                                $aux_str .= $aux_str_vals . ")";
                                $value = $aux_str;
                            } else {
                                $condition = "IS";
                                $value = "NULL AND " . $tableName . ".`" . $field . "` IS NOT NULL";
                            }
                        } else if ($is_md5) {
                            $condition = '= MD5(';
                            $post_condition = ')';
                            $ignore_espace = true;
                            //$value = "MD5('" . $value . "')";
                        } else if (is_string($value)) {
                            if (is_null($value)) {
                                if(in_array($condition,array('='))) $condition = 'IS';
                                if(in_array($condition,array('!='))) $condition = 'IS NOT';
                                $value = null;
                            }
                            else
                                $value = ''.$value;
                        } else {
                            if (is_null($value)) {
                                if(in_array($condition,array('='))) $condition = 'IS';
                                if(in_array($condition,array('!='))) $condition = 'IS NOT';
                                $value = "NULL";
                            }
                        }

                        $espace_before = empty($ignore_espace) ? " " : "";
                        if(in_array($condition,array('LIKE','NOT LIKE')))
                        {
                            $empieza_por_pct = !empty($value) && substr($value,0,1) == '%';
                            $acaba_por_pct = !empty($value) && substr($value,-1) == '%';
                            if($empieza_por_pct || $acaba_por_pct)
                            {
                                $espace_before .= "CONCAT(";
                                if($empieza_por_pct) $espace_before .= "'%',";
                                if($acaba_por_pct) $post_condition .= ",'%'";
                                $post_condition .= ")";
                                $value = str_replace('%','',$value);
                            }
                        }
                        else if(in_array($condition, array('IS','IS NOT')))
                        {
                            $added_params = true;
                            $value = 'NULL';
                        }
                        if ($es_filtro_subtabla) {
                            if(!empty($added_params))
                            {
                                $where_query .= " " . $field . " " . $condition . $espace_before . $value . $post_condition;
                            }
                            else {
                                $where_query .= " " . $field . " " . $condition . $espace_before . "?" . $post_condition;
                                $where_params[] = $value;
                            }
                        }
                        else {
                            if(!empty($added_params))
                            {
                                $where_query .= " " . $tableName . ".`" . $field . "` " . $condition . $espace_before . $value . $post_condition;
                            }
                            else {
                                $where_query .= " " . $tableName . ".`" . $field . "` " . $condition . $espace_before . "?" . $post_condition;
                                $where_params[] = $value;
                            }
                        }

                    }
                    if (count($filter) > 1) {
                        $where_query .= " )";
                    }
                }
            }

            if ($is_first)
                $is_first = false;

        }

        return array(
            $where_query,
            $where_params
        );
    }

}

?>