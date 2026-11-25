# Puente ESOE (`local_esoe`)

Plugin local que conecta el Moodle institucional de la ESOE con la plataforma ESOE
(`sapplopez57-eng/plataforma-esoe-ff`). No modifica el núcleo de Moodle: vive entero en
`public/local/esoe/`, como exige ADR-001.

## Qué resuelve

La plataforma consumía servicios web del núcleo y pagaba dos costos por ello.

**Una llamada en vez de dos por usuario.** Para pintar «mis plataformas» hacía
`core_enrol_get_users_courses` y `gradereport_overview_get_course_grades` y unía los
resultados en cliente. Cada llamada carga su propia espera, sus reintentos y su riesgo de
quedarse a medias. `local_esoe_get_expediente` hace la unión en el servidor y devuelve una
sola respuesta.

**Menos datos personales en tránsito.** Para detectar la inscripción hacía
`core_user_get_users_by_field`, que devuelve el **registro completo** de cada coincidencia
—todos los campos de perfil—, y recién entonces exigía coincidencia única en cliente.
Datos que la plataforma no necesita viajaban por la red en cada detección.
`local_esoe_find_user` aplica la unicidad en el servidor y devuelve solo el identificador,
el nombre y el tipo de coincidencia.

## Funciones

| Función | Capacidad | Devuelve |
|---|---|---|
| `local_esoe_get_expediente` | `local/esoe:leerexpediente` (+ `local/esoe:leerexpedienteajeno` y `moodle/grade:viewall` para leer el de otro) | identidad, cursos inscritos, avance y calificación |
| `local_esoe_find_user` | `local/esoe:buscarusuario` | identificador, nombre y `CORREO` o `IDENTIDAD` |

## Decisiones de seguridad

- **El servicio nace deshabilitado y restringido.** `enabled => 0` y `restrictedusers => 1`
  en `db/services.php`: el administrador tiene que habilitarlo y autorizar al usuario del
  token a propósito. Así el token institucional alcanza estas dos funciones y no el servicio
  móvil completo.
- **Ninguna capacidad sensible se concede por arquetipo.** `leerexpedienteajeno` y
  `buscarusuario` tienen `archetypes` vacío: se conceden sobre un rol propio, nunca por
  herencia.
- **La ambigüedad y la ausencia son indistinguibles.** `find_user` devuelve lo mismo cuando
  no hay coincidencia y cuando hay varias, para que la función no sirva como oráculo para
  averiguar si un correo existe.
- **Las calificaciones salen por el camino del núcleo.** Se reutiliza
  `grade_report_overview::setup_courses_data()` en vez de consultar las tablas de notas a
  mano, porque ese camino aplica las reglas de visibilidad: una consulta propia devolvería
  también notas ocultas.
- **Cero SQL construido a mano.** Todo acceso a datos pasa por APIs del núcleo
  (`enrol_get_users_courses`, `core_user::get_user`) o por `$DB` con marcadores de posición
  y `sql_equal`, así que no hay superficie de inyección.
- **Los nombres de curso salen filtrados** con `core_external\util::format_string` y el
  contexto del curso, de modo que un nombre con etiquetas no llega crudo a la plataforma.
- **No se leen usuarios borrados, suspendidos ni el invitado.**
- **El plugin no guarda nada:** se declara como proveedor nulo de privacidad con su razón.

## Instalación

1. Copiar el plugin en `public/local/esoe/` del Moodle institucional (rama `esoe/estable`).
2. Entrar como administrador para que Moodle corra la actualización y registre las
   funciones y capacidades.
3. **Administración del sitio → Servidor → Servicios web → Servicios externos**: habilitar
   «Puente ESOE» y autorizar el usuario del token.
4. Crear el token para ese usuario sobre el servicio «Puente ESOE», y ponerlo en
   `MOODLE_TOKEN_SERVICIO` de la plataforma.
5. Conceder a ese usuario un rol de sistema con las capacidades que necesite:
   `local/esoe:buscarusuario` para la detección y, si va a leer expedientes ajenos,
   `local/esoe:leerexpediente`, `local/esoe:leerexpedienteajeno` y `moodle/grade:viewall`.

## Antes de levantar el sitio

Correr en la plataforma `npm run comprobar:moodle-base`, que falla si la base del Moodle
arrastra el esquema de una rama de desarrollo: Moodle no baja de versión. Después
`npm run comprobar:moodle`, que interroga al sitio ya levantado.

## Pruebas

`public/local/esoe/tests/external/` cubre los dos servicios con PHPUnit, con foco en
autorización y en minimización de datos: sin capacidad no se puede llamar, la ambigüedad no
se distingue de la ausencia, la respuesta no lleva correo ni número de identidad, un nombre
de curso con etiquetas sale filtrado, y no se leen usuarios borrados ni suspendidos.

```bash
vendor/bin/phpunit --filter local_esoe
```
