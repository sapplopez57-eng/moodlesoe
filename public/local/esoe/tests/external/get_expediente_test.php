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

/**
 * Pruebas del expediente consolidado del puente ESOE.
 *
 * @package    local_esoe
 * @covers     \local_esoe\external\get_expediente
 * @copyright  Escuela de Suboficiales del Ejercito
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_expediente_test extends \core_external\tests\externallib_testcase {

    /**
     * Concede capacidades del sistema a un usuario mediante un rol propio.
     *
     * @param \stdClass $usuario Usuario que recibe el rol.
     * @param string[] $capacidades Capacidades a conceder.
     * @return void
     */
    private function conceder(\stdClass $usuario, array $capacidades): void {
        $contexto = \context_system::instance();
        $rol = $this->getDataGenerator()->create_role();
        foreach ($capacidades as $capacidad) {
            assign_capability($capacidad, CAP_ALLOW, $rol, $contexto->id);
        }
        role_assign($rol, $usuario->id, $contexto->id);
    }

    public function test_devuelve_los_cursos_en_que_esta_inscrito_el_propio_usuario(): void {
        $this->resetAfterTest();
        $alumno = $this->getDataGenerator()->create_user();
        $this->conceder($alumno, ['local/esoe:leerexpediente']);
        $curso = $this->getDataGenerator()->create_course(['fullname' => 'Tactica I', 'shortname' => 'TAC-1']);
        $ajeno = $this->getDataGenerator()->create_course(['fullname' => 'Logistica', 'shortname' => 'LOG-1']);
        $this->getDataGenerator()->enrol_user($alumno->id, $curso->id);
        $this->setUser($alumno);

        $salida = get_expediente::execute();

        $this->assertEquals($alumno->id, $salida['usuarioid']);
        $this->assertCount(1, $salida['cursos'], 'solo deben salir los cursos en que esta inscrito');
        $this->assertEquals($curso->id, $salida['cursos'][0]['id']);
        $this->assertSame('Tactica I', $salida['cursos'][0]['nombre']);
        $this->assertSame('TAC-1', $salida['cursos'][0]['codigo']);
        $this->assertNotEquals($ajeno->id, $salida['cursos'][0]['id']);
    }

    public function test_el_nombre_del_curso_sale_filtrado_y_no_como_etiquetas(): void {
        $this->resetAfterTest();
        $alumno = $this->getDataGenerator()->create_user();
        $this->conceder($alumno, ['local/esoe:leerexpediente']);
        $curso = $this->getDataGenerator()->create_course([
            'fullname'  => 'Armamento <script>alert(1)</script>',
            'shortname' => 'ARM-1',
        ]);
        $this->getDataGenerator()->enrol_user($alumno->id, $curso->id);
        $this->setUser($alumno);

        $salida = get_expediente::execute();

        $this->assertStringNotContainsString('<script>', $salida['cursos'][0]['nombre']);
    }

    public function test_sin_la_capacidad_propia_no_se_puede_leer_el_expediente(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_expediente::execute();
    }

    public function test_leer_el_expediente_de_otro_exige_capacidad_adicional(): void {
        $this->resetAfterTest();
        $curioso = $this->getDataGenerator()->create_user();
        $this->conceder($curioso, ['local/esoe:leerexpediente']);
        $objetivo = $this->getDataGenerator()->create_user();
        $this->setUser($curioso);

        $this->expectException(\required_capability_exception::class);
        get_expediente::execute((int) $objetivo->id);
    }

    public function test_con_las_capacidades_se_lee_el_expediente_de_otro(): void {
        $this->resetAfterTest();
        $operador = $this->getDataGenerator()->create_user();
        $this->conceder($operador, [
            'local/esoe:leerexpediente',
            'local/esoe:leerexpedienteajeno',
            'moodle/grade:viewall',
        ]);
        $objetivo = $this->getDataGenerator()->create_user();
        $curso = $this->getDataGenerator()->create_course(['fullname' => 'Topografia', 'shortname' => 'TOP-1']);
        $this->getDataGenerator()->enrol_user($objetivo->id, $curso->id);
        $this->setUser($operador);

        $salida = get_expediente::execute((int) $objetivo->id);

        $this->assertEquals($objetivo->id, $salida['usuarioid']);
        $this->assertCount(1, $salida['cursos']);
        $this->assertEquals($curso->id, $salida['cursos'][0]['id']);
    }

    public function test_no_se_lee_el_expediente_de_un_usuario_suspendido(): void {
        $this->resetAfterTest();
        $operador = $this->getDataGenerator()->create_user();
        $this->conceder($operador, [
            'local/esoe:leerexpediente',
            'local/esoe:leerexpedienteajeno',
            'moodle/grade:viewall',
        ]);
        $objetivo = $this->getDataGenerator()->create_user();
        \core_user::update_user(['id' => $objetivo->id, 'suspended' => 1], false);
        $this->setUser($operador);

        $this->expectException(\moodle_exception::class);
        get_expediente::execute((int) $objetivo->id);
    }

    public function test_un_usuario_inexistente_no_devuelve_un_expediente_vacio(): void {
        $this->resetAfterTest();
        $operador = $this->getDataGenerator()->create_user();
        $this->conceder($operador, [
            'local/esoe:leerexpediente',
            'local/esoe:leerexpedienteajeno',
            'moodle/grade:viewall',
        ]);
        $this->setUser($operador);

        $this->expectException(\dml_missing_record_exception::class);
        get_expediente::execute(999999);
    }

    public function test_la_forma_de_la_respuesta_cumple_lo_declarado(): void {
        $this->resetAfterTest();
        $alumno = $this->getDataGenerator()->create_user();
        $this->conceder($alumno, ['local/esoe:leerexpediente']);
        $curso = $this->getDataGenerator()->create_course(['fullname' => 'Comunicaciones', 'shortname' => 'COM-1']);
        $this->getDataGenerator()->enrol_user($alumno->id, $curso->id);
        $this->setUser($alumno);

        $salida = \core_external\external_api::clean_returnvalue(
            get_expediente::execute_returns(),
            get_expediente::execute()
        );

        $this->assertArrayHasKey('usuarioid', $salida);
        $this->assertArrayHasKey('nombrecompleto', $salida);
        $this->assertArrayHasKey('cursos', $salida);
        $this->assertArrayHasKey('inicio', $salida['cursos'][0]);
        $this->assertArrayHasKey('fin', $salida['cursos'][0]);
    }
}
