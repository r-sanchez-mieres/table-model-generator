<?php
    declare(strict_types=1);

namespace Entities\Base;

use Helpers\Common\{Convert}; 

use Helpers\Database\ { DbContext, DbType, DbDataValidator, DbException, DbQuery, DbParameter };

abstract class abstract_sucursal
{
    private $sucursal_id = Array('value' => null, 'datatype' => DbType::INTEGER, 'validators' => array());
    private $sucursal_nombre = Array('value' => null, 'datatype' => DbType::STRING, 'validators' => array());
    private $sucursal_fecha_creacion = Array('value' => null, 'datatype' => DbType::DATETIME, 'validators' => array());
    private $sucursal_servicio_id = Array('value' => null, 'datatype' => DbType::INTEGER, 'validators' => array());
protected $dbmanager = null;


    public function __construct()
    {

        $this->dbmanager = DbContext::getInstance()->getManager();
    }

    public function get_sucursal_id(): ?int
    {
        return $this->sucursal_id['value'];
    }

    public function set_sucursal_id(?int $sucursal_id): void
    {
        if(!DbDataValidator::validate($sucursal_id, $this->sucursal_id['validators'])) {
            throw new DbException('Error al establecer el valor de <strong>sucursal_id</strong>:<br>' . DbDataValidator::get_error_text());
        }
        $this->sucursal_id['value'] = $sucursal_id;
    }

    public function get_sucursal_nombre(): ?string
    {
        return $this->sucursal_nombre['value'];
    }

    public function set_sucursal_nombre(?string $sucursal_nombre): void
    {
        if(!DbDataValidator::validate($sucursal_nombre, $this->sucursal_nombre['validators'])) {
            throw new DbException('Error al establecer el valor de <strong>sucursal_nombre</strong>:<br>' . DbDataValidator::get_error_text());
        }
        $this->sucursal_nombre['value'] = $sucursal_nombre;
    }

    public function get_sucursal_fecha_creacion(): ?\DateTime
    {
        return $this->sucursal_fecha_creacion['value'];
    }

    public function set_sucursal_fecha_creacion(?\DateTime $sucursal_fecha_creacion): void
    {
        if(!DbDataValidator::validate($sucursal_fecha_creacion, $this->sucursal_fecha_creacion['validators'])) {
            throw new DbException('Error al establecer el valor de <strong>sucursal_fecha_creacion</strong>:<br>' . DbDataValidator::get_error_text());
        }
        $this->sucursal_fecha_creacion['value'] = $sucursal_fecha_creacion;
    }

    public function get_sucursal_servicio_id(): ?int
    {
        return $this->sucursal_servicio_id['value'];
    }

    public function set_sucursal_servicio_id(?int $sucursal_servicio_id): void
    {
        if(!DbDataValidator::validate($sucursal_servicio_id, $this->sucursal_servicio_id['validators'])) {
            throw new DbException('Error al establecer el valor de <strong>sucursal_servicio_id</strong>:<br>' . DbDataValidator::get_error_text());
        }
        $this->sucursal_servicio_id['value'] = $sucursal_servicio_id;
    }


            public function toArray() : array {
                $datos = array();
    $datos['sucursal_id'] = $this->get_sucursal_id(); 
$datos['sucursal_nombre'] = $this->get_sucursal_nombre(); 
$datos['sucursal_fecha_creacion'] = $this->get_sucursal_fecha_creacion(); 
$datos['sucursal_servicio_id'] = $this->get_sucursal_servicio_id(); 
 

    return $datos;

            }
        


            public function load($p_sucursal_id) {
                $query = new DbQuery("SELECT * FROM sucursales WHERE sucursal_id = $p_sucursal_id");
                $query->addParameter(new DbParameter('sucursal_id', $this->sucursal_id['datatype'], Convert::toString($p_sucursal_id)));
                $datos = $this->dbmanager->executeQuery($query);
                if(count($datos) > 0) {
                    $this->fill($datos[0]);
                } else {
                    $this->fill(null);
                }

                return $this->sucursal_id['value'] !== null;
            }
        


            public function fill(?array $p_entity_data) : bool{
                if($p_entity_data !== null & count($p_entity_data) > 0 ) {
$this->set_sucursal_id(Convert::toInt($p_entity_data['sucursal_id']));
$this->set_sucursal_nombre($p_entity_data['sucursal_nombre']);
$this->set_sucursal_fecha_creacion(Convert::toDateTime($p_entity_data['sucursal_fecha_creacion']));
$this->set_sucursal_servicio_id(Convert::toInt($p_entity_data['sucursal_servicio_id']));

                } else {
$this->set_sucursal_id(null);
$this->set_sucursal_nombre(null);
$this->set_sucursal_fecha_creacion(null);
$this->set_sucursal_servicio_id(null);

                }
                return $this->sucursal_id['value'] !== null;
            }

        

    private function camelCase($string)
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
}