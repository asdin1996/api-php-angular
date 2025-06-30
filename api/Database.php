<?php

require_once(__DIR__.'/Entity.php');

class Database {

    // Estas variables se obtendrán de la configuración
    private $host = '';
    private $database = '';
    private $user = '';
    private $password = '';
    private $dbtype = 'mysql';
    private $port = null;
    protected $schema = '';

    // Variables de debug
    private $log_enabled = false;

    // Objeto de la base de datos
    private $dbObject = null;

    function __construct($options)
    {
        // Obtenemos los valores de configuración de la base de datos
        $this->host = !empty($options['db_host']) ? $options['db_host'] : '';
        $this->database = !empty($options['db_database']) ? $options['db_database'] : '';
        $this->user = !empty($options['db_user']) ? $options['db_user'] : '';
        $this->password = !empty($options['db_password']) ? $options['db_password'] : '';
        $this->dbtype = !empty($options['db_dbtype']) ? $options['db_dbtype'] : 'mysql';
        $this->port = !empty($options['db_port']) ? $options['db_port'] : null;
        $this->schema = $this->database;

        $this->dbObject = new mysqli($this->host, $this->user, $this->password, $this->database);
        if ($this->dbObject->connect_errno) {
            throw new Exception('API_BDD_CONNECTION_ERROR');
        }
        else {
            $this->dbObject->set_charset("utf8");
        }
    }

    function getSchema()
    {
        return $this->schema;
    }

    function __disableLog()
    {
        $this->log_enabled = false;
    }
    function __enableLog()
    {
        $this->log_enabled = true;
    }

    function __destruct()
    {
        if (!empty($this->dbObject)) {
            try {
                $this->dbObject->close();
            }catch(Exception $e){}
            $this->dbObject = null;
        }
    }

    function existsId($datatable,$id)
    {
        $exists = false;
        $sql = "SELECT ID FROM ".$datatable." WHERE ID = ".$id;
        $this->insertIntoQueryLog($sql);
        $_SESSION['__all_query__'][] = $sql;
        $_SESSION['__last_query__'] = $sql;
        //EntityLib::debugSequence($sql);
        $result = $this->dbObject->query($sql);
        $q_exec = !empty($_SESSION['__queries_executed__']) ? intval($_SESSION['__queries_executed__']) : 0;
        $q_exec++;
        $_SESSION['__queries_executed__'] = $q_exec;
        $exists = $result->num_rows !== 0;
        return $exists;
    }

    function getItems($sql,$params = array())
    {
        // Desactivamos la caché por ahora para refactorizar para usar prepare en BDD
        /*
        $cache = !empty($_SESSION['__cache__']);
        if($cache) {
            $hashSql = sha1($sql, false);
            $cache_data = !empty($_SESSION['__cachedata__']) ? $_SESSION['__cachedata__'] : array();
            if(!empty($cache_data[$hashSql]))
            {
                $this_cache = $cache_data[$hashSql];

                $cache_timestamp = $this_cache['timestamp'];
                $actual_timestamp = gettimeofday();
                $sec_actual = $actual_timestamp['sec'];
                $sec_cache = $cache_timestamp['sec'];

                if(($sec_cache+2) >= $sec_actual)
                {
                    $q_cached = !empty($_SESSION['__queries_cached__']) ? intval($_SESSION['__queries_cached__']) : 0;
                    $q_cached++;
                    $_SESSION['__queries_cached__'] = $q_cached;
                    return $this_cache['data'];
                }
            }
        }
        */

        $data = array();
        $this->insertIntoQueryLog($sql);
        $_SESSION['__all_query__'][] = $sql;
        $_SESSION['__last_query__'] = $sql;
        //EntityLib::debugSequence($sql);
        //$result = $this->dbObject->query($sql);
        $stmt = $this->prepareQuery($sql,$params);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result) {
            // output data of each row
            while($row = mysqli_fetch_assoc($result)) {
                $data[] = $row;
            }
        }
        $q_exec = !empty($_SESSION['__queries_executed__']) ? intval($_SESSION['__queries_executed__']) : 0;
        $q_exec++;
        $_SESSION['__queries_executed__'] = $q_exec;
        if ($result && $result->num_rows > 0) {
            // output data of each row
            while($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }

        // Desactivamos caché por ahora
        /*
        if($cache)
        {
            if(empty($_SESSION['__cachedata__']))
                $_SESSION['__cachedata__'] = array();
            $_SESSION['__cachedata__'][$hashSql] = array(
                'timestamp' => gettimeofday(),
                'data' => $data
            );
        }
        */

        return $data;
    }

    function getLastQueryAffectedRows()
    {
        return $this->dbObject->affected_rows;
    }

    function query($sql,$params = array())
    {
        $this->insertIntoQueryLog($sql);

        $_SESSION['__all_query__'][] = $sql;
        $_SESSION['__last_query__'] = $sql;
        //EntityLib::debugSequence($sql);

        $stmt = $this->prepareQuery($sql,$params);
        $result = $stmt->execute();
        $q_exec = !empty($_SESSION['__queries_executed__']) ? intval($_SESSION['__queries_executed__']) : 0;
        $q_exec++;
        $_SESSION['__queries_executed__'] = $q_exec;

        // Detectamos si hay algún error en la consulta!
        if(!$result)
        {
            if(!empty($this->dbObject->error))
            {
                $message = EntityLib::__('API_QUERY_MYSQL_ERROR',array($sql,$this->dbObject->error));
                throw new Exception($message);
            }
        }

        return $result;
    }
    function querySelect($sql,$params = array())
    {
        $data = array();
        $result = $this->getItems($sql,$params);
        if(!empty($result))
        {
            $data = $result[0];
        }
        return $data;
    }
    function querySelectAll($sql,$params = array())
    {
        $data = array();
        $result = $this->getItems($sql,$params);
        if(!empty($result))
        {
            $data = $result;
        }

        return $data;
    }

