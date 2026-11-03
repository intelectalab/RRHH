# 09 · Integraciones

Regla (CLAUDE.md, regla 8): ningún módulo de dominio conoce a un proveedor. Cada capacidad externa es un **puerto** (interfaz en `Domain/Ports` o `Shared/Ports`), con al menos dos **adaptadores**: uno falso y uno real. Los efectos externos salen por **outbox** y se ejecutan en la cola `integrations` con idempotencia.

## 1. Anatomía común

```
Caso de uso ──(transacción)──► outbox ──► trabajo en cola ──► Puerto ──► Adaptador ──► Proveedor
                                                      │
                                                      └─► integration_calls (bitácora) · salud del conector
Proveedor ──► webhook entrante /api/v1/integrations/{conexión}/… ──► verificar firma ──► evento interno
```

Todo adaptador:

1. Implementa la interfaz completa del puerto y **solo** esa interfaz.
2. Recibe su configuración de `integration_connections` (por organización y, si aplica, por razón social); los secretos van cifrados y nunca se devuelven por API (solo `secret_hint`).
3. Traduce errores del proveedor a las excepciones del puerto: `TransientFailure` (reintentar), `PermanentFailure` (no reintentar, marcar y avisar), `ConfigurationError` (conector mal configurado), `RateLimited` (reintentar tras `retryAfter`).
4. Envía la **clave de idempotencia** al proveedor si este la admite; si no, consulta el estado antes de reintentar.
5. Tiene tiempo máximo de espera, reintentos con espera creciente y **cortacircuitos** por conexión.
6. Registra cada llamada en la bitácora de integración (sin cuerpos con datos personales; con `request_id`, duración, resultado).
7. Implementa `health(): HealthStatus`.
8. Pasa la **misma batería de pruebas de contrato** que el adaptador falso (`Tests/Contract/<Puerto>ContractTest`).

Selección de adaptador: `IntegrationManager::for($puerto, $tenant, $legalEntity = null)` devuelve el adaptador configurado; si no hay conexión, devuelve el adaptador interno o falso según el entorno. En producción, un puerto sin conexión real responde `ConfigurationError` con mensaje claro, salvo los que tienen implementación propia (firma con evidencia propia, notificación en la aplicación).

## 2. Implementaciones falsas

Viven en `Infrastructure/Adapters/Fake*` de cada módulo y se registran por defecto en `local` y `testing`.

- **Deterministas**: mismo dato de entrada ⇒ mismo resultado.
- **Observables**: guardan lo «enviado» en una tabla o bandeja visible (`/dev/mailbox`, bandeja de mensajes simulados) para que el flujo se pueda recorrer de punta a punta sin proveedor.
- **Programables**: `Fake::failNext(TransientFailure::class)`, `Fake::delay()`, `Fake::respondWith()` para probar reintentos, tiempos de espera e idempotencia.
- **Completas**: implementan también el camino de vuelta (webhook entrante simulado: «firmado», «entregado», «leído»).
- En la organización demo, lo simulado se rotula como simulado en la interfaz.

## 3. Puertos

Firmas en pseudocódigo PHP; los DTO son `readonly`. `Ref` = identificador del proveedor.

### 3.1 Firma electrónica — `SignatureProvider` (DOC)

Dos niveles (C-12): firma electrónica con **evidencia propia** (adaptador interno, siempre disponible) y firma digital con certificado a través de un proveedor.

```php
interface SignatureProvider {
  public function capabilities(): SignatureCapabilities;      // simple | avanzada | certificado, orden, firma masiva
  public function createEnvelope(EnvelopeRequest $r): EnvelopeRef;   // documento(s) sha256, firmantes, orden, vencimiento
  public function signingUrl(EnvelopeRef $e, SignerRef $s): Url;      // si el proveedor aloja la firma
  public function status(EnvelopeRef $e): EnvelopeStatus;
  public function cancel(EnvelopeRef $e, string $reason): void;
  public function downloadSigned(EnvelopeRef $e): SignedPackage;      // PDF firmado + evidencia
  public function parseWebhook(Request $r): SignatureEvent;           // viewed | signed | declined | completed | expired
}
```

