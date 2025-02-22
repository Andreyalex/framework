<?php
/**
 * Bluz Framework Component
 *
 * @copyright Bluz PHP Team
 * @link https://github.com/bluzphp/framework
 */

/**
 * @namespace
 */
namespace Bluz\Db;

use Bluz\Common\Exception\ConfigurationException;
use Bluz\Common\Options;
use Bluz\Db\Query;
use Bluz\Db\Exception\DbException;
use Bluz\Proxy\Logger;

/**
 * PDO wrapper
 *
 * @package  Bluz\Db
 * @link     https://github.com/bluzphp/framework/wiki/Db
 *
 * @author   Anton Shevchuk
 * @created  07.07.11 15:36
 */
class Db
{
    use Options;

    /**
     * PDO connection settings
     * @link http://php.net/manual/en/pdo.construct.php
     * @var array
     */
    protected $connect = array(
        "type" => "mysql",
        "host" => "localhost",
        "name" => "",
        "user" => "root",
        "pass" => "",
        "options" => array()
    );

    /**
     * PDO connection flags
     * @link http://php.net/manual/en/pdo.setattribute.php
     * @var array
     */
    protected $attributes = array(
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
    );

    /**
     * PDO instance
     * @var \PDO
     */
    protected $handler;

    /**
     * Part time
     * @var float
     */
    protected $timer;

    /**
     * Setup connection
     *
     * Just init
     *
     *     $db->setConnect(array(
     *         'type' => 'mysql',
     *         'host' => 'localhost',
     *         'name' => 'db name',
     *         'user' => 'root',
     *         'pass' => ''
     *     ));
     *
     * @param array $connect options
     * @throws DbException
     * @return Db
     */
    public function setConnect(array $connect)
    {
        $this->connect = array_merge($this->connect, $connect);
        $this->checkConnect();
        return $this;
    }

    /**
     * Check connection options
     *
     * @throws ConfigurationException
     * @return void
     */
    private function checkConnect()
    {
        if (empty($this->connect['type']) or
            empty($this->connect['host']) or
            empty($this->connect['name']) or
            empty($this->connect['user'])
        ) {
            throw new ConfigurationException(
                "Database adapter is not configured.
                Please check 'db' configuration section: required type, host, db name and user"
            );
        }
    }

    /**
     * Setup attributes for PDO connect
     *
     * @param array $attributes
     * @return Db
     */
    public function setAttributes(array $attributes)
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * Connect to Db
     * @api
     * @throws DbException
     * @return Db
     */
    public function connect()
    {
        if (empty($this->handler)) {
            try {
                $this->checkConnect();
                $this->log("Connect to " . $this->connect['host']);
                $this->handler = new \PDO(
                    $this->connect['type'] . ":host=" . $this->connect['host'] . ";dbname=" . $this->connect['name'],
                    $this->connect['user'],
                    $this->connect['pass'],
                    $this->connect['options']
                );

                foreach ($this->attributes as $attribute => $value) {
                    $this->handler->setAttribute($attribute, $value);
                }

                $this->ok();
            } catch (\Exception $e) {
                throw new DbException('Attempt connection to database is failed: '. $e->getMessage());
            }
        }
        return $this;
    }

    /**
     * Return PDO handler
     * @api
     * @return \PDO
     */
    public function handler()
    {
        if (empty($this->handler)) {
            $this->connect();
        }
        return $this->handler;
    }

    /**
     * Prepare SQL query and return PDO Statement
     * @api
     * @param string $sql
     * @param array $params
     * @return \PDOStatement
     */
    protected function prepare($sql, $params)
    {
        $stmt = $this->handler()->prepare($sql);
        $stmt->execute($params);

        $this->log($sql, $params);

        return $stmt;
    }

    /**
     * Quotes a string for use in a query
     *
     * Example of usage
     *     $db->quote($_GET['id'])
     *
     * @api
     * @param string $value
     * @return string
     */
    public function quote($value)
    {
        return $this->handler()->quote($value);
    }

    /**
     * Quote a string so it can be safely used as a table or column name
     * @api
     * @param string $identifier
     * @return string
     */
    public function quoteIdentifier($identifier)
    {
        // switch statement for DB type
        switch ($this->connect['type']) {
            case 'mysql':
                return '`' . str_replace('`', '``', $identifier) . '`';
            case 'postgresql':
            case 'sqlite':
            default:
                return '"' . str_replace('"', '\\' . '"', $identifier) . '"';
        }
    }

