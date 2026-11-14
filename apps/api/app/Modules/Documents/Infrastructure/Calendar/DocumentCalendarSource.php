<?php

namespace App\Modules\Documents\Infrastructure\Calendar;

use App\Modules\Documents\Application\DocumentAccess;
use App\Modules\Documents\Application\DocumentCatalog;
use App\Modules\Documents\Domain\DocumentStateMachine;
use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Shared\Calendar\CalendarSource;
use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DOC-2 · Vencimientos de documentos en el calendario central (CFG-8): el día en que vence cada documento vigente,
 * con el mismo alcance y la misma confidencialidad que el legajo (regla 6). De un documento confidencial solo se ve
 * su tipo, no su título.
 */
final class DocumentCalendarSource implements CalendarSource
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentCatalog $catalog,
    ) {}

    public function key(): string
    {
        return 'documents';
    }

    public function permission(): string
    {
        return 'documents.documents.view';
    }

    public function items(CarbonImmutable $from, CarbonImmutable $to, Request $request, ?EffectivePermissions $permissions): array
    {
        /** @var list<Document> $rows */
        $rows = $this->access->scoped($request, $this->permission())
            ->whereIn('documents.status', [DocumentStateMachine::ACTIVE, DocumentStateMachine::EXPIRED])
            ->whereNotNull('documents.expires_on')
            ->whereBetween('documents.expires_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('documents.expires_on')->limit(500)->get()->all();
        if ($rows === []) {
            return [];
        }
        $categories = DocumentCategory::query()->withTrashed()->get()->keyBy('id');
        $people = DB::table('people')->whereIn('id', array_values(array_filter(array_unique(array_map(fn (Document $d) => $d->owner_person_id, $rows)))))
            ->get(['id', 'first_names', 'preferred_name', 'paternal_surname'])->keyBy('id');

        return array_map(function (Document $d) use ($categories, $people): array {
            $category = $categories->get($d->document_category_id);
            $type = $category instanceof DocumentCategory ? $this->catalog->name($category) : null;
            $p = $d->owner_person_id === null ? null : $people->get($d->owner_person_id);
            $confidential = in_array($d->classification, ['confidential', 'restricted'], true);
            $day = (string) $d->day('expires_on');

            return [
                'id' => $d->id,
                'title' => $p === null ? ($confidential ? (string) $type : $d->title) : trim(($p->preferred_name ?: $p->first_names).' '.$p->paternal_surname),
                'subtitle' => $confidential || $p === null ? $type : $d->title,
                'starts_on' => $day,
                'ends_on' => $day,
                'link' => $d->employment_id === null ? '/documentos/vencimientos' : '/personas/colaboradores/'.$d->employment_id.'?tab=documents',
                'tone' => 'document_expiry',
            ];
        }, $rows);
    }
}
