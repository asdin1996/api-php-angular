<?php

// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');
require_once(__DIR__ . '/../../ClassLoader.php');

class Comentario extends Entity
{

    function __construct($tablename='_comentarios', $main_rec = false)
    {
        parent::__construct('_comentarios', $main_rec);
    }

    // Creamos un comentario nuevo, ya sea respondiendo o añadiendo uno nuevo
    public function fncNuevo($id,$params = array())
    {
        $result = array();
        try {
            $now = date('Y-m-d H:i:s');
            $comentario_anadir = array(
                'origen' => $params['origen'],
                'origen_id' => $id,
                'comentario' => $params['comentario'],
                'datetime_add' => $now,
                'datetime_upd' => $now,
            );
            if(!empty($params['padre_id']))
                $comentario_anadir['padre_id'] = $params['padre_id'];

            if(empty($comentario_anadir['origen']) || empty($comentario_anadir['origen_id']))
                throw new Exception('No puede asignar un comentario sin especificar su origen');

            $comentarioObj = ClassLoader::getModelObject('_comentarios',true);
            $comentario_id = $comentarioObj->save($comentario_anadir);
            $result['success'] = true;
            $result['message'] = 'Comentario añadido correctamente.';
            $result['id'] = $comentario_id;
            $result['data'] = $comentarioObj->getById($comentario_id);
        }catch(Exception $e)
        {
            throw new Exception($e->getMessage());
        }
        return $result;
    }

    public function fncEditar($id,$params = array())
    {
        $result = array();
        try {
            $delete = !empty($params['deleted']);
            $comentario_editar = array(
                'id' => $params['id'],
            );
            if(!empty($params['deleted']))
                $comentario_editar['deleted'] = 1;
            else
            {
                $comentario_editar['comentario'] = $params['comentario'];
            }
            $comentarioObj = ClassLoader::getModelObject('_comentarios',true);
            $comentario_id = $comentarioObj->save($comentario_editar);
            $result['success'] = true;
            if($delete)
                $result['message'] = 'Comentario eliminado correctamente.';
            else
                $result['message'] = 'Comentario editado correctamente.';
            $result['id'] = $comentario_id;
            $result['data'] = $comentarioObj->getById($comentario_id);
        }catch(Exception $e)
        {
            throw new Exception($e->getMessage());
        }
        return $result;
    }
}

