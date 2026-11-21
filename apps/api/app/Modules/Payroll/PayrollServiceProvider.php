<?php

namespace App\Modules\Payroll;

use App\Modules\Payroll\Application\ConceptAccounting;
use App\Modules\Payroll\Application\PayrollOpeningBalances;
use App\Modules\Payroll\Application\Settlements\SettlementDocuments;
use App\Modules\Payroll\Console\AlertSettlementDeadlines;
use App\Modules\Payroll\Console\NotifyPayrollObligations;
use App\Modules\Payroll\Console\RemindPayslipAcknowledgement;
use App\Modules\Payroll\Infrastructure\Exports\FifthCategoryLedgerExportSource;
use App\Modules\Payroll\Infrastructure\Imports\LegacyPayrollResultsImporter;
use App\Modules\Payroll\Infrastructure\Imports\PayrollBalancesImporter;
use App\Modules\Payroll\Infrastructure\Imports\PayrollConceptsImporter;
use App\Modules\Payroll\Infrastructure\Imports\PayrollIncidentsImporter;
use App\Modules\Payroll\Infrastructure\Setup\PayrollConceptsPackComponent;
use App\Modules\Payroll\Infrastructure\Subscribers\GeneratePayslips;
use App\Modules\Payroll\Infrastructure\Subscribers\OpenSettlementOnTermination;
use App\Modules\Payroll\Infrastructure\Subscribers\ProcessPayrollRun;
use App\Modules\Payroll\Infrastructure\Subscribers\RecalculateRetroactively;
use App\Modules\Payroll\Infrastructure\Workflow\PayrollPeriodOutcome;
use App\Modules\Payroll\Infrastructure\Workflow\SettlementOutcome;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Outbox\OutboxService;
use App\Shared\Payroll\ConceptAccounts;
use App\Shared\Payroll\OpeningBalances;
use App\Shared\Setup\CountryPackRegistry;
use App\Shared\Signatures\SignatureSubjectRegistry;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo NOM · Motor de nómina (kit/docs/modulos/M-NOM.md). Módulo comercial payroll.
 * Núcleo genérico (Domain: fórmulas, dependencias y cálculo) + paquete de país (packages/country-xx/data/
 * motor_planilla.php) + régimen laboral por empresa y por trabajador. Se usa solo por servicios y por la API.
 * NOM-8: corridas en segundo plano; NOM-7: recálculo retroactivo al cambiar datos con fecha pasada.
 * Módulo CPT · Conceptos y fórmulas (M-CPT): catálogo con versiones, borrador y publicación, afectaciones,
 * contabilización por concepto (puerto ConceptAccounts) y conceptos del paquete de país.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConceptAccounts::class, ConceptAccounting::class);
        $this->app->bind(OpeningBalances::class, PayrollOpeningBalances::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        // CPT-5 · Conceptos peruanos precargados como parte del paquete de país (IMP-4).
        $this->app->make(CountryPackRegistry::class)->register($this->app->make(PayrollConceptsPackComponent::class));
        // CPT-6 · Importación de conceptos propios y de incidencias del periodo (motor de IMP-1).
        $imports = $this->app->make(ImportRegistry::class);
        $imports->register($this->app->make(PayrollConceptsImporter::class));
        $imports->register($this->app->make(PayrollIncidentsImporter::class));
        // PER-12 · Acumulados del ejercicio traídos del sistema anterior.
        $imports->register($this->app->make(PayrollBalancesImporter::class));
        // PER-13 · Resultados del sistema anterior para la planilla en paralelo.
        $imports->register($this->app->make(LegacyPayrollResultsImporter::class));
        // PER-11 · Libro de retenciones de quinta exportable con el servicio común (UI-11).
        $this->app->make(ExportRegistry::class)->register($this->app->make(FifthCategoryLedgerExportSource::class));
        $outbox = $this->app->make(OutboxService::class);
        $outbox->subscribe($this->app->make(ProcessPayrollRun::class));
        $outbox->subscribe($this->app->make(RecalculateRetroactively::class));
        // BOL-1 · Boletas al cerrar el periodo.
        $outbox->subscribe($this->app->make(GeneratePayslips::class));
        // LIQ-1 · RN-LIQ-01 · Liquidación en borrador al registrarse el cese; anulada si se revierte.
        $outbox->subscribe($this->app->make(OpenSettlementOnTermination::class));
        if ($this->app->runningInConsole()) {
            $this->commands([RemindPayslipAcknowledgement::class, AlertSettlementDeadlines::class, NotifyPayrollObligations::class]);
            $this->app->booted(function (): void {
                // BOL-2 · RN-BOL-03 · Recordatorio diario del acuse pendiente.
                $this->app->make(Schedule::class)->command('payslips:remind-acknowledgement')->dailyAt('09:20')->withoutOverlapping();
                // LIQ-3 · RN-LIQ-02 · Aviso del plazo de pago de las liquidaciones.
                $this->app->make(Schedule::class)->command('settlements:alert-deadlines')->hourly()->withoutOverlapping();
                // CUM-9 · Avisos de obligaciones de planilla por vencer y vencidas (NOT-2).
                $this->app->make(Schedule::class)->command('payroll:obligation-alerts')->dailyAt('08:40')->withoutOverlapping();
            });
        }
        // PER-8 · Aprobación del periodo por el flujo configurado (WFL-2).
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(PayrollPeriodOutcome::class));
        // LIQ-3 · Aprobación de la liquidación por el flujo configurado; LIQ-4 · firma de los documentos de cese (DOC-6).
        $this->app->make(ApprovalOutcomeRegistry::class)->register($this->app->make(SettlementOutcome::class));
        $this->app->make(SignatureSubjectRegistry::class)->register($this->app->make(SettlementDocuments::class));
    }
}
