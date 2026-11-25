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

/**
 * Funciones de servicio web del puente ESOE.
 *
 * El servicio se declara deshabilitado y restringido a usuarios autorizados:
 * el administrador tiene que habilitarlo y autorizar el usuario del token a
 * proposito. Asi el token institucional alcanza solo estas dos funciones y no
 * el servicio movil completo.
 *
 * @package    local_esoe
 * @copyright  Escuela de Suboficiales del Ejercito
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_esoe_get_expediente' => [
        'classname'     => 'local_esoe\external\get_expediente',
        'methodname'    => 'execute',
        'description'   => 'Devuelve en una sola llamada la identidad, los cursos y las calificaciones de un usuario.',
        'type'          => 'read',
        'capabilities'  => 'local/esoe:leerexpediente',
        'ajax'          => false,
        'loginrequired' => true,
    ],
    'local_esoe_find_user' => [
        'classname'     => 'local_esoe\external\find_user',
        'methodname'    => 'execute',
        'description'   => 'Resuelve un usuario por correo o por numero de identidad exigiendo coincidencia unica, y devuelve solo su identificador y su nombre.',
        'type'          => 'read',
        'capabilities'  => 'local/esoe:buscarusuario',
        'ajax'          => false,
        'loginrequired' => true,
    ],
];

$services = [
    'Puente ESOE' => [
        'shortname'       => 'local_esoe_puente',
        'functions'       => [
            'local_esoe_get_expediente',
            'local_esoe_find_user',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
