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

use Bluz\Db\Exception\RelationNotFoundException;

/**
 * Relations map of Db tables
 *
 * @package  Bluz\Db
 *
 * @author   Anton Shevchuk
 * @created  12.11.13 13:22
 */
class Relations
{
    /**
     * Relation stack, i.e.
     *     array(
     *         'Model1:Model2' => ['Model1'=>'foreignKey', 'Model2'=>'primaryKey'],
     *         'Pages:Users' => ['Pages'=>'userId', 'Users'=>'id'],
     *         'PagesTags:Pages' => ['PagesTags'=>'pageId', 'Pages'=>'id'],
     *         'PagesTags:Tags' => ['PagesTags'=>'tagId', 'Tags'=>'id'],
     *         'Pages:Tags' => ['PagesTags'],
     *     )
     *
     * @var array
     */
    protected static $relations;

    /**
     * Class map, i.e.
     *     array(
     *         'Pages' => '\Application\Pages\Table',
     *         'Users' => '\Application\Users\Table',
     *     )
     *
     * @var array
     */
    protected static $modelClassMap;

    /**
     * Setup relation between two models
     *
     * @param string $modelOne
     * @param string $keyOne
     * @param string $modelTwo
     * @param string $keyTwo
     * @return void
     */
    public static function setRelation($modelOne, $keyOne, $modelTwo, $keyTwo)
    {
        $relations = [$modelOne => $keyOne, $modelTwo => $keyTwo];
        self::setRelations($modelOne, $modelTwo, $relations);
    }

    /**
     * Setup multi relations
     *
     * @param string $modelOne
     * @param string $modelTwo
     * @param array $relations
     * @return void
     */
    public static function setRelations($modelOne, $modelTwo, $relations)
    {
        $name = [$modelOne, $modelTwo];
        sort($name);
        $name = join(':', $name);
        // create record in static variable
        self::$relations[$name] = $relations;
    }

    /**
     * Get relations
     *
     * @param string $modelOne
     * @param string $modelTwo
     * @return array|false
     */
    public static function getRelations($modelOne, $modelTwo)
    {
        $name = [$modelOne, $modelTwo];
        sort($name);
        $name = join(':', $name);

        if (isset(self::$relations[$name])) {
            return self::$relations[$name];
        } else {
            return false;
        }
    }

    /**
     * findRelation
     *
     * @param Row $row
     * @param string $relation
     * @throws Exception\RelationNotFoundException
     * @return array
     */
    public static function findRelation($row, $relation)
    {
        $model = $row->getTable()->getModel();

        /** @var \Bluz\Db\Table $relationTable */
        $relationTable = Relations::getModelClass($relation);
        $relationTable::getInstance();

        if (!$relations = Relations::getRelations($model, $relation)) {
            throw new RelationNotFoundException(
                "Relations between model `$model` and `$relation` is not defined"
            );
        }

        // check many-to-many relations
        if (sizeof($relations) == 1) {
            $relations = Relations::getRelations($model, current($relations));
        }

        $field = $relations[$model];
        $key = $row->{$field};

        return Relations::findRelations($model, $relation, [$key]);
    }

    /**
     * Find Relations between two tables
     *
     * @param string $modelOne
     * @param string $modelTwo target table
     * @param array $keys from first table
     * @throws Exception\RelationNotFoundException
     * @return array
     */
    public static function findRelations($modelOne, $modelTwo, $keys)
    {
        $keys = (array) $keys;
        if (!$relations = self::getRelations($modelOne, $modelTwo)) {
            throw new RelationNotFoundException("Relations between model `$modelOne` and `$modelTwo` is not defined");
        }

        /* @var Table $tableOneClass name */
        $tableOneClass = self::getModelClass($modelOne);

        /* @var string $tableOneName */
        $tableOneName = $tableOneClass::getInstance()->getName();

        /* @var Table $tableTwoClass name */
        $tableTwoClass = self::getModelClass($modelTwo);

        /* @var string $tableTwoName */
        $tableTwoName = $tableTwoClass::getInstance()->getName();

        /* @var Query\Select $tableTwoSelect */
        $tableTwoSelect = $tableTwoClass::getInstance()->select();

        // check many to many relation
        if (is_int(array_keys($relations)[0])) {
            // many to many relation over third table
            $modelThree = $relations[0];

            // relations between target table and third table
            $relations = self::getRelations($modelTwo, $modelThree);

            /* @var Table $tableThreeClass name */
            $tableThreeClass = self::getModelClass($modelThree);

            /* @var string $tableTwoName */
            $tableThreeName = $tableThreeClass::getInstance()->getName();

            // join it to query
            $tableTwoSelect->join(
                $tableTwoName,
                $tableThreeName,
                $tableThreeName,
                $tableTwoName.'.'.$relations[$modelTwo].'='.$tableThreeName.'.'.$relations[$modelThree]
            );

            // relations between source table and third table
            $relations = self::getRelations($modelOne, $modelThree);

            // join it to query
            $tableTwoSelect->join(
                $tableThreeName,
                $tableOneName,
                $tableOneName,
                $tableThreeName.'.'.$relations[$modelThree].'='.$tableOneName.'.'.$relations[$modelOne]
            );

            // set source keys
            $tableTwoSelect->where($tableOneName.'.'. $relations[$modelOne] .' IN (?)', $keys);
        } else {
            // set source keys
            $tableTwoSelect->where($relations[$modelTwo] .' IN (?)', $keys);
        }
        return $tableTwoSelect->execute();
    }

    /**
     * Add information about model's classes
     *
     * @param string $model
     * @param string $className
     * @return void
     */
    public static function addClassMap($model, $className)
    {
        self::$modelClassMap[$model] = $className;
    }

    /**
     * Get information about Model classes
     *
     * @param string $model
     * @throws Exception\RelationNotFoundException
     * @return string
     */
    public static function getModelClass($model)
    {
        if (!isset(self::$modelClassMap[$model])) {
            // try to detect
            $className = '\\Application\\'.$model.'\\Table';

            if (!class_exists($className)) {
                throw new RelationNotFoundException("Related class for model `$model` not found");
            }
            self::$modelClassMap[$model] = $className;
        }
        return self::$modelClassMap[$model];
    }

    /**
     * Get information about Table classes
     *
     * @param string $modelName
     * @param array $data
     * @throws Exception\RelationNotFoundException
     * @return Row
     */
    public static function createRow($modelName, $data)
    {
        $tableClass = self::getModelClass($modelName);

        /* @var Table $tableClass name */
        return $tableClass::getInstance()->create($data);
    }

    /**
     * Fetch by Divider
     *
     * @access  public
     * @param array $input
     * @return array
     */
    public static function fetch($input)
    {
        $output = array();
        $map = array();
        foreach ($input as $i => $row) {
            $model = '';
            foreach ($row as $key => $value) {
                if (strpos($key, '__') === 0) {
                    $model = substr($key, 2);
                    continue;
                }
                $map[$i][$model][$key] = $value;
            }
            foreach ($map[$i] as $model => &$data) {
                $data = self::createRow($model, $data);
            }
            $output[] = $map;
        }
        return $output;
    }
}
