<?php

// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');

class UsuarioDimension extends Entity
{
    function __construct($tablename='usuarios_dimensiones', $main_rec = false)
    {
        parent::__construct('usuarios_dimensiones', $main_rec);
    }

    public function beforeSave(&$data){
        $errors = parent::beforeSave($data);

        if(!empty($data['dimension_fake']) && !empty($data['valor_fake']))
        {
            $cambiar_dimension = true;
            $nueva_dimension = '';
            $nuevo_id = 0;
            $dimension_fake = $data['dimension_fake'];
            $valor_fake = $data['valor_fake'];
            $dimensionObj = ClassLoader::getModelObject('_dimensiones',false);
            $dimension_data = $dimensionObj->getById($dimension_fake);
            if(!empty($dimension_data))
            {
                $nueva_dimension = !empty($dimension_data['data_table']) ? $dimension_data['data_table'] : '';
                if(!empty($nueva_dimension))
                {
                    $relatedObj = ClassLoader::getModelObject($nueva_dimension,false);
                    $nuevo_data = $relatedObj->getById($valor_fake);
                    if(!empty($nuevo_data))
                        $nuevo_id = $valor_fake;
                }
            }

            if($cambiar_dimension)
            {
                if(!empty($nueva_dimension) && !empty($nuevo_id))
                {
                    $data['dimension'] = $nueva_dimension;
                    $data['valor'] = $nuevo_id;
                }
                else
                {
                    $data['dimension'] = null;
                    $data['valor'] = null;
                }
                unset($data['dimension_fake']);
                unset($data['dimension_fake_value']);
                unset($data['valor_fake']);
                unset($data['valor_fake_value']);
            }

        }
        return $errors;
    }

    public function processItem(&$rec)
    {
        if($this->level <= 2) {
            $dimension = $rec['dimension'];
            $dimension_valor = !empty($rec['valor']) ? $rec['valor'] : '';
            $dimension_valor_name = null;
            $dimension_id = null;
            $dimension_name = null;
            if (!empty($dimension_valor) && !empty($dimension)) {
                $relatedObj = ClassLoader::getModelObject($dimension, false);
                $related_data = $relatedObj->getById($dimension_valor);
                if (!empty($related_data))
                    $dimension_valor_name = $related_data['__name__'];

                $dimObj = ClassLoader::getModelObject('_dimensiones', false);
                $dimension_data = $dimObj->getList(array('filters' => array('data_table' => array('=' => $dimension))));
                if (!empty($dimension_data) && !empty($dimension_data['data'])) {
                    $dimension_id = $dimension_data['data'][0]['id'];
                    $dimension_name = $dimension_data['data'][0]['nombre'];
                }
            }
            $rec['dimension_fake'] = $dimension_id;
            $rec['dimension_fake_value'] = $dimension_name;
            $rec['valor_fake'] = $dimension_valor;
            $rec['valor_fake_value'] = $dimension_valor_name;
        }
    }


}

