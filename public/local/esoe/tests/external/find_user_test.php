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
 * Pruebas de la resolucion de usuario del puente ESOE.
 *
 * @package    local_esoe
 * @covers     \local_esoe\external\find_user
 * @copyright  Escuela de Suboficiales del Ejercito
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class find_user_test extends \core_external\tests\externallib_testcase {

    /**
     * Crea un usuario con la capacidad de buscar y lo deja como usuario actual.
     *
     * @return \stdClass
     */
    private function usuario_autorizado(): \stdClass {
        $operador = $this->getDataGenerator()->create_user();
        $rol = $this->getDataGenerator()->create_role();
        assign_capability('local/esoe:buscarusuario', CAP_ALLOW, $rol, \context_system::instance()->id);
        role_assign($rol, $operador->id, \context_system::instance()->id);
        $this->setUser($operador);
        return $operador;
    }

    public function test_resuelve_por_correo_con_coincidencia_unica(): void {
        $this->resetAfterTest();
        $buscado = $this->getDataGenerator()->create_user(['email' => 'cabo.perez@esoe.mil', 'idnumber' => 'C-1001']);
        $this->usuario_autorizado();

        $salida = find_user::execute('email', 'cabo.perez@esoe.mil');

        $this->assertTrue($salida['encontrado']);
        $this->assertEquals($buscado->id, $salida['usuarioid']);
        $this->assertSame('CORREO', $salida['coincidencia']);
    }

    public function test_resuelve_por_identidad_con_coincidencia_unica(): void {
        $this->resetAfterTest();
        $buscado = $this->getDataGenerator()->create_user(['email' => 'sgto.gomez@esoe.mil', 'idnumber' => 'S-2002']);
        $this->usuario_autorizado();

        $salida = find_user::execute('idnumber', 'S-2002');

        $this->assertTrue($salida['encontrado']);
        $this->assertEquals($buscado->id, $salida['usuarioid']);
        $this->assertSame('IDENTIDAD', $salida['coincidencia']);
    }

    public function test_la_respuesta_no_lleva_datos_personales_de_mas(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['email' => 'tte.silva@esoe.mil', 'idnumber' => 'T-3003']);
        $this->usuario_autorizado();

        $salida = find_user::execute('email', 'tte.silva@esoe.mil');

        $this->assertSame(
            ['encontrado', 'usuarioid', 'nombrecompleto', 'coincidencia'],
            array_keys($salida),
            'la respuesta solo debe llevar identificador, nombre y tipo de coincidencia'
        );
        $this->assertStringNotContainsString('@esoe.mil', json_encode($salida));
        $this->assertStringNotContainsString('T-3003', json_encode($salida));
    }

    public function test_la_coincidencia_no_distingue_mayusculas(): void {
        $this->resetAfterTest();
        $buscado = $this->getDataGenerator()->create_user(['email' => 'my.ruiz@esoe.mil', 'idnumber' => 'M-4004']);
        $this->usuario_autorizado();

        $salida = find_user::execute('email', 'MY.RUIZ@ESOE.MIL');

        $this->assertTrue($salida['encontrado']);
        $this->assertEquals($buscado->id, $salida['usuarioid']);
    }

    public function test_la_ambiguedad_se_devuelve_igual_que_la_ausencia(): void {
        $this->resetAfterTest();
        set_config('allowaccountssameemail', 1);
        $this->getDataGenerator()->create_user(['email' => 'repetido@esoe.mil', 'idnumber' => 'R-1']);
        $this->getDataGenerator()->create_user(['email' => 'repetido@esoe.mil', 'idnumber' => 'R-2']);
        $this->usuario_autorizado();

        $ambiguo = find_user::execute('email', 'repetido@esoe.mil');
        $ausente = find_user::execute('email', 'nadie@esoe.mil');

        $this->assertFalse($ambiguo['encontrado']);
        $this->assertSame($ausente, $ambiguo, 'ambiguedad y ausencia deben ser indistinguibles');
    }

    public function test_no_resuelve_usuarios_borrados_ni_suspendidos(): void {
        $this->resetAfterTest();
        $borrado = $this->getDataGenerator()->create_user(['email' => 'borrado@esoe.mil', 'idnumber' => 'B-1']);
        delete_user($borrado);
        $suspendido = $this->getDataGenerator()->create_user(['email' => 'suspendido@esoe.mil', 'idnumber' => 'P-1']);
        \core_user::update_user(['id' => $suspendido->id, 'suspended' => 1], false);
        $this->usuario_autorizado();

        $this->assertFalse(find_user::execute('idnumber', 'B-1')['encontrado']);
        $this->assertFalse(find_user::execute('email', 'suspendido@esoe.mil')['encontrado']);
    }

    public function test_un_valor_vacio_no_resuelve_nada(): void {
        $this->resetAfterTest();
        $this->usuario_autorizado();

        $this->assertFalse(find_user::execute('email', '   ')['encontrado']);
    }

    public function test_un_campo_no_admitido_se_rechaza(): void {
        $this->resetAfterTest();
        $this->usuario_autorizado();

        $this->expectException(\invalid_parameter_exception::class);
        find_user::execute('password', 'cualquiera');
    }

    public function test_sin_la_capacidad_no_se_puede_buscar(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['email' => 'objetivo@esoe.mil', 'idnumber' => 'O-1']);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        find_user::execute('email', 'objetivo@esoe.mil');
    }
}
