<?php
class PostgresSimpleTableToModel
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function generateModel($tableName, $schema = 'public')
    {
        $className = "abstract_{$tableName}";
        $columns   = $this->getTableColumns($tableName, $schema);
        $modelCode = $this->buildModel($className, $columns, $tableName);

        //return $modelCode;

        return array('model_name' => "{$className}.class.php", 'code' => $modelCode);
    }

    private function getTableColumns($tableName, $schema)
    {
        // Consulta específica para PostgreSQL
        $query = "
            SELECT
                column_name as field,
                data_type as type,
                is_nullable as nullable,
                column_default as default_value
            FROM information_schema.columns
            WHERE table_name = :tableName
            AND table_schema = :schema
            ORDER BY ordinal_position
        ";
        $query = "
            SELECT
                c.column_name AS field,
                c.data_type AS type,
                c.is_nullable AS nullable,
                c.column_default AS default_value,
                CASE
                    WHEN kcu.column_name IS NOT NULL THEN 'YES'
                    ELSE 'NO'
                END AS is_primary_key
            FROM information_schema.columns c
            LEFT JOIN information_schema.key_column_usage kcu
                ON c.table_name = kcu.table_name
            AND c.table_schema = kcu.table_schema
            AND c.column_name = kcu.column_name
            AND EXISTS (
                    SELECT 1
                    FROM information_schema.table_constraints tc
                    WHERE tc.constraint_name = kcu.constraint_name
                    AND tc.constraint_type = 'PRIMARY KEY'
                    AND tc.table_schema = c.table_schema
                    AND tc.table_name = c.table_name
                )
            WHERE c.table_name = :tableName
            AND c.table_schema = :schema
            ORDER BY c.ordinal_position
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':tableName' => $tableName, ':schema' => $schema]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildModel($className, $columns, $tableName)
    {
        $properties     = [];
        $methods        = [];
        $fill           = "";
        $fillNull       = "";
        $toArray        = "";
        $pk             = "";
        $col_fillables  = [];
        $parmas         = [];
        $parameter_update = "";
        foreach ($columns as $column) {
            $field     = $column['field'];
            $property  = $this->camelCase($field);
            $type      = $this->mapPostgresTypeToPhp($column['type']);
            $validator = sprintf("Array('value' => null, 'datatype' => DbType::%s, 'validators' => array(%s))", $this->dbTypes($column['type']), ! $column['nullable'] ? "'required' => true" : '');

            $properties[] = "    private \${$property} = {$validator};";
            $methods[]    = $this->generateAccessors($property, $type);

            $toArray .= "\$datos['{$field}'] = \$this->get_{$field}(); \n";
            
            switch ( $type ) {
                case 'int':
                    $valueFill = "Convert::toInt(\$p_entity_data['{$field}'])";
                    $params[] = "\$query->addParameter(new DbParameter('{$field}', \$this->{$field}['datatype'], Convert::toInt(\$this->{$field}['value'])));";
                    break;
                case '\DateTime':
                    $valueFill = "Convert::toDateTime(\$p_entity_data['{$field}'])";
                    $params[] = "\$query->addParameter(new DbParameter('{$field}', \$this->{$field}['datatype'], Convert::toDateTime(\$this->{$field}['value'])));";
                    break;
                default:
                    $valueFill = "\$p_entity_data['{$field}']";
                    $params[] = "\$query->addParameter(new DbParameter('{$field}', \$this->{$field}['datatype'], \$this->{$field}['value']));";
            }
            
            $fill .= "\$this->set_{$field}($valueFill);\n";
            $fillNull .= "\$this->set_{$field}(null);\n";

            if ($pk == "" && $column['is_primary_key'] == 'YES') {
                $pk = $field;
            }
            if ($column['is_primary_key'] == 'YES') {
                $parameter_update = array_pop($params);
            }

            if($column['is_primary_key'] == 'NO') {
                $col_fillables[] = $field;
            }

        }

        $col_fillable_pattern = array_map(function($n){
            return sprintf("{%s}", $n);
        },$col_fillables);

        $methods[] = "
            public function toArray() : array {
                \$datos = array();
    {$toArray} \n
    return \$datos;

            }
        ";

        $methods[] = "
            public function load(\$p_{$pk}) {
                \$query = new DbQuery(\"SELECT * FROM {$tableName} WHERE {$pk} = \$p_{$pk}\");
                \$query->addParameter(new DbParameter('{$pk}', \$this->{$pk}['datatype'], Convert::toString(\$p_{$pk})));
                \$datos = \$this->dbmanager->executeQuery(\$query);
                if(count(\$datos) > 0) {
                    \$this->fill(\$datos[0]);
                } else {
                    \$this->fill(null);
                }

                return \$this->{$pk}['value'] !== null;
            }
        ";

        $methods[] = "
            public function fill(?array \$p_entity_data) : bool{
                if(\$p_entity_data !== null & count(\$p_entity_data) > 0 ) {
                    {$fill}
                } else {
                    {$fillNull}
                }
                return \$this->{$pk}['value'] !== null;
            }
        ";


        $cols = implode(', ', $col_fillables);
        $col_pattern = implode(', ', $col_fillable_pattern);

        $update_cols = array_map(function($n){
            return sprintf("{$n} = {%s}", $n);
        }, $col_fillables);

        $params_str = implode(" \n", $params);
        $params_updates = implode(", ", $update_cols);
        $pk_patt = "{" . $pk . "}";

        $update_cols_str = implode(" \n", $params) . $parameter_update;
        $methods[] = "
            public function save() : bool {
                if(\$this->{$pk}['value'] == null) {
                    \$query = new DbQuery(\"INSERT INTO {$tableName} ({$cols}) VALUES ({$col_pattern}) \");
                    {$params_str}
                } else {
                    \$query = new DbQuery(\"UPDATE {$tableName} SET {$params_updates} WHERE {$pk} = {$pk_patt}\");
                    {$update_cols_str}
                }

                \$filas_afectadas = \$this->dbmanager->executeNonQuery(\$query);
                
                if(\$this->get_{$pk}() == null) {
                    \$this->set_{$pk}(\$this->dbmanager->lastID());
                }
                
                return \$filas_afectadas !== -1;
            }
        ";

        return $this->generateClassTemplate($className, $properties, $methods);
    }

    private function generateAccessors($property, $type)
    {
        $ucProperty = ($property);

        return <<<METHOD
    public function get_{$ucProperty}(): ?{$type}
    {
        return \$this->{$property}['value'];
    }

    public function set_{$ucProperty}(?{$type} \${$property}): void
    {
        if(!DbDataValidator::validate(\${$property}, \$this->{$property}['validators'])) {
            throw new DbException('Error al establecer el valor de <strong>{$property}</strong>:<br>' . DbDataValidator::get_error_text());
        }
        \$this->{$property}['value'] = \${$property};
    }
METHOD;
    }

    private function dbTypes($postgresType)
    {
        $dbTypes = [
            'bigint'                      => 'INTEGER',
            'integer'                     => 'INTEGER',
            'smallint'                    => 'INTEGER',
            'serial'                      => 'INTEGER',
            'bigserial'                   => 'INTEGER',
            'character varying'           => 'STRING',
            'varchar'                     => 'STRING',
            'text'                        => 'STRING',
            'char'                        => 'STRING',
            'timestamp'                   => 'DATETIME',
            'timestamp with time zone'    => 'DATETIME',
            'date'                        => 'DATETIME',
            'time'                        => 'DATETIME',
            'time with time zone'         => 'DATETIME',
            'timestamp without time zone' => 'DATETIME',
            'boolean'                     => 'BOOLEAN',
            'double precision'            => 'DECIMAL',
            'real'                        => 'DECIMAL',
            'numeric'                     => 'DECIMAL',
            'decimal'                     => 'DECIMAL',
            'json'                        => 'JSON',
            'jsonb'                       => 'JSON',
            'uuid'                        => 'STRING',
            'bytea'                       => 'STRING',
        ];

        
        return $dbTypes[strtolower($postgresType)] ?? 'mixed';
    }

    private function mapPostgresTypeToPhp($postgresType)
    {
        $typeMap = [
            'integer'                  => 'int',
            'bigint'                   => 'int',
            'smallint'                 => 'int',
            'serial'                   => 'int',
            'bigserial'                => 'int',
            'character varying'        => 'string',
            'varchar'                  => 'string',
            'text'                     => 'string',
            'char'                     => 'string',
            'timestamp'                => '\DateTime',
            'timestamp with time zone' => '\DateTime',
            'timestamp without time zone' => '\DateTime',
            'date'                     => '\DateTime',
            'time'                     => '\DateTime',
            'time with time zone'      => '\DateTime',
            'boolean'                  => 'bool',
            'double precision'         => 'float',
            'real'                     => 'float',
            'numeric'                  => 'float',
            'decimal'                  => 'float',
            'json'                     => 'array',
            'jsonb'                    => 'array',
            'uuid'                     => 'string',
            'bytea'                    => 'string',
        ];

        return $typeMap[strtolower($postgresType)] ?? 'mixed';
    }

    private function camelCase($string)
    {
        return lcfirst(str_replace('_', '_', $string));
    }

    private function generateClassTemplate($className, $properties, $methods)
    {
        $properties[] = "protected \$dbmanager = null;\n";
        $props        = implode("\n", $properties);
        $meths        = implode("\n\n", $methods);
        $imports      = "declare(strict_types=1);\n
namespace Entities\Base;\n
use Helpers\Common\{Convert}; \n
use Helpers\Database\ { DbContext, DbType, DbDataValidator, DbException, DbQuery, DbParameter };\n";

        return <<<PHP
<?php
    {$imports}
abstract class {$className}
{
{$props}

    public function __construct()
    {

        \$this->dbmanager = DbContext::getInstance()->getManager();
    }

{$meths}

}
PHP;
    }
}

// Uso para PostgreSQL con PDO
$dsn = "pgsql:host=localhost;port=5432;dbname=biometria";
$pdo = new PDO($dsn, 'postgres', 'root');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$generator = new PostgresSimpleTableToModel($pdo);
$model     = $generator->generateModel('sucursales');
file_put_contents($model['model_name'], $model['code']);
