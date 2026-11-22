<?php

namespace NotificationLab;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DatabaseNotifications
{
    public function available(): bool
    {
        $model = $this->model();

        return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
    }

    public function query(): Builder
    {
        return $this->model()->newQuery();
    }

    public function page(array $filters): array
    {
        if (! $this->available()) {
            return ['available' => false, 'items' => [], 'page' => 1, 'hasMore' => false];
        }

        $query = $this->query();
        $model = $query->getModel();

        if (($filters['search'] ?? '') !== '') {
            $connection = $model->getConnection();
            $grammar = $connection->getQueryGrammar();
            $cast = match ($connection->getDriverName()) {
                'mysql', 'mariadb' => 'CHAR',
                'sqlsrv' => 'NVARCHAR(255)',
                default => 'TEXT',
            };
            $key = $grammar->wrap($model->getKeyName());
            $recipient = $grammar->wrap('notifiable_id');
            $query->whereRaw("(CAST({$key} AS {$cast}) = ? OR CAST({$recipient} AS {$cast}) = ?)", [
                $filters['search'], $filters['search'],
            ]);
        }

        if (($filters['type'] ?? '') !== '') {
            $query->whereLike('type', '%'.$filters['type'].'%');
        }

        match ($filters['status'] ?? '') {
            'read' => $query->whereNotNull('read_at'),
            'unread' => $query->whereNull('read_at'),
            default => null,
        };

        $page = $query->orderByDesc('created_at')->orderByDesc($model->getKeyName())
            ->simplePaginate(20, page: $filters['page'] ?? 1);

        $items = $page->getCollection()->map(function (Model $notification): array {
            $data = $notification->data ?? [];
            if (is_string($data)) {
                $data = json_decode($data, true) ?? $data;
            }
            $title = is_array($data) ? ($data['title'] ?? $data['message'] ?? null) : null;

            return [
                'id' => (string) $notification->getKey(),
                'type' => $notification->type,
                'recipientType' => $notification->notifiable_type,
                'recipientId' => (string) $notification->notifiable_id,
                'readAt' => $notification->read_at,
                'createdAt' => $notification->created_at,
                'title' => is_string($title) ? $title : null,
                'data' => $data,
            ];
        })->all();

        return ['available' => true, 'items' => $items, 'page' => $page->currentPage(), 'hasMore' => $page->hasMorePages()];
    }

    private function model(): Model
    {
        $class = config('notification-lab.database.model');

        return new $class;
    }
}
