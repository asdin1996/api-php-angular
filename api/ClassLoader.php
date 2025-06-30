<?php
require_once(__DIR__.'/Entity.php');
class ClassLoader {

    private static function getDefaultEntityObject($table,$main_rec = false)
    {
        return new Entity($table,$main_rec);
    }
    public static function getModelObject($table,$main_rec = false)
    {
        // Si no encontramos en Entity, buscamos en Entity/Base (para permitir override básico)
        // Si hay que hacer override de una entidad base, hay que hacerle que extienda de esta.
        // El orden de búsqueda es:
        // api/Entity
        // api/Base
        // api/Entity.php

        // Primero buscamos la entidad y tabla real según la palabra que nos hayan pasado. Se permiten 2 alias para cada tabla
        // El nombre de la tabla real
        // El nombre de la tabla desde la api (friendly*)
        $sql = "SELECT `entity`,`table` FROM __schema_tables WHERE (`api_call` = ?) AND (deleted = 0)";
        $sql .= " UNION SELECT `entity`,`table` FROM __schema_tables WHERE (`table` = ?) AND (deleted = 0)";
        $query_params = array($table,$table);
        $tablename = '';
        $dbObject = !empty($_SESSION['__db__']) ? $_SESSION['__db__'] : null;
        $resultSql = $dbObject->querySelectAll($sql,$query_params);
        if(!empty($resultSql)) {
            $entity_name = $resultSql[0]['entity'];
            $tablename = $resultSql[0]['table'];
        }

        // Si no se encuentra ninguna información, se tira Entity
        if(empty($entity_name))
        {
            return self::getDefaultEntityObject($table,$main_rec);
        }

        // Si hemos encontrado información de entidad, buscaremos su fichero.
        // Aquí tiramos del campo entity de la tabla. Por defecto debe valer Entity, si hay código, se pondrá el nombre de la clase
        // 1 - Buscamos en /api/Entity -> Aqui están las clases del proyecto personalizado
        // SI HAY QUE SOBREESCRIBIR UNA CLASE BASE (Usuario,Rol,Permisos,etc.. -> las que empiezan por _ en resumen en tabla) en un proyecto, se meterá su clase aquí.
        // A la hora de heredar esta, hay que hacerle extend del Base/Clase, de esa forma hacemos override de la funcionalidad Base, que esta ya tira del Entity
        $e = null;
        $not_found = true;
        $modelForSearch = $entity_name;
        $modelForSearch = ucwords($modelForSearch);
        $fileSearch = array(
            $modelForSearch => __DIR__ . '/Entity/' . $modelForSearch . '.php',
        );
        if(file_exists($fileSearch[$modelForSearch])) {
            require_once($fileSearch[$modelForSearch]);
            $not_found = false;
            $model = $modelForSearch;
        }

        // 2 - Si no está la clase en api/Entity, la buscamos en api/Entity/Base
        if($not_found) {

            // Primero tiramos del Extend{model}
            $fileSearch = array(
                $modelForSearch => __DIR__ . '/Entity/Base/Extend' . $modelForSearch . '.php',
            );
            if(file_exists($fileSearch[$modelForSearch])) {
                require_once($fileSearch[$modelForSearch]);
                $not_found = false;
                $model = 'Extend'.$modelForSearch;
            }

            if($not_found) {
                $fileSearch = array(
                    $modelForSearch => __DIR__ . '/Entity/Base/' . $modelForSearch . '.php',
                );
                if (file_exists($fileSearch[$modelForSearch])) {
                    require_once($fileSearch[$modelForSearch]);
                    $not_found = false;
                    $model = $modelForSearch;
                }
            }
        }

        // Si llegamos aquí y no hemos encontrado el nombre del fichero que se ha informado directamente en la tabla, tiramos del Entity.
        // Ojo, aquí podríamos dar error para evitar problemas, si se ponen ficheros que no existen y demás lo más normal es que sea algún error.
        // Por ahora, abierto.
        if($not_found) {
            return self::getDefaultEntityObject($table,$main_rec);
        }

        // Si el sistema llega aquí es porque hay clase, hace el new del item
        $e = new $model($tablename,$main_rec);
        return $e;
    }

}
?>