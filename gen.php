<?php
class PostgresSimpleTableToModel
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function generateModel($tableName, $className, $schema = 'public')
    {
        $columns   = $this->getTableColumns($tableName, $schema);
        $modelCode = $this->buildModel($className, $columns, $tableName);

        return $modelCode;
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
        $properties = [];
        $methods    = [];
        $fill       = "";
        $fillNull   = "";
        $toArray    = "";
        $pk         = "";
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
                    break;
                case '\DateTime':
                    $valueFill = "Convert::toDateTime(\$p_entity_data['{$field}'])";
                    break;
                default:
                    $valueFill = "\$p_entity_data['{$field}']";
            }
            
            $fill .= "\$this->set_{$field}($valueFill);\n";
            $fillNull .= "\$this->set_{$field}(null);\n";

            if ($pk == "" && $column['is_primary_key'] == 'YES') {
                $pk = $field;
            }

        }

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

    private function camelCase(\$string)
    {
        return lcfirst(str_replace('_', '', ucwords(\$string, '_')));
    }
}
PHP;
    }
}

// Uso para PostgreSQL con PDO
$dsn = "pgsql:host=localhost;port=5433;dbname=biometria";
$pdo = new PDO($dsn, 'postgres', 'root');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$generator = new PostgresSimpleTableToModel($pdo);
$model     = $generator->generateModel('sucursales', 'abstract_sucursal');
file_put_contents('abstract_sucursal.class.php', $model);