- Adaptador interno: registra por firmante hash del documento, fecha y hora, IP, agente, método de verificación (OTP por SMS, correo o WhatsApp; clave de la cuenta) y genera una constancia PDF. La validez por tipo de documento es la decisión D-10.
- El dominio guarda siempre su propia evidencia aunque el proveedor tenga la suya.
- Falso: «firma» al invocar `Fake::sign($signer)`; produce PDF con marca «SIMULADO».

### 3.2 Correo — `MailChannel` (NOT)

```php
interface MailChannel {
  public function send(MailMessage $m): MessageRef;           // from por organización (marca blanca), plantilla, adjuntos por file_id
  public function parseWebhook(Request $r): DeliveryEvent;    // delivered | bounced | complained
}
```

- Rebotes y quejas marcan la dirección como no entregable y generan aviso a RRHH.
- Dominio de envío propio por organización según plan (verificación DKIM/SPF en la conexión).
- Falso: buzón local consultable.

### 3.3 WhatsApp — `WhatsAppChannel` (NOT, WHA)

```php
interface WhatsAppChannel {
  public function sendTemplate(Phone $to, TemplateRef $t, array $vars): MessageRef;   // mensajes iniciados por la empresa
  public function sendText(Phone $to, string $text, ConversationRef $c): MessageRef;  // dentro de una conversación abierta
  public function sendDocument(Phone $to, FileRef $f, ConversationRef $c): MessageRef;
  public function parseWebhook(Request $r): InboundMessage|DeliveryEvent;
  public function templates(): array;                                                 // plantillas aprobadas
}
```

- Dos usos: **notificación** (plantillas) y **autoservicio** (E-11): el mensaje entrante se enruta a `Modules/Integrations/WhatsApp/Conversation`, que identifica a la persona por teléfono verificado + segundo factor para operaciones sensibles (boleta, constancia), y ejecuta **los mismos casos de uso** que la API (solicitar vacaciones, descargar boleta, pedir constancia, marcar).
- Consentimiento de canal por persona; baja con una palabra clave.
- Ningún dato sensible en texto plano de plantilla: la boleta se entrega como enlace de corta vida o documento tras verificar identidad.
- Proveedor a elegir: D-11. Falso: consola de conversación simulada.

### 3.4 Push — `PushChannel` (NOT)

```php
interface PushChannel {
  public function send(DeviceToken $d, PushMessage $m): MessageRef;   // título y texto sin datos sensibles; deep link
  public function invalidTokens(array $refs): array;
}
```

Canal de notificación por aplicación (`in_app`) no usa puerto: es propio.

### 3.5 Bancos y billeteras — `PayoutFileGenerator` y `PayoutGateway` (DEC, PRE, GAS)

```php
interface PayoutFileGenerator {                 // camino por archivo (por defecto)
  public function supports(BankCode $b, PayoutKind $k): bool;      // sueldos, CTS, proveedores
  public function generate(PayoutBatch $b): GeneratedFile;         // formato del banco
  public function validate(PayoutBatch $b): ValidationReport;      // cuentas, montos, moneda
}
interface PayoutGateway {                       // camino en línea (opcional: billeteras, banca por API)
  public function submit(PayoutBatch $b, IdempotencyKey $k): PayoutRef;
  public function status(PayoutRef $r): PayoutStatus;              // por línea: pagado | rechazado | pendiente
}
```

- El lote de pago nace de un periodo aprobado; su total debe coincidir con el neto del periodo (se valida antes de generar).
- El archivo generado se guarda con `sha256`; regenerar crea versión nueva y audita.
- `mark-paid` es una acción humana cuando el camino es por archivo; con pasarela, lo marca el estado del proveedor.
- Los formatos por banco son parte del paquete de país. Qué bancos primero: D-12.

### 3.6 Biométricos y dispositivos — `PunchSource` (ASI)

```php
interface PunchSource {
  public function pull(DeviceRef $d, Cursor $since): PunchBatch;         // modelo de extracción (agente o API del fabricante)
  public function parsePush(Request $r): PunchBatch;                     // modelo de envío del dispositivo
  public function syncRoster(DeviceRef $d, array $people): SyncResult;   // enviar personas habilitadas al reloj (opcional)
  public function health(DeviceRef $d): HealthStatus;
}
```

