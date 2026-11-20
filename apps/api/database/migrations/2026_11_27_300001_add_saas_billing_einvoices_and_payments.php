<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SAS-11 · ADR 0010 · Cobro de la suscripción y estado de cuenta:
 * - saas_invoices: estado «observado» (comprobante rechazado por la administración tributaria), datos del comprobante
 *   electrónico (serie, número, ticket, constancia, mensaje, intentos), impuesto aplicado, monto pagado, aviso previo
 *   y mora, anulación y autor.
 * - saas_payments: pagos manuales o de la pasarela (adaptador de cobro); se anulan, no se borran.
 * - current_tenant_billing(): estado de cuenta de la organización activa (la aplicación no lee tablas de la plataforma).
 * - Permisos de consola platform.billing.view y platform.billing.manage.
 *
 * Idempotente (IF NOT EXISTS / ON CONFLICT): sirve sobre una base creada desde schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE saas_invoices
              ADD COLUMN IF NOT EXISTS tax_rate numeric(7,4),
              ADD COLUMN IF NOT EXISTS tax_treatment text CHECK (tax_treatment IN ('taxed','export_exempt')),
              ADD COLUMN IF NOT EXISTS amount_paid numeric(14,2) NOT NULL DEFAULT 0,
              ADD COLUMN IF NOT EXISTS buyer_tax_id text,
              ADD COLUMN IF NOT EXISTS buyer_name text,
              ADD COLUMN IF NOT EXISTS einvoice_status text NOT NULL DEFAULT 'not_sent' CHECK (einvoice_status IN ('not_sent','pending','accepted','rejected')),
              ADD COLUMN IF NOT EXISTS einvoice_series text,
              ADD COLUMN IF NOT EXISTS einvoice_number text,
              ADD COLUMN IF NOT EXISTS einvoice_ticket text,
              ADD COLUMN IF NOT EXISTS einvoice_hash text,
              ADD COLUMN IF NOT EXISTS einvoice_message text,
              ADD COLUMN IF NOT EXISTS einvoice_accepted_at timestamptz,
              ADD COLUMN IF NOT EXISTS einvoice_attempts integer NOT NULL DEFAULT 0,
              ADD COLUMN IF NOT EXISTS reminded_at timestamptz,
              ADD COLUMN IF NOT EXISTS overdue_at timestamptz,
              ADD COLUMN IF NOT EXISTS voided_at timestamptz,
              ADD COLUMN IF NOT EXISTS void_reason text,
              ADD COLUMN IF NOT EXISTS created_by_platform_user_id uuid;

            DO $$
            DECLARE c record;
            BEGIN
              FOR c IN SELECT conname FROM pg_constraint WHERE conrelid = 'saas_invoices'::regclass AND contype = 'c'
                AND pg_get_constraintdef(oid) LIKE '%status = ANY%' AND pg_get_constraintdef(oid) NOT LIKE '%einvoice_status%'
              LOOP
                EXECUTE format('ALTER TABLE saas_invoices DROP CONSTRAINT %I', c.conname);
              END LOOP;
              ALTER TABLE saas_invoices ADD CONSTRAINT saas_invoices_status_check CHECK (status IN ('draft','observed','issued','paid','overdue','void'));
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_saas_invoices_created_by_platform_user_id') THEN
                ALTER TABLE saas_invoices ADD CONSTRAINT fk_saas_invoices_created_by_platform_user_id FOREIGN KEY (created_by_platform_user_id) REFERENCES platform_users (id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_saas_invoices_created_by_platform_user_id ON saas_invoices (created_by_platform_user_id);

            CREATE TABLE IF NOT EXISTS saas_payments (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL,
              saas_invoice_id uuid NOT NULL,
              amount numeric(14,2) NOT NULL,
              currency char(3) NOT NULL,
              paid_on date NOT NULL,
              method text NOT NULL DEFAULT 'transfer' CHECK (method IN ('transfer','card','wallet','cash','other')),
              reference text,
              source text NOT NULL DEFAULT 'manual' CHECK (source IN ('manual','gateway')),
              gateway_ref text,
              status text NOT NULL DEFAULT 'confirmed' CHECK (status IN ('confirmed','reversed')),
              recorded_by_platform_user_id uuid,
              reversed_at timestamptz,
              reverse_reason text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT fk_saas_payments_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (id),
              CONSTRAINT fk_saas_payments_saas_invoice_id FOREIGN KEY (saas_invoice_id) REFERENCES saas_invoices (id),
              CONSTRAINT fk_saas_payments_recorded_by_platform_user_id FOREIGN KEY (recorded_by_platform_user_id) REFERENCES platform_users (id)
            );
            COMMENT ON TABLE saas_payments IS 'SAS-11 · Pagos de la organización a un comprobante del SaaS, registrados a mano en la consola o llegados por la pasarela (adaptador de cobro). Un pago no se borra: se anula con motivo.';
            CREATE INDEX IF NOT EXISTS ix_saas_payments_tenant_id ON saas_payments (tenant_id);
            CREATE INDEX IF NOT EXISTS ix_saas_payments_saas_invoice_id ON saas_payments (saas_invoice_id);
            CREATE INDEX IF NOT EXISTS ix_saas_payments_recorded_by_platform_user_id ON saas_payments (recorded_by_platform_user_id);
            DROP TRIGGER IF EXISTS trg_saas_payments_touch ON saas_payments;
            CREATE TRIGGER trg_saas_payments_touch BEFORE UPDATE ON saas_payments FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            -- Tabla de la plataforma: la aplicación de las organizaciones no la toca.
            REVOKE ALL ON saas_payments FROM PUBLIC, gestia_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON saas_payments TO gestia_platform;

            CREATE OR REPLACE FUNCTION current_tenant_billing() RETURNS jsonb
              LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $f$
              SELECT jsonb_build_object(
                'subscription', (SELECT jsonb_build_object('plan', p.name, 'status', s.status, 'billing_cycle', s.billing_cycle,
                    'price', COALESCE(s.price, p.price)::text, 'currency', COALESCE(s.currency, p.currency), 'starts_on', s.starts_on,
                    'trial_ends_on', s.trial_ends_on)
                  FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.tenant_id = app_tenant_id() LIMIT 1),
                'invoices', COALESCE((SELECT jsonb_agg(jsonb_build_object('id', i.id, 'number', i.number, 'status', i.status,
                    'period_start', i.period_start, 'period_end', i.period_end, 'subtotal', i.subtotal::text, 'tax', i.tax::text,
                    'total', i.total::text, 'amount_paid', i.amount_paid::text, 'currency', i.currency, 'issued_at', i.issued_at,
                    'due_on', i.due_on, 'paid_at', i.paid_at, 'einvoice_status', i.einvoice_status, 'einvoice_series', i.einvoice_series,
                    'einvoice_number', i.einvoice_number, 'einvoice_hash', i.einvoice_hash, 'einvoice_accepted_at', i.einvoice_accepted_at,
                    'einvoice_message', i.einvoice_message) ORDER BY i.period_start DESC, i.number DESC)
                  FROM saas_invoices i WHERE i.tenant_id = app_tenant_id() AND i.status <> 'draft'), '[]'::jsonb),
                'payments', COALESCE((SELECT jsonb_agg(jsonb_build_object('id', y.id, 'invoice_id', y.saas_invoice_id, 'amount', y.amount::text,
                    'currency', y.currency, 'paid_on', y.paid_on, 'method', y.method, 'status', y.status) ORDER BY y.paid_on DESC)
                  FROM saas_payments y WHERE y.tenant_id = app_tenant_id()), '[]'::jsonb),
                'settings', COALESCE((SELECT jsonb_object_agg(key, value) FROM platform_settings
                  WHERE key IN ('billing.reminder_days_before_due', 'billing.suspend_after_overdue_days')), '{}'::jsonb))
            $f$;
            REVOKE ALL ON FUNCTION current_tenant_billing() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION current_tenant_billing() TO gestia_app;

            INSERT INTO permissions (code, module_code, resource, action, kind, is_sensitive, description) VALUES
              ('platform.billing.view', 'base', 'billing', 'view', 'action', false, 'Ver cargos, comprobantes electrónicos, pagos y estado de cuenta de las organizaciones'),
              ('platform.billing.manage', 'base', 'billing', 'manage', 'action', false, 'Generar cargos, emitir comprobantes electrónicos, registrar pagos y anular comprobantes')
            ON CONFLICT (code) DO NOTHING;
            INSERT INTO system_role_permissions (role_code, permission_code, scope) VALUES
              ('superadmin_saas', 'platform.billing.view', 'platform'),
              ('superadmin_saas', 'platform.billing.manage', 'platform')
            ON CONFLICT DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
