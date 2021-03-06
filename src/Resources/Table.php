<?php

namespace DreamFactory\Core\SqlDb\Resources;

use Config;
use DB;
use DreamFactory\Core\Database\Components\Expression;
use DreamFactory\Core\Database\Enums\DbFunctionUses;
use DreamFactory\Core\Database\Resources\BaseDbTableResource;
use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\RelationSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\DbComparisonOperators;
use DreamFactory\Core\Enums\DbLogicalOperators;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\BatchException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\SqlDb\Components\TableDescriber;
use DreamFactory\Core\Utility\DataFormatter;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Scalar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Class Table
 *
 * @package DreamFactory\Core\SqlDb\Resources
 */
class Table extends BaseDbTableResource
{
    //*************************************************************************
    //	Traits
    //*************************************************************************

    use TableDescriber;

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var null | bool
     */
    protected $transaction = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * {@inheritdoc}
     */
    public function updateRecordsByFilter($table, $record, $filter = null, $params = [], $extras = [])
    {
        $record = static::validateAsArray($record, null, false, 'There are no fields in the record.');

        $idFields = array_get($extras, ApiOptions::ID_FIELD);
        $idTypes = array_get($extras, ApiOptions::ID_TYPE);
        $related = array_get($extras, ApiOptions::RELATED);
        $allowRelatedDelete = Scalar::boolval(array_get($extras, ApiOptions::ALLOW_RELATED_DELETE));
        $ssFilters = array_get($extras, 'ss_filters');

        try {
            if (!$tableSchema = $this->getTableSchema(null, $table)) {
                throw new NotFoundException("Table '$table' does not exist in the database.");
            }

            $fieldsInfo = $tableSchema->getColumns(true);
            $idsInfo = $this->getIdsInfo($table, $fieldsInfo, $idFields, $idTypes);
            $relatedInfo = $tableSchema->getRelations(true);
            $parsed = $this->parseRecord($record, $fieldsInfo, $ssFilters, true);

            // build filter string if necessary, add server-side filters if necessary
            $builder = $this->dbConn->table($tableSchema->internalName);
            $this->convertFilterToNative($builder, $filter, $params, $ssFilters, $fieldsInfo);

            if (!empty($parsed)) {
                $builder->update($parsed);
            }

            $results = $this->runQuery($table, $builder, $extras);

            if (!empty($relatedInfo)) {
                // update related info
                foreach ($results as $row) {
                    static::checkForIds($row, $idsInfo, $extras);
                    $this->updatePostRelations($table, array_merge($row, $record), $relatedInfo, $allowRelatedDelete);
                }
                // get latest with related changes if requested
                if (!empty($related)) {
                    $results = $this->runQuery($table, $builder, $extras);
                }
            }

            return $results;
        } catch (RestException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to update records in '$table'.\n{$ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function patchRecordsByFilter($table, $record, $filter = null, $params = [], $extras = [])
    {
        // currently the same as update here
        return $this->updateRecordsByFilter($table, $record, $filter, $params, $extras);
    }

    /**
     * {@inheritdoc}
     */
    public function truncateTable($table, $extras = [])
    {
        // truncate the table, return success
        try {
            if (!$tableSchema = $this->getTableSchema(null, $table)) {
                throw new NotFoundException("Table '$table' does not exist in the database.");
            }
            // build filter string if necessary, add server-side filters if necessary
            $builder = $this->dbConn->table($tableSchema->internalName);
            $ssFilters = array_get($extras, 'ss_filters');
            $params = [];
            $serverFilter = $this->buildQueryStringFromData($ssFilters);
            if (!empty($serverFilter)) {
                Session::replaceLookups($serverFilter);
                $filterString = $this->parseFilterString($serverFilter, $params, $this->tableFieldsInfo);
                $builder->whereRaw($filterString, $params);
                $builder->delete();
            } else {
                $builder->truncate();
            }

            return ['success' => true];
        } catch (RestException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to delete records from '$table'.\n{$ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteRecordsByFilter($table, $filter, $params = [], $extras = [])
    {
        if (empty($filter)) {
            throw new BadRequestException("Filter for delete request can not be empty.");
        }

        $idFields = array_get($extras, ApiOptions::ID_FIELD);
        $idTypes = array_get($extras, ApiOptions::ID_TYPE);
        $ssFilters = array_get($extras, 'ss_filters');

        try {
            if (!$tableSchema = $this->getTableSchema(null, $table)) {
                throw new NotFoundException("Table '$table' does not exist in the database.");
            }
            $fieldsInfo = $tableSchema->getColumns(true);
            /*$idsInfo = */
            $this->getIdsInfo($table, $fieldsInfo, $idFields, $idTypes);

            // build filter string if necessary, add server-side filters if necessary
            $builder = $this->dbConn->table($tableSchema->internalName);
            $this->convertFilterToNative($builder, $filter, $params, $ssFilters, $fieldsInfo);

            $results = $this->runQuery($table, $builder, $extras);

            $builder->delete();

            return $results;
        } catch (RestException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to delete records from '$table'.\n{$ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveRecordsByFilter($table, $filter = null, $params = [], $extras = [])
    {
        $ssFilters = array_get($extras, 'ss_filters');

        try {
            $tableSchema = $this->getTableSchema(null, $table);
            if (!$tableSchema) {
                throw new NotFoundException("Table '$table' does not exist in the database.");
            }

            $fieldsInfo = $tableSchema->getColumns(true);

            // build filter string if necessary, add server-side filters if necessary
            $builder = $this->dbConn->table($tableSchema->internalName);
            $this->convertFilterToNative($builder, $filter, $params, $ssFilters, $fieldsInfo);

            return $this->runQuery($table, $builder, $extras);
        } catch (RestException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to retrieve records from '$table'.\n{$ex->getMessage()}");
        }
    }

    // Helper methods

    protected function restrictFieldsToDefined()
    {
        return true;
    }

    /**
     * @param              $table
     * @param Builder      $builder
     * @param array        $extras
     * @return int|array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws NotFoundException
     * @throws RestException
     */
    protected function runQuery($table, Builder $builder, $extras)
    {
        $schema = $this->getTableSchema(null, $table);
        if (!$schema) {
            throw new NotFoundException("Table '$table' does not exist in the database.");
        }

        $limit = intval(array_get($extras, ApiOptions::LIMIT, 0));
        $offset = intval(array_get($extras, ApiOptions::OFFSET, 0));
        $countOnly = Scalar::boolval(array_get($extras, ApiOptions::COUNT_ONLY));
        $includeCount = Scalar::boolval(array_get($extras, ApiOptions::INCLUDE_COUNT));

        $maxAllowed = static::getMaxRecordsReturnedLimit();
        $needLimit = false;
        if (($limit < 1) || ($limit > $maxAllowed)) {
            // impose a limit to protect server
            $limit = $maxAllowed;
            $needLimit = true;
        }

        // count total records
        $count = ($countOnly || $includeCount || $needLimit) ? $builder->count() : 0;

        if ($countOnly) {
            return $count;
        }

        // apply the selected fields
        $select = $this->parseSelect($schema, $extras);
        $builder->select($select);

        // apply the rest of the parameters
        $order = trim(array_get($extras, ApiOptions::ORDER));
        if (!empty($order)) {
            if (false !== strpos($order, ';')) {
                throw new BadRequestException('Invalid order by clause in request.');
            }
            $commas = explode(',', $order);
            switch (count($commas)) {
                case 0:
                    break;
                case 1:
                    $spaces = explode(' ', $commas[0]);
                    $orderField = $spaces[0];
                    $direction = (isset($spaces[1]) ? $spaces[1] : 'asc');
                    $builder->orderBy($orderField, $direction);
                    break;
                default:
                    // todo need to validate format here first
                    $builder->orderByRaw($order);
                    break;
            }
        }
        $group = trim(array_get($extras, ApiOptions::GROUP));
        if (!empty($group)) {
            $group = static::fieldsToArray($group);
            if (false !== strpos($group, ';')) {
                throw new BadRequestException('Invalid group by clause in request.');
            }
            $groups = $this->parseGroupBy($schema, $group);
            $builder->groupBy($groups);
        }
        $builder->take($limit);
        $builder->skip($offset);

        $result = $this->getQueryResults($schema, $builder, $extras);

        if (!empty($result)) {
            $related = array_get($extras, ApiOptions::RELATED);
            if (!empty($related) || $schema->fetchRequiresRelations) {
                $related = ('*' === $related) ? '*' : static::fieldsToArray($related);
                /** @type RelationSchema[] $availableRelations */
                $availableRelations = $schema->getRelations(true);
                if (!empty($availableRelations)) {
                    // until this is refactored to collections
                    $data = $result->toArray();
                    $this->retrieveRelatedRecords($schema, $availableRelations, $related, $data);
                    $result = collect($data);
                }
            }
        }

        $meta = [];
        if ($includeCount || $needLimit) {
            if ($includeCount || $count > $maxAllowed) {
                $meta['count'] = $count;
            }
            if (($count - $offset) > $limit) {
                $meta['next'] = $offset + $limit;
            }
        }

        if (Scalar::boolval(array_get($extras, ApiOptions::INCLUDE_SCHEMA))) {
            try {
                $meta['schema'] = $schema->toArray(true);
            } catch (RestException $ex) {
                throw $ex;
            } catch (\Exception $ex) {
                throw new InternalServerErrorException("Error describing database table '$table'.\n" .
                    $ex->getMessage(), $ex->getCode());
            }
        }

        $data = $result->toArray();
        if (!empty($meta)) {
            $data['meta'] = $meta;
        }

        return $data;
    }

    /**
     * @param TableSchema $schema
     * @param Builder     $builder
     * @param array       $extras
     * @return Collection
     */
    protected function getQueryResults(TableSchema $schema, Builder $builder, $extras)
    {
        $result = $builder->get();

        $result->transform(function ($item) use ($schema) {
            $item = (array)$item;
            foreach ($item as $field => &$value) {
                if (!is_null($value) && ($fieldInfo = $schema->getColumn($field, true))) {
                    $value = $this->schema->formatValue($value, $fieldInfo->phpType);
                }
            }

            return $item;
        });

        return $result;
    }

    /**
     * Take in a ANSI SQL filter string (WHERE clause)
     * or our generic NoSQL filter array or partial record
     * and parse it to the service's native filter criteria.
     * The filter string can have substitution parameters such as
     * ':name', in which case an associative array is expected,
     * for value substitution.
     *
     * @param \Illuminate\Database\Query\Builder $builder
     * @param string | array                     $filter       SQL WHERE clause filter string
     * @param array                              $params       Array of substitution values
     * @param array                              $ss_filters   Server-side filters to apply
     * @param array                              $avail_fields All available fields for the table
     *
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    protected function convertFilterToNative(
        Builder $builder,
        $filter,
        $params = [],
        $ss_filters = [],
        $avail_fields = []
    ) {
        // interpret any parameter values as lookups
        $params = (is_array($params) ? static::interpretRecordValues($params) : []);
        $serverFilter = $this->buildQueryStringFromData($ss_filters);

        $outParams = [];
        if (empty($filter)) {
            $filter = $serverFilter;
        } elseif (is_string($filter)) {
            if (!empty($serverFilter)) {
                $filter = '(' . $filter . ') ' . DbLogicalOperators::AND_STR . ' (' . $serverFilter . ')';
            }
        } elseif (is_array($filter)) {
            // todo parse client filter?
            $filter = '';
            if (!empty($serverFilter)) {
                $filter = '(' . $filter . ') ' . DbLogicalOperators::AND_STR . ' (' . $serverFilter . ')';
            }
        }

        Session::replaceLookups($filter);
        $filterString = $this->parseFilterString($filter, $outParams, $avail_fields, $params);
        if (!empty($filterString)) {
            $builder->whereRaw($filterString, $outParams);
        }
    }

    /**
     * @param       $filter_info
     *
     * @return null|string
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    protected function buildQueryStringFromData($filter_info)
    {
        $filters = array_get($filter_info, 'filters');
        if (empty($filters)) {
            return null;
        }

        $sql = '';
        $combiner = array_get($filter_info, 'filter_op', DbLogicalOperators::AND_STR);
        foreach ($filters as $key => $filter) {
            if (!empty($sql)) {
                $sql .= " $combiner ";
            }

            $name = array_get($filter, 'name');
            $op = strtoupper(array_get($filter, 'operator'));
            if (empty($name) || empty($op)) {
                // log and bail
                throw new InternalServerErrorException('Invalid server-side filter configuration detected.');
            }

            if (DbComparisonOperators::requiresNoValue($op)) {
                $sql .= "($name $op)";
            } else {
                $value = array_get($filter, 'value');
                $sql .= "($name $op $value)";
            }
        }

        return $sql;
    }

    /**
     * @param string         $filter
     * @param array          $out_params
     * @param ColumnSchema[] $fields_info
     * @param array          $in_params
     *
     * @return string
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    protected function parseFilterString($filter, array &$out_params, $fields_info, array $in_params = [])
    {
        if (empty($filter)) {
            return null;
        }

        $filter = trim($filter);
        // todo use smarter regex
        // handle logical operators first
        $logicalOperators = DbLogicalOperators::getDefinedConstants();
        foreach ($logicalOperators as $logicalOp) {
            if (DbLogicalOperators::NOT_STR === $logicalOp) {
                // NOT(a = 1)  or NOT (a = 1)format
                if ((0 === stripos($filter, $logicalOp . ' (')) || (0 === stripos($filter, $logicalOp . '('))) {
                    $parts = trim(substr($filter, 3));
                    $parts = $this->parseFilterString($parts, $out_params, $fields_info, $in_params);

                    return static::localizeOperator($logicalOp) . $parts;
                }
            } else {
                // (a = 1) AND (b = 2) format or (a = 1)AND(b = 2) format
                $filter = str_ireplace(')' . $logicalOp . '(', ') ' . $logicalOp . ' (', $filter);
                $paddedOp = ') ' . $logicalOp . ' (';
                if (false !== $pos = stripos($filter, $paddedOp)) {
                    $left = trim(substr($filter, 0, $pos)) . ')'; // add back right )
                    $right = '(' . trim(substr($filter, $pos + strlen($paddedOp))); // adding back left (
                    $left = $this->parseFilterString($left, $out_params, $fields_info, $in_params);
                    $right = $this->parseFilterString($right, $out_params, $fields_info, $in_params);

                    return $left . ' ' . static::localizeOperator($logicalOp) . ' ' . $right;
                }
            }
        }

        $wrap = false;
        if ((0 === strpos($filter, '(')) && ((strlen($filter) - 1) === strrpos($filter, ')'))) {
            // remove unnecessary wrapping ()
            $filter = substr($filter, 1, -1);
            $wrap = true;
        }

        // Some scenarios leave extra parens dangling
        $pure = trim($filter, '()');
        $pieces = explode($pure, $filter);
        $leftParen = (!empty($pieces[0]) ? $pieces[0] : null);
        $rightParen = (!empty($pieces[1]) ? $pieces[1] : null);
        $filter = $pure;

        // the rest should be comparison operators
        // Note: order matters here!
        $sqlOperators = DbComparisonOperators::getParsingOrder();
        foreach ($sqlOperators as $sqlOp) {
            $paddedOp = static::padOperator($sqlOp);
            if (false !== $pos = stripos($filter, $paddedOp)) {
                $field = trim(substr($filter, 0, $pos));
                $negate = false;
                if (false !== strpos($field, ' ')) {
                    $parts = explode(' ', $field);
                    $partsCount = count($parts);
                    if (($partsCount > 1) &&
                        (0 === strcasecmp($parts[$partsCount - 1], trim(DbLogicalOperators::NOT_STR)))
                    ) {
                        // negation on left side of operator
                        array_pop($parts);
                        $field = implode(' ', $parts);
                        $negate = true;
                    }
                }
                /** @type ColumnSchema $info */
                if (null === $info = array_get($fields_info, strtolower($field))) {
                    // This could be SQL injection attempt or bad field
                    throw new BadRequestException("Invalid or unparsable field in filter request: '$field'");
                }

                // make sure we haven't chopped off right side too much
                $value = trim(substr($filter, $pos + strlen($paddedOp)));
                if ((0 !== strpos($value, "'")) &&
                    (0 !== $lpc = substr_count($value, '(')) &&
                    ($lpc !== $rpc = substr_count($value, ')'))
                ) {
                    // add back to value from right
                    $parenPad = str_repeat(')', $lpc - $rpc);
                    $value .= $parenPad;
                    $rightParen = preg_replace('/\)/', '', $rightParen, $lpc - $rpc);
                }
                if (DbComparisonOperators::requiresValueList($sqlOp)) {
                    if ((0 === strpos($value, '(')) && ((strlen($value) - 1) === strrpos($value, ')'))) {
                        // remove wrapping ()
                        $value = substr($value, 1, -1);
                        $parsed = [];
                        foreach (explode(',', $value) as $each) {
                            $parsed[] = $this->parseFilterValue(trim($each), $info, $out_params, $in_params);
                        }
                        $value = '(' . implode(',', $parsed) . ')';
                    } else {
                        throw new BadRequestException('Filter value lists must be wrapped in parentheses.');
                    }
                } elseif (DbComparisonOperators::requiresNoValue($sqlOp)) {
                    $value = null;
                } else {
                    static::modifyValueByOperator($sqlOp, $value);
                    $value = $this->parseFilterValue($value, $info, $out_params, $in_params);
                }

                $sqlOp = static::localizeOperator($sqlOp);
                if ($negate) {
                    $sqlOp = DbLogicalOperators::NOT_STR . ' ' . $sqlOp;
                }

                if ($function = $info->getDbFunction(DbFunctionUses::FILTER)) {
                    $out = $this->dbConn->raw($function);
                } else {
                    $out = $info->quotedName;
                }
                $out .= " $sqlOp";

                $out .= (isset($value) ? " $value" : null);
                if ($leftParen) {
                    $out = $leftParen . $out;
                }
                if ($rightParen) {
                    $out .= $rightParen;
                }

                return ($wrap ? '(' . $out . ')' : $out);
            }
        }

        // This could be SQL injection attempt or unsupported filter arrangement
        throw new BadRequestException('Invalid or unparsable filter request.');
    }

    /**
     * @param mixed        $value
     * @param ColumnSchema $info
     * @param array        $out_params
     * @param array        $in_params
     *
     * @return int|null|string
     * @throws BadRequestException
     */
    protected function parseFilterValue($value, ColumnSchema $info, array &$out_params, array $in_params = [])
    {
        // if a named replacement parameter, un-name it because Laravel can't handle named parameters
        if (is_array($in_params) && (0 === strpos($value, ':'))) {
            if (array_key_exists($value, $in_params)) {
                $value = $in_params[$value];
            }
        }

        // remove quoting on strings if used, i.e. 1.x required them
        if (is_string($value)) {
            if ((0 === strcmp("'" . trim($value, "'") . "'", $value)) ||
                (0 === strcmp('"' . trim($value, '"') . '"', $value))
            ) {
                $value = substr($value, 1, -1);
            } elseif ((0 === strpos($value, '(')) && ((strlen($value) - 1) === strrpos($value, ')'))) {
                // function call
                return $value;
            }
        }

        switch ($info->type) {
            case DbSimpleTypes::TYPE_BOOLEAN:
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;

            case DbSimpleTypes::TYPE_INTEGER:
            case DbSimpleTypes::TYPE_ID:
            case DbSimpleTypes::TYPE_REF:
            case DbSimpleTypes::TYPE_USER_ID:
            case DbSimpleTypes::TYPE_USER_ID_ON_CREATE:
            case DbSimpleTypes::TYPE_USER_ID_ON_UPDATE:
                if (!is_int($value)) {
                    if (!(ctype_digit($value))) {
                        throw new BadRequestException("Field '{$info->getName(true)}' must be a valid integer.");
                    } else {
                        $value = intval($value);
                    }
                }
                break;

            case DbSimpleTypes::TYPE_DECIMAL:
            case DbSimpleTypes::TYPE_DOUBLE:
            case DbSimpleTypes::TYPE_FLOAT:
                $value = floatval($value);
                break;

            case DbSimpleTypes::TYPE_STRING:
            case DbSimpleTypes::TYPE_TEXT:
                break;

            // special checks
            case DbSimpleTypes::TYPE_DATE:
                $cfgFormat = Config::get('df.db_date_format');
                $outFormat = 'Y-m-d';
                $value = DataFormatter::formatDateTime($outFormat, $value, $cfgFormat);
                break;

            case DbSimpleTypes::TYPE_TIME:
                $cfgFormat = Config::get('df.db_time_format');
                $outFormat = 'H:i:s.u';
                $value = DataFormatter::formatDateTime($outFormat, $value, $cfgFormat);
                break;

            case DbSimpleTypes::TYPE_DATETIME:
                $cfgFormat = Config::get('df.db_datetime_format');
                $outFormat = 'Y-m-d H:i:s';
                $value = DataFormatter::formatDateTime($outFormat, $value, $cfgFormat);
                break;

            case DbSimpleTypes::TYPE_TIMESTAMP:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_CREATE:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_UPDATE:
                $cfgFormat = Config::get('df.db_timestamp_format');
                $outFormat = 'Y-m-d H:i:s';
                $value = DataFormatter::formatDateTime($outFormat, $value, $cfgFormat);
                break;

            default:
                break;
        }

        // anything else schema specific
        $value = $this->schema->parseValueForSet($value, $info);

        $out_params[] = $value;
        $value = '?';

        return $value;
    }

    /**
     * @inheritdoc
     */
    protected function getCurrentTimestamp()
    {
        return $this->schema->getTimestampForSet();
    }

    /**
     * @inheritdoc
     */
    protected function parseValueForSet($value, $field_info, $for_update = false)
    {
        if ($value instanceof Expression) {
            // todo need to wrangle in expression parameters somehow
            return DB::raw($value->expression);
        } else {
            return parent::parseValueForSet($value, $field_info, $for_update);
        }
    }

    /**
     * @param array $record
     *
     * @return array
     */
    public static function interpretRecordValues($record)
    {
        if (!is_array($record) || empty($record)) {
            return $record;
        }

        foreach ($record as $field => $value) {
            Session::replaceLookups($value);
            static::valueToExpression($value);
            $record[$field] = $value;
        }

        return $record;
    }

    /**
     * @param $value
     */
    public static function valueToExpression(&$value)
    {
        if (is_array($value) && isset($value['expression'])) {
            $expression = $value['expression'];
            $params = [];
            if (is_array($expression) && isset($expression['value'])) {
                $params = isset($expression['params']) ? $expression['params'] : [];
                $expression = $expression['value'];
            }

            $value = new Expression($expression, $params);
        }
    }

    /**
     * @param ColumnSchema $field
     *
     * @return \Illuminate\Database\Query\Expression|string|array
     */
    protected function parseFieldForSelect($field)
    {
        if ($function = $field->getDbFunction(DbFunctionUses::SELECT)) {
            return $this->dbConn->raw($function . ' AS ' . $field->getName(true, true));
        }

        $out = $field->name;
        if (!empty($field->alias)) {
            $out .= ' AS ' . $field->alias;
        }

        return $out;
    }

    protected static function fieldsToArray($fields)
    {
        if (empty($fields) || (ApiOptions::FIELDS_ALL === $fields)) {
            return [];
        }

        return (!is_array($fields)) ? array_map('trim', explode(',', trim($fields, ','))) : $fields;
    }

    /**
     * @param  TableSchema $schema
     * @param  array|null  $extras
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    protected function parseSelect($schema, $extras)
    {
        $idFields = array_get($extras, ApiOptions::ID_FIELD);
        if (empty($idFields)) {
            $idFields = $schema->primaryKey;
        }
        $idFields = static::fieldsToArray($idFields);
        $fields = array_get($extras, ApiOptions::FIELDS);
        if (empty($fields)) {
            $fields = $idFields;
        }
        $fields = static::fieldsToArray($fields);
        $outArray = [];
        if (empty($fields)) {
            foreach ($schema->getColumns() as $fieldInfo) {
                if ($fieldInfo->isAggregate) {
                    continue;
                }
                $out = $this->parseFieldForSelect($fieldInfo);
                if (is_array($out)) {
                    $outArray = array_merge($outArray, $out);
                } else {
                    $outArray[] = $out;
                }
            }
        } else {
            $related = array_get($extras, ApiOptions::RELATED);
            $related = static::fieldsToArray($related);
            if (!empty($related) || $schema->fetchRequiresRelations) {
                // add any required relationship mapping fields
                foreach ($schema->getRelations() as $relation) {
                    if ($relation->alwaysFetch || in_array($relation->getName(true), $related)) {
                        if ($fieldInfo = $schema->getColumn($relation->field)) {
                            $relationField = $fieldInfo->getName(true); // account for aliasing
                            if (false === array_search($relationField, $fields)) {
                                $fields[] = $relationField;
                            }
                        }
                    }
                }
            }
            foreach ($fields as $field) {
                if ($fieldInfo = $schema->getColumn($field, true)) {
                    $out = $this->parseFieldForSelect($fieldInfo);
                    if (is_array($out)) {
                        $outArray = array_merge($outArray, $out);
                    } else {
                        $outArray[] = $out;
                    }
                } else {
                    throw new BadRequestException('Invalid field requested: ' . $field);
                }
            }
        }

        return $outArray;
    }

    /**
     * @param  array          $fields
     * @param  ColumnSchema[] $avail_fields
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    protected function parseBindings(array $fields, $avail_fields)
    {
        $bindArray = [];
        if (empty($fields)) {
            foreach ($avail_fields as $fieldInfo) {
                if ($fieldInfo->isAggregate) {
                    continue;
                }
                $bindArray[] = [
                    'name'     => $fieldInfo->getName(true),
                    'php_type' => $fieldInfo->phpType
                ];
            }
        } else {
            foreach ($fields as $field) {
                if ($fieldInfo = array_get($avail_fields, strtolower($field))) {
                    $bindArray[] = [
                        'name'     => $fieldInfo->getName(true),
                        'php_type' => $fieldInfo->phpType
                    ];
                }
            }
        }

        return $bindArray;
    }

    /**
     * @param  string|array   $fields
     * @param  ColumnSchema[] $avail_fields
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    protected function parseOrderBy(array $fields, $avail_fields)
    {
        $outArray = [];
        $bindArray = [];
        if (!empty($fields)) {
            foreach ($fields as $field) {
                $ndx = strtolower($field);
                if (!isset($avail_fields[$ndx])) {
                    throw new BadRequestException('Invalid field requested: ' . $field);
                }

                $fieldInfo = $avail_fields[$ndx];
                $bindArray[] = [
                    'name'     => $fieldInfo->getName(true),
                    'php_type' => $fieldInfo->phpType
                ];
                $out = $this->parseFieldForSelect($fieldInfo);
                if (is_array($out)) {
                    $outArray = array_merge($outArray, $out);
                } else {
                    $outArray[] = $out;
                }
            }
        } else {
            foreach ($avail_fields as $fieldInfo) {
                if ($fieldInfo->isAggregate) {
                    continue;
                }
                $bindArray[] = [
                    'name'     => $fieldInfo->getName(true),
                    'php_type' => $fieldInfo->phpType
                ];
                $out = $this->parseFieldForSelect($fieldInfo);
                if (is_array($out)) {
                    $outArray = array_merge($outArray, $out);
                } else {
                    $outArray[] = $out;
                }
            }
        }

        return ['fields' => $outArray, 'bindings' => $bindArray];
    }

    /**
     * @param  TableSchema $schema
     * @param  array       $fields
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    protected function parseGroupBy($schema, $fields = null)
    {
        $outArray = [];
        if (!empty($fields)) {
            foreach ($fields as $field) {
                if ($fieldInfo = $schema->getColumn($field, true)) {
                    $outArray[] = $fieldInfo->name;
                } else {
                    $outArray[] = DB::raw($field); // todo better checks on group by clause
                }
            }
        }

        return $outArray;
    }

    /**
     * @param      $table
     * @param null $fields_info
     * @param null $requested_fields
     * @param null $requested_types
     *
     * @return array|\DreamFactory\Core\Database\Schema\ColumnSchema[]
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     */
    protected function getIdsInfo($table, $fields_info = null, &$requested_fields = null, $requested_types = null)
    {
        $idsInfo = [];
        if (empty($requested_fields)) {
            $requested_fields = [];
            /** @type ColumnSchema[] $idsInfo */
            $idsInfo = static::getPrimaryKeys($fields_info);
            foreach ($idsInfo as $info) {
                $requested_fields[] = $info->getName(true);
            }
        } else {
            if (false !== $requested_fields = static::validateAsArray($requested_fields, ',')) {
                foreach ($requested_fields as $field) {
                    $ndx = strtolower($field);
                    if (isset($fields_info[$ndx])) {
                        $idsInfo[] = $fields_info[$ndx];
                    }
                }
            }
        }

        return $idsInfo;
    }

    /**
     * @param $avail_fields
     *
     * @return string
     */
    public static function getPrimaryKeyFieldFromDescribe($avail_fields)
    {
        foreach ($avail_fields as $field_info) {
            if ($field_info->isPrimaryKey) {
                return $field_info->name;
            }
        }

        return '';
    }

    /**
     * @param array   $avail_fields
     * @param boolean $names_only Return only an array of names, otherwise return all properties
     *
     * @return array
     */
    public static function getPrimaryKeys($avail_fields, $names_only = false)
    {
        $keys = [];
        foreach ($avail_fields as $info) {
            if ($info->isPrimaryKey) {
                $keys[] = ($names_only ? $info->name : $info);
            }
        }

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    protected function addToTransaction(
        $record = null,
        $id = null,
        $extras = null,
        $rollback = false,
        $continue = false,
        $single = false
    ) {
        if ($rollback) {
            // sql transaction really only for rollback scenario, not batching
            if (0 >= $this->dbConn->transactionLevel()) {
                $this->dbConn->beginTransaction();
            }
        }

        $ssFilters = array_get($extras, 'ss_filters');
        $updates = array_get($extras, 'updates');
        $idFields = array_get($extras, 'id_fields');
        $needToIterate = ($single || !$continue || (1 < count($this->tableIdsInfo)));

        $related = array_get($extras, 'related');
        $requireMore = Scalar::boolval(array_get($extras, 'require_more')) || !empty($related);
        $allowRelatedDelete = Scalar::boolval(array_get($extras, 'allow_related_delete'));
        $relatedInfo = $this->describeTableRelated($this->transactionTable);

        $builder = $this->dbConn->table($this->transactionTableSchema->internalName);
        if (!empty($id)) {
            if (is_array($id)) {
                foreach ($idFields as $name) {
                    $builder->where($name, array_get($id, $name));
                }
            } else {
                $name = array_get($idFields, 0);
                $builder->where($name, $id);
            }
        }

        $serverFilter = $this->buildQueryStringFromData($ssFilters);
        if (!empty($serverFilter)) {
            Session::replaceLookups($serverFilter);
            $params = [];
            $filterString = $this->parseFilterString($serverFilter, $params, $this->tableFieldsInfo);
            $builder->whereRaw($filterString, $params);
        }

        $out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                // need the id back in the record
                if (!empty($id)) {
                    if (is_array($id)) {
                        $record = array_merge($record, $id);
                    } else {
                        $record[array_get($idFields, 0)] = $id;
                    }
                }

                if (!empty($relatedInfo)) {
                    $this->updatePreRelations($record, $relatedInfo);
                }

                $parsed = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters);
                if (empty($parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                if (empty($id) && (1 === count($this->tableIdsInfo)) && $this->tableIdsInfo[0]->autoIncrement) {
                    $idName = $this->tableIdsInfo[0]->name;
                    $id[$idName] = $builder->insertGetId($parsed, $idName);
                    $record[$idName] = $id[$idName];
                } else {
                    if (!$builder->insert($parsed)) {
                        throw new InternalServerErrorException("Record insert failed.");
                    }
                }

                if (!empty($relatedInfo)) {
                    $this->updatePostRelations(
                        $this->transactionTable,
                        $record,
                        $relatedInfo,
                        $allowRelatedDelete
                    );
                }

                $idName =
                    (isset($this->tableIdsInfo, $this->tableIdsInfo[0], $this->tableIdsInfo[0]->name))
                        ? $this->tableIdsInfo[0]->name : null;
                $out = (is_array($id)) ? $id : [$idName => $id];

                // add via record, so batch processing can retrieve extras
                if ($requireMore) {
                    parent::addToTransaction($id);
                }
                break;

            case Verbs::PUT:
            case Verbs::PATCH:
                if (!empty($updates)) {
                    $record = $updates;
                }

                if (!empty($relatedInfo)) {
                    $this->updatePreRelations($record, $relatedInfo);
                }
                $parsed = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters, true);

                // only update by ids can use batching, too complicated with ssFilters and related update
//                if ( !$needToIterate && !empty( $updates ) )
//                {
//                    return parent::addToTransaction( null, $id );
//                }

                if (!empty($parsed)) {
                    $rows = $builder->update($parsed);
                    if (0 >= $rows) {
                        // could have just not updated anything, or could be bad id
                        $result = $this->runQuery(
                            $this->transactionTable,
                            $builder,
                            $extras
                        );
                        if (empty($result)) {
                            throw new NotFoundException("Record with identifier '" .
                                print_r($id, true) .
                                "' not found.");
                        }
                    }
                }

                if (!empty($relatedInfo)) {
                    // need the id back in the record
                    if (!empty($id)) {
                        if (is_array($id)) {
                            $record = array_merge($record, $id);
                        } else {
                            $record[array_get($idFields, 0)] = $id;
                        }
                    }
                    $this->updatePostRelations(
                        $this->transactionTable,
                        $record,
                        $relatedInfo,
                        $allowRelatedDelete
                    );
                }

                $idName =
                    (isset($this->tableIdsInfo, $this->tableIdsInfo[0], $this->tableIdsInfo[0]->name))
                        ? $this->tableIdsInfo[0]->name : null;
                $out = (is_array($id)) ? $id : [$idName => $id];

                // add via record, so batch processing can retrieve extras
                if ($requireMore) {
                    parent::addToTransaction($id);
                }
                break;

            case Verbs::DELETE:
                if (!$needToIterate) {
                    return parent::addToTransaction(null, $id);
                }

                // add via record, so batch processing can retrieve extras
                if ($requireMore) {
                    $result = $this->runQuery(
                        $this->transactionTable,
                        $builder,
                        $extras
                    );
                    if (empty($result)) {
                        // bail, we know it isn't there
                        throw new NotFoundException("Record with identifier '" . print_r($id, true) . "' not found.");
                    }

                    $out = $result[0];
                }

                if (1 > $builder->delete()) {
                    // wasn't anything there to delete
                    throw new NotFoundException("Record with identifier '" . print_r($id, true) . "' not found.");
                }

                if (empty($out)) {
                    $idName =
                        (isset($this->tableIdsInfo, $this->tableIdsInfo[0], $this->tableIdsInfo[0]->name))
                            ? $this->tableIdsInfo[0]->name : null;
                    $out = (is_array($id)) ? $id : [$idName => $id];
                }
                break;

            case Verbs::GET:
                if (!$needToIterate) {
                    return parent::addToTransaction(null, $id);
                }

                $result = $this->runQuery($this->transactionTable, $builder, $extras);
                if (empty($result)) {
                    throw new NotFoundException("Record with identifier '" . print_r($id, true) . "' not found.");
                }

                $out = $result[0];
                break;
        }

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    protected function commitTransaction($extras = null)
    {
        if (empty($this->batchRecords) && empty($this->batchIds)) {
            if (0 < $this->dbConn->transactionLevel()) {
                $this->dbConn->commit();
            }

            return null;
        }

        $updates = array_get($extras, 'updates');
        $ssFilters = array_get($extras, 'ss_filters');
        $related = array_get($extras, 'related');
        $requireMore = Scalar::boolval(array_get($extras, 'require_more')) || !empty($related);
        $allowRelatedDelete = Scalar::boolval(array_get($extras, 'allow_related_delete'));
        $relatedInfo = $this->describeTableRelated($this->transactionTable);

        $builder = $this->dbConn->table($this->transactionTableSchema->internalName);

        /** @type ColumnSchema $idName */
        $idName = (isset($this->tableIdsInfo, $this->tableIdsInfo[0])) ? $this->tableIdsInfo[0] : null;
        if (empty($idName)) {
            throw new BadRequestException('No valid identifier found for this table.');
        }

        if (!empty($this->batchRecords)) {
            if (is_array($this->batchRecords[0])) {
                $temp = [];
                foreach ($this->batchRecords as $record) {
                    $temp[] = array_get($record, $idName->getName(true));
                }

                $builder->whereIn($idName->name, $temp);
            } else {
                $builder->whereIn($idName->name, $this->batchRecords);
            }
        } else {
            $builder->whereIn($idName->name, $this->batchIds);
        }

        $serverFilter = $this->buildQueryStringFromData($ssFilters);
        if (!empty($serverFilter)) {
            Session::replaceLookups($serverFilter);
            $params = [];
            $filterString = $this->parseFilterString($serverFilter, $params, $this->tableFieldsInfo);
            $builder->whereRaw($filterString, $params);
        }

        $out = [];
        $action = $this->getAction();
        if (!empty($this->batchRecords)) {
            if (1 == count($this->tableIdsInfo)) {
                // records are used to retrieve extras
                // ids array are now more like records
                $result = $this->runQuery($this->transactionTable, $builder, $extras);
                if (empty($result)) {
                    throw new NotFoundException('No records were found using the given identifiers.');
                }

                $out = $result;
            } else {
                $out = $this->retrieveRecords($this->transactionTable, $this->batchRecords, $extras);
            }

            $this->batchRecords = [];
        } elseif (!empty($this->batchIds)) {
            switch ($action) {
                case Verbs::PUT:
                case Verbs::PATCH:
                    if (!empty($updates)) {
                        $parsed = $this->parseRecord($updates, $this->tableFieldsInfo, $ssFilters, true);
                        if (!empty($parsed)) {
                            $rows = $builder->update($parsed);
                            if (count($this->batchIds) !== $rows) {
                                throw new BadRequestException('Batch Error: Not all requested records could be updated.');
                            }
                        }

                        foreach ($this->batchIds as $id) {
                            if (!empty($relatedInfo)) {
                                $this->updatePostRelations(
                                    $this->transactionTable,
                                    array_merge($updates, [$idName->getName(true) => $id]),
                                    $relatedInfo,
                                    $allowRelatedDelete
                                );
                            }
                        }

                        if ($requireMore) {
                            $result = $this->runQuery(
                                $this->transactionTable,
                                $builder,
                                $extras
                            );

                            $out = $result;
                        }
                    }
                    break;

                case Verbs::DELETE:
                    $result = $this->runQuery(
                        $this->transactionTable,
                        $builder,
                        $extras
                    );
                    if (count($this->batchIds) !== count($result)) {
                        foreach ($this->batchIds as $index => $id) {
                            $found = false;
                            foreach ($result as $record) {
                                if ($id == array_get($record, $idName->getName(true))) {
                                    $out[$index] = $record;
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $out[$index] = new NotFoundException("Record with identifier '" . print_r($id,
                                        true) . "' not found.");
                            }
                        }
                    } else {
                        $out = $result;
                    }

                    $rows = $builder->delete();
                    if (count($this->batchIds) !== $rows) {
                        throw new BatchException($out, 'Batch Error: Not all requested records could be deleted.');
                    }
                    break;

                case Verbs::GET:
                    $result = $this->runQuery(
                        $this->transactionTable,
                        $builder,
                        $extras
                    );

                    if (count($this->batchIds) !== count($result)) {
                        foreach ($this->batchIds as $index => $id) {
                            $found = false;
                            foreach ($result as $record) {
                                if ($id == array_get($record, $idName->getName(true))) {
                                    $out[$index] = $record;
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $out[$index] = new NotFoundException("Record with identifier '" . print_r($id,
                                        true) . "' not found.");
                            }
                        }

                        throw new BatchException($out, 'Batch Error: Not all requested records could be retrieved.');
                    }

                    $out = $result;
                    break;

                default:
                    break;
            }

            if (empty($out)) {
                $out = [];
                foreach ($this->batchIds as $id) {
                    $out[] = [$idName->getName(true) => $id];
                }
            }

            $this->batchIds = [];
        }

        if (0 < $this->dbConn->transactionLevel()) {
            $this->dbConn->commit();
        }

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    protected function rollbackTransaction()
    {
        if (0 < $this->dbConn->transactionLevel()) {
            $this->dbConn->rollBack();
        }

        return true;
    }

    public static function getApiDocInfo($service, array $resource = [])
    {
        $base = parent::getApiDocInfo($service, $resource);

//        $addTableParameters = [
//            [
//                'name'          => 'related',
//                'description'   => 'Comma-delimited list of relationship names to retrieve for each record, or \'*\' to retrieve all.',
//                'type'          => 'string',
//                'in'     => 'query',
//                'required'      => false,
//            ]
//        ];
//
//        $addTableNotes =
//            'Use the <b>related</b> parameter to return related records for each resource. ' .
//            'By default, no related records are returned.<br/> ';

        $base['definitions'] = array_merge($base['definitions'], static::getApiDocCommonModels());

        return $base;
    }
}