    /**
     * Execute SQL query
     *
     * Example of usage
     *     $db->query("SET NAMES 'utf8'");
     *
     * @api
     * @param string $sql <p>
     *  "UPDATE users SET name = :name WHERE id = :id"
     *  </p>
     * @param array $params <p>
     *  array (':name' => 'John', ':id' => '123')
     * </p>
     * @param array $types <p>
     *  array (':name' => \PDO::PARAM_STR, ':id' => \PDO::PARAM_INT)
     * </p>
     * @return integer the number of rows
     */
    public function query($sql, $params = array(), $types = array())
    {
        $stmt = $this->handler()->prepare($sql);
        foreach ($params as $key => &$param) {
            $stmt->bindParam(
                (is_int($key)?$key+1:":".$key),
                $param,
                isset($types[$key])?$types[$key]:\PDO::PARAM_STR
            );
        }
        $this->log($sql, $params);
        $stmt->execute($params);
        $this->ok();
        return $stmt->rowCount();
    }

    /**
     * Create new query select builder
     * @api
     * @param string $select The selection expressions
     * @return Query\Select
     */
    public function select(...$select)
    {
        $query = new Query\Select();
        $query->select(...$select);
        return $query;
    }

    /**
     * Create new query insert builder
     * @api
     * @param string $table
     * @return Query\Insert
     */
    public function insert($table)
    {
        $query = new Query\Insert();
        $query->insert($table);
        return $query;
    }

    /**
     * Create new query update builder
     * @api
     * @param string $table
     * @return Query\Update
     */
    public function update($table)
    {
        $query = new Query\Update();
        $query->update($table);
        return $query;
    }

    /**
     * Create new query update builder
     * @api
     * @param string $table
     * @return Query\Delete
     */
    public function delete($table)
    {
        $query = new Query\Delete();
        $query->delete($table);
        return $query;
    }

    /**
     * Return first field from first element from the result set
     *
     * Example of usage
     *     $db->fetchOne("SELECT COUNT(*) FROM users");
     *
     * @api
     * @param string $sql <p>
     *  "SELECT id FROM users WHERE name = :name AND pass = :pass"
     *  </p>
     * @param array $params <p>
     *  array (':name' => 'John', ':pass' => '123456')
     * </p>
     * @return string
     */
    public function fetchOne($sql, $params = array())
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->fetch(\PDO::FETCH_COLUMN);

