<?php

// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');

class Configuracion extends Entity
{
    function __construct($tablename='_configuracion', $main_rec = false)
    {
        parent::__construct('_configuracion', $main_rec);
    }

    // Función que devuelve configuración.
    // Dependiendo de si se pasa o no nombre, devolverá todos los valores o solo el valor de configuración
    public function getConfig($name = '')
    {
        $filters = array(
            'deleted' => array('=' => 0),
        );
        if(!empty($name))
        {
            $filters['path'] = array('=' => $name);
        }
        $data = $this->getList(array('filters' => $filters));
        if(!empty($name))
        {
            $result = '';
            if(!empty($data) && !empty($data['data']) && !empty($data['data'][0]))
                $result = $data['data'][0]['value'];
        }
        else
        {
            $result = array();
            if(!empty($data) && !empty($data['data']))
                $result = $data['data'][0];
        }
        return $result;
    }


}

