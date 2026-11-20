# Divulgación progresiva (UI-10)

Cada pantalla muestra primero lo que la mayoría necesita y deja lo avanzado un paso más allá. El objetivo es que
un usuario nuevo complete la tarea sin leer campos que no le aplican, y que quien sí los necesita los encuentre en
el mismo lugar de siempre.

## Los tres niveles

| Nivel | Qué va ahí | Componente |
|---|---|---|
| 1 · A la vista | Campos obligatorios y las decisiones que cambian el resultado (quién, qué, cuándo) | Formulario normal |
| 2 · Plegado | Opciones con valor por defecto razonable: prioridad, notas, campos personalizados, filtros finos | `Disclosure` (`@/ui/components/Disclosure`) |
| 3 · Segundo nivel | Configuración avanzada del módulo: parámetros, reglas, plantillas | Configuración → sección del módulo, o Configuración → Parámetros |

## Reglas

1. **Alta en pasos.** Un formulario de alta pide solo los campos obligatorios en el primer paso. Si el alta es larga,
   se divide en pasos con su avance (ejemplo: el ingreso de un colaborador, `HireScreen`: documento → persona →
   puesto → contrato → revisión). Lo opcional va plegado dentro de cada paso.
2. **El plegado lleva resumen.** El botón dice qué hay adentro («Más opciones · prioridad, descripción y campos
   propios»), para que nadie tenga que abrirlo para saberlo.
3. **Un error abre lo plegado.** Si el servidor devuelve un error en un campo plegado, la sección se abre sola
   (`forceOpen`) y el error queda junto a su campo (RN-UI-05).
4. **Lo que ya tiene valor se muestra abierto.** Al editar un registro con datos en la sección plegada
   (`defaultOpen`), la sección aparece abierta: ocultar un dato ya cargado confunde.
5. **La configuración avanzada no vive en la pantalla de trabajo.** Las pantallas operativas (tareas, vacaciones,
   ausencias) no traen interruptores de configuración; enlazan a la sección del módulo en Configuración, y los
   valores legales o de política viven en Parámetros con su vigencia.
6. **Nada se oculta por permiso con un plegado.** Lo que el usuario no puede ver no se pliega: no se envía
   (regla 6 de `CLAUDE.md`). El plegado es solo para ordenar lo que sí puede ver.

## Ejemplos en el código

- `TasksScreen` → panel de tarea: título, responsable y vencimiento a la vista; prioridad, descripción y campos
  personalizados en «Más opciones».
- `HireScreen` → alta en cinco pasos con su avance.
- `CompaniesScreen` → datos avanzados de la empresa en un bloque plegado.
- `ParametersScreen` → historial de cada parámetro plegado bajo su valor vigente.
- Catálogo de componentes (`/componentes` → Divulgación progresiva) con el patrón en vivo.