    // DEPRECATED 24/09!
    /*
    function querySelectAllWithParams($sql,$params)
    {
        $data = array();
        $result = $this->getItems($sql);
        if(!empty($result))
        {
            $data = $result;
        }

        return $data;
    }

    function getById($datatable,$id)
    {
        $sql = "SELECT * FROM ".$datatable." WHERE id = ?";
        $query_params = array($id);
        $data = array();
        $result = $this->getItems($sql,$query_params);
        if(!empty($result))
        {
            $data = $result[0];
        }
        return $data;
    }

    function getBySelect($datatable,$where,$orderBy = 'ID DESC',$limit = 1)
    {
        $sql = "SELECT * FROM ".$datatable." WHERE ".$where." ORDER BY ".$orderBy;
        $data = array();
        $sql .= " LIMIT ".$limit;  // Solo SQL permite LIMIT
        $result = $this->getItems($sql);
        if(!empty($result))
        {
            $data = $result[0];
        }
        return $data;
    }

    function delete($datatable,$id)
    {
        $delete = false;
        $sql = "DELETE FROM ".$datatable." WHERE ID = ".$id;
        $this->insertIntoQueryLog($sql);
        $_SESSION['__all_query__'][] = $sql;
        $_SESSION['__last_query__'] = $sql;
        //EntityLib::debugSequence($sql);
        $delete = $this->dbObject->query($sql);
        $q_exec = !empty($_SESSION['__queries_executed__']) ? intval($_SESSION['__queries_executed__']) : 0;
        $q_exec++;
        $_SESSION['__queries_executed__'] = $q_exec;
        return $delete;
    }

    function update($sql)
    {
        $this->insertIntoQueryLog($sql);
        $_SESSION['__all_query__'][] = $sql;
        $_SESSION['__last_query__'] = $sql;
        //EntityLib::debugSequence($sql);
        $updated = $this->dbObject->query($sql);
        $q_exec = !empty($_SESSION['__queries_executed__']) ? intval($_SESSION['__queries_executed__']) : 0;
        $q_exec++;
        $_SESSION['__queries_executed__'] = $q_exec;
        return $updated;
    }

    function getLastId($datatable)
    {
        $last_id = 0;
        $key_field = 'id';
        $max_field = "MAX(".$key_field.")";
        $sql = "SELECT IFNULL(".$max_field.",0) as MAX FROM ".$datatable;
        $this->insertIntoQueryLog($sql);
        $_SESSION['__all_query__'][] = $sql;
        $_SESSION['__last_query__'] = $sql;
        //EntityLib::debugSequence($sql);
        $result = $this->dbObject->query($sql);
        $q_exec = !empty($_SESSION['__queries_executed__']) ? intval($_SESSION['__queries_executed__']) : 0;
        $q_exec++;
        $_SESSION['__queries_executed__'] = $q_exec;
        if($result->num_rows == 1)
        {
            $record = $result->fetch_assoc();
            $last_id = $record['MAX'];
        }
        return $last_id;
    }
    */

    function getLastModifiedId()
    {
        return $this->dbObject->insert_id;
    }

    function beginTransaction()
    {
        $this->dbObject->begin_transaction();
    }
    function commitTransaction()
    {
        $this->dbObject->commit();
    }
    function rollbackTransaction(){
        $this->dbObject->rollback();
    }

    function insertIntoQueryLog($query)
    {
        //Si hay que depurar querys, activar

        if(empty($_SESSION['__sql_count__'])) $_SESSION['__sql_count__'] = 0;
        $_SESSION['__sql_count__']++;

        //echo '<pre>';print_r($query);echo '</pre>';
        if(empty($_SESSION['__sql__'])) $_SESSION['__sql__'] = array();
            $_SESSION['__sql__'][] = $query;


        if($this->log_enabled) {
            $this->__disableLog();
            $aux = new Entity('__sql_logger');
            $data = array(
                'query' => $query,
            );
            $log_id = $aux->save($data);
            $this->__enableLog();
        }

    }

    public function prepareQuery($sql,$params)
    {
        try {
            $stmt = mysqli_prepare($this->dbObject, $sql);
            if ($stmt) {
                if (!empty($params)) {
                    $types = '';
                    foreach ($params as $param) {
                        if (is_int($param)) {
                            $types .= 'i'; // Entero
                        } elseif (is_float($param)) {
                            $types .= 'd'; // Doble
                        } else {
                            $types .= 's'; // Cadena
                        }
                    }
                    $bindParams = array($types);
                    foreach ($params as &$param) {
                        $bindParams[] = &$param;
                    }

                    call_user_func_array(array($stmt, 'bind_param'), $bindParams);
                }
            } else {

                throw new Exception(mysqli_error($this->dbObject));
            }
        } catch(Exception $e)
        {
            /*
            echo '<pre>';print_r($sql);echo '</pre>';
            echo '<pre>';print_r($params);echo '</pre>';
            echo '<pre>';print_r($bindParams);echo '</pre>';
            die(mysqli_error($this->dbObject));
            */
            throw $e;
        }
        return $stmt;
    }

}

?>