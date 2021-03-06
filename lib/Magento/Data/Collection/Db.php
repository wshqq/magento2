<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category   Magento
 * @package    Magento_Data
 * @copyright  Copyright (c) 2014 X.commerce, Inc. (http://www.magentocommerce.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/**
 * Base items collection class
 */
namespace Magento\Data\Collection;

class Db extends \Magento\Data\Collection
{
    /**
     * DB connection
     *
     * @var \Zend_Db_Adapter_Abstract
     */
    protected $_conn;

    /**
     * Select object
     *
     * @var \Zend_Db_Select
     */
    protected $_select;

    /**
     * Identifier field name for collection items
     *
     * Can be used by collections with items without defined
     *
     * @var string
     */
    protected $_idFieldName;

    /**
     * List of bound variables for select
     *
     * @var array
     */
    protected $_bindParams = array();

    /**
     * All collection data array
     * Used for getData method
     *
     * @var array
     */
    protected $_data = null;

    /**
     * Fields map for correlation names & real selected fields
     *
     * @var array
     */
    protected $_map = null;

    /**
     * Database's statement for fetch item one by one
     *
     * @var \Zend_Db_Statement_Pdo
     */
    protected $_fetchStmt = null;

    /**
     * Whether orders are rendered
     *
     * @var bool
     */
    protected $_isOrdersRendered = false;

    /**
     * @var \Magento\Logger
     */
    protected $_logger;

    /**
     * @var \Magento\Data\Collection\Db\FetchStrategyInterface
     */
    private $_fetchStrategy;

    /**
     * @param \Magento\Data\Collection\EntityFactoryInterface $entityFactory
     * @param \Magento\Logger $logger
     * @param \Magento\Data\Collection\Db\FetchStrategyInterface $fetchStrategy
     * @param mixed $connection
     */
    public function __construct(
        \Magento\Data\Collection\EntityFactoryInterface $entityFactory,
        \Magento\Logger $logger,
        \Magento\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        $connection = null
    ) {
        parent::__construct($entityFactory);
        $this->_fetchStrategy = $fetchStrategy;
        if (!is_null($connection)) {
            $this->setConnection($connection);
        }
        $this->_logger = $logger;
    }

    /**
     * Add variable to bind list
     *
     * @param string $name
     * @param mixed $value
     * @return \Magento\Data\Collection\Db
     */
    public function addBindParam($name, $value)
    {
        $this->_bindParams[$name] = $value;
        return $this;
    }

    /**
     * Specify collection objects id field name
     *
     * @param string $fieldName
     * @return \Magento\Data\Collection\Db
     */
    protected function _setIdFieldName($fieldName)
    {
        $this->_idFieldName = $fieldName;
        return $this;
    }

    /**
     * Id field name getter
     *
     * @return string
     */
    public function getIdFieldName()
    {
        return $this->_idFieldName;
    }

    /**
     * Get collection item identifier
     *
     * @param \Magento\Object $item
     * @return mixed
     */
    protected function _getItemId(\Magento\Object $item)
    {
        if ($field = $this->getIdFieldName()) {
            return $item->getData($field);
        }
        return parent::_getItemId($item);
    }

    /**
     * Set database connection adapter
     *
     * @param \Zend_Db_Adapter_Abstract $conn
     * @return \Magento\Data\Collection\Db
     * @throws \Zend_Exception
     */
    public function setConnection($conn)
    {
        if (!$conn instanceof \Zend_Db_Adapter_Abstract) {
            throw new \Zend_Exception('dbModel read resource does not implement \Zend_Db_Adapter_Abstract');
        }

        $this->_conn = $conn;
        $this->_select = $this->_conn->select();
        $this->_isOrdersRendered = false;
        return $this;
    }

    /**
     * Get \Zend_Db_Select instance
     *
     * @return \Magento\DB\Select
     */
    public function getSelect()
    {
        return $this->_select;
    }

    /**
     * Retrieve connection object
     *
     * @return \Magento\DB\Adapter\AdapterInterface
     */
    public function getConnection()
    {
        return $this->_conn;
    }

    /**
     * Get collection size
     *
     * @return int
     */
    public function getSize()
    {
        if (is_null($this->_totalRecords)) {
            $sql = $this->getSelectCountSql();
            $this->_totalRecords = $this->getConnection()->fetchOne($sql, $this->_bindParams);
        }
        return intval($this->_totalRecords);
    }

    /**
     * Get SQL for get record count
     *
     * @return \Magento\DB\Select
     */
    public function getSelectCountSql()
    {
        $this->_renderFilters();

        $countSelect = clone $this->getSelect();
        $countSelect->reset(\Zend_Db_Select::ORDER);
        $countSelect->reset(\Zend_Db_Select::LIMIT_COUNT);
        $countSelect->reset(\Zend_Db_Select::LIMIT_OFFSET);
        $countSelect->reset(\Zend_Db_Select::COLUMNS);

        $countSelect->columns('COUNT(*)');

        return $countSelect;
    }

    /**
     * Get sql select string or object
     *
     * @param   bool $stringMode
     * @return  string || \Zend_Db_Select
     */
    public function getSelectSql($stringMode = false)
    {
        if ($stringMode) {
            return $this->_select->__toString();
        }
        return $this->_select;
    }

    /**
     * Add select order
     *
     * @param   string $field
     * @param   string $direction
     * @return  \Magento\Data\Collection\Db
     */
    public function setOrder($field, $direction = self::SORT_ORDER_DESC)
    {
        return $this->_setOrder($field, $direction);
    }

    /**
     * self::setOrder() alias
     *
     * @param string $field
     * @param string $direction
     * @return \Magento\Data\Collection\Db
     */
    public function addOrder($field, $direction = self::SORT_ORDER_DESC)
    {
        return $this->_setOrder($field, $direction);
    }

    /**
     * Add select order to the beginning
     *
     * @param string $field
     * @param string $direction
     * @return \Magento\Data\Collection\Db
     */
    public function unshiftOrder($field, $direction = self::SORT_ORDER_DESC)
    {
        return $this->_setOrder($field, $direction, true);
    }

    /**
     * Add ORDER BY to the end or to the beginning
     *
     * @param string $field
     * @param string $direction
     * @param bool $unshift
     * @return \Magento\Data\Collection\Db
     */
    private function _setOrder($field, $direction, $unshift = false)
    {
        $this->_isOrdersRendered = false;
        $field = (string)$this->_getMappedField($field);
        $direction = (strtoupper($direction) == self::SORT_ORDER_ASC) ? self::SORT_ORDER_ASC : self::SORT_ORDER_DESC;

        unset($this->_orders[$field]); // avoid ordering by the same field twice
        if ($unshift) {
            $orders = array($field => $direction);
            foreach ($this->_orders as $key => $dir) {
                $orders[$key] = $dir;
            }
            $this->_orders = $orders;
        } else {
            $this->_orders[$field] = $direction;
        }
        return $this;
    }

    /**
     * Render sql select conditions
     *
     * @return  \Magento\Data\Collection\Db
     */
    protected function _renderFilters()
    {
        if ($this->_isFiltersRendered) {
            return $this;
        }

        $this->_renderFiltersBefore();

        foreach ($this->_filters as $filter) {
            switch ($filter['type']) {
                case 'or' :
                    $condition = $this->_conn->quoteInto($filter['field'].'=?', $filter['value']);
                    $this->_select->orWhere($condition);
                    break;
                case 'string' :
                    $this->_select->where($filter['value']);
                    break;
                case 'public':
                    $field = $this->_getMappedField($filter['field']);
                    $condition = $filter['value'];
                    $this->_select->where(
                        $this->_getConditionSql($field, $condition), null, \Magento\DB\Select::TYPE_CONDITION
                    );
                    break;
                default:
                    $condition = $this->_conn->quoteInto($filter['field'].'=?', $filter['value']);
                    $this->_select->where($condition);
            }
        }
        $this->_isFiltersRendered = true;
        return $this;
    }

    /**
     * Hook for operations before rendering filters
     */
    protected function _renderFiltersBefore()
    {
    }

    /**
     * Add field filter to collection
     *
     * @see self::_getConditionSql for $condition
     *
     * @param string|array $field
     * @param null|string|array $condition
     * @return \Magento\Data\Collection\Db
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if (is_array($field)) {
            $conditions = array();
            foreach ($field as $key => $value) {
                $conditions[] = $this->_translateCondition($value, isset($condition[$key]) ? $condition[$key] : null);
            }

            $resultCondition = '(' . implode(') ' . \Zend_Db_Select::SQL_OR . ' (', $conditions) . ')';
        } else {
            $resultCondition = $this->_translateCondition($field, $condition);
        }

        $this->_select->where($resultCondition, null, \Magento\DB\Select::TYPE_CONDITION);

        return $this;
    }

    /**
     * Build sql where condition part
     *
     * @param   string|array $field
     * @param   null|string|array $condition
     *
     * @return  string
     */
    protected function _translateCondition($field, $condition)
    {
        $field = $this->_getMappedField($field);
        return $this->_getConditionSql($this->getConnection()->quoteIdentifier($field), $condition);
    }

    /**
     * Try to get mapped field name for filter to collection
     *
     * @param   string $field
     * @return  string
     */
    protected function _getMappedField($field)
    {
        $mapper = $this->_getMapper();

        if (isset($mapper['fields'][$field])) {
            $mappedFiled = $mapper['fields'][$field];
        } else {
            $mappedFiled = $field;
        }

        return $mappedFiled;
    }

    /**
     * Retrieve mapper data
     *
     * @return array|bool|null
     */
    protected function _getMapper()
    {
        if (isset($this->_map)) {
            return $this->_map;
        } else {
            return false;
        }
    }

    /**
     * Build SQL statement for condition
     *
     * If $condition integer or string - exact value will be filtered ('eq' condition)
     *
     * If $condition is array - one of the following structures is expected:
     * - array("from" => $fromValue, "to" => $toValue)
     * - array("eq" => $equalValue)
     * - array("neq" => $notEqualValue)
     * - array("like" => $likeValue)
     * - array("in" => array($inValues))
     * - array("nin" => array($notInValues))
     * - array("notnull" => $valueIsNotNull)
     * - array("null" => $valueIsNull)
     * - array("moreq" => $moreOrEqualValue)
     * - array("gt" => $greaterValue)
     * - array("lt" => $lessValue)
     * - array("gteq" => $greaterOrEqualValue)
     * - array("lteq" => $lessOrEqualValue)
     * - array("finset" => $valueInSet)
     * - array("regexp" => $regularExpression)
     * - array("seq" => $stringValue)
     * - array("sneq" => $stringValue)
     *
     * If non matched - sequential array is expected and OR conditions
     * will be built using above mentioned structure
     *
     * @param string $fieldName
     * @param integer|string|array $condition
     * @return string
     */
    protected function _getConditionSql($fieldName, $condition)
    {
        return $this->getConnection()->prepareSqlCondition($fieldName, $condition);
    }

    protected function _getConditionFieldName($fieldName)
    {
        return $fieldName;
    }

    /**
     * Render sql select orders
     *
     * @return  \Magento\Data\Collection\Db
     */
    protected function _renderOrders()
    {
        if (!$this->_isOrdersRendered) {
            foreach ($this->_orders as $field => $direction) {
                $this->_select->order(new \Zend_Db_Expr($field . ' ' . $direction));
            }
            $this->_isOrdersRendered = true;
        }

        return $this;
    }

    /**
     * Render sql select limit
     *
     * @return  \Magento\Data\Collection\Db
     */
    protected function _renderLimit()
    {
        if ($this->_pageSize) {
            $this->_select->limitPage($this->getCurPage(), $this->_pageSize);
        }

        return $this;
    }

    /**
     * Set select distinct
     *
     * @param   bool $flag
     *
     * @return  \Magento\Data\Collection\Db
     */
    public function distinct($flag)
    {
        $this->_select->distinct($flag);
        return $this;
    }

    /**
     * Before load action
     *
     * @return \Magento\Data\Collection\Db
     */
    protected function _beforeLoad()
    {
        return $this;
    }

    /**
     * Load data
     *
     * @param   bool $printQuery
     * @param   bool $logQuery
     *
     * @return  \Magento\Data\Collection\Db
     */
    public function load($printQuery = false, $logQuery = false)
    {
        if ($this->isLoaded()) {
            return $this;
        }

        $this->_beforeLoad();

        $this->_renderFilters()
             ->_renderOrders()
             ->_renderLimit();

        $this->printLogQuery($printQuery, $logQuery);
        $data = $this->getData();
        $this->resetData();

        if (is_array($data)) {
            foreach ($data as $row) {
                $item = $this->getNewEmptyItem();
                if ($this->getIdFieldName()) {
                    $item->setIdFieldName($this->getIdFieldName());
                }
                $item->addData($row);
                $this->addItem($item);
            }
        }

        $this->_setIsLoaded();
        $this->_afterLoad();
        return $this;
    }

    /**
     * Returns a collection item that corresponds to the fetched row
     * and moves the internal data pointer ahead
     *
     * @return  \Magento\Object|bool
     */
    public function fetchItem()
    {
        if (null === $this->_fetchStmt) {
            $this->_renderOrders()
                 ->_renderLimit();

            $this->_fetchStmt = $this->getConnection()
                ->query($this->getSelect());
        }
        $data = $this->_fetchStmt->fetch();
        if (!empty($data) && is_array($data)) {
            $item = $this->getNewEmptyItem();
            if ($this->getIdFieldName()) {
                $item->setIdFieldName($this->getIdFieldName());
            }
            $item->setData($data);

            return $item;
        }
        return false;
    }

    /**
     * Overridden to use _idFieldName by default.
     *
     * @param null $valueField
     * @param string $labelField
     * @param array $additional
     * @return array
     */
    protected function _toOptionArray($valueField = null, $labelField = 'name', $additional = array())
    {
        if ($valueField === null) {
            $valueField = $this->getIdFieldName();
        }
        return parent::_toOptionArray($valueField, $labelField, $additional);
    }

    /**
     * Overridden to use _idFieldName by default.
     *
     * @param   string $valueField
     * @param   string $labelField
     * @return  array
     */
    protected function _toOptionHash($valueField = null, $labelField = 'name')
    {
        if ($valueField === null) {
            $valueField = $this->getIdFieldName();
        }
        return parent::_toOptionHash($valueField, $labelField);
    }

    /**
     * Convert items array to hash for select options
     * using fetchItem method
     *
     * The difference between _toOptionHash() and this one is that this
     * method fetch items one by one and does not load all collection items at once
     * return items hash
     * array($value => $label)
     *
     * @see     fetchItem()
     *
     * @param   string $valueField
     * @param   string $labelField
     * @return  array
     */
    protected function _toOptionHashOptimized($valueField = null, $labelField = 'name')
    {
        if ($valueField === null) {
            $valueField = $this->getIdFieldName();
        }
        $result = array();
        while ($item = $this->fetchItem()) {
            $result[$item->getData($valueField)] = $item->getData($labelField);
        }
        return $result;
    }

    /**
     * Get all data array for collection
     *
     * @return array
     */
    public function getData()
    {
        if ($this->_data === null) {
            $this->_renderFilters()
                 ->_renderOrders()
                 ->_renderLimit();
            $select = $this->getSelect();
            $this->_data = $this->_fetchAll($select);
            $this->_afterLoadData();
        }
        return $this->_data;
    }

    /**
     * Process loaded collection data
     *
     * @return \Magento\Data\Collection\Db
     */
    protected function _afterLoadData()
    {
        return $this;
    }

    /**
     * Reset loaded for collection data array
     *
     * @return \Magento\Data\Collection\Db
     */
    public function resetData()
    {
        $this->_data = null;
        return $this;
    }

    protected function _afterLoad()
    {
        return $this;
    }

    public function loadData($printQuery = false, $logQuery = false)
    {
        return $this->load($printQuery, $logQuery);
    }

    /**
     * Print and/or log query
     *
     * @param   bool $printQuery
     * @param   bool $logQuery
     * @param   string $sql
     *
     * @return  \Magento\Data\Collection\Db
     */
    public function printLogQuery($printQuery = false, $logQuery = false, $sql = null)
    {
        if ($printQuery || $this->getFlag('print_query')) {
            echo is_null($sql) ? $this->getSelect()->__toString() : $sql;
        }

        if ($logQuery || $this->getFlag('log_query')) {
            $this->_logQuery($sql);
        }
        return $this;
    }

    /**
     * Log query
     *
     * @param string $sql
     */
    protected function _logQuery($sql)
    {
        $this->_logger->log(is_null($sql) ? $this->getSelect()->__toString() : $sql);
    }

    /**
     * Reset collection
     *
     * @return \Magento\Data\Collection\Db
     */
    protected function _reset()
    {
        $this->getSelect()->reset();
        $this->_initSelect();
        $this->_setIsLoaded(false);
        $this->_items = array();
        $this->_data = null;
        return $this;
    }

    /**
     * Fetch collection data
     *
     * @param \Zend_Db_Select $select
     * @return array
     */
    protected function _fetchAll(\Zend_Db_Select $select)
    {
        return $this->_fetchStrategy->fetchAll($select, $this->_bindParams);
    }

    /**
     * Add filter to Map
     *
     * @param string $filter
     * @param string $alias
     * @param string $group default 'fields'
     *
     * @return \Magento\Data\Collection\Db
     */
    public function addFilterToMap($filter, $alias, $group = 'fields')
    {
        if (is_null($this->_map)) {
            $this->_map = array($group => array());
        } elseif (is_null($this->_map[$group])) {
            $this->_map[$group] = array();
        }
        $this->_map[$group][$filter] = $alias;

        return $this;
    }

    /**
     * Clone $this->_select during cloning collection, otherwise both collections will share the same $this->_select
     */
    public function __clone()
    {
        if (is_object($this->_select)) {
            $this->_select = clone $this->_select;
        }
    }

    /**
     * Init select
     */
    protected function _initSelect()
    {
        // no implementation, should be overridden in children classes
    }
}
