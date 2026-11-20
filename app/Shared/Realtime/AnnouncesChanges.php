<?php

namespace App\Shared\Realtime;

/**
 * Regla 31: a model that announces its own changes (created, updated, deleted) to the screens that show it, from any
 * path that saves it (web, imports, integrations, jobs). A row that belongs to another (a stock row, a tax of an item)
 * announces its parent: override `liveResource()` and `livePublicId()`, and `liveAction()` to say «updated».
 *
 * @property int $company_id
 */
trait AnnouncesChanges
{
    public static function bootAnnouncesChanges(): void
    {
        static::created(fn (self $model) => $model->announceChange('created'));
        static::updated(fn (self $model) => $model->announceChange('updated'));
        static::deleted(fn (self $model) => $model->announceChange('deleted'));
    }

    /** The resource the web listens to: «items», «customers», «stock»… */
    abstract public function liveResource(): string;

    public function livePublicId(): ?string
    {
        return $this->getAttribute('public_id');
    }

    /** A vertical module's resources go on its own channel. */
    public function liveModule(): ?string
    {
        return null;
    }

    public function liveAction(string $action): string
    {
        return $action;
    }

    protected function announceChange(string $action): void
    {
        app(Realtime::class)->forCompanyId((int) $this->company_id, $this->liveResource(), $this->livePublicId(), $this->liveAction($action), $this->getAttribute('updated_at'), $this->liveModule());
    }
}
