<?php

/*
 * SEG-9 · Campos sensibles por esquema del contrato (x-sensitive-fields de api/openapi.yaml)
 * y el permiso que exige su lectura, según kit/docs/07-permisos-y-roles.md §2.
 *
 * esquema => [campo => [permiso de lectura, categoría del registro de accesos]]
 * Categorías: salary, health, complaint, disciplinary, biometric, bank_account,
 * identity_document, union, other.
 *
 * SensitiveFieldsContractTest falla si un campo sensible del contrato no está aquí
 * o si el permiso no existe en el catálogo.
 */
return [
    'Absence' => ['diagnosis' => ['time.absences.medical_certificate.view', 'health']],
    'AccountingEntry' => ['lines' => ['payroll.accounting_entries.view', 'salary']],
    'Application' => ['expected_salary' => ['recruiting.candidates.salary_expectation.view', 'salary']],
    'Benefit' => ['cost' => ['benefits.benefits.cost.view', 'salary']],
    'BenefitRun' => ['totals' => ['payroll.benefit_runs.view', 'salary']],
    'Compensation' => [
        'base_salary' => ['people.employments.salary.view', 'salary'],
        'components' => ['people.employments.salary.view', 'salary'],
    ],
    'CompensationCycle' => ['budget' => ['compensation.cycles.view', 'salary']],
    'CompensationProposal' => [
        'current' => ['compensation.proposals.view', 'salary'],
        'proposed' => ['compensation.proposals.view', 'salary'],
    ],
    'ComplianceLedgerEntry' => ['payload' => ['compliance.ledger.view', 'other']],
    'Contractor' => ['fee' => ['payroll.contractors.fees.view', 'salary']],
    'ContractorInvoice' => [
        'gross' => ['payroll.contractors.fees.view', 'salary'],
        'withholding' => ['payroll.contractors.fees.view', 'salary'],
        'net' => ['payroll.contractors.fees.view', 'salary'],
    ],
    'DisciplinaryCase' => [
        'facts' => ['discipline.cases.view', 'disciplinary'],
        'statement' => ['discipline.cases.view', 'disciplinary'],
        'measure' => ['discipline.cases.view', 'disciplinary'],
    ],
    'Employment' => ['base_salary' => ['people.employments.salary.view', 'salary']],
    'ExternalIncomeDeclaration' => [
        'gross_income' => ['payroll.external_income_declarations.view', 'salary'],
        'exempt_income' => ['payroll.external_income_declarations.view', 'salary'],
        'tax_withheld' => ['payroll.external_income_declarations.view', 'salary'],
        'monthly_detail' => ['payroll.external_income_declarations.view', 'salary'],
    ],
    'FifthCategoryLedgerEntry' => [
        'gross_annual_income' => ['payroll.fifth_category_ledger.view', 'salary'],
        'net_annual_income' => ['payroll.fifth_category_ledger.view', 'salary'],
        'annual_tax' => ['payroll.fifth_category_ledger.view', 'salary'],
        'ordinary_withholding' => ['payroll.fifth_category_ledger.view', 'salary'],
        'additional_withholding' => ['payroll.fifth_category_ledger.view', 'salary'],
        'withholding_amount' => ['payroll.fifth_category_ledger.view', 'salary'],
    ],
    'FifthCategoryRefund' => ['amount' => ['payroll.fifth_category_refunds.view', 'salary']],
    'Grievance' => [
        'description' => ['grievances.cases.view', 'complaint'],
        'protection_measures' => ['grievances.cases.view', 'complaint'],
    ],
    'JobRequisition' => ['salary_range' => ['people.employments.salary.view', 'salary']],
    'Loan' => [
        'principal' => ['payroll.loans.view', 'salary'],
        'schedule' => ['payroll.loans.view', 'salary'],
        'balance' => ['payroll.loans.view', 'salary'],
    ],
    'MedicalExam' => [
        'fitness' => ['sst.medical_exams.view', 'health'],
        'restrictions' => ['sst.medical_exams.view', 'health'],
        'file_id' => ['sst.medical_exams.view', 'health'],
    ],
    'Offer' => ['salary' => ['recruiting.offers.amount.view', 'salary']],
    'PayrollPeriod' => ['totals' => ['payroll.results.view', 'salary']],
    'PayrollResult' => [
        'earnings' => ['payroll.results.view', 'salary'],
        'deductions' => ['payroll.results.view', 'salary'],
        'employer_contributions' => ['payroll.results.view', 'salary'],
        'net' => ['payroll.results.view', 'salary'],
        'lines' => ['payroll.results.view', 'salary'],
        'trace' => ['payroll.results.trace.view', 'salary'],
    ],
    'Payslip' => ['net' => ['payroll.results.view', 'salary']],
    'Position' => ['budget' => ['org.positions.budget.view', 'salary']],
    // POS-3 · POS-4 (respuestas de /position-requests y /headcount*, esquema genérico en el contrato).
    'PositionRequest' => ['estimated_cost' => ['org.positions.budget.view', 'salary']],
    'HeadcountBudget' => ['monthly_cost' => ['org.positions.budget.view', 'salary']],
    'HeadcountUnit' => [
        'projected_cost' => ['org.positions.budget.view', 'salary'],
        'budgeted_cost' => ['org.positions.budget.view', 'salary'],
        'deviation_cost' => ['org.positions.budget.view', 'salary'],
    ],
    'HeadcountPosition' => [
        'base_cost' => ['org.positions.budget.view', 'salary'],
        'projected_cost' => ['org.positions.budget.view', 'salary'],
    ],
    'Provision' => ['amount' => ['payroll.provisions.view', 'salary']],
    'RegistryProfile' => [
        'has_disability' => ['people.people.health_data.view', 'health'],
        'is_unionized' => ['unions.memberships.view', 'union'],
        'initial_basic_remuneration' => ['people.employments.salary.view', 'salary'],
    ],
    'SalaryAdvance' => [
        'amount' => ['payroll.salary_advances.view', 'salary'],
        'accrued_basis' => ['payroll.salary_advances.view', 'salary'],
    ],
    'SalaryBand' => [
        'min' => ['compensation.bands.view', 'salary'],
        'mid' => ['compensation.bands.view', 'salary'],
        'max' => ['compensation.bands.view', 'salary'],
    ],
    'Settlement' => [
        'lines' => ['payroll.settlements.view', 'salary'],
        'total' => ['payroll.settlements.view', 'salary'],
    ],
    'SstIncident' => ['medical_details' => ['people.people.health_data.view', 'health']],
    'SuccessionPlan' => ['successors' => ['careers.succession_plans.view', 'other']],
    'TalentReview' => ['placements' => ['careers.talent_reviews.view', 'other']],
    'UnionMembership' => [
        'union_id' => ['unions.memberships.view', 'union'],
        'employment_id' => ['unions.memberships.view', 'union'],
    ],
];