- Además existe la **entrada abierta** `POST /integrations/biometric/{device_id}/punches` (formato propio documentado) para cualquier reloj con un agente intermedio: responde a la queja de compatibilidad limitada (research §5.2).
- Mapeo `identificador en el reloj ↔ relación laboral` en tabla propia; marcaciones sin mapeo quedan en una bandeja de «no identificadas», no se pierden.
- Deduplicación por `(device_id, identificador, instante)`.
- La **plantilla biométrica no sale del dispositivo**; Ayni HR recibe solo el evento (ver `12` §12). Si un proveedor de selfie con detección de vida devuelve una imagen, se trata como dato sensible con retención corta.

### 3.7 SSO — `IdentityProvider` (SEG, INT)

```php
interface IdentityProvider {
  public function authorizationUrl(State $s): Url;
  public function handleCallback(Request $r): ExternalIdentity;    // sujeto, correo, grupos
  public function logoutUrl(): ?Url;
}
interface DirectorySync {                                          // aprovisionamiento (SCIM u otro), opcional
  public function parse(Request $r): DirectoryChange;              // alta, cambio, baja de cuenta
}
```

- OIDC y SAML. Vinculación por identificador externo, no por correo. El SSO autentica; los **permisos siguen siendo los de Ayni HR**.
- El personal sin correo sigue entrando con documento + clave u OTP aunque la organización tenga SSO.
- Dirección inversa: `employee.hired` / `employee.terminated` pueden crear y desactivar cuentas en el directorio por webhook.

### 3.8 Calendario — `CalendarProvider` (ENT, FBK, VAC)

```php
interface CalendarProvider {
  public function freeBusy(array $attendees, DateRange $r): array;
  public function createEvent(CalendarEvent $e, IdempotencyKey $k): EventRef;   // entrevistas, 1:1, capacitaciones
  public function updateEvent(EventRef $r, CalendarEvent $e): void;
  public function cancelEvent(EventRef $r): void;
}
```

Alternativa sin conexión: archivo `.ics` adjunto y suscripción de calendario de solo lectura por usuario con token (ausencias del equipo).

### 3.9 ERP y contabilidad — `AccountingExporter` y `MasterDataSync` (PRV, INT)

```php
interface AccountingExporter {
  public function export(AccountingEntry $e, IdempotencyKey $k): ExportResult;   // archivo en formato del ERP o llamada a su API
  public function validateMapping(LegalEntityRef $le): ValidationReport;         // cuentas y centros de costo mapeados
}
interface MasterDataSync {
  public function pullCostCenters(LegalEntityRef $le): array;
  public function pullAccounts(LegalEntityRef $le): array;
}
```

- El asiento se arma en `Payroll` desde los resultados cerrados y el mapeo concepto → cuenta por centro de costo; el adaptador solo lo transporta.
- Un asiento exportado no se modifica: se genera uno de reversión y uno nuevo.
- Camino por defecto: archivo (CSV o plantilla del ERP). Qué ERP primero: D-12.

### 3.10 Proveedor de IA — `AiProvider` (IAR)

```php
interface AiProvider {
  public function complete(AiRequest $r): AiResponse;        // mensajes, herramientas declaradas, límites
  public function stream(AiRequest $r): iterable;
  public function embed(array $texts): array;                // opcional
  public function capabilities(): AiCapabilities;
}
```

- El adaptador no ejecuta herramientas: devuelve la intención de llamada; la capa `Ai` la ejecuta con los permisos del usuario (`02` §19).
- Configurable: retención de datos en el proveedor y región, según lo que ofrezca cada uno (A-09, A-08).
- Falso: respuestas grabadas por caso de uso; permite pruebas deterministas.

### 3.11 SUNAT, T-Registro, PLAME y AFPnet — `FilingGenerator` (DEC)

**No hay conexión con la autoridad.** El sistema genera y valida archivos; la empresa los presenta en el canal oficial y registra el resultado (D-09).

```php
interface FilingGenerator {
  public function kind(): FilingKind;                              // plame | t_registro | afpnet | …
  public function validate(FilingInput $i): ValidationReport;      // datos faltantes o inconsistentes por trabajador
  public function generate(FilingInput $i): array;                 // uno o varios archivos con la estructura oficial
  public function specVersion(): string;                           // versión de la estructura que implementa
}
```

