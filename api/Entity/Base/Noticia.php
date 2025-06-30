<?php

// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');

class Noticia extends Entity
{
    function __construct($tablename='_noticias', $main_rec = false)
    {
        parent::__construct('_noticias', $main_rec);
    }

    public function getList($params = array())
    {
        // Forzamos a filtrar por estado = publico si no se ha definido un filtro para estado
        if(!empty($params) && !empty($params['from_api'])) {
            if (empty($params['filters']))
                $params['filters'] = array();
            if (empty($params['filters']['estado']))
                $params['filters']['estado'] = array('=' => 'publico');
        }
        $result = parent::getList($params);
        return $result;
    }

}

