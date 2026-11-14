<?php

namespace App\Modules\Workflow\Infrastructure\Home;

use App\Modules\Workflow\Infrastructure\Inbox\ApprovalInboxSource;
use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;

/**
 * COL-12 · MSS-1 · Solicitudes que esperan la decisión del usuario (a su nombre o de uno de sus roles),
 * con las vencidas primero. El centro de aprobaciones (WFL-4) explica el número.
 */
final class PendingApprovalsCard implements HomeCard
{
    public function __construct(private readonly ApprovalInboxSource $source) {}

    public function key(): string
    {
        return 'pending_approvals';
    }

    public function permission(): string
    {
        return 'workflow.approval_requests.view';
    }

    public function audiences(): array
    {
        return [self::HR, self::MANAGER];
    }

    public function order(): int
    {
        return 5;
    }

    public function compute(HomeContext $context): array
    {
        $count = $this->source->count($context->userId);
        $items = [];
        $overdue = 0;
        foreach ($this->source->items($context->userId, 8) as $item) {
            $overdue += $item['overdue'] ? 1 : 0;
            $items[] = ['id' => $item['id'], 'name' => $item['title'], 'detail' => $item['type'], 'date' => $item['due_at'] === null ? null : substr((string) $item['due_at'], 0, 10), 'overdue' => $item['overdue'], 'link' => $item['href']];
        }

        return [
            'value' => $count,
            'link' => '/aprobaciones',
            'tone' => $overdue > 0 ? 'danger' : ($count > 0 ? 'warning' : null),
            'items' => $items,
            'meta' => ['overdue_in_page' => $overdue],
        ];
    }
}