        $this->ok();
        return $result;
    }

    /**
     * Returns an array containing first row from the result set
     *
     * Example of usage
     *     $db->fetchRow("SELECT name, email FROM users WHERE id = ". $db->quote($id));
     *     $db->fetchRow("SELECT name, email FROM users WHERE id = ?", array($id));
     *     $db->fetchRow("SELECT name, email FROM users WHERE id = :id", array(':id'=>$id));
     *
     * @api
     * @param string $sql <p>
     *  "SELECT * FROM users WHERE name = :name AND pass = :pass"
     *  </p>
     * @param array $params <p>
     *  array (':name' => 'John', ':pass' => '123456')
     * </p>
     * @return array <pre>
     *  array ('name' => 'John', 'email' => 'john@smith.com')
     * </pre>
     */
    public function fetchRow($sql, $params = array())
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->ok();
        return $result;
    }

    /**
     * Returns an array containing all of the result set rows
     *
     * Example of usage
     *     $db->fetchAll("SELECT * FROM users WHERE ip = ?", array('192.168.1.1'));
     *
     * @api
     * @param string $sql <p>
     *  "SELECT * FROM users WHERE ip = :ip"
     *  </p>
     * @param array $params <p>
     *  array (':ip' => '127.0.0.1')
     * </p>
     * @return array[]
     */
    public function fetchAll($sql, $params = array())
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->ok();
        return $result;
    }

    /**
     * Returns an array containing one column from the result set rows
     *
     * @api
     * @param string $sql <p>
     *  "SELECT id FROM users WHERE ip = :ip"
     *  </p>
     * @param array $params <p>
     *  array (':ip' => '127.0.0.1')
     * </p>
     * @return array
     */
    public function fetchColumn($sql, $params = array())
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->ok();
        return $result;
    }

    /**
     * Returns an array containing all of the result set rows
     *
     * Group by first column
     *     $db->fetchGroup("SELECT ip, COUNT(id) FROM users GROUP BY ip", array());
     *
     * @api
     * @param string $sql <p>
     *  "SELECT ip, id FROM users"
     *  </p>
     * @param array $params
     * @return array
     */
    public function fetchGroup($sql, $params = array())
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC | \PDO::FETCH_GROUP);

        $this->ok();
        return $result;
    }

    /**
     * Returns an array containing all of the result set rows
     *
     * Group by first column
     *
     * @api
     * @param string $sql <p>
     *  "SELECT ip, id FROM users"
     *  </p>
     * @param array $params
     * @return array
     */
    public function fetchColumnGroup($sql, $params = array())
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP);

        $this->ok();
        return $result;
    }

    /**
     * Returns a key-value array
     *
     * @api
     * @param string $sql <p>
     *  "SELECT id, username FROM users WHERE ip = :ip"
     *  </p>
     * @param array $params <p>
     *  array (':ip' => '127.0.0.1')
     * </p>
     * @return array
     */
    public function fetchPairs($sql, $params = array())
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->ok();
        return $result;
    }

    /**
     * Returns an object containing first row from the result set
     *
     * Fetch object to stdClass
     *     $stdClass = $db->fetchObject('SELECT * FROM some_table WHERE id = ?', array($id));
     * Fetch object to new Some object
     *     $someClass = $db->fetchObject('SELECT * FROM some_table WHERE id = ?', array($id), 'Some');
     * Fetch object to exists instance of Some object
     *     $someClass = $db->fetchObject('SELECT * FROM some_table WHERE id = ?', array($id), $someClass);
     *
     * @api
     * @param string $sql <p>
     *  "SELECT * FROM users WHERE name = :name AND pass = :pass"
     *  </p>
     * @param array $params <p>
     *  array (':name' => 'John', ':pass' => '123456')
     * </p>
     * @param mixed $object
     * @return array
     */
    public function fetchObject($sql, $params = array(), $object = "stdClass")
    {
        $stmt = $this->prepare($sql, $params);

        if (is_string($object)) {
            // some class name
            $result = $stmt->fetchObject($object);
        } else {
            // some instance
            $stmt->setFetchMode(\PDO::FETCH_INTO, $object);
            $result = $stmt->fetch(\PDO::FETCH_INTO);
        }

        $stmt->closeCursor();
        $this->ok();
        return $result;
    }

    /**
     * Returns an array of objects containing the result set
     *
     * @api
     * @param string $sql <p>
     *  "SELECT * FROM users WHERE name = :name AND pass = :pass"
     *  </p>
     * @param array $params <p>
     *  array (':name' => 'John', ':pass' => '123456')
     * </p>
     * @param mixed $object
     * @return array
     */
    public function fetchObjects($sql, $params = array(), $object = null)
    {
        $stmt = $this->prepare($sql, $params);

        if (is_string($object)) {
            // fetch to some class by name
            $result = $stmt->fetchAll(\PDO::FETCH_CLASS, $object);
        } else {
            // fetch to StdClass
            $result = $stmt->fetchAll(\PDO::FETCH_OBJ);
        }

        $stmt->closeCursor();
        $this->ok();
        return $result;
    }

    /**
     * Returns an array of linked objects containing the result set
     *
     * @api
     * @param string $sql <p>
     *  "SELECT '__users', u.*, '__users_profile', up.*
     *   FROM users u
     *   LEFT JOIN users_profile up ON up.userId = u.id"
     *   WHERE u.name = :name
     *  </p>
     * @param array $params <p>
     *  array (':name' => 'John')
     * </p>
     * @return array
     */
    public function fetchRelations($sql, $params = array())
    {
        $stmt = $this->prepare($sql, $params);

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // prepare results
        $result = Relations::fetch($result);

        $stmt->closeCursor();
        $this->ok();
        return $result;
    }

    /**
     * Transaction wrapper
     *
     * Example of usage
     *     $db->transaction(function() use ($db) {
     *         $db->query("INSERT INTO `table` ...");
     *         $db->query("UPDATE `table` ...");
     *         $db->query("DELETE FROM `table` ...");
     *     })
     *
     * @api
     * @param  callable $process
     * @throws DbException
     * @return bool
     */
    public function transaction($process)
    {
        if (!is_callable($process)) {
            throw new DbException('First argument of transaction method should be callable');
        }
        try {
            $this->handler()->beginTransaction();
            call_user_func($process);
            $this->handler()->commit();
            return true;
        } catch (\PDOException $e) {
            $this->handler()->rollBack();
            return false;
        }
    }

    /**
     * Setup timer
     *
     * @return void
     */
    protected function ok()
    {
        $log = sprintf("--: %f", microtime(true) - $this->timer);
        Logger::info($log);
    }

    /**
     * Log queries by Application
     *
     * @param string $sql
     * @param array $context
     * @return void
     */
    protected function log($sql, array $context = [])
    {
        $this->timer = microtime(true);

        $sql = str_replace('%', '%%', $sql);
        $sql = preg_replace('/\?/', '"%s"', $sql, sizeof($context));

        // replace mask by data
        $log = vsprintf("db: ". $sql, $context);

        Logger::info($log);
    }

    /**
     * Disconnect PDO and clean default adapter
     * @api
     * @return void
     */
    public function disconnect()
    {
        if ($this->handler) {
            $this->handler = null;
        }
    }
}