- Implementaciones en `packages/country-pe/filings/`. La estructura de cada archivo y la tabla de equivalencia concepto → código oficial son **datos del paquete con vigencia**; al cambiar la estructura se publica una versión nueva del paquete.
- `validate` corre antes de permitir generar: documento de identidad, régimen pensionario, fechas de alta y baja, conceptos sin código oficial.
- Flujo: `generated → validated | with_errors → submitted (marcado por una persona, con constancia adjunta) → rectified`.
- Las estructuras oficiales no se verificaron en `research.md`: deben tomarse de la documentación vigente de la autoridad antes de implementar cada generador.

### 3.12 Otros puertos

| Puerto | Uso | Nota |
|---|---|---|
| `SmsChannel` | OTP y avisos al personal sin correo | Falso: bandeja local |
| `Geocoder` | Dirección ↔ coordenadas para geocercas | Opcional; se puede fijar el punto en el mapa |
| `LivenessVerifier` | Selfie con detección de vida en marcación | Requiere evaluación de impacto (D-16) |
| `AntivirusScanner` | Archivos subidos | Obligatorio en producción |
| `PdfRenderer` | HTML → PDF | Implementación propia por defecto (A-11) |
| `BackgroundCheckProvider` | Antecedentes y psicometría en ATS | Con consentimiento del candidato; fase 9 |
| `JobBoardPublisher` | Publicar vacantes en bolsas externas | Fase 9 |
| `ExchangeRateSource` | Tipo de cambio diario | Carga manual como respaldo |
| `HolidaySource` | Feriados | El paquete de país los trae; el puerto es para actualizaciones |

## 4. Salud de conectores

- `integration_connections`: `port`, `driver`, `config` (cifrado), `status` (`connected | disconnected | error`), `health` (`ok | degraded | down | unknown`), `last_checked_at`, `last_success_at`, `last_error`.
- Fuentes de salud:
  1. **Sonda activa**: `health()` programada por conexión (frecuencia por puerto).
  2. **Pasiva**: tasa de error y latencia de las llamadas reales (ventana móvil).
  3. **Cortacircuitos**: abierto ⇒ `down`.
  4. **Latido de dispositivos**: `last_seen_at` de relojes y kioskos; sin latido en el intervalo esperado ⇒ `degraded`.
- Cambios de salud emiten `integration.health_changed` → alerta explicada al administrador de la organización («El reloj de Almacén central no envía marcaciones desde las 07:40; las marcaciones se guardarán al reconectar») y a la consola SaaS.
- Pantalla de integraciones: estado, última sincronización, pendientes en cola, errores recientes con acción de reintento, botón «Probar conexión» (`POST /integrations/{id}/test`).
- `GET /integrations/health` (organización) y `GET /platform/health` (plataforma).
- Degradación: un conector caído nunca bloquea la operación principal. La solicitud de vacaciones se aprueba aunque el correo no salga; el mensaje queda en cola y visible.

## 5. Webhooks entrantes

- Ruta por conexión: `/api/v1/integrations/{connection_id}/webhook` (y la específica de WhatsApp). Públicas, con límite de solicitudes.
- Pasos fijos: verificar firma del proveedor → deduplicar por identificador del evento del proveedor → guardar crudo cifrado en `inbound_events` → responder 2xx de inmediato → procesar en cola.
- Un webhook con firma inválida responde 401 y cuenta para la salud del conector.

## 6. Lista de comprobación para un adaptador nuevo

1. Implementa el puerto completo y pasa las pruebas de contrato.
2. Configuración validada al guardar (`ConfigurationError` con campo y motivo).
3. Secretos cifrados; nunca en logs ni en respuestas.
4. Idempotencia verificada con una prueba de doble entrega.
5. Mapeo de errores a las cuatro excepciones del puerto.
6. `health()` implementado y visible en la pantalla.
7. Webhook entrante con verificación de firma y deduplicación.
8. Sin datos sensibles innecesarios hacia el proveedor (minimización); registrado en el inventario de encargados de tratamiento (`12` §10).
9. Documentado en `docs/modulos/` del módulo dueño: qué datos salen, a dónde y con qué base legal.
