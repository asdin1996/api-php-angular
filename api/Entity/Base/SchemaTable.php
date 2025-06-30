<?php

// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');
require_once(__DIR__ . '/../../ClassLoader.php');

class SchemaTable extends Entity
{
    function __construct($tablename='__schema_tables', $main_rec = false)
    {
        parent::__construct('__schema_tables', $main_rec);
    }

    function fncCreateFieldsFromTable($id,$data)
    {
        $this_data = $this->getById($id);
        $result = array(
            'redirect' => '/utils/createdb.php?table='.$this_data['table'],
            'target' => '_blank',
        );
        return $result;
    }
    function fncCreateFieldsFromTableHead($id,$data)
    {
        $tablename = !(empty($data['wizard1_tablename'])) ? $data['wizard1_tablename'] : '';
        $tablename = trim($tablename);
        $tablename = str_replace(' ','',$tablename);
        if(empty($tablename)) throw new Exception(EntityLib::__('API_WRONG_TABLENAME'));
        $result = array(
            'redirect' => '/utils/createdb.php?table='.$tablename,
            'target' => '_blank',
        );
        return $result;
    }

    function fncCheckFields($id,$data)
    {
        $result = array(
            'redirect' => '/utils/createdb.php?table='.$data['table'],
            'target' => '_blank',
        );
        return $result;
    }
}

