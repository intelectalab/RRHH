<?php

use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\StateMachine\StateMachine;

/**
 * Regla 3 de CLAUDE.md · Prueba de matriz (cada estado × cada acción) generada desde la definición de cada
 * máquina de estados del dominio. Descubre todas las subclases de StateMachine en app/, así una máquina nueva
 * queda cubierta sin escribir su propia matriz: lo declarado lleva al estado destino y todo lo demás lanza
 * InvalidTransitionException (409 invalid_transition) con las acciones permitidas del estado actual.
 */
/**
 * @return array<string, array{0: class-string<StateMachine>}>
 */
function stateMachineClasses(): array
{
    $classes = [];
    $base = dirname(__DIR__, 2).'/app';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (! str_contains($source, 'extends StateMachine') || ! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
            || ! preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $cls)) {
            continue;
        }
        $fqcn = $ns[1].'\\'.$cls[1];
        if (class_exists($fqcn) && is_subclass_of($fqcn, StateMachine::class) && ! (new ReflectionClass($fqcn))->isAbstract()) {
            $classes[$fqcn] = [$fqcn];
        }
    }
    ksort($classes);

    return $classes;
}

it('descubre las máquinas de estado del dominio', function () {
    expect(count(stateMachineClasses()))->toBeGreaterThan(20);
});

it('aplica la matriz estado × acción de cada máquina', function (string $class) {
    /** @var class-string<StateMachine> $class */
    $machine = new $class;
    $transitions = $machine->transitions();
    expect($transitions)->not->toBeEmpty();

    $states = array_keys($transitions);
    $actions = [];
    foreach ($transitions as $from => $byAction) {
        foreach ($byAction as $action => $to) {
            expect($action !== '' && $to !== '')->toBeTrue();
            $states[] = $to;
            $actions[] = $action;
        }
    }
    $states = array_values(array_unique($states));
    $actions = array_values(array_unique($actions));

    foreach ($states as $state) {
        expect($machine->allowedActions($state))->toEqualCanonicalizing(array_keys($transitions[$state] ?? []));
        foreach ($actions as $action) {
            if (isset($transitions[$state][$action])) {
                expect($machine->can($state, $action))->toBeTrue();
                expect($machine->getNextState($state, $action))->toBe($transitions[$state][$action]);

                continue;
            }
            expect($machine->can($state, $action))->toBeFalse();
            try {
                $machine->getNextState($state, $action);
                $this->fail("{$class}: «{$action}» desde «{$state}» debía ser una transición inválida");
            } catch (InvalidTransitionException) {
                expect(true)->toBeTrue();
            }
        }
    }
})->with(fn () => stateMachineClasses());
