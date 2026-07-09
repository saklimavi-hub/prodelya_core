<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RevisionRepeatOrderPermissionTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_unauthorized_user_cannot_see_or_run_revision_repeat_actions(): void
    {
        $sourceOrder = $this->createSourceOrder();

        $this->getAs($this->unauthorizedUser, route('admin.orders.show', $sourceOrder))
            ->assertOk()
            ->assertDontSee('Revizyon Oluştur')
            ->assertDontSee('Tekrar Sipariş Oluştur');

        $this->postAs($this->unauthorizedUser, route('admin.orders.revision-draft.store', $sourceOrder))
            ->assertForbidden();

        $this->postAs($this->unauthorizedUser, route('admin.orders.repeat-order-draft.store', $sourceOrder))
            ->assertForbidden();
    }
}
