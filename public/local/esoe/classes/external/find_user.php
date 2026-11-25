<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_esoe\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;

/**
 * Resuelve un usuario por correo o por numero de identidad exigiendo coincidencia unica.
 *
 * Por que existe, en lugar de usar core_user_get_users_by_field: esa funcion del nucleo
 * devuelve el registro completo de cada coincidencia, con todos los campos de perfil, y la
 * plataforma ESOE descartaba casi todo en cliente despues de exigir coincidencia unica. Es
 * decir, datos personales que la plataforma no necesita viajaban por la red en cada
 * deteccion de inscripcion. Aqui la exigencia de unicidad se aplica en el servidor y solo
 * salen el identificador y el nombre.
 *
 * La ambiguedad y la ausencia devuelven lo mismo, encontrado en falso, a proposito: asi la
 * funcion no sirve para averiguar si un correo existe cuando hay varias coincidencias.
 *
 * @package    local_esoe
 * @copyright  Escuela de Suboficiales del Ejercito
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_user extends external_api {

    /** @var string[] Campos por los que se admite buscar. */
    private const CAMPOS_ADMITIDOS = ['email', 'idnumber'];

    /**
     * Parametros admitidos.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'field' => new external_value(PARAM_ALPHA, 'Campo de busqueda: email o idnumber.'),
            'value' => new external_value(PARAM_RAW_TRIMMED, 'Valor exacto que se busca.'),
        ]);
    }

    /**
     * Ejecuta la busqueda.
     *
     * @param string $field Campo de busqueda.
     * @param string $value Valor exacto.
     * @return array
     */
    public static function execute(string $field, string $value): array {
        global $DB;

        $parametros = self::validate_parameters(self::execute_parameters(), [
            'field' => $field,
            'value' => $value,
        ]);

        $campo = $parametros['field'];
        if (!in_array($campo, self::CAMPOS_ADMITIDOS, true)) {
            throw new invalid_parameter_exception(get_string('campodebusquedanoadmitido', 'local_esoe'));
        }

        $contextodelsitio = context_system::instance();
        self::validate_context($contextodelsitio);
        require_capability('local/esoe:buscarusuario', $contextodelsitio);

        $buscado = trim($parametros['value']);
        $vacio = ['encontrado' => false, 'usuarioid' => 0, 'nombrecompleto' => '', 'coincidencia' => ''];
        if ($buscado === '') {
            return $vacio;
        }

        $comparacion = $DB->sql_equal($campo, ':buscado', false, false);
        $condicion = "{$comparacion} AND deleted = 0 AND suspended = 0 AND id <> :invitado";
        $coincidencias = $DB->get_records_select(
            'user',
            $condicion,
            ['buscado' => $buscado, 'invitado' => (int) $DB->get_field('user', 'id', ['username' => 'guest'])],
            '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename',
            0,
            2
        );

        if (count($coincidencias) !== 1) {
            return $vacio;
        }

        $usuario = reset($coincidencias);
        return [
            'encontrado'     => true,
            'usuarioid'      => (int) $usuario->id,
            'nombrecompleto' => fullname($usuario),
            'coincidencia'   => $campo === 'email' ? 'CORREO' : 'IDENTIDAD',
        ];
    }

    /**
     * Forma de la respuesta.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'encontrado'     => new external_value(PARAM_BOOL, 'Verdadero solo si hubo exactamente una coincidencia.'),
            'usuarioid'      => new external_value(PARAM_INT, 'Identificador del usuario, cero si no hubo coincidencia unica.'),
            'nombrecompleto' => new external_value(PARAM_NOTAGS, 'Nombre completo, vacio si no hubo coincidencia unica.'),
            'coincidencia'   => new external_value(PARAM_ALPHA, 'CORREO o IDENTIDAD, vacio si no hubo coincidencia unica.'),
        ]);
    }
}
