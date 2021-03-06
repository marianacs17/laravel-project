<?php

namespace Laravel\Nova\Tests\Controller;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Actions\ActionEvent;
use Laravel\Nova\Nova;
use Laravel\Nova\Tests\Fixtures\IdFilter;
use Laravel\Nova\Tests\Fixtures\User;
use Laravel\Nova\Tests\Fixtures\UserPolicy;
use Laravel\Nova\Tests\IntegrationTestCase;

class LensResourceRestoreTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticate();
    }

    public function test_can_restore_resources()
    {
        $user = factory(User::class)->create();
        $user2 = factory(User::class)->create();

        $user->delete();
        $user2->delete();

        $response = $this->withExceptionHandling()
                        ->putJson('/nova-api/users/lens/user-lens/restore', [
                            'resources' => [$user->id, $user2->id],
                        ]);

        $response->assertStatus(200);

        $this->assertCount(2, User::all());

        $this->assertNull(User::withTrashed()->find($user->id)->deleted_at);
        $this->assertNull(User::withTrashed()->find($user2->id)->deleted_at);

        $this->assertCount(2, ActionEvent::all());
        $this->assertEquals('Restore', ActionEvent::first()->name);
        $this->assertEquals($user->id, ActionEvent::where('actionable_id', $user->id)->first()->target->id);
    }

    public function test_can_restore_all_matching_resources()
    {
        $user = factory(User::class)->create();
        $user2 = factory(User::class)->create();

        $user->delete();
        $user2->delete();

        $response = $this->withExceptionHandling()
                        ->putJson('/nova-api/users/lens/user-lens/restore', [
                            'resources' => 'all',
                        ]);

        $response->assertStatus(200);

        $this->assertCount(2, User::withTrashed()->get());

        $this->assertNull(User::withTrashed()->find($user->id)->deleted_at);
        $this->assertNull(User::withTrashed()->find($user2->id)->deleted_at);

        $this->assertCount(2, ActionEvent::all());
        $this->assertEquals('Restore', ActionEvent::first()->name);
    }

    public function test_can_restore_resources_via_filters()
    {
        $user = factory(User::class)->create();
        $user2 = factory(User::class)->create();

        $user->delete();
        $user2->delete();

        $filters = base64_encode(json_encode([
            [
                'class' => IdFilter::class,
                'value' => 1,
            ],
        ]));

        $response = $this->withExceptionHandling()
                        ->putJson('/nova-api/users/lens/user-lens/restore?filters='.$filters, [
                            'resources' => 'all',
                        ]);

        $response->assertStatus(200);

        $this->assertCount(2, User::withTrashed()->get());

        $this->assertNull(User::withTrashed()->find($user->id)->deleted_at);
        $this->assertNotNull(User::withTrashed()->find($user2->id)->deleted_at);

        $this->assertCount(1, ActionEvent::all());
        $this->assertEquals('Restore', ActionEvent::first()->name);
        $this->assertEquals($user->id, ActionEvent::where('actionable_id', $user->id)->first()->target_id);
    }

    public function test_cant_restore_resources_not_authorized_to_restore()
    {
        $user = factory(User::class)->create();
        $user2 = factory(User::class)->create();

        $user->delete();
        $user2->delete();

        $_SERVER['nova.user.authorizable'] = true;
        $_SERVER['nova.user.restorable'] = false;

        Gate::policy(User::class, UserPolicy::class);

        $response = $this->withExceptionHandling()
                        ->putJson('/nova-api/users/lens/user-lens/restore', [
                            'resources' => [$user->id],
                        ]);

        unset($_SERVER['nova.user.authorizable']);
        unset($_SERVER['nova.user.restorable']);

        $response->assertStatus(200);

        $this->assertNotNull(User::withTrashed()->find($user->id)->deleted_at);
        $this->assertNotNull(User::withTrashed()->find($user2->id)->deleted_at);

        $this->assertCount(0, ActionEvent::all());
    }

    public function test_should_store_action_event_on_correct_connection_when_restoring()
    {
        $this->setupActionEventsOnSeparateConnection();

        $user = factory(User::class)->create();
        $user2 = factory(User::class)->create();

        $user->delete();
        $user2->delete();

        $response = $this->withExceptionHandling()
            ->putJson('/nova-api/users/lens/user-lens/restore', [
                'resources' => [$user->id, $user2->id],
            ]);

        $response->assertStatus(200);

        $this->assertCount(2, User::all());

        $this->assertNull(User::withTrashed()->find($user->id)->deleted_at);
        $this->assertNull(User::withTrashed()->find($user2->id)->deleted_at);

        $this->assertCount(0, DB::connection('sqlite')->table('action_events')->get());
        $this->assertCount(2, DB::connection('sqlite-custom')->table('action_events')->get());

        tap(Nova::actionEvent()->first(), function ($actionEvent) use ($user) {
            $this->assertEquals('Restore', $actionEvent->name);
            $this->assertEquals($user->id, $actionEvent->target_id);
        });
    }
}
