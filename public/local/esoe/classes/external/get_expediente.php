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

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/grade/lib.php');
require_once($CFG->dirroot . '/grade/report/overview/lib.php');

use context_course;
use context_system;
use core_completion\progress;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\util;
use core_user;
use grade_plugin_return;
use grade_report_overview;

/**
 * Devuelve en una sola llamada la identidad, los cursos y las calificaciones de un usuario.
 *
 * La plataforma ESOE hacia dos llamadas por usuario, core_enrol_get_users_courses y
 * gradereport_overview_get_course_grades, y unia los resultados en cliente. Cada llamada
 * carga su propia espera, sus reintentos y su riesgo de quedarse a medias. Aqui la union
 * se hace del lado del servidor y viaja una sola respuesta.
 *
 * Las calificaciones se obtienen por el mismo camino que usa el informe general del nucleo,
 * de modo que se respetan las notas ocultas y las reglas de visibilidad: no se consulta la
 * base de datos a mano.
 *
 * @package    local_esoe
 * @copyright  Escuela de Suboficiales del Ejercito
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_expediente extends external_api {

    /**
     * Parametros admitidos.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(
                PARAM_INT,
                'Identificador del usuario. Cero significa el usuario del token.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Ejecuta la consulta del expediente.
     *
     * @param int $userid Identificador del usuario, cero para el del token.
     * @return array
     */
    public static function execute(int $userid = 0): array {
        global $USER;

        $parametros = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        $userid = (int) $parametros['userid'];
        if ($userid === 0) {
            $userid = (int) $USER->id;
        }

        $contextodelsitio = context_system::instance();
        self::validate_context($contextodelsitio);
        require_capability('local/esoe:leerexpediente', $contextodelsitio);

        if ((int) $USER->id !== $userid) {
            require_capability('local/esoe:leerexpedienteajeno', $contextodelsitio);
            require_capability('moodle/grade:viewall', $contextodelsitio);
        }

        $usuario = core_user::get_user($userid, '*', MUST_EXIST);
        core_user::require_active_user($usuario);

        $notas = self::notas_por_curso($userid);
        $cursos = [];
        $inscritos = enrol_get_users_courses(
            $userid,
            true,
            'id, fullname, shortname, startdate, enddate, visible, enablecompletion'
        );

        foreach ($inscritos as $curso) {
            $contextodelcurso = context_course::instance($curso->id);
            $avance = null;
            if (!empty($curso->enablecompletion)) {
                $medido = progress::get_course_progress_percentage($curso, $userid);
                if ($medido !== null) {
                    $avance = (int) round($medido);
                }
            }
            $cursos[] = [
                'id'            => (int) $curso->id,
                'nombre'        => util::format_string($curso->fullname, $contextodelcurso),
                'codigo'        => util::format_string($curso->shortname, $contextodelcurso),
                'avance'        => $avance,
                'calificacion'  => $notas[(int) $curso->id] ?? null,
                'inicio'        => (int) $curso->startdate,
                'fin'           => (int) $curso->enddate,
            ];
        }

        return [
            'usuarioid'     => $userid,
            'nombrecompleto' => fullname($usuario),
            'cursos'        => $cursos,
        ];
    }

    /**
     * Calificacion final por curso, tal como la calcula el informe general del nucleo.
     *
     * Se reutiliza grade_report_overview en vez de consultar las tablas de notas, porque
     * ese camino es el que aplica las reglas de visibilidad y el que fuerza el recalculo
     * cuando hace falta. Una consulta propia devolveria tambien notas ocultas.
     *
     * @param int $userid Identificador del usuario.
     * @return array Calificacion ya formateada, indexada por identificador de curso.
     */
    private static function notas_por_curso(int $userid): array {
        $cursodelsitio = get_course(SITEID);
        $contextodelsitio = context_course::instance($cursodelsitio->id);
        $retorno = new grade_plugin_return([
            'type'     => 'report',
            'plugin'   => 'overview',
            'courseid' => $cursodelsitio->id,
            'userid'   => $userid,
        ]);
        $informe = new grade_report_overview($userid, $retorno, $contextodelsitio);
        $informe->regrade_all_courses_if_needed();

        $notas = [];
        foreach ($informe->setup_courses_data(true) as $fila) {
            $notas[(int) $fila['course']->id] = grade_format_gradevalue(
                $fila['finalgrade'],
                $fila['courseitem'],
                true
            );
        }
        return $notas;
    }

    /**
     * Forma de la respuesta.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'usuarioid'      => new external_value(PARAM_INT, 'Identificador del usuario consultado.'),
            'nombrecompleto' => new external_value(PARAM_NOTAGS, 'Nombre completo del usuario.'),
            'cursos'         => new external_multiple_structure(
                new external_single_structure([
                    'id'           => new external_value(PARAM_INT, 'Identificador del curso.'),
                    'nombre'       => new external_value(PARAM_RAW, 'Nombre del curso, ya filtrado.'),
                    'codigo'       => new external_value(PARAM_RAW, 'Nombre corto del curso, ya filtrado.'),
                    'avance'       => new external_value(
                        PARAM_INT,
                        'Porcentaje de avance, o nulo si el curso no tiene finalizacion activada.',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'calificacion' => new external_value(
                        PARAM_RAW,
                        'Calificacion final formateada, o nula si no hay nota visible.',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'inicio'       => new external_value(PARAM_INT, 'Fecha de inicio en formato unix, cero si no tiene.'),
                    'fin'          => new external_value(PARAM_INT, 'Fecha de fin en formato unix, cero si no tiene.'),
                ])
            ),
        ]);
    }
}